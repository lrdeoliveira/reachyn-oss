<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate de recurso PREMIUM (exclusivo do plano Studio). Mesmo critério já usado inline em
 * deepResearch/voiceClone/dub: libera só quem tem plano com limits()['premium'] = true
 * (Studio e o interno 'unlimited'/exempt). Independe de BILLING_ENFORCE — recurso premium
 * é sempre restrito ao plano que o inclui.
 *
 * Sem tenant resolvido → deixa passar (o controller trata 401/404).
 */
class EnsurePremiumPlan
{
    public function handle(Request $request, Closure $next)
    {
        $tenant = $request->user()?->tenant;
        if (! $tenant) {
            return $next($request);
        }

        if ($tenant->limits()['premium'] ?? false) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => 'studio_plan_required',
                'message' => 'As Histórias são exclusivas do plano Studio.',
            ], 402);
        }

        return redirect(rtrim((string) config('services.studio.url'), '/').'/plano?assinar=1');
    }
}
