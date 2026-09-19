<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Draft;
use App\Services\UsageService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 🕹️ Sprites de jogo (aba Sprites) — proxy autenticado → engine /v1/sprite/*.
 * O engine fala com o motor hospedado (chave do operador em Chaves de geração) e, no
 * persist, baixa os artefatos pro nosso storage; aqui a spritesheet/preview entram na
 * GALERIA (Draft) pra virarem acervo reutilizável como qualquer outra mídia gerada.
 */
class SpriteController extends Controller implements HasMiddleware
{
    /** Ids de job do serviço são slugs curtos — allowlist antes de entrar em path. */
    private const JOB_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * 💸 GATE + COTA (baseline #8, AUD-009 / LLM10) — corrigido em 2026-08-07.
     *
     * O `store` enfileira um job no motor hospedado, e quem paga esse job é o SALDO DO OPERADOR,
     * não o do cliente. Até aqui as 7 rotas de sprite estavam só sob `auth:sanctum` + throttle:
     * qualquer tenant autenticado — inclusive em trial — queimava crédito nosso a 10 jobs/min, sem
     * plano e sem medição. Os controllers vizinhos sempre cobraram (Character 13 cotas, Film 19,
     * Generate 10, Studio 42); este nasceu como proxy e passou batido.
     *
     * Só o `store` entra: `frames` e `normalize` são passthrough do NOSSO ffmpeg-service (CPU
     * própria, zero crédito externo), `persist` só baixa artefato já pago, e o resto é leitura.
     * Cobrar leitura seria inventar custo que não existe.
     */
    public static function middleware(): array
    {
        return [
            new Middleware('subscribed', only: ['store']),
        ];
    }

    public function __construct(private UsageService $usage) {}

    /** Campos aceitos no enqueue (allowlist — o engine filtra de novo, defesa em profundidade). */
    private const FIELDS = [
        'type', 'characterName', 'sourcePrompt', 'sourceImageUrl', 'referenceJobId',
        'editPrompt', 'direction', 'gameView', 'actions', 'actionBaselines',
        'candidatePromptPreset', 'pixelSnapAnchor', 'pixelSnap', 'seed', 'actionContext',
        'chroma', 'kColors',
    ];

    /** GET /api/sprite/me — saldo de créditos do motor de sprites. */
    public function me(): JsonResponse
    {
        return $this->relay($this->engine()->get('/v1/sprite/me'));
    }

    /** GET /api/sprite/jobs — lista (mais novos primeiro). */
    public function index(Request $r): JsonResponse
    {
        $limit = min(max((int) $r->query('limit', 25), 1), 100);

        return $this->relay($this->engine()->get('/v1/sprite/jobs', ['limit' => $limit]));
    }

    /** POST /api/sprite/jobs — enfileira personagem, variação ou animação avulsa. */
    public function store(Request $r): JsonResponse
    {
        $payload = array_intersect_key($r->all(), array_flip(self::FIELDS));

        // characterName vira nome de pasta/artefato no serviço → slug estrito.
        $name = strtolower(trim((string) ($payload['characterName'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $name)) {
            return response()->json(['ok' => false, 'error' => 'Nome do personagem: use letras minúsculas, números, hífen (até 40).'], 422);
        }
        $payload['characterName'] = $name;

        // Sprite é geração de IMAGEM (spritesheet) — mesmo balde dos outros geradores visuais.
        // Cobra ANTES de enfileirar: o job vira gasto no provedor no instante em que entra na
        // fila, então autorizar depois seria autorizar o que já saiu do bolso.
        $t = $r->user()?->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');
        if (! $this->usage->tryConsume($t, 'image', 1)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        $res = $this->engine()->post('/v1/sprite/jobs', $payload);
        if (! $res->successful()) {
            // Não entrou na fila = não gastou lá. Estorna, senão o cliente paga pelo nosso erro.
            $this->usage->refund($t, 'image', 1);
        }

        return $this->relay($res);
    }

    /** GET /api/sprite/jobs/{id} — um job (status, steps, artifacts). */
    public function show(string $id): JsonResponse
    {
        abort_unless((bool) preg_match(self::JOB_ID, $id), 422, 'id inválido');

        return $this->relay($this->engine()->get("/v1/sprite/jobs/{$id}"));
    }

    /**
     * POST /api/sprite/jobs/{id}/persist — engine baixa os artefatos pro nosso storage;
     * aqui as peças visuais entram na galeria. Idempotente por jobId (repersistir não
     * duplica o card no acervo).
     */
    public function persist(Request $r, string $id): JsonResponse
    {
        abort_unless((bool) preg_match(self::JOB_ID, $id), 422, 'id inválido');

        $res = $this->engine()->post("/v1/sprite/jobs/{$id}/persist");
        if ($res->successful()) {
            $files = (array) $res->json('files', []);
            if ($files !== []) {
                $this->attachToGallery($r, $id, (string) $r->input('name', ''), $files);
            }
        }

        return $this->relay($res);
    }

    // ——— Motor LOCAL (pipeline próprio: âncora/clipe pelas rotas de geração existentes;
    // aqui só os passos novos — frames e normalização, sem crédito de IA) ———

    /** POST /api/sprite/frames — clipe i2v do NOSSO acervo → contact sheet + token. */
    public function frames(Request $r): JsonResponse
    {
        $in = $r->validate([
            'video_url' => 'required|string|max:500',
            'fps' => 'sometimes|integer|min:4|max:24',
        ]);
        // Mesmo contrato anti-SSRF do i2v: só mídia do nosso storage.
        if (! ($url = StudioController::ownMediaUrl($in['video_url']))) {
            return response()->json(['ok' => false, 'error' => 'Vídeo inválido (só mídia do seu acervo).'], 422);
        }
        $payload = ['video_url' => $url];
        if (isset($in['fps'])) {
            $payload['fps'] = (int) $in['fps'];
        }

        return $this->relay($this->engine()->post('/v1/sprite/frames', $payload));
    }

    /** POST /api/sprite/normalize — poses (vídeo OU prancha) → spritesheet; sucesso entra na galeria. */
    public function normalize(Request $r): JsonResponse
    {
        $payload = $r->validate([
            // fonte vídeo: token (frames extraídos) + índices na ordem do loop
            'token' => 'required_without:sheet_url|string|regex:/^[a-f0-9]{16}$/',
            'frames' => 'required_without:sheet_url|array|max:32',
            'frames.*' => 'integer|min:1|max:999',
            // fonte prancha: imagem 5×2 (do NOSSO acervo) fatiada pelo grid nominal
            'sheet_url' => 'sometimes|string|max:500',
            'cols' => 'sometimes|integer|min:1|max:12',
            'rows' => 'sometimes|integer|min:1|max:8',
            'chroma' => 'sometimes|string|max:7',
            'tolerance' => 'sometimes|integer|min:20|max:160',
            'cell' => 'sometimes|integer|min:64|max:512',
            'fps' => 'sometimes|integer|min:4|max:24',
            'name' => 'sometimes|string|max:60',
        ]);
        $name = (string) ($payload['name'] ?? '');
        unset($payload['name']);
        if (($payload['sheet_url'] ?? '') !== '') {
            if (! ($u = StudioController::ownMediaUrl($payload['sheet_url']))) {
                return response()->json(['ok' => false, 'error' => 'Prancha inválida (só mídia do seu acervo).'], 422);
            }
            $payload['sheet_url'] = $u;
        }

        $res = $this->engine()->post('/v1/sprite/normalize', $payload);
        if ($res->successful() && ($sheet = (string) $res->json('sheet_url')) !== '') {
            $files = array_filter([
                'spritesheet' => $sheet,
                'preview' => (string) $res->json('preview_url'),
            ]);
            // "jobId" local = hash da sheet (URL imutável) — dá a idempotência da galeria.
            $this->attachToGallery($r, substr(sha1($sheet), 0, 16), $name, $files);
        }

        return $this->relay($res);
    }

    /**
     * POST /api/sprite/asset-gallery — anexa na Galeria um ASSET DE JOGO já gerado (modo
     * "Assets de jogo" da aba: tileset/background/textura/GUI/ícones/props). A imagem já
     * existe no nosso acervo (saiu do /generate/image, que cobrou); aqui é só o anexo —
     * zero crédito. Idempotente pelo hash da URL (mesmo molde do normalize).
     */
    public function assetGallery(Request $r): JsonResponse
    {
        $in = $r->validate([
            'url' => 'required|string|max:2000',
            'name' => 'sometimes|string|max:60',
        ]);
        if (! ($url = StudioController::ownMediaUrl($in['url']))) {
            return response()->json(['ok' => false, 'error' => 'Asset inválido (só mídia do seu acervo).'], 422);
        }
        $this->attachToGallery($r, substr(sha1($url), 0, 16), (string) ($in['name'] ?? ''), ['asset' => $url]);

        return response()->json(['ok' => true]);
    }

    /** Spritesheets/previews/âncoras persistidos → um Draft na galeria (manifest JSON fica fora). */
    private function attachToGallery(Request $r, string $jobId, string $name, array $files): void
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return;
        }
        $keyword = mb_substr(trim('sprite '.($name !== '' ? $name.' ' : '').substr($jobId, -8)), 0, 80);
        if (Draft::where('tenant_id', $t->id)->where('keyword', $keyword)->exists()) {
            return;
        }
        try {
            $media = [];
            foreach ($files as $n => $url) {
                if (! is_string($url) || $url === '' || str_ends_with((string) $n, '/manifest')) {
                    continue;
                }
                $media[] = array_merge(
                    ['id' => (int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)), 'kind' => 'image', 'url' => $url],
                    Draft::imageMeta($url),
                );
            }
            if ($media === []) {
                return;
            }
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword]);
            $d->update(['media' => $media]);
        } catch (\Throwable $e) {
            Log::warning('[sprite] falha ao anexar à galeria', ['job' => $jobId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Repassa a resposta do engine. Diferente do relay de geração (que genericiza),
     * aqui o 4xx PRESERVA a mensagem do serviço: validação ("direction must match…")
     * e saldo (402) são orientação real de uso — e a aba é do operador local.
     * 5xx segue genérico (502) com o corpo no log.
     */
    private function relay(Response $res): JsonResponse
    {
        if ($res->status() < 500) {
            return response()->json($res->json() ?? [], $res->status());
        }

        Log::warning('[sprite] engine falhou', [
            'status' => $res->status(),
            'body' => mb_substr((string) $res->body(), 0, 2000),
        ]);

        return response()->json(['ok' => false, 'error' => 'O motor de sprites está indisponível, tente novamente.'], 502);
    }

    private function engine(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()
            ->timeout(180); // enqueue/poll são leves; o persist baixa alguns MB de artefatos
    }
}
