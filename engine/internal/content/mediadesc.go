// mediadesc.go — LEITURA da mídia que vai ser publicada, pra que a copy fale do arquivo REAL.
//
// O buraco que isto fecha: o /v1/text sempre escreveu a partir de `keyword` + `brief`/`facts`
// (a pesquisa). No upload manual (compositor "➕ Novo post") NÃO existe pesquisa — brief e facts
// chegam vazios e sobra o keyword, o texto que o cliente digitou. O resultado tinha cara de
// aleatório porque era: a IA nunca viu a foto nem o vídeo que ia junto do post. Descrever a mídia
// e injetar essa descrição no redator é o que ancora a legenda no que está na tela.
//
// A visão já era usada em outros três lugares (juiz da malha 3D, ficha de personagem, brief visual
// do carrossel) — aqui é o mesmo motor com um prompt de LEGENDA, não de direção de arte.
//
// Vídeo: o provedor de visão lê imagem, não vídeo. Extraímos um frame (media.LastFrame, o mesmo
// caminho do /v1/lastframe) e descrevemos ele. Um frame não é o vídeo inteiro — a descrição diz
// isso explicitamente pro redator não afirmar o que não pode ver.
//
// White-label (#6): erros não citam o provedor.
package content

import (
	"context"
	"encoding/base64"
	"fmt"
	"strings"
	"time"
)

// mediaDescribePrompt — prompt de LEGENDA (não de direção de arte). O que interessa aqui é o que
// um leitor veria e comentaria, e não a paleta ou a luz (isso é o carouselVisualBriefPrompt).
//
// A regra anti-dúvida repete a lição da ficha de personagem: descrever a própria incerteza
// ("parece ser", "possivelmente") envenena o texto seguinte, porque o redator trata a ressalva
// como fato. O que não dá pra ver, simplesmente se omite.
const mediaDescribePrompt = `Descreva esta mídia para quem vai escrever a legenda de uma publicação em rede social sobre ela.
Diga, de forma concreta e específica: o que é o assunto principal; quem ou o que aparece; o que está acontecendo; o cenário e o contexto; qualquer texto legível na imagem (transcreva-o); e o clima/tom da cena.
Escreva em português do Brasil, em prosa corrida, no máximo 8 linhas.
NUNCA escreva sobre a sua própria incerteza: nada de "parece", "possivelmente", "não é possível determinar", "aparentemente". O que você não consegue ver, você simplesmente omite.
Não invente número, marca, nome próprio nem data que não estejam visíveis.`

// videoFrameNote — aviso anexado à descrição de VÍDEO. Sem ele o redator escreve sobre o vídeo
// inteiro como se tivesse assistido, e afirma movimento/narrativa que ninguém verificou.
const videoFrameNote = "\n\n(Observação: o material acima descreve UM QUADRO extraído do vídeo, não o vídeo inteiro. Não afirme o que acontece ao longo do vídeo — fale do assunto, não de uma narrativa que você não viu.)"

// DescribeMedia — lê a mídia (imagem ou vídeo) e devolve uma descrição em PT-BR pronta pra
// alimentar o redator do /v1/text. `kind` aceita "video" (qualquer outro valor = imagem); vazio
// deduz pela extensão da URL.
//
// Erro NÃO é fatal para quem chama: sem descrição o texto volta a sair só do keyword, que é o
// comportamento antigo. O chamador decide se prefere avisar ou seguir.
func (s *Service) DescribeMedia(ctx context.Context, mediaURL, kind string) (string, error) {
	url := strings.TrimSpace(mediaURL)
	if url == "" {
		return "", fmt.Errorf("leitura da mídia: sem mídia")
	}

	ehVideo := strings.EqualFold(strings.TrimSpace(kind), "video")
	if strings.TrimSpace(kind) == "" {
		ehVideo = ehExtensaoDeVideo(url)
	}

	// Vídeo → extrai um frame e descreve o frame. É o mesmo extrator do /v1/lastframe.
	frameURL := url
	if ehVideo {
		f, err := s.media.LastFrame(ctx, url)
		if err != nil || strings.TrimSpace(f) == "" {
			return "", fmt.Errorf("leitura da mídia: não foi possível extrair um quadro do vídeo")
		}
		frameURL = f
	}

	// INLINE (base64), nunca por link: o nosso acervo recusa o download de terceiros (403) e
	// mandar a URL devolvia "não consegui ler a imagem" com status 200 — erro disfarçado de
	// resposta. Mesma lição do vision.go.
	data := buscarBytes(ctx, frameURL)
	if len(data) == 0 {
		return "", fmt.Errorf("leitura da mídia: arquivo não pôde ser lido")
	}
	dataURL := "data:" + sniffImageMime(data) + ";base64," + base64.StdEncoding.EncodeToString(data)

	ctx, cancel := context.WithTimeout(ctx, 90*time.Second)
	defer cancel()
	out, err := s.image.VisionDescribe(ctx, "", mediaDescribePrompt, dataURL)
	if err != nil {
		return "", err
	}
	desc := stripCJK(strings.TrimSpace(out))
	if desc == "" {
		return "", fmt.Errorf("leitura da mídia: leitura vazia")
	}
	if ehVideo {
		desc += videoFrameNote
	}

	return desc, nil
}

// ehExtensaoDeVideo — palpite pela extensão quando o chamador não informa o tipo. Query string é
// descartada antes (URL assinada do storage traz `?X-Amz-...` e quebraria o sufixo).
func ehExtensaoDeVideo(url string) bool {
	limpa := strings.ToLower(url)
	if i := strings.Index(limpa, "?"); i >= 0 {
		limpa = limpa[:i]
	}
	for _, ext := range []string{".mp4", ".mov", ".webm", ".m4v", ".avi", ".mkv"} {
		if strings.HasSuffix(limpa, ext) {
			return true
		}
	}

	return false
}
