package content

import (
	"context"
	"fmt"
	"log"
	"strings"
	"time"
)

// PRESET VOX — jornalismo explicativo animado.
//
// O formato tem três leis, e elas vieram da observação do que o canal realmente faz — não de
// uma descrição vaga de "estilo bonito":
//
//  1. A NARRAÇÃO MANDA. Não há apresentador na tela: há uma voz conduzindo um raciocínio. O
//     roteiro é o produto; a imagem é serviço dele.
//  2. A IMAGEM OBEDECE À NARRAÇÃO, FRASE A FRASE. Quando a voz diz "as prateleiras esvaziaram",
//     naquele instante aparecem prateleiras vazias. Ilustração genérica ("pessoas felizes num
//     escritório") mata o formato — o que prende não é a imagem bonita, é a coincidência exata
//     entre o que se ouve e o que se vê.
//  3. O VISUAL É GRÁFICO, NÃO FILMAGEM. Recorte de papel, colagem de jornal, textura craft,
//     paleta fechada. Foto realista puxa a atenção pro cenário; o recorte mantém a atenção no
//     argumento.
//     ⚠️ "Gráfico" NÃO quer dizer "vazio". A lei fala do MATERIAL (papel, não fotografia), não da
//     quantidade — e ler as duas coisas juntas foi um erro nosso: o teto de três elementos com
//     "muito espaço vazio" entregava quadro pobre, e a peça lia como pausada mesmo com o corte no
//     ritmo certo. A referência do formato povoa a cena (cinco a sete recortes que se relacionam)
//     mantendo UMA ideia legível. Cena cheia com uma ideia; nunca cena vazia, nunca duas ideias.
//
// O preset não é um pipeline novo: é o pipeline do Short com outra direção. Reaproveitar em vez
// de duplicar mantém uma única cadeia de geração, retry e montagem para manter viva.

// VoxPreset — valor de VideoOptions.Preset que liga esta direção.
const VoxPreset = "vox"

// voxExtraMax — teto (em runas) de CADA texto extra vindo do console (estilo/direção/roteiro).
// O console já valida, mas o engine valida DE NOVO: o /v1/* aceita chamada de qualquer cliente
// com token, e texto sem teto aqui viraria prompt gigante indo direto pro modelo (custo + abuso).
const voxExtraMax = 2000

// voxExtra — sanitiza um texto extra do preset: apara espaços e aplica o teto voxExtraMax.
// Vazio entra, vazio sai — e vazio significa "só o padrão Vox", que é o comportamento histórico.
func voxExtra(s string) string {
	s = strings.TrimSpace(s)
	if r := []rune(s); len(r) > voxExtraMax {
		return string(r[:voxExtraMax])
	}
	return s
}

// voxImageDirective — a linguagem visual, anexada ao prompt de imagem de CADA beat.
//
// Virou FUNÇÃO (era constante) pra aceitar direção de arte EXTRA vinda da aba /video (estilo
// Vox): o texto do cliente entra DEPOIS do bloco padrão, nunca no lugar dele — o padrão é a lei do formato e o
// extra só refina (paleta, material, época). Extra vazio ⇒ exatamente o texto histórico.
func voxImageDirective(styleExtra string) string {
	if e := voxExtra(styleExtra); e != "" {
		return voxImageDirectiveBase + " ADDITIONAL ART DIRECTION (apply on top of the rules above, never replacing them): " + e
	}
	return voxImageDirectiveBase
}

