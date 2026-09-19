package mesh

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// catalogoFalso — um /object_info mínimo, com os nós nativos e a lista de checkpoints que o
// servidor enxerga. É por aqui que o engine descobre QUAL modelo está instalado.
func catalogoFalso(checkpoints ...string) map[string]any {
	info := map[string]any{}
	for _, n := range nosNativos {
		info[n] = map[string]any{}
	}
	opcoes := make([]any, 0, len(checkpoints))
	for _, c := range checkpoints {
		opcoes = append(opcoes, c)
	}
	info["ImageOnlyCheckpointLoader"] = map[string]any{
		"input": map[string]any{
			"required": map[string]any{
				"ckpt_name": []any{opcoes, map[string]any{}},
			},
		},
	}

	return info
}

// O ponto do arquivo inteiro: o grafo tem que ser 100% nó nativo. Um custom node aqui traz de
// volta a cadeia que custou três sessões de Colab — extensão CUDA, ABI presa ao torch, e o
// servidor caindo junto com a imagem e o vídeo.
func TestGrafoUsaApenasNosNativosDoComfyUI(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(catalogoFalso("hunyuan3d-dit-v2_fp16.safetensors"))
	}))
	defer srv.Close()

	info, err := New(srv.URL).catalogo(context.Background())
	if err != nil {
		t.Fatalf("catalogo: %v", err)
	}
	g := montarGrafo(info, "base.png", "fox_1", 42)
	nativo := map[string]bool{"LoadImage": true}
	for _, n := range nosNativos {
		nativo[n] = true
	}
	for id, no := range g {
		classe, _ := no.(map[string]any)["class_type"].(string)
		if !nativo[classe] {
			t.Errorf("nó %q usa %q, que NÃO é nativo do ComfyUI", id, classe)
		}
		if strings.Contains(strings.ToLower(classe), "trellis") {
			t.Errorf("nó %q voltou pro TRELLIS (%s)", id, classe)
		}
	}
}

// O checkpoint vem do servidor, não de um nome fixo — e entre os dois, ganha o 2.1.
func TestEscolherCheckpointPrefereODoisPontoUm(t *testing.T) {
	casos := []struct {
		nome       string
		instalados []string
		quer       string
	}{
		{"só 2.0", []string{"hunyuan3d-dit-v2_fp16.safetensors"}, "hunyuan3d-dit-v2_fp16.safetensors"},
		{"só 2.1", []string{"hunyuan_3d_v2.1.safetensors"}, "hunyuan_3d_v2.1.safetensors"},
		{"os dois", []string{"hunyuan3d-dit-v2_fp16.safetensors", "hunyuan_3d_v2.1.safetensors"}, "hunyuan_3d_v2.1.safetensors"},
		{"nenhum", []string{"sd_xl_base_1.0.safetensors"}, ""},
		{"lista vazia", nil, ""},
	}
	for _, c := range casos {
		t.Run(c.nome, func(t *testing.T) {
			if got := escolherCheckpoint(catalogoFalso(c.instalados...)); got != c.quer {
				t.Errorf("escolherCheckpoint = %q, queria %q", got, c.quer)
			}
		})
	}
}

// Cada número aqui é decisão de qualidade medida pela Comfy-Org. Se alguém "arredondar" um deles
// depois, a malha piora em silêncio — igual ao vídeo que derretia com cfg errado.
func TestReceitaSegueOTemplateOficial(t *testing.T) {
	if r := receitaDe("hunyuan_3d_v2.1.safetensors"); r.resolucao != 4096 || r.passos != 30 || r.cfg != 5 || r.recorte != "center" {
		t.Errorf("2.1: %+v — o template oficial pede 4096/30/cfg 5/center", r)
	}
	if r := receitaDe("hunyuan3d-dit-v2_fp16.safetensors"); r.resolucao != 3072 || r.passos != 20 || r.cfg != 8 || r.recorte != "none" {
		t.Errorf("2.0: %+v — o template oficial pede 3072/20/cfg 8/none", r)
	}
}

// A armadilha que teria custado mais uma sessão: o SaveGLB devolve o nome SEM a pasta, e a pasta
// num campo à parte. Juntar errado = /view 404 com o arquivo pronto do outro lado.
func TestAcharSaidaPreservaASubpasta(t *testing.T) {
	outputs := map[string]any{
		"out": map[string]any{
			"3d": []any{map[string]any{
				"filename": "fox_7_00001_.glb", "subfolder": "mesh", "type": "output",
			}},
		},
	}
	arq, sub, ok := acharSaida(outputs)
	if !ok {
		t.Fatal("não achou a saída publicada pelo SaveGLB")
	}
	if arq != "fox_7_00001_.glb" || sub != "mesh" {
		t.Errorf("arquivo=%q subpasta=%q — queria fox_7_00001_.glb / mesh", arq, sub)
	}
}

func TestAcharSaidaIgnoraSaidaQueNaoEhMalha(t *testing.T) {
	outputs := map[string]any{
		"prev": map[string]any{"images": []any{map[string]any{"filename": "x.png", "subfolder": ""}}},
	}
	if _, _, ok := acharSaida(outputs); ok {
		t.Error("aceitou um PNG como malha")
	}
}

// Sem checkpoint o servidor sobe igual e aceita tudo — o erro tem que vir ANTES de enfileirar, e
// dizer o que fazer. Descobrir isso 20 min depois é o que aconteceu na sessão de 2026-07-31.
func TestSemCheckpointFalhaAntesDeEnfileirarEDizOQueFazer(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/object_info" {
			t.Errorf("chamou %s — não devia ter passado da checagem", r.URL.Path)
		}
		_ = json.NewEncoder(w).Encode(catalogoFalso("sd_xl_base_1.0.safetensors"))
	}))
	defer srv.Close()

	_, _, err := New(srv.URL).Malha(context.Background(), "https://exemplo.test/a.png", 1)
	if err == nil {
		t.Fatal("aceitou gerar malha sem modelo instalado")
	}
	if !strings.Contains(err.Error(), "colab_3d.py") {
		t.Errorf("erro não diz como resolver: %v", err)
	}
}

