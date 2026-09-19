<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnimationProject;
use App\Models\Character;
use App\Models\CreationTemplate;
use App\Models\Draft;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Templates de criação (aba Rápido / Quick Start — benchmark Nordy+RunningHub). Tira o usuário do
 * "por onde começo?": ele escolhe um modelo pronto e o app pré-preenche o Estúdio (target=animation)
 * ou abre um rascunho já rotulado (target=draft).
 *
 * APLICAR É 0 CRÉDITO (as gerações seguintes cobram como hoje). Por isso o apply de animação NÃO
 * cria um projeto órfão pré-parse: ele devolve um `prefill` que o wizard do Estúdio injeta no form
 * de criação — reusando o fluxo real (criar → parse → etapas) e o binding de personagem (preCharId)
 * que já existem. O apply de draft cria um Draft vazio (0 crédito) e manda pro editor de conteúdo.
 */
class StudioTemplateController extends Controller
{
    /** GET /api/studio/templates?category=&target= — globais (Reachyn) + receitas da marca ativa. */
    public function index(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $q = CreationTemplate::visibleTo($t->id)->where('active', true);
        $cat = (string) $r->input('category', '');
        if ($cat !== '' && in_array($cat, CreationTemplate::CATEGORIES, true)) {
            $q->where('category', $cat);
        }
        $rows = $q->orderBy('sort')->orderBy('id')->get();

        $target = (string) $r->input('target', '');
        if ($target !== '' && in_array($target, CreationTemplate::TARGETS, true)) {
            $rows = $rows->filter(fn ($x) => ($x->payload['target'] ?? 'draft') === $target)->values();
        }

        $templates = $rows->map(function (CreationTemplate $x) {
            $p = (array) $x->payload;

            return [
                'id' => $x->id,
                'slug' => $x->slug,
                'title' => $x->title,
                'description' => $x->description,
                'category' => $x->category,
                'preview_url' => $x->preview_url,
                'is_global' => $x->tenant_id === null,
                'target' => in_array($p['target'] ?? '', CreationTemplate::TARGETS, true) ? $p['target'] : 'draft',
                'summary' => $this->summarize($p),
                'estimated_credits' => $this->estimateCredits($p),
                'character_required' => (bool) ($p['character_required'] ?? false),
                'easyapps_suggested' => array_values(array_filter((array) ($p['easyapps_suggested'] ?? []), 'is_string')),
            ];
        })->values();

        return response()->json(['ok' => true, 'templates' => $templates]);
    }

    /** POST /api/studio/templates/{id}/apply { characterId?, title? } — 0 crédito. Devolve handoff. */
    public function apply(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);

        $tpl = CreationTemplate::visibleTo($t->id)->where('active', true)->find($id);
        if (! $tpl) {
            return response()->json(['ok' => false, 'error' => 'template não encontrado'], 404);
        }
        $p = (array) $tpl->payload;
        $target = in_array($p['target'] ?? '', CreationTemplate::TARGETS, true) ? (string) $p['target'] : 'draft';

