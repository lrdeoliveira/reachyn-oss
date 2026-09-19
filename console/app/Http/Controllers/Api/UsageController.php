<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Scopes\TenantScope;
use App\Services\PublishingBilling;
use App\Services\UsageService;
use Illuminate\Http\Request;

/**
 * /api/usage — plano, consumo do mês e redes conectadas (cota local, sem cobrança).
 */
class UsageController extends Controller
{
    public function show(Request $request, UsageService $usage)
    {
        $t = TenantScope::activeTenant() ?? $request->user()->tenant;
        abort_unless($t, 404, 'Usuário sem marca.');
        $org = $t->organization ?? $request->user()->organization;
        abort_unless($org, 404, 'Usuário sem organização.');

        $limits = $org->limits();
        $summary = $usage->summary($t);
        $used = [
            'image' => $summary['kinds']['image']['used'] ?? 0,
            'video' => $summary['kinds']['video']['used'] ?? 0,
            'veo' => $summary['kinds']['veo']['used'] ?? 0,
        ];

        $networks = max(0, app(PublishingBilling::class)->accountCount($org));

        $recent = $org->creditTransactions()->latest('id')->limit(12)
            ->get(['delta', 'balance_after', 'type', 'reference_type', 'reference_id', 'created_at']);

        return response()->json([
            'ok' => true,
            'plan' => $org->plan,
            'limits' => $limits,
            'usage' => $used,
            'networks' => $networks,
            'billing_status' => $org->billing_status ?? 'none',
            'generation_active' => $org->hasGenerationPlan(),
            'publishing_active' => $org->hasPublishing(),
            'payments_enabled' => false,
            'voice_id' => $t->voice_id,
            'content_lang' => $t->content_lang ?? 'pt-BR',
            'brand_kit' => $t->brandKit(),
            'credits' => [
                'balance' => (int) $org->credit_balance,
                'monthly' => $org->monthlyCredits(),
                'exempt' => $org->isExempt(),
                'costs' => UsageService::CREDIT_COST,
                'recent' => $recent,
            ],
        ]);
    }
}
