<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate de pesquisa/resumo. No OSS não há trial pago — passa adiante.
 */
class EnsureTrialOrSubscribed
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
