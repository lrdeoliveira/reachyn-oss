# reachyn

Motor de produção de conteúdo **multi-tenant** e **white-label**: pesquisa um tema,
gera texto, imagem e vídeo prontos para cada rede social, passa por uma fila de
aprovação e publica nas contas conectadas.

Os **provedores de IA** (LLM, imagem, vídeo, voz, rerank, busca) são **plugáveis e
configurados por variáveis de ambiente** — o código não fixa nenhum fornecedor. Veja
`.env.example` para a lista de chaves esperadas.

## Estrutura

| Pasta | Stack | Papel |
|---|---|---|
| `console/` | **Laravel + Filament** · prod: **nginx + php-fpm** (supervisor) | Identidade (Sanctum, canônica), multi-tenant/RLS, back-office do cliente + painel do operador |
| `engine/` | **Go** | Geração de conteúdo + jobs de mídia + providers plugáveis (`llm` / `image` / `video` / `speech` / `rerank`). API HTTP interna |
| `web/` | **Next.js** | O **Studio** criativo: fluxo Pesquisa → Conteúdo → Mídia → Aprovar → Publicar, edição por rede em abas, preview/carrossel de mídia |
| `media/ffmpeg-service/` | Python (ffmpeg) | Micro-serviço de montagem de vídeo, ingest, thumbnail e **persist** no storage |
| `media/gallery/` | Python (Flask) | UI read-only do acervo (restrita por IP) |
| `landing/` | nginx (estático) | Site institucional |
| *(storage)* | **Scality** (Zenko CloudServer) | Servidor S3-compatível. Console / engine / ffmpeg apontam para `scality-s3:8000`; dados em volumes `external` preservados |

## Dois painéis Filament
- `AdminPanelProvider` → operador (todos os tenants, moderação, uso).
- `AppPanelProvider` → back-office do cliente (publicados, plano, conexões).

## Dev local
```bash
cp .env.example .env          # preencher segredos (provedores de IA, S3, DB)
# console
cd console && composer install && php artisan key:generate && php artisan migrate
# engine
cd engine && go run ./cmd/reachyn        # :8080/health
# web
cd web && npm install && npm run dev      # :3210
```

Ou suba tudo em containers com hot-reload:
```bash
docker compose -f docker-compose.dev.yml up -d --build
```

## Arquitetura

- O **engine** é **interno** (sem porta publicada): só `console` e `web` o chamam por
  `engine:8080` na rede `reachyn-net`.
- O **console** em produção roda **nginx + php-fpm** sob supervisor (configs em
  `console/docker/`, non-root); o **dev** usa `php artisan serve` via override no
  `docker-compose.dev.yml`.
- A stack é **self-contained**: o código é embutido nas imagens (sem bind mount em
  produção). Aplicar mudança de código = `docker compose build <serviço>` + `up -d`.
  Os volumes guardam só **dados** (banco, storage, `.env`).
- **Storage**: servidor S3-compatível (Scality/Zenko CloudServer) na própria stack;
  console/engine/ffmpeg apontam para `scality-s3:8000` por nome de serviço.

## Deploy (prod)
A imagem embute código + dependências; deploy = build da imagem versionada, não
`composer/npm install` no disco. Os domínios públicos do Traefik nas labels do
`docker-compose.yml` usam placeholders (`example.com`) — troque pelos seus.

```bash
docker compose build <serviço> && docker compose up -d <serviço>
```

- Segredos: `.env` é git-ignored e vive só no ambiente de deploy (nunca versionado).
  Veja `.env.example` para a allowlist completa.
- Recriar **sem** rebuild deixa código antigo com env novo e pode quebrar — sempre
  rebuild ao mudar código.
