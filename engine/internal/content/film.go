// film.go — FILME CONTÍNUO (plano-sequência). Diferente da HISTÓRIA (cenas independentes com
// corte), o filme é UMA jornada de câmera sem cortes, dividida em N trechos encadeados por
// KEYFRAMES COMPARTILHADOS: o clipe do trecho i começa EXATAMENTE no keyframe i e termina
// EXATAMENTE no keyframe i+1 (primeiro+último frame no modelo de vídeo — Kling 3.0 aceita os
// dois no array image_urls). Como os keyframes são âncoras fixas, os clipes são independentes
// entre si (geram em paralelo) e não há drift acumulado. Peças: GenerateFilmPlan (LLM → beats),
// GenerateFilmClip (i2v com 2 frames) e AssembleFilm (concat + trilha no ffmpeg-service).
package content

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/media"
	"github.com/redfoxcode/reachyn/engine/internal/provider/video"
)

// FilmBeat — um TRECHO do plano-sequência: o que o keyframe i MOSTRA (frame_prompt, imagem
// ESTÁTICA), o MOVIMENTO contínuo de câmera/ação do keyframe i até o keyframe i+1 (move_prompt)
// e a LOCUÇÃO do trecho (voiceover — usada quando a narração é ligada na montagem, F2).
type FilmBeat struct {
	Title       string     `json:"title"`
	FramePrompt string     `json:"frame_prompt"`   // keyframe i — descrição estática (vira imagem)
	MovePrompt  string     `json:"move_prompt"`    // SÓ o movimento do keyframe i ao i+1 (vira vídeo)
	Voiceover   string     `json:"voiceover"`      // locução publicitária do trecho (dimensionada à duração)
	Spec        *SceneSpec `json:"spec,omitempty"` // FICHA DE CENA (S1): plano/movimento/luz/emoção do keyframe
}

// FilmPlanResult — plano de filmagem: N beats + o prompt do keyframe FINAL (K_N, o frame em
// que o filme termina) + TÍTULO do filme e DIREÇÃO MUSICAL (formato "roteiro técnico" completo:
// o operador vê Roteiro/Storyboard/Música+Narração/Câmera como seções editáveis).
type FilmPlanResult struct {
	Title            string     `json:"title"` // título do filme (no idioma do tenant)
	Beats            []FilmBeat `json:"beats"`
	FinalFramePrompt string     `json:"final_frame_prompt"`
	MusicPrompt      string     `json:"music_prompt"` // direção musical (EN — vira o prompt da trilha na montagem)
}

// Clamps do plano: 2 trechos (10s) a 24 trechos (120s de clipes de 5s) — teto anti-gasto
// (denial-of-wallet) alinhado ao maxVideoSeconds/2 do orquestrador de vídeo.
const (
	filmMinBeats = 2
	filmMaxBeats = 24
)

func clampFilmBeats(n int) int {
	if n < filmMinBeats {
		return filmMinBeats
	}
	if n > filmMaxBeats {
		return filmMaxBeats
	}
	return n
}

// filmMaxTokens — orçamento de saída do plano (reasoning model: pensamento + JSON precisam
// caber). ~700 tokens/beat de folga; mesmo raciocínio do storyMaxTokens.
func filmMaxTokens(n int) int {
	t := 5000 + n*700
	if t < 8192 {
		t = 8192
	}
	if t > 24000 {
		t = 24000
	}
	return t
}

// filmDirectorRole — a craft do cinematógrafo de PLANO-SEQUÊNCIA (regras BUDO de câmera
// contínua): um único take, movimento físico plausível e calmo, revelação progressiva.
const filmDirectorRole = `You are a master one-take cinematographer and commercial director — the craft of continuous single-shot films (brand commercials, real-estate tours, product films). Your rules: the camera NEVER cuts; it flows in ONE continuous physical move (dolly, orbit, crane, push-in, glide) at a calm believable speed (about 3 ft/s — no whip pans, no teleports); every move REVEALS something new (progressive revelation builds desire); the motion axis stays coherent between moments (no direction whiplash); light and atmosphere evolve gradually; the final frame is the emotional payoff (hero shot).`

