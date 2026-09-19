<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CreditTransaction;
use App\Models\GenModel;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\ModelSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 💳 Model sheet: o lote de shots cobra CUSTO-UNITÁRIO × QUANTIDADE — nunca ao quadrado.
 *
 * 🐛 REGRESSÃO (achada no extrato de prod, 2026-07-22): a reserva passava a QUANTIDADE nos dois
 * argumentos — peso `$perWeight * $n` E custo `$perCost * $n` — mas o UsageService já multiplica
 * `custo × peso`. Resultado: $perCost × n². A folha da personagem Mel (31 shots a 9 créditos)
 * debitou 8.649 em vez de 279 — 31× a mais. O estorno do job repetia o mesmo erro (n² de volta),
 * então nem o caminho de falha fechava a conta.
 *
 * Regra que este teste tranca: peso = quantidade · custo = de UMA unidade.
 */
class ModelSheetCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTO_SHOT = 9; // img-realista, o motor dos shots

    private Tenant $marca;

    private User $cliente;

    private Character $personagem;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // a geração em si é do job; aqui só interessa a RESERVA
        $this->marca = Tenant::factory()->for(Organization::factory()->paying('studio', 100000))->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
        config(['services.engine.url' => 'http://engine.test', 'services.engine.admin_token' => 'x']);

        GenModel::updateOrCreate(
            ['slug' => ModelSheetService::SHEET_I2I_MODEL],
            [
                'display_name' => 'Realista', 'kind' => 'image', 'subtype' => 'image_to_image',
                'provider' => 'kie', 'provider_model_id' => 'seedream/4.5',
                'cost_credits' => self::CUSTO_SHOT, 'is_active' => true, 'min_plan' => null,
            ]
        );

        $this->personagem = Character::create([
            'tenant_id' => $this->marca->id,
            'name' => 'Mel',
            'description' => 'Uma abelha curiosa de olhos grandes',
            'style' => '3d',
            'base_url' => 'https://s3.example.com/public/reachyn/image/mel-base.jpg',
        ]);
    }

    /** Quantos shots de IA a folha desta personagem pede (a mesma conta que o controller reserva). */
    private function shotsEsperados(): int
    {
        $svc = app(ModelSheetService::class);

        return $svc->shotCount($svc->groups($this->personagem->fresh(), null, 'pt-BR'));
    }

    public function test_reserva_do_lote_cobra_custo_unitario_vezes_quantidade(): void
    {
        Http::fake([
            '*/v1/characterbible' => Http::response([], 500), // sem bíblia: segue com prompt genérico
            '*' => Http::response([], 200),
        ]);

        $this->actingAs($this->cliente)
            ->postJson("/api/characters/{$this->personagem->id}/sheet", [])
            ->assertOk();

        $n = $this->shotsEsperados();
        $this->assertGreaterThan(1, $n, 'a folha precisa de mais de 1 shot pra o teste ter sentido');

        $debito = CreditTransaction::where('tenant_id', $this->marca->id)
            ->where('reference_id', 'image')
            ->where('type', 'debit_generation')
            ->latest('id')->first();

        $this->assertNotNull($debito, 'a reserva do lote tem de aparecer no extrato');
        $this->assertSame(-(self::CUSTO_SHOT * $n), (int) $debito->delta, "cobrou {$debito->delta} — o certo é custo unitário × {$n} shots, não ao quadrado");
    }
}
