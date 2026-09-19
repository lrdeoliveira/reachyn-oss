# Overview

O Reachyn pesquisa um tema, gera texto/imagem/vídeo com IA plugável, passa por aprovação humana e publica nas redes conectadas. Cada **tenant** é uma marca: voz, personagens, conexões e cota próprios.

## Peças

- **`console/`** — Laravel 13 + Filament 5 + Sanctum. Única borda autenticada. Painéis `/admin` (operador) e `/app` (cliente). Expõe `/api/studio/*` e `/api/mcp/reachyn`.
- **`engine/`** — Go. Geração interna. Sem sessão de usuário. Toda rota `/v1/*` exige `X-Admin-Token`.
- **`web/`** — Next.js (Studio). Token em memória. Fala só com o console.
- **`media/ffmpeg-service/`** — Python + ffmpeg. Montagem, mux, thumbnail, persist no S3.
- **`media/gallery/`** — Flask. Acervo read-only.
- **`landing/`** — HTML estático institucional.
- **Storage** — S3-compatível na stack (`scality-s3:8000`).

## Caminho feliz

Login `/app` → SSO one-time → Studio (`:3210` em dev) → pesquisa/geração no console → débito de créditos → proxy ao engine → URL no storage → (opcional) `POST /api/studio/submit` vira `Publication` imutável.

## Esta edição vs. um SaaS fechado

- Sem gateway de pagamento no código.
- Créditos = teto local da organização.
- Planos (`starter`/`pro`/`studio`/`enterprise`) = feature-gate, não preço.
- Texto público fala “a IA”. Slugs de env (`MINIMAX_API_KEY`, `OLLAMA_API_KEY`, …) ficam — são contrato com o engine.