// GenerateFilmPlan — gera o PLANO DE FILMAGEM contínuo: N beats {frame_prompt, move_prompt} +
// o keyframe final. O CONTRATO DE CONTINUIDADE é explícito: o enquadramento onde o move_prompt
// do beat i TERMINA é exatamente o frame_prompt do beat i+1 (e o do último beat termina no
// final_frame_prompt). `masterDesc` (opcional) descreve o protagonista fixo (produto/persona/
// imóvel) que deve aparecer coerente do início ao fim. `clipDur` = duração de cada trecho (s).
func (s *Service) GenerateFilmPlan(ctx context.Context, brief, style, masterDesc, persona, lang, clipDur string, count int, gl GenLines) (FilmPlanResult, error) {
	n := clampFilmBeats(count)
	// 230s: folga sobre o caso típico (~1min mesmo com 24 beats), tolerando picos de lentidão do
	// provider numa ÚNICA tentativa. O http client (320s) é maior de propósito p/ não cortar antes.
	// Cadeia de timeouts: engine 230s < job http 280s < job 300s < poll do front 300s.
	ctx, cancel := context.WithTimeout(ctx, 230*time.Second)
	defer cancel()

	// 🎥 DIRETOR escolhido (aba Prompts) substitui a craft do cinematógrafo padrão — muda a VOZ
	// do plano (imobiliário/produto/food/moda…), mantendo o contrato de continuidade e o JSON.
	role := filmDirectorRole
	if p := strings.TrimSpace(persona); p != "" {
		role = clip(p, 4000) // persona combinada (diretor + roteirista da locução)
	}

	langRule := `Write the titles and the scene descriptions inside the prompts in English.`
	if lang == "pt-BR" {
		langRule = `Write the titles and the DESCRIPTIONS inside the prompts in BRAZILIAN PORTUGUESE (PT-BR) — the system translates them for the generators.`
	}
	masterRule := ""
	if m := strings.TrimSpace(masterDesc); m != "" {
		// FIXED SUBJECT: identidade constante do início ao fim. Quando o sujeito vem de uma imagem de
		// referência (a foto do carro/produto/imóvel que o usuário subiu), o frame_prompt deve citá-lo
		// GENERICAMENTE — inventar make/modelo/cor/detalhe no texto BRIGA com a referência e é a raiz do
		// drift ("o carro muda ao longo do filme"). A aparência exata vem da imagem, não do texto.
		masterRule = ` FIXED SUBJECT — the film features ONE subject consistently from start to finish (identity, colors and proportions never change): ` + clip(m, 800) + `. In every frame_prompt refer to this subject GENERICALLY (e.g. "the car", "the product", "the building", "the presenter") and describe only its framing, position and the light on it — do NOT invent or state a specific brand, model, color or design detail, because its exact appearance is fixed by the reference image and any invented detail will contradict it.`
	}
	sys := filmPlanSystem(role, langRule, masterRule, clipDur, n)

	user := "Film brief / objective:\n<<<USER_INPUT>>>\n" + clip(brief, 2000) + "\n<<<END_USER_INPUT>>>\nVisual style: " + clip(style, 120)

	raw, err := s.genTextOrdered(ctx, gl.WithDefaults().Text, sys, user, filmMaxTokens(n), true, storyMinimaxModel)
	if err != nil {
		return FilmPlanResult{}, err
	}
	return parseFilmPlan(raw, n)
}

