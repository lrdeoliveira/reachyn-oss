// Package mesh — geração de MALHA 3D a partir de uma imagem, no ComfyUI do operador.
//
// PRA QUE EXISTE: fechar o ciclo 3D do FoxAssets sem conta paga de serviço de malha. Até
// 2026-07-30 a malha só entrava por UPLOAD (gerada fora, em Tripo/Meshy/Blender) — a aba 3D
// tinha acervo, viewer e âncora de ângulo, mas nada que produzisse a geometria. Com a malha
// vinda daqui, o caminho inteiro passa a ser do estúdio: imagem → malha → âncora no ângulo do
// plano → ControlNet (img-local-pose) → e a exportação pro Unity que já existe.
//
// POR QUE ISTO NÃO RODA NO MAC: os modelos de malha rodam em CUDA, e o ComfyUI do Mac é Metal.
// Por isso o motor é o mesmo ComfyUI remoto que já serve imagem e vídeo (Colab/GPU), e não uma
// segunda infraestrutura.
//
// O MOTOR É NATIVO DO COMFYUI — e essa escolha é o coração deste arquivo. Até 2026-07-31 aqui
// morava o grafo do TRELLIS.2, que exigia um custom node, SEIS extensões CUDA compiladas com ABI
// presa à versão exata de torch+python, e acesso a um repo GATED da Meta (DINOv3, aprovação
// manual). Três tentativas de instalar, zero malha, e duas delas derrubaram o servidor inteiro —
// levando junto a imagem e o vídeo, que funcionavam.
//
// O Hunyuan3D é suportado pelos nós NATIVOS do ComfyUI (`Hunyuan3Dv2Conditioning`,
// `VAEDecodeHunyuan3D`, `VoxelToMesh`, `SaveGLB`): nenhum custom node, nenhuma extensão
// compilada, nenhum repo gated, e o torch fica intocado — instalar 3D deixa de poder quebrar o
// resto. O `SaveGLB` do core ainda escreve o GLB em Python puro e PUBLICA no history
// (`ui.3d`), que é justamente o que o exportador do TRELLIS não fazia. Instalar = baixar UM
// arquivo: scripts/comfy/colab_3d.py.
package mesh

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math/rand/v2"
	"mime/multipart"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/gerr"
)

// comfyHTTP — teto alto: gerar malha + texturizar leva minutos, e a fila do ComfyUI é serial
// (a malha pode esperar uma imagem ou um clipe terminar antes de começar).
var comfyHTTP = &http.Client{Timeout: 1800 * time.Second}

type Client struct {
	comfyURL string
}

// New devolve o cliente de malha. baseURL vazio = motor desligado: as chamadas falham com erro
// de CONFIG na hora, sem tentar rede — mesmo contrato dos outros motores locais.
func New(baseURL string) *Client {
	return &Client{comfyURL: strings.TrimRight(strings.TrimSpace(baseURL), "/")}
}

// Ligado diz se há motor configurado — o console usa pra não oferecer o botão que falharia.
func (c *Client) Ligado() bool { return c.comfyURL != "" }

// catalogo — o /object_info inteiro. Serve para DOIS usos: saber quais nós existem e, na hora
// de montar o grafo, preencher os campos obrigatórios com o default DO SERVIDOR.
func (c *Client) catalogo(ctx context.Context) (map[string]any, error) {
	if c.comfyURL == "" {
		return nil, gerr.Configf("malha 3D: motor local não configurado (COMFY_URL)")
	}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/object_info", nil)
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return nil, fmt.Errorf("malha 3D: %w", err)
	}
	defer resp.Body.Close()
	corpo, _ := io.ReadAll(io.LimitReader(resp.Body, 64<<20))
	var info map[string]any
	if jerr := json.Unmarshal(corpo, &info); jerr != nil {
		return nil, fmt.Errorf("malha 3D: %w", naoEhComfy(resp.StatusCode, corpo))
	}

	return info, nil
}

