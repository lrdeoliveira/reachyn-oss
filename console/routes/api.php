<?php

use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\GenerateController;
use App\Http\Controllers\Api\GenerationKeyController;
use App\Http\Controllers\Api\GenLinesController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Controllers\Api\PublishKeyController;
use App\Http\Controllers\Api\StudioController;
use App\Http\Controllers\Api\UsageController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// SSO one-time code → token efêmero (AUD-011). Pública por design: a credencial é o code
// de uso único (Cache::pull = get+forget atômico), não a sessão. Sem code válido → 401.
// AUD-019: throttle estrito (anti brute-force de codes).
Route::post('/studio/sso-exchange', function (Request $request) {
    $code = (string) $request->input('code');
    $uid = $code !== '' ? Cache::pull("studio_sso:{$code}") : null;
    abort_if(! $uid, 401, 'Código SSO inválido ou expirado.');
    $user = User::find($uid);
    abort_if(! $user, 401, 'Usuário não encontrado.');
    // Mesmo escopo/TTL mínimos do fix anterior, agora sem expor o token na URL.
    $token = $user->createToken('studio', ['studio'], now()->addMinutes(15))->plainTextToken;

    return response()->json(['token' => $token]);
})->middleware('throttle:20,1');

// AUD-003/019: TODA rota autenticada ganha rate limit base (60 req/min por usuário/IP).
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/me', fn (Request $request) => $request->user()->load('tenant'));

    // Uso x quota do tenant + fatura (página Plano & Uso)
    Route::get('/usage', [UsageController::class, 'show']);
    // Arquivo de publicações (snapshot permanente do que foi ao ar) — escopado por tenant.
    Route::get('/publications', [PublicationController::class, 'index']);
    Route::get('/publications/{publication}', [PublicationController::class, 'show']);
    Route::delete('/publications/{publication}', [PublicationController::class, 'destroy']); // remove só o registro local (não despublica das redes)
    // Galeria de mídia do tenant logado (escopada por tenant_id no banco).
    Route::get('/media/list', [StudioController::class, 'mediaList']);
    // Exclui um item da galeria por draft_id + id (item de qualquer rascunho do tenant).
    Route::delete('/media/item', [StudioController::class, 'mediaListDelete']);
    // Remove de uma vez todas as mídias mortas (URL fora do nosso storage) do tenant.
    Route::post('/media/clean-broken', [StudioController::class, 'mediaCleanBroken']);

    // Studio — fluxo do dashboard (rascunho): pesquisar → conteúdo → mídia → publicar
    // AUD-003/019: rotas de IA de texto (gastam crédito de pesquisa/LLM) têm throttle dedicado
    // mais apertado (30/min) além do limite base do grupo.
    Route::post('/studio/research', [StudioController::class, 'research'])->middleware('throttle:30,1');
    Route::post('/studio/deepsearch', [StudioController::class, 'deepResearch'])->middleware('throttle:30,1');
    Route::patch('/studio/research', [StudioController::class, 'researchUpdate']);
    Route::post('/studio/imageprompt', [StudioController::class, 'imagePrompt'])->middleware('throttle:30,1');
    Route::post('/studio/mediaprompts', [StudioController::class, 'mediaPrompts'])->middleware('throttle:30,1');
    Route::get('/studio/voices', [StudioController::class, 'voices']);
    Route::get('/studio/search-keys', [StudioController::class, 'searchKeys']);
    Route::post('/studio/search-keys', [StudioController::class, 'searchKeysUpdate']);
    // Principal/fallback por função de pesquisa (normal/deep/scraper) + teste de chave.
    Route::get('/studio/search-config', [StudioController::class, 'searchConfig']);
    Route::post('/studio/search-config', [StudioController::class, 'searchConfigUpdate']);
    Route::post('/studio/search-test', [StudioController::class, 'searchTest'])->middleware('throttle:30,1');
    Route::get('/studio/draft', [StudioController::class, 'show']);
    // Criar rascunho EM BRANCO (sem pesquisa) — só o tema; a geração roda com brief vazio.
    Route::post('/studio/draft', [StudioController::class, 'createBlank']);
    Route::post('/studio/text', [StudioController::class, 'text'])->middleware('throttle:30,1');
    Route::patch('/studio/text', [StudioController::class, 'textUpdate']);
    Route::post('/studio/media', [StudioController::class, 'media']);
    Route::delete('/studio/media', [StudioController::class, 'mediaDelete']);
    Route::post('/studio/premium-video', [StudioController::class, 'premiumVideo']);
    Route::post('/studio/thumbnail', [StudioController::class, 'thumbnail']);
    Route::post('/studio/viral', [StudioController::class, 'viral']);
    Route::post('/studio/clip', [StudioController::class, 'clip']);
    Route::post('/studio/dub', [StudioController::class, 'dub']);
    Route::post('/studio/voice-clone', [StudioController::class, 'voiceClone']);
    Route::post('/studio/upload', [StudioController::class, 'upload']);
    Route::post('/studio/submit', [StudioController::class, 'submit']);
    Route::get('/studio/publish-status', [StudioController::class, 'publishStatus']);

    // Geração (proxy autenticado → engine, com enforcement de quota)
    // AUD-003/019: research/summarize/text não têm cota (texto) → throttle dedicado 30/min
    // garante que NENHUMA rota que gasta IA fique sem rate limit. As de mídia já têm cota (402).
    Route::post('/generate/research', [GenerateController::class, 'research'])->middleware('throttle:30,1');
    Route::post('/generate/summarize', [GenerateController::class, 'summarize'])->middleware('throttle:30,1');
    Route::post('/generate/text', [GenerateController::class, 'text'])->middleware('throttle:30,1');
    Route::post('/generate/thumbnail', [GenerateController::class, 'thumbnail'])->middleware('throttle:30,1');
    Route::post('/generate/image', [GenerateController::class, 'image']);
    Route::post('/generate/short', [GenerateController::class, 'short']);
    Route::post('/generate/video', [GenerateController::class, 'video']);
    Route::post('/generate/premium-video', [GenerateController::class, 'premiumVideo']);

    // Fila de aprovação (Studio Next.js + engine/web)
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals', [ApprovalController::class, 'store']);
    Route::patch('/approvals/{approval}', [ApprovalController::class, 'update']);
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

    // Conexões (redes sociais Zernio + blog/WordPress)
    Route::get('/connections', [ConnectionController::class, 'index']);
    Route::post('/connections', [ConnectionController::class, 'store']);
    Route::delete('/connections', [ConnectionController::class, 'destroy']);

    // Chaves de geração (operador) — página nativa do Studio
    Route::get('/admin/gen-keys', [GenerationKeyController::class, 'index']);
    Route::put('/admin/gen-keys', [GenerationKeyController::class, 'update']);
    Route::post('/admin/gen-keys/test', [GenerationKeyController::class, 'test']);
    Route::delete('/admin/gen-keys/{provider}', [GenerationKeyController::class, 'destroy']);

    // Linhas de geração (operador) — principal/fallback por função (text/image/video/voice). GLOBAL.
    Route::get('/admin/gen-lines', [GenLinesController::class, 'index']);
    Route::post('/admin/gen-lines', [GenLinesController::class, 'update']);

    // Chaves de publicação (operador) — Zernio
    Route::get('/admin/publish-keys', [PublishKeyController::class, 'index']);
    Route::put('/admin/publish-keys', [PublishKeyController::class, 'update']);
    Route::post('/admin/publish-keys/test', [PublishKeyController::class, 'test']);
    Route::delete('/admin/publish-keys/{provider}', [PublishKeyController::class, 'destroy']);
});