// filmPlanSystem — o SYSTEM PROMPT do plano de filmagem, extraído da GenerateFilmPlan pra ser
// testável: é onde as travas de ofício (budoVideoRules), os limites duros do motor (videoHardLimits)
// e a geografia fixa de cena (sceneGeographyRule) precisam CHEGAR. Função pura, sem rede — o teste
// de presença (videolimits_test.go) tranca o "escrita e nunca ligada" de 2026-08-02.
func filmPlanSystem(role, langRule, masterRule, clipDur string, n int) string {
	return fmt.Sprintf(`%s You are planning a COMPLETE continuous one-take film of %d consecutive segments (each segment lasts ~%s seconds).

Output ONLY JSON: {"title":"...","beats":[{"title":"...","frame_prompt":"...","move_prompt":"...","voiceover":"...","spec":{"shot":"...","movement":"...","light":"...","emotion":"..."}}],"final_frame_prompt":"...","music_prompt":"..."} with EXACTLY %d beats. The top-level "title" is the FILM's title — short and evocative, like a commercial's name. The top-level "music_prompt" is the MUSIC DIRECTION for the whole film — one sentence in English describing genre, mood, instrumentation and how it evolves (e.g. "cinematic orchestral build, warm piano intro swelling into driving strings and percussion, triumphant finale").

THE CONTINUITY CONTRACT (the most important rule): the film is ONE unbroken camera take. For each beat i, move_prompt describes the continuous camera/subject motion that starts at that beat's frame_prompt and lands EXACTLY on the next beat's frame_prompt (the last beat lands on final_frame_prompt). Therefore each frame_prompt MUST be the natural end point of the previous move — same location reached by the move, same light continuity, same subject state. Never start a beat somewhere the camera could not have physically arrived. THE 180-DEGREE RULE: keep a consistent axis of action across consecutive beats — the camera stays on ONE side of the subject/line of action and screen direction is preserved (a subject moving screen-left keeps moving screen-left); never jump the camera to the opposite side between beats, as that breaks spatial continuity in a single take.

THE 2-SECOND HOOK (beat 1): the film lives or dies in its first two seconds. Beat 1 must open on the most visually striking moment available — motion already underway or extreme contrast (color, scale, framing) — and plant a visual question ("where is this?", "what is that?") that the following beats answer. NEVER open on a logo, a title card, a neutral establishing wide or slow context-setting; context belongs to beats 2-3, the opening frame is the bait. The hook must still obey the continuity contract (it is the real first frame of the take, not a detached teaser). Pick a concrete hook pattern for beat 1 and commit to it: bold visual contrast, a result/transformation shown first, an intriguing detail in extreme close, or a striking motion. The FIRST spoken line must work even on mute (the visual carries it) and must NEVER be a greeting ("olá", "seja bem-vindo", "hoje eu vou…") — go straight to the bait.

NARRATIVE SPINE: the beats form ONE deliberate arc, not a slideshow. Shape it hook → build (rising interest/desire, each beat revealing something new) → turn (the emotional peak or key reveal) → payoff (the closing hero shot). Every beat must earn its place — if a beat has no job in this arc, it should not exist. For a longer film (6+ beats), place ONE re-hook around the middle: a deliberate visual or tonal shift (new angle, scale change, pace or light shift) that re-grabs attention before it drifts.

For each beat:
- title: short segment name (the narrative beat).
- frame_prompt: a STATIC keyframe — what the camera sees at that instant: the environment, the subject and what it is doing, the mood. NO camera-movement words and NO framing/lighting words here (those go in spec); it becomes a still image.
- move_prompt: ONLY the camera/subject MOTION from this keyframe to the next (one continuous move: e.g. "the camera glides forward through the doorway while the light warms"), 1-3 sentences, physically plausible in ~%s seconds.
- voiceover: the spoken AD COPY for this segment — commercial narration (emotional, selling, premium tone) that a narrator can comfortably speak in ~%s seconds. BUDGET the words tightly: about 2 words per second, so ~%s seconds means only a handful of words — write SHORT, punchy lines, never a paragraph. The voiceovers of all beats read in sequence must form ONE flowing script (no repetition, each line advances the pitch). The LAST beat's voiceover must land a SPECIFIC, actionable call-to-action tied to the product/offer (e.g. "agende sua visita hoje", "garanta o seu na pré-venda") — never a vague "saiba mais" or a bare brand name.
- spec: the SHOT LIST entry (director's choices) for this keyframe — an object with: shot (the framing, ONE key from [`+shotKeysList+`]; VARY it across beats — open wide/establishing, push to closer framings as desire builds, end on a hero shot), movement (the camera motion, ONE key from [`+moveKeysList+`], matching move_prompt), light (ONE key from [`+lightKeysList+`]; keep it COHERENT, evolving gradually — never a jarring change between consecutive beats), emotion (2-4 words for the mood of the shot). Do NOT put framing or lighting words inside frame_prompt — they belong in spec.
- final_frame_prompt: the STATIC closing keyframe (the hero shot / payoff).`, role, n, clipDur, n, clipDur, clipDur, clipDur) + `

For move_prompt specifically,` + budoVideoRules + videoHardLimits + actorPhysicsRule + `

` + sceneGeographyRule + `

` + langRule + masterRule + ` Output ONLY the JSON, no explanations, no markdown fences. ` + antiInjectionRule + whiteLabelRule
}

