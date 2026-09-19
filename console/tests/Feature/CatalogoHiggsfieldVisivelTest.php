<?php

namespace Tests\Feature;

use App\Models\GenModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🐛 REGRESSÃO (achada em prod, 2026-08-30): 21 modelos de imagem do Higgsfield, ativos e já
 * precificados pelo operador, não apareciam em seletor nenhum — o sync gravava `subtype` nulo e
 * todo seletor de imagem do front filtra `subtype === 'text_to_image'`.
 *
 * Falha silenciosa: sem erro no log, no lint ou no teste. A lista só vinha curta.
 *
 * Regra que este teste tranca: modelo de imagem que entra pelo bridge nasce ENDEREÇÁVEL por um
 * seletor. Inativo e sem preço ele continua (secure-by-default) — invisível por não ter subtype, não.
 */
class CatalogoHiggsfieldVisivelTest extends TestCase
{
    use RefreshDatabase;

    public function test_modelo_de_imagem_do_bridge_nasce_com_subtype_de_seletor(): void
    {
        config(['services.cli_bridge.url' => 'http://bridge.test', 'services.cli_bridge.token' => 'x']);
        Http::fake(['*/v1/models' => Http::response(['models' => [
            ['job_type' => 'nano_banana_pro', 'display_name' => 'Nano Banana Pro', 'type' => 'image', 'refs' => true],
            ['job_type' => 'z_image', 'display_name' => 'Z Image', 'type' => 'image', 'refs' => false],
            ['job_type' => 'kling_2_5', 'display_name' => 'Kling', 'type' => 'video', 'refs' => true],
            ['job_type' => 'veo_t2v', 'display_name' => 'Veo', 'type' => 'video', 'refs' => false],
        ]], 200)]);

        $this->artisan('reachyn:sync-higgsfield')->assertExitCode(0);

        // Imagem: os dois no seletor de geração, com ou sem referência opcional.
        $this->assertSame('text_to_image', GenModel::where('slug', 'hf-nano-banana-pro')->value('subtype'));
        $this->assertSame('text_to_image', GenModel::where('slug', 'hf-z-image')->value('subtype'));
        // Vídeo: quem aceita referência parte de um quadro.
        $this->assertSame('image_to_video', GenModel::where('slug', 'hf-kling-2-5')->value('subtype'));
        $this->assertSame('text_to_video', GenModel::where('slug', 'hf-veo-t2v')->value('subtype'));
        // Secure-by-default segue de pé: entra inativo e sem preço.
        $novo = GenModel::where('slug', 'hf-z-image')->first();
        $this->assertFalse((bool) $novo->is_active);
        $this->assertNull($novo->cost_credits);
    }

    public function test_backfill_nao_mexe_no_que_o_operador_calibrou(): void
    {
        // Simula a linha como está em prod: nomeada, precificada, ativa — e invisível.
        $m = GenModel::create([
            'slug' => 'hf-seedream-v5-pro', 'display_name' => 'Detalhe máximo', 'kind' => 'image',
            'provider' => 'cli-bridge', 'provider_model_id' => 'higgsfield:seedream_v5_pro',
            'cost_credits' => 12, 'is_active' => true, 'sort_order' => 900, 'subtype' => null,
        ]);

        (require database_path('migrations/2026_08_30_101000_backfill_subtype_dos_modelos_do_bridge.php'))->up();

        $m->refresh();
        $this->assertSame('text_to_image', $m->subtype, 'o modelo precisa virar endereçável pelo seletor');
        $this->assertSame('Detalhe máximo', $m->display_name);
        $this->assertSame(12, (int) $m->cost_credits);
        $this->assertTrue((bool) $m->is_active);
        $this->assertSame(900, (int) $m->sort_order, 'o motor local (sort_order 0) continua em primeiro');
    }
}