// completarObrigatorios — preenche o que o nó exige e o grafo não mandou, com o default do
// catálogo. Os nós do TRELLIS declaram tudo como `required` (inclusive `verbose` e uma dúzia de
// parâmetros de sampler com default), então um grafo "só com o essencial" é RECUSADO inteiro:
// "Required input is missing: shape_rescale_t". Copiar quarenta valores à mão envelheceria mal —
// o servidor é quem sabe o contrato da versão instalada.
func completarObrigatorios(grafo map[string]any, info map[string]any) {
	for _, no := range grafo {
		n, ok := no.(map[string]any)
		if !ok {
			continue
		}
		classe, _ := n["class_type"].(string)
		inputs, _ := n["inputs"].(map[string]any)
		spec, _ := info[classe].(map[string]any)
		entrada, _ := spec["input"].(map[string]any)
		req, _ := entrada["required"].(map[string]any)
		for campo, def := range req {
			if _, jaTem := inputs[campo]; jaTem {
				continue
			}
			d, _ := def.([]any)
			if len(d) == 0 {
				continue
			}
			// Campo de LISTA (enum): o default é a primeira opção.
			if opcoes, elista := d[0].([]any); elista {
				if len(opcoes) > 0 {
					inputs[campo] = opcoes[0]
				}

				continue
			}
			// Campo simples: o default vem no dicionário de metadados, quando existe.
			if len(d) > 1 {
				if meta, emapa := d[1].(map[string]any); emapa {
					if v, tem := meta["default"]; tem {
						inputs[campo] = v

						continue
					}
				}
			}
			// Sem default declarado: um zero do tipo é melhor que deixar faltando — o erro do
			// servidor vira específico ("valor inválido") em vez de "campo ausente".
			switch d[0] {
			case "INT":
				inputs[campo] = 0
			case "FLOAT":
				inputs[campo] = 0.0
			case "BOOLEAN":
				inputs[campo] = false
			case "STRING":
				inputs[campo] = ""
			}
		}
	}
}

// nosNativos — o que o grafo usa. Todos vêm do ComfyUI de fábrica; se algum faltar, o servidor
// está numa versão velha demais, e o remédio é atualizar o ComfyUI, não instalar extensão.
var nosNativos = []string{
	"ImageOnlyCheckpointLoader", "CLIPVisionEncode", "Hunyuan3Dv2Conditioning",
	"EmptyLatentHunyuan3Dv2", "ModelSamplingAuraFlow", "KSampler",
	"VAEDecodeHunyuan3D", "VoxelToMesh", "SaveGLB",
}

// Nos — diagnóstico honesto do motor de malha. Devolve o que ESTÁ pronto: os nós nativos
// presentes e, se houver, o checkpoint Hunyuan3D instalado.
//
// A pergunta que isto responde não é "o servidor respondeu?" — é "ele sabe gerar malha AGORA?".
// São coisas diferentes: o ComfyUI sobe perfeitamente sem o checkpoint, e o operador só
// descobriria minutos depois de clicar. Lista vazia = de pé, mas sem gerador.
func (c *Client) Nos(ctx context.Context) ([]string, error) {
	info, err := c.catalogo(ctx)
	if err != nil {
		return nil, err
	}
	var nos []string
	for _, n := range nosNativos {
		if _, ok := info[n]; ok {
			nos = append(nos, n)
		}
	}
	// Sem checkpoint não há malha, por mais nós que existam. Reportar isso aqui é o que faz o
	// botão sumir da aba 3D em vez de falhar no clique.
	if ckpt := escolherCheckpoint(info); ckpt != "" {
		nos = append(nos, "checkpoint:"+ckpt)
	} else {
		nos = nil
	}

	return nos, nil
}

// escolherCheckpoint — qual modelo de malha está instalado, perguntando ao servidor em vez de
// fixar um nome. O `ImageOnlyCheckpointLoader` publica no /object_info a lista de arquivos que
// ele enxerga; procuramos ali um Hunyuan3D.
//
// Preferimos o 2.1 quando os dois estão presentes: gera com resolução maior (4096 contra 3072)
// e é o que a Comfy-Org usa no template mais recente.
func escolherCheckpoint(info map[string]any) string {
	nome, _ := info["ImageOnlyCheckpointLoader"].(map[string]any)
	entrada, _ := nome["input"].(map[string]any)
	req, _ := entrada["required"].(map[string]any)
	campo, _ := req["ckpt_name"].([]any)
	if len(campo) == 0 {
		return ""
	}
	opcoes, _ := campo[0].([]any)
	var v20 string
	for _, o := range opcoes {
		s, _ := o.(string)
		l := strings.ToLower(s)
		if !strings.Contains(l, "hunyuan") || !strings.Contains(l, "3d") {
			continue
		}
		if strings.Contains(l, "2.1") || strings.Contains(l, "v2_1") || strings.Contains(l, "v2-1") {
			return s
		}
		if v20 == "" {
			v20 = s
		}
	}

	return v20
}