// parseFilmPlan — validação/normalização do JSON devolvido pelo LLM (extraído junto com o system
// prompt; o corpo é o mesmo de antes).
func parseFilmPlan(raw string, n int) (FilmPlanResult, error) {
	var o FilmPlanResult
	if err := json.Unmarshal([]byte(extractJSON(raw)), &o); err != nil {
		return FilmPlanResult{}, fmt.Errorf("plano do filme: o modelo não retornou JSON válido")
	}
	if len(o.Beats) == 0 {
		return FilmPlanResult{}, fmt.Errorf("plano do filme: nenhum trecho gerado")
	}
	if len(o.Beats) > n {
		o.Beats = o.Beats[:n]
	}
	for i := range o.Beats {
		o.Beats[i].Title = stripCJK(o.Beats[i].Title)
		// frame_prompt fica LIMPO. A FICHA (plano/luz/emoção) é composta na GERAÇÃO do keyframe (o
		// console passa o spec do beat a /v1/image) — assim editar a ficha muda a próxima geração. O
		// move_prompt segue como o movimento autoritativo do plano-sequência (spec.movement só espelha).
		o.Beats[i].FramePrompt = stripCJK(o.Beats[i].FramePrompt)
		o.Beats[i].MovePrompt = stripCJK(o.Beats[i].MovePrompt)
		o.Beats[i].Voiceover = stripCJK(o.Beats[i].Voiceover)
	}
	o.FinalFramePrompt = stripCJK(o.FinalFramePrompt)
	if strings.TrimSpace(o.FinalFramePrompt) == "" {
		// Sem keyframe final explícito → o filme termina no último frame_prompt (degradação suave).
		o.FinalFramePrompt = o.Beats[len(o.Beats)-1].FramePrompt
	}
	o.Title = stripCJK(o.Title)
	o.MusicPrompt = stripCJK(o.MusicPrompt)
	return o, nil
}

// Seções regeneráveis do plano ("roteiro técnico" — cada bloco edita/regenera sem tocar o resto).
// A chave casa com o campo que a seção reescreve nos beats (musica reescreve só o music_prompt).
var filmSectionField = map[string]string{
	"roteiro":    "title",        // título do filme + título/beat (o esqueleto narrativo)
	"storyboard": "frame_prompt", // o que cada quadro MOSTRA (keyframes)
	"narracao":   "voiceover",    // a locução contínua
	"camera":     "move_prompt",  // o movimento de câmera de cada trecho (pra IA de vídeo)
	"musica":     "music_prompt", // direção musical do filme
}