// Escrito em inglês porque é onde os modelos de imagem são calibrados. Proíbe texto na arte de
// propósito: letra gerada por modelo sai torta e com erro de grafia, e no formato o texto — quando
// existe — é composto depois, com tipografia de verdade.
const voxImageDirectiveBase = ". VISUAL LANGUAGE (follow strictly): cut-paper collage illustration, " +
	"editorial explainer style; layered paper cut-outs with visible torn and scissor-cut edges, " +
	"subtle craft paper grain, halftone newsprint texture, vintage newspaper clippings and " +
	"engraving fragments used as material; flat graphic shapes, bold simplified silhouettes, " +
	"limited palette of three or four colours plus off-white paper, soft drop shadows between " +
	"paper layers as if physically stacked. Composition: ONE clear idea, but BUILD THE SCENE OUT " +
	"— five to seven distinct paper cut-out elements that relate to each other (the subject plus " +
	"supporting props, fragments and small repeated shapes), arranged in a deliberate layout with " +
	"foreground and background layers. Dense and busy is correct; sparse is wrong. Keep the main " +
	"subject dominant and readable at thumbnail size, and keep every element serving the SAME " +
	"single idea. " +
	"NOT photorealistic, NOT a photograph, NOT 3D render, NOT cinematic lighting. " +
	"NO text, NO lettering, NO numbers, NO captions, NO logos, NO watermark anywhere in the frame."

// voxBeatSystem — prompt de segmentação do preset. Difere do genérico em duas coisas que são o
// formato inteiro: o `script` é uma linha de RACIOCÍNIO (não uma legenda solta), e o
// `image_prompt` tem de ilustrar LITERALMENTE o que aquela frase diz.
//
// A instrução "literal" é repetida e exemplificada porque, sem isso, o modelo entrega paisagem
// decorativa: pedir "uma imagem sobre economia" devolve gráfico genérico, e o beat perde a
// sincronia que faz o formato funcionar.
// `extraRules` — regras extras de ESTRUTURA vindas da aba /video, estilo Vox (ex.: "comercial de
// 15s: gancho, problema, solução, prova, CTA"). Entram DEPOIS do bloco padrão, nunca no lugar: o padrão é o
// que garante beats falados + imagem literal, e o extra só redireciona a editoria. Vazio ⇒ o
// prompt histórico, byte a byte.
func voxBeatSystem(count, secs, words, maxChars int, idioma, orient, frame, pos, extraRules string) string {
	base := voxBeatSystemBase(count, secs, words, maxChars, idioma, orient, frame, pos)
	if e := voxExtra(extraRules); e != "" {
		return base + "\n\nREGRAS EXTRAS DO CLIENTE (aplicar POR CIMA das regras acima, sem substituí-las; em conflito, as regras do formato vencem):\n" + e
	}
	return base
}

