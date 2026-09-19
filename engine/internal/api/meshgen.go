// meshgen.go — GERAÇÃO de malha 3D (imagem → GLB) no ComfyUI do operador, via TRELLIS.
//
// Não confundir com o mesh.go ao lado: lá é RENDER de uma malha que já existe (blender-bridge
// desenha turntable/direções/âncora a partir do .glb). Aqui a malha NASCE. Fecha a Fase 3 do
// docs/ESTUDIO-3D.md — até 2026-07-30 a geometria só entrava por upload, gerada fora.
package api

import (
	"context"
	"log"
	"net/http"
	"os"
	"strings"
	"time"
)

// mesh — POST /v1/mesh {imageUrl, lock?, style?, seed?} → {url}. Síncrono, como o resto do
// FoxAssets local: quem chama espera. A geração leva minutos; job assíncrono aqui só
// acrescentaria uma máquina de estados para um produto de UM usuário.
func (s *Server) meshGenerate(w http.ResponseWriter, r *http.Request) {
	var in struct {
		ImageURL string `json:"imageUrl"` // a imagem que vira objeto (do NOSSO acervo)
		// lock/style: com lock presente, a malha nasce de uma FICHA DE MALHA (corpo inteiro,
		// perfil, fundo branco — SEM nenhuma menção a sexo: negar era mencionar, e mencionar
		// atraía) e o TEXTO do personagem vira o gabarito dos juízes de visão — da ficha antes
		// da GPU e da malha pronta depois. Vazio = malha direto da imagem, sem juiz.
		Lock  string `json:"lock"`
		Style string `json:"style"`
		Seed  int64  `json:"seed"` // >0 repete a mesma malha; 0 = nova a cada chamada
	}
	if !decode(w, r, &in) {
		return
	}
	url, ficha, err := s.svc().Mesh(r.Context(), in.ImageURL, in.Lock, in.Style, in.Seed)
	if err != nil {
		writeErr(w, err)

		return
	}
	// PERSONAGEM: pós-limpeza no Blender + JUIZ DE VISÃO da malha pronta (o texto do
	// personagem é o gabarito; renders sem textura são a evidência). Reprovou = entrega COM
	// o veredito no aviso — regenerar é decisão do operador no botão, não do servidor: um
	// clique custa no máximo 3 fichas + UMA malha de GPU (a 1ª versão re-gerava a malha
	// sozinha e o Colab ficava "gerando sem parar", 2026-07-31).
	aviso := ""
	if strings.TrimSpace(in.Lock) != "" {
		url = s.meshLimpaOuCrua(r.Context(), url)
		if aprovada, motivo := s.julgarMalha(r.Context(), url, in.Lock); !aprovada {
			aviso = "o juiz de visão reprovou esta malha: " + motivo + " — confira no viewer e gere de novo se concordar"
			log.Printf("malha: REPROVADA pelo juiz (%s) — entregue com aviso", motivo)
		}
	}
	resp := map[string]any{"url": url, "ficha": ficha}
	if aviso != "" {
		resp["aviso"] = aviso
	}
	writeJSON(w, http.StatusOK, resp)
}

// meshLimpaOuCrua — receita "limpar" no bridge; falhou, fica a crua (best-effort com log).
func (s *Server) meshLimpaOuCrua(ctx context.Context, meshURL string) string {
	lctx, cancel := context.WithTimeout(ctx, 8*time.Minute)
	defer cancel()
	arqs, err := bridgeReceita(lctx, "limpar", meshURL, nil)
	if err != nil {
		log.Printf("malha: pós-limpeza falhou (%v) — mantendo a malha crua", err)

		return meshURL
	}
	for _, a := range arqs {
		if a.Mime == "model/gltf-binary" {
			url, perr := s.spriteMedia.PersistBytes(lctx, a.Data, "mesh", "glb")
			if perr != nil || url == "" {
				log.Printf("malha: persistir a malha limpa falhou (%v) — mantendo a crua", perr)

				return meshURL
			}
			log.Printf("malha: pós-limpeza aplicada (%s)", url)

			return url
		}
	}
	log.Printf("malha: bridge não devolveu GLB — mantendo a malha crua")

	return meshURL
}

// julgarMalha — renderiza 2 vistas da malha no bridge e passa pro juiz de visão do content.
// Bridge fora do ar = aprova com log (o juiz é fiscal, não gerador).
func (s *Server) julgarMalha(ctx context.Context, meshURL, lock string) (bool, string) {
	rctx, cancel := context.WithTimeout(ctx, 8*time.Minute)
	defer cancel()
	arqs, err := bridgeReceita(rctx, "direcoes", meshURL, map[string]any{"count": 8, "size": 768})
	if err != nil {
		log.Printf("malha: render do juiz indisponível (%v) — aprovando sem conferir", err)

		return true, ""
	}
	// Duas vistas bastam pro juiz: um perfil (leste) e a traseira (norte) — é onde disco,
	// partes soltas e anatomia aparecem. As 8 existem porque a receita é a mesma da UI.
	var vistas [][]byte
	for _, a := range arqs {
		if a.Name == "leste.png" || a.Name == "norte.png" {
			vistas = append(vistas, a.Data)
		}
	}
	if len(vistas) == 0 {
		return true, ""
	}

	return s.svc().JulgarMalha(rctx, vistas, lock)
}

// meshGenHealth — o gerador de malha está PRONTO? É outra pergunta que "o servidor respondeu":
// o ComfyUI pode estar de pé sem o TRELLIS instalado — o estado normal de toda sessão nova do
// Colab antes do colab_trellis.py. A UI usa isto pra não oferecer um botão que só falharia
// depois de minutos de espera.
//
// DUAS capacidades, DUAS respostas (2026-08-01): `ok` responde pelo GERADOR de malha (ComfyUI) e
// `render` responde pelo blender-bridge, que faz turntable/8 direções/limpeza. Elas são
// independentes — o bridge subiu como container e passou a funcionar em produção enquanto o
// gerador segue exigindo COMFY_URL. Enquanto havia só `ok`, a UI gateava os botões de RENDER
// pelo estado do GERADOR: com o bridge no ar e o gerador ausente, um recurso que funciona ficava
// desabilitado. Campo separado pra cada pergunta.
func (s *Server) meshGenHealth(w http.ResponseWriter, r *http.Request) {
	nos, err := s.svc().MeshNos(r.Context())
	resp := map[string]any{"ok": err == nil && len(nos) > 0, "nodes": nos}
	if err != nil {
		resp["erro"] = err.Error()
	}
	// Só a CONFIGURAÇÃO: sem URL o bridge está desligado por definição (é o mesmo teste que
	// bridgeReceita faz antes de tentar). Não bate no /health do sidecar pra não transformar
	// uma consulta barata de UI numa chamada de rede que pode pendurar.
	resp["render"] = strings.TrimSpace(os.Getenv("BLENDER_BRIDGE_URL")) != ""
	writeJSON(w, http.StatusOK, resp)
}