// FilmSectionResult — saída do GenerateFilmSection: só os campos da seção pedida vêm preenchidos.
type FilmSectionResult struct {
	Title            string   `json:"title,omitempty"`
	BeatValues       []string `json:"beat_values,omitempty"` // valor novo do campo da seção, por beat (na ordem)
	FinalFramePrompt string   `json:"final_frame_prompt,omitempty"`
	MusicPrompt      string   `json:"music_prompt,omitempty"`
}

// GenerateFilmSection — regenera UMA seção do plano mantendo TODO o resto intacto (a história
// completa manda: as outras seções são contexto fixo, não podem ser contrariadas). `section` ∈
// filmSectionField. Recebe os beats atuais como contexto e devolve só os valores novos da seção.
func (s *Service) GenerateFilmSection(ctx context.Context, brief, style, lang, clipDur, section string, beats []FilmBeat, finalFrame string, gl GenLines) (FilmSectionResult, error) {
	if _, ok := filmSectionField[section]; !ok {
		return FilmSectionResult{}, fmt.Errorf("seção desconhecida: %s", section)
	}
	if len(beats) == 0 && section != "musica" {
		return FilmSectionResult{}, fmt.Errorf("plano vazio — planeje o filme primeiro")
	}
	ctx, cancel := context.WithTimeout(ctx, 170*time.Second)
	defer cancel()

	// Contexto: o plano ATUAL inteiro (todas as dimensões) — a seção nova precisa casar com ele.
	var b strings.Builder
	for i, bt := range beats {
		fmt.Fprintf(&b, "Segment %d — title: %s | frame: %s | camera: %s | voiceover: %s\n",
			i+1, clip(bt.Title, 90), clip(bt.FramePrompt, 220), clip(bt.MovePrompt, 220), clip(bt.Voiceover, 160))
	}
	if strings.TrimSpace(finalFrame) != "" {
		fmt.Fprintf(&b, "Final frame: %s\n", clip(finalFrame, 220))
	}

	langRule := "Write in English."
	if lang == "pt-BR" {
		langRule = "Write in BRAZILIAN PORTUGUESE (PT-BR)."
	}
	var task string
	switch section {
	case "roteiro":
		task = `Rewrite the NARRATIVE SKELETON: output {"title":"<new film title, short and evocative>","beat_values":["<new short segment title>", ...]} with EXACTLY one beat_value per segment, in order. Titles only — do NOT touch frames, camera or voiceover; the new titles must describe the SAME action each segment already shows. ` + langRule
	case "storyboard":
		task = `Rewrite ONLY what each storyboard frame SHOWS: output {"beat_values":["<new frame_prompt>", ...],"final_frame_prompt":"<new closing frame>"} with EXACTLY one beat_value per segment, in order. Each frame_prompt is a STATIC image description (no camera-movement, framing or lighting words) consistent with that segment's title, camera move and voiceover. Consecutive frames must respect continuity (each frame is where the previous camera move lands). ` + langRule
	case "narracao":
		task = fmt.Sprintf(`Rewrite ONLY the narration: output {"beat_values":["<new voiceover>", ...]} with EXACTLY one beat_value per segment, in order. Commercial ad copy (emotional, selling, premium tone); each segment's line must be comfortably speakable in ~%s seconds (about 2 words per second — keep it SHORT), and all lines in sequence must read as ONE flowing script that matches what the frames show. %s`, clipDur, langRule)
	case "camera":
		task = `Rewrite ONLY the camera directions (for a video-generation AI): output {"beat_values":["<new move_prompt>", ...]} with EXACTLY one beat_value per segment, in order. Each move_prompt is ONLY the continuous camera/subject motion from that segment's frame to the next one (1-3 sentences, physically plausible, calm speed, no cuts); it must land exactly on the next frame. Write the motion descriptions in English (video models read English).`
	case "musica":
		task = `Write the MUSIC DIRECTION for the whole film: output {"music_prompt":"<one sentence in English describing genre, mood, instrumentation and how the music evolves across the film>"}. It must match the story's emotional arc.`
	}

	sys := filmDirectorRole + "\n\nYou are revising ONE section of an existing one-take film plan. THE FULL STORY RULES: everything you write must follow the brief and the existing plan below — never contradict stated colors, subjects, wardrobe, locations or the order of events.\n\n" + task + videoHardLimits + actorPhysicsRule + sceneGeographyRule + "\nOutput ONLY the JSON, no explanations, no markdown fences. " + antiInjectionRule + whiteLabelRule
	user := "Film brief:\n<<<USER_INPUT>>>\n" + clip(brief, 2000) + "\n<<<END_USER_INPUT>>>\nVisual style: " + clip(style, 120) + "\n\nCurrent plan (context — keep everything not in your section):\n" + clip(b.String(), 6000)

	raw, err := s.genTextOrdered(ctx, gl.WithDefaults().Text, sys, user, filmMaxTokens(len(beats)), true, storyMinimaxModel)
	if err != nil {
		return FilmSectionResult{}, err
	}
	var o FilmSectionResult
	if err := json.Unmarshal([]byte(extractJSON(raw)), &o); err != nil {
		return FilmSectionResult{}, fmt.Errorf("seção do plano: o modelo não retornou JSON válido")
	}
	// Validação: toda seção exceto "musica" reescreve um campo POR BEAT — beat_values precisa
	// cobrir os beats (excesso trunca; faltando = erro, senão trechos ficariam pela metade).
	if section != "musica" {
		if len(o.BeatValues) < len(beats) {
			return FilmSectionResult{}, fmt.Errorf("seção do plano: o modelo devolveu %d valores para %d trechos", len(o.BeatValues), len(beats))
		}
		o.BeatValues = o.BeatValues[:len(beats)]
		for i := range o.BeatValues {
			o.BeatValues[i] = stripCJK(o.BeatValues[i])
		}
	}
	o.Title = stripCJK(o.Title)
	o.FinalFramePrompt = stripCJK(o.FinalFramePrompt)
	o.MusicPrompt = stripCJK(o.MusicPrompt)
	if section == "musica" && strings.TrimSpace(o.MusicPrompt) == "" {
		return FilmSectionResult{}, fmt.Errorf("seção do plano: música vazia")
	}
	return o, nil
}

