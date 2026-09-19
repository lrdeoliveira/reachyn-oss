<?php

namespace Tests\Feature;

use App\Jobs\MotionTransferJob;
use App\Models\AnimationProject;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\TenantScope;
use App\Services\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 🕺 Motion transfer (POST /api/animation/{id}/motion) — RunningHub, premium. Trava: exige keyframe
 * da cena, vídeo-guia do nosso S3, cobra crédito e despacha o job assíncrono; sem crédito = 402.
 */
class MotionTransferTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $marca;

    private User $cliente;

    private string $guia = 'https://s3.example.com/public/reachyn/video/guia.mp4';

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
        Queue::fake();
    }

    private function projetoComKeyframe(): AnimationProject
    {
        return AnimationProject::factory()->create([
            'tenant_id' => $this->marca->id,
            'storyboard' => [['keyframe_url' => 'https://s3.example.com/public/reachyn/image/kf.png']],
        ]);
    }

    private function mockUsage(bool $consume): void
    {
        $this->mock(UsageService::class, function ($m) use ($consume) {
            $m->shouldReceive('tryConsume')->andReturn($consume);
            $m->shouldReceive('refund');
            $m->shouldReceive('weightFor')->andReturn(1);
        });
    }

    public function test_despacha_job_e_marca_generating(): void
    {
        $this->mockUsage(true);
        $p = $this->projetoComKeyframe();

        $this->actingAs($this->cliente)
            ->postJson("/api/animation/{$p->id}/motion", ['index' => 0, 'motionRefUrl' => $this->guia])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'generating']);

        Queue::assertPushed(MotionTransferJob::class, function ($job) use ($p) {
            return $job->projectId === $p->id && $job->index === 0
                && $job->workflowId === (string) config('services.motion.workflow_id')
                && collect($job->nodeInfoList)->firstWhere('nodeId', (string) config('services.motion.image_node'))['fieldValue'] === 'https://s3.example.com/public/reachyn/image/kf.png';
        });
        $this->assertSame('generating', array_values((array) $p->fresh()->storyboard)[0]['video_status']);
    }

    public function test_exige_keyframe_da_cena(): void
    {
        $this->mockUsage(true);
        $p = AnimationProject::factory()->create(['tenant_id' => $this->marca->id, 'storyboard' => [['keyframe_url' => '']]]);

        $this->actingAs($this->cliente)
            ->postJson("/api/animation/{$p->id}/motion", ['index' => 0, 'motionRefUrl' => $this->guia])
            ->assertStatus(422);
        Queue::assertNotPushed(MotionTransferJob::class);
    }

    public function test_recusa_guia_de_fora(): void
    {
        $this->mockUsage(true);
        $p = $this->projetoComKeyframe();

        $this->actingAs($this->cliente)
            ->postJson("/api/animation/{$p->id}/motion", ['index' => 0, 'motionRefUrl' => 'https://evil.example.com/x.mp4'])
            ->assertStatus(422);
        Queue::assertNotPushed(MotionTransferJob::class);
    }

    public function test_sem_credito_402(): void
    {
        $this->mockUsage(false);
        $p = $this->projetoComKeyframe();

        $this->actingAs($this->cliente)
            ->postJson("/api/animation/{$p->id}/motion", ['index' => 0, 'motionRefUrl' => $this->guia])
            ->assertStatus(402);
        Queue::assertNotPushed(MotionTransferJob::class);
    }

    public function test_nao_alcanca_projeto_de_outra_marca(): void
    {
        $this->mockUsage(true);
        $outra = Tenant::factory()->create();
        $p = AnimationProject::factory()->create([
            'tenant_id' => $outra->id,
            'storyboard' => [['keyframe_url' => 'https://s3.example.com/public/reachyn/image/kf.png']],
        ]);

        $this->actingAs($this->cliente)
            ->postJson("/api/animation/{$p->id}/motion", ['index' => 0, 'motionRefUrl' => $this->guia])
            ->assertNotFound();
    }
}
