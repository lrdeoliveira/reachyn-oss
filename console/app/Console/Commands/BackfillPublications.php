<?php

namespace App\Console\Commands;

use App\Models\Approval;
use App\Models\Draft;
use App\Models\Publication;
use Illuminate\Console\Command;

/**
 * Backfill do arquivo de publicações (tabela publications) a partir do histórico antigo.
 *
 * Antes da tabela `publications`, o "histórico de publicados" era derivado on-the-fly de
 * Drafts (status=publicado, com publish.results) e Approvals (status=aprovado, com
 * meta.publish). Esta varredura reconstrói o snapshot PERMANENTE retroativamente, usando o
 * MESMO helper que o fluxo ao vivo (Publication::record), para não duplicar lógica.
 *
 * Multi-tenant: roda no CLI (sem usuário autenticado), então o global scope BelongsToTenant
 * já faz bypass e enxerga TODOS os tenants. Ainda assim usamos withoutGlobalScopes()
 * explícito para garantir a varredura cross-tenant independente do contexto.
 *
 * Idempotente: Publication::record faz updateOrCreate por (source_type, source_id), então
 * pode rodar quantas vezes quiser sem duplicar. Use --dry-run para só contar.
 */
class BackfillPublications extends Command
{
    protected $signature = 'reachyn:backfill-publications {--dry-run : Apenas conta o que seria gravado, sem persistir}';

    protected $description = 'Reconstrói o arquivo de publicações (publications) a partir de Drafts publicados e Approvals aprovados (histórico antigo).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $count = 0;

        // --- Drafts publicados (cross-tenant) ---
        Draft::withoutGlobalScopes()
            ->where('status', 'publicado')
            ->chunkById(200, function ($drafts) use ($dry, &$count): void {
                foreach ($drafts as $d) {
                    $results = $d->publish['results'] ?? null;
                    if (! is_array($results)) {
                        continue; // sem resultado de publicação → nada a arquivar
                    }

                    // content_text = textos não-vazios juntados (mesma convenção do PublishDraftJob).
                    $texts = array_filter($d->texts ?? [], fn ($v) => trim((string) $v) !== '');
                    $content = implode("\n\n---\n\n", array_map('strval', $texts));

                    $count++;
                    if (! $dry) {
                        Publication::record(
                            tenantId: (int) $d->tenant_id,
                            sourceType: 'draft',
                            sourceId: $d->id,
                            keyword: (string) $d->keyword,
                            contentText: $content,
                            media: $d->media ?? [],
                            results: $results,
                        );
                    }
                }
            });

        // --- Approvals aprovados (cross-tenant) ---
        Approval::withoutGlobalScopes()
            ->where('status', 'aprovado')
            ->chunkById(200, function ($approvals) use ($dry, &$count): void {
                foreach ($approvals as $a) {
                    $results = $a->meta['publish'] ?? null;
                    if (! is_array($results)) {
                        continue; // sem meta.publish → nada a arquivar
                    }

                    // media derivada de image_url/video_url (mesma convenção do ApprovalController).
                    $media = [];
                    if ($a->image_url) {
                        $media[] = ['kind' => 'image', 'url' => $a->image_url];
                    }
                    if ($a->video_url) {
                        $media[] = ['kind' => 'video', 'url' => $a->video_url];
                    }

                    $count++;
                    if (! $dry) {
                        Publication::record(
                            tenantId: (int) $a->tenant_id,
                            sourceType: 'approval',
                            sourceId: $a->id,
                            keyword: (string) $a->keyword,
                            contentText: (string) $a->preview_text,
                            media: $media,
                            results: $results,
                        );
                    }
                }
            });

        $this->info("Backfill de publicações: {$count} registros".($dry ? ' (dry-run, nada gravado)' : ' criados/atualizados').'.');

        return self::SUCCESS;
    }
}
