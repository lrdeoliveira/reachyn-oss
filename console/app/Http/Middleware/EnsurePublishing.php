<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate de publicação. No OSS publicação não é produto separado — passa adiante.
 */
class EnsurePublishing
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
