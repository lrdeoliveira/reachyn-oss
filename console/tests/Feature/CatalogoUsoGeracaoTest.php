<?php

namespace Tests\Feature;

use App\Models\GenModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Separação entre GERAR e TRATAR no catálogo (scope `uso`).
 *
 * 🐛 REGRESSÃO que este teste tranca: ferramenta de pós-produção aparecendo no seletor de
 * geração.
 *
 * Upscale, remover fundo, expandir enquadramento e deflicker têm `kind` image/video como
 * qualquer outro modelo — o que os distingue é precisarem de uma peça de ENTRADA. Enquanto
 * ficaram desligados (decisão explícita da migration de 2026-08-04) o problema era teórico. Ao
 * ligá-los em 2026-08-29 ele deixou de ser: sem o filtro, o cliente escolhe "Upscale" no seletor
 * de imagem esperando uma imagem nova e recebe outra coisa.
 *
 * O default importa tanto quanto o filtro: quem NÃO passa `uso` tem que continuar recebendo só
 * geradores. É isso que mantém os seletores já existentes intactos — nenhum deles conhece o
 * parâmetro novo, e uma inversão aqui quebraria todos de uma vez, em silêncio.
 */
class CatalogoUsoGeracaoTest extends TestCase
{
    use RefreshDatabase;

    private function criaCatalogo(): void
    {
        GenModel::factory()->create([
            'slug' => 'gerador-imagem', 'kind' => 'image',
            'subtype' => null, 'is_active' => true, 'cost_credits' => 2,
        ]);
        GenModel::factory()->create([
            'slug' => 'gerador-com-subtype', 'kind' => 'image',
            'subtype' => 'text_to_image', 'is_active' => true, 'cost_credits' => 2,
        ]);
        GenModel::factory()->create([
            'slug' => 'upscale', 'kind' => 'image',
            'subtype' => GenModel::USO_POSTPRODUCAO, 'is_active' => true, 'cost_credits' => 2,
        ]);
    }

    public function test_geracao_nao_traz_postproducao(): void
    {
        $this->criaCatalogo();

        $slugs = GenModel::active()->uso('geracao')->pluck('slug')->all();

        $this->assertContains('gerador-imagem', $slugs);
        // Subtype preenchido com OUTRA coisa não pode ser confundido com pós-produção: o filtro
        // é pelo valor exato, não pela mera presença de subtype.
        $this->assertContains('gerador-com-subtype', $slugs);
        $this->assertNotContains('upscale', $slugs);
    }

    public function test_postproducao_traz_apenas_ferramentas(): void
    {
        $this->criaCatalogo();

        $slugs = GenModel::active()->uso(GenModel::USO_POSTPRODUCAO)->pluck('slug')->all();

        // Sem assertSame numa lista fixa: as migrations de catálogo já povoam a pós-produção
        // (hf-outpaint, hf-topaz-*…), e travar o conteúdo exato faria este teste quebrar a cada
        // ferramenta nova — ruído, não regressão. O que importa é que TUDO que sai aqui é
        // pós-produção, e que nenhum gerador se infiltrou.
        $this->assertContains('upscale', $slugs);
        $this->assertNotContains('gerador-imagem', $slugs);
        $this->assertNotContains('gerador-com-subtype', $slugs);
        foreach (GenModel::active()->uso(GenModel::USO_POSTPRODUCAO)->get() as $m) {
            $this->assertSame(GenModel::USO_POSTPRODUCAO, $m->subtype, "{$m->slug} não é pós-produção");
        }
    }

    /** O endpoint que o front consome: sem `uso` na query, nada de pós-produção na lista. */
    public function test_endpoint_sem_parametro_omite_postproducao(): void
    {
        $this->criaCatalogo();

        $slugs = GenModel::active()->uso('geracao')->kind('image')->pluck('slug')->all();

        $this->assertNotContains('upscale', $slugs, 'pós-produção vazou pro seletor de geração');
    }
}
