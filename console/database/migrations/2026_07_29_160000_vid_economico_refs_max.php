<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TETO DE REFERÊNCIAS do vid-economico (seedance-1.5-pro): 2, não 5.
 *
 * MEDIDO em 2026-07-29 no piloto "O Sinal na Colina": cena com 3 âncoras (quadro + RAPOSA +
 * MEL) morria no provedor com "The parameter `content` specified in the request is not valid:
 * expected at most one last frame image content but got 2 instead". O job tentava 3× e a cena
 * voltava sem clipe — duas das quatro cenas do filme. Com 2 âncoras passa.
 *
 * O `refsMax()` devolvia 5 pra todo modelo multi-ref (teto do agregador, não do modelo), então
 * o excesso só aparecia como falha lá na ponta. Agora o catálogo declara o teto real e tanto o
 * lote (/roteiro/render) quanto o clipe avulso (/generate/video) cortam/avisam antes de gastar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refsMax('vid-economico', 2);
    }

    public function down(): void
    {
        $this->refsMax('vid-economico', null);
    }

    /** Mexe SÓ na chave refs_max de capabilities.kie — o resto do catálogo fica como está. */
    private function refsMax(string $slug, ?int $max): void
    {
        $row = DB::table('gen_models')->where('slug', $slug)->first();
        if (! $row) {
            return;
        }
        $caps = json_decode((string) $row->capabilities, true) ?: [];
        if (! isset($caps['kie']) || ! is_array($caps['kie'])) {
            return;
        }
        if ($max === null) {
            unset($caps['kie']['refs_max']);
        } else {
            $caps['kie']['refs_max'] = $max;
        }
        DB::table('gen_models')->where('slug', $slug)->update(['capabilities' => json_encode($caps)]);
    }
};
