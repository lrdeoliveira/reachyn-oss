# web Next.js — build + start.
FROM node:26-alpine AS build
WORKDIR /app
# NEXT_PUBLIC_* são inlined em build time → precisam estar presentes aqui.
ARG NEXT_PUBLIC_CONSOLE_URL
ENV NEXT_PUBLIC_CONSOLE_URL=$NEXT_PUBLIC_CONSOLE_URL
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:26-alpine
WORKDIR /app
ENV NODE_ENV=production
# AUD-017: rodar como o usuário 'node' (UID 1000) que já vem na imagem oficial, não root.
# --chown garante que os artefatos do build pertençam ao 'node' (next start só lê /app).
COPY --from=build --chown=node:node /app ./
USER node
EXPOSE 3210
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:3210/').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"
CMD ["npx", "next", "start", "-p", "3210"]
