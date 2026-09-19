<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Scene;
use App\Services\StoryboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CENAS da escaleta (F4). CRUD + reordenar. Cada cena pertence a um projeto do tenant e liga
 * personagem × cenário. Os selects de cenário/personagem já existem (biblioteca do tenant).
 */
class SceneController extends Controller
{
    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function scene(Request $r, int|string $id): Scene
    {
        return Scene::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    private function rules(): array
    {
        return [
            // Ato: a história dividida em blocos. 1 quando ninguém declarou — não existe cena órfã.
            'ato' => 'nullable|integer|min:1|max:12',
            'scenario_id' => 'nullable|integer',
            'character_ids' => 'nullable|array',
            'character_ids.*' => 'integer',
            // Quais ELEMENTOS do catálogo entram na cena — a imagem de cada um vira âncora.
            'element_ids' => 'nullable|array',
            'element_ids.*' => 'integer',
            'local' => 'nullable|string|max:120',
            'int_ext' => 'nullable|string|in:INT,EXT',
            'tempo' => 'nullable|string|max:60',
            'motivacao' => 'nullable|string|max:2000',
            'objetivo_cena' => 'nullable|string|max:2000',
            'conflito_cena' => 'nullable|string|max:2000',
            'virada' => 'nullable|boolean',
            'tamanho' => 'nullable|string|max:40',
            'resumo' => 'nullable|string|max:4000',
            // Narração: o que se OUVE na cena. Teto alto porque é fala, não etiqueta.
            'narracao' => 'nullable|string|max:4000',
            'ordem' => 'nullable|integer',
        ];
    }

    /**
     * POST /api/projects/{id}/storyboard — compõe a FOLHA DE STORYBOARD do projeto inteiro (todas as
     * cenas, na ordem, com os planos de cada uma) e devolve a URL.
     *
     * É o documento que se confere ANTES de montar. Os painéis são os quadros que já existem
     * (`shots.quadro_url`) e os rótulos saem da própria decupagem — número, tempo, enquadramento,
     * ação. NÃO gera imagem e NÃO gasta crédito: é composição, igual ao model sheet.
     *
     * ⚠️ Por que composta e não desenhada pela IA: a aba Movies foi removida em 2026-07-22
     * justamente porque o modelo NÃO obedece a grade pedida (pediram 4x4, veio 2x6 com painéis
     * deitados). Aqui quem desenha a grade é o compositor, então ela não tem como vir errada.
     */
    public function storyboard(Request $r, string $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = Project::where('id', $id)->where('tenant_id', $t->id)->firstOrFail();
        $cenas = $p->scenes()->with('shots')->get();

        // A folha é do PROJETO: os planos de todas as cenas, em ordem de cena e depois de plano —
        // é assim que a timeline vai rodar, e é assim que se confere.
        $planos = $cenas->flatMap(fn ($c) => $c->shots)->all();
        if ($planos === []) {
            return response()->json(['ok' => false, 'error' => 'nenhum plano na escaleta ainda — faça a decupagem das cenas primeiro'], 422);
        }

        $paineis = StoryboardService::painelsDePlanos($planos);
        $semQuadro = count(array_filter($paineis, fn ($x) => ($x['url'] ?? '') === ''));
        $url = (new StoryboardService)->compor(
            $paineis,
            (string) ($p->name ?: 'Storyboard'),
            count($paineis).' planos · '.$cenas->count().' cenas',
            [
                ['k' => 'projeto', 'v' => (string) ($p->name ?: '—')],
                ['k' => 'cenas', 'v' => (string) $cenas->count()],
                ['k' => 'planos', 'v' => (string) count($paineis)],
            ],
        );
        if ($url === null) {
            return response()->json(['ok' => false, 'error' => 'não foi possível compor a folha'], 502);
        }

        // `sem_quadro` é o que a tela usa pra avisar quantos planos ainda não têm imagem — a folha
        // sai mesmo assim, porque o buraco visível é o motivo dela existir.
        return response()->json(['ok' => true, 'url' => $url, 'panels' => count($paineis), 'sem_quadro' => $semQuadro]);
    }

    /** Só os campos editáveis presentes no request. */
    private function fields(array $data): array
    {
        $out = [];
        foreach (['ato', 'scenario_id', 'character_ids', 'element_ids', 'local', 'int_ext', 'tempo', 'motivacao', 'objetivo_cena', 'conflito_cena', 'virada', 'tamanho', 'resumo', 'narracao', 'ordem'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        return $out;
    }

    public function store(Request $r): JsonResponse
    {
        $tid = $this->tenant($r)->id;
        $data = $r->validate(['project_id' => 'required|integer'] + $this->rules());
        // o projeto tem de ser do tenant
        Project::where('id', $data['project_id'])->where('tenant_id', $tid)->firstOrFail();
        $ordem = $data['ordem'] ?? ((int) Scene::where('project_id', $data['project_id'])->max('ordem') + 1);
        $s = Scene::create(array_merge(
            ['tenant_id' => $tid, 'project_id' => $data['project_id'], 'ordem' => $ordem],
            $this->fields($data),
        ));

        return response()->json(['ok' => true, 'scene' => $s], 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $s = $this->scene($r, $id);
        $data = $r->validate($this->rules());
        $s->fill($this->fields($data))->save();

        return response()->json(['ok' => true, 'scene' => $s->fresh()]);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $this->scene($r, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/scenes/reorder { ids: [...] } → aplica a ordem na sequência dada. */
    public function reorder(Request $r): JsonResponse
    {
        $tid = $this->tenant($r)->id;
        $data = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
        foreach ($data['ids'] as $i => $sid) {
            Scene::where('id', $sid)->where('tenant_id', $tid)->update(['ordem' => $i]);
        }

        return response()->json(['ok' => true]);
    }
}
