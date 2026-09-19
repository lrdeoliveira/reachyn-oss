<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateElementJob;
use App\Models\Element;
use App\Models\GenModel;
use App\Services\UsageService;
use App\Support\AssetPrompt;
use App\Support\GenPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CATÁLOGO DE ELEMENTOS — o carro, a cadeira, a mala, o cão de rua.
 *
 * Personagem e cenário já tinham biblioteca com imagem-âncora, e é por isso que atravessam o filme
 * sem mudar de cara. Objeto não tinha casa: nada garantia que o carro da cena 2 fosse o carro da
 * cena 7. Aqui ele ganha a mesma mecânica — ficha + imagem que viaja como referência para toda cena
 * que o usa.
 *
 * A imagem é gerada como a BASE de um personagem, não como um cenário: objeto isolado, fundo neutro,
 * inteiro no quadro. É âncora de identidade, não fotografia de catálogo — quem vai compor a cena
 * depois é a cena.
 */
class ElementController extends Controller
{
    /** Mesmo t2i default da base do personagem e da imagem do cenário. */
    private const MODEL_T2I = 'img-ultra';

    public function __construct(private UsageService $usage) {}

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function element(Request $r, int|string $id): Element
    {
        return Element::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    public function index(Request $r): JsonResponse
    {
        return response()->json(
            Element::where('tenant_id', $this->tenant($r)->id)->orderBy('categoria')->orderBy('name')->limit(200)->get()
        );
    }

    /** As categorias do catálogo, pro front não duplicar a lista. */
    public function categorias(): JsonResponse
    {
        return response()->json(array_map(
            fn ($k, $v) => ['valor' => $k, 'rotulo' => $v],
            array_keys(Element::CATEGORIAS),
            Element::CATEGORIAS
        ));
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate($this->rules());
        $e = Element::create([
            'tenant_id' => $this->tenant($r)->id,
            'name' => trim($data['name']),
            'categoria' => $data['categoria'] ?? 'prop',
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
        ]);

        return response()->json(['ok' => true, 'element' => $e], 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $e = $this->element($r, $id);
        $data = $r->validate($this->rules(false));
        foreach (['name', 'categoria', 'description', 'image_url'] as $k) {
            if (array_key_exists($k, $data)) {
                $e->{$k} = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
            }
        }
        $e->save();

        return response()->json(['ok' => true, 'element' => $e->fresh()]);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $this->element($r, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/elements/{id}/image → a imagem-âncora do elemento (t2i, assíncrona).
     *
     * Reserva a cota aqui e o job estorna na falha — mesma regra do personagem e do cenário, pra
     * ninguém pagar por imagem que não recebeu.
     */
    public function generateImage(Request $r, string $id): JsonResponse
    {
        $e = $this->element($r, $id);
        if ($e->status === 'base') {
            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este elemento.'], 409);
        }
        $desc = trim((string) ($r->input('description') ?? $e->description));
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o elemento antes de gerar a imagem.'], 422);
        }

        $plan = $this->tenant($r)->plan;
        $slug = is_string($r->input('model')) && trim((string) $r->input('model')) !== ''
            ? (string) $r->input('model')
            : (string) ($e->image_model ?: '');
        $gm = ($slug !== '' ? GenModel::resolveSelectable($slug, 'image', $plan) : null)
            ?? GenModel::resolveSelectable(self::MODEL_T2I, 'image', $plan);

        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $weight, $gm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        // TÉCNICA: request explícito > a salva no elemento > o padrão do engine. Como no cenário,
        // o objeto era gerado sem estilo — e quebrava a unidade visual da história.
        $style = AssetPrompt::normStyle($r->input('style') ?: $e->style);
        $prompt = AssetPrompt::elemento($desc);

        $aspect = in_array($r->input('aspect'), ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'], true)
            ? (string) $r->input('aspect') : '1:1';
        $payload = ['prompt' => $prompt, 'aspect' => $aspect, 'style' => $style];
        if ($gm) {
            $payload = array_merge($payload, GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, $r->input('quality'))));
        }

        $e->update(['description' => $desc, 'style' => $style, 'status' => 'base', 'image_model' => $gm?->slug]);
        GenerateElementJob::dispatch($e->id, $this->tenant($r)->id, $payload, $weight, $gm?->cost_credits);

        return response()->json(['ok' => true, 'element' => $e->fresh()]);
    }

    private function rules(bool $criando = true): array
    {
        return [
            'name' => ($criando ? 'required' : 'nullable').'|string|max:120',
            'categoria' => ['nullable', 'string', 'in:'.implode(',', array_keys(Element::CATEGORIAS))],
            'description' => 'nullable|string|max:4000',
            'image_url' => 'nullable|string|max:500',
        ];
    }
}
