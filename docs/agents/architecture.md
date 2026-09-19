# Arquitetura

## Fronteiras

```
browser → web (Next) → console /api/* → engine /v1/* → provedor (chave no .env)
                              │
                              └── media/ffmpeg-service, storage S3-compat
```

O Studio **nunca** chama o engine. O engine **nunca** conhece o usuário. O console autentica, resolve tenant, debita cota e injeta `X-Admin-Token`.

Auth do Studio: SSO one-time (`/studio-sso` → `POST /api/studio/sso-exchange`) → Bearer Sanctum (15 min). Header `X-Tenant-Id` só aceita marca da org do usuário.

MCP: mesmo console, `/api/mcp/reachyn`, ability `mcp`. Tools delegam ao `StudioController`.

## Isolamento

Organização = conta (saldo + usuários). Tenant = marca (conteúdo).

Três camadas, fail-open:

1. `where('tenant_id')` no código
2. `TenantScope` global + `BelongsToTenant`
3. RLS no Postgres — runtime `reachyn_app` (sem bypass). Tabela nova: `TenantRls::protect('tabela')`

`php artisan reachyn:verify-db-isolation` confere.

## Fila e jobs

Fila `database` (não Redis). Jobs longos (`GenerateVideoJob`, `GenerateImageJob`, …) atualizam o rascunho; o Studio polla `GET /api/studio/draft`. Falha de geração → estorno de créditos.

## Deploy

Imagem self-contained. Código novo só entra com `docker compose build` + `up -d`. Sem bind mount de aplicação. Domínios no compose: placeholders (`*.example.com`).

## Painéis

| URL | Quem |
|---|---|
| `/admin` | Operador (todos os tenants) |
| `/app` | Cliente (login canônico) |
