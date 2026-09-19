package content

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"regexp"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
	"github.com/redfoxcode/reachyn/engine/internal/identity"
)

// linhaDeEstiloLock — a linha de ESTILO DE ARTE que o gerador de lock sempre escreve por
// último ("Realistic rendering style…", "photorealistic eye detail…"). Sai da FICHA DE
// MALHA: ela só serve pra puxar o render de volta pro fotorrealismo.
var linhaDeEstiloLock = regexp.MustCompile(`(?im)^.*(photorealistic|realistic rendering|rendering style|art style|8k|cinematic).*$[\r\n]*`)

// meshSheetDirective — o enquadramento que o gerador de malha PRECISA e que a imagem-base
// raramente tem: corpo INTEIRO em perfil, fundo branco limpo com margem. Neutro de espécie
// de propósito (personagem pode ser bicho ou humanoide).
//
// Vem NA FRENTE do texto do personagem, com pesos de atenção (sintaxe (texto:1.3) do
// ComfyUI) — a 1ª versão apensava no FIM e o SDXL devolveu um BUSTO: o CLIP corta em 77
// tokens por chunk, e depois de 13 linhas de lock a diretriz chegava diluída.
//
// ESTATUETA DE VINIL, não foto: a saída pro corpo limpo é de ESTILO, não de vocabulário —
// sem nenhuma palavra de sexo no prompt, o estilo fotorrealista desenhava anatomia de
// cachorro em quase toda ficha (7 de 8 reprovações do juiz em 2026-07-31) e o botão virava
// beco sem saída. Estatueta colecionável tem superfície lisa POR DEFINIÇÃO — e a malha só
// aproveita a FORMA; textura/pelo não entram no GLB de qualquer jeito.
const meshSheetDirective = "(character model sheet for 3D, full body wide shot in strict side profile:1.3), the ENTIRE body visible from head to toe or from nose to tail, all legs and paws on the ground, standing in a neutral pose, (clean vinyl collectible figurine style, smooth simplified surfaces, smooth featureless underbelly:1.2), (pure white seamless studio background, bright even lighting:1.2), generous empty margin around the subject.\n"

// meshSheetNegative — o que a FICHA não pode ser. "close-up/portrait/bust": o prior de
// retrato do SDXL é forte demais pra só o positivo segurar (foi o busto da 1ª rodada E2E).
// "shadow": a sombra suave no chão virou DISCO fundido nas patas da malha. SEM nenhuma
// palavra de sexo — decisão de 2026-07-31 (3 malhas macho): negar sexo é mencionar sexo, e
// mencionar atrai; quem fiscaliza o personagem é o juiz de visão, não a palavra no prompt.
const meshSheetNegative = "close-up, portrait, cropped, partial body, headshot, bust, zoomed in, dark background, gray background, shadow, cast shadow, drop shadow, reflection"

// juizFichaPrompt — o juiz de VISÃO da ficha: compara a imagem com o texto do personagem e
// responde JSON. É o "fluxo que vê o texto": o texto é o gabarito, a visão confere.
//
// O juiz da ficha NÃO fiscaliza anatomia — de propósito: detalhe de baixo-ventre na ficha
// vira um CALOMBO na malha, e a receita "limpar" APLAINA exatamente essa região antes do
// juiz da malha (que aí sim confere o resultado final). Reprovar ficha por anatomia
// travava o botão em beco sem saída (2026-07-31: 11 fichas seguidas reprovadas, quase
// todas por isso, com enquadramento e cores PERFEITOS) — sendo que a malha delas sairia
// limpa depois do Blender. Ficha responde por: enquadramento, fundo, semelhança e ESTILO.
//
// ESTILO virou critério (4) na noite de 2026-07-31: a linha boa com wrap "produto" devolveu
// uma FOTOGRAFIA e o juiz aprovou — fotorrealismo na ficha é a porta de volta da anatomia e
// da proporção errada na malha. Figurine é o contrato; foto reprova.
const juizFichaPrompt = `You are a quality inspector for an input sheet that will feed an image-to-3D generator. Only the overall SHAPE matters: the mesh will be untextured, its underside gets smoothed automatically and any ground shadow gets cut off automatically afterwards. Compare the image against the character description below and answer ONLY a JSON object {"aprovada": true|false, "motivo": "<short reason when reproved>"}.
Reprove ONLY for one of these four problems:
1) the character is NOT shown in FULL BODY (head to feet or nose to tail, with empty margin around it);
2) the background contains OTHER OBJECTS or scenery, or is strongly colored/dark instead of white-ish;
3) it is clearly a DIFFERENT character: wrong species/type, completely different color scheme, or the main accessory (e.g. the collar) is absent;
4) it looks like a photorealistic PHOTOGRAPH of a real animal or person (realistic fur/skin detail) instead of a stylized smooth collectible-figurine render.
Everything else is acceptable — do NOT reprove for: soft ground shadows under the feet, anything on the belly/underside, small charms/tags/details missing or simplified, illegible text, color shade differences, texture or fur details missing or simplified by the stylization.
Character description:
`