func voxBeatSystemBase(count, secs, words, maxChars int, idioma, orient, frame, pos string) string {
	return fmt.Sprintf(`Você escreve um vídeo de JORNALISMO EXPLICATIVO %s (estilo documentário animado) e o segmenta em %d BEATS. Cada beat = 1 clipe de ~%d segundos.%s

REGRAS DO FORMATO:
1. A NARRAÇÃO CONDUZ. Não há apresentador. O conjunto dos "script" tem de se ler como UM raciocínio contínuo. Nada de saudação, nada de "neste vídeo você vai ver", nada de call-to-action.
   A ORDEM DOS BEATS É OBRIGATÓRIA e cada posição tem uma função fixa:
   • beat 1 — ABERTURA: um fato concreto ou número que desconcerta. Ele NÃO pode depender de nada dito antes: proibido começar com "a mesma", "isso", "por isso", "hoje ela" ou qualquer referência a algo que o espectador ainda não ouviu.
   • beats do meio — MECANISMO: explicam COMO e POR QUE, um passo por beat, cada um continuando o anterior. É aqui, e só aqui, que entram as limitações e os contrapontos ("mas ela erra…").
   • ÚLTIMO beat — CONCLUSÃO: entrega o que a peça queria dizer, e fecha. É PROIBIDO terminar num problema, numa limitação, num "mas" ou numa ressalva — quem assiste sai com a última frase na cabeça, e terminar no defeito joga fora o argumento inteiro.
   Se o material que você recebeu mencionar a conclusão no meio do texto, MOVA-A para o último beat; a ordem do texto de entrada não manda na ordem dos beats.
   ANTES DE RESPONDER, releia seus beats na ordem: o 1 se sustenta sozinho? o último é uma conclusão e não uma ressalva? Se qualquer resposta for não, reescreva.
2. FRASE FALÁVEL, COM TETO DE TAMANHO. Cada "script" tem NO MÁXIMO %d CARACTERES (~%d palavras) em %s, escrito para ser DITO em voz alta: frases curtas, voz ativa, sem jargão, sem adjetivo enfeitando.
   ⚠️ O TETO É FÍSICO, NÃO ESTILÍSTICO: a frase será FALADA dentro de %d segundos e a locução roda a %.1f caracteres por segundo. Frase mais longa NÃO CABE no clipe — ela é CORTADA NO MEIO e o beat sai com a fala truncada. CONTE OS CARACTERES de cada script antes de responder; passou do teto, reescreva a frase MAIS CURTA (corte o complemento, não o argumento). Uma ideia por beat cabe no teto; duas não cabem — se você precisou de duas, o beat está errado, não o teto. Número concreto vale mais que superlativo. PUREZA DE IDIOMA: o "script" e o "caption" ficam 100%% no idioma pedido, sem UMA palavra em inglês — nem "explosion", nem "boom", nem termo técnico não traduzido. Escreveu palavra estrangeira, reescreva a frase inteira.
3. A IMAGEM OBEDECE À FRASE, LITERALMENTE. O "image_prompt" descreve exatamente a coisa que aquela frase menciona, no instante em que ela é dita. Se o script diz "as prateleiras esvaziaram", o prompt descreve prateleiras vazias — não "um supermercado". Se diz "o custo despencou", descreve uma linha ou uma pilha caindo — não "conceito de economia". Proibido prompt genérico, abstrato ou decorativo: se o prompt serviria para qualquer outra frase, está errado.
   O "image_prompt" nomeia OBJETOS E RELAÇÕES, nunca fotografia: proibido "aerial view", "close-up", "cinematic lighting", "LED glow", "shallow depth of field", "dark room lighting" e qualquer vocabulário de câmera ou de luz. A peça é feita de papel recortado — quem descreve enquadramento e iluminação de foto está pedindo a imagem errada, e o resultado sai meio foto meio colagem.
4. UMA IDEIA POR QUADRO, MAS QUADRO CHEIO. Uma ideia só por imagem — e ela é construída com CINCO A SETE recortes que se relacionam (o assunto principal mais objetos de apoio, fragmentos e formas repetidas). Quadro com dois ou três elementos soltos num vazio está ERRADO: é pobre, não é minimalista. Cheio de coisas servindo à MESMA ideia. Enquadramento %s.
5. DUAS IMAGENS POR BEAT. A frase falada tem duas metades, e cada metade ganha a SUA imagem:
   • "image_prompt" ilustra o que a PRIMEIRA metade da frase diz;
   • "image_prompt_b" ilustra o que a SEGUNDA metade diz.
   As duas têm de mostrar COISAS DIFERENTES — outro objeto, outro momento, outro ângulo do argumento. Repetir a mesma cena com uma variaçãozinha desperdiça o corte: quem assiste vê o quadro trocar e espera informação nova. Se a frase não tiver duas metades distintas, a segunda imagem mostra a CONSEQUÊNCIA do que a primeira mostra.
6. UM EFEITO SONORO DE MATERIAL POR BEAT. O "sfx" é um prompt curto em inglês descrevendo UM som físico e discreto que casa com o que a imagem faz: papel deslizando, whoosh curto, carimbo batendo, recorte assentando, página virando, clique seco. É som de COISA, nunca de ambiente ("tense music", "crowd noise" = errado) e nunca dramático — a colagem é papel sobre a mesa, o som é do papel.

SAÍDA: responda DIRETO só o JSON, SEM raciocínio, SEM <think>:
{"beats":[{"caption":"5-10 palavras em %s","script":"a frase falada, NO MÁXIMO %d caracteres, em %s","image_prompt":"em inglês: a coisa concreta da 1ª metade da frase, descrita visualmente; sem texto","image_prompt_b":"em inglês: a coisa concreta da 2ª metade da frase; DIFERENTE da primeira; sem texto","sfx":"em inglês: um som físico curto de papel/material, 3-6 palavras"}]}
com EXATAMENTE %d beats.`, orient, count, secs, pos, maxChars, words, idioma, secs, charsPerSec, frame, idioma, maxChars, idioma, count)
}

