<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Publication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Arquivo de publicações — o que o cliente já publicou (snapshot permanente).
 *
 * Multi-tenant: TODA query filtra por tenant_id explícito (defesa em profundidade)
 * E o model Publication aplica o global scope do trait BelongsToTenant. O cliente
 * logado NUNCA enxerga publicação de outro tenant.
 */
class PublicationController extends Controller
{
    /** GET /api/publications → lista do tenant (mais recentes primeiro). */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $items = Publication::where('tenant_id', $tenantId) // explícito + global scope do trait
            ->orderByDesc('published_at')
            ->limit(200)
            ->get()
            ->map(function (Publication $p) {
                $media = $p->media ?? [];

                return [
                    'id' => $p->id,
                    'keyword' => $p->keyword,
                    'status' => $p->status,
                    'networks' => $p->networks ?? [],
                    'thumb' => $media[0] ?? null,      // 1ª mídia como capa
                    'media_count' => count($media),
                    'published_at' => optional($p->published_at)->toIso8601String(),
                ];
            });

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** GET /api/publications/{publication} → detalhe completo. */
    public function show(Request $request, Publication $publication): JsonResponse
    {
        // Isolamento por tenant (além do global scope): 403 se for de outro tenant.
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        return response()->json(['ok' => true, 'item' => [
            'id' => $publication->id,
            'keyword' => $publication->keyword,
            'status' => $publication->status,
            'content_text' => $publication->content_text,
            'media' => $publication->media ?? [],
            'networks' => $publication->networks ?? [],
            'published_at' => optional($publication->published_at)->toIso8601String(),
        ]]);
    }
}
