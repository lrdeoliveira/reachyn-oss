<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🐛 CONSERTO: 21 modelos de imagem do Higgsfield, ATIVOS e já precificados, não apareciam em
 * seletor nenhum.
 *
 * O `reachyn:sync-higgsfield` cadastrava sem `subtype`, e todo seletor de imagem do front filtra
 * `subtype === 'text_to_image'` (aba Imagem, Estúdio, Personagens, Carrossel). O operador nomeou
 * cada modelo à mão no Filament (white-label), definiu os créditos e ativou — e o produto seguiu
 * oferecendo cinco. Falha silenciosa clássica: nada de errado no log, no lint ou no teste; a lista
 * só vinha curta.
 *
 * Aqui o backfill do que já está gravado; a origem foi consertada no comando (SUBTYPES).
 *
 * ⚠️ Só preenche subtype NULO. Não toca em preço, nome público, sort_order nem is_active — a
 * calibração do operador é a fonte da verdade, e o motor local (img-cli-mmx, sort_order 0) segue
 * em primeiro na lista.
 */
return new class extends Migration
{
    public function up(): void
    {
        $porTipo = ['image' => 'text_to_image', 'audio' => 'text_to_speech'];
        foreach ($porTipo as $kind => $subtype) {
            DB::table('gen_models')
                ->where('provider', 'cli-bridge')->where('kind', $kind)->whereNull('subtype')
                ->update(['subtype' => $subtype, 'updated_at' => now()]);
        }

        // Vídeo: quem aceita referência parte de um quadro (image_to_video); o resto é t2v. Os de
        // vídeo já apareciam (o seletor de vídeo não filtra subtype), mas ficar sem o campo é
        // dívida — qualquer tela nova que filtre repetiria o mesmo sumiço.
        foreach (DB::table('gen_models')->where('provider', 'cli-bridge')->where('kind', 'video')->whereNull('subtype')->get(['id', 'capabilities']) as $m) {
            $caps = json_decode((string) $m->capabilities, true);
            DB::table('gen_models')->where('id', $m->id)->update([
                'subtype' => ($caps['refs'] ?? false) ? 'image_to_video' : 'text_to_video',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sem volta: devolver o subtype pra null é reintroduzir o bug (modelo pago e invisível).
    }
};
