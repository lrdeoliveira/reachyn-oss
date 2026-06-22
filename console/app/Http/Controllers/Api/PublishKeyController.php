<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Chaves de PUBLICAÇÃO (operador) — Zernio (publish social white-label).
 * Mesmo storage cifrado das de geração (provider_keys); o ZernioService lê de lá (fallback .env).
 * Operador-only. Nunca devolve a chave em claro: só status.
 */
class PublishKeyController extends Controller
{
    public const PROVIDERS = [
        ['key' => 'zernio', 'label' => 'Zernio', 'group' => 'Publicação social', 'desc' => 'Motor de publicação social do Reachyn. Uma chave do operador publica nas redes que cada cliente conecta por OAuth (Instagram, Facebook, LinkedIn, YouTube, X, Threads…).', 'testable' => true],
    ];

    private function gate(Request $r): void
    {
        abort_unless($r->user()?->isOperator(), 403, 'Restrito a operadores.');
    }

    /** Chave efetiva de um provider: provider_keys (cifrada) → .env. */
    private function effectiveKey(string $provider): string
    {
        $pk = ProviderKey::where('provider', $provider)->first();
        if ($pk?->api_key) {
            return $pk->api_key;
        }

        return $provider === 'zernio' ? (string) (config('services.zernio.key') ?: env('ZERNIO_API_KEY', '')) : '';
    }

    public function index(Request $r): JsonResponse
    {
        $this->gate($r);
        $configured = [];
        foreach (self::PROVIDERS as $p) {
            $configured[$p['key']] = $this->effectiveKey($p['key']) !== '';
        }

        return response()->json(['ok' => true, 'providers' => self::PROVIDERS, 'configured' => $configured]);
    }

    public function update(Request $r): JsonResponse
    {
        $this->gate($r);
        $changed = 0;
        foreach (self::PROVIDERS as $p) {
            $v = trim((string) $r->input($p['key'], ''));
            if ($v !== '') {
                ProviderKey::updateOrCreate(['provider' => $p['key']], ['api_key' => $v]);
                $changed++;
            }
        }
        if ($changed === 0) {
            return response()->json(['ok' => false, 'error' => 'Nenhuma chave preenchida.'], 422);
        }

        return response()->json(['ok' => true, 'changed' => $changed, 'configured' => array_fill_keys(array_column(self::PROVIDERS, 'key'), true)]);
    }

    public function test(Request $r): JsonResponse
    {
        $this->gate($r);
        $provider = (string) $r->input('provider', 'zernio');
        $key = trim((string) $r->input('key', '')) ?: $this->effectiveKey($provider);
        if ($key === '') {
            return response()->json(['ok' => false, 'error' => 'Sem chave para testar.']);
        }
        $t0 = microtime(true);
        try {
            $resp = Http::withToken($key)->acceptJson()->timeout(15)->get('https://api.zernio.com/v1/profiles');
            $ms = (int) round((microtime(true) - $t0) * 1000);

            return response()->json($resp->successful()
                ? ['ok' => true, 'latency_ms' => $ms]
                : ['ok' => false, 'error' => 'HTTP '.$resp->status()]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function destroy(Request $r, string $provider): JsonResponse
    {
        $this->gate($r);
        ProviderKey::where('provider', $provider)->delete();

        return response()->json(['ok' => true, 'configured' => [$provider => $this->effectiveKey($provider) !== '']]);
    }
}
