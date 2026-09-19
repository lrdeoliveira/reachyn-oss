<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔒 Isolamento multi-tenant — a invariante central do produto, e até 2026-07-15 sem NENHUM teste.
 *
 * São 3 barreiras (ver AUD-020 no TenantScope): (1) `where('tenant_id')` no controller, (2) este
 * global scope, (3) RLS no Postgres. Aqui trava a barreira 2, que é a única exercitável na suíte
 * — o RLS é no-op em SQLite (ver TenantIsolationRlsTest, que roda contra Postgres).
 *
 * ⚠️ METADE DESTES TESTES TRAVA O *FAIL-OPEN*, e isso é de propósito. O scope NÃO filtra para
 * operador/CLI/job/webhook — quem "consertar" isso achando que é vazamento derruba o painel
 * /admin (que é cross-tenant por definição), os jobs de fila e os webhooks do Stripe, que rodam
 * sem sessão. O controle primário é o controller; este scope é rede de segurança.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marcaA;

    private Tenant $marcaB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marcaA = Tenant::factory()->create();
        $this->marcaB = Tenant::factory()->create();
        Draft::factory()->count(2)->create(['tenant_id' => $this->marcaA->id]);
        Draft::factory()->create(['tenant_id' => $this->marcaB->id]);
    }

    private function clienteDe(Tenant $t): User
    {
        // Um teste = um request. Sem o flush, a memoização por id de usuário atravessa os testes
        // (RefreshDatabase recicla o id 1) e o cliente da marca B herdaria a marca A.
        TenantScope::flushActiveTenantCache();

        return User::factory()->create(['tenant_id' => $t->id, 'organization_id' => $t->organization_id, 'role' => 'client']);
    }

    /** O que o produto inteiro depende: o cliente de uma marca não enxerga a outra. */
    public function test_cliente_nao_ve_draft_de_outra_marca(): void
    {
        $this->actingAs($this->clienteDe($this->marcaA));

        $ids = Draft::pluck('tenant_id')->unique()->all();

        $this->assertSame([$this->marcaA->id], $ids, 'vazou draft de outra marca');
        $this->assertSame(2, Draft::count());
    }

    /** Buscar pelo ID EXATO do vizinho também não pode achar — senão /drafts/{id} vaza. */
    public function test_cliente_nao_acha_draft_da_outra_marca_nem_pelo_id(): void
    {
        $alheio = Draft::withoutGlobalScopes()->where('tenant_id', $this->marcaB->id)->firstOrFail();
        $this->actingAs($this->clienteDe($this->marcaA));

        $this->assertNull(Draft::find($alheio->id));
    }

    /** FAIL-OPEN INTENCIONAL: o painel /admin é cross-tenant — filtrar aqui o quebraria. */
    public function test_operador_ve_todas_as_marcas(): void
    {
        $op = User::factory()->create(['role' => 'operator', 'tenant_id' => $this->marcaA->id]);
        $this->actingAs($op);

        $this->assertSame(3, Draft::count(), 'operador precisa enxergar cross-tenant (painel /admin)');
    }

    /** FAIL-OPEN INTENCIONAL: job de fila e CLI rodam sem sessão — filtrar aqui os quebraria. */
    public function test_sem_usuario_autenticado_nao_filtra(): void
    {
        $this->assertSame(3, Draft::count(), 'job/CLI/webhook rodam sem auth e precisam ver tudo');
    }

    /** O tenant_id do cliente é preenchido sozinho — controller que esquecer não cria órfão. */
    public function test_creating_preenche_o_tenant_do_cliente(): void
    {
        $this->actingAs($this->clienteDe($this->marcaB));

        $d = Draft::create(['keyword' => 'sem tenant explícito']);

        $this->assertSame($this->marcaB->id, $d->tenant_id);
    }

    /** ...mas NUNCA sobrescreve um tenant_id explícito: é assim que o operador cria pra um cliente. */
    public function test_creating_nao_sobrescreve_tenant_explicito(): void
    {
        $this->actingAs($this->clienteDe($this->marcaA));

        $d = Draft::create(['keyword' => 'escolhido a dedo', 'tenant_id' => $this->marcaB->id]);

        $this->assertSame($this->marcaB->id, $d->tenant_id);
    }
}
