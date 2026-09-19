# AGENTS.md

Guia para **qualquer IA** que clone este repositório. Leia isto antes de editar código.

O Reachyn é um motor **multi-tenant** e **white-label**: pesquisa um tema, gera texto/imagem/vídeo, passa por aprovação e publica nas redes conectadas. Os provedores de IA são **plugáveis por variável de ambiente** (BYOK) — slugs de chave (`MINIMAX_API_KEY`, `OLLAMA_API_KEY`, …) são contrato de fio com o engine; **não renomeie**.

Detalhe: [docs/agents/](docs/agents/README.md). Setup: [README.md](README.md). Env: [`.env.example`](.env.example).

## O que é o quê

| Pasta | Stack | Papel |
|---|---|---|
| `console/` | Laravel + Filament | Única borda autenticada (Sanctum). Tenant, cota, fila, MCP, API do Studio |
| `engine/` | Go | Geração interna (`:8080`). Sem auth de usuário. Toda rota `/v1/*` exige `X-Admin-Token` |
| `web/` | Next.js | Studio. Fala **só** com o console — nunca com o engine |
| `media/ffmpeg-service/` | Python | Montagem, mux, thumbnail, persist no S3 |
| `media/gallery/` | Flask | Acervo read-only |
| `landing/` | HTML estático | Site institucional |
| *(storage)* | S3-compatível na stack | Serviço `scality-s3:8000` |

Dois painéis Filament: `/admin` (operador, todos os tenants) e `/app` (cliente — login canônico).

## Fluxo que importa

```
browser  →  web (Studio)  →  console /api/*  →  engine /v1/*  →  provedor (BYOK)
                 ↑                │
            token em memória      └── débito de créditos ANTES de gerar (estorna se falhar)
```

1. Cliente loga em `/app`. SSO one-time (`/studio-sso` → `POST /api/studio/sso-exchange`) vira Bearer de 15 min.
2. Studio chama `POST /api/studio/research` (texto: `/api/studio/text`; mídia: `/api/studio/media` ou proxy `/api/generate/*`). Console autentica, resolve tenant (`X-Tenant-Id`), debita cota, proxy com `X-Admin-Token`.
3. Engine monta o prompt (IDENTITY LOCK + ficha de cena), escolhe o adapter pelo slug do modelo, devolve URL no storage.
4. Jobs longos vão pra fila `database`; o Studio polla `GET /api/studio/draft`.
5. Publicar: `POST /api/studio/submit` → snapshot imutável em `publications`.

MCP (mesmo console): `/api/mcp/reachyn`, ability `mcp`. Tools delegam ao `StudioController`.

## Isolamento (não quebre)

Três barreiras, fail-open por design:

1. `where('tenant_id')` no código
2. `TenantScope` (global) + trait `BelongsToTenant`
3. RLS no Postgres — runtime conecta como `reachyn_app` (sem bypass). Tabela nova: `TenantRls::protect('tabela')`

Organização = conta (saldo de créditos + usuários). Tenant = **marca** (conteúdo, conexões, voz). O header `X-Tenant-Id` só aceita marca da org do usuário.

## Cota (esta edição)

Não há cobrança embutida. Créditos são **teto local** (`UsageService` + `CreditWallet`). Org `billing_status=exempt` não debita. Plano (`starter`/`pro`/`studio`/`enterprise`) é feature-gate: `0` no tipo = 402.

## Comandos

```bash
# stack
docker compose -f docker-compose.dev.yml up -d --build

# console
cd console && composer install && php artisan key:generate && php artisan migrate
composer test          # PHPUnit, sqlite in-memory
composer analyse       # Larastan nível 3 — gate

# engine
cd engine && go test ./... && go build ./...

# web
cd web && npm install && npm run dev    # :3210
npx tsc --noEmit
```

CI: `.github/workflows/ci.yml` (por stack) e `security-audit.yml`. Deploy = **rebuild da imagem** (self-contained; sem bind mount de código). Domínios no compose são placeholders (`example.com`).

## Convenções

- Idioma do produto e dos commits: **português do Brasil**.
- O `web` não importa o engine. Geração nova = rota no console + (se precisar) `/v1/*` no engine.
- IDENTITY LOCK: i2i ancora na base do personagem; não redesenhe espécie/sexo/proporções.
- Slugs de provedor no código (`minimax`, `ollama`, `fal`, `veo`, `tavily`) **não se renomeiam**.
- Texto de UI/docs públicos: “a IA” / “o provedor configurado” — sem marca comercial.
- Não commitar `.env`. Não citar IP, host de prod, nem path de deploy.

## Se algo “não funciona”

| Sintoma | Olhe |
|---|---|
| Engine 403 | `ENGINE_ADMIN_TOKEN` igual no console e no engine |
| Web não gera | A chamada tem que ir ao console, não ao `engine:8080` |
| Tenant vê dado de outro | Runtime é `reachyn_app`? `php artisan reachyn:verify-db-isolation` |
| Código novo não aparece no container | Faltou `docker compose build` + `up -d` |
| 402 na geração | Saldo / feature-gate do plano — não é falha de provedor |
