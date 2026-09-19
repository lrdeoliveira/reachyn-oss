<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AssembleFilmJob;
use App\Jobs\RenderSceneClipJob;
use App\Jobs\RenderSceneImageJob;
use App\Models\Draft;
use App\Models\GenModel;
use App\Models\Prompt;
use App\Services\UsageService;
use App\Support\GenPayload;
use App\Support\IdentityLock;
use App\Support\Locucao;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Proxy de geração web → console → engine Go.
 * O console é a borda autenticada: valida o tenant, faz o enforcement de quota (402)
 * e credita o uso. Mantém o engine interno (sem auth/tenant), fora do alcance do browser.
 */
class GenerateController extends Controller implements HasMiddleware
{
    // RBK-002: gate de assinatura nas rotas que gastam crédito de IA. EXCEÇÃO freemium:
    // pesquisa/resumo ficam liberados também no trial (gate 'trial-or-subscribed' + cota diária).
    public static function middleware(): array
    {
        return [
            new Middleware('trial-or-subscribed', only: ['research', 'summarize']),
            new Middleware('subscribed', except: ['research', 'summarize']),
        ];
    }

    // Formatos aceitos na imagem síncrona. Inclui 21:9 porque a prancha de poses da aba /sprite
    // pede tira panorâmica; o que não estiver aqui cai no 9:16 (vertical = formato primário).
    private const ASPECTOS_IMAGEM = ['9:16', '1:1', '16:9', '3:4', '4:3', '4:5', '21:9'];

    public function __construct(private UsageService $usage) {}

    // Pesquisa/resumo: liberados no trial, com cota DIÁRIA (anti denial-of-wallet).
    public function research(Request $r): JsonResponse
    {
        return $this->researchGated($r, '/v1/research', $r->only('keyword', 'sources'));
    }

    public function summarize(Request $r): JsonResponse
    {
        return $this->chargedText($r, fn () => $this->researchGated($r, '/v1/summarize', $r->only('keyword', 'sources')));
    }

    public function text(Request $r): JsonResponse
    {
        return $this->chargedText($r, fn () => $this->proxy('/v1/text', $r->only('keyword', 'brief', 'platform')));
    }

    /** Cobra 1 geração de TEXTO (custo default Equilibrado; a API pública não tem seletor) em volta
     *  do proxy — estornada se a resposta não for 2xx. Espelha a cobrança do fluxo no Studio. */
    private function chargedText(Request $r, \Closure $run): JsonResponse
    {
        $t = $r->user()?->tenant;
        $cost = GenModel::resolveSelectable('txt-equilibrado', 'text', $t?->plan)?->cost_credits;
        if ($t && ! $this->usage->tryConsume($t, 'text', 1, $cost)) {
            return response()->json(['error' => 'insufficient_credits', 'message' => 'Créditos insuficientes para geração de texto.'], 402);
        }
        $res = $run();
        if ($t && $res->getStatusCode() >= 400) {
            $this->usage->refund($t, 'text', 1, $cost);
        }

        return $res;
    }

    public function thumbnail(Request $r): JsonResponse
    {
        return $this->proxy('/v1/thumbnail', $r->only('videoUrl', 'title'));
    }

    // NOTA: short/veo NÃO vivem mais aqui — eram proxy síncrono e estão mortos (nenhum caller).
    // A geração pesada de mídia é assíncrona (jobs) via StudioController::media.
    // `image` é a EXCEÇÃO e voltou (2026-08-01): abas Imagem/Sprite/Ficha do personagem pedem uma
    // imagem e ESPERAM a URL na mesma resposta — não têm tela de polling. Ver image() abaixo.

