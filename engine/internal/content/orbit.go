// orbit.go — turnaround de personagem via ÓRBITA DE CÂMERA (vídeo i2v), a solução real pro caso
// 2026-07-17: o subject_reference de IMAGEM da MiniMax não segue instrução de ângulo grande de
// forma confiável (testado 4/4 tentativas de PERFIL — todas saíram de frente), mas um modelo de
// VÍDEO mantém coerência espacial ao longo de um movimento de câmera contínuo. Em vez de pedir
// "gere o personagem de perfil" isolado (o modelo não tem uma referência espacial pra obedecer),
// gera-se 1 clipe curto com a câmera orbitando o personagem e extrai-se o frame de cada ângulo do
// turnaround — validado com geração real (mel-orbit.mp4): cobertura completa frente→3/4→perfil→
// costas→perfil→frente, identidade consistente em todos os frames.
package content

import (
	"context"
	"fmt"
)

// orbitFractions — posições (fração 0.0-1.0 da duração do clipe) de onde extrair cada um dos 8
// ângulos canônicos do turnaround (FRENTE, 3/4 ESQ, PERFIL ESQ, 3/4 TRASEIRA ESQ, COSTAS,
// 3/4 TRASEIRA DIR, PERFIL DIR, 3/4 DIR — mesma ordem de ModelSheetService::KINDS['angles']).
// Calibrado numa geração real: a rotação do Hailuo NÃO é uniforme (segura a frente nos primeiros
// ~25% do clipe, depois acelera) — frações uniformes ainda cobrem o círculo inteiro corretamente,
// só não caem exatamente nos graus nominais (ex.: o frame "90°" pode estar mais perto de 60-70°
// na prática). Aceitável: é MUITO melhor que os 0% de conformidade de ângulo do caminho anterior.
var orbitFractions = []float64{0, 0.125, 0.25, 0.375, 0.5, 0.625, 0.75, 0.875}

// orbitCameraDirective — a instrução de câmera colada DEPOIS do identity lock. Câmera orbita
// 360° contínuos; o personagem fica parado no centro (evita o clipe virar uma cena de ação em
// vez de um turnaround limpo). Duration "6" (Hailuo direto) — clipe curto, custo mínimo.
const orbitCameraDirective = " The camera smoothly and continuously orbits a full 360 degrees " +
	"around the subject in one uninterrupted circular motion, moving from front view, through " +
	"the left profile, to the back view, through the right profile, and back to front. The " +
	"subject stays perfectly still and stationary in the center the entire time — it does not " +
	"walk, turn or move; only the camera moves around it. Plain seamless light-gray studio " +
	"floor, no scenery, soft even studio lighting throughout, sharp focus, no cuts, no camera " +
	"shake, consistent identical character the entire clip."

// GenerateOrbitAngles — gera 1 clipe de órbita (Hailuo i2v, conta MiniMax pré-paga) a partir da
// imagem-base do personagem e extrai os 8 frames do turnaround (ffmpeg-service /frames-at).
// `identity` = o identity lock do personagem (SEM a parte de enquadramento por shot — só quem
// ele é: espécie, cor, traços). Retorna as 8 URLs na ordem canônica de orbitFractions/KINDS.
func (s *Service) GenerateOrbitAngles(ctx context.Context, identity, baseImageURL string) ([]string, error) {
	if baseImageURL == "" {
		return nil, fmt.Errorf("turnaround por órbita: sem imagem-base")
	}
	prompt := identity + orbitCameraDirective
	videoURL, err := s.video.HailuoDirect(ctx, "", prompt, "6", baseImageURL)
	if err != nil {
		return nil, fmt.Errorf("turnaround por órbita: geração do vídeo falhou: %w", err)
	}
	urls, err := s.media.FramesAt(ctx, videoURL, orbitFractions)
	if err != nil {
		return nil, fmt.Errorf("turnaround por órbita: extração dos frames falhou: %w", err)
	}
	if len(urls) != len(orbitFractions) {
		return nil, fmt.Errorf("turnaround por órbita: esperava %d frames, veio %d", len(orbitFractions), len(urls))
	}
	for i, u := range urls {
		if u == "" {
			return nil, fmt.Errorf("turnaround por órbita: frame %d (fração %.3f) falhou na extração", i, orbitFractions[i])
		}
	}
	return urls, nil
}