// ─────────────────────────────────────────────────────────────────────────────────────────────
// MOTORES — escolhidos por TESTE, no mesmo quadro e com o mesmo prompt (2026-08-03).
// ─────────────────────────────────────────────────────────────────────────────────────────────

// VoxImageModel — GPT Image 2, agora pela conta de ASSINATURA (adapter dinâmico do bridge).
// Com a saída do agregador em 2026-08-03 o modelo NÃO mudou: é o mesmo GPT Image 2, por outra
// conta. Isso importa porque a escolha abaixo foi feita por TESTE — trocar o modelo aqui
// quebraria as leis do formato, então valia mais reapontar a conta do que aceitar outro motor.
//
// Comparado com nano-banana-pro e flux-2 pro na mesma
// frase ("as prateleiras esvaziaram"):
//   - GPT Image 2: colagem LITERAL (prateleiras vazias com exatamente uma lata esquecida), sangra
//     de borda a borda, paleta fechada. Acerta as leis 2 e 3. ✅
//   - nano-banana-pro: colagem bonita mas ABSTRATA (as prateleiras viram tiras de papel) e
//     flutuando sobre fundo branco com margem — inútil pra vídeo, que precisa sangrar.
//   - flux-2 pro: ignorou "não fotorrealista" e devolveu fotografia em perspectiva.
//
// ⚠️ O aspecto tem de ir junto: sem ele a imagem volta em 3:2 mesmo com aspect="9:16" pedido.
const VoxImageModel = "higgsfield:gpt_image_2"

// voxMotionPrompt — a direção de movimento do preset.
//
// Virou FUNÇÃO (era constante) pra aceitar direção de movimento EXTRA vinda da aba /video,
// estilo Vox ("dolly suave", "planos longos"…). O extra APENDA depois do bloco padrão — a trava de câmera
// e a preservação de identidade continuam valendo sempre. Vazio ⇒ o texto histórico.
func voxMotionPrompt(directionExtra string) string {
	if e := voxExtra(directionExtra); e != "" {
		return voxMotionPromptBase + " ADDITIONAL MOTION DIRECTION (apply on top of the rules above, never replacing them): " + e
	}
	return voxMotionPromptBase
}
//
// 🎞️ A CORREÇÃO DE 2026-08-04, e ela inverte a versão anterior. O prompt antigo PROIBIA movimento
// ("NOTHING is destroyed... the ONLY motion is a very subtle breathing sway... extremely
// restrained"). O resultado era honesto com o texto e errado com o formato: colagem parada, que o
// cliente descreveu como "muito simples". Voltando ao método de origem, o estilo é COLAGEM
// ANIMADA — papel voando, seta que entra e bate, cofre que abre, elemento que desliza pra dentro
// do quadro. O movimento é o que separa o formato de um slideshow com voz por cima.
//
// O QUE A VERSÃO ANTIGA ACERTOU, e por isso sobrevive aqui: modelo i2v trata verbo de ação como
// licença pra REESCREVER a cena — na primeira tentativa o motor apagou o assunto principal (uma
// linha vermelha sumiu em 5s) porque o prompt mandava a tira "deslizar" e os fragmentos "se
// espalharem". A saída não é proibir movimento, é dizer QUEM se move e o que permanece: os
// elementos animam, mas ninguém troca de forma, de cor ou de identidade, e o assunto não sai de
// cena. Animação de recorte de papel, não redesenho.
//
// A CÂMERA continua travada, e isso não é excesso de zelo: câmera à deriva é o que denuncia vídeo
// de IA e quebra a ilusão de papel sobre a mesa. Movimento é dos ELEMENTOS, não da lente.
const voxMotionPromptBase = "Animated paper cut-out collage, stop-motion style, flat lay seen from " +
	"directly above. The paper elements ANIMATE: pieces slide, tilt, pivot and settle; loose " +
	"scraps drift; an element may enter the frame and come to rest. Motion is crisp and " +
	"deliberate, like physical paper moved by hand between frames. " +
	"IDENTITY IS PRESERVED: every element keeps its exact shape, colour and cut-paper texture, " +
	"and the main subject stays in frame and stays recognisable — nothing is redrawn, melted, " +
	"dissolved or replaced. " +
	"The CAMERA is completely fixed: no pan, no tilt, no zoom, no dolly, no parallax, no shake — " +
	"only the paper moves. Not photorealistic, no added text."

