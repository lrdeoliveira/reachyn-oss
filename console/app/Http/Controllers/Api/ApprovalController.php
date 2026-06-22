<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Publication;
use App\Services\PublishService;
use App\Support\Audit;
use Illuminate\Http\Request;

/**
 * Fila de aprovação nativa (substitui o suspend/resume do Windmill).
 * Consumida pelo Studio Next.js (cliente) e alimentada pelo engine/web.
 */
class ApprovalController extends Controller
{
    /** Pendências do tenant do usuário. */
    public function index(Request $request)
    {
        return Approval::where('tenant_id', $request->user()->tenant_id)
            ->latest()
            ->limit(100)
            ->get();
    }

    /** Cria uma peça pendente (engine/web). */
    public function store(Request $request)
    {
        $data = $request->validate([
            'keyword' => 'nullable|string',
            'preview_text' => 'nullable|string',
            'image_url' => 'nullable|string',
            'video_url' => 'nullable|string',
            'job_id' => 'nullable|string',
            'meta' => 'nullable|array',
        ]);

        // AUD-013: a mídia deve vir do nosso bucket (engine manda URLs do próprio bucket).
        // Rejeita URL externa para impedir mídia arbitrária na fila/publish.
        foreach (['image_url', 'video_url'] as $field) {
            if (! empty($data[$field]) && ! StudioController::isOwnMediaUrl($data[$field])) {
                abort(422, 'URL de mídia inválida (deve ser do domínio de mídia do Reachyn).');
            }
        }

        $data['tenant_id'] = $request->user()->tenant_id;
        $data['status'] = 'pendente';

        return response()->json(Approval::create($data), 201);
    }

    /**
     * Edita o texto/tema de uma peça pendente (cliente revisa antes de aprovar).
     * Só campos de texto — as URLs de mídia não são editáveis aqui (mantém a
     * garantia AUD-013 de que a mídia veio do nosso bucket).
     */
    public function update(Request $request, Approval $approval)
    {
        $this->authorizeTenant($request, $approval);
        // Só edita peça ainda pendente — já publicada/rejeitada é imutável.
        abort_if($approval->status !== 'pendente', 409, 'Esta peça já foi processada e não pode ser editada.');

        $data = $request->validate([
            'keyword' => 'nullable|string|max:200',
            'preview_text' => 'nullable|string|max:20000',
        ]);

        $approval->update($data);

        // AUD-005: trilha da edição.
        Audit::log('approval.update', ['approval_id' => $approval->id, 'tenant_id' => $approval->tenant_id]);

        return response()->json(['ok' => true, 'approval' => $approval->fresh()]);
    }

    /** Aprovar → publica via Zernio nas contas do tenant. */
    public function approve(Request $request, Approval $approval, PublishService $publisher)
    {
        $this->authorizeTenant($request, $approval);
        // AUD-004: idempotência anti-double/spam. Só processa de 'pendente'.
        abort_if($approval->status !== 'pendente', 409, 'Já processado.');

        // Reivindica a aprovação ATOMICAMENTE (where status=pendente): só quem vencer a
        // corrida segue para publishApproval — evita publicar a mesma peça 2×.
        $claimed = Approval::where('id', $approval->id)
            ->where('tenant_id', $approval->tenant_id)
            ->where('status', 'pendente')
            ->update(['status' => 'publicando']);
        abort_if($claimed !== 1, 409, 'Já processado.');

        $results = $publisher->publishApproval($approval);
        $approval->update([
            'status' => 'aprovado',
            'meta' => array_merge($approval->meta ?? [], ['publish' => $results]),
        ]);

        // Arquivo de publicações: snapshot PERMANENTE do que foi ao ar. Mídia da peça +
        // texto de preview + redes/posts. tenant_id explícito (do próprio approval).
        // Publication::record já é à prova de falha (try/catch interno) — não quebra o approve.
        $media = [];
        if ($approval->image_url) {
            $media[] = ['kind' => 'image', 'url' => $approval->image_url];
        }
        if ($approval->video_url) {
            $media[] = ['kind' => 'video', 'url' => $approval->video_url];
        }
        Publication::record(
            tenantId: $approval->tenant_id,
            sourceType: 'approval',
            sourceId: $approval->id,
            keyword: (string) $approval->keyword,
            contentText: (string) $approval->preview_text,
            media: $media,
            results: $results,
        );

        // AUD-005: trilha da decisão.
        Audit::log('approval.approve', ['approval_id' => $approval->id, 'tenant_id' => $approval->tenant_id]);

        return response()->json(['status' => 'aprovado', 'publish' => $results]);
    }

    /** Rejeitar → descarta. */
    public function reject(Request $request, Approval $approval)
    {
        $this->authorizeTenant($request, $approval);
        // AUD-004: só rejeita de 'pendente', de forma atômica.
        abort_if($approval->status !== 'pendente', 409, 'Já processado.');

        $claimed = Approval::where('id', $approval->id)
            ->where('tenant_id', $approval->tenant_id)
            ->where('status', 'pendente')
            ->update(['status' => 'rejeitado']);
        abort_if($claimed !== 1, 409, 'Já processado.');

        // AUD-005: trilha da decisão.
        Audit::log('approval.reject', ['approval_id' => $approval->id, 'tenant_id' => $approval->tenant_id]);

        return response()->json(['status' => 'rejeitado']);
    }

    private function authorizeTenant(Request $request, Approval $approval): void
    {
        abort_unless($approval->tenant_id === $request->user()->tenant_id, 403, 'Aprovação de outro tenant.');
    }
}
