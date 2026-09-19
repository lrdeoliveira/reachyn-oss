<?php

namespace Tests\Feature;

use App\Models\ProviderKey;
use App\Models\Publication;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔗 Recuperação do histórico: as publicações gravadas sem link (ver ZernioPermalinkTest) ainda
 * guardam o `post_id`, então o link é buscável. Corrigir só daqui pra frente deixaria o arquivo
 * do cliente permanentemente sem lugar pra clicar.
 */
class BackfillPermalinksTest extends TestCase
{
    use RefreshDatabase;

    private function pub(array $networks): Publication
    {
        $t = Tenant::create(['name' => 'T', 'slug' => 't'.uniqid()]);

        return Publication::create([
            'tenant_id' => $t->id, 'source_type' => 'draft', 'source_id' => (string) random_int(1, 1e9),
            'keyword' => 'k', 'networks' => $networks, 'status' => 'publicado',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        ProviderKey::create(['provider' => 'zernio', 'api_key' => 'k_teste']);
    }

    public function test_preenche_o_link_que_faltava(): void
    {
        Http::fake(['*/posts/p1' => Http::response(['post' => [
            '_id' => 'p1', 'platforms' => [['platform' => 'youtube', 'platformPostUrl' => 'https://youtu.be/x']],
        ]])]);
        $p = $this->pub([['platform' => 'youtube', 'ok' => true, 'post_id' => 'p1', 'url' => null]]);

        $this->artisan('reachyn:backfill-permalinks')->assertSuccessful();

        $this->assertSame('https://youtu.be/x', $p->fresh()->networks[0]['url']);
    }

    public function test_nao_toca_no_que_ja_tem_link_nem_no_que_falhou(): void
    {
        // Idempotência e escopo: relê só o que precisa. Se batesse em tudo, uma segunda rodada
        // poderia sobrescrever link bom com null vindo de um post já expirado no provedor.
        Http::fake(['*' => Http::response(['post' => ['_id' => 'x', 'platforms' => []]])]);
        $p = $this->pub([
            ['platform' => 'x', 'ok' => true, 'post_id' => 'p1', 'url' => 'https://x.com/ja-tem'],
            ['platform' => 'reddit', 'ok' => false, 'post_id' => null, 'url' => null],
        ]);

        $this->artisan('reachyn:backfill-permalinks')->assertSuccessful();

        $redes = $p->fresh()->networks;
        $this->assertSame('https://x.com/ja-tem', $redes[0]['url']);
        $this->assertNull($redes[1]['url']);
        Http::assertNothingSent();
    }

    public function test_dry_run_nao_grava(): void
    {
        Http::fake(['*/posts/p1' => Http::response(['post' => [
            '_id' => 'p1', 'platforms' => [['platform' => 'youtube', 'platformPostUrl' => 'https://youtu.be/x']],
        ]])]);
        $p = $this->pub([['platform' => 'youtube', 'ok' => true, 'post_id' => 'p1', 'url' => null]]);

        $this->artisan('reachyn:backfill-permalinks', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($p->fresh()->networks[0]['url']);
    }
}
