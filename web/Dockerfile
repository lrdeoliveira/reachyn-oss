# web Next.js — build + start.
FROM node:24-alpine AS build
WORKDIR /app
# NEXT_PUBLIC_* são inlined em build time → precisam estar presentes aqui.
ARG NEXT_PUBLIC_CONSOLE_URL
ENV NEXT_PUBLIC_CONSOLE_URL=$NEXT_PUBLIC_CONSOLE_URL
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:24-alpine
WORKDIR /app
ENV NODE_ENV=production
# AUD-017: rodar como o usuário 'node' (UID 1000) que já vem na imagem oficial, não root.
# --chown garante que os artefatos do build pertençam ao 'node' (next start só lê /app).
COPY --from=build --chown=node:node /app ./
USER node
EXPOSE 3210
CMD ["npx", "next", "start", "-p", "3210"]
