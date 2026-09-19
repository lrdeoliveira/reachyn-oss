<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateScenarioJob;
use App\Models\GenModel;
use App\Models\Scenario;
use App\Services\UsageService;
use App\Support\AssetPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Biblioteca de CENÁRIOS reutilizáveis (F5 — "Elementos" do storyboard). CRUD por tenant:
 * nome + descrição + imagem-âncora. O cenário entra como ref i2i de cena nas Histórias/Quadrinhos
 * e como locação no Estúdio de Animação.
 *
 * A imagem-âncora vem por dois caminhos: uma imagem que já existe (galeria/elemento do storyboard)
 * ou GERADA aqui a partir da descrição (generateImage). Antes só existia o primeiro — e nenhuma
 * tela chamava o store, então a biblioteca não tinha como crescer.
 */
class ScenarioController extends Controller
{
    /** Modelo t2i default da imagem-âncora — o mesmo que o personagem usa na base. */
    private const MODEL_T2I = 'img-ultra';

    public function __construct(private UsageService $usage) {}

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function scenario(Request $r, int|string $id): Scenario
    {
        return Scenario::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    /** GET /api/scenarios → cenários do tenant (mais recentes primeiro). */
    public function index(Request $r): JsonResponse
    {
        return response()->json(
            Scenario::where('tenant_id', $this->tenant($r)->id)
                ->latest('updated_at')->limit(200)
                // status vai junto: é ele que o front acompanha no polling enquanto a imagem gera.
                ->get(['id', 'name', 'description', 'image_url', 'status', 'image_model', 'style', 'updated_at'])
        );
    }

    /** POST /api/scenarios { name, description?, imageUrl? } → cria (imagem só do nosso storage). */
    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'imageUrl' => 'nullable|string|max:500',
        ]);
        $img = trim((string) ($data['imageUrl'] ?? ''));
        if ($img !== '' && ! StudioController::isOwnMediaUrl($img)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida (use uma imagem da galeria)'], 422);
        }
        $s = Scenario::create([
            'tenant_id' => $this->tenant($r)->id,
            'name' => trim($data['name']),
            'description' => trim((string) ($data['description'] ?? '')),
            'image_url' => $img ?: null,
        ]);

        return response()->json(['ok' => true, 'scenario' => $s], 201);
    }

    /** PATCH /api/scenarios/{id} { name?, description?, imageUrl? } → edita. */
    public function update(Request $r, string $id): JsonResponse
    {
        $s = $this->scenario($r, $id);
        $data = $r->validate([
            'name' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'imageUrl' => 'nullable|string|max:500',
        ]);
        if (array_key_exists('imageUrl', $data)) {
            $img = trim((string) $data['imageUrl']);
            if ($img !== '' && ! StudioController::isOwnMediaUrl($img)) {
                return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422);
            }
            $s->image_url = $img ?: null;
        }
        if (($n = trim((string) ($data['name'] ?? ''))) !== '') {
            $s->name = $n;
        }
        if (array_key_exists('description', $data)) {
            $s->description = trim((string) $data['description']);
        }
        $s->save();

        return response()->json(['ok' => true, 'scenario' => $s]);
    }

    /** DELETE /api/scenarios/{id} → remove do catálogo (a imagem na galeria fica). */
    public function destroy(Request $r, string $id): JsonResponse
    {
        $this->scenario($r, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/scenarios/{id}/image { description?, model? } → GERA a imagem-âncora do cenário
     * a partir da descrição (t2i, assíncrono). Espelha CharacterController::generateBase:
     * reserve-then-consume da cota 'image', job grava a URL, front faz polling do índice.
     *
     * Regenerar reusa o modelo salvo quando o request não manda um — mesma regra do personagem.
     */
    public function generateImage(Request $r, string $id): JsonResponse
    {
        $s = $this->scenario($r, $id);
        if ($s->isBusy()) {
            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este cenário.'], 409);
        }

        $desc = trim((string) ($r->input('description') ?: $s->description));
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o cenário antes de gerar a imagem.'], 422);
        }

        $plan = $this->tenant($r)->plan;
        $slug = is_string($r->input('model')) && trim((string) $r->input('model')) !== ''
            ? (string) $r->input('model')
            : (string) ($s->image_model ?: '');
        $gm = ($slug !== '' ? GenModel::resolveSelectable($slug, 'image', $plan) : null)
            ?? GenModel::resolveSelectable(self::MODEL_T2I, 'image', $plan);

        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $weight, $gm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        // TÉCNICA: request explícito > a salva no cenário > o padrão do engine. O cenário gerava
        // SEM estilo nenhum até 2026-08-30 — o engine caía em 'realista' e o lugar saía foto
        // enquanto o elenco da mesma história saía em anime.
        $style = AssetPrompt::normStyle($r->input('style') ?: $s->style);
        $payload = ['prompt' => AssetPrompt::cenario($desc), 'aspect' => '16:9', 'style' => $style];
        if ($gm) {
            $payload['provider'] = $gm->provider;
            $payload['model'] = $gm->provider_model_id;
            if ($gm->provider === 'magnific') {
                $payload['magnific'] = $gm->capabilities['magnific'] ?? new \stdClass;
            }
        }

        $s->update(['description' => $desc, 'style' => $style, 'status' => 'base', 'image_model' => $gm?->slug]);
        GenerateScenarioJob::dispatch($s->id, $this->tenant($r)->id, $payload, $weight, $gm?->cost_credits);

        return response()->json(['ok' => true, 'scenario' => $s->fresh()]);
    }
}
