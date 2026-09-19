<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * Ponte para o COMPOSITOR nativo (next/og no serviço web): manda brand kit + conteúdo, recebe os
 * bytes do PNG. Não gera imagem com IA — compõe tipografia sobre uma imagem que já existe. Por
 * isso não custa crédito: é compute e storage.
 *
 * Existe como classe própria porque agora tem DOIS chamadores — o compositor de posts
 * (StudioController::compose, síncrono) e o render de carrossel (GenerateCarouselSlideJob, no
 * worker). Duplicar a chamada faria o contrato (token, timeout, validação de content-type) viver
 * em dois lugares e divergir no primeiro ajuste.
 */
class Composer
{
    /**
     * Renderiza uma composição e devolve os BYTES do PNG, ou null se o compositor não estiver
     * configurado/respondeu errado. Nunca lança: o chamador decide se a falha é fatal (post único)
     * ou parcial (carrossel preserva os slides já compostos).
     *
     * @param  array<string,mixed>  $body  corpo já montado do POST (type/format/brand/content/slide)
     */
    public static function render(array $body, int $timeout = 30): ?string
    {
        $token = (string) config('services.web.compose_token');
        $url = rtrim((string) config('services.web.url'), '/');
        if ($token === '' || $url === '') {
            return null;
        }
        try {
            $resp = Http::withHeaders(['X-Compose-Token' => $token])->timeout($timeout)->post($url.'/api/compose', $body);
        } catch (\Throwable $e) {
            return null;
        }
        if (! $resp->successful() || ! str_starts_with((string) $resp->header('Content-Type'), 'image/')) {
            return null;
        }

        return $resp->body();
    }

    /** O compositor está configurado? (sem token/URL, o carrossel cai no modo arte-total.) */
    public static function disponivel(): bool
    {
        return (string) config('services.web.compose_token') !== '' && (string) config('services.web.url') !== '';
    }
}