// voxFPSDelay — quadros/s efetivos da peça. É a assinatura visual do formato: a animação avança
// em degraus dentro de um arquivo a 30fps, dando o movimento de stop-motion característico.
//
// 12 é a cadência da REFERÊNCIA do formato (breakdown técnico: gráficos renderizados a 12fps
// dentro de composição a 24 — "sem isso a sequência fica sem personalidade"). Começamos em 18
// por cautela, mas 18 dilui o efeito; 12 é o piso da faixa útil (12..24) e é onde o degrau
// vira assinatura em vez de defeito. (Ajustado 2026-08-05; nota: até esta data o valor nem
// chegava ao serviço — o mapeamento fps_delay faltava no body do Shortform.)
const voxFPSDelay = 12

// voxShotSecs — tamanho-alvo de cada PLANO dentro da cena (ver shotSecs em longform.go).
//
// O preset manda a câmera ficar TRAVADA (voxMotionPrompt), e é isso que dá a colagem de papel —
// mas travada por 6 seguidos vira slide. O ritmo vem do corte, não do movimento: a mesma cena
// entra em geral, fecha e vai ao detalhe. E como a arte é chapada e a câmera não anda, o plano
// fechado revela recorte de papel em vez de denunciar ampliação.
const voxShotSecs = 2.2

// voxHeadTrim — segundos descartados do INÍCIO de todo clipe i2v do preset.
//
// O motor recebe a imagem-base como primeiro quadro e leva um instante pra engatar o movimento:
// o clipe abre com a arte PARADA. Numa peça de plano longo isso passa; num explicativo cortado a
// cada 2s é meio segundo de imagem congelada em cada corte — que é exatamente a sensação de
// "slideshow" que o formato não pode ter. Cortar a cabeça faz todo plano entrar com o papel já
// em movimento.
//
// 0,35s é o menor valor que resolve: o clipe gerado tem folga de sobra (6s contra cenas de ~2,5s),
// então não falta material, e o tpad do ffmpeg-service cobre qualquer caso de borda clonando o
// último quadro.
const voxHeadTrim = 0.35

// voxSegundoPlano — o preset parte cada beat em DOIS segmentos: o clipe animado (1ª metade da
// frase) e uma imagem NOVA em Ken Burns (2ª metade).
//
// O problema que isto resolve: nós já tínhamos cortes na quantidade certa (build_shot_cuts recorta
// a cena em planos de ~2,2s), mas o corte caía na MESMA arte com outro enquadramento. O método de
// referência troca de ilustração a cada corte — em 30s ele mostra 13 imagens distintas onde nós
// mostrávamos 6. O que lê como "montagem mais detalhada" não é a técnica de corte, é a densidade
// de arte NOVA por segundo.
//
// Por que imagem e não outro clipe: dobrar os clipes dobraria o item caro da conta (vídeo), e o
// ganho perceptível está na troca de quadro, não em ter movimento em todos eles. A imagem custa
// uma fração e o Ken Burns dá o movimento do segundo plano. Metade dos planos anima de verdade,
// metade tem movimento de câmera — que é, aliás, o que o próprio método de referência faz na mão
// quando um clipe falha.
//
// Consequência: com o corte virando troca de arte, o recorte do mesmo clipe (voxShotSecs) perde a
// função dentro do beat partido e sai — ver shotSecsDoBeat.
const voxSegundoPlano = true

