<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrgAsset;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hub "My Assets" (org_assets) — o usuário guarda mídia que gostou pra reusar em outro projeto
 * (benchmark Nordy My Asset). Sem custo (só referencia URLs do nosso S3). Escopo por marca:
 * TenantScope (app) + RLS (banco); aqui o `where('tenant_id', ...)` explícito garante coerência
 * também pro operador (cujo TenantScope é fail-open).
 */
class StudioAssetController extends Controller
{
    /** GET /api/studio/assets?kind=&favorite= — os assets da marca ativa, mais novos primeiro. */
    public function index(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $q = OrgAsset::where('tenant_id', $t->id);
        if (($kind = (string) $r->input('kind', '')) !== '' && in_array($kind, OrgAsset::KINDS, true)) {
            $q->where('kind', $kind);
        }
        if ($r->boolean('favorite')) {
            $q->where('favorite', true);
        }
        $assets = $q->orderByDesc('created_at')->limit(300)->get(['id', 'kind', 'source', 'url', 'thumb_url', 'meta', 'favorite', 'created_at']);

        return response()->json(['ok' => true, 'assets' => $assets]);
    }

    /** POST /api/studio/assets { url, kind?, source?, thumb_url?, meta? } — guarda um asset (0 crédito). */
    public function store(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $url = trim((string) $r->input('url', ''));
        if (! StudioController::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'URL inválida'], 422);
        }
        $kind = in_array($r->input('kind'), OrgAsset::KINDS, true) ? (string) $r->input('kind') : 'image';
        $source = in_array($r->input('source'), OrgAsset::SOURCES, true) ? (string) $r->input('source') : 'generation';
        $thumb = trim((string) $r->input('thumb_url', ''));

        // meta: só campos conhecidos e escalares seguros (proveniência), sem confiar no cliente cru.
        $rawMeta = (array) $r->input('meta', []);
        $meta = [];
        foreach (['draft_id', 'project_id', 'character_id'] as $k) {
            if (isset($rawMeta[$k]) && is_numeric($rawMeta[$k])) {
                $meta[$k] = (int) $rawMeta[$k];
            }
        }
        foreach (['prompt', 'easyapp'] as $k) {
            if (isset($rawMeta[$k]) && is_string($rawMeta[$k])) {
                $meta[$k] = mb_substr($rawMeta[$k], 0, 500);
            }
        }

        // Dedup: mesma URL já guardada nesta marca → devolve a existente (idempotente).
        $existing = OrgAsset::where('tenant_id', $t->id)->where('url', $url)->first();
        if ($existing) {
            return response()->json(['ok' => true, 'asset' => $existing, 'deduped' => true]);
        }

        $asset = OrgAsset::create([
            'tenant_id' => $t->id,
            'user_id' => $r->user()->id,
            'kind' => $kind,
            'source' => $source,
            'url' => $url,
            'thumb_url' => ($thumb !== '' && StudioController::isOwnMediaUrl($thumb)) ? $thumb : null,
            'meta' => $meta ?: null,
            'favorite' => false,
        ]);

        return response()->json(['ok' => true, 'asset' => $asset]);
    }

    /** DELETE /api/studio/assets/{id} — remove um asset da marca (não apaga a mídia no S3). */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $asset = OrgAsset::where('tenant_id', $t->id)->find($id);
        if (! $asset) {
            return response()->json(['ok' => false, 'error' => 'asset não encontrado'], 404);
        }
        $asset->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/assets/{id}/favorite { favorite? } — marca/desmarca favorito. */
    public function favorite(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $asset = OrgAsset::where('tenant_id', $t->id)->find($id);
        if (! $asset) {
            return response()->json(['ok' => false, 'error' => 'asset não encontrado'], 404);
        }
        $asset->favorite = $r->has('favorite') ? $r->boolean('favorite') : ! $asset->favorite;
        $asset->save();

        return response()->json(['ok' => true, 'favorite' => $asset->favorite]);
    }

    private function tenant(Request $r): Tenant
    {
        $t = $r->user()?->tenant;
        abort_unless($t !== null, 404);

        return $t;
    }
}
