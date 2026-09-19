<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP do engine — base URL + token de serviço + JSON, num lugar só.
 *
 * A mesma montagem estava repetida em ~28 arquivos (controllers, jobs e services), ora como
 * helper privado `engine()`, ora como closure inline, ora colada direto na chamada. Duplicação
 * de configuração é a pior espécie: quando o contrato muda — e ele mudou, quando o engine passou
 * a EXIGIR o X-Admin-Token em todas as rotas /v1/* e não só em /v1/admin — é preciso caçar todas
 * as cópias, e a que passa despercebida vira 401 em produção num caminho pouco usado.
 *
 * O timeout é o único parâmetro porque é o único que varia de verdade entre chamadas (geração
 * longa × leitura rápida). A regra de ouro dos timeouts encadeados: quem está POR FORA espera
 * MAIS que quem está por dentro (job 360 > engine 300 > bridge 280), pra que o erro venha
 * explicado por quem sabe o motivo em vez de virar um timeout seco na borda.
 */
class EngineClient
{
    /** Timeout padrão: acima dos 300s do engine, que por sua vez fica acima dos 280s do bridge. */
    public const TIMEOUT_PADRAO = 360;

    /**
     * Cabeçalho com que o engine avisa que a geração de TEXTO foi entregue pela linha de RESERVA,
     * e não pelo modelo pedido. Vale "1" quando caiu pra reserva; ausente quando a primária serviu.
     *
     * Existe porque a queda pra reserva volta como HTTP 200 — indistinguível de sucesso — e o
     * débito acontece ANTES de gerar, pelo preço do modelo pedido. Sem este sinal o cliente paga
     * premium por entrega de nível básico. Consumido por ResolvesTextModel::ajustaSeReserva().
     * Definido em engine/internal/api/reserva.go — mudar os dois juntos.
     */
    public const CABECALHO_RESERVA = 'X-Reachyn-Reserva';

    public static function make(int $timeout = self::TIMEOUT_PADRAO): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()
            ->timeout($timeout);
    }

    /** Timeout de uma geração que roda no ESTÚDIO LOCAL (ComfyUI/bridge no host): o engine
     *  espera até 600s por ele, então quem chama de fora tem de esperar mais. */
    public const TIMEOUT_ESTUDIO_LOCAL = 660;

    /**
     * Cliente para uma chamada de GERAÇÃO, com o timeout derivado do próprio payload.
     *
     * 🐛 Este método era CHAMADO e não EXISTIA: `RenderSceneImageJob`, `GenerateShotFrameJob` e
     * `GenerateElementJob` já o invocavam desde 4cfc2c7, e `EngineClient` só tinha `make()`.
     * Todo quadro de cena / sprite / elemento enfileirado morria com "Call to undefined method"
     * — reservava o crédito, falhava as 3 tentativas e caía no estorno. Não aparecia em
     * `failed_jobs` porque esse caminho ainda não tinha sido exercitado em produção.
     *
     * A derivação existe porque o teto real não é da rota, é de ONDE o motor roda: modelo do
     * estúdio local (`img-local-*`, ComfyUI no host) leva minutos, enquanto a nuvem responde em
     * dezenas de segundos. Mantém a regra de ouro dos timeouts encadeados descrita acima —
     * quem está por fora espera mais que quem está por dentro.
     *
     * @param  array<string,mixed>  $payload  body que vai pro engine (mesmo array do POST)
     * @param  int|null  $timeout  força um valor; null = deriva do payload
     */
    public static function paraGeracao(array $payload, ?int $timeout = null): PendingRequest
    {
        if ($timeout !== null) {
            return self::make($timeout);
        }

        // O modelo pode vir como slug do catálogo (`model`) ou já resolvido em `provider`.
        $pistas = strtolower(implode(' ', array_filter([
            (string) ($payload['model'] ?? ''),
            (string) ($payload['provider'] ?? ''),
        ])));

        $estudioLocal = str_contains($pistas, 'local')
            || str_contains($pistas, 'comfy')
            || str_contains($pistas, 'cli-bridge');

        return self::make($estudioLocal ? self::TIMEOUT_ESTUDIO_LOCAL : self::TIMEOUT_PADRAO);
    }
}
