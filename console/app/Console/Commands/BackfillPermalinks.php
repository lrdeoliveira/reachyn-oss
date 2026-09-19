<?php

namespace App\Console\Commands;

use App\Models\Publication;
use App\Services\ZernioService;
use Illuminate\Console\Command;

/**
 * Preenche o LINK das publicações que foram ao ar sem ele.
 *
 * POR QUE EXISTE: o permalink vinha do campo errado (`permalink`/`postUrl` em vez de
 * `platformPostUrl`), então TODA publicação já feita foi gravada com `url = null` — 6 de 6 em
 * produção quando isto foi escrito. A peça está no ar, o arquivo tem o `post_id`, e o link é
 * recuperável: o Zernio devolve o post inteiro por ID. Corrigir só daqui pra frente deixaria o
 * histórico do cliente permanentemente sem lugar pra clicar.
 *
 * Idempotente e conservador: só toca em rede que publicou OK, tem `post_id` e está SEM url. Rede
 * que já tem link, que falhou, ou cujo post o Zernio não devolve mais fica exatamente como está.
 */
class BackfillPermalinks extends Command
{
    protected $signature = 'reachyn:backfill-permalinks {--dry-run : Apenas mostra o que seria preenchido, sem gravar}';

    protected $description = 'Busca no Zernio o link das publicações gravadas sem permalink e preenche o arquivo.';

    public function handle(ZernioService $zernio): int
    {
        $dry = (bool) $this->option('dry-run');
        $achados = $intactos = 0;

        Publication::withoutGlobalScopes()->chunkById(100, function ($pubs) use ($zernio, $dry, &$achados, &$intactos): void {
            foreach ($pubs as $pub) {
                $redes = is_array($pub->networks) ? $pub->networks : [];
                $mudou = false;
                foreach ($redes as $i => $n) {
                    if (empty($n['ok']) || empty($n['post_id']) || ! empty($n['url'])) {
                        continue;
                    }
                    $url = $zernio->postUrl((string) $n['post_id']);
                    if ($url === null) {
                        $intactos++;
                        $this->line(sprintf('  · #%d %s — sem link no Zernio (mantido)', $pub->id, $n['platform'] ?? '?'));

                        continue;
                    }
                    $redes[$i]['url'] = $url;
                    $mudou = true;
                    $achados++;
                    $this->info(sprintf('  ✓ #%d %s → %s', $pub->id, $n['platform'] ?? '?', $url));
                }
                if ($mudou && ! $dry) {
                    $pub->networks = $redes;
                    $pub->save();
                }
            }
        });

        $this->newLine();
        $this->info(sprintf('%s%d link(s) recuperado(s); %d rede(s) seguem sem link.',
            $dry ? '[dry-run] ' : '', $achados, $intactos));

        return self::SUCCESS;
    }
}
