# engine Go — build estático multi-stage.
FROM golang:1.27-alpine AS build
WORKDIR /src
COPY go.mod ./
RUN go mod download || true
COPY . .
RUN CGO_ENABLED=0 go build -trimpath -o /reachyn ./cmd/reachyn

FROM alpine:3.24
RUN apk add --no-cache ca-certificates
COPY --from=build /reachyn /usr/local/bin/reachyn
# AUD-017: rodar como usuário não-root (binário estático, não precisa de root).
RUN adduser -D -H appuser
USER appuser
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/health >/dev/null 2>&1 || exit 1
ENTRYPOINT ["reachyn"]