// meshSheetI2I — a diretriz da ficha na linha BOA (i2i ancorado de assinatura, na
// imagem-base): sem pesos de atenção (sintaxe do SDXL) e com a identidade vindo da
// REFERÊNCIA — é o personagem DE VERDADE virando model sheet, não um genérico da espécie.
// Sem nenhuma menção a sexo, como todo o fluxo 3D.
//
// ESTATUETA também aqui: a 1ª versão da linha boa (2026-07-31, tarde) pedia só "the SAME
// character" com estilo "produto" — e a âncora realista devolveu uma FOTOGRAFIA de
// dachshund, com anatomia de macho na MEL (fêmea) e malha reprovada por proporção. A
// convenção figurine (decisão 6cee66d) vale pro i2i igual: identidade vem da referência,
// SUPERFÍCIE vem do estilo.
const meshSheetI2I = "Recreate the subject from the reference image as a character model sheet for 3D: the SAME character with the same identity, colors and accessories — but rendered as a clean vinyl collectible figurine with smooth simplified surfaces and a smooth featureless underbelly, NOT a photograph — shown in FULL BODY in strict side profile, the entire body visible from head to toe or from nose to tail, all legs on the ground, standing in a neutral pose, on a pure white seamless studio background with bright even lighting, no ground shadow, and a generous empty margin around the subject."

// meshSheetZ — a diretriz da reserva LOCAL no Z-Image Turbo (linguagem natural, sem pesos
// de atenção nem negativo — o modelo é distilled com cfg 1.0 e OBEDECE o positivo). Vai com
// estilo "cru": o wrap de estilo sabotaria o enquadramento, como sabotou no SDXL.
const meshSheetZ = "A character model sheet for 3D of the character described below: full body in strict side profile, the entire body visible from head to toe or from nose to tail, all legs on the ground, standing in a neutral pose with a smooth clean underside, clean vinyl collectible figurine style with smooth simplified surfaces, on a pure white seamless studio background with bright even lighting, no ground shadow, and a generous empty margin around the subject.\nCharacter:\n"

// juizMalhaPrompt — o juiz de VISÃO da malha pronta: recebe renders (sem textura) e o texto.
const juizMalhaPrompt = `You are a strict quality inspector for a generated 3D mesh. The image is a render of the UNTEXTURED mesh (uniform gray is expected and fine). Compare with the character description below and answer ONLY a JSON object {"aprovada": true|false, "motivo": "<short reason when reproved>"}.
Reprove when ANY of these fails:
1) the mesh does not look like the described character (species/type, proportions, accessories);
2) there is a disc, plate, pedestal or floor fused to the feet;
3) there are extra body parts, floating fragments, big holes, or genitals visible;
4) a major body part (head, tail, ear, leg) is missing or detached.
Character description:
`

// 🧊 Malha 3D — imagem → GLB, no ComfyUI do operador. Devolve as URLs duráveis (malha e,
// quando personagem, a ficha usada — o caller usa a ficha pra regenerar SÓ a malha).
//
// FLUXO DO PERSONAGEM (lock != ""): o Hunyuan3D tem conditioning SÓ VISUAL — não lê texto.
// Então o TEXTO entra de dois jeitos: gera a FICHA DE MALHA (sem nenhuma menção a sexo) e
// vira o GABARITO do juiz de visão, que reprova ficha fora do padrão antes de gastar minutos
// de GPU na malha. Sem fallback silencioso na ficha: falhou = erro claro.
func (s *Service) Mesh(ctx context.Context, imageURL, lock, style string, seed int64) (meshURL, fichaURL string, err error) {
	insumo := imageURL
	if strings.TrimSpace(lock) != "" {
		ficha, ferr := s.meshSheet(ctx, imageURL, lock, style)
		if ferr != nil {
			return "", "", ferr
		}
		insumo, fichaURL = ficha, ficha
	}
	data, ext, err := s.mesh.Malha(ctx, insumo, seed)
	if err != nil {
		return "", "", err
	}
	url, err := s.media.PersistBytes(ctx, data, "mesh", ext)

	return url, fichaURL, err
}

