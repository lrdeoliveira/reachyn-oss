<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate de geração. No OSS a cota é o saldo local (UsageService); este middleware não bloqueia por assinatura.
 */
class EnsureSubscribed
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
