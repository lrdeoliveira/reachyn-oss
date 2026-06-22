<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UsageService;
use App\Services\ZernioService;
use Illuminate\Http\Request;

/**
 * /api/usage — plano, consumo do mês e redes conectadas.
 * Formato consumido pelo dashboard (página Plano & Uso + voice_id no Studio).
 */
class UsageController extends Controller
{
    public function show(Request $request, UsageService $usage)
    {
        $t = $request->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        $limits = $t->limits();
        $summary = $usage->summary($t);
        $used = [
            'image' => $summary['kinds']['image']['used'] ?? 0,
            'video' => $summary['kinds']['video']['used'] ?? 0,
            'premium-video' => $summary['kinds']['premium-video']['used'] ?? 0,
        ];

        $networks = 0;
        try {
            if ($t->zernio_profile_id) {
                $networks = count(app(ZernioService::class)->listAccounts($t->zernio_profile_id));
            }
        } catch (\Throwable $e) {
            // ignora — Zernio indisponível não quebra a página
        }

        return response()->json([
            'ok' => true,
            'plan' => $t->plan,
            'limits' => $limits,
            'usage' => $used,
            'networks' => $networks,
            'voice_id' => $t->voice_id,
        ]);
    }
}