// AnglesSheet — orquestra a prancha de ÂNGULOS inteira: tenta o caminho normal (i2i ancorado,
// 1 shot por vista, igual às outras pranchas) e só troca pro turnaround por órbita se ele estiver
// indisponível (a MESMA detecção que já existe pra fallback ancorado — ver minimaxImageAnchored).
// Isso preserva o caminho de MAIOR qualidade (i2i por vista) quando ele está de pé; a órbita entra só na
// contingência, onde a alternativa seria 10 shots de subject_reference sem conformidade de
// ângulo nenhuma. `shots` = os prompts POR VISTA de sempre (caminho i2i); `identity` =
// só o identity lock (usado no caminho órbita). Retorna as URLs na mesma ordem de `shots`.
func (s *Service) AnglesSheet(ctx context.Context, identity, baseImageURL, aspect, style string,
	shots []ShotPrompt, provider, model string, nonHumanSubject bool) ([]string, string, error) {
	if len(shots) == 0 {
		return nil, "", fmt.Errorf("turnaround: sem shots")
	}
	// Sonda o caminho de QUALIDADE com o 1º shot (FRENTE): uma chamada i2i ancorada por vista.
	// Se ele responder, o grupo inteiro segue por aí. Só GERA o clipe de órbita (custo/tempo de
	// um vídeo) quando o caminho por vista já não está respondendo — não se desperdiça a órbita
	// enquanto a via boa está de pé.
	//
	// Antes da saída do agregador (2026-08-03) esta sonda era condicionada a provider=="kie".
	// Agora o caminho por vista é o i2i de assinatura, que vale pra QUALQUER provider escolhido
	// no catálogo — por isso a condição sumiu em vez de virar provider=="cli-bridge": amarrar de
	// novo a um provider faria a prancha cair na órbita sempre que o cliente escolhesse outro.
	probe, err := s.i2iAncorado(ctx, shots[0].Prompt, aspect, style, []string{baseImageURL})
	if err == nil && probe != "" {
		urls := make([]string, len(shots))
		urls[0] = probe // i2iAncorado já devolve URL durável — persistir de novo seria round-trip à toa
		type res struct {
			i   int
			url string
			err error
		}
		out := make(chan res, len(shots)-1)
		for i := 1; i < len(shots); i++ {
			go func(i int) {
				u, err := s.i2iAncorado(ctx, shots[i].Prompt, aspect, style, []string{baseImageURL})
				out <- res{i, u, err}
			}(i)
		}
		failed := 0
		for range len(shots) - 1 {
			r := <-out
			if r.err != nil || r.url == "" {
				failed++
				continue
			}
			urls[r.i] = r.url
		}
		if failed == 0 {
			return urls, "i2i", nil
		}
		// alguns shots falharam mesmo com o motor de pé (raro) — cai pra órbita completa, em vez
		// de devolver uma prancha furada misturada com subject_reference furado.
	}

	// A órbita é um movimento de câmera HORIZONTAL — cobre os N primeiros shots (as vistas de
	// giro: frente/perfil/costas/3-quartos). TOPO e BASE (se existirem, sempre no FIM da lista —
	// ver ModelSheetService::groups()) não têm como sair de uma órbita horizontal; seguem pelo
	// caminho ancorado de sempre (best-effort, mesma resiliência do restante do model sheet —
	// melhor tentar e falhar honesto numa célula do que bloquear a prancha inteira por causa
	// desses 2 shots que a técnica de vídeo não resolve).
	orbitURLs, err := s.GenerateOrbitAngles(ctx, identity, baseImageURL)
	if err != nil {
		return nil, "", err
	}
	n := len(orbitURLs)
	if n > len(shots) {
		n = len(shots)
	}
	urls := make([]string, len(shots))
	copy(urls, orbitURLs[:n])
	// TOPO e BASE: a órbita é HORIZONTAL, não passa por cima nem por baixo. Tenta o mmx (filtro de
	// moderação próprio, recupera a célula quando o motor principal recusa por falso positivo) e depois a
	// reserva pré-paga. Em sujeito NÃO-HUMANO os dois são pulados: ambos ancoram pelo mesmo
	// subject-ref "só rosto humano" e devolveriam outro personagem — medido em 2026-07-21, o TOPO
	// da Mel veio com um cachorro preto e branco. Nesses casos as duas células ficam vazias, o que
	// é honesto; a folha só não é composta se NENHUM shot vingar (ver ModelSheetService).
	for i := n; i < len(shots); i++ {
		fora := fmt.Errorf("turnaround: fora do alcance da órbita")
		u, aerr := s.mmxImageAnchored(ctx, fora, shots[i].Prompt, aspect, style, []string{baseImageURL}, nonHumanSubject)
		if aerr != nil {
			u, aerr = s.minimaxImageAnchored(ctx, fora, shots[i].Prompt, aspect, style, []string{baseImageURL}, nonHumanSubject)
		}
		if aerr == nil {
			urls[i] = u
		}
	}
	return urls, "orbit", nil
}

// ShotPrompt — {label, prompt} de um shot do model sheet, espelha o formato vindo do console.
type ShotPrompt struct {
	Label  string
	Prompt string
}
