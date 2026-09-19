# Regras de domínio

## Cota

`UsageService` + `CreditWallet`. Débito **antes** de gerar; falha → estorno. Org `billing_status=exempt` não debita. Feature-gate: `0` no tipo do plano = HTTP 402. Sem tabela de preço e sem webhook de pagamento nesta edição.

## Tenant e org

- Organização: saldo, usuários, `billing_status`.
- Tenant: marca — voz, personagens, conexões, conteúdo.
- Usuário só enxerga tenants da própria org. `X-Tenant-Id` fora dessa lista = 403.

Conteúdo de marca (`Character`, `Publication`, conexão social, …) usa `BelongsToTenant`. Tabela nova precisa de `TenantRls::protect`.

## Personagem

Ficha persistida no tenant. Geração i2i **ancora na base** (IDENTITY LOCK): não redesenhar espécie, sexo, idade aparente, proporções. Troca de look = overlay, não identity swap.

## Publicação

`POST /api/studio/submit` cria `Publication` com snapshot imutável (copy + assets + briefing). Publicar nas redes é passo seguinte (`/publish`), não gera de novo.

## Planos

`starter` / `pro` / `studio` / `enterprise` liberam tetos e features (MCP, volume, tipos). Não carregam preço no código público.

## White-label

UI e docs: “a IA”, “o provedor configurado”. Slugs de env e de adapter (`MINIMAX_API_KEY`, `minimax`, `ollama`, …) ficam no código — o engine resolve por eles.
