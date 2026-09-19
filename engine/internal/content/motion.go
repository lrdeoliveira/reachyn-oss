package content

import (
	"context"
	"fmt"
	"time"

	"github.com/redfoxcode/reachyn/engine/internal/provider/motion"
)

// MotionTransfer — roda um workflow ComfyUI CURADO de motion transfer (RunningHub) e RE-HOSPEDA o
// resultado no nosso S3. Schema-driven: o console monta o nodeInfoList (quais nós recebem o keyframe
// da cena e o vídeo-guia) e o workflowId; o engine só orquestra submit→poll→persist. Vídeo é LENTO
// (minutos) — budget de poll grande. A URL da RunningHub expira em 24h, por isso o Persist é obrigatório.
func (s *Service) MotionTransfer(ctx context.Context, workflowID string, nodes []motion.NodeInfo, instanceType string) (string, error) {
	if s.motion == nil || !s.motion.Enabled() {
		return "", fmt.Errorf("motion: provedor indisponível")
	}
	taskID, err := s.motion.Submit(ctx, workflowID, nodes, instanceType)
	if err != nil {
		return "", err
	}
	url, err := s.motion.Poll(ctx, taskID, 130, 6*time.Second) // ~13min de teto (a geração leva ~8min)
	if err != nil {
		return "", err
	}
	return s.media.Persist(ctx, url, "video", "mp4"), nil
}