// meshSheet — gera a ficha de malha e SUBMETE AO JUIZ (até 3 tentativas; reprovadas são
// descartadas antes de custar GPU de malha). 3 reprovações = ERRO CLARO com o motivo — a
// 1ª versão "seguia com a última" e o resultado era meshear uma ficha sabidamente ruim,
// queimando minutos de GPU num resultado que o juiz da malha reprovaria de novo (o Colab
// "gerando sem parar", 2026-07-31). Juiz indisponível não bloqueia: segue com log.
func (s *Service) meshSheet(ctx context.Context, baseURL, lock, style string) (string, error) {
	// A descrição da ficha perde o sexo (SemSexo) E a linha de ESTILO do lock ("Realistic
	// rendering style… photorealistic eye detail"): flagrado no /history de 2026-07-31 —
	// essa linha + o wrap "realista" do StyledPrompt afogavam a estatueta de vinil num
	// oceano de fotorrealismo, e o fotorrealismo é que desenhava anatomia e variava os cães.
	descricao := linhaDeEstiloLock.ReplaceAllString(identity.SemSexo(lock), "")
	motivoFinal := ""
	for tentativa := 1; tentativa <= 3; tentativa++ {
		url, data, ferr := s.gerarFicha(ctx, baseURL, descricao)
		if ferr != nil {
			return "", ferr
		}
		aprovada, motivo := s.julgar(ctx, juizFichaPrompt+descricao, [][]byte{data})
		if aprovada {
			log.Printf("malha: ficha aprovada pelo juiz (tentativa %d) — %s", tentativa, url)

			return url, nil
		}
		motivoFinal = motivo
		log.Printf("malha: ficha REPROVADA pelo juiz (tentativa %d: %s) — %s", tentativa, motivo, url)
	}

	// 🐛 Era `fmt.Errorf`, ou seja, classe Transient (o default de quem não se declara): a borda
	// trocava esta mensagem — que diz EXATAMENTE o que fazer — pelo 502 genérico "a IA está
	// indisponível no momento". Mentira dupla, flagrada no teste de 2026-08-02 com o personagem
	// "Drone de Segurança Alpha": a IA estava perfeitamente disponível e trabalhou 3 minutos; o
	// que não fechava era o DADO — o lock descrevia um "octocopter drone" e a imagem-base
	// mostrava esse drone sobre pernas humanas, então o juiz reprovou as 3 fichas, com razão.
	// Reprovação do juiz é Config por definição: retentar não muda nada enquanto a descrição e a
	// imagem se contradisserem — quem tem de agir é o usuário. Config vira 422 na borda e
	// preserva a mensagem, que é a única coisa aqui que resolve o problema de quem está na tela.
	return "", gerr.Configf("malha: nenhuma ficha passou no juiz em 3 tentativas (último motivo: %s) — a descrição do personagem e a imagem-base precisam combinar; ajuste uma das duas e tente de novo", motivoFinal)
}

