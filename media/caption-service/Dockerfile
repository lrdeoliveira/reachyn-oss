# caption-service — render Remotion (legenda animada → WebM transparente).
# Self-contained (GUIDELINE 12): deps + bundle + Chrome Headless Shell embutidos na imagem.
FROM node:26-bookworm-slim

# Libs do Chrome Headless Shell (lista oficial do Remotion pra Debian) + as MESMAS fontes do
# ffmpeg-service (fonts-liberation/dejavu/noto): legenda animada e queimada usam a mesma fonte.
RUN apt-get update && apt-get install -y --no-install-recommends \
      libnss3 libdbus-1-3 libatk1.0-0 libgbm-dev libasound2 libxrandr2 \
      libxkbcommon-dev libxfixes3 libxcomposite1 libxdamage1 libatk-bridge2.0-0 \
      libpango-1.0-0 libcairo2 libcups2 \
      fonts-liberation fonts-dejavu-core fonts-noto-core fontconfig ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY remotion ./remotion
COPY scripts ./scripts
COPY server.mjs ./
RUN node scripts/prebundle.mjs

# AUD-017: não-root. O `browser ensure` roda DEPOIS do USER pro Chrome Headless Shell ficar
# num caminho que o appuser lê/executa em runtime.
RUN useradd -r -u 1001 -m -d /home/appuser appuser && chown -R appuser:appuser /app
USER appuser
ENV HOME=/home/appuser
RUN npx remotion browser ensure

EXPOSE 3979
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3979/health').then(r=>{if(!r.ok)process.exit(1)}).catch(()=>process.exit(1))"
CMD ["node", "server.mjs"]