// GenerateFilmClip — gera UM trecho do filme. DOIS MODOS:
//   - KEYFRAME (endURL preenchido): i2v com PRIMEIRO e ÚLTIMO frame via Magnific (Kling: os dois
//     frames vão no array image_urls — validado na doc oficial e com geração real). Sem drift.
//   - CORRENTE (endURL vazio — o sistema dos modelos BARATOS): i2v normal a partir do startURL
//     (que é o ÚLTIMO frame real do trecho anterior, extraído via /v1/lastframe) — funciona com
//     QUALQUER modelo i2v (seedance/hailuo/wan), com reserva MiniMax Hailuo direto (pré-paga).
//
// O move_prompt do plano JÁ é o prompt de movimento — NÃO passa por LLM (determinístico; a
// diretriz de continuidade é apensa aqui).
func (s *Service) GenerateFilmClip(ctx context.Context, movePrompt, startURL, endURL, duration, aspect, provider, model, fallback string, magSpec video.MagnificVideoSpec) (string, error) {
	if strings.TrimSpace(startURL) == "" {
		return "", fmt.Errorf("filme: trecho sem keyframe inicial")
	}
	final := strings.TrimSpace(movePrompt)
	if final == "" {
		final = "Continuous smooth camera movement"
	}
	tail := strings.TrimSpace(endURL) != ""
	if tail {
		if provider != "magnific" || model == "" {
			return "", fmt.Errorf("filme: o modo keyframe exige um modelo de vídeo com controle de primeiro/último frame")
		}
		// Diretriz de continuidade fixa: começa no 1º frame, termina no último, sem cortes/morphs.
		// "Never mirror": o modelo espelhava a composição do frame final (caso real 2026-07-16 —
		// o fim do trecho era o keyframe invertido, carro andando pro lado errado).
		final += ". One continuous unbroken take: the shot STARTS exactly at the first reference frame and ENDS exactly at the last reference frame. Never mirror or flip any frame or composition: every subject keeps its screen side (left/right) and direction of travel exactly as shown in the reference frames. Smooth, natural, physically plausible camera motion at a calm pace. No cuts, no transitions, no morphing: preserve the exact identity, colors and proportions of every subject and of the environment from the reference frames."
		refs := []string{startURL, endURL} // refs[0..1] = primeiro e último frame
		url, err := retry(ctx, 2, 5*time.Second, func() (string, error) {
			return s.video.MagnificVideo(ctx, model, final, aspect, duration, refs, magSpec)
		})
		if err != nil {
			return "", err
		}
		return s.media.Persist(ctx, url, "video", "mp4"), nil
	}
	// MODO CORRENTE: o frame inicial é a única âncora — a diretriz proíbe redesenho e pede
	// aterrissagem estável (o último frame deste clipe vira o início do próximo).
	final += ". One continuous unbroken take that STARTS exactly at the provided frame. Never mirror or flip the composition: every subject keeps its screen side (left/right) and direction of travel consistent for the whole shot. Smooth, natural, physically plausible camera motion at a calm pace. No cuts, no transitions, no morphing: preserve the exact identity, colors and proportions of every subject and of the environment. End the shot on a stable, well-composed frame."
	if model == "" {
		return "", fmt.Errorf("filme: trecho sem modelo de vídeo")
	}
	url, err := s.clipModelOrdered(ctx, provider, model, fallback, []string{startURL}, final, duration, aspect, magSpec)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "video", "mp4"), nil
}

