<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prompt;
use Illuminate\Http\Request;

/**
 * Biblioteca de prompts do tenant (CRUD). Texto livre (título + conteúdo). O global scope
 * BelongsToTenant garante o isolamento multi-tenant (queries + route-model binding).
 */
class PromptController extends Controller
{
    /** ?kind=image|video devolve só as personas daquele alvo (select de persona do Studio). */
    public function index(Request $r)
    {
        $q = Prompt::where('tenant_id', $r->user()->tenant_id);
        if ($kind = $r->query('kind')) {
            abort_unless(in_array($kind, ['image', 'video'], true), 422);
            $q->personas($kind);
        }

        return $q->latest('updated_at')
            ->limit(500)
            ->get(['id', 'title', 'kind', 'content', 'updated_at']);
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'title' => 'nullable|string|max:200',
            'kind' => 'nullable|in:image,video', // persona de estilo; ausente = prompt de texto
            'content' => 'required|string|max:20000',
        ]);
        $data['tenant_id'] = $r->user()->tenant_id;
        $data['title'] = trim((string) ($data['title'] ?? '')) ?: 'Sem título';

        return response()->json(['ok' => true, 'prompt' => Prompt::create($data)], 201);
    }

    public function update(Request $r, Prompt $prompt)
    {
        abort_unless($prompt->tenant_id === $r->user()->tenant_id, 404);
        $data = $r->validate([
            'title' => 'nullable|string|max:200',
            'kind' => 'nullable|in:image,video',
            'content' => 'nullable|string|max:20000',
        ]);
        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']) ?: 'Sem título';
        }
        $prompt->update($data);

        return response()->json(['ok' => true, 'prompt' => $prompt->fresh()]);
    }

    public function destroy(Request $r, Prompt $prompt)
    {
        abort_unless($prompt->tenant_id === $r->user()->tenant_id, 404);
        $prompt->delete();

        return response()->json(['ok' => true]);
    }
}