        // Personagem da biblioteca (opcional) — validar que é da própria marca antes de repassar.
        $preCharId = null;
        if ($cid = (int) $r->input('characterId')) {
            $c = Character::where('tenant_id', $t->id)->find($cid);
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'personagem não encontrado'], 404);
            }
            $preCharId = $c->id;
        }
        $titleOverride = mb_substr(trim((string) $r->input('title', '')), 0, 120);

        if ($target === 'animation') {
            $prefill = [
                'script' => mb_substr((string) ($p['script_seed'] ?? ''), 0, 24000),
                'mode' => in_array($p['mode'] ?? '', AnimationProject::MODES, true) ? $p['mode'] : 'historia',
                'style' => array_key_exists($p['style'] ?? '', AnimationFlow::STYLE_LEADS) ? $p['style'] : '3d',
                'aspect' => ($p['aspect'] ?? '9:16') === '16:9' ? '16:9' : '9:16',
                'scenes' => max(1, min(20, (int) ($p['scenes'] ?? 6))),
                'quality' => in_array($p['quality'] ?? '', ['economico', 'padrao', 'premium'], true) ? $p['quality'] : 'padrao',
                'sequenceMode' => in_array($p['sequence_mode'] ?? '', AnimationProject::SEQUENCE_MODES, true) ? $p['sequence_mode'] : 'encadeado',
                'palette' => mb_substr((string) ($p['palette'] ?? ''), 0, 200),
                'title' => $titleOverride,
                'preCharId' => $preCharId,
            ];

            return response()->json(['ok' => true, 'target' => 'animation', 'redirect' => '/estudio', 'prefill' => $prefill]);
        }

        // target=draft — cria o rascunho (0 crédito) e manda pro editor de conteúdo (/editar).
        $keyword = $titleOverride !== '' ? $titleOverride : mb_substr((string) $tpl->title, 0, 120);
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword]);
        $prefill = [
            'script_seed' => mb_substr((string) ($p['script_seed'] ?? ''), 0, 24000),
            'platforms' => array_values(array_filter((array) ($p['platforms'] ?? []), 'is_string')),
            'aspect' => (string) ($p['aspect'] ?? '1:1'),
            'easyapps_suggested' => array_values(array_filter((array) ($p['easyapps_suggested'] ?? []), 'is_string')),
        ];

        return response()->json(['ok' => true, 'target' => 'draft', 'draftId' => $draft->id, 'redirect' => '/editar', 'prefill' => $prefill]);
    }

    /**
     * POST /api/studio/templates { title, category?, fromProjectId? | fromDraftId? } — salva o
     * trabalho atual como RECEITA da marca (creation_templates com tenant_id preenchido → visível só
     * pra equipe da marca, via "Da sua equipe"). O RLS garante que só grava na própria marca.
     */
    public function store(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $title = mb_substr(trim((string) $r->input('title', '')), 0, 120);
        if ($title === '') {
            return response()->json(['ok' => false, 'error' => 'dê um nome pra receita'], 422);
        }
        $category = in_array($r->input('category'), CreationTemplate::CATEGORIES, true) ? (string) $r->input('category') : 'outro';

        if ($pid = (int) $r->input('fromProjectId')) {
            $p = AnimationProject::where('tenant_id', $t->id)->find($pid);
            if (! $p) {
                return response()->json(['ok' => false, 'error' => 'projeto não encontrado'], 404);
            }
            $scenes = max(1, min(20, count((array) $p->storyboard) ?: 6));
            $payload = [
                'target' => 'animation', 'mode' => $p->mode, 'style' => $p->style, 'aspect' => $p->aspect,
                'scenes' => $scenes, 'quality' => $p->quality, 'sequence_mode' => $p->sequence_mode,
                'palette' => mb_substr((string) $p->palette, 0, 200),
                'script_seed' => mb_substr((string) $p->script, 0, 2000),
                'character_required' => false,
            ];
        } elseif ($did = (int) $r->input('fromDraftId')) {
            $draft = Draft::where('tenant_id', $t->id)->find($did);
            if (! $draft) {
                return response()->json(['ok' => false, 'error' => 'rascunho não encontrado'], 404);
            }
            $payload = [
                'target' => 'draft', 'aspect' => '1:1',
                'script_seed' => mb_substr((string) $draft->keyword, 0, 2000),
                'character_required' => false,
            ];
        } else {
            return response()->json(['ok' => false, 'error' => 'informe fromProjectId ou fromDraftId'], 422);
        }

        $tpl = CreationTemplate::create([
            'tenant_id' => $t->id,
            'slug' => $this->uniqueSlug($title, $t->id),
            'title' => $title,
            'category' => $category,
            'payload' => $payload,
            'active' => true,
            'sort' => 0,
        ]);

        return response()->json(['ok' => true, 'template' => ['id' => $tpl->id, 'slug' => $tpl->slug, 'title' => $tpl->title]]);
    }

    /** DELETE /api/studio/templates/{id} — remove uma RECEITA da marca (nunca um template global). */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        // whereNotNull(tenant_id): global (tenant_id NULL) não é apagável por cliente. RLS respalda.
        $tpl = CreationTemplate::where('tenant_id', $t->id)->whereNotNull('tenant_id')->find($id);
        if (! $tpl) {
            return response()->json(['ok' => false, 'error' => 'receita não encontrada'], 404);
        }
        $tpl->delete();

        return response()->json(['ok' => true]);
    }

    /** Slug allowlist ^[a-z0-9-]{1,64}$ único por marca (receita da org). */
    private function uniqueSlug(string $title, int $tenantId): string
    {
        $base = mb_substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($title)) ?: 'receita', 0, 56);
        $base = trim($base, '-') ?: 'receita';
        $slug = $base;
        $n = 1;
        while (CreationTemplate::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    /** Resumo curto pro card ("6 cenas · 9:16 · qualidade padrão"). */
    private function summarize(array $p): string
    {
        $target = $p['target'] ?? 'draft';
        if ($target === 'animation') {
            $scenes = max(1, min(20, (int) ($p['scenes'] ?? 6)));
            $q = in_array($p['quality'] ?? '', ['economico', 'padrao', 'premium'], true) ? $p['quality'] : 'padrao';
            $bits = [$scenes.' '.($scenes === 1 ? 'cena' : 'cenas'), (string) ($p['aspect'] ?? '9:16'), 'qualidade '.$q];
            if (! empty($p['storyboard_only'])) {
                array_unshift($bits, 'só storyboard');
            }

            return implode(' · ', $bits);
        }

        return 'post + imagem · '.(string) ($p['aspect'] ?? '1:1');
    }

    /**
     * Estimativa APROXIMADA de créditos pra mostrar no card ANTES de aplicar (transparência — padrão
     * Nordy/RunningHub). Ballpark por unidade (mesma ordem dos buckets do UsageService: texto≈5,
     * imagem≈2, vídeo/cena≈60); o custo REAL depende do modelo/plano e é cobrado na hora de gerar.
     */
    private function estimateCredits(array $p): int
    {
        $target = $p['target'] ?? 'draft';
        if ($target !== 'animation') {
            return 15; // rascunho: 1 texto + 1 imagem, aprox.
        }
        $scenes = max(1, min(20, (int) ($p['scenes'] ?? 6)));
        $text = 5;                 // parse do roteiro
        $perImage = 2;             // keyframe por cena
        if (! empty($p['storyboard_only'])) {
            return $text + $scenes * $perImage;
        }
        $mode = $p['mode'] ?? 'historia';
        $perVideo = $mode === 'quadrinhos' ? 0 : 60; // Quadrinhos = slides (sem i2v)
        $assembly = 40;            // montagem/storyvideo final, aprox.

        return $text + $scenes * ($perImage + $perVideo) + $assembly;
    }

    private function tenant(Request $r): Tenant
    {
        $t = $r->user()?->tenant;
        abort_unless($t !== null, 404);

        return $t;
    }
}