// Malha — imagem → GLB. Devolve os BYTES (o caller persiste, mesmo contrato dos outros motores
// locais: o ComfyUI serve arquivo, não URL pública).
//
// Sem fallback: não existe "outro motor de malha" pra onde cair, e inventar um custo em serviço
// pago seria o oposto do que este caminho promete.
func (c *Client) Malha(ctx context.Context, imageURL string, seed int64) ([]byte, string, error) {
	if c.comfyURL == "" {
		return nil, "", gerr.Configf("malha 3D: motor local não configurado (COMFY_URL)")
	}
	if strings.TrimSpace(imageURL) == "" {
		return nil, "", gerr.Configf("malha 3D: escolha a imagem que vai virar objeto")
	}
	// Perguntar ANTES de trabalhar. Subir a imagem primeiro fazia o erro honesto ("falta o
	// modelo, rode colab_3d.py") ser encoberto por qualquer tropeço no download da imagem — e o
	// operador ia depurar a imagem, não o motor. Barato: uma requisição.
	info, err := c.catalogo(ctx)
	if err != nil {
		return nil, "", err
	}
	if err = validar(info); err != nil {
		return nil, "", err
	}
	nome, err := c.upload(ctx, imageURL, fmt.Sprintf("malha_%d.png", rand.Int64N(1<<50)))
	if err != nil {
		return nil, "", fmt.Errorf("malha 3D: imagem base: %w", err)
	}
	if seed <= 0 {
		seed = rand.Int64N(1 << 62)
	}
	// Prefixo ÚNICO por job. O nó de exportação do TRELLIS grava o arquivo e NÃO publica nada em
	// `outputs` do /history (conferido: job com execution_success e outputs {}), então não há o
	// que ler — o que nos devolve o arquivo é o nome ser previsível. Com prefixo próprio, o
	// primeiro índice é sempre o nosso, sem corrida com outra geração na mesma sessão.
	prefixo := fmt.Sprintf("fox_%d", rand.Int64N(1<<40))
	grafo := montarGrafo(info, nome, prefixo, seed)

	body, _ := json.Marshal(map[string]any{"prompt": grafo})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.comfyURL+"/prompt", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("malha 3D: %w", err)
	}
	defer resp.Body.Close()
	var queued struct {
		PromptID string `json:"prompt_id"`
	}
	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if jerr := json.Unmarshal(raw, &queued); jerr != nil || queued.PromptID == "" {
		return nil, "", fmt.Errorf("malha 3D: %w", naoEhComfy(resp.StatusCode, raw))
	}
	log.Printf("malha 3D: enfileirada (%s)", queued.PromptID)

	arquivo, subpasta, err := c.esperar(ctx, queued.PromptID, prefixo)
	if err != nil {
		return nil, "", err
	}

	return c.baixar(ctx, arquivo, subpasta)
}

// naoEhComfy — resposta que não veio da API (o proxy do túnel respondendo por ele). Mesmo
// tratamento do motor de vídeo: "http 404" apontaria pro nosso código quando o motor é que caiu.
func naoEhComfy(status int, corpo []byte) error {
	if t := strings.TrimSpace(string(corpo)); strings.HasPrefix(t, "<") {
		return fmt.Errorf("o endereço do motor local respondeu uma página web (http %d), não a API — servidor fora do ar ou túnel expirado; confira o COMFY_URL", status)
	}

	return fmt.Errorf("http %d: %s", status, clip(corpo, 300))
}

func clip(b []byte, n int) string {
	s := strings.TrimSpace(string(b))
	if len(s) > n {
		return s[:n] + "…"
	}

	return s
}

