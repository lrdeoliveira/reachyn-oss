// mesh.go — receitas 3D do blender-bridge (Fase 2 do docs/ESTUDIO-3D.md): turntable, 8 direções
// e âncora de ângulo renderizadas do .glb do asset, SEM Blender aberto e sem agente.
//
// Topologia igual ao mmx-bridge/ComfyUI: o Blender é app do macOS (GPU Metal), roda no HOST;
// o engine chama o sidecar via BLENDER_BRIDGE_URL (host.docker.internal:3922) e persiste os
// bytes no Scality via ffmpeg-service (/persist-bytes — safe_fetch não alcança o host, e nem
// deve: anti-SSRF). Quem valida DONO da malha é o console (mesh_url sai do banco, nunca do
// cliente); aqui só se valida receita e se repassa.
package api

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"time"
)

// meshReceitas — allowlist local (espelho da do bridge): nome fora daqui nem sai do engine.
var meshReceitas = map[string]bool{"turntable": true, "direcoes": true, "angulo": true, "limpar": true}

// bridgeArq — um arquivo devolvido pelo blender-bridge, já decodificado.
type bridgeArq struct {
	Name string
	Mime string
	Data []byte
}

// bridgeReceita — roda uma receita no blender-bridge e devolve os arquivos decodificados.
// Erro claro quando o bridge está desligado — quem chama decide se a receita é obrigatória
// (render pedido pelo usuário) ou best-effort (pós-limpeza da malha gerada).
func bridgeReceita(ctx context.Context, receita, meshURL string, params map[string]any) ([]bridgeArq, error) {
	base := strings.TrimRight(strings.TrimSpace(os.Getenv("BLENDER_BRIDGE_URL")), "/")
	token := strings.TrimSpace(os.Getenv("BLENDER_BRIDGE_TOKEN"))
	if base == "" || token == "" {
		return nil, fmt.Errorf("estúdio 3D local desligado (blender-bridge não configurado)")
	}
	body, _ := json.Marshal(map[string]any{"receita": receita, "glb_url": meshURL, "params": params})
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, base+"/render", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", "application/json")
	res, err := (&http.Client{Timeout: 8 * time.Minute}).Do(req)
	if err != nil {
		return nil, fmt.Errorf("blender-bridge inalcançável: %w", err)
	}
	defer res.Body.Close()
	var out struct {
		Files []struct {
			Name string `json:"name"`
			Mime string `json:"mime"`
			B64  string `json:"b64"`
		} `json:"files"`
		Error  string `json:"error"`
		Detail string `json:"detail"`
	}
	// Teto de 512MB decodificados: 8 direções em 2048² cabem folgado; acima disso é anomalia.
	if err := json.NewDecoder(io.LimitReader(res.Body, 512<<20)).Decode(&out); err != nil {
		return nil, fmt.Errorf("resposta inválida do blender-bridge")
	}
	if res.StatusCode != http.StatusOK || len(out.Files) == 0 {
		msg := out.Error
		if out.Detail != "" {
			msg += ": " + out.Detail
		}
		if msg == "" {
			msg = fmt.Sprintf("bridge status %d", res.StatusCode)
		}
		return nil, fmt.Errorf("%s", msg)
	}
	arqs := make([]bridgeArq, 0, len(out.Files))
	for _, f := range out.Files {
		data, derr := base64.StdEncoding.DecodeString(f.B64)
		if derr != nil || len(data) == 0 {
			continue
		}
		arqs = append(arqs, bridgeArq{Name: f.Name, Mime: f.Mime, Data: data})
	}
	if len(arqs) == 0 {
		return nil, fmt.Errorf("bridge devolveu arquivos vazios")
	}
	return arqs, nil
}

// meshRender — POST /v1/mesh/render {meshUrl, receita, params?} → {files: [{name, url, mime}]}.
// Síncrono como o resto do FoxAssets local: turntable de 72 frames leva ~30s no Mac; o teto
// de 8min cobre malha pesada com folga.
func (s *Server) meshRender(w http.ResponseWriter, r *http.Request) {
	var in struct {
		MeshURL string         `json:"meshUrl"`
		Receita string         `json:"receita"`
		Params  map[string]any `json:"params"`
	}
	if !decode(w, r, &in) {
		return
	}
	if !meshReceitas[in.Receita] {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "receita desconhecida"})
		return
	}
	if strings.TrimSpace(in.MeshURL) == "" {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": "meshUrl obrigatória"})
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 8*time.Minute)
	defer cancel()
	arqs, err := bridgeReceita(ctx, in.Receita, in.MeshURL, in.Params)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}

	// Persiste cada arquivo no Scality (kind mesh3d) — a URL final é a que a UI e a Galeria usam.
	type arq struct {
		Name string `json:"name"`
		URL  string `json:"url"`
		Mime string `json:"mime"`
	}
	files := make([]arq, 0, len(arqs))
	for _, f := range arqs {
		ext := "png"
		switch f.Mime {
		case "video/mp4":
			ext = "mp4"
		case "model/gltf-binary":
			ext = "glb"
		}
		url, perr := s.spriteMedia.PersistBytes(ctx, f.Data, "mesh3d", ext)
		if perr != nil {
			writeJSON(w, http.StatusBadGateway, map[string]string{"error": "persistência falhou: " + perr.Error()})
			return
		}
		files = append(files, arq{Name: f.Name, URL: url, Mime: f.Mime})
	}
	writeJSON(w, http.StatusOK, map[string]any{"files": files})
}
