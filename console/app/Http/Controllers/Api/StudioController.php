<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DubVideo;
use App\Jobs\GenerateVideoJob;
use App\Models\Draft;
use App\Services\PublishService;
use App\Services\UsageService;
use App\Services\ZernioService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Porta do dashboard antigo (reachyn-os): fluxo pesquisar→conteúdo→mídia→aprovar→publicar
 * em torno de um "rascunho" (Draft). Substitui os /api/studio/* do Next antigo, agora
 * autenticado (Sanctum) + tenant-scoped, chamando o engine Go.
 */
class StudioController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [];
    }

    public function __construct(private UsageService $usage) {}

    private function engine(): \Illuminate\Http\Client\PendingRequest
    {
        // X-Admin-Token em TODAS as chamadas /v1/* (o engine passou a exigir o token
        // compartilhado nas rotas de geração, não só /v1/admin). Defesa em profundidade.
        return Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()->timeout(600);
    }

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function draft(Request $r, int|string $id): Draft
    {
        return Draft::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    /** POST /api/studio/research { keyword } → pesquisa + resumo e cria o rascunho. */
    public function research(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }

        $sources = (array) $r->input('sources', ['web']);
        if ($sources === []) {
            $sources = ['web'];
        }
        $payload = ['keyword' => $keyword, 'sources' => $sources];
        // BYOK: chaves de pesquisa do próprio tenant (vazias = usa as do sistema)
        $keys = array_filter((array) ($t->search_keys ?? []), fn ($v) => trim((string) $v) !== '');
        if ($keys !== []) {
            $payload['keys'] = $keys;
        }
        // Principal/fallback por função (tenant ou defaults) → engine respeita a ordem.
        $payload['lines'] = $t->searchLines();
        $res = $this->engine()->post('/v1/research', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'pesquisa falhou'], 502);
        }
        $results = $res->json('results') ?? [];
        $answer = (string) $res->json('answer');

        $summary = $answer;
        $brief = '';
        if ($results !== []) {
            $sum = $this->engine()->post('/v1/summarize', ['keyword' => $keyword, 'sources' => $results]);
            if ($sum->successful()) {
                $summary = (string) ($sum->json('summary') ?: $answer);
                $brief = (string) $sum->json('brief');
            }
        }

        $research = ['answer' => $answer, 'summary' => $summary, 'brief' => $brief, 'results' => $results];
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword, 'research' => $research]);

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'research' => $research]);
    }

    /** POST /api/studio/deepsearch { keyword } → pesquisa profunda. Plano Studio. */
    public function deepResearch(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if (! ($t->limits()['premium'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Pesquisa profunda é exclusiva do plano Studio.'], 402);
        }
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }
        $payload = ['keyword' => $keyword];
        $keys = array_filter((array) ($t->search_keys ?? []), fn ($v) => trim((string) $v) !== '');
        if ($keys !== []) {
            $payload['keys'] = $keys;
        }
        // Principal/fallback por função (tenant ou defaults) → engine respeita a ordem.
        $payload['lines'] = $t->searchLines();
        $res = $this->engine()->post('/v1/deepsearch', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'pesquisa profunda falhou'], 502);
        }
        $summary = (string) $res->json('summary');
        $research = [
            'answer' => $summary,
            'summary' => $summary,
            'brief' => (string) ($res->json('brief') ?: $summary),
            'results' => $res->json('results') ?? [],
        ];
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword, 'research' => $research]);

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'research' => $research]);
    }

    /** GET /api/studio/search-keys → status (configurado ou não) de cada provedor de pesquisa. */
    public function searchKeys(Request $r): JsonResponse
    {
        $saved = (array) ($this->tenant($r)->search_keys ?? []);
        $configured = [];
        foreach (\App\Models\Tenant::SEARCH_KEY_SLUGS as $k) {
            $configured[$k] = isset($saved[$k]) && trim((string) $saved[$k]) !== '';
        }

        return response()->json(['ok' => true, 'configured' => $configured]);
    }

    /** POST /api/studio/search-keys → salva as chaves BYOK (merge das não-vazias) ou limpa. */
    public function searchKeysUpdate(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if ($r->boolean('clear')) {
            $t->update(['search_keys' => null]);

            return response()->json(['ok' => true, 'cleared' => true]);
        }
        $keys = (array) ($t->search_keys ?? []);
        foreach (\App\Models\Tenant::SEARCH_KEY_SLUGS as $k) {
            $v = trim((string) $r->input($k, ''));
            if ($v !== '') {
                $keys[$k] = $v;
            }
        }
        $t->update(['search_keys' => $keys ?: null]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/studio/search-config → configuração de principal/fallback por função.
     * Retorna as linhas efetivas (tenant OU defaults), status das chaves BYOK e os
     * defaults recomendados (pra UI exibir "recomendado").
     */
    public function searchConfig(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $saved = (array) ($t->search_keys ?? []);
        $keysConfigured = [];
        foreach (\App\Models\Tenant::SEARCH_KEY_SLUGS as $k) {
            $keysConfigured[$k] = isset($saved[$k]) && trim((string) $saved[$k]) !== '';
        }

        return response()->json([
            'ok' => true,
            'lines' => $t->searchLines(),
            'keys_configured' => $keysConfigured,
            'recommended' => \App\Models\Tenant::SEARCH_LINES_DEFAULT,
        ]);
    }

    /**
     * POST /api/studio/search-config → salva SÓ as linhas (principal/fallback) por função.
     * As chaves BYOK continuam no searchKeysUpdate (POST /api/studio/search-keys).
     * Valida provedor por função; fallback "" ou provedor válido ≠ primary. Inválido → 422.
     */
    public function searchConfigUpdate(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        if ($r->boolean('clear')) {
            $t->update(['search_lines' => null]);

            return response()->json(['ok' => true, 'cleared' => true, 'lines' => $t->fresh()->searchLines()]);
        }

        $providers = \App\Models\Tenant::SEARCH_PROVIDERS;
        $lines = [];
        foreach ($providers as $fn => $valid) {
            $in = (array) $r->input("lines.$fn", $r->input($fn, []));
            $primary = trim((string) ($in['primary'] ?? ''));
            $fallback = trim((string) ($in['fallback'] ?? ''));

            if ($primary === '' || ! in_array($primary, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor principal inválido para '$fn' (permitidos: ".implode(', ', $valid).').',
                ], 422);
            }
            if ($fallback !== '' && ! in_array($fallback, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor de fallback inválido para '$fn' (permitidos: ".implode(', ', $valid).' ou vazio).',
                ], 422);
            }
            if ($fallback !== '' && $fallback === $primary) {
                return response()->json([
                    'ok' => false,
                    'error' => "O fallback de '$fn' não pode ser igual ao principal.",
                ], 422);
            }
            $lines[$fn] = ['primary' => $primary, 'fallback' => $fallback];
        }

        $t->update(['search_lines' => $lines]);

        return response()->json(['ok' => true, 'lines' => $lines]);
    }

    /**
     * POST /api/studio/search-test { provider, key? } → testa uma chave de provedor de pesquisa.
     * A key efetiva = a enviada no body OU a salva do tenant (BYOK) OU a do sistema (no engine).
     * Repassa o veredito do engine ({ ok, latency_ms, error }).
     */
    public function searchTest(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $provider = trim((string) $r->input('provider'));
        $valid = \App\Models\Tenant::SEARCH_KEY_SLUGS;
        if (! in_array($provider, $valid, true)) {
            return response()->json(['ok' => false, 'error' => 'provedor inválido'], 422);
        }

        $payload = ['provider' => $provider];
        // Precedência: key do body > key salva do tenant > (engine usa a do sistema).
        $key = trim((string) $r->input('key', ''));
        if ($key === '') {
            $key = trim((string) (((array) ($t->search_keys ?? []))[$provider] ?? ''));
        }
        if ($key !== '') {
            $payload['key'] = $key;
        }

        $res = $this->engine()->post('/v1/search-test', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'teste de chave falhou'], 502);
        }

        return response()->json([
            'ok' => (bool) $res->json('ok'),
            'latency_ms' => $res->json('latency_ms'),
            'error' => $res->json('error'),
        ]);
    }

    /** POST /api/studio/imageprompt { draftId } → sugere descrição de imagem a partir do resumo. */
    public function imagePrompt(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $summary = $d->research['summary'] ?? $d->research['brief'] ?? '';
        if (trim((string) $summary) === '') {
            return response()->json(['ok' => false, 'error' => 'Sem resumo de pesquisa neste rascunho.'], 400);
        }
        $res = $this->engine()->post('/v1/imageprompt', ['keyword' => $d->keyword, 'summary' => $summary]);

        return response()->json(['ok' => true, 'prompt' => (string) $res->json('prompt')]);
    }

    /**
     * POST /api/studio/mediaprompts { draftId, kind?, aspect?, duration? } → sugere o(s) prompt(s)
     * de mídia JÁ condizentes com o tipo/tamanho/duração escolhidos. kind="image"|"video" gera só
     * o do tipo pedido; vazio = ambos (retrocompat). O prompt de vídeo é orientado a movimento/câmera.
     * Retrocompat: também devolve `prompt` (= imagePrompt) para consumidores antigos.
     */
    public function mediaPrompts(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $summary = $d->research['summary'] ?? $d->research['brief'] ?? '';
        if (trim((string) $summary) === '') {
            return response()->json(['ok' => false, 'error' => 'Sem resumo de pesquisa neste rascunho.'], 400);
        }
        // Textos das publicações priorizados por rank (aderência ao resumo) desc → o engine
        // combina resumo + textos, dando mais peso aos posts mais aderentes ao resumo.
        $meta = $d->texts_meta ?? [];
        $ordered = collect($d->texts ?? [])
            ->filter(fn ($t) => trim((string) $t) !== '')
            ->sortByDesc(fn ($t, $p) => (float) ($meta[$p]['rank'] ?? 0))
            ->values()->all();
        // tipo/tamanho/duração: o prompt é gerado só DEPOIS de definidos, p/ nascer condizente.
        $kind = in_array($r->input('kind'), ['image', 'video'], true) ? $r->input('kind') : '';
        $aspect = (string) $r->input('aspect', '');
        $duration = (string) $r->input('duration', '');
        $res = $this->engine()->post('/v1/mediaprompts', [
            'keyword' => $d->keyword, 'summary' => $summary, 'texts' => $ordered,
            'kind' => $kind, 'aspect' => $aspect, 'duration' => $duration,
        ]);
        $imagePrompt = (string) $res->json('imagePrompt');
        $videoPrompt = (string) $res->json('videoPrompt');

        return response()->json([
            'ok' => true,
            'imagePrompt' => $imagePrompt,
            'videoPrompt' => $videoPrompt,
            'prompt' => $imagePrompt, // retrocompat
        ]);
    }

    /** GET /api/studio/voices → vozes de narração disponíveis (da conta do provedor de voz). */
    public function voices(): JsonResponse
    {
        $res = $this->engine()->get('/v1/voices');
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível listar as vozes agora', 'voices' => []]);
        }

        return response()->json(['ok' => true, 'voices' => (array) $res->json('voices')]);
    }

    /** PATCH /api/studio/research { draftId, summary } → edita o resumo de referência. */
    public function researchUpdate(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $research = $d->research ?? [];
        $research['summary'] = (string) $r->input('summary');
        $d->update(['research' => $research]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/studio/draft?id= → estado do rascunho. */
    public function show(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->query('id'));

        return response()->json(['ok' => true, 'draft' => [
            'id' => $d->id,
            'keyword' => $d->keyword,
            'research' => $d->research,
            'texts' => $d->texts ?? (object) [],
            'texts_meta' => $d->texts_meta ?? (object) [],
            'media' => $d->media ?? [],
            'image_prompt' => $d->image_prompt,
            'image_url' => $d->image_url,
            'video_url' => $d->video_url,
            'status' => $d->status,
        ]]);
    }

    /** POST /api/studio/draft { keyword } → cria um rascunho EM BRANCO (sem pesquisa).
     *  Caminho "criar conteúdo sem pesquisa": o tema vira a referência e a geração de
     *  texto roda com brief/facts vazios — text() já trata research ausente. */
    public function createBlank(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword]);

        return response()->json(['ok' => true, 'draftId' => $draft->id]);
    }

    /** POST /api/studio/text { draftId, platform } → gera o texto da plataforma. */
    public function text(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $platform = (string) $r->input('platform');
        $brief = $d->research['summary'] ?? '';                            // resumo destilado (orientação)
        $facts = $d->research['brief'] ?? ($d->research['summary'] ?? ''); // material cru das fontes (base factual)

        $res = $this->engine()->post('/v1/text', ['keyword' => $d->keyword, 'brief' => $brief, 'facts' => $facts, 'platform' => $platform]);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'geração de texto falhou'], 502);
        }
        $post = (string) $res->json('post');
        $imagePrompt = (string) $res->json('image_prompt');
        $grounding = (float) $res->json('grounding');
        $rank = (float) $res->json('rank_summary');
        $flags = $res->json('flags') ?? [];

        $texts = array_merge($d->texts ?? [], [$platform => $post]);
        // FIXA o metadado do texto por plataforma: rank vs resumo + grounding vs fontes + flags.
        $textsMeta = array_merge($d->texts_meta ?? [], [$platform => ['rank' => $rank, 'grounding' => $grounding, 'flags' => $flags]]);
        $d->update([
            'texts' => $texts,
            'texts_meta' => $textsMeta,
            'image_prompt' => $d->image_prompt ?: $imagePrompt,
        ]);

        return response()->json(['ok' => true, 'platform' => $platform, 'post' => $post, 'image_prompt' => $imagePrompt, 'grounding' => $grounding, 'rank_summary' => $rank, 'flags' => $flags]);
    }

    /** PATCH /api/studio/text { draftId, platform, text } → salva o texto editado. */
    public function textUpdate(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $texts = array_merge($d->texts ?? [], [(string) $r->input('platform') => (string) $r->input('text')]);
        $d->update(['texts' => $texts]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/studio/media { draftId?, kind, prompt, aspect, style, voice_id, ... } → gera e acumula.
     *
     * Fluxo de VÍDEO UNIFICADO (kind="video"): um único endpoint atende desde o clipe simples
     * (scenes=1, sem narração/legenda/música) até o short completo (multi-cena, voz, legenda,
     * trilha). As "flags" do contrato front→console:
     *   scenes(1..12) · narration(bool) · voice_id · lang(pt-BR|en-US) · subtitles(bool)
     *   music(bool) · premium(bool) · duration(6|10) · prompt/videoPrompt · style · imageUrl
     *
     * Roteamento por custo/destino:
     *   premium=true  → vídeo premium (bucket 'premium-video', peso weightFor('premium-video')=10) — mesmo que premiumVideo().
     *   premium=false → /v1/video (bucket 'video'); o PESO depende da complexidade:
     *       (scenes>1 || narration || music) → weightFor('short')=5  (vídeo "produzido")
     *       senão                            → weightFor('video')=3  (clipe simples)
     *
     * Retrocompat: kind="image" segue intacto; kind="video" sem flags = clipe simples
     * (scenes=1, tudo off); kind="shortvideo" é ALIAS de video com defaults narration/
     * subtitles/music = true.
     */
    public function media(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $kind = (string) $r->input('kind');
        $prompt = (string) $r->input('prompt');

        // Imagem de input opcional (i2i/i2v/base do short). Vem de upload OU da galeria —
        // ambos geram URL do NOSSO S3. Validação anti-SSRF: se preenchida, EXIGE que seja
        // do nosso domínio de mídia (isOwnMediaUrl). URL externa arbitrária → 422.
        $imageUrl = (string) $r->input('imageUrl', '');
        if ($imageUrl !== '' && ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }

        $draftId = $r->input('draftId');
        if ($draftId) {
            $d = $this->draft($r, $draftId);
        } else {
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($prompt ?: 'mídia avulsa', 0, 80)]);
        }

        // ── IMAGEM (inalterado) ────────────────────────────────────────────────
        if ($kind === 'image') {
            $weight = $this->usage->weightFor('image');
            // RESERVE-THEN-CONSUME: reserva atômica ANTES de chamar o engine; estorna se falhar.
            if (! $this->usage->tryConsume($t, 'image', $weight)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            // formato da imagem: 9:16 | 1:1 | 16:9 | 4:5 (default 1:1).
            $imgAspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9', '4:5'], true) ? (string) $r->input('aspect') : '1:1';
            // i2i: repassa imageUrl quando presente (imagem de referência/base da geração).
            $payload = ['prompt' => $prompt ?: $d->keyword, 'aspect' => $imgAspect, 'style' => $r->input('style', 'realista')];
            if ($imageUrl !== '') {
                $payload['imageUrl'] = $imageUrl;
            }
            $url = $this->engine()->post('/v1/image', $payload)->json('url');
            if (! $url) {
                $this->usage->refund($t, 'image', $weight); // geração falhou → estorna
                return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
            }
            $item = ['id' => (string) (int) (microtime(true) * 1000), 'kind' => 'image', 'url' => $url, 'style' => (string) $r->input('style', 'realista')];
            $media = array_merge($d->media ?? [], [$item]);
            $d->update(['media' => $media]);

            return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
        }

        // ── VÍDEO UNIFICADO ────────────────────────────────────────────────────
        // 'shortvideo' = alias de 'video' com defaults de short (narração/legenda/música ON).
        if (! in_array($kind, ['video', 'shortvideo'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo de mídia inválido'], 400);
        }
        $isShortAlias = $kind === 'shortvideo';

        // Validações das flags do contrato:
        // duração de cada clipe: '5' ou '10' (o provider gera 5s ou 10s; '6' legado vira 5). Default '5'.
        $duration = (string) $r->input('duration', '5');
        if (! in_array($duration, ['5', '6', '10'], true)) {
            $duration = '5';
        }
        // scenes: nº de cenas (cada cena = 1 clipe). TETO por DURAÇÃO TOTAL = 5 min (300s) — cada
        // cena custa geração de IA, então o limite barra gasto excessivo: 5s→50 cenas, 10s→30 cenas.
        $perScene = $duration === '10' ? 10 : 5;
        $maxScenes = intdiv(300, $perScene);
        $scenes = (int) $r->input('scenes', 1);
        $scenes = max(1, min($maxScenes, $scenes ?: 1));
        // bools via FILTER_VALIDATE_BOOLEAN (aceita "true"/"1"/"on"/etc.); alias liga os defaults.
        $bool = fn (string $field, bool $default) => $r->has($field)
            ? filter_var($r->input($field), FILTER_VALIDATE_BOOLEAN)
            : $default;
        $narration = $bool('narration', $isShortAlias);
        $subtitles = $bool('subtitles', $isShortAlias);
        $music = $bool('music', $isShortAlias);
        $premium = $bool('premium', false);
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        $style = (string) $r->input('style', 'realista');

        // ── PREMIUM → vídeo premium (mesma lógica do método premiumVideo()) ─────
        if ($premium) {
            $weight = $this->usage->weightFor('premium-video'); // peso 10
            // RESERVE-THEN-CONSUME (AUD-002).
            if (! $this->usage->tryConsume($t, 'premium-video', $weight)) {
                return response()->json(['ok' => false, 'error' => 'Vídeo premium não está incluído no seu plano.'], 402);
            }
            $brief = $d->research['summary'] ?? $d->research['brief'] ?? '';
            $premiumStyle = (string) $r->input('style', 'cinematografico'); // vídeo premium usa default cinematográfico
            // ASSÍNCRONO (vídeo premium leva ~5-11min → estourava o proxy). Worker gera; front faz polling.
            GenerateVideoJob::dispatch($d->id, $t->id, '/v1/premium-video', [
                'keyword' => $d->keyword,
                'brief' => $brief,
                'style' => $premiumStyle,
                'lang' => $lang,
                // Vídeo premium + narração própria: voz do tenant por cima do vídeo mudo + legenda.
                'narration' => $narration,
                'voiceId' => $narration ? (string) ($r->input('voice_id') ?: $t->voice_id) : '',
            ], 'premium-video', $weight, $premiumStyle);

            return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo premium em geração — aparece na galeria em alguns minutos.']);
        }

        // ── NORMAL → /v1/video ─────────────────────────────────────────────────
        // BUCKET: short (multi-cena OU narração OU música) custa ~7× o clipe simples → bucket
        // próprio 'short', separado de 'video' (ver docs/custos-e-planos.md). 1 op = 1 unidade.
        $isProduced = $scenes > 1 || $narration || $music;
        $bucket = $isProduced ? 'short' : 'video';
        $weight = 1;
        // RESERVE-THEN-CONSUME (AUD-002): debita o bucket do tipo antes de chamar o engine.
        if (! $this->usage->tryConsume($t, $bucket, $weight)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }

        // videoPrompt tem precedência sobre prompt; fallback final = keyword.
        $videoPrompt = (string) $r->input('videoPrompt');
        $effectivePrompt = $videoPrompt !== '' ? $videoPrompt : ($prompt !== '' ? $prompt : $d->keyword);

        $payload = [
            'prompt' => $effectivePrompt,
            'scenes' => $scenes,
            'narration' => $narration,
            'voiceId' => (string) $r->input('voice_id'),
            'lang' => $lang,
            'subtitles' => $subtitles,
            'music' => $music,
            'duration' => $duration,
            'style' => $style,
            // formato do vídeo: só 9:16 (vertical) ou 16:9 (horizontal); default 9:16.
            'aspect' => $r->input('aspect') === '16:9' ? '16:9' : '9:16',
        ];
        // base/i2v: repassa imageUrl quando presente (e já validada como nossa acima).
        if ($imageUrl !== '') {
            $payload['imageUrl'] = $imageUrl;
        }
        // ASSÍNCRONO: o worker chama o engine (minutos) e anexa o vídeo; o front faz polling.
        // Síncrono aqui estourava o timeout do proxy → resposta cortada → "undefined" na UI.
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/video', $payload, $bucket, $weight, $style);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo em geração — aparece na galeria em alguns minutos.']);
    }

    /** DELETE /api/studio/media { draftId, id } → remove item da galeria. */
    public function mediaDelete(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $id = (string) $r->input('id');
        $media = array_values(array_filter($d->media ?? [], fn ($m) => ($m['id'] ?? null) !== $id));
        $d->update(['media' => $media]);

        return response()->json(['ok' => true, 'media' => $media]);
    }

    /**
     * DELETE /api/media/item { draft_id, id } → remove um item de mídia da GALERIA.
     *
     * Diferente do mediaDelete (que opera no rascunho "atual"), aqui o item pode estar
     * em QUALQUER rascunho do tenant — a galeria achata a mídia de todos. Por isso o
     * draft_id vem do front.
     *
     * Isolamento multi-tenant: o Draft é carregado escopado por tenant_id explícito
     * (defesa em profundidade) + global scope do trait BelongsToTenant; 404 se não for
     * do tenant logado. Só remove mídia de rascunho do próprio tenant.
     *
     * Se a URL for do nosso storage (disco `media`, host vindo de env), tenta apagar
     * o objeto no S3 também — em try/catch, pra não falhar se o objeto já sumiu.
     */
    public function mediaListDelete(Request $r): JsonResponse
    {
        $draftId = $r->input('draft_id', $r->input('draftId'));
        $d = $this->draft($r, $draftId); // escopado por tenant + firstOrFail (404)
        $id = (string) $r->input('id');

        $removed = collect($d->media ?? [])->firstWhere('id', $id);
        $media = array_values(array_filter($d->media ?? [], fn ($m) => ($m['id'] ?? null) !== $id));
        $d->update(['media' => $media]);

        // Apaga o objeto no nosso storage (S3) só se a URL for do nosso domínio
        // de mídia. URLs mortas/externas (de provedores, já expiradas) são ignoradas.
        $url = (string) ($removed['url'] ?? '');
        if ($url !== '' && self::isOwnMediaUrl($url)) {
            try {
                $base = rtrim((string) config('filesystems.disks.media.url'), '/');
                $key = ltrim((string) parse_url(substr($url, 0, strlen($base)) === $base ? substr($url, strlen($base)) : $url, PHP_URL_PATH), '/');
                if ($key !== '') {
                    Storage::disk('media')->delete($key);
                }
            } catch (\Throwable $e) {
                // objeto já não existe / storage indisponível → seguimos (o item já saiu do array)
            }
        }

        return response()->json(['ok' => true, 'media' => $media]);
    }

    /** POST /api/studio/submit { draftId } → publica cada plataforma do rascunho (Zernio). */
    public function submit(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $texts = $d->texts ?? [];
        $platforms = array_keys(array_filter($texts, fn ($v) => trim((string) $v) !== ''));
        if ($platforms === []) {
            return response()->json(['ok' => false, 'error' => 'nada pra publicar (gere textos primeiro)'], 400);
        }

        // AUD-004: anti-duplo-publish. Bloqueia se já está rodando e transiciona o state
        // de forma ATÔMICA (só dispara o job se ESTE request foi quem marcou 'running').
        abort_if(($d->publish['state'] ?? null) === 'running', 409, 'Publicação já em andamento.');

        $payload = [
            'state' => 'running',
            'platforms' => array_values($platforms),
            'started_at' => now()->toIso8601String(),
        ];
        // UPDATE condicional (PostgreSQL): vence a corrida só quem encontrar
        // publish->>'state' != 'running'. O operador ->> extrai o campo como texto
        // (funciona em colunas json e jsonb). null/qualquer-coisa != "running" passa.
        $claimed = Draft::where('id', $d->id)
            ->where('tenant_id', $t->id)
            ->where(function ($q) {
                $q->whereNull('publish')
                    ->orWhereRaw("publish->>'state' IS NULL")
                    ->orWhereRaw("publish->>'state' <> 'running'");
            })
            ->update(['publish' => json_encode($payload)]);

        abort_if($claimed !== 1, 409, 'Publicação já em andamento.');

        \App\Jobs\PublishDraftJob::dispatch($d->id);

        return response()->json(['ok' => true, 'async' => true, 'state' => 'running', 'platforms' => array_values($platforms)]);
    }

    /** GET /api/studio/publish-status { draftId } → estado do publish assíncrono. */
    public function publishStatus(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));

        return response()->json(['ok' => true, 'publish' => $d->publish, 'status' => $d->status]);
    }

    /**
     * Sobe bytes no sistema de mídia (S3, mesmo storage do ffmpeg-service).
     * Fallback gracioso pro storage local do console se o S3 estiver indisponível.
     */
    /**
     * AUD-013/014: valida que a URL é http(s) e aponta para o nosso domínio de mídia
     * (MEDIA_S3_PUBLIC_BASE, host vindo de env). Bloqueia file://, IP interno e hosts externos.
     */
    public static function isOwnMediaUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $allowed = [];
        if ($base = parse_url((string) config('filesystems.disks.media.url'), PHP_URL_HOST)) {
            $allowed[] = strtolower((string) $base);
        }
        if ($base = parse_url((string) env('MEDIA_S3_PUBLIC_BASE'), PHP_URL_HOST)) {
            $allowed[] = strtolower((string) $base);
        }

        foreach (array_unique(array_filter($allowed)) as $h) {
            if ($host === $h) {
                return true;
            }
        }

        return false;
    }

    /** RBK-003/PUB-002: host é YouTube (clip/thumbnail aceitam ingest via yt-dlp). */
    public static function isYouTubeUrl(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);

        return in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true);
    }

    public static function storeMedia(string $contents, string $ext, string $kind): string
    {
        // AUD-006: nunca produzir extensão/Content-Type executável (svg/html/xml…).
        $ext = strtolower(trim($ext, '. '));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'm4a', 'mp3'];
        if (! in_array($ext, $allowed, true)) {
            $ext = in_array($kind, ['video', 'dub'], true) ? 'mp4' : 'jpg';
        }
        $name = "reachyn/uploads/{$kind}_".(int) (microtime(true) * 1000).".{$ext}";
        try {
            Storage::disk('media')->put($name, $contents, 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable $e) {
            // Fallback same-origin (app.example.com/storage): força download em vez de
            // render inline, p/ que um arquivo malicioso não execute na origem do app.
            $local = 'uploads/'.basename($name);
            Storage::disk('public')->put($local, $contents, [
                'ContentDisposition' => 'attachment; filename="'.basename($name).'"',
            ]);

            return rtrim((string) config('app.url'), '/').'/storage/'.$local;
        }
    }

    /**
     * AUD-024: salva um upload por STREAM (sem file_get_contents do arquivo inteiro),
     * usando o handle do arquivo temporário. Mesma allowlist de extensão do storeMedia.
     */
    public static function storeUploadedFile(\Illuminate\Http\UploadedFile $file, string $ext, string $kind): string
    {
        $ext = strtolower(trim($ext, '. '));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'm4a', 'mp3'];
        if (! in_array($ext, $allowed, true)) {
            $ext = in_array($kind, ['video', 'dub'], true) ? 'mp4' : 'jpg';
        }
        $name = "reachyn/uploads/{$kind}_".(int) (microtime(true) * 1000).".{$ext}";
        try {
            // putFileAs transmite o arquivo em stream para o disco de mídia (Scality/S3).
            Storage::disk('media')->putFileAs(dirname($name), $file, basename($name), 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable $e) {
            // Fallback same-origin: força download (attachment) p/ não renderizar inline.
            $local = 'uploads/'.basename($name);
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk('public')->put($local, $stream, [
                'ContentDisposition' => 'attachment; filename="'.basename($name).'"',
            ]);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return rtrim((string) config('app.url'), '/').'/storage/'.$local;
        }
    }

    private function attach(Draft $d, string $kind, ?string $url, array $extra = []): array
    {
        if (! $url) {
            return $d->media ?? [];
        }
        $item = array_merge(['id' => (string) (int) (microtime(true) * 1000), 'kind' => $kind, 'url' => $url], $extra);
        $media = array_merge($d->media ?? [], [$item]);
        $d->update(['media' => $media]);

        return $media;
    }

    /** POST /api/studio/premium-video { draftId } → vídeo premium. */
    public function premiumVideo(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // Sem rascunho ativo o vídeo premium não tem keyword/contexto pra gerar — erro amigável (não 500).
        $draftId = $r->input('draftId');
        if (! $draftId) {
            return response()->json(['ok' => false, 'error' => 'Selecione ou crie um rascunho antes de gerar o Vídeo Premium.'], 422);
        }
        $d = $this->draft($r, $draftId);
        $weight = $this->usage->weightFor('premium-video');
        // RESERVE-THEN-CONSUME (AUD-002).
        if (! $this->usage->tryConsume($t, 'premium-video', $weight)) {
            return response()->json(['ok' => false, 'error' => 'Vídeo premium não está incluído no seu plano.'], 402);
        }
        $brief = $d->research['summary'] ?? $d->research['brief'] ?? '';
        $style = (string) $r->input('style', 'cinematografico');
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; valor inválido/ausente → default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        $url = $this->engine()->post('/v1/premium-video', ['keyword' => $d->keyword, 'brief' => $brief, 'style' => $style, 'lang' => $lang])->json('url');
        if (! $url) {
            $this->usage->refund($t, 'premium-video', $weight);
            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo premium gerado.', 'media' => $this->attach($d, 'video', $url, ['style' => $style])]);
    }

    /** POST /api/studio/thumbnail { draftId, videoUrl } → thumbnail do vídeo. */
    public function thumbnail(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        // RBK-003: defesa-em-profundidade SSRF — só mídia própria ou YouTube.
        if (! self::isOwnMediaUrl($videoUrl) && ! self::isYouTubeUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo não permitida.'], 422);
        }
        $url = $this->engine()->post('/v1/thumbnail', ['videoUrl' => $videoUrl, 'title' => $d->keyword])->json('url');
        if (! $url) {
            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'media' => $this->attach($d, 'image', $url)]);
    }

    /** POST /api/studio/viral { draftId, photoUrl, template, title, theme } → template viral. */
    public function viral(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        // RBK-003: a foto de origem precisa ser mídia própria (upload → nosso storage).
        if (! self::isOwnMediaUrl((string) $r->input('photoUrl'))) {
            return response()->json(['ok' => false, 'error' => 'A foto precisa ser uma mídia sua (faça o upload primeiro).'], 422);
        }
        $weight = $this->usage->weightFor('image');
        // RESERVE-THEN-CONSUME (AUD-002).
        if (! $this->usage->tryConsume($t, 'image', $weight)) {
            return response()->json(['ok' => false, 'error' => 'Limite de imagens do plano atingido.'], 402);
        }
        $url = $this->engine()->post('/v1/viral', $r->only('photoUrl', 'template', 'title', 'theme'))->json('url');
        if (! $url) {
            $this->usage->refund($t, 'image', $weight);
            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'message' => 'Template viral gerado.', 'media' => $this->attach($d, 'image', $url, ['style' => 'viral'])]);
    }

    /** POST /api/studio/clip { draftId, videoUrl, n } → corta vídeo longo em N shorts (engine). */
    public function clip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        if ($videoUrl === '') {
            return response()->json(['ok' => false, 'error' => 'informe a URL do vídeo longo'], 400);
        }
        // RBK-003: aceita mídia própria OU YouTube (ingest); bloqueia URL arbitrária (SSRF).
        if (! self::isOwnMediaUrl($videoUrl) && ! self::isYouTubeUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo não permitida (use um vídeo seu ou um link do YouTube).'], 422);
        }
        $n = max(1, min(5, (int) $r->input('n', 3)));
        // clip gera N vídeos → reserva N×peso(video) DE UMA VEZ (AUD-002). O que não for
        // gerado é estornado no fim, então o cliente nunca paga por clip que falhou.
        $unit = $this->usage->weightFor('video');
        if (! $this->usage->tryConsume($t, 'video', $n * $unit)) {
            return response()->json(['ok' => false, 'error' => 'Clipar vídeo não está incluído no seu plano (ou saldo insuficiente para a quantidade pedida).'], 402);
        }
        $clips = $this->engine()->post('/v1/clip', ['videoUrl' => $videoUrl, 'n' => $n])->json() ?? [];

        $media = $d->media ?? [];
        $added = 0;
        foreach ($clips as $c) {
            if (($c['ok'] ?? false) && ! empty($c['url'])) {
                $media[] = ['id' => (string) (int) (microtime(true) * 1000).$added, 'kind' => 'video', 'url' => $c['url']];
                $added++;
            }
        }
        if ($added > 0) {
            $d->update(['media' => $media]);
        }
        // Estorna a parte reservada que não virou clip ($n - $added).
        $notGenerated = $n - $added;
        if ($notGenerated > 0) {
            $this->usage->refund($t, 'video', $notGenerated * $unit);
        }

        return response()->json(['ok' => true, 'message' => "{$added} short(s) gerado(s).", 'media' => $media]);
    }

    /** POST /api/studio/upload (multipart: file, draftId?, kind) → salva no storage e acumula. */
    public function upload(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // AUD-006: allowlist de mídia real (sem svg/html/xml).
        // AUD-024 (DoS): limites de tamanho POR TIPO, bem abaixo dos 200MB anteriores —
        // imagem 10MB, áudio 50MB, vídeo 100MB. Reduz superfície de exaustão de memória/disco.
        $kind = (string) $r->input('kind', 'image');
        $rules = match ($kind) {
            'video' => 'required|file|mimes:mp4,mov|max:102400',          // 100MB
            'audio' => 'required|file|mimes:m4a,mp3|max:51200',           // 50MB
            default => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240', // 10MB
        };
        $r->validate(['file' => $rules]);
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'sem arquivo'], 400);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'upload']);

        // Deriva a extensão do MIME real (nunca confia no nome/extensão do cliente).
        $ext = strtolower((string) ($file->guessExtension() ?: ($kind === 'video' ? 'mp4' : 'jpg')));
        // AUD-024: streaming via Storage::putFileAs — não carrega o arquivo inteiro em memória.
        $url = self::storeUploadedFile($file, $ext, $kind);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'media' => $this->attach($d, $kind, $url)]);
    }

    /** POST /api/studio/voice-clone (multipart: file) → clona a voz (provedor de voz) e salva no tenant. Studio. */
    public function voiceClone(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if (! ($t->limits()['premium'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Voz clonada é exclusiva do plano Studio.'], 402);
        }
        // AUD-024 (DoS) + AUD-006: amostra de voz = áudio real, máx 50MB (antes: sem limite).
        $r->validate([
            'file' => 'required|file|mimes:m4a,mp3,wav,ogg|max:51200',
        ]);
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'envie um arquivo de áudio (amostra da voz)'], 400);
        }
        $key = (string) config('services.voice.key');
        $base = rtrim((string) config('services.voice.base'), '/');
        if ($key === '' || $base === '') {
            return response()->json(['ok' => false, 'error' => 'Clonagem de voz indisponível no momento.'], 502);
        }

        $res = Http::withHeaders(['x-api-key' => $key])->timeout(120)
            ->attach('files', file_get_contents($file->getRealPath()), $file->getClientOriginalName() ?: 'sample.mp3')
            ->post($base.'/v1/voices/add', [
                'name' => "reachyn-{$t->id}-".time(),
                'description' => 'Voz do cliente (Reachyn)',
            ]);
        if (! $res->successful() || ! $res->json('voice_id')) {
            return response()->json(['ok' => false, 'error' => 'falha ao clonar a voz'], 502);
        }
        $voiceId = $res->json('voice_id');
        $t->update(['voice_id' => $voiceId]);

        // AUD-005: auditoria de consumo premium (sem expor a chave/áudio).
        Audit::log('studio.voice_clone', ['tenant_id' => $t->id, 'voice_id' => $voiceId]);

        return response()->json(['ok' => true, 'voice_id' => $voiceId]);
    }

    /** POST /api/studio/dub { draftId, videoUrl, lang } → dubla via provedor de voz (assíncrono). Studio. */
    public function dub(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if (! ($t->limits()['premium'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Dublagem é exclusiva do plano Studio.'], 402);
        }
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        $lang = (string) $r->input('lang');
        if ($videoUrl === '' || ! in_array($lang, ['en', 'es', 'fr', 'de', 'it', 'pt'], true)) {
            return response()->json(['ok' => false, 'error' => 'vídeo e idioma válido obrigatórios'], 400);
        }
        // AUD-014: só aceita URL http(s) do nosso domínio de mídia (anti-SSRF de 2ª ordem).
        if (! self::isOwnMediaUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo inválida'], 422);
        }

        DubVideo::dispatch($d->id, $videoUrl, $lang);

        // AUD-005: auditoria de consumo premium (dublagem).
        Audit::log('studio.dub', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'lang' => $lang]);

        return response()->json(['ok' => true, 'status' => 'gerando', 'message' => "Dublando para ".strtoupper($lang)." (~5-15 min). A versão aparece na galeria."]);
    }

    /**
     * GET /api/media/list → galeria de mídia do tenant logado.
     *
     * Isolamento multi-tenant: a query filtra por tenant_id explícito (defesa em
     * profundidade) E o trait BelongsToTenant do Draft já aplica um global scope por
     * tenant. O cliente logado NUNCA vê mídia de outro tenant — o filtro é 100% no
     * banco (tenant_id), pois o path no S3 não carrega o tenant.
     */
    public function mediaList(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $drafts = Draft::where('tenant_id', $t->id) // explícito + global scope do trait
            ->whereNotNull('media')
            ->orderByDesc('updated_at')
            ->get();

        // Achata o array `media` de cada rascunho, anexando origem (draft_id/keyword/data).
        $items = $drafts->flatMap(function (Draft $d) {
            return collect($d->media ?? [])->map(fn (array $m) => [
                'id' => (string) ($m['id'] ?? ''),
                'kind' => (string) ($m['kind'] ?? ''),
                'url' => (string) ($m['url'] ?? ''),
                'style' => $m['style'] ?? null,
                'draft_id' => $d->id,
                'keyword' => $d->keyword,
                'date' => (string) $d->updated_at,
            ]);
        })->values();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * POST /api/media/clean-broken → remove de uma vez todas as mídias MORTAS do tenant.
     * "Morta/quebrada" = URL que não é do nosso storage (link de provedor expirado, storage antigo):
     * !isOwnMediaUrl($url). Não há objeto nosso pra apagar no S3, então só limpamos o array
     * `media` de cada rascunho. Multi-tenant: só drafts do tenant logado (explícito + global scope).
     */
    public function mediaCleanBroken(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $drafts = Draft::where('tenant_id', $t->id) // explícito + global scope do trait (defesa dupla)
            ->whereNotNull('media')
            ->get();

        $removed = 0;
        foreach ($drafts as $d) {
            $media = $d->media ?? [];
            $kept = array_values(array_filter($media, fn ($m) => self::isOwnMediaUrl($m['url'] ?? '')));
            if (count($kept) !== count($media)) {
                $removed += count($media) - count($kept);
                $d->update(['media' => $kept]);
            }
        }

        return response()->json(['ok' => true, 'removed' => $removed]);
    }
}