// upload — baixa a imagem do acervo e sobe pro input/ do ComfyUI (LoadImage só lê arquivo local).
func (c *Client) upload(ctx context.Context, imageURL, filename string) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, imageURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download da imagem: http %d", resp.StatusCode)
	}
	data, err := io.ReadAll(io.LimitReader(resp.Body, 30<<20))
	if err != nil {
		return "", err
	}

	var buf bytes.Buffer
	mw := multipart.NewWriter(&buf)
	part, _ := mw.CreateFormFile("image", filename)
	_, _ = part.Write(data)
	_ = mw.WriteField("overwrite", "true")
	_ = mw.Close()

	up, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.comfyURL+"/upload/image", &buf)
	up.Header.Set("Content-Type", mw.FormDataContentType())
	ur, err := comfyHTTP.Do(up)
	if err != nil {
		return "", err
	}
	defer ur.Body.Close()
	var out struct {
		Name      string `json:"name"`
		Subfolder string `json:"subfolder"`
	}
	corpo, _ := io.ReadAll(io.LimitReader(ur.Body, 1<<20))
	if jerr := json.Unmarshal(corpo, &out); jerr != nil || out.Name == "" {
		return "", naoEhComfy(ur.StatusCode, corpo)
	}
	if out.Subfolder != "" {
		return out.Subfolder + "/" + out.Name, nil
	}

	return out.Name, nil
}

// esperar — poll do /history até o job terminar, e então ACHA o GLB no que voltou.
//
// O nó de exportação do Trellis devolve o caminho como TEXTO (é output_node, mas não publica em
// `images`), e o campo muda entre versões do wrapper. Em vez de adivinhar o nome do campo, a
// busca varre o JSON inteiro dos outputs atrás de uma string terminada em .glb — o que sobrevive
// a renomeação e evita a espera estourar o tempo com o arquivo pronto do outro lado.
func (c *Client) esperar(ctx context.Context, promptID, prefixo string) (arquivo, subpasta string, err error) {
	limite := time.Now().Add(1800 * time.Second)
	for {
		select {
		case <-ctx.Done():
			return "", "", ctx.Err()
		case <-time.After(5 * time.Second):
		}
		if time.Now().After(limite) {
			return "", "", fmt.Errorf("malha 3D: excedeu 30 min (servidor ocupado ou GPU sem fôlego)")
		}
		req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/history/"+url.PathEscape(promptID), nil)
		resp, herr := comfyHTTP.Do(req)
		if herr != nil {
			log.Printf("malha 3D: poll falhou (%v) — tentando de novo", herr)

			continue
		}
		var hist map[string]struct {
			Status struct {
				StatusStr string  `json:"status_str"`
				Messages  [][]any `json:"messages"`
			} `json:"status"`
			Outputs map[string]any `json:"outputs"`
		}
		derr := json.NewDecoder(io.LimitReader(resp.Body, 16<<20)).Decode(&hist)
		resp.Body.Close()
		if derr != nil {
			continue
		}
		entry, ok := hist[promptID]
		if !ok {
			continue // ainda na fila ou executando
		}
		if entry.Status.StatusStr == "error" {
			msg := "erro de execução"
			for _, m := range entry.Status.Messages {
				if len(m) >= 2 && fmt.Sprint(m[0]) == "execution_error" {
					if b, jerr := json.Marshal(m[1]); jerr == nil {
						msg = clip(b, 400)
					}
				}
			}

			return "", "", fmt.Errorf("malha 3D: %s", msg)
		}
		if entry.Status.StatusStr != "success" {
			continue
		}
		// Caminho normal: o SaveGLB nativo publica {"3d": [{filename, subfolder, type}]}.
		if arq, sub, ok := acharSaida(entry.Outputs); ok {
			return arq, sub, nil
		}

		// Rede de segurança: alguma versão que devolva só o caminho como texto.
		if nome := acharGLB(entry.Outputs); nome != "" {
			if i := strings.LastIndex(nome, "/"); i >= 0 {
				return nome[i+1:], nome[:i], nil
			}

			return nome, "", nil
		}

		// Último recurso: procurar pelo nome que NÓS pedimos. O ComfyUI numera com _00001_; um
		// prefixo por job faz o primeiro índice ser sempre o certo.
		for _, n := range []string{prefixo + "_00001_.glb", prefixo + "_00002_.glb", prefixo + ".glb"} {
			if c.existe(ctx, n, "mesh") {
				return n, "mesh", nil
			}
		}

		return "", "", fmt.Errorf("malha 3D: o job terminou mas o arquivo não apareceu (prefixo %s)", prefixo)
	}
}

