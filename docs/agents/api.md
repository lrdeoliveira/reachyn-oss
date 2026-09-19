# Contratos de API

Três superfícies. O Studio e o MCP **não** falam com o engine.

Prefixo HTTP do console: `/api`. Auth: Sanctum (`auth:sanctum`). Tenant ativo: middleware `SetCurrentTenant` + `TenantScope` (header `X-Tenant-Id` ou `__tenant`, só marca da org).

## Studio (`console`)

SSO é **público** (a credencial é o code de uso único). O resto do grupo exige Bearer.

| Método | Rota | Função |
|---|---|---|
| POST | `/api/studio/sso-exchange` | Code one-time → Bearer 15 min (`studio`) |
| GET | `/api/me` | Usuário, org, marcas, tenant ativo |
| GET | `/api/usage` | Cota / saldo local |
| POST | `/api/studio/research` | Pesquisa + briefing |
| POST | `/api/studio/text` | Texto / copy |
| POST | `/api/studio/media` | Mídia do rascunho (imagem, etc.) |
| POST | `/api/studio/clip` | Clipe |
| POST | `/api/studio/tts` | Narração |
| POST | `/api/studio/music` | Música |
| GET | `/api/studio/draft` | Rascunho (o Studio **polla** aqui) |
| POST | `/api/studio/submit` | Snapshot → `publications` |
| POST | `/api/studio/schedule` | Agenda publicação |
| GET | `/api/studio/publish-status` | Status da publicação |
| GET | `/api/characters` | Personagens do tenant |
| GET | `/api/media/list` | Acervo |
| GET | `/api/publications` | Arquivo de posts |

Há dezenas de rotas irmãs (`/studio/story*`, `/studio/carousel*`, `/studio/vox*`, `/animation`, …). Fonte: `console/routes/api.php`. Controller canônico: `App\Http\Controllers\Api\StudioController`.

## Proxy de geração (`console` → engine)

Mesmo grupo autenticado. Síncrono; modelo `async` do catálogo = 422 (vai por `/studio/*` + job).

| Método | Rota |
|---|---|
| POST | `/api/generate/research` |
| POST | `/api/generate/text` |
| POST | `/api/generate/image` |
| POST | `/api/generate/video` |
| POST | `/api/generate/thumbnail` |

## MCP (`console`)

`POST /api/mcp/reachyn`. Ability `mcp`. Tools (delegam ao `StudioController`):

`reachyn_generate_image` (sync), `reachyn_generate_narration` (sync), `reachyn_generate_video` (async → `draftId`), `reachyn_generate_music` (async), `reachyn_upload_media`, `reachyn_check_media`, `reachyn_list_voices`, `reachyn_dub_video` e `reachyn_clone_voice` (plano `studio`).

## Engine (`engine`, interno)

`GET /health` é público (liveness). Toda `/v1/*` exige `X-Admin-Token` = `ENGINE_ADMIN_TOKEN` do console. Sem token = 403.

Núcleo: `POST /v1/research`, `/v1/text`, `/v1/image`, `/v1/video`, `/v1/tts`, `/v1/music`, `/v1/veo`, `/v1/thumbnail`. Lista completa: `engine/internal/api/api.go` → `Routes()`.

O engine escolhe o adapter pelo slug do modelo (`minimax`, `ollama`, `fal`, `veo`, …). Esses slugs são contrato de fio — **não renomeie**.
