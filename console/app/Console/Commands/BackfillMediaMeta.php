<?php

namespace App\Console\Commands;

use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche largura/altura/peso nos itens de mídia JÁ EXISTENTES na galeria.
 *
 * POR QUE: a ficha técnica passou a ser gravada em 2026-07-20; tudo que foi gerado antes tem
 * só {id,kind,url,style,platforms}. O card então não mostra a linha — de propósito, pra não
 * exibir campo vazio. Este comando recupera o que É recuperável, lendo o arquivo no storage.
 *
 * ⚠️ O `model` (motor) NÃO é recuperável e este comando NÃO tenta adivinhar. Ele nunca foi
 * gravado, e inferir pelo tamanho seria chute disfarçado de dado: 2752x1536 sugere KIE e
 * 1536x1024 sugere cursor, mas 1280x720 é AMBÍGUO entre o mmx antigo e o image-01. Item velho
 * fica com dimensão/peso e sem motor — o que é honesto sobre o que sabemos.
 *
 * Idempotente: item que já tem `w` é pulado. Mídia cuja URL não existe mais no storage também
 * (imageMeta devolve vazio) — não inventa nem apaga.
 */
class BackfillMediaMeta extends Command
{
    protected $signature = 'reachyn:backfill-media-meta {--dry-run : só relata, não grava}';

    protected $description = 'Preenche largura/altura/peso nos itens de mídia antigos da galeria';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');
        $this->info($seco ? 'DRY-RUN — nada será gravado.' : 'Gravando…');

        $tot = $ok = $pulados = $semArquivo = 0;

        Draft::query()->whereNotNull('media')->chunkById(100, function ($drafts) use (&$tot, &$ok, &$pulados, &$semArquivo, $seco) {
            foreach ($drafts as $d) {
                $media = $d->media ?? [];
                if (! is_array($media) || $media === []) {
                    continue;
                }
                $mudou = false;
                foreach ($media as $i => $m) {
                    if (($m['kind'] ?? '') !== 'image') {
                        continue;
                    }
                    $tot++;
                    if (isset($m['w'])) {
                        $pulados++; // já tem ficha

                        continue;
                    }
                    $meta = Draft::imageMeta($m['url'] ?? null);
                    if ($meta === []) {
                        $semArquivo++; // URL morta / fora do nosso storage

                        continue;
                    }
                    $media[$i] = array_merge($m, $meta);
                    $mudou = true;
                    $ok++;
                }
                if ($mudou && ! $seco) {
                    // Sem lockForUpdate: é backfill de campo NOVO em item existente; se um job
                    // escrever no mesmo draft agora, ele acrescenta item, não reescreve estes.
                    DB::table('drafts')->where('id', $d->id)->update(['media' => json_encode($media)]);
                }
            }
        });

        $this->table(['imagens', 'preenchidas', 'já tinham', 'sem arquivo'], [[$tot, $ok, $pulados, $semArquivo]]);
        $this->info($seco ? 'DRY-RUN concluído.' : 'Backfill concluído.');

        return self::SUCCESS;
    }
}