// GenerateFilmQuick — ⚡ FILME RÁPIDO (Sprint D): o filme inteiro numa ÚNICA geração Kling
// (modo multi_shots, doc oficial: até 5 cortes, prompt+duration por corte, total 3-15s, só 1º
// frame). `shots` = o move_prompt de cada beat do plano (na ordem); `totalSeconds` = duração
// TOTAL desejada (3-15; a duração de CADA corte é distribuída automaticamente — ver
// DistributeShotDurations). `imageURL` = o keyframe de ABERTURA (única referência aceita neste
// modo). Mais barato e mais coeso que o modo corrente/keyframe (sem keyframes intermediários
// nem chamadas encadeadas). Retorna a URL durável (bucket "video"; o caller ainda passa por
// AssembleFilm pra aplicar narração/grade/letterbox/endcard).
func (s *Service) GenerateFilmQuick(ctx context.Context, shots []string, imageURL string, totalSeconds int, aspect, model string) (string, error) {
	// ⛔ SEM MOTOR desde a saída do agregador (2026-08-03).
	//
	// O "filme rápido" dependia do modo multi_shots — vários cortes numa ÚNICA geração, com
	// prompt e duração por corte. Era o que o tornava mais barato e mais coeso que gerar clipe
	// a clipe. Nenhum motor que ficou (Higgsfield, Magnific, MiniMax) expõe esse modo.
	//
	// Isto é um erro de CONFIG explícito, e não uma degradação para N gerações encadeadas, de
	// propósito: a mesma peça sairia por várias vezes o preço, sem o cliente pedir nem ver. O
	// caminho que continua no ar é o modo corrente/keyframe (GenerateFilmClip), onde o custo
	// por clipe está à vista no seletor.
	_, _, _, _, _ = shots, imageURL, totalSeconds, aspect, model

	return "", gerr.Configf("o filme rápido está indisponível: nenhum motor atual gera vários cortes numa geração só — monte o filme pelo modo cena a cena")
}