    /**
     * POST /api/generate/image { prompt, aspect?, style?, model?, imageUrl?, imageUrls?, persona?, charIds? }
     * → { ok, url, name?, model? } SÍNCRONO.
     *
     * POR QUE VOLTOU: removida em 2026-07-16 sob a alegação de "nenhum caller", que estava errada —
     * 4 call sites vivos (web/app/(dash)/imagem, sprite/MotorLocal ×2, components/FichaPersonagem)
     * davam await nela e passaram a tomar 404. Esses fluxos são de UMA imagem por clique e não têm
     * polling; devolver draftId quebraria a UI inteira.
     *
     * POR QUE NÃO TRAVA O PHP-FPM (o motivo original da remoção): motor com `capabilities.async`
     * no catálogo (CLI bridge, 110-145s — o Cloudflare corta em ~100s com 524) é RECUSADO aqui com
     * 422 mandando usar a Mídia do Estúdio, que enfileira job. Só motor rápido passa pelo proxy.
     */
    public function image(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'Sem marca ativa.'], 403);
        }

        $data = $r->validate([
            'prompt' => 'required|string|max:4000',
            'aspect' => 'nullable|string|max:10',
            'style' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:60',
            'imageUrl' => 'nullable|string|max:2000',
            'imageUrls' => 'nullable|array|max:3',
            'imageUrls.*' => 'string|max:2000',
            'persona' => 'nullable|string|max:4000',
            'charIds' => 'nullable|array|max:5',
            'charIds.*' => 'integer',
        ]);

        // Anti-SSRF: referência só do NOSSO storage (mesma trava do StudioController::media).
        // `ownMediaUrl` ainda normaliza o host atual do bucket; URL de fora simplesmente cai fora.
        $refs = [];
        foreach (array_merge((array) ($data['imageUrls'] ?? []), [$data['imageUrl'] ?? null]) as $u) {
            if (is_string($u) && $u !== '' && ($n = StudioController::ownMediaUrl($u)) && ! in_array($n, $refs, true)) {
                $refs[] = $n;
            }
        }

        $gm = GenModel::resolveSelectable($data['model'] ?? null, 'image', $t->plan)
            ?? self::maisBaratoComRefs('image', $refs !== []);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de imagem disponível.'], 422);
        }

        // ⛔ GUARDA: motor lento não pode virar proxy síncrono (era o bug de 600s de PHP-FPM).
        if ($gm->capabilities['async'] ?? false) {
            return response()->json([
                'ok' => false,
                'error' => 'Este motor leva ~2 min e não roda nesta tela. Gere pela Mídia do Estúdio, que avisa quando ficar pronta.',
            ], 422);
        }

        $refs = array_slice($refs, 0, $gm->refsMax());

        $weight = $this->usage->weightFor('image');
        // RESERVE-THEN-CONSUME: reserva ANTES de chamar o engine e estorna se não vier URL.
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        $estilo = (string) ($data['style'] ?? 'realista');
        // IDENTITY LOCK resolvido AQUI: o engine não conhece `charIds` — quem lê o lock atual de
        // cada personagem do tenant é o console (mesmo caminho de roteiroImagem/media).
        $prompt = IdentityLock::aplicar(trim($data['prompt']), $t->id, (array) ($data['charIds'] ?? []));

        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => in_array($data['aspect'] ?? '', self::ASPECTOS_IMAGEM, true) ? $data['aspect'] : '9:16',
            'style' => $estilo,
            'persona' => $this->resolvePersona($r, 'image', $t->id, $estilo),
        ], GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null)));
        if ($refs !== []) {
            $payload['imageUrl'] = $refs[0];
            $payload['imageUrls'] = $refs;
            $payload['anchorIdentity'] = true;
        }

        try {
            $url = $this->engine()->post('/v1/image', $payload)->json('url');
        } catch (\Throwable $e) {
            $this->usage->refund($t, 'image', $weight, $gm->cost_credits);
            Log::warning('[generate/image] engine falhou', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Não foi possível gerar a imagem.'], 502);
        }
        if (! $url) {
            $this->usage->refund($t, 'image', $weight, $gm->cost_credits);

            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'url' => $url, 'name' => $gm->display_name, 'model' => $gm->slug]);
    }

    /**
     * POST /api/generate/video { prompt, imageUrl?, imageUrls?, persona?, personaId?, aspect?,
     * duration?, model?, quality?, style?, narration?, voiceId?, lang?, subtitles?, subtitle*?,
     * music?, charIds? } → { ok, url, name?, model? } SÍNCRONO.
     *
     * POR QUE VOLTOU (2026-08-01): removida em 2026-07-16 sob a alegação de "nenhum caller", que
     * estava errada — 3 call sites vivos davam `await` nela e tomavam 404 em prod:
     *   web/app/(dash)/video/page.tsx (aba Vídeo) · roteiro/page.tsx (aba Montagem) ·
     *   sprite/MotorLocal.tsx (aba Sprites). Todos esperam a URL do clipe NA MESMA resposta —
     * não têm tela de polling, devolver draftId quebraria as três.
     *
     * POR QUE NÃO TRAVA O PHP-FPM (o motivo original da remoção): mesma trinca de guardas do
     * image() — modelo `capabilities.async` é recusado com 422; modelo premium (Veo) também, por
     * rotear no engine pra /v1/veo, que é MUITO mais lento; e o proxy tem timeout EXPLÍCITO de
     * 180s (ver $this->engine()->timeout(...) abaixo), não os 600s do default. Só motor de clipe
     * rápido (1-2 min) passa por aqui; o resto vai pela Mídia do Estúdio, que enfileira job.
     *
     * ⚠️ CAMPOS DO FRONT QUE O ENGINE **NÃO LÊ** em /v1/video (struct de `func (s *Server) video`).
     * NÃO transforme nenhum destes em pass-through — o engine ignoraria e viraria campo decorativo
     * (bug recorrente neste projeto):
     *   · `smooth`      — interpolação 24→48fps NÃO existe em /v1/video (só no worker do roteiro,
     *                     RenderSceneClipJob). Aqui é síncrono e não há etapa de pós.
     *   · `keyframes`   — primeiro+último quadro exige o array `imageUrls`, que /v1/video não tem.
     *   · `separateParts` / `narrationText` — devolver clipe cru + áudio à parte é contrato do
     *                     StudioController (jobs). Por isso a resposta daqui nunca traz
     *                     `audioUrl`/`parts` — o front trata os dois como opcionais.
     *   · `imageUrls`   — o engine aceita só `imageUrl` SINGULAR (ver struct). Usamos a PRIMEIRA
     *                     âncora; as demais são registradas em log (não somem em silêncio).
     *   · `charIds`     — resolvido AQUI (IdentityLock), o engine não conhece a biblioteca.
     */
    public function video(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'Sem marca ativa.'], 403);
        }

        $data = $r->validate([
            'prompt' => 'required|string|max:4000',
            'imageUrl' => 'nullable|string|max:2000',
            'imageUrls' => 'nullable|array|max:5',
            'imageUrls.*' => 'string|max:2000',
            'aspect' => 'nullable|string|max:10',
            'duration' => 'nullable|string|max:4',
            'model' => 'nullable|string|max:60',
            'quality' => 'nullable|string|max:40',
            'style' => 'nullable|string|max:40',
            'persona' => 'nullable|string|max:4000',
            'narration' => 'nullable|boolean',
            'voiceId' => 'nullable|string|max:80',
            'lang' => 'nullable|string|max:10',
            'subtitles' => 'nullable|boolean',
            'music' => 'nullable|boolean',
            'charIds' => 'nullable|array|max:5',
            'charIds.*' => 'integer',
        ]);

        // Anti-SSRF: âncora só do NOSSO storage (mesma trava do image()/StudioController::media).
        $refs = [];
        foreach (array_merge([$data['imageUrl'] ?? null], (array) ($data['imageUrls'] ?? [])) as $u) {
            if (is_string($u) && $u !== '' && ($n = StudioController::ownMediaUrl($u)) && ! in_array($n, $refs, true)) {
                $refs[] = $n;
            }
        }

        $vm = GenModel::resolveSelectable($data['model'] ?? null, 'video', $t->plan)
            ?? self::maisBaratoComRefs('video', $refs !== []);
        if (! $vm) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de vídeo disponível.'], 422);
        }

        // ⛔ GUARDA 1: motor lento não pode virar proxy síncrono (era o bug de 600s de PHP-FPM).
        if ($vm->capabilities['async'] ?? false) {
            return response()->json([
                'ok' => false,
                'error' => 'Este motor leva vários minutos e não roda nesta tela. Gere pela Mídia do Estúdio, que avisa quando ficar pronto.',
            ], 422);
        }

        // ⛔ GUARDA 2: premium (Veo) roteia por /v1/veo no engine e é MUITO mais lento que o clipe
        // KIE — segurar a conexão aqui garantiria estouro de timeout (e 524 do Cloudflare).
        if ($vm->capabilities['veo'] ?? false) {
            return response()->json([
                'ok' => false,
                'error' => 'O motor premium é lento demais para esta tela. Gere pela Mídia do Estúdio, que avisa quando ficar pronto.',
            ], 422);
        }

        $refsAceitas = array_slice($refs, 0, max(1, $vm->refsMax()));
        // O engine só tem `imageUrl` (singular): a 1ª âncora vira a base i2v e as outras NÃO viajam.
        if (count($refsAceitas) > 1) {
            Log::info('[generate/video] engine aceita 1 âncora; extras descartadas', [
                'model' => $vm->slug, 'recebidas' => count($refs), 'usadas' => 1,
            ]);
        }

        $weight = $this->usage->weightFor('video');
        // RESERVE-THEN-CONSUME: reserva ANTES de chamar o engine e estorna se não vier URL.
        if (! $this->usage->tryConsume($t, 'video', $weight, $vm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de vídeo.'], 402);
        }

        $estilo = (string) ($data['style'] ?? '');
        // IDENTITY LOCK resolvido AQUI (o engine não conhece `charIds`) — igual ao image().
        $prompt = IdentityLock::aplicar(trim($data['prompt']), $t->id, (array) ($data['charIds'] ?? []));

        // Duração: só a que o CATÁLOGO do modelo oferece (duracoes()); fora disso, a primeira.
        $duracoes = array_map('strval', $vm->duracoes());
        $duration = in_array((string) ($data['duration'] ?? ''), $duracoes, true)
            ? (string) $data['duration'] : ($duracoes[0] ?? '5');

        $subtitles = filter_var($data['subtitles'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => in_array($data['aspect'] ?? '', ['9:16', '1:1', '16:9'], true) ? $data['aspect'] : '9:16',
            'duration' => $duration,
            'scenes' => 1, // caixa única = UM clipe; roteiro/filme é que segmenta
            'style' => $estilo,
            'persona' => $this->resolvePersona($r, 'video', $t->id, $estilo),
            'narration' => filter_var($data['narration'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'voiceId' => (string) ($data['voiceId'] ?? ''),
            'lang' => in_array($data['lang'] ?? '', ['pt-BR', 'en-US'], true) ? $data['lang'] : 'pt-BR',
            'subtitles' => $subtitles,
            'music' => filter_var($data['music'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'gen_lines' => GenPayload::videoGenLine($vm, GenPayload::quality($vm, $data['quality'] ?? null)),
        ], $subtitles ? StudioController::subtitleStyleFrom($r) : []);
        if ($refsAceitas !== []) {
            $payload['imageUrl'] = $refsAceitas[0];
        }

        try {
            // TIMEOUT EXPLÍCITO (o default do engine() é 600s — foi ele que travou PHP-FPM e
            // motivou a remoção original). 180s = teto do clipe rápido (1-2 min) + folga de rede;
            // o que não couber aí é motor errado pra esta tela e cai nas guardas acima.
            $url = $this->engine()->timeout(180)->post('/v1/video', $payload)->json('url');
        } catch (\Throwable $e) {
            $this->usage->refund($t, 'video', $weight, $vm->cost_credits);
            Log::warning('[generate/video] engine falhou', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Não foi possível gerar o vídeo.'], 502);
        }
        if (! $url) {
            $this->usage->refund($t, 'video', $weight, $vm->cost_credits);

            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'url' => $url, 'name' => $vm->display_name, 'model' => $vm->slug]);
    }

    /** Proxy puro (sem metering). */
    private function proxy(string $path, array $payload): JsonResponse
    {
        $res = $this->engine()->post($path, $payload);

        return $this->relay($res);
    }

    /**
     * Proxy de pesquisa/resumo com cota DIÁRIA no trial (freemium). Assinantes/exempt passam
     * direto; conta em trial debita 1 da cota diária de pesquisa e estorna se a chamada falhar.
     */
    private function researchGated(Request $r, string $path, array $payload): JsonResponse
    {
        $tenant = $r->user()?->tenant;
        $trial = $tenant && $tenant->inTrial() && ! $tenant->hasActivePlan();
        if ($trial && ! $this->usage->tryConsumeDaily($tenant, 'research', UsageService::RESEARCH_TRIAL_DAILY)) {
            return response()->json([
                'error' => 'research_daily_limit',
                'message' => 'Limite de pesquisas do teste atingido hoje ('.UsageService::RESEARCH_TRIAL_DAILY.'/dia). Assine um plano para liberar.',
            ], 402);
        }

        $res = $this->engine()->post($path, $payload);
        if ($trial && ! $res->successful()) {
            $this->usage->refundDaily($tenant, 'research');
        }

        return $this->relay($res);
    }

    /**
     * AUD-013 (white-label): repassa a resposta do engine ao browser, mas em falha
     * (não-2xx) devolve mensagem GENÉRICA — nunca o corpo cru do engine, que pode
     * citar provedores de IA. O detalhe real fica no log.
     */
    private function relay(Response $res): JsonResponse
    {
        if ($res->successful()) {
            return response()->json($res->json() ?? [], $res->status());
        }

        Log::warning('[engine] geração falhou', [
            'status' => $res->status(),
            'body' => mb_substr((string) $res->body(), 0, 2000),
        ]);

        // 4xx (ex.: input inválido) preserva o status; 5xx vira 502 (gateway).
        $status = $res->status() >= 400 && $res->status() < 500 ? $res->status() : 502;

        return response()->json([
            'ok' => false,
            'error' => 'A IA está indisponível no momento, tente novamente.',
        ], $status);
    }

    /**
     * GET /api/comfy/health → status do ComfyUI local (só informativo, sem custo — a UI usa
     * pra mostrar "não configurado"/"fora do ar" em vez de deixar o botão falhar sem explicação).
     */
    public function comfyHealth(): JsonResponse
    {
        return $this->relay($this->engine()->get('/v1/comfy/health'));
    }

    /**
     * POST /api/roteiro/render { name, model?, smooth?, draftId?, cenas: [...] }
     * → põe as cenas SEM clipe na fila do worker e devolve {draftId, fila, prontas}.
     *
     * COBRANÇA: 1 crédito de vídeo por cena enfileirada (mesmo custo do modelo escolhido,
     * igual a qualquer outra geração de vídeo do produto) — reservado por cena ANTES do
     * dispatch e estornado pelo job se a geração falhar. Cena que esbarra no limite do plano
     * no meio do lote é pulada (entra em `avisos`), não aborta o resto do lote.
     */
    public function roteiroRender(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'sem workspace'], 422);
        }
        $cenas = (array) $r->input('cenas', []);
        if ($cenas === []) {
            return response()->json(['ok' => false, 'error' => 'Nenhuma cena para renderizar.'], 422);
        }
        // Teto alinhado ao filme de 5 min (maxVideoSeconds/5s no engine).
        if (count($cenas) > 60) {
            return response()->json(['ok' => false, 'error' => 'Máximo de 60 cenas por filme.'], 422);
        }

        // Âncora em qualquer cena muda o fallback: o lote do roteiro ancora identidade (i2v).
        $temAncora = collect($cenas)->contains(fn ($c) => ! empty($c['imageUrls']) || ! empty($c['imageUrl']));
        $vm = GenModel::query()->kind('video')->where('slug', (string) $r->input('model'))
            ->where(fn ($w) => $w->where('is_active', true)->orWhereNotNull('capabilities->kie'))->first()
            ?? self::maisBaratoComRefs('video', $temAncora);
        if (! $vm) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de vídeo disponível.'], 422);
        }
        $smooth = filter_var($r->input('smooth'), FILTER_VALIDATE_BOOLEAN);
        $weight = $this->usage->weightFor('video');
        // Memoiza por estilo — o lote pode ter até 60 cenas e a persona só muda quando o estilo
        // muda (personaId/texto explícito do request é o mesmo pra todo o lote).
        $personaPorEstilo = [];
        $personaDoLote = fn (string $estilo) => $personaPorEstilo[$estilo]
            ??= $this->resolvePersona($r, 'video', $t->id, $estilo);

        // Um Draft por roteiro: reenviar o mesmo id retoma de onde parou.
        $draft = $r->filled('draftId')
            ? Draft::where('tenant_id', $t->id)->find((int) $r->input('draftId'))
            : null;
        $draft ??= Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr(trim((string) $r->input('name', 'roteiro')), 0, 80) ?: 'roteiro']);

        $film = is_array($draft->film) ? $draft->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $fila = 0;
        $prontas = 0;
        $avisos = [];

        foreach ($cenas as $i => $c) {
            // IDENTITY LOCK: a Escaleta manda QUEM está na cena (character_ids); o lock atual
            // desses personagens entra no prompt.
            $prompt = IdentityLock::aplicar(
                trim((string) ($c['prompt'] ?? '')),
                $t->id,
                (array) ($c['charIds'] ?? []),
            );
            $refs = [];
            foreach ((array) ($c['imageUrls'] ?? []) as $u) {
                if ($n = StudioController::ownMediaUrl((string) $u)) {
                    $refs[] = $n;
                }
            }
            $refs = array_slice($refs, 0, $vm->refsMax());
            $jaTem = trim((string) ($beats[$i]['clip_url'] ?? '')) !== '';
            if (! $jaTem && ($u = StudioController::ownMediaUrl((string) ($c['clipUrl'] ?? '')))) {
                $beats[$i]['clip_url'] = $u;
                $jaTem = true;
            }
            $beats[$i] = array_merge($beats[$i] ?? [], [
                'title' => mb_substr(trim((string) ($c['titulo'] ?? '')), 0, 120),
                'frame_prompt' => $prompt,
                'voiceover' => mb_substr(trim((string) ($c['narracao'] ?? '')), 0, 1200),
            ]);
            if ($jaTem) {
                $prontas++;

                continue;
            }
            if ($prompt === '' && $refs === []) {
                continue; // cena vazia: nada a gerar
            }

            $estiloCena = (string) ($c['estilo'] ?? '');
            $payload = array_filter([
                'prompt' => $prompt,
                'aspect' => in_array($c['aspect'] ?? '', ['9:16', '1:1', '16:9'], true) ? $c['aspect'] : '9:16',
                'duration' => in_array((string) ($c['duration'] ?? ''), ['5', '10'], true) ? (string) $c['duration'] : '5',
                'scenes' => 1,
                'style' => $estiloCena,
                'persona' => $personaDoLote($estiloCena),
                'smooth' => $smooth,
                'gen_lines' => GenPayload::videoGenLine($vm, GenPayload::quality($vm, null)),
            ], fn ($v) => $v !== '' && $v !== null);
            if ($refs !== []) {
                $payload['imageUrl'] = $refs[0];
                $payload['imageUrls'] = $refs;
            }

            if ($av = Locucao::aviso((string) ($c['narracao'] ?? ''), (float) ($payload['duration'] ?? 5), $vm->duracoes())) {
                $avisos[] = ['cena' => $i + 1, 'titulo' => $beats[$i]['title'] ?? '', 'aviso' => $av];
            }

            // Reserva a cota DESTA cena antes de enfileirar — cena que não cabe no plano é
            // pulada (avisada), o resto do lote segue.
            if (! $this->usage->tryConsume($t, 'video', $weight, $vm->cost_credits)) {
                $avisos[] = ['cena' => $i + 1, 'titulo' => $beats[$i]['title'] ?? '', 'aviso' => 'Limite do plano atingido — cena não enfileirada.'];

                continue;
            }

            RenderSceneClipJob::dispatch($draft->id, $i, $payload, $vm->slug, $t->id, $vm->cost_credits);
            $fila++;
        }

        $film['beats'] = $beats;
        $draft->update(['film' => $film]);

        return response()->json(array_filter([
            'ok' => true, 'draftId' => $draft->id, 'fila' => $fila, 'prontas' => $prontas,
            'avisos' => $avisos,
        ], fn ($v) => $v !== []));
    }

    /** Estilos onde a disciplina de fotografia (câmera/lente/luz física, zero buzzword) do
     *  BUDO faz sentido — "3d"/"anime"/"pintura"/"produto" são ESTILIZADOS de propósito;
     *  aplicar prompt fotográfico ali empurraria de volta pro fotorrealismo (mesma regra de
     *  roteamento do imageprompts.md/stylized.md em /Volumes/M5SSD/Assets/Human Images). */
    private const ESTILOS_FOTOGRAFICOS = ['realista', 'cinematico', ''];

    /** Persona (estilo) a aplicar na geração — mesmo contrato de StudioController::resolvePersona
     *  (personaId salvo > texto digitado na hora), com UM acréscimo: se o cliente não escolheu
     *  NENHUMA persona e o estilo pede fotografia física, cai na persona BUDO semeada (ver
     *  migration seed_diretor_de_fotografia_budo) — é o que fecha o Roteiro NÃO passar mais
     *  batido pelo motor sem nenhuma direção de fotografia (pedido do Luciano, 2026-08-01).
     *  Cliente que escolher outra persona (ou "nenhuma" explícito via personaId=0) sempre vence. */
    private function resolvePersona(Request $r, string $kind, int $tenantId, string $estilo): string
    {
        if ($id = $r->input('personaId')) {
            return (string) (Prompt::where('tenant_id', $tenantId)->personas($kind)->find($id)?->content ?? '');
        }
        if ($texto = trim((string) $r->input('persona', ''))) {
            return mb_substr($texto, 0, 4000);
        }
        if (! in_array($estilo, self::ESTILOS_FOTOGRAFICOS, true)) {
            return ''; // estilizado (3d/anime/pintura/produto/…) — BUDO fotográfico não se aplica
        }

        return (string) (Prompt::where('tenant_id', $tenantId)->personas($kind)
            ->where('title', $kind === 'video' ? '🎬 Diretor de Fotografia (BUDO — Vídeo)' : '🎬 Diretor de Fotografia (BUDO)')
            ->value('content') ?? '');
    }

    /**
     * Modelo ATIVO mais barato — o fallback de quem NÃO escolheu modelo. Com âncoras na mão, só
     * modelo que RECEBE referência: o fallback cego por custo caía num t2i puro e a âncora sumia
     * em silêncio.
     *
     * ⚠️ 2026-08-04: chamava-se `kieMaisBarato` e filtrava `provider='kie'` + o `refs_field`
     * DENTRO do spec do agregador. Depois da saída do KIE isso passou a devolver null em todos
     * os 4 pontos de chamada — quem não escolhia modelo ficava sem nenhum. O filtro de âncora
     * agora usa refsMax(), que entende os dois formatos de catálogo (o `refs` bool do Higgsfield
     * e o `refs_field` legado), e a seleção é por CAPACIDADE, não por marca de provedor.
     */
    private static function maisBaratoComRefs(string $kind, bool $comAncora): ?GenModel
    {
        $q = GenModel::query()->active()->kind($kind)->orderBy('cost_credits')->orderBy('id');
        if (! $comAncora) {
            return $q->first();
        }

        // refsMax() é método PHP (lê capabilities em dois formatos), então o filtro de âncora
        // roda na coleção — o catálogo tem dezenas de linhas, não milhares.
        return $q->get()->first(fn (GenModel $m) => $m->refsMax() > 0);
    }

    /**
     * POST /api/roteiro/imagem { draftId?, index, prompt, aspect?, style?, model?, imageUrls?, imageRoles?, charIds?, name? }
     * → põe o QUADRO da cena na fila do worker e devolve {draftId}.
     *
     * COBRANÇA: 1 crédito de imagem (custo do modelo escolhido), reservado antes do dispatch e
     * estornado pelo job se a geração falhar — mesmo padrão de ShotController::quadro.
     */
    public function roteiroImagem(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'sem workspace'], 422);
        }
        $data = $r->validate([
            'draftId' => 'nullable|integer',
            'index' => 'required|integer|min:0|max:59',
            'prompt' => 'required|string|max:4000',
            'aspect' => 'nullable|string|max:10',
            'style' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:60',
            'imageUrls' => 'nullable|array|max:5',
            // PAPÉIS das refs (mesma ordem/tamanho de imageUrls). Allowlist fechada: o valor vira
            // INSTRUÇÃO dentro do prompt — texto livre aqui seria injeção de prompt.
            'imageRoles' => 'nullable|array|max:5',
            'imageRoles.*' => ['string', Rule::in(StudioController::REF_ROLES)],
            'charIds' => 'nullable|array|max:5',
            'name' => 'nullable|string|max:80',
            'seed' => 'nullable|integer|min:1',
        ]);

        // PAPEL SEGUE A URL: os papéis chegam posicionais (papel[i] ↔ imageUrls[i]), mas a lista de
        // refs é FILTRADA (SSRF: ownMediaUrl descarta o que não é mídia nossa) e depois CORTADA em
        // refsMax(). Se os papéis não passarem pelo mesmo filtro/corte, eles deslizam e a referência
        // errada ganha o papel errado — o balão de estilo viraria identidade. Por isso as duas listas
        // andam juntas, indexadas pela POSIÇÃO ORIGINAL do request.
        $papeisIn = array_values((array) ($data['imageRoles'] ?? []));
        $refs = [];
        $papeis = [];
        foreach (array_values((array) ($data['imageUrls'] ?? [])) as $i => $u) {
            if ($n = StudioController::ownMediaUrl((string) $u)) {
                $refs[] = $n;
                $papeis[] = isset($papeisIn[$i]) ? (string) $papeisIn[$i] : 'identidade';
            }
        }
        $temPapeis = $papeisIn !== [];

        $gm = GenModel::query()->kind('image')->where('slug', (string) ($data['model'] ?? ''))
            ->where(fn ($w) => $w->where('is_active', true)->orWhereNotNull('capabilities->kie'))->first()
            ?? self::maisBaratoComRefs('image', $refs !== []);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de imagem disponível.'], 422);
        }

        $refs = array_slice($refs, 0, $gm->refsMax());
        $papeis = array_slice($papeis, 0, $gm->refsMax()); // mesmo corte, mesma ordem

        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        $estilo = (string) ($data['style'] ?? 'realista');
        $prompt = IdentityLock::aplicar(trim($data['prompt']), $t->id, (array) ($data['charIds'] ?? []));
        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => in_array($data['aspect'] ?? '', ['9:16', '1:1', '16:9', '3:4', '4:3'], true) ? $data['aspect'] : '9:16',
            'style' => $estilo,
            'persona' => $this->resolvePersona($r, 'image', $t->id, $estilo),
        ], GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null)));
        if ($refs !== []) {
            $payload['imageUrl'] = $refs[0];
            $payload['imageUrls'] = $refs;
            $payload['anchorIdentity'] = true;
            // Sem papéis declarados, nada muda (1 ref = identidade, como sempre foi). Com papéis,
            // apensa a MESMA instrução da Mídia (StudioController::refsRoleText) — mesmo conceito
            // nos dois caminhos, texto num lugar só.
            if ($temPapeis) {
                $payload['prompt'] = ((string) $payload['prompt']).StudioController::refsRoleText($refs, $papeis);
            }
        }
        if (! empty($data['seed'])) {
            $payload['seed'] = (int) $data['seed'];
        }

        $draft = ! empty($data['draftId'])
            ? Draft::where('tenant_id', $t->id)->find((int) $data['draftId'])
            : null;
        $draft ??= Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr(trim((string) ($data['name'] ?? 'roteiro')), 0, 80) ?: 'roteiro']);

        RenderSceneImageJob::dispatch($draft->id, (int) $data['index'], $payload, $gm->slug, $t->id, $gm->cost_credits, $weight);

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'queued' => true]);
    }

    /** Movimentos que a CÂMERA PROGRAMADA reproduz de verdade (zoompan sobre imagem parada).
     *  Espelha CAM_MOVES do ffmpeg-service (media/ffmpeg-service/server.py) e a lista gêmea de
     *  FilmController::CAM_MOVES — duplicada de propósito: a aba Filme e a Montagem são fluxos
     *  independentes, e nenhuma delas deve poder mandar uma key que o ffmpeg não conhece.
     *  Allowlist é defesa em camada: o valor já vem de um <select>, mas nada além disto passa. */
    private const CAM_MOVES = ['static', 'push_in', 'pull_out', 'pan_left', 'pan_right', 'tilt_up', 'tilt_down'];

    /**
     * POST /api/roteiro/camclip { draftId, index, imageUrl, move, duration?, aspect?, name? }
     * → 🎞️ CÂMERA PROGRAMADA da Montagem (sem IA de vídeo, SEM CRÉDITO).
     *
     * É o que torna web-doc barato: cena contemplativa (mapa, foto de arquivo, hero shot) não tem
     * nada se mexendo — pagar i2v ali queima crédito e ainda arrisca drift de identidade. Aqui o
     * clipe sai do QUADRO JÁ APROVADO com o movimento 2D por cima, no ffmpeg: custo zero (só CPU),
     * instantâneo e sem drift, porque nenhum modelo redesenha nada.
     *
     * ⚠️ `imageUrl` vem EXPLÍCITO do cliente (o front manda; o servidor não adivinha): a Montagem
     * mantém o quadro da cena no estado do canvas, que pode estar à frente do que já foi gravado
     * no rascunho. Por isso a URL passa obrigatoriamente pelo filtro anti-SSRF antes de qualquer
     * coisa — só mídia do NOSSO storage.
     *
     * PERSISTÊNCIA: `film.beats[index]` do mesmo Draft — exatamente onde roteiroRender grava
     * `clip_url` e roteiroStatus lê. A Montagem e a aba Filme compartilham esta estrutura, então
     * o clipe aparece na tela pelo polling que já existe.
     */
    public function roteiroCamclip(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'sem workspace'], 422);
        }
        $data = $r->validate([
            'draftId' => 'nullable|integer',
            'index' => 'required|integer|min:0|max:59',
            'imageUrl' => 'required|string|max:2000',
            'move' => 'required|string|max:40',
            'duration' => 'nullable',
            'aspect' => 'nullable|string|max:10',
            'name' => 'nullable|string|max:80',
        ]);

        $move = trim((string) $data['move']);
        if (! in_array($move, self::CAM_MOVES, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'Este movimento precisa da IA de vídeo (a câmera programada só faz zoom, giro e inclinação).',
                'supported' => self::CAM_MOVES,
            ], 422);
        }

        // Anti-SSRF: a URL é entrada do cliente e vira requisição de um serviço interno.
        $src = StudioController::ownMediaUrl((string) $data['imageUrl']);
        if (! $src) {
            return response()->json(['ok' => false, 'error' => 'Gere o quadro desta cena antes.'], 422);
        }

        // Custo ZERO: tryConsume com 0 mantém o feature-gate do plano e o analytics, mas não debita
        // (UsageService só chama a carteira quando cost > 0) — nada de linha de 0 crédito no extrato.
        if (! $this->usage->tryConsume($t, 'video', 1, 0)) {
            return response()->json(['ok' => false, 'error' => 'Vídeo não está incluso no seu plano.'], 402);
        }

        $dur = in_array((string) ($data['duration'] ?? '5'), ['5', '10'], true) ? (int) $data['duration'] : 5;
        $aspect = in_array($data['aspect'] ?? '', ['9:16', '1:1', '16:9'], true) ? (string) $data['aspect'] : '9:16';

        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->acceptJson()->timeout(90) // ~5s de clipe sai em segundos; 90 é folga, não expectativa
                ->post('/camclip', [
                    'image_url' => $src,
                    'move' => $move,
                    'duration' => $dur,
                    'aspect' => $aspect,
                ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'A câmera programada não respondeu. Tente de novo.'], 502);
        }
        $url = $res->successful() ? trim((string) $res->json('url')) : '';
        if ($url === '') {
            return response()->json(['ok' => false, 'error' => 'A câmera programada falhou nesta cena.'], 502);
        }

        // Um Draft por roteiro, igual roteiroImagem/roteiroRender: sem draftId, cria.
        $draft = ! empty($data['draftId'])
            ? Draft::where('tenant_id', $t->id)->find((int) $data['draftId'])
            : null;
        $draft ??= Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr(trim((string) ($data['name'] ?? 'roteiro')), 0, 80) ?: 'roteiro']);

        $i = (int) $data['index'];
        DB::transaction(function () use ($draft, $i, $url, $move, $src) {
            $locked = Draft::lockForUpdate()->find($draft->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            $beats[$i] = array_merge($beats[$i] ?? [], [
                'clip_url' => $url,
                'clip_engine' => 'camera',  // marca a origem: a montagem e a UI diferenciam
                'clip_move' => $move,
                'frame_url' => $beats[$i]['frame_url'] ?? $src,
            ]);
            $film['beats'] = $beats;
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'index' => $i, 'url' => $url, 'move' => $move]);
    }

    /** GET /api/roteiro/{draft} → estado das cenas (clipe pronto por índice), pro front acompanhar. */
    public function roteiroStatus(Request $r, int $draft): JsonResponse
    {
        $t = $r->user()?->tenant;
        $d = $t ? Draft::where('tenant_id', $t->id)->find($draft) : null;
        if (! $d) {
            return response()->json(['ok' => false, 'error' => 'roteiro não encontrado'], 404);
        }
        $beats = array_values((array) ((is_array($d->film) ? $d->film : [])['beats'] ?? []));
        $film = is_array($d->film) ? $d->film : [];

        return response()->json([
            'ok' => true,
            'draftId' => $d->id,
            'cenas' => array_map(fn ($b) => ['clipUrl' => $b['clip_url'] ?? null, 'imageUrl' => $b['frame_url'] ?? null], $beats),
            'filmUrl' => $film['final_url'] ?? null,
            'montando' => (bool) ($film['montando'] ?? false),
        ]);
    }

    /**
     * POST /api/generate/filmplan { brief, beats?, style?, persona?, clipDuration?, lang? }
     * → plano do filme: N cenas {title, frame_prompt, move_prompt, voiceover} + título + direção
     * musical. Texto puro — cobra 1 geração de texto (mesmo helper que `text()` já usa); quem
     * gasta crédito de mídia é a produção depois, cena a cena.
     */
    public function filmplan(Request $r): JsonResponse
    {
        $brief = trim((string) $r->input('brief'));
        if ($brief === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva a ideia do filme.'], 422);
        }
        $clipDur = in_array((string) $r->input('clipDuration'), ['5', '10'], true) ? (string) $r->input('clipDuration') : '5';

        return $this->chargedText($r, fn () => $this->relay($this->engine()->timeout(300)->post('/v1/filmplan', [
            'brief' => mb_substr($brief, 0, 4000),
            'beats' => (int) $r->input('beats', 8),
            'style' => (string) $r->input('style', ''),
            'persona' => mb_substr(trim((string) $r->input('persona', '')), 0, 1500),
            'clipDuration' => $clipDur,
            'lang' => (string) $r->input('lang', 'pt-BR'),
        ])));
    }

    /**
     * POST /api/generate/assemble { clipUrls[], aspect?, transition?, smooth?, music?,
     * musicPrompt?, narration?, script?, scripts?, voiceId?, subtitles? } → monta os clipes na
     * ordem num filme só.
     *
     * COBRANÇA: mesmo bucket que `FilmController::assemble` já usa pra montagem — 1 'short' +
     * 1 'effect' se a montagem pedir narração (síntese ElevenLabs tem custo real; legenda
     * queimada em cima do áudio já sintetizado não soma).
     */
    public function assemble(Request $r): JsonResponse
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'sem workspace'], 422);
        }

        $clips = array_values(array_filter(array_map(
            fn ($u) => trim((string) $u),
            (array) $r->input('clipUrls', []),
        )));
        foreach ($clips as $i => $u) {
            if (! ($n = StudioController::ownMediaUrl($u))) {
                return response()->json(['ok' => false, 'error' => 'Clipe inválido (só mídia do seu acervo).'], 422);
            }
            $clips[$i] = $n;
        }
        if (count($clips) < 2) {
            return response()->json(['ok' => false, 'error' => 'Escolha ao menos duas cenas com clipe pronto para montar.'], 422);
        }
        if (count($clips) > 60) {
            return response()->json(['ok' => false, 'error' => 'Máximo de 60 cenas por montagem (o filme vai até 5 minutos).'], 422);
        }

        $aspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9'], true) ? (string) $r->input('aspect') : null;
        $scripts = array_map(
            fn ($s) => mb_substr(trim((string) $s), 0, 1200),
            array_slice(array_values((array) $r->input('scripts', [])), 0, count($clips)),
        );
        $porCena = $scripts !== [] && trim(implode('', $scripts)) !== '';
        $script = trim((string) $r->input('script', ''));
        $narration = filter_var($r->input('narration'), FILTER_VALIDATE_BOOLEAN) && ($script !== '' || $porCena);
        $body = [
            'clipUrls' => $clips,
            'aspect' => $aspect,
            'transitionDefault' => (string) $r->input('transition', ''),
            'transitionDur' => (float) $r->input('transitionDur', 0),
            'colorMatch' => filter_var($r->input('colorMatch', true), FILTER_VALIDATE_BOOLEAN),
            'smooth' => filter_var($r->input('smooth'), FILTER_VALIDATE_BOOLEAN),
            'grade' => (string) $r->input('grade', ''),
            'music' => filter_var($r->input('music'), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => mb_substr(trim((string) $r->input('musicPrompt', '')), 0, 300),
            'narration' => $narration,
            'script' => $script,
            'scripts' => $scripts,
            'voiceId' => (string) $r->input('voiceId', ''),
            'subtitles' => $narration && filter_var($r->input('subtitles'), FILTER_VALIDATE_BOOLEAN),
        ];

        if (! $this->usage->tryConsume($t, 'short', 1)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        $effectCost = $narration ? 1 : 0;
        if ($effectCost > 0 && ! $this->usage->tryConsume($t, 'effect', $effectCost)) {
            $this->usage->refund($t, 'short', 1);

            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para narração.'], 402);
        }

        // COM roteiro (draftId): vai pra FILA — a montagem custa ~1,25× a duração final e pode
        // passar do corte do nginx/php-fpm; na fila não há esse teto.
        if ($r->filled('draftId')) {
            $d = Draft::where('tenant_id', $t->id)->find((int) $r->input('draftId'));
            if (! $d) {
                $this->usage->refund($t, 'short', 1);
                if ($effectCost > 0) {
                    $this->usage->refund($t, 'effect', $effectCost);
                }

                return response()->json(['ok' => false, 'error' => 'roteiro não encontrado'], 404);
            }
            AssembleFilmJob::dispatch($d->id, $body, $t->id, $effectCost);

            return response()->json(['ok' => true, 'queued' => true, 'draftId' => $d->id]);
        }

        // SEM roteiro: caminho direto (API/uso avulso), síncrono. 570s porque o nginx corta em
        // 600 e o php em 700 — pedir mais só trocaria erro claro por 504 no meio da montagem.
        $res = $this->engine()->timeout(570)->post('/v1/filmassemble', $body);
        if ($res->status() >= 400) {
            $this->usage->refund($t, 'short', 1);
            if ($effectCost > 0) {
                $this->usage->refund($t, 'effect', $effectCost);
            }
        } elseif (($url = (string) $res->json('url')) !== '') {
            $this->attachToGallery($r, (string) $r->input('name', 'filme montado'), $url);
        }

        return $this->relay($res);
    }

    /** Anexa a mídia gerada a um rascunho novo → aparece na Galeria (mesmo acervo dos uploads).
     *  Best-effort: falha ao gravar não derruba a geração (a mídia já está no S3). */
    private function attachToGallery(Request $r, string $prompt, ?string $videoUrl): void
    {
        $t = $r->user()?->tenant;
        if (! $t || ! $videoUrl) {
            return;
        }
        try {
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($prompt, 0, 80) ?: 'vídeo']);
            $d->update(['media' => [array_merge(
                ['id' => (int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)), 'kind' => 'video', 'url' => $videoUrl],
                Draft::probeMeta($videoUrl),
            )]]);
        } catch (\Throwable $e) {
            Log::warning('[roteiro/assemble] falha ao anexar à galeria', ['error' => $e->getMessage()]);
        }
    }

    private function engine(): PendingRequest
    {
        // X-Admin-Token em TODAS as chamadas /v1/* (o engine passou a exigir o token
        // compartilhado nas rotas de geração, não só /v1/admin).
        return Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()
            ->timeout(600); // pesquisa/resumo/texto por LLM podem levar dezenas de segundos
    }
}
