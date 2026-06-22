#!/usr/bin/env bash
# migrate-prod.sh — roda as migrations em produção como o SUPERUSER do Postgres.
#
# Por quê: o runtime do app conecta como `reachyn_app` (role não-privilegiado, que RESPEITA
# o RLS — ver migration enable_rls_*). Esse role NÃO tem DDL, então `php artisan migrate`
# falharia. As migrations precisam do superuser (`reachyn`), cuja senha fica em
# /root/.reachyn_super_pw na VPS (fora do git). Use SEMPRE este script pra migrar em prod.
#
# Uso (na VPS): bash /docker/reachyn-mono/scripts/migrate-prod.sh
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f /root/.reachyn_super_pw ]; then
  echo "ERRO: /root/.reachyn_super_pw não encontrado (senha do superuser do Postgres)." >&2
  exit 1
fi

docker compose exec -T \
  -e DB_USERNAME=reachyn \
  -e DB_PASSWORD="$(cat /root/.reachyn_super_pw)" \
  console php artisan migrate --force

# RBK-001: healthcheck de isolamento. SEM os -e DB_* → usa o role de RUNTIME do .env
# (reachyn_app). Com `set -e`, aborta o deploy se o app conectar como superuser/BYPASSRLS
# (o que tornaria o RLS inerte).
echo "→ verificando isolamento RLS (runtime user)..."
docker compose exec -T console php artisan reachyn:verify-db-isolation