// acharSaida — lê a saída ESTRUTURADA do SaveGLB: `{"<nó>": {"3d": [{filename, subfolder}]}}`.
//
// Ler os dois campos separados importa mais do que parece: o SaveGLB grava em `output/mesh/` e
// devolve `filename` SEM a pasta, com a pasta no `subfolder`. Uma busca que só procurasse a
// string ".glb" acharia o nome e perderia o "mesh" — e o /view responderia 404 com o arquivo
// pronto do outro lado, que é o tipo de erro que se disfarça de "o job não gerou nada".
func acharSaida(outputs map[string]any) (arquivo, subpasta string, ok bool) {
	for _, saida := range outputs {
		m, isMap := saida.(map[string]any)
		if !isMap {
			continue
		}
		for _, chave := range []string{"3d", "gltf", "mesh", "images"} {
			lista, temChave := m[chave].([]any)
			if !temChave {
				continue
			}
			for _, item := range lista {
				it, isItem := item.(map[string]any)
				if !isItem {
					continue
				}
				nome, _ := it["filename"].(string)
				if !strings.HasSuffix(strings.ToLower(nome), ".glb") {
					continue
				}
				sub, _ := it["subfolder"].(string)

				return nome, sub, true
			}
		}
	}

	return "", "", false
}

// acharGLB — varre qualquer estrutura vinda do history atrás do primeiro caminho .glb.
func acharGLB(v any) string {
	switch t := v.(type) {
	case string:
		if strings.HasSuffix(strings.ToLower(t), ".glb") {
			return t
		}
	case []any:
		for _, item := range t {
			if s := acharGLB(item); s != "" {
				return s
			}
		}
	case map[string]any:
		for _, item := range t {
			if s := acharGLB(item); s != "" {
				return s
			}
		}
	}

	return ""
}

// existe — o arquivo está lá? Uma requisição barata antes de baixar dezenas de MB.
func (c *Client) existe(ctx context.Context, arquivo, subpasta string) bool {
	q := url.Values{"filename": {arquivo}, "subfolder": {subpasta}, "type": {"output"}}
	req, _ := http.NewRequestWithContext(ctx, http.MethodHead, c.comfyURL+"/view?"+q.Encode(), nil)
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return false
	}
	defer resp.Body.Close()

	return resp.StatusCode == http.StatusOK
}

// baixar — pega os bytes do GLB gerado.
func (c *Client) baixar(ctx context.Context, arquivo, subpasta string) ([]byte, string, error) {
	q := url.Values{"filename": {arquivo}, "subfolder": {subpasta}, "type": {"output"}}
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.comfyURL+"/view?"+q.Encode(), nil)
	resp, err := comfyHTTP.Do(req)
	if err != nil {
		return nil, "", fmt.Errorf("malha 3D: download: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil, "", fmt.Errorf("malha 3D: download: http %d", resp.StatusCode)
	}
	// 80 MB: o mesmo teto que o upload de malha do console aceita (malha de personagem com
	// textura embutida). Acima disso o arquivo não entraria no acervo de qualquer jeito.
	data, err := io.ReadAll(io.LimitReader(resp.Body, 80<<20))
	if err != nil || len(data) == 0 {
		return nil, "", fmt.Errorf("malha 3D: download vazio")
	}
	// glTF binário começa com "glTF" — conferir aqui evita gravar no acervo um HTML de erro
	// com nome de modelo, que só falharia depois, no viewer, parecendo malha corrompida.
	if len(data) < 4 || string(data[:4]) != "glTF" {
		return nil, "", fmt.Errorf("malha 3D: o arquivo devolvido não é um GLB (começa com %q)", clip(data, 16))
	}

	return data, "glb", nil
}

// receita — os parâmetros de cada versão do Hunyuan3D, copiados dos templates OFICIAIS da
// Comfy-Org (`3d_hunyuan3d_image_to_model.json` e `3d_hunyuan3d-v2.1.json`).
//
// Vêm de lá, e não de palpite, porque cada número aqui é uma decisão de qualidade que alguém já
// mediu: o 2.1 quer 30 passos com cfg 5 e recorte "center"; o 2.0 quer 20 passos com cfg 8 e sem
// recorte. Trocar às cegas devolve malha derretida — foi a lição do vídeo, e vale igual aqui.
type receita struct {
	resolucao int
	passos    int
	cfg       float64
	recorte   string
}