// GenerateLipSyncClip — LIP SYNC de uma cena/fala (Estúdio de Animação): talking-head onde a
// boca do personagem sincroniza com o áudio. `imageURL` = retrato/keyframe do personagem que
// fala; `audioURL` = a fala (TTS já gerado). Substitui o i2v mudo + mux nas cenas com diálogo.
// model/spec vêm do catálogo (gen_lines.video, provider magnific). Persiste no bucket "video".
func (s *Service) GenerateLipSyncClip(ctx context.Context, imageURL, audioURL, model, provider string, magSpec video.MagnificVideoSpec) (string, error) {
	if strings.TrimSpace(imageURL) == "" || strings.TrimSpace(audioURL) == "" {
		return "", fmt.Errorf("lip sync: imagem e áudio são obrigatórios")
	}
	if model == "" {
		return "", fmt.Errorf("lip sync: escolha um modelo de lip sync")
	}
	// Magnific: /v1/ai/omni-human-1-5 é o MESMO OmniHuman 1.5 que rodava pelo agregador —
	// era o único papel sem sucessor no Higgsfield quando o agregador saiu. Mesmo retry, pelo
	// mesmo motivo: modelo de fala sincronizada recusa por concorrência quando várias cenas
	// geram juntas, e esperar recupera sem perder o lip-sync num i2v mudo.
	if provider != "magnific" {
		return "", gerr.Configf("fala sincronizada: escolha um modelo de fala sincronizada")
	}
	// Retry 3× com backoff: o modelo de fala sincronizada recusa por "concurrent limit" quando
	// muitas cenas geram em paralelo. O limite libera quando um job vizinho termina — esperar e
	// retentar recupera sem cair no i2v mudo (que perde o lip-sync sem avisar).
	url, err := retry(ctx, 3, 20*time.Second, func() (string, error) {
		return s.video.MagnificLipSync(ctx, model, imageURL, audioURL, magSpec)
	})
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "video", "mp4"), nil
}

// AssembleFilm — MONTA o filme: concatena os clipes na ordem (sem transição — a continuidade
// é real, os frames casam) no ffmpeg-service (/concat-clips), com trilha musical e/ou
// NARRAÇÃO CONTÍNUA (F2): a locução do roteiro inteiro entra POR CIMA do filme montado (as
// durações dos clipes ficam intactas — essencial pra continuidade) + legenda word-level
// opcional. Persiste no bucket "short" (vídeo final publicável).
func (s *Service) AssembleFilm(ctx context.Context, clipURLs []string, o media.FilmMixOpts) (string, error) {
	if len(clipURLs) == 0 {
		return "", fmt.Errorf("filme: nenhum clipe pronto para montar")
	}
	o.Aspect = validVideoAspect(o.Aspect)
	if o.Narration && strings.TrimSpace(o.Script) == "" {
		return "", fmt.Errorf("filme: narração ligada sem roteiro (gere o plano com locução)")
	}
	if o.Narration && o.VoiceID == "" {
		o.VoiceID = defaultVoice
	}
	url, err := s.media.ConcatClips(ctx, clipURLs, o)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "short", "mp4"), nil
}

// VideoLastFrame — extrai o ÚLTIMO frame de um clipe (ffmpeg-service /last-frame) → JPG
// durável. Usado pra RE-ANCORAR um keyframe no fim REAL do trecho anterior (modo corrente).
func (s *Service) VideoLastFrame(ctx context.Context, videoURL string) (string, error) {
	if strings.TrimSpace(videoURL) == "" {
		return "", fmt.Errorf("filme: sem vídeo para extrair o frame")
	}
	return s.media.LastFrame(ctx, videoURL)
}

// FilterImage — F2: filtro Instagram (color grade + intensidade) numa foto, persistido no Scality.
func (s *Service) FilterImage(ctx context.Context, imageURL, grade string, strength int) (string, error) {
	if strings.TrimSpace(imageURL) == "" {
		return "", fmt.Errorf("filtro: sem imagem")
	}
	url, err := s.media.ImageFilter(ctx, imageURL, grade, strength)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "image", "jpg"), nil
}
