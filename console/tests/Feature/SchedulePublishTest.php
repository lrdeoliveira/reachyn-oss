<?php

namespace Tests\Feature;

use App\Jobs\PublishDraftJob;
use App\Models\Draft;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🗓️ Publicação AGENDADA — /api/studio/schedule + worker reachyn:publish-due.
 *
 * O agendamento reusa o `publish` do submit (state='scheduled') justamente pra herdar o
 * anti-duplo-publish (AUD-004). O teste que mais importa aqui é o do claim: duas rodadas do
 * worker se cruzando NÃO podem publicar o mesmo rascunho duas vezes.
 */
class SchedulePublishTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marca = Tenant::factory()->create();
        TenantScope::flushActiveTenantCache();
        $this->cliente = User::factory()->create([
            'tenant_id' => $this->marca->id,
            'organization_id' => $this->marca->organization_id,
            'role' => 'client',
        ]);
    }

    private function rascunho(array $attrs = []): Draft
    {
        return Draft::factory()->create(array_merge([
            'tenant_id' => $this->marca->id,
            'texts' => ['instagram' => 'legenda pronta', 'linkedin' => 'outra legenda'],
        ], $attrs));
    }

    public function test_agenda_e_nao_publica_agora(): void
    {
        Queue::fake();
        $d = $this->rascunho();
        $quando = now()->addHours(3);

        $this->actingAs($this->cliente)->postJson('/api/studio/schedule', [
            'draftId' => $d->id,
            'scheduledAt' => $quando->toIso8601String(),
            'platforms' => ['instagram'],
        ])->assertOk()->assertJson(['ok' => true, 'state' => 'scheduled', 'platforms' => ['instagram']]);

        $d->refresh();
        $this->assertSame('scheduled', $d->publish['state']);
        $this->assertSame(['instagram'], $d->publish['platforms']);
        $this->assertNotNull($d->scheduled_at);
        Queue::assertNothingPushed(); // agendar não dispara
    }

    public function test_recusa_data_no_passado_e_alem_de_um_ano(): void
    {
        $d = $this->rascunho();
        foreach ([now()->subDay(), now()->addYears(2)] as $quando) {
            $this->actingAs($this->cliente)->postJson('/api/studio/schedule', [
                'draftId' => $d->id, 'scheduledAt' => $quando->toIso8601String(),
            ])->assertStatus(422);
        }
        // data ilegível também
        $this->actingAs($this->cliente)->postJson('/api/studio/schedule', [
            'draftId' => $d->id, 'scheduledAt' => 'amanhã de tarde',
        ])->assertStatus(422);
    }

    public function test_nao_agenda_sem_texto(): void
    {
        $d = $this->rascunho(['texts' => []]);
        $this->actingAs($this->cliente)->postJson('/api/studio/schedule', [
            'draftId' => $d->id, 'scheduledAt' => now()->addHour()->toIso8601String(),
        ])->assertStatus(400);
    }

    public function test_nao_reagenda_publicacao_em_andamento(): void
    {
        $d = $this->rascunho(['publish' => ['state' => 'running']]);
        $this->actingAs($this->cliente)->postJson('/api/studio/schedule', [
            'draftId' => $d->id, 'scheduledAt' => now()->addHour()->toIso8601String(),
        ])->assertStatus(409);
    }

    public function test_cancela_agendamento(): void
    {
        $d = $this->rascunho(['publish' => ['state' => 'scheduled'], 'scheduled_at' => now()->addHour()]);

        $this->actingAs($this->cliente)
            ->postJson('/api/studio/unschedule', ['draftId' => $d->id])
            ->assertOk();

        $d->refresh();
        $this->assertNull($d->publish);
        $this->assertNull($d->scheduled_at);
    }

    public function test_nao_cancela_o_que_ja_esta_publicando(): void
    {
        // Deixar cancelar aqui daria a impressão falsa de ter impedido a publicação — o job já saiu.
        $d = $this->rascunho(['publish' => ['state' => 'running'], 'scheduled_at' => null]);
        $this->actingAs($this->cliente)
            ->postJson('/api/studio/unschedule', ['draftId' => $d->id])
            ->assertStatus(409);
    }

    public function test_worker_dispara_o_vencido_e_ignora_o_futuro(): void
    {
        Queue::fake();
        $vencido = $this->rascunho(['publish' => ['state' => 'scheduled', 'platforms' => ['instagram']], 'scheduled_at' => now()->subMinutes(2)]);
        $futuro = $this->rascunho(['publish' => ['state' => 'scheduled', 'platforms' => ['instagram']], 'scheduled_at' => now()->addHour()]);

        $this->artisan('reachyn:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishDraftJob::class, 1);
        $vencido->refresh();
        $this->assertSame('running', $vencido->publish['state']);
        $this->assertNull($vencido->scheduled_at); // consumido
        $futuro->refresh();
        $this->assertSame('scheduled', $futuro->publish['state']);
    }

    public function test_duas_rodadas_do_worker_nao_publicam_duas_vezes(): void
    {
        // O claim condicional é o que segura isto. Sem ele, uma rodada lenta cruzando com a
        // seguinte publicaria o mesmo rascunho de novo — e publicação não tem desfazer.
        Queue::fake();
        $this->rascunho(['publish' => ['state' => 'scheduled', 'platforms' => ['instagram']], 'scheduled_at' => now()->subMinute()]);

        $this->artisan('reachyn:publish-due')->assertSuccessful();
        $this->artisan('reachyn:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishDraftJob::class, 1);
    }

    public function test_dry_run_nao_dispara_nada(): void
    {
        Queue::fake();
        $d = $this->rascunho(['publish' => ['state' => 'scheduled', 'platforms' => ['instagram']], 'scheduled_at' => now()->subMinute()]);

        $this->artisan('reachyn:publish-due --dry-run')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame('scheduled', $d->refresh()->publish['state']);
    }
}
