<?php

use App\Services\ZernioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::get('/', function () {
    return view('welcome');
});

/**
 * Boot-fetch do engine: chaves de geração geridas no console (cifradas → em claro).
 * Máquina-a-máquina na rede interna, protegido pelo token compartilhado (ENGINE_ADMIN_TOKEN).
 */
Route::get('/internal/gen-keys', function (Request $request) {
    $token = (string) config('services.engine.admin_token');
    abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Admin-Token')), 403);

    return response()->json(app(\App\Services\GenerationKeys::class)->payload());
});

// Cadastro fechado no Reachyn: qualquer /register cai no login (usuários criados pelo operador).
Route::match(['get', 'post'], '/register', fn () => redirect()->route('filament.app.auth.login'));
Route::match(['get', 'post'], '/app/register', fn () => redirect()->route('filament.app.auth.login'));

/**
 * SSO console → Studio: cliente logado no painel /app é redirecionado ao Studio (web)
 * com um ONE-TIME CODE (?code=) de uso único e curta validade (AUD-011). O Studio troca
 * o code pelo token efêmero via POST /api/studio/sso-exchange — o token NUNCA viaja na URL
 * (sem vazamento por Referer/histórico/logs). Substitui o "colar token" manual.
 */
Route::get('/studio-sso', function () {
    // Login do cliente vive no painel Filament (não há rota 'login' genérica).
    if (! Auth::check()) {
        return redirect()->route('filament.app.auth.login');
    }

    $base = rtrim((string) config('services.studio.url'), '/');
    abort_if($base === '', 404, 'Studio não configurado.');

    // Code aleatório de uso único (60s). O par user_id fica server-side no cache;
    // só o code opaco vai na URL — inútil após resgatado/expirado.
    $code = Str::random(48);
    Cache::put("studio_sso:{$code}", Auth::id(), now()->addSeconds(60));

    return redirect("{$base}/?code=".urlencode($code));
})->name('studio.sso');

/**
 * Logout do dashboard → encerra a sessão e volta ao login.
 * AUD-026: POST-only + CSRF (a rota web já passa pelo middleware VerifyCsrfToken). Antes
 * aceitava GET, o que permitia logout forçado via <img>/link (CSRF). Front deve usar form POST.
 */
Route::post('/logout', function (Request $request) {
    Auth::guard('web')->logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('filament.app.auth.login');
})->name('dash.logout');

/** OAuth white-label de rede social (Zernio): navegação full-page → 302 pro provedor → volta ao Studio. */
Route::get('/connect/{platform}', function (string $platform) {
    if (! Auth::check()) {
        return redirect()->route('filament.app.auth.login');
    }
    $t = Auth::user()->tenant;
    abort_unless($t, 404);

    $zernio = app(ZernioService::class);
    if (! $t->zernio_profile_id) {
        $t->update(['zernio_profile_id' => $zernio->createProfile($t->name ?: $t->slug)]);
    }
    $studio = rtrim((string) config('services.studio.url'), '/');

    // Gate de teto de redes: cada conta conectada no Zernio custa ~US$6/mês → respeitar o
    // limite do plano (Tenant::PLAN_LIMITS['networks']). Reconectar uma plataforma JÁ ligada
    // não conta como nova (renovação de token). Zernio fora do ar → fail-open (não trava).
    $limit = (int) ($t->limits()['networks'] ?? 0);
    try {
        $accounts = $zernio->listAccounts($t->zernio_profile_id);
        $hasPlatform = collect($accounts)->contains(fn ($a) => ($a['platform'] ?? null) === $platform);
        if (! $hasPlatform && count($accounts) >= $limit) {
            return redirect($studio.'/conexoes?erro=limite_redes&limite='.$limit);
        }
    } catch (\Throwable $e) {
        // fail-open: indisponibilidade do Zernio não impede o cliente de conectar.
    }

    $url = $zernio->connectUrl($platform, $t->zernio_profile_id, $studio.'/conexoes/ok?rede='.$platform);

    return redirect($url);
})->middleware(['throttle:20,1'])->name('connect.platform'); // AUD-019 anti-flood
