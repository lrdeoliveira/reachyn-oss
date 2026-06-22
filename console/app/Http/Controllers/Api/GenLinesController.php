<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GenerationKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Linhas de GERAÇÃO (operador): principal/fallback por função (text/image/video/voice).
 * GLOBAL (não por tenant) — análogo ao gen-keys do operador, guardado em app_settings.
 * Operador-only. Salva em app_settings e empurra pro engine (efeito imediato).
 */
class GenLinesController extends Controller
{
    public function __construct(private GenerationKeys $keys) {}

    private function gate(Request $r): void
    {
        abort_unless($r->user()?->isOperator(), 403, 'Restrito a operadores.');
    }

    /** GET /api/admin/gen-lines — linhas efetivas + recomendados + allowlist por função. */
    public function index(Request $r): JsonResponse
    {
        $this->gate($r);

        return response()->json([
            'ok' => true,
            'lines' => $this->keys->genLines(),
            'recommended' => GenerationKeys::GEN_LINES_DEFAULT,
            'providers_by_function' => GenerationKeys::GEN_PROVIDERS,
        ]);
    }

    /**
     * POST /api/admin/gen-lines { lines } — valida e salva (422 se inválido); push pro engine.
     * { clear: true } reseta aos defaults recomendados.
     * Regra por função: primary válido pra função; fallback "" ou válido ≠ primary.
     */
    public function update(Request $r): JsonResponse
    {
        $this->gate($r);

        if ($r->boolean('clear')) {
            $this->keys->forgetGenLines();
            try {
                $this->keys->pushToEngine();
            } catch (\Throwable $e) {
                return response()->json(['ok' => true, 'cleared' => true, 'lines' => $this->keys->genLines(), 'warning' => 'Resetado no console, mas o engine não recebeu: '.$e->getMessage()]);
            }

            return response()->json(['ok' => true, 'cleared' => true, 'lines' => $this->keys->genLines()]);
        }

        $functions = GenerationKeys::GEN_PROVIDERS;
        $lines = [];
        foreach ($functions as $fn => $valid) {
            $in = (array) $r->input("lines.$fn", $r->input($fn, []));
            $primary = trim((string) ($in['primary'] ?? ''));
            $fallback = trim((string) ($in['fallback'] ?? ''));

            if ($primary === '' || ! in_array($primary, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor principal inválido para '$fn' (permitidos: ".implode(', ', $valid).').',
                ], 422);
            }
            if ($fallback !== '' && ! in_array($fallback, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor de fallback inválido para '$fn' (permitidos: ".implode(', ', $valid).' ou vazio).',
                ], 422);
            }
            if ($fallback !== '' && $fallback === $primary) {
                return response()->json([
                    'ok' => false,
                    'error' => "O fallback de '$fn' não pode ser igual ao principal.",
                ], 422);
            }
            $lines[$fn] = ['primary' => $primary, 'fallback' => $fallback];
        }

        $this->keys->setGenLines($lines);

        try {
            $this->keys->pushToEngine();
        } catch (\Throwable $e) {
            return response()->json(['ok' => true, 'lines' => $lines, 'warning' => 'Salvo no console, mas o engine não recebeu: '.$e->getMessage()]);
        }

        return response()->json(['ok' => true, 'lines' => $lines]);
    }
}
