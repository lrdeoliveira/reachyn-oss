package api

import (
	"sync"
	"time"
)

// rateLimiter — token-bucket simples (stdlib pura) usado como TETO DE SEGURANÇA do engine
// contra denial-of-wallet (RBK-005, OWASP LLM10): mesmo que o ENGINE_ADMIN_TOKEN vaze ou um
// serviço da rede interna seja comprometido, as rotas CARAS de geração ficam limitadas a uma
// taxa global. NÃO substitui a quota por-plano do console — é defesa em profundidade independente.
type rateLimiter struct {
	mu     sync.Mutex
	tokens float64
	max    float64
	refill float64 // tokens por segundo
	last   time.Time
}

func newRateLimiter(max, refillPerSec float64) *rateLimiter {
	return &rateLimiter{tokens: max, max: max, refill: refillPerSec, last: time.Now()}
}

// allow consome 1 token se houver; reabastece proporcional ao tempo decorrido.
func (rl *rateLimiter) allow() bool {
	rl.mu.Lock()
	defer rl.mu.Unlock()

	now := time.Now()
	rl.tokens += now.Sub(rl.last).Seconds() * rl.refill
	if rl.tokens > rl.max {
		rl.tokens = rl.max
	}
	rl.last = now

	if rl.tokens >= 1 {
		rl.tokens--
		return true
	}
	return false
}
