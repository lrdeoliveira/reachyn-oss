<?php

use App\Http\Controllers\Api\AnimationController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AssetVersionController;
use App\Http\Controllers\Api\CharacterController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\ElementController;
use App\Http\Controllers\Api\FilmController;
use App\Http\Controllers\Api\GenerateController;
use App\Http\Controllers\Api\GenerationKeyController;
use App\Http\Controllers\Api\GenLinesController;
use App\Http\Controllers\Api\GenModelController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PromptController;
use App\Http\Controllers\Api\ProviderCreditController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Controllers\Api\PublishKeyController;
use App\Http\Controllers\Api\ScenarioController;
use App\Http\Controllers\Api\SceneController;
use App\Http\Controllers\Api\ShotController;
use App\Http\Controllers\Api\SpriteController;
use App\Http\Controllers\Api\StudioAssetController;
use App\Http\Controllers\Api\StudioController;
use App\Http\Controllers\Api\StudioTemplateController;
use App\Http\Controllers\Api\UsageController;
use App\Models\AnimationProject;
use App\Models\Connection;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;


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
})->middleware('throttle:20,1,sso-exchange');

// AUD-003/019: TODA rota autenticada ganha rate limit base (60 req/min por usuário/IP).
// ⚠️ throttle SEMPRE com PREFIXO (3º arg): sem ele, o Laravel usa a MESMA chave (sha1 do user id)
// pra TODAS as rotas — o polling do app esgotava o balde compartilhado e rotas de limite baixo
// (retry 10/min, variações 6/min) respondiam 429 na PRIMEIRA tentativa. Incidente 2026-07-08.
Route::middleware(['auth:sanctum', 'throttle:120,1,api-base'])->group(function () {
    // Fase 2: 'tenant' = marca ATIVA (selecionada); 'organization' = billing; 'brands' = marcas da org.
    Route::get('/me', function (Request $request) {
        $user = $request->user();
        $org = $user->organization;
        $active = TenantScope::activeTenant() ?? $user->tenant;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant' => $active ? [
                'id' => $active->id, 'name' => $active->name,
                'plan' => $active->plan, 'voice_id' => $active->voice_id,
            ] : null,
            'organization' => $org ? ['id' => $org->id, 'name' => $org->name, 'plan' => $org->plan] : null,
            'brands' => $user->accessibleTenants()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug])->values(),
            'active_tenant_id' => $active?->id,
        ];
    });

    // S5: trocar senha LOGADO (a partir do Studio /conta). Exige a senha atual e aplica a
    // mesma régua do cadastro (min 10, maiúsc+minúsc, número, não-vazada — baseline #9).
    // Throttle apertado: força-bruta da senha atual não passa.
    Route::post('/account/password', function (Request $request) {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()->uncompromised()],
        ]);
        $u = $request->user();
        if (! Hash::check($data['current_password'], $u->password)) {
            return response()->json(['ok' => false, 'error' => 'A senha atual não confere.'], 422);
        }
        $u->forceFill(['password' => $data['password']])->save(); // cast 'hashed' aplica o hash
        // Hardening: senha trocada → revoga os DEMAIS tokens Sanctum (outros dispositivos/SSO
        // antigos caem); o token/sessão atual segue válido pra não deslogar quem trocou.
        $current = $u->currentAccessToken();
        $tokens = $u->tokens();
        if ($current instanceof PersonalAccessToken) {
            $tokens->whereKeyNot($current->getKey());
        }
        $tokens->delete();

        return ['ok' => true];
    })->middleware('throttle:5,1,account-password');

    // S3: estado do checklist de primeiro uso — DERIVADO ao vivo do banco (sem tabela nova).
    // Connection/Draft/Publication têm global scope por tenant; AnimationProject NÃO tem o trait
    // BelongsToTenant → escopo EXPLÍCITO aqui (sem isso, projeto de QUALQUER tenant marcava o
    // passo "Estúdio" pra todo mundo — vazamento cross-tenant do booleano).
    Route::get('/onboarding', function (Request $request) {
        $t = TenantScope::activeTenant() ?? $request->user()->tenant;

        return ['ok' => true, 'steps' => [
            'connected' => Connection::query()->exists(),
            'media' => Draft::query()->whereRaw("coalesce(media::text,'[]') <> '[]'")->exists(),
            'studio' => $t !== null && AnimationProject::where('tenant_id', $t->id)->exists(),
            'published' => Publication::query()->exists(),
        ]];
    });

    // S2: preferências de aviso por e-mail (opt-out). Chaves: generation_ready,
    // approvals_digest, credits_low, trial_ending (ausente = ligado).
    Route::get('/notify-prefs', function (Request $request) {
        $keys = ['generation_ready', 'approvals_digest', 'credits_low', 'trial_ending'];
        $prefs = (array) ($request->user()->notify_prefs ?? []);

        return ['ok' => true, 'prefs' => collect($keys)->mapWithKeys(fn ($k) => [$k => ($prefs[$k] ?? true) !== false])];
    });
    Route::patch('/notify-prefs', function (Request $request) {
        $keys = ['generation_ready', 'approvals_digest', 'credits_low', 'trial_ending'];
        $in = $request->validate(collect($keys)->mapWithKeys(fn ($k) => [$k => 'sometimes|boolean'])->all());
        $u = $request->user();
        $u->forceFill(['notify_prefs' => array_merge((array) ($u->notify_prefs ?? []), $in)])->save();

        return ['ok' => true];
    });

    // Catálogo de modelos de geração disponível pro cliente (por kind: video/image/audio/text).
    // Só ativos, filtrado por plano, white-label (GenModelResource esconde provedor/endpoint).
    Route::get('/gen-models', [GenModelController::class, 'index']);

    // Uso x quota do tenant + fatura (página Plano & Uso)
    Route::get('/usage', [UsageController::class, 'show']);
    // Saldo REAL da conta no agregador (dinheiro do provedor, não cota do plano). Só operador —
    // o controller devolve 403 pro resto, porque cita o provedor pelo nome (white-label #6).
    Route::get('/provider-credit', [ProviderCreditController::class, 'show']);
    // Arquivo de publicações (snapshot permanente do que foi ao ar) — escopado por tenant.
    Route::get('/publications', [PublicationController::class, 'index']);
    Route::get('/publications/{publication}', [PublicationController::class, 'show']);
    Route::delete('/publications/{publication}', [PublicationController::class, 'destroy']); // remove só o registro local (não despublica das redes)
    Route::post('/publications/bulk-delete', [PublicationController::class, 'bulkDestroy']); // remove vários registros locais
    Route::post('/publications/{publication}/retry', [PublicationController::class, 'retry'])->middleware('throttle:10,1,retry'); // 🔁 reposta só as redes que falharam
    Route::post('/publications/{publication}/republish', [PublicationController::class, 'republish'])->middleware('throttle:10,1,republish'); // ♻️ reposta TODAS as redes de novo
    Route::post('/publications/{publication}/repost', [PublicationController::class, 'repost'])->middleware('throttle:10,1,repost'); // ➕ publica nas redes ESCOLHIDAS (inclui novas)
    // Galeria de mídia do tenant logado (escopada por tenant_id no banco).
    Route::get('/media/list', [StudioController::class, 'mediaList']);
    // Refinadores de prompt disponíveis (CLIs vivas no host, via /health do cli-bridge).
    Route::get('/studio/refiners', [StudioController::class, 'refiners']);
    // Exclui um item da galeria por draft_id + id (item de qualquer rascunho do tenant).
    Route::delete('/media/item', [StudioController::class, 'mediaListDelete']);
    // Remove de uma vez todas as mídias mortas (URL fora do nosso storage) do tenant.
    Route::post('/media/clean-broken', [StudioController::class, 'mediaCleanBroken']);
    // Congela um quadro de um clipe do acervo e adota como base de personagem/cenário (fusão
    // FoxAssets, 2026-08-01). Não gasta crédito — só move mídia já gerada.
    Route::post('/media/frame-to-base', [StudioController::class, 'frameToBase'])->middleware('throttle:20,1,media-frame-to-base');
    // 🩹 Conserto por máscara / 🎨 Refino i2i — motor LOCAL (ComfyUI). Em ambiente sem esse motor
    // ativo no catálogo, a rota responde 422 de forma limpa (GenModel::resolveSelectable = null).
    Route::post('/studio/inpaint', [StudioController::class, 'inpaint'])->middleware('throttle:20,1,inpaint');
    Route::post('/studio/refine-local', [StudioController::class, 'refineLocal'])->middleware('throttle:20,1,refine-local');

    // Studio — fluxo do dashboard (rascunho): pesquisar → conteúdo → mídia → publicar
    // AUD-003/019: rotas de IA de texto (gastam crédito de pesquisa/LLM) têm throttle dedicado
    // mais apertado (30/min) além do limite base do grupo.
    Route::post('/studio/research', [StudioController::class, 'research'])->middleware('throttle:30,1,research');
    Route::post('/studio/deepsearch', [StudioController::class, 'deepResearch'])->middleware('throttle:30,1,deepsearch');
    Route::post('/studio/resummarize', [StudioController::class, 'resummarize'])->middleware('throttle:30,1,resummarize');
    Route::get('/studio/brand-voice', [StudioController::class, 'brandVoice']);
    Route::post('/studio/brand-voice', [StudioController::class, 'brandVoice'])->middleware('throttle:30,1,brand-voice');
    Route::get('/studio/brand-kit', [StudioController::class, 'brandKit']);
    Route::post('/studio/brand-kit', [StudioController::class, 'brandKit'])->middleware('throttle:30,1,brand-kit');
    Route::post('/studio/compose', [StudioController::class, 'compose'])->middleware('throttle:30,1,compose');
    Route::post('/studio/shot-card', [StudioController::class, 'shotCard'])->middleware('throttle:30,1,shot-card');
    Route::patch('/studio/research', [StudioController::class, 'researchUpdate']);
    Route::post('/studio/imageprompt', [StudioController::class, 'imagePrompt'])->middleware('throttle:30,1,imageprompt');
    Route::post('/studio/mediaprompts', [StudioController::class, 'mediaPrompts'])->middleware('throttle:30,1,mediaprompts');
    Route::get('/studio/voices', [StudioController::class, 'voices']);
    Route::get('/studio/search-keys', [StudioController::class, 'searchKeys']);
    Route::post('/studio/search-keys', [StudioController::class, 'searchKeysUpdate']);
    // Principal/fallback por função de pesquisa (normal/deep/scraper) + teste de chave.
    Route::get('/studio/search-config', [StudioController::class, 'searchConfig']);
    Route::post('/studio/search-config', [StudioController::class, 'searchConfigUpdate']);
    Route::post('/studio/search-test', [StudioController::class, 'searchTest'])->middleware('throttle:30,1,search-test');
    Route::get('/studio/draft', [StudioController::class, 'show']);
    // Criar rascunho EM BRANCO (sem pesquisa) — só o tema; a geração roda com brief vazio.
    Route::post('/studio/draft', [StudioController::class, 'createBlank']);
    Route::post('/studio/text', [StudioController::class, 'text'])->middleware('throttle:30,1,text');
    Route::post('/studio/ideas', [StudioController::class, 'ideas'])->middleware('throttle:20,1,ideas'); // 💡 gerador de ideias (F1 da Fábrica de Conteúdo, texto puro)
    Route::post('/studio/optimize', [StudioController::class, 'optimize'])->middleware('throttle:20,1,optimize'); // 🚀 pacote de otimização (F4 da Fábrica de Conteúdo, texto puro)
    Route::post('/studio/repurpose', [StudioController::class, 'repurpose'])->middleware('throttle:20,1,repurpose'); // ♻️ transformação de formato (F6 da Fábrica de Conteúdo, texto puro)
    Route::post('/studio/calendar', [StudioController::class, 'calendar'])->middleware('throttle:20,1,calendar'); // 🗓️ calendário editorial + séries (F5 da Fábrica de Conteúdo, texto puro)
    // 🎞️ Motion: anima a TELA ESTÁTICA aprovada (gate de aprovação — a tela custa pouco, o vídeo caro).
    Route::post('/studio/motion-clip', [StudioController::class, 'motionClip'])->middleware('throttle:20,1,motion-clip');
    // 🎬 JUNTAR os clipes de motion num vídeo só. Não gera nem cobra crédito de IA — os clipes já
    // foram pagos; é a mesma montagem do filme contínuo (/concat-clips), reusada.
    Route::post('/studio/motion-join', [StudioController::class, 'motionJoin'])->middleware('throttle:10,1,motion-join');
    Route::post('/studio/story', [StudioController::class, 'story'])->middleware('throttle:20,1,story');
    Route::post('/studio/story-review', [StudioController::class, 'storyReview'])->middleware('throttle:12,1,story-review'); // 🎬 script doctor (S2, texto puro)
    Route::post('/studio/story-structure', [StudioController::class, 'storyStructure'])->middleware('throttle:12,1,story-structure'); // 🎬 espinha dramática (S2 passo 1)
    Route::patch('/studio/story-scenes', [StudioController::class, 'storyScenes']);
    Route::post('/studio/story-reference', [StudioController::class, 'storyReference']);
    Route::post('/studio/story-reference-url', [StudioController::class, 'storyReferenceUrl']);
    Route::patch('/studio/story-cast', [StudioController::class, 'storyCast']);
    Route::post('/studio/story-cast-generate', [StudioController::class, 'storyCastGenerate'])->middleware('throttle:20,1,story-cast-generate');
    Route::post('/studio/story-audio', [StudioController::class, 'storyAudio'])->middleware('throttle:30,1,story-audio');
    Route::post('/studio/story-clear-media', [StudioController::class, 'storyClearMedia']);
    Route::post('/studio/story-scene-ref-remove', [StudioController::class, 'storySceneRefRemove']);
    Route::post('/studio/story-base-ref-clear', [StudioController::class, 'storyBaseRefClear']);
    Route::post('/studio/story-scene-add', [StudioController::class, 'storySceneAdd']);
    Route::post('/studio/story-scene-remove', [StudioController::class, 'storySceneRemove']);
    Route::post('/studio/story-scene-move', [StudioController::class, 'storySceneMove']);
    Route::post('/studio/story-image', [StudioController::class, 'storyImage'])->middleware('throttle:30,1,story-image');
    Route::post('/studio/image-variations', [StudioController::class, 'imageVariations'])->middleware('throttle:6,1,image-variations'); // 🎲 3 candidatas síncronas (cada request = até 4 gerações → teto ~24 img/min)
    Route::post('/studio/image-filter', [StudioController::class, 'imageFilter'])->middleware('throttle:30,1,image-filter'); // 🎨 F2: filtro Instagram numa foto (síncrono, 2 créd effect)
    Route::post('/studio/story-edit-image', [StudioController::class, 'storyEditImage'])->middleware('throttle:30,1,story-edit-image');
    Route::post('/studio/story-clip', [StudioController::class, 'storyClip'])->middleware('throttle:30,1,story-clip');
    Route::post('/studio/story-video', [StudioController::class, 'storyVideo'])->middleware('throttle:20,1,story-video');
    Route::post('/studio/story-texts', [StudioController::class, 'storyTexts'])->middleware('throttle:20,1,story-texts');
    // Redes definitivas do post (não gera nada, não gasta crédito) — a seleção das badges manda.
    Route::post('/studio/post-networks', [StudioController::class, 'postNetworks'])->middleware('throttle:60,1,post-networks');
    // 🎬 MOVIES REMOVIDO (2026-07-22): a aba dependia de a IA obedecer a grade do storyboard-sheet
    // pra os quadros do filme saírem no aspecto certo — e ela não obedece (pedimos 4x4, veio 2x6
    // com painéis deitados; 4x3 → 2x5). O erro contaminava keyframe e vídeo (faixa preta) e os
    // painéis do storyboard nem apareciam no filme. Toda a superfície /studio/film* saiu com a aba.
    // As rotas de AVENTURAS e a listagem de filmes seguem abaixo: elas só LEEM o que já existe, e
    // os filmes montados antes continuam válidos. O código do FilmController fica no repositório
    // (inalcançável) porque as Aventuras compartilham o controller — e pra restaurar ser um revert.
    Route::post('/studio/story-scene-image-set', [StudioController::class, 'storySceneImageSet']);
    Route::post('/studio/story-scene-video-set', [StudioController::class, 'storySceneVideoSet']);
    Route::post('/studio/story-clear-all-media', [StudioController::class, 'storyClearAllMedia']);
    // 🗺️ AVENTURAS — junta filmes prontos num filmão (ex: 5 histórias de 1-2min).
    Route::get('/studio/adventure-films', [FilmController::class, 'adventureFilms']);
    Route::post('/studio/adventure', [FilmController::class, 'adventure'])->middleware('throttle:10,1,adventure');
    Route::get('/studio/story-export', [StudioController::class, 'storyExport'])->middleware('throttle:20,1,story-export');
    Route::post('/studio/story-final-upload', [StudioController::class, 'storyFinalUpload'])->middleware('throttle:20,1,story-final-upload');
    Route::patch('/studio/text', [StudioController::class, 'textUpdate']);
    // ✅ Roteiro do Vox SEM gerar mídia (texto puro, barato): o cliente lê e corrige antes de a
    // peça virar dinheiro. Ver StudioController::voxRoteiro.
    // 💳 Luz de saldo do provedor (leitura, barata) — a tela avisa antes de o cliente gastar.
    Route::get('/studio/saldo', [StudioController::class, 'saldoProvedor']);
    Route::post('/studio/vox-roteiro', [StudioController::class, 'voxRoteiro'])->middleware('throttle:20,1,vox-roteiro');
    // 🎬 Montador da aba Vox: concatena clipes do acervo (subidos ou da galeria) na ordem dada,
    // via o MESMO /v1/filmassemble da aba Filme. Ver StudioController::voxMontar.
    Route::post('/studio/vox-montar', [StudioController::class, 'voxMontar'])->middleware('throttle:10,1,vox-montar');
    // 💾 Storyboard do Vox persistido no rascunho (story.vox) + 🎬 geração/regeneração de UMA
    // cena (job assíncrono → clip_url no beat). Ver voxStoryboard/voxCena.
    Route::post('/studio/vox-storyboard', [StudioController::class, 'voxStoryboard'])->middleware('throttle:30,1,vox-storyboard');
    Route::post('/studio/vox-cena', [StudioController::class, 'voxCena'])->middleware('throttle:20,1,vox-cena');
    Route::post('/studio/media', [StudioController::class, 'media']);
    Route::delete('/studio/media', [StudioController::class, 'mediaDelete']);
    Route::post('/studio/media-clear', [StudioController::class, 'mediaClear']);
    // 🎠 CARROSSEL: o plano editorial (texto, barato) e o render dos slides (imagem, caro) são
    // rotas SEPARADAS de propósito — o usuário revisa o plano antes de gastar N imagens.
    // Throttles com PREFIXO próprio: chave sem prefixo é compartilhada entre rotas e derruba uma
    // por causa do uso da outra (429 já visto em produção).
    Route::post('/studio/carousel', [StudioController::class, 'carousel'])->middleware('throttle:20,1,carousel-plan');
    Route::post('/studio/carousel-render', [StudioController::class, 'carouselRender'])->middleware('throttle:20,1,carousel-render');
    // 🎞️ Carrossel → VÍDEO: monta os slides já renderizados num vídeo vertical. Prefixo PRÓPRIO no
    // throttle — throttle sem prefixo COMPARTILHA a chave entre rotas (incidente 2026-07-08).
    Route::post('/studio/carousel-video', [StudioController::class, 'carouselVideo'])->middleware('throttle:10,1,carousel-video');
    Route::post('/studio/visual-brief', [StudioController::class, 'visualBrief'])->middleware('throttle:10,1,visual-brief');
    Route::post('/studio/enhance', [StudioController::class, 'enhance'])->middleware('throttle:30,1,enhance'); // pós-processa imagem existente (upscale/remover fundo)
    Route::post('/studio/reformat', [StudioController::class, 'reformat'])->middleware('throttle:30,1,reformat'); // 🖼️ mesma arte em N proporções (GD, custo zero)
    // Fluxo Rápido / Quick Start (benchmark Nordy+RunningHub): templates de criação + EasyApps de pós-produção.
    Route::get('/studio/templates', [StudioTemplateController::class, 'index'])->middleware('throttle:60,1,studio-templates'); // leitura: catálogo globais+org
    Route::post('/studio/templates', [StudioTemplateController::class, 'store'])->middleware('throttle:20,1,studio-template-store'); // salva projeto/draft como receita da marca
    Route::post('/studio/templates/{id}/apply', [StudioTemplateController::class, 'apply'])->middleware('throttle:30,1,studio-template-apply'); // 0 crédito: pré-preenche Estúdio/abre draft
    Route::delete('/studio/templates/{id}', [StudioTemplateController::class, 'destroy'])->middleware('throttle:30,1,studio-template-del'); // remove receita da marca (nunca global)
    Route::post('/studio/easyapp', [StudioController::class, 'easyapp'])->middleware('throttle:30,1,studio-easyapp'); // EasyApp 1-clique: upscale/bg_remove/relight/product_bg
    // Hub My Assets (reuso de mídia da marca) — sem custo, escopo por tenant.
    Route::get('/studio/assets', [StudioAssetController::class, 'index'])->middleware('throttle:60,1,studio-assets');
    Route::post('/studio/assets', [StudioAssetController::class, 'store'])->middleware('throttle:60,1,studio-assets-store');
    Route::delete('/studio/assets/{id}', [StudioAssetController::class, 'destroy'])->middleware('throttle:60,1,studio-assets-del');
    Route::post('/studio/assets/{id}/favorite', [StudioAssetController::class, 'favorite'])->middleware('throttle:60,1,studio-assets-fav');
    Route::post('/studio/music', [StudioController::class, 'studioMusic'])->middleware('throttle:10,1,music');
    Route::post('/studio/tts', [StudioController::class, 'tts'])->middleware('throttle:30,1,tts');
    Route::post('/studio/veo', [StudioController::class, 'veo']);
    Route::post('/studio/thumbnail', [StudioController::class, 'thumbnail']);
    Route::post('/studio/clip', [StudioController::class, 'clip']);
    Route::post('/studio/dub', [StudioController::class, 'dub']);
    Route::post('/studio/voice-clone', [StudioController::class, 'voiceClone']);
    Route::post('/studio/upload', [StudioController::class, 'upload']);
    // Mídia que JÁ é nossa (galeria) → anexa por URL, sem trafegar bytes pelo navegador.
    Route::post('/studio/adopt', [StudioController::class, 'adopt']);
    Route::post('/studio/submit', [StudioController::class, 'submit']);
    Route::post('/studio/schedule', [StudioController::class, 'schedule'])->middleware('throttle:30,1,studio-schedule'); // 🗓️ agenda a publicação
    Route::post('/studio/unschedule', [StudioController::class, 'unschedule'])->middleware('throttle:30,1,studio-unschedule'); // cancela o agendamento
    Route::get('/studio/publish-status', [StudioController::class, 'publishStatus']);

    // Geração (proxy autenticado → engine, com enforcement de quota)
    // AUD-003/019: research/summarize/text não têm cota (texto) → throttle dedicado 30/min
    // garante que NENHUMA rota que gasta IA fique sem rate limit. As de mídia já têm cota (402).
    Route::post('/generate/research', [GenerateController::class, 'research'])->middleware('throttle:30,1,generate-research');
    Route::post('/generate/summarize', [GenerateController::class, 'summarize'])->middleware('throttle:30,1,generate-summarize');
    Route::post('/generate/text', [GenerateController::class, 'text'])->middleware('throttle:30,1,generate-text');
    Route::post('/generate/thumbnail', [GenerateController::class, 'thumbnail'])->middleware('throttle:30,1,generate-thumbnail');
    // /generate/image RESTAURADO (2026-08-01): a remoção de 2026-07-16 alegou "nenhum caller" e
    // estava errada — abas Imagem, Sprite e Ficha do personagem chamam e ESPERAM a URL na resposta
    // (não têm polling), então tomavam 404. Segue síncrona, mas com guarda: modelo `async` do
    // catálogo é recusado com 422 → é isso que impede o travamento de PHP-FPM que motivou a remoção.
    // Throttle mais apertado que as de texto: cada chamada gasta crédito de IA.
    Route::post('/generate/image', [GenerateController::class, 'image'])->middleware('throttle:20,1,generate-image');
    // /generate/video RESTAURADO (2026-08-01): mesma história do /generate/image — a remoção de
    // 2026-07-16 alegou "nenhum caller" e estava errada (abas Vídeo, Montagem e Sprites chamam e
    // ESPERAM a URL na resposta; tomavam 404). Segue síncrona, mas guardada: modelo `async` ou
    // premium (Veo, que roteia pro /v1/veo lento) é recusado com 422, e o proxy usa timeout
    // explícito de 180s em vez dos 600s do default. Throttle apertado: clipe é o item mais caro.
    Route::post('/generate/video', [GenerateController::class, 'video'])->middleware('throttle:10,1,generate-video');
    // /generate/{short,veo} seguem REMOVIDOS (2026-07-16): proxy síncrono de job LONGO e sem
    // caller. Mídia pesada vai por /studio/* (StudioController), que é assíncrona (jobs).

    // 🎬 ESTÚDIO DE ANIMAÇÃO (roteiro → desenho pronto): wizard da aba /animacao. Toda geração
    // é assíncrona (jobs) + gasta crédito de IA → throttle DEDICADO por rota (incidente 2026-07-08).
    Route::get('/animation', [AnimationController::class, 'index']);
    Route::post('/animation', [AnimationController::class, 'store'])->middleware('throttle:10,1,animation-create');
    Route::get('/animation/{id}', [AnimationController::class, 'show']);
    Route::patch('/animation/{id}', [AnimationController::class, 'update']);
    Route::delete('/animation/{id}', [AnimationController::class, 'destroy'])->middleware('throttle:20,1,animation-delete');
    Route::post('/animation/{id}/element', [AnimationController::class, 'element'])->middleware('throttle:30,1,animation-element');
    Route::post('/animation/{id}/element-character', [AnimationController::class, 'elementCharacter'])->middleware('throttle:30,1,animation-element-character'); // 🎭 biblioteca (sem custo)
    Route::post('/animation-structure', [AnimationController::class, 'structure'])->middleware('throttle:12,1,animation-structure'); // 🎬 Sala de Roteiro (texto puro, pré-projeto)
    // 🎞️ FOLHA DE STORYBOARD — o documento conferido ANTES da montagem. Composta dos keyframes que
    // já existem (não gera imagem, não gasta crédito), com a grade desenhada pelo compositor: é o
    // conserto da falha que matou a aba Movies em 2026-07-22 (a IA não obedecia a grade pedida).
    Route::post('/animation/{id}/storyboard', [AnimationController::class, 'storyboard'])->middleware('throttle:20,1,animation-storyboard');
    Route::post('/animation/{id}/frame-set', [AnimationController::class, 'frameSet']); // 📌 galeria → keyframe (sem custo)
    Route::post('/animation/{id}/scene-video-set', [AnimationController::class, 'sceneVideoSet']); // 🎬 galeria → clipe (sem custo)
    Route::post('/animation/{id}/motion', [AnimationController::class, 'motion'])->middleware('throttle:6,1,animation-motion'); // 🕺 motion transfer (RunningHub, premium/caro/assíncrono)
    Route::post('/animation/{id}/frame-variations', [AnimationController::class, 'frameVariations'])->middleware('throttle:6,1,animation-frame-variations'); // 🎲 síncrono, N imagens por request
    Route::post('/animation/{id}/frame-edit', [AnimationController::class, 'frameEdit'])->middleware('throttle:30,1,animation-frame-edit'); // ✏️ i2i "mude só isto"
    Route::post('/animation/{id}/review', [AnimationController::class, 'review'])->middleware('throttle:12,1,animation-review'); // 🩺 script doctor (texto puro)
    Route::post('/animation/{id}/section', [AnimationController::class, 'section'])->middleware('throttle:20,1,animation-section'); // ↻ regenera 1 seção do plano (paridade com o Filme)
    Route::post('/animation/{id}/element-scenario', [AnimationController::class, 'elementScenario'])->middleware('throttle:30,1,animation-element-scenario'); // 🌍 biblioteca de cenários (sem custo)
    Route::post('/animation/{id}/elements', [AnimationController::class, 'elements'])->middleware('throttle:10,1,animation-elements');
    // Curadoria da lista de elementos (sem custo — não gera imagem): acrescentar o que a IA não
    // extraiu e remover o que ela extraiu errado. Antes só dava pra GERAR, nunca pra editar a lista.
    Route::post('/animation/{id}/element-add', [AnimationController::class, 'elementAdd'])->middleware('throttle:30,1,animation-element-add');
    Route::delete('/animation/{id}/element', [AnimationController::class, 'elementRemove'])->middleware('throttle:30,1,animation-element-remove');
    Route::post('/animation/{id}/reparse-elements', [AnimationController::class, 'reparseElements'])->middleware('throttle:6,1,animation-reparse-elements'); // volta atrás de um excluir demais
    Route::post('/animation/{id}/frame', [AnimationController::class, 'frame'])->middleware('throttle:30,1,animation-frame');
    Route::post('/animation/{id}/frames', [AnimationController::class, 'frames'])->middleware('throttle:10,1,animation-frames');
    Route::post('/animation/{id}/frame-end', [AnimationController::class, 'frameEnd'])->middleware('throttle:30,1,animation-frame-end'); // 🔚 keyframe FINAL de 1 cena
    Route::post('/animation/{id}/frames-end', [AnimationController::class, 'framesEnd'])->middleware('throttle:10,1,animation-frames-end'); // 🔚 keyframes finais (todas)
    Route::post('/animation/{id}/scene', [AnimationController::class, 'scene'])->middleware('throttle:30,1,animation-scene');
    Route::post('/animation/{id}/scenes', [AnimationController::class, 'scenes'])->middleware('throttle:10,1,animation-scenes');
    // Paridade com a tela clássica: estrutura do storyboard + ref própria da cena + reimport do final.
    Route::post('/animation/{id}/scene-add', [AnimationController::class, 'sceneAdd'])->middleware('throttle:30,1,animation-scene-add');
    Route::post('/animation/{id}/scene-remove', [AnimationController::class, 'sceneRemove'])->middleware('throttle:30,1,animation-scene-remove');
    Route::post('/animation/{id}/scene-move', [AnimationController::class, 'sceneMove'])->middleware('throttle:60,1,animation-scene-move');
    Route::post('/animation/{id}/scene-ref', [AnimationController::class, 'sceneRef'])->middleware('throttle:30,1,animation-scene-ref');
    Route::post('/animation/{id}/final-upload', [AnimationController::class, 'finalUpload'])->middleware('throttle:20,1,animation-final-upload');
    Route::post('/animation/{id}/assemble', [AnimationController::class, 'assemble'])->middleware('throttle:10,1,animation-assemble');
    Route::post('/animation/{id}/auto', [AnimationController::class, 'auto'])->middleware('throttle:10,1,animation-auto');

    // Biblioteca de PERSONAGENS (CRUD + geração) — base reutilizável + model sheet híbrido, por tenant.
    // A geração (base/sheet/edit) é assíncrona (job) + gasta crédito de IA → throttle dedicado.
    // Biblioteca de CENÁRIOS (F5 — "Elementos"): CRUD + geração da imagem-âncora. A imagem vem da
    // galeria (store/update) OU é gerada por IA a partir da descrição (/image, assíncrono + cota),
    // igual à base do personagem. Throttle com NOME PRÓPRIO: sem o prefixo a chave é compartilhada
    // entre todas as rotas e um 429 aqui derrubaria o resto da API.
    Route::get('/scenarios', [ScenarioController::class, 'index']);
    Route::post('/scenarios', [ScenarioController::class, 'store']);
    Route::patch('/scenarios/{id}', [ScenarioController::class, 'update']);
    Route::delete('/scenarios/{id}', [ScenarioController::class, 'destroy']);
    Route::post('/scenarios/{id}/image', [ScenarioController::class, 'generateImage'])->middleware('throttle:20,1,scenarios-id-image');
    Route::get('/characters', [CharacterController::class, 'index']);
    Route::post('/characters', [CharacterController::class, 'store']);
    Route::get('/characters/{id}', [CharacterController::class, 'show']);
    Route::patch('/characters/{id}', [CharacterController::class, 'update']);
    Route::delete('/characters/{id}', [CharacterController::class, 'destroy']);
    Route::post('/characters/{id}/base', [CharacterController::class, 'generateBase'])->middleware('throttle:20,1,characters-id-base');
    // Fusão FoxAssets (2026-08-01): roda uma persona da aba Prompts contra uma mensagem; e
    // extrai character lock + bíblia da foto-base já enviada, sem regerar model sheet.
    Route::post('/characters/persona-chat', [CharacterController::class, 'personaChat'])->middleware('throttle:20,1,characters-persona-chat');
    Route::post('/characters/{id}/extract', [CharacterController::class, 'extractFromBase'])->middleware('throttle:20,1,characters-id-extract');
    // Prompts RECONSTRUÍDOS (base + model sheet) pra copiar/usar em ferramenta externa sem API; e o
    // round-trip inverso: subir de volta o que foi gerado fora (sem IA, sem cota).
    Route::get('/characters/{id}/prompts', [CharacterController::class, 'prompts']);
    Route::post('/characters/{id}/base-upload', [CharacterController::class, 'baseUpload'])->middleware('throttle:20,1,characters-id-base-upload');
    Route::post('/characters/{id}/sheet', [CharacterController::class, 'generateSheet'])->middleware('throttle:20,1,characters-id-sheet');
    Route::post('/characters/{id}/from-image', [CharacterController::class, 'fromImage'])->middleware('throttle:20,1,id-from-image');
    Route::post('/characters/{id}/edit-base', [CharacterController::class, 'editBase'])->middleware('throttle:30,1,id-edit-base');
    Route::post('/characters/{id}/enhance-base', [CharacterController::class, 'enhanceBase'])->middleware('throttle:20,1,id-enhance-base'); // 🎭 tirar fundo / ✨ melhorar qualidade da base
    // Model sheet por prancha: regenerar/editar UMA prancha, subir prancha externa, remover UMA prancha, destravar status preso.
    Route::post('/characters/{id}/panel', [CharacterController::class, 'regeneratePanel'])->middleware('throttle:20,1,characters-id-panel');
    Route::post('/characters/{id}/panel-upload', [CharacterController::class, 'panelUpload'])->middleware('throttle:20,1,characters-id-panel-upload');
    Route::delete('/characters/{id}/panel', [CharacterController::class, 'deletePanel']);
    Route::post('/characters/{id}/reset', [CharacterController::class, 'resetStatus']);
    // Figurino manual: gera/edita uma prancha de roupa (várias coexistem). Gasta crédito de IA → throttle.
    Route::post('/characters/{id}/outfit', [CharacterController::class, 'addOutfit'])->middleware('throttle:20,1,characters-id-outfit');

    // Fila de aprovação (Studio Next.js + engine/web)
    // Biblioteca de prompts (CRUD) — texto livre, por tenant.
    Route::get('/prompts', [PromptController::class, 'index']);
    Route::post('/prompts', [PromptController::class, 'store']);
    Route::patch('/prompts/{prompt}', [PromptController::class, 'update']);
    Route::delete('/prompts/{prompt}', [PromptController::class, 'destroy']);

    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals', [ApprovalController::class, 'store']);
    Route::patch('/approvals/{approval}', [ApprovalController::class, 'update']);
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);

    // Conexões (redes sociais via OAuth Zernio)
    Route::get('/connections', [ConnectionController::class, 'index']);
    // POST /connections (conexão por chave manual — WordPress) removido em 2026-07-29 junto com
    // a superfície de blog: sem uso em produção e gravava credencial sem validar. Só OAuth agora.
    Route::post('/connections/profile', [ConnectionController::class, 'addProfile'])->middleware('throttle:20,1,connections-profile');
    Route::delete('/connections', [ConnectionController::class, 'destroy']);

    // Chaves de geração (operador) — página nativa do Studio
    Route::get('/admin/gen-keys', [GenerationKeyController::class, 'index']);
    Route::put('/admin/gen-keys', [GenerationKeyController::class, 'update']);
    Route::post('/admin/gen-keys/test', [GenerationKeyController::class, 'test']);
    Route::delete('/admin/gen-keys/{provider}', [GenerationKeyController::class, 'destroy']);

    // Linhas de geração (operador) — principal/fallback por função (text/image/video/voice). GLOBAL.
    Route::get('/admin/gen-lines', [GenLinesController::class, 'index']);
    Route::post('/admin/gen-lines', [GenLinesController::class, 'update']);

    // Saldo dos provedores de IA (operador) — mesmo payload de /provider-credit, com nome
    // explícito pro painel da página Chaves & API. O controller devolve 403 pra não-operador.
    Route::get('/admin/provider-balances', [ProviderCreditController::class, 'show']);

    // Chaves de publicação (operador) — Zernio
    Route::get('/admin/publish-keys', [PublishKeyController::class, 'index']);
    Route::put('/admin/publish-keys', [PublishKeyController::class, 'update']);
    Route::post('/admin/publish-keys/test', [PublishKeyController::class, 'test']);
    Route::delete('/admin/publish-keys/{provider}', [PublishKeyController::class, 'destroy']);


    // ── 🎬 ESTÚDIO (fusão FoxAssets, 2026-08-01): PROJETOS/ESCALETA/PLANOS/CENÁRIOS-ELEMENTOS/
    // VERSÕES-DE-ASSET/SPRITE. Ver AGENTS.md do FoxAssets pro desenho completo do pipeline. ──

    // PROJETOS (F4) — o agrupador leve de uma escaleta (uma história / mini-GDD).
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::patch('/projects/{id}', [ProjectController::class, 'update']);
    Route::get('/projects/{id}/bible', [ProjectController::class, 'bible']); // export mini-GDD (Markdown)
    Route::get('/projects/{id}/screenplay', [ProjectController::class, 'screenplay']);
    Route::post('/projects/{id}/plan', [ProjectController::class, 'plan'])->middleware('throttle:10,1,projects-plan');
    Route::post('/projects/{id}/review', [ProjectController::class, 'review'])->middleware('throttle:10,1,projects-review');
    // 🎭 BOTÃO ÚNICO: gera a imagem-âncora de todo personagem/cenário/elemento da história que
    // ainda não tem, no modelo e na técnica da história. Throttle baixo: um clique dispara N jobs.
    Route::post('/projects/{id}/cast', [ProjectController::class, 'cast'])->middleware('throttle:6,1,projects-cast');
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);

    // ESCALETA (scenes) — cenas ordenadas do projeto; liga personagem × cenário × elementos.
    // 🎞️ FOLHA DE STORYBOARD do projeto — o documento conferido ANTES da montagem. Composta dos
    // quadros que já existem (não gera imagem, não gasta crédito), com a grade desenhada pelo
    // compositor: é o conserto da falha que matou a aba Movies (a IA não obedecia a grade pedida).
    Route::post('/projects/{id}/storyboard', [SceneController::class, 'storyboard'])->middleware('throttle:20,1,project-storyboard');
    Route::post('/scenes/reorder', [SceneController::class, 'reorder']);
    Route::post('/scenes', [SceneController::class, 'store']);
    Route::patch('/scenes/{id}', [SceneController::class, 'update']);
    Route::delete('/scenes/{id}', [SceneController::class, 'destroy']);

    // PLANOS (shots) — a decupagem de cada cena (enquadramento/ângulo/altura/movimento).
    Route::get('/shots/vocabulario', [ShotController::class, 'vocabulario']);
    // Vocabulário de AJUSTE do quadro (escuro, dramático, fechado, chuva…) — o front monta os
    // botões daqui, sem duplicar a lista.
    Route::get('/shots/ajustes', [ShotController::class, 'ajustes']);
    // ⬆️ Ponte do UPSCALE SELETIVO: sobe a resolução do quadro deste plano e amarra o resultado de
    // volta ao plano — é o que transforma "uma imagem melhor" no keyframe que vai virar clipe.
    Route::post('/shots/{id}/upscale', [ShotController::class, 'upscale'])->middleware('throttle:20,1,shot-upscale');
    Route::get('/shots/{id}', [ShotController::class, 'show']);
    Route::post('/shots', [ShotController::class, 'store']);
    Route::patch('/shots/{id}', [ShotController::class, 'update']);
    Route::delete('/shots/{id}', [ShotController::class, 'destroy']);
    Route::post('/shots/reorder', [ShotController::class, 'reorder']);
    Route::post('/shots/{id}/ancora', [ShotController::class, 'ancora'])->middleware('throttle:60,1,shots-ancora');
    Route::post('/shots/{id}/quadro', [ShotController::class, 'quadro'])->middleware('throttle:20,1,shots-quadro');
    Route::post('/scenes/{id}/decupar', [ShotController::class, 'decupar'])->middleware('throttle:20,1,scenes-decupar');

    // VERSÕES DE ASSET + MALHA 3D — histórico de imagem de personagem/cenário/elemento; upload/
    // geração/render de malha (GLB/FBX). Dependem de motor 3D LOCAL (ComfyUI/Trellis) no catálogo —
    // sem ele, os endpoints respondem erro limpo em vez de tentar gerar (mesma régua do inpaint).
    Route::get('/asset-versions', [AssetVersionController::class, 'index']);
    Route::post('/asset-versions/{id}/restore', [AssetVersionController::class, 'restore']);
    Route::delete('/asset-versions/{id}', [AssetVersionController::class, 'destroy']);
    Route::post('/mesh-upload', [AssetVersionController::class, 'meshUpload'])->middleware('throttle:20,1,mesh-upload');
    Route::post('/mesh-generate', [AssetVersionController::class, 'meshGenerate'])->middleware('throttle:6,1,mesh-generate');
    Route::get('/mesh-health', [AssetVersionController::class, 'meshHealth']);
    Route::get('/mesh/{tipo}/{id}', [AssetVersionController::class, 'mesh']);
    Route::delete('/mesh/{tipo}/{id}', [AssetVersionController::class, 'meshDelete'])->middleware('throttle:20,1,mesh-delete');
    Route::get('/mesh-fbx/{tipo}/{id}', [AssetVersionController::class, 'meshFbx']);
    Route::post('/mesh/{tipo}/{id}/render', [AssetVersionController::class, 'meshRender'])->middleware('throttle:12,1,mesh-render');

    // ELEMENTOS — catálogo de objetos/veículos/animais reutilizáveis (mesma mecânica de personagem/cenário).
    Route::get('/elements', [ElementController::class, 'index']);
    Route::get('/elements/categorias', [ElementController::class, 'categorias']);
    Route::post('/elements', [ElementController::class, 'store']);
    Route::patch('/elements/{id}', [ElementController::class, 'update']);
    Route::delete('/elements/{id}', [ElementController::class, 'destroy']);
    Route::post('/elements/{id}/image', [ElementController::class, 'generateImage'])->middleware('throttle:20,1,elements-id-image');

    // SPRITE — pipeline de sprite-sheet/animação 2D.
    Route::get('/sprite/me', [SpriteController::class, 'me']);
    Route::get('/sprite/jobs', [SpriteController::class, 'index']);
    Route::post('/sprite/jobs', [SpriteController::class, 'store'])->middleware('throttle:10,1,sprite-jobs');
    Route::get('/sprite/jobs/{id}', [SpriteController::class, 'show']);
    Route::post('/sprite/jobs/{id}/persist', [SpriteController::class, 'persist'])->middleware('throttle:20,1,sprite-persist');
    Route::post('/sprite/frames', [SpriteController::class, 'frames'])->middleware('throttle:30,1,sprite-frames');
    Route::post('/sprite/normalize', [SpriteController::class, 'normalize'])->middleware('throttle:30,1,sprite-normalize');
    Route::post('/sprite/asset-gallery', [SpriteController::class, 'assetGallery'])->middleware('throttle:30,1,sprite-asset-gallery');

    // ROTEIRO (canvas de nós) — resolvido o bloqueio de metering (2026-08-01): imagem/clipe da
    // cena cobram o custo do modelo escolhido (mesmo UsageService::tryConsume de qualquer outra
    // geração); montagem cobra o mesmo bucket 'short'+'effect' que FilmController::assemble já usa.
    Route::post('/roteiro/imagem', [GenerateController::class, 'roteiroImagem'])->middleware('throttle:20,1,roteiro-imagem');
    Route::post('/roteiro/render', [GenerateController::class, 'roteiroRender'])->middleware('throttle:10,1,roteiro-render');
    // Câmera programada da Montagem: sem IA e sem crédito (ffmpeg zoompan sobre o quadro parado).
    // Throttle mesmo assim — é barata, mas chama serviço externo (ffmpeg-service).
    Route::post('/roteiro/camclip', [GenerateController::class, 'roteiroCamclip'])->middleware('throttle:30,1,roteiro-camclip');
    Route::get('/roteiro/{draft}', [GenerateController::class, 'roteiroStatus']);
    Route::post('/generate/filmplan', [GenerateController::class, 'filmplan'])->middleware('throttle:12,1,generate-filmplan');
    Route::post('/generate/assemble', [GenerateController::class, 'assemble'])->middleware('throttle:10,1,generate-assemble');
    // ComfyUI (Estúdio local, GPU do Luciano) — só informativo, sem custo; a UI mostra "não
    // configurado" quando COMFY_URL está vazio (ver engine/internal/config, fase 4 da fusão).
    Route::get('/comfy/health', [GenerateController::class, 'comfyHealth']);
});