// voxImagem — gera UMA imagem do preset e devolve a URL durável.
//
// Existe como função porque o beat agora pede duas (ver voxSegundoPlano) e porque é aqui que mora
// o resgate de prompt bloqueado: repetir o mesmo prompt recusado por política é gastar as três
// tentativas do retry pra receber a mesma recusa três vezes, e a cena sai da peça em silêncio.
// Bloqueio não é instabilidade — é uma resposta sobre o CONTEÚDO, e a resposta certa é reescrever.
// `styleExtra` — direção de arte extra da aba /video (estilo Vox), apendada à diretiva padrão
// (voxImageDirective).
func (s *Service) voxImagem(ctx context.Context, prompt, aspect, styleExtra string) (string, error) {
	url, err := s.voxImagemUma(ctx, prompt, aspect, styleExtra)
	if err == nil || !erroDePolitica(err) {
		return url, err
	}
	// Reescreve UMA vez. O prompt novo tem de dizer a mesma coisa por outro caminho visual —
	// símbolo, consequência, objeto — porque suavizar o assunto entrega ilustração que não
	// combina com a frase narrada, e aí a lei 2 do formato cai junto.
	novo, rerr := s.textPrime(ctx, voxReescritaSystem, prompt, 300)
	if rerr != nil || strings.TrimSpace(novo) == "" {
		return "", err // devolve o erro ORIGINAL: é ele que explica o que houve
	}
	log.Printf("vox: prompt de imagem bloqueado por política — reescrito e repetido")

	return s.voxImagemUma(ctx, strings.TrimSpace(novo), aspect, styleExtra)
}

// voxImagemUma — uma tentativa: gera pelo motor do preset e persiste os bytes.
// Bridge devolve BYTES; persistir aqui é o que dá URL durável (o safe_fetch do ffmpeg-service não
// alcança o host, por anti-SSRF).
func (s *Service) voxImagemUma(ctx context.Context, prompt, aspect, styleExtra string) (string, error) {
	data, ext, err := s.image.CliImage(ctx, VoxImageModel, prompt+voxImageDirective(styleExtra), aspect, "", nil)
	if err != nil {
		return "", err
	}

	return s.media.PersistBytes(ctx, data, "image", ext)
}

// GenerateVoxScene — gera UMA cena do preset (imagem em colagem + clipe i2v), pra regeneração
// beat a beat da aba /video (estilo Vox) (V2).
//
// POR QUE EXISTE: no fluxo monolítico (GenerateVideoUnified) uma cena torta só se descobre no
// filme pronto — e refazer é pagar a peça INTEIRA de novo. Aqui a cena é a unidade: gera, olha,
// regenera SÓ ela, e a montagem final concatena as cenas aprovadas (/v1/filmassemble).
//
// Reusa exatamente as peças do monolítico — voxImagem (motor + diretiva + resgate de política),
// voxMotionPrompt (com a direção extra) e clipModelOrdered (principal→reserva) — pra cena avulsa
// sair IGUAL à que o filme inteiro geraria. Pipeline paralelo aqui seria o bug clássico da casa:
// duas verdades divergindo em silêncio.
//
// Retorna a URL DURÁVEL do clipe (persistida no nosso storage): a montagem acontece DEPOIS, em
// outro request, e URL de provider expira.
func (s *Service) GenerateVoxScene(ctx context.Context, imagePrompt string, opt VideoOptions) (string, error) {
	imagePrompt = strings.TrimSpace(imagePrompt)
	if imagePrompt == "" {
		return "", fmt.Errorf("cena sem image_prompt — gere o storyboard antes")
	}
	// Mesmo retry do fluxo monolítico: geração de imagem é instável sob carga.
	img, err := retry(ctx, 3, 2*time.Second, func() (string, error) {
		return s.voxImagem(ctx, imagePrompt, opt.Aspect, opt.VoxStyleExtra)
	})
	if err != nil {
		return "", err
	}
	if img == "" {
		return "", fmt.Errorf("a imagem da cena não saiu")
	}
	clipURL, err := s.clipModelOrdered(ctx, opt.VideoProvider, opt.VideoModel, opt.VideoFallback,
		[]string{img}, voxMotionPrompt(opt.VoxDirectionExtra), opt.Duration, opt.Aspect, opt.VideoMagnific)
	if err != nil {
		return "", err
	}
	if clipURL == "" {
		return "", fmt.Errorf("o clipe da cena não saiu")
	}

	return s.media.Persist(ctx, clipURL, "clip", "mp4"), nil
}

