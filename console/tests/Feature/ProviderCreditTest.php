<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Saldo da conta no agregador (/api/provider-credit).
 *
 * O teste que MAIS importa aqui é o 403 pro cliente: o payload cita o provedor pelo nome, o que
 * fura o white-label (guideline #6) se vazar. Em prod não existe usuário `client` pra exercitar
 * esse caminho manualmente — então ele só fica coberto aqui.
 */
class ProviderCreditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        Cache::flush();
        // Cada teste liga só o provedor que quer exercitar; os outros ficam desligados pra
        // não virarem chamada externa acidental.
        config([
            'services.elevenlabs.key' => '',
            'services.cli_bridge.url' => '',
        ]);
    }

    private function usuario(string $role): User
    {
        return User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => $role,
        ]);
    }

    public function test_cliente_nao_ve_o_saldo_do_provedor(): void
    {
        config(['services.elevenlabs.key' => 'chave-eleven']);
        Http::fake(['api.elevenlabs.io/*' => Http::response(['character_count' => 1, 'character_limit' => 2])]);

        $this->actingAs($this->usuario('client'))
            ->getJson('/api/provider-credit')
            ->assertForbidden();

        // Nem deve TENTAR falar com o provedor num pedido não autorizado.
        Http::assertNothingSent();
    }

    public function test_operador_ve_o_saldo(): void
    {
        config(['services.cli_bridge.url' => 'http://bridge.local']);
        Http::fake(['bridge.local/*' => Http::response(['providers' => ['higgsfield' => ['credits' => 9959.87]]])]);

        $this->actingAs($this->usuario('operator'))
            ->getJson('/api/provider-credit')
            ->assertOk()
            ->assertJson(['ok' => true, 'provider' => 'Higgsfield', 'balance' => 9959.87]);
    }

    /** Provedor fora do ar não pode virar erro na sidebar — devolve ok:false e o componente some. */
    public function test_falha_do_provedor_devolve_ok_false_sem_quebrar(): void
    {
        config(['services.cli_bridge.url' => 'http://bridge.local']);
        Http::fake(['bridge.local/*' => Http::response(null, 500)]);

        $this->actingAs($this->usuario('operator'))
            ->getJson('/api/provider-credit')
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_sem_chave_configurada_nao_chama_o_provedor(): void
    {
        // Nenhum provedor configurado (o setUp já zera os outros).
        Http::fake();

        $this->actingAs($this->usuario('operator'))
            ->getJson('/api/provider-credit')
            ->assertOk()
            ->assertJson(['ok' => false]);

        Http::assertNothingSent();
    }

    // ---- Painel completo (/api/admin/provider-balances) ----

    public function test_painel_recusa_nao_operador(): void
    {
        Http::fake();

        $this->actingAs($this->usuario('client'))
            ->getJson('/api/admin/provider-balances')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /** Um provedor fora do ar não pode apagar os outros: cada item carrega seu próprio ok/erro. */
    public function test_painel_isola_falha_de_um_provedor(): void
    {
        config([
            'services.elevenlabs.key' => 'chave-eleven',
            'services.cli_bridge.url' => 'http://bridge.local',
        ]);
        Http::fake([
            'bridge.local/*' => Http::response(null, 502), // bridge caído
            'api.elevenlabs.io/*' => Http::response([
                'tier' => 'creator',
                'character_count' => 28079,
                'character_limit' => 333478,
                'next_character_count_reset_unix' => 1785340800,
            ]),
        ]);

        $r = $this->actingAs($this->usuario('operator'))
            ->getJson('/api/admin/provider-balances')
            ->assertOk()
            ->json('provedores');

        $por = collect($r)->keyBy('id');

        // Higgsfield falhou — mas com erro próprio, sem derrubar os outros.
        $this->assertFalse($por['higgsfield']['ok']);
        $this->assertNotEmpty($por['higgsfield']['erro']);

        // ElevenLabs é cota de caracteres: o saldo é o que RESTA do limite.
        $this->assertTrue($por['elevenlabs']['ok']);
        $this->assertSame('caracteres', $por['elevenlabs']['tipo']);
        $this->assertSame(333478 - 28079, $por['elevenlabs']['saldo']);
        $this->assertSame(333478, $por['elevenlabs']['limite']);
        $this->assertSame(28079, $por['elevenlabs']['usado']);
        $this->assertNotNull($por['elevenlabs']['reset_em']);

        // Provedores sem endpoint de saldo aparecem declarados, nunca com número inventado.
        foreach (['minimax', 'google'] as $id) {
            $this->assertFalse($por[$id]['ok']);
            $this->assertNull($por[$id]['saldo']);
        }
    }

    /** 401 do ElevenLabs é escopo faltando (user_read) — não pode virar "sem saldo". */
    public function test_elevenlabs_401_vira_erro_de_permissao(): void
    {
        config(['services.elevenlabs.key' => 'chave-sem-escopo']);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'missing_permissions']], 401),
        ]);

        $eleven = collect(
            $this->actingAs($this->usuario('operator'))
                ->getJson('/api/admin/provider-balances')
                ->assertOk()
                ->json('provedores')
        )->firstWhere('id', 'elevenlabs');

        $this->assertFalse($eleven['ok']);
        $this->assertStringContainsString('permissão', $eleven['erro']);
    }

    /** A chave do provedor nunca pode sair no payload. */
    public function test_payload_nao_vaza_chave(): void
    {
        config(['services.elevenlabs.key' => 'segredo-eleven-123']);
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['character_count' => 1, 'character_limit' => 2]),
        ]);

        $bruto = $this->actingAs($this->usuario('operator'))
            ->getJson('/api/admin/provider-balances')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('segredo-eleven-123', $bruto);
    }
}