// gerarFicha — a ficha nasce na linha BOA: i2i ANCORADO na imagem-base do
// personagem (identidade real). O SDXL do Colab era o teto de qualidade do fluxo inteiro —
// "está horrível" foi o veredito do operador (2026-07-31) — e vira RESERVA: entra só quando
// a linha boa falha (bridge fora) ou quando não há imagem-base pra ancorar. Estilo
// "3d" (Pixar — anatomia fora por convenção, ver diretriz 6cee66d) nas DUAS linhas: o
// "produto" da 1ª versão embrulhava a ficha em fotorrealismo e a âncora realista virava
// FOTO — anatomia e proporção erradas de volta (regressão flagrada na noite de 2026-07-31).
func (s *Service) gerarFicha(ctx context.Context, baseURL, descricao string) (string, []byte, error) {
	if strings.TrimSpace(baseURL) != "" {
		prompt := meshSheetI2I + "\nCharacter reference notes:\n" + descricao
		if u, err := s.i2iAncorado(ctx, prompt, "4:3", "3d", []string{baseURL}); err == nil && u != "" {
			durable := s.media.Persist(ctx, u, "image", "jpg")
			if data := buscarBytes(ctx, durable); len(data) > 0 {
				return durable, data, nil
			}
			log.Printf("malha: ficha da linha boa gerada mas ilegível pro juiz — caindo pro Estúdio local")
		} else {
			log.Printf("malha: linha boa da ficha falhou (%v) — caindo pro Estúdio local", err)
		}
	}
	// Reserva local: Z-Image Turbo primeiro (o modelo bom do Estúdio — 9 passos, ~20s na
	// L4); o SDXL antigo só se o Z falhar (sessão do Colab sem os arquivos dele).
	data, ext, err := s.image.ComfyImage(ctx, "zimage-t2i", meshSheetZ+descricao, "", "4:3", "cru", nil, "", 0)
	if err != nil {
		log.Printf("malha: reserva Z-Image falhou (%v) — tentando o SDXL antigo", err)
		data, ext, err = s.image.ComfyImage(ctx, "sdxl-t2i", meshSheetDirective+descricao, meshSheetNegative, "4:3", "3d", nil, "", 0)
	}
	if err != nil {
		return "", nil, fmt.Errorf("malha: ficha de malha falhou nas duas linhas (%w) — confira o CLI_BRIDGE_URL e o COMFY_URL", err)
	}
	url, perr := s.media.PersistBytes(ctx, data, "image", ext)
	if perr != nil || url == "" {
		return "", nil, fmt.Errorf("malha: persistir a ficha falhou: %w", perr)
	}

	return url, data, nil
}

// buscarBytes — baixa uma URL durável do NOSSO acervo (pro juiz, que recebe bytes inline).
func buscarBytes(ctx context.Context, url string) []byte {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil
	}
	res, err := (&http.Client{Timeout: 60 * time.Second}).Do(req)
	if err != nil {
		return nil
	}
	defer res.Body.Close()
	if res.StatusCode != http.StatusOK {
		return nil
	}
	data, _ := io.ReadAll(io.LimitReader(res.Body, 30<<20))

	return data
}

// JulgarMalha — o juiz de visão da MALHA pronta: renders (bytes PNG) contra o texto do
// personagem. Reprova se QUALQUER vista reprovar.
func (s *Service) JulgarMalha(ctx context.Context, renders [][]byte, lock string) (bool, string) {
	return s.julgar(ctx, juizMalhaPrompt+identity.SemSexo(lock), renders)
}

// julgar — roda o juiz de visão numa ou mais imagens (bytes PNG → data URL inline; o
// provedor de visão não baixa do nosso S3). Juiz INDISPONÍVEL aprova com log: o juiz é
// fiscal, não gerador — bloquear a entrega porque o fiscal está fora seria pior.
func (s *Service) julgar(ctx context.Context, prompt string, imagens [][]byte) (bool, string) {
	for i, img := range imagens {
		if len(img) == 0 {
			continue
		}
		vctx, cancel := context.WithTimeout(ctx, 90*time.Second)
		resp, err := s.image.VisionDescribe(vctx, "", prompt, "data:image/png;base64,"+base64.StdEncoding.EncodeToString(img))
		cancel()
		if err != nil {
			log.Printf("malha: juiz de visão indisponível (%v) — aprovando sem conferir", err)

			return true, ""
		}
		var v struct {
			Aprovada bool   `json:"aprovada"`
			Motivo   string `json:"motivo"`
		}
		if jerr := json.Unmarshal([]byte(extractJSON(resp)), &v); jerr != nil {
			log.Printf("malha: veredito ilegível do juiz (%.120s) — aprovando sem conferir", resp)

			return true, ""
		}
		if !v.Aprovada {
			return false, fmt.Sprintf("vista %d: %s", i+1, v.Motivo)
		}
	}

	return true, ""
}

// MeshNos — quais nós de malha o servidor expõe. Lista vazia com erro nil significa uma coisa
// específica e útil: o ComfyUI está de pé, mas sem o gerador instalado.
func (s *Service) MeshNos(ctx context.Context) ([]string, error) {
	return s.mesh.Nos(ctx)
}
