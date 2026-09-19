# Landing institucional reachyn.agency — site estático servido por nginx.
# Imagem UNPRIVILEGED (roda como uid 101, porta 8080) — secure-baseline: container não-root.
FROM nginxinc/nginx-unprivileged:alpine
# COPY como root (build); o USER runtime vem depois. A imagem base já roda como uid 101 no fim,
# mas declarar USER explícito satisfaz o scanner (CKV_DOCKER_3) e documenta o não-root.
USER root
COPY index.html privacidade.html termos.html cookies.html subprocessadores.html direitos.html dpa.html /usr/share/nginx/html/
COPY favicon.ico favicon.svg favicon-32.png apple-touch-icon.png /usr/share/nginx/html/
# robots.txt e sitemap.xml: os dois davam 404 até 2026-08-29 — os únicos pedidos LEGÍTIMOS no
# meio dos ~1400 404/dia do nginx (o resto é scanner atrás de /wp-admin e /.env).
COPY robots.txt sitemap.xml /usr/share/nginx/html/
USER 101
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/ >/dev/null 2>&1 || exit 1
