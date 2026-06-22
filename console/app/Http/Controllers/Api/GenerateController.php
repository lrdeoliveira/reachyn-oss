<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsageService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy de geração web → console → engine Go.
 * O console é a borda autenticada: valida o tenant, faz o enforcement de quota (402)
 * e credita o uso. Mantém o engine interno (sem auth/tenant), fora do alcance do browser.
 */
class GenerateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(private UsageService $usage) {}

    // Texto/derivados: sem cota.
    public function research(Request $r): JsonResponse
    {
        return $this->proxy('/v1/research', $r->only('keyword', 'sources'));
    }

    public function summarize(Request $r): JsonResponse
    {
        return $this->proxy('/v1/summarize', $r->only('keyword', 'sources'));
    }

    public function text(Request $r): JsonResponse
    {
        return $this->proxy('/v1/text', $r->only('keyword', 'brief', 'platform'));
    }

    public function thumbnail(Request $r): JsonResponse
    {
        return $this->proxy('/v1/thumbnail', $r->only('videoUrl', 'title'));
    }

    // Mídia: cota por tipo (bucket image|video|premium-video) com PESO por custo (AUD-012).
    public function image(Request $r): JsonResponse
    {
        return $this->gated($r, 'image', '/v1/image', $r->only('prompt', 'aspect', 'style'), $this->usage->weightFor('image'));
    }

    public function short(Request $r): JsonResponse
    {
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; valor inválido/ausente → default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        // short tem bucket próprio (custo ~7× o clipe simples — ver docs/custos-e-planos.md).
        $payload = array_merge($r->only('keyword', 'brief', 'voiceId'), ['lang' => $lang]);

        return $this->gated($r, 'short', '/v1/short', $payload, 1);
    }

    public function video(Request $r): JsonResponse
    {
        return $this->gated($r, 'video', '/v1/video', $r->only('prompt'), $this->usage->weightFor('video'));
    }

    public function premiumVideo(Request $r): JsonResponse
    {
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; valor inválido/ausente → default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        $payload = array_merge($r->only('keyword', 'brief'), ['lang' => $lang]);

        return $this->gated($r, 'premium-video', '/v1/premium-video', $payload, $this->usage->weightFor('premium-video'));
    }

    /** Proxy puro (sem metering). */
    private function proxy(string $path, array $payload): JsonResponse
    {
        $res = $this->engine()->post($path, $payload);

        return $this->relay($res);
    }

    /**
     * Proxy com gate de quota no padrão RESERVE-THEN-CONSUME (AUD-002):
     * reserva atomicamente a cota ANTES de chamar o engine; se a geração falhar, estorna.
     * 402 se fora do plano ou cota esgotada.
     */
    private function gated(Request $r, string $kind, string $path, array $payload, int $weight = 1): JsonResponse
    {
        $tenant = $r->user()->tenant;
        abort_unless($tenant, 404, 'Usuário sem tenant.');

        // RESERVA: debita o peso atomicamente; false = não cabe no plano.
        if (! $this->usage->tryConsume($tenant, $kind, $weight)) {
            return response()->json([
                'error' => 'quota_exceeded',
                'message' => 'Limite do plano atingido para este tipo de mídia.',
                'kind' => $kind,
            ], 402);
        }

        $res = $this->engine()->post($path, $payload);
        if (! $res->successful()) {
            // Geração falhou → ESTORNA a reserva (não cobra o cliente por falha nossa).
            $this->usage->refund($tenant, $kind, $weight);
        }

        return $this->relay($res);
    }

    /**
     * AUD-013 (white-label): repassa a resposta do engine ao browser, mas em falha
     * (não-2xx) devolve mensagem GENÉRICA — nunca o corpo cru do engine, que pode
     * citar provedores de IA (vídeo/voz/etc.). O detalhe real fica no log.
     */
    private function relay(Response $res): JsonResponse
    {
        if ($res->successful()) {
            return response()->json($res->json() ?? [], $res->status());
        }

        Log::warning('[engine] geração falhou', [
            'status' => $res->status(),
            'body' => mb_substr((string) $res->body(), 0, 2000),
        ]);

        // 4xx (ex.: input inválido) preserva o status; 5xx vira 502 (gateway).
        $status = $res->status() >= 400 && $res->status() < 500 ? $res->status() : 502;

        return response()->json([
            'ok' => false,
            'error' => 'A IA está indisponível no momento, tente novamente.',
        ], $status);
    }

    private function engine(): \Illuminate\Http\Client\PendingRequest
    {
        // X-Admin-Token em TODAS as chamadas /v1/* (o engine passou a exigir o token
        // compartilhado nas rotas de geração, não só /v1/admin).
        return Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()
            ->timeout(600); // jobs de vídeo são longos
    }
}