func receitaDe(ckpt string) receita {
	l := strings.ToLower(ckpt)
	if strings.Contains(l, "2.1") || strings.Contains(l, "v2_1") || strings.Contains(l, "v2-1") {
		return receita{resolucao: 4096, passos: 30, cfg: 5, recorte: "center"}
	}

	return receita{resolucao: 3072, passos: 20, cfg: 8, recorte: "none"}
}

// grafo — o workflow NATIVO de imagem → malha, em API format:
//
//	ImageOnlyCheckpointLoader → CLIPVisionEncode → Hunyuan3Dv2Conditioning
//	  → KSampler (com ModelSamplingAuraFlow) → VAEDecodeHunyuan3D (voxel)
//	  → VoxelToMesh (surface net) → SaveGLB
//
// Conferimos os nós ANTES de enfileirar: nó inexistente faz o ComfyUI recusar o grafo inteiro com
// uma mensagem que não diz qual peça faltou. Conferir aqui transforma "erro genérico depois de 10
// min" em "atualize o ComfyUI" ou "falta o checkpoint".
func validar(info map[string]any) error {
	var faltam []string
	for _, n := range nosNativos {
		if _, ok := info[n]; !ok {
			faltam = append(faltam, n)
		}
	}
	if len(faltam) > 0 {
		return gerr.Configf("malha 3D: este ComfyUI é antigo demais — faltam nós que hoje são nativos (%s); atualize o ComfyUI da sessão", strings.Join(faltam, ", "))
	}
	if escolherCheckpoint(info) == "" {
		return gerr.Configf("malha 3D: o servidor está de pé mas não tem o modelo de malha (rode scripts/comfy/colab_3d.py na sessão do Colab — é um download, sem instalação)")
	}

	return nil
}

func montarGrafo(info map[string]any, imagem, prefixo string, seed int64) map[string]any {
	ckpt := escolherCheckpoint(info)
	r := receitaDe(ckpt)

	g := map[string]any{
		// Um único checkpoint entrega MODEL + CLIP_VISION + VAE. Nada mais a baixar.
		"ckpt": map[string]any{"class_type": "ImageOnlyCheckpointLoader", "inputs": map[string]any{
			"ckpt_name": ckpt}},
		"img": map[string]any{"class_type": "LoadImage", "inputs": map[string]any{"image": imagem}},
		"visao": map[string]any{"class_type": "CLIPVisionEncode", "inputs": map[string]any{
			"clip_vision": []any{"ckpt", 1}, "image": []any{"img", 0}, "crop": r.recorte}},
		"cond": map[string]any{"class_type": "Hunyuan3Dv2Conditioning", "inputs": map[string]any{
			"clip_vision_output": []any{"visao", 0}}},
		"latente": map[string]any{"class_type": "EmptyLatentHunyuan3Dv2", "inputs": map[string]any{
			"resolution": r.resolucao, "batch_size": 1}},
		// O template oficial passa o modelo por aqui antes do sampler; sem isso a malha sai pior.
		"shift": map[string]any{"class_type": "ModelSamplingAuraFlow", "inputs": map[string]any{
			"model": []any{"ckpt", 0}, "shift": 1.0}},
		"amostra": map[string]any{"class_type": "KSampler", "inputs": map[string]any{
			"model": []any{"shift", 0}, "positive": []any{"cond", 0}, "negative": []any{"cond", 1},
			"latent_image": []any{"latente", 0}, "seed": seed, "steps": r.passos, "cfg": r.cfg,
			"sampler_name": "euler", "scheduler": "normal", "denoise": 1.0}},
		"voxel": map[string]any{"class_type": "VAEDecodeHunyuan3D", "inputs": map[string]any{
			"samples": []any{"amostra", 0}, "vae": []any{"ckpt", 2},
			"num_chunks": 8000, "octree_resolution": 256}},
		// "surface net" em vez de "basic": malha mais limpa, mesmo custo.
		"malha": map[string]any{"class_type": "VoxelToMesh", "inputs": map[string]any{
			"voxel": []any{"voxel", 0}, "algorithm": "surface net", "threshold": 0.6}},
		"out": map[string]any{"class_type": "SaveGLB", "inputs": map[string]any{
			"mesh": []any{"malha", 0}, "filename_prefix": "mesh/" + prefixo}},
	}
	completarObrigatorios(g, info)

	return g
}