// ComfyUI velho demais: o remédio é atualizar o ComfyUI, não instalar extensão. O erro precisa
// dizer isso, senão o operador sai compilando coisa — que é exatamente o buraco de onde saímos.
func TestComfyUIAntigoMandaAtualizar(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		info := catalogoFalso("hunyuan_3d_v2.1.safetensors")
		delete(info, "VAEDecodeHunyuan3D")
		_ = json.NewEncoder(w).Encode(info)
	}))
	defer srv.Close()

	info, err := New(srv.URL).catalogo(context.Background())
	if err != nil {
		t.Fatalf("catalogo: %v", err)
	}
	err = validar(info)
	if err == nil || !strings.Contains(err.Error(), "atualize o ComfyUI") {
		t.Errorf("erro pouco útil: %v", err)
	}
}

// Nos() responde "sabe gerar malha AGORA?", não "o servidor respondeu?". Sem checkpoint, vazio —
// é isso que faz o botão sumir da aba 3D em vez de falhar no clique.
func TestNosVazioQuandoFaltaOModelo(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(catalogoFalso("sd_xl_base_1.0.safetensors"))
	}))
	defer srv.Close()

	nos, err := New(srv.URL).Nos(context.Background())
	if err != nil {
		t.Fatalf("Nos: %v", err)
	}
	if len(nos) != 0 {
		t.Errorf("disse que sabe gerar malha sem modelo: %v", nos)
	}
}

func TestNosReportaOCheckpointInstalado(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(catalogoFalso("hunyuan_3d_v2.1.safetensors"))
	}))
	defer srv.Close()

	nos, err := New(srv.URL).Nos(context.Background())
	if err != nil {
		t.Fatalf("Nos: %v", err)
	}
	var achou bool
	for _, n := range nos {
		if n == "checkpoint:hunyuan_3d_v2.1.safetensors" {
			achou = true
		}
	}
	if !achou {
		t.Errorf("não reportou o checkpoint: %v", nos)
	}
}

// Ponta a ponta contra um ComfyUI falso: catálogo → upload → prompt → history → /view → bytes.
// É o teste que a sessão do Colab nunca conseguiu rodar, e ele não custa GPU nenhuma.
func TestMalhaPontaAPontaDevolveOsBytesDoGLB(t *testing.T) {
	const glb = "glTF\x02\x00\x00\x00conteudo-da-malha"
	var imagemHospedada *httptest.Server
	imagemHospedada = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/png")
		_, _ = w.Write([]byte("\x89PNG\r\n\x1a\nfake"))
	}))
	defer imagemHospedada.Close()

	var viuUpload, viuPrompt bool
	var grafoEnviado map[string]any
	comfy := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.URL.Path == "/object_info":
			_ = json.NewEncoder(w).Encode(catalogoFalso("hunyuan_3d_v2.1.safetensors"))
		case r.URL.Path == "/upload/image":
			viuUpload = true
			_ = json.NewEncoder(w).Encode(map[string]any{"name": "malha_1.png", "subfolder": ""})
		case r.URL.Path == "/prompt":
			viuPrompt = true
			var body struct {
				Prompt map[string]any `json:"prompt"`
			}
			_ = json.NewDecoder(r.Body).Decode(&body)
			grafoEnviado = body.Prompt
			_ = json.NewEncoder(w).Encode(map[string]any{"prompt_id": "p1"})
		case strings.HasPrefix(r.URL.Path, "/history/"):
			_ = json.NewEncoder(w).Encode(map[string]any{
				"p1": map[string]any{
					"status": map[string]any{"status_str": "success"},
					"outputs": map[string]any{"out": map[string]any{
						"3d": []any{map[string]any{
							"filename": "fox_00001_.glb", "subfolder": "mesh", "type": "output",
						}},
					}},
				},
			})
		case r.URL.Path == "/view":
			if q := r.URL.Query(); q.Get("filename") != "fox_00001_.glb" || q.Get("subfolder") != "mesh" {
				t.Errorf("/view pediu filename=%q subfolder=%q — perdeu a pasta", q.Get("filename"), q.Get("subfolder"))
				w.WriteHeader(http.StatusNotFound)

				return
			}
			_, _ = w.Write([]byte(glb))
		default:
			t.Errorf("chamada inesperada: %s", r.URL.Path)
		}
	}))
	defer comfy.Close()

	dados, ext, err := New(comfy.URL).Malha(context.Background(), imagemHospedada.URL+"/base.png", 99)
	if err != nil {
		t.Fatalf("Malha: %v", err)
	}
	if !viuUpload || !viuPrompt {
		t.Errorf("pulou etapa: upload=%v prompt=%v", viuUpload, viuPrompt)
	}
	if string(dados) != glb {
		t.Errorf("bytes errados: %q", string(dados))
	}
	if ext != "glb" {
		t.Errorf("extensão %q, queria glb", ext)
	}
	// A seed pedida tem que chegar no sampler: sem isso, "gerar de novo com outra seed" mente.
	amostra, _ := grafoEnviado["amostra"].(map[string]any)
	inputs, _ := amostra["inputs"].(map[string]any)
	if seed, _ := inputs["seed"].(float64); seed != 99 {
		t.Errorf("seed no KSampler = %v, queria 99", inputs["seed"])
	}
}
