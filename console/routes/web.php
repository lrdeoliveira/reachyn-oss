<?php

use App\Http\Controllers\AccountPrivacyController;
use App\Models\Profile;
use App\Services\GenerationKeys;
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

    return response()->json(app(GenerationKeys::class)->payload());
});

// Cadastro self-service (freemium): a rota de registro é provida pelo painel Filament /app
// quando services.signup.enabled (REGISTRATION_ENABLED) = true. Com a flag OFF, /app/register
// simplesmente não existe (404) — não há mais redirect manual aqui.

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

/** OAuth white-label de rede social (Zernio): navegação full-page → 302 pro provedor → volta ao Studio.
 *  ?profile=<id> escolhe o PERFIL alvo (multi-perfil); sem isso, usa o perfil padrão do tenant. */
Route::get('/connect/{platform}', function (string $platform, Request $request) {
    if (! Auth::check()) {
        return redirect()->route('filament.app.auth.login');
    }
    $t = Auth::user()->tenant;
    abort_unless($t, 404);

    $zernio = app(ZernioService::class);
    $studio = rtrim((string) config('services.studio.url'), '/');

    // Perfil alvo: ?profile=<id> (deve ser do tenant) OU o padrão. Garante um profile Zernio nele.
    $profileId = $request->query('profile');
    $profile = $profileId
        ? Profile::where('tenant_id', $t->id)->find($profileId)
        : Profile::where('tenant_id', $t->id)->orderByDesc('is_default')->orderBy('id')->first();

    if (! $profile) {
        // Tenant ainda sem perfil: cria o profile Zernio + a linha padrão (espelha tenants.zernio_profile_id).
        $zpid = $t->zernio_profile_id ?: $zernio->createProfile($t->name ?: $t->slug);
        if (! $t->zernio_profile_id) {
            $t->update(['zernio_profile_id' => $zpid]);
        }
        $profile = Profile::create(['tenant_id' => $t->id, 'name' => $t->name ?: 'Principal', 'zernio_profile_id' => $zpid, 'is_default' => true]);
    } elseif (! $profile->zernio_profile_id) {
        $profile->update(['zernio_profile_id' => $zernio->createProfile($profile->name)]);
    }
    $zpid = $profile->zernio_profile_id;

    // F-pub: SEM teto de redes — cada conta conectada é cobrada $8/mês (publicação, separada dos
    // créditos de geração). O cliente conecta quantas quiser; a quantidade é só informativa (sem cobrança)
    // por PublishingBilling (scheduler diário reachyn:sync-publishing). Cada conta é lucrativa ($6
    // custo + $2 lucro), então não há motivo de limite.

    $url = $zernio->connectUrl($platform, $zpid, $studio.'/conexoes/ok?rede='.$platform);

    return redirect($url);
})->middleware(['throttle:20,1,connect-platform', 'publishing'])->name('connect.platform'); // AUD-019 anti-flood + gate de PUBLICAÇÃO (não geração)

/**
 * Canal de direitos do titular (LGPD) — "Privacidade & Meus Dados".
 * Exportar dados (art. 18, V) e excluir a conta (art. 18, VI). Autenticado (guard web).
 */
Route::middleware('auth')->group(function () {
    Route::get('/conta/privacidade', [AccountPrivacyController::class, 'index'])->name('account.privacy');
    Route::get('/conta/exportar', [AccountPrivacyController::class, 'export'])->name('account.export');
    Route::post('/conta/excluir', [AccountPrivacyController::class, 'requestDeletion'])->name('account.deletion.request');
    Route::post('/conta/excluir/cancelar', [AccountPrivacyController::class, 'cancelDeletion'])->name('account.deletion.cancel.panel');
});

// Cancelar exclusão via link do e-mail (PÚBLICA: o token é a credencial).
Route::get('/account/deletion/cancel/{token}', [AccountPrivacyController::class, 'cancelByToken'])
    ->where('token', '[A-Za-z0-9]+')->name('account.deletion.cancel');
