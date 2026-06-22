<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GenerationKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chaves de GERAÇÃO (operador) — consumidas pela página nativa do Studio (Next.js).
 * Operador-only. Nunca devolve as chaves em claro: só status (configurada?) + metadados.
 */
class GenerationKeyController extends Controller
{
    public function __construct(private GenerationKeys $keys) {}

    private function gate(Request $r): void
    {
        abort_unless($r->user()?->isOperator(), 403, 'Restrito a operadores.');
    }

    /** GET /api/admin/gen-keys — provedores + status (sem expor as chaves). */
    public function index(Request $r): JsonResponse
    {
        $this->gate($r);

        return response()->json([
            'ok' => true,
            'providers' => GenerationKeys::PROVIDERS,
            'configured' => $this->keys->configured(),
            // valores atuais de base_url/model por provider (não secretos) — pra UI preencher.
            'settings' => $this->keys->settings(),
        ]);
    }

    /** PUT /api/admin/gen-keys — salva as não-vazias (vazio = mantém) e empurra pro engine. */
    public function update(Request $r): JsonResponse
    {
        $this->gate($r);
        $changed = 0;
        foreach (GenerationKeys::PROVIDERS as $p) {
            $key = trim((string) $r->input($p['key'], ''));

            // base_url/model só são aceitos pra providers configuráveis (ex.: <provider>_base_url).
            $settings = [];
            foreach (GenerationKeys::configurableFields($p['key']) as $f) {
                $field = $p['key'].'_'.$f; // ex.: <provider>_base_url, <provider>_model
                if ($r->has($field)) {
                    $settings[$f] = trim((string) $r->input($field, ''));
                }
            }

            if ($key !== '' || $settings !== []) {
                $this->keys->set($p['key'], $key, $settings);
                $changed++;
            }
        }
        if ($changed === 0) {
            return response()->json(['ok' => false, 'error' => 'Nenhuma chave preenchida.'], 422);
        }
        try {
            $this->keys->pushToEngine();
        } catch (\Throwable $e) {
            return response()->json(['ok' => true, 'warning' => 'Salvo no console, mas o engine não recebeu: '.$e->getMessage(), 'configured' => $this->keys->configured(), 'settings' => $this->keys->settings()]);
        }

        return response()->json(['ok' => true, 'changed' => $changed, 'configured' => $this->keys->configured(), 'settings' => $this->keys->settings()]);
    }

    /** POST /api/admin/gen-keys/test — testa um provedor (chave do corpo ou a salva). */
    public function test(Request $r): JsonResponse
    {
        $this->gate($r);
        $provider = (string) $r->input('provider');
        $key = trim((string) $r->input('key', ''));
        if ($key === '') {
            $key = $this->keys->all()[$provider] ?? '';
        }

        return response()->json($this->keys->test($provider, $key));
    }

    /** DELETE /api/admin/gen-keys/{provider} — remove a chave (volta a usar a do sistema/.env). */
    public function destroy(Request $r, string $provider): JsonResponse
    {
        $this->gate($r);
        $this->keys->forget($provider);
        try {
            $this->keys->pushToEngine();
        } catch (\Throwable $e) {
            // best-effort — o boot-fetch reconcilia
        }

        return response()->json(['ok' => true, 'configured' => $this->keys->configured(), 'settings' => $this->keys->settings()]);
    }
}