// voxReescritaSystem — instrução da reescrita de prompt bloqueado.
const voxReescritaSystem = `Um prompt de imagem foi RECUSADO pela política de conteúdo do gerador. Reescreva-o para passar, mantendo o MESMO significado visual.

REGRAS:
1. Nada de pessoa em sofrimento, ferimento, violência explícita, prisão de pessoas identificáveis, arma apontada, nudez, sangue, nome ou rosto de pessoa real.
2. NÃO amoleça o assunto. Diga a mesma coisa por outro caminho visual: o OBJETO no lugar da pessoa, a CONSEQUÊNCIA no lugar do ato, o SÍMBOLO no lugar da cena (a cela vazia, as grades, a colher, o buraco na parede).
3. É colagem de papel recortado, não fotografia — descreva formas e objetos, nunca enquadramento ou iluminação de câmera.
4. Em inglês, uma frase, sem texto ou letras na arte.

SAÍDA: só o prompt reescrito. Sem aspas, sem explicação, sem preâmbulo.`

// erroDePolitica — a falha foi recusa por CONTEÚDO (e não instabilidade ou falta de crédito)?
//
// Detecta pelo texto porque é o que chega até aqui: o bridge e os providers embrulham a recusa do
// motor numa string. A distinção importa porque muda a ação — instabilidade se resolve repetindo,
// recusa só se resolve reescrevendo (ver voxImagem).
func erroDePolitica(err error) bool {
	if err == nil {
		return false
	}
	s := strings.ToLower(err.Error())
	for _, p := range []string{
		"content policy", "content_policy", "safety", "blocked", "bloquead",
		"violat", "moderation", "prohibited", "not allowed", "flagged",
	} {
		if strings.Contains(s, p) {
			return true
		}
	}

	return false
}

// shotSecsDoBeat — tamanho-alvo do plano DESTE segmento (ver shotSecs).
//
// Com o segundo plano ligado, o beat já foi partido em dois segmentos curtos com arte diferente:
// o corte virou troca de quadro, que é o que se queria. Recortar de novo o clipe por dentro daria
// dois cortes em ~2s — pisca-pisca, não montagem.
func shotSecsDoBeat(preset string, temSegundoPlano bool) float64 {
	if temSegundoPlano {
		return 0
	}

	return shotSecs(preset)
}

// sfxGain — ganho do SFX de material do Vox. Discreto de propósito (o default do serviço é 0.9,
// que é ganho de EFEITO DRAMÁTICO): a doutrina do formato é "só uma coisa alta por vez", e a
// coisa alta é a narração — o papel farfalha por baixo, não por cima.
func sfxGain(sfx string) float64 {
	if sfx == "" {
		return 0
	}

	return 0.55
}

// headTrim — segundos a descartar do início do clipe, por preset (ver voxHeadTrim).
func headTrim(preset string) float64 {
	if preset == VoxPreset {
		return voxHeadTrim
	}

	return 0
}

// voxStyle — estilo do modelo de VÍDEO no preset. O movimento é de colagem animada: câmera
// quase parada, elementos deslizando em plano, nada de movimento cinematográfico de câmera —
// que é justamente o que denuncia "vídeo de IA" e quebra a ilusão de papel recortado.
const voxStyle = "vox"

func init() {
	videoStyleDirective[voxStyle] = "Style: animated paper cut-out collage. The camera barely " +
		"moves; elements slide and pivot flatly in plane like physical paper pieces on a table. " +
		"Minimal, deliberate motion. No parallax fly-through, no dolly, no handheld shake, no " +
		"photorealistic rendering."
}
