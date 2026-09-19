<?php

namespace Tests\Feature;

use App\Models\GenModel;
use App\Services\AnimationFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🎚 A qualidade PADRÃO do catálogo tem de chegar no createTask.
 *
 * `qualities`/`default_quality` são metadados (o seletor da UI) e saem do payload — mas o `extra`
 * DE DENTRO da qualidade escolhida é campo do provedor e tem de entrar. A aba Mídia sempre fez
 * isso (recebe a quality do usuário); a Animação só fazia o unset e mandava sem.
 *
 * Prod 2026-07-15: hailuo/02 (vid-natural-lite) EXIGE `resolution` → `422: Invalid parameter`.
 * Modelo ATIVO no catálogo, 100% quebrado na Animação, e ninguém sabia porque ele nunca tinha
 * sido usado. Os vizinhos (seedance-2-*) passavam por SORTE: o default do provedor é 720p, o
 * mesmo que o catálogo cobra. Sorte não é contrato — e quando o default do provedor não bate com
 * o que a gente precifica, o cliente paga por uma qualidade e recebe outra, em silêncio.
 */
class AnimationGenLineQualityTest extends TestCase
{
    use RefreshDatabase;

    private function flow(): AnimationFlow
    {
        return app(AnimationFlow::class);
    }

    /** @param array<string,mixed> $spec spec do motor (capabilities.<provider>) */
    private function modelo(array $spec, string $kind = 'video'): GenModel
    {
        return GenModel::factory()->create([
            'slug' => 'teste-'.$kind.'-'.uniqid(),
            'kind' => $kind,
            'provider' => 'magnific',
            'provider_model_id' => 'fornecedor/modelo',
            'capabilities' => ['magnific' => $spec],
        ]);
    }

    public function test_extra_da_qualidade_padrao_entra_no_payload(): void
    {
        $vm = $this->modelo([
            'refs_field' => 'image_url', 'duration_field' => 'duration', 'duration_string' => true,
            'qualities' => [
                ['key' => '512p', 'extra' => ['resolution' => '512P']],
                ['key' => '768p', 'extra' => ['resolution' => '768P']],
            ],
            'default_quality' => '768p',
        ]);

        $spec = $this->flow()->videoGenLine($vm)['video']['magnific'];

        // Sem isto o hailuo/02 devolve 422 — não é campo opcional na prática.
        $this->assertSame(['resolution' => '768P'], $spec['extra']);
    }

    /** Metadado de catálogo não é campo do createTask — mandar faria o provedor recusar. */
    public function test_qualities_e_default_quality_nao_vazam_pro_provedor(): void
    {
        $vm = $this->modelo([
            'refs_field' => 'image_url',
            'qualities' => [['key' => '720p', 'extra' => ['resolution' => '720p']]],
            'default_quality' => '720p',
        ]);

        $spec = $this->flow()->videoGenLine($vm)['video']['magnific'];

        $this->assertArrayNotHasKey('qualities', $spec);
        $this->assertArrayNotHasKey('default_quality', $spec);
    }

    /** A qualidade padrão COMPLETA o extra base (mode do Grok convive com o resolution). */
    public function test_extra_base_e_preservado_e_mesclado(): void
    {
        $vm = $this->modelo([
            'extra' => ['mode' => 'normal'],
            'qualities' => [['key' => '720p', 'extra' => ['resolution' => '720p']]],
            'default_quality' => '720p',
        ]);

        $spec = $this->flow()->videoGenLine($vm)['video']['magnific'];

        $this->assertSame(['mode' => 'normal', 'resolution' => '720p'], $spec['extra']);
    }

    /** default_quality inválido não pode virar "nenhuma qualidade" — cai na primeira. */
    public function test_default_quality_invalido_cai_na_primeira(): void
    {
        $vm = $this->modelo([
            'qualities' => [['key' => '480p', 'extra' => ['resolution' => '480p']]],
            'default_quality' => 'nao-existe',
        ]);

        $spec = $this->flow()->videoGenLine($vm)['video']['magnific'];

        $this->assertSame(['resolution' => '480p'], $spec['extra']);
    }

    /** Modelo sem qualities segue exatamente como era (vid-natural, vid-fluido…). */
    public function test_modelo_sem_qualities_nao_muda(): void
    {
        $vm = $this->modelo(['refs_field' => 'image_url', 'extra' => ['resolution' => '768P']]);

        $spec = $this->flow()->videoGenLine($vm)['video']['magnific'];

        $this->assertSame(['refs_field' => 'image_url', 'extra' => ['resolution' => '768P']], $spec);
    }

    /** O payload de IMAGEM passa pela mesma regra. */
    public function test_imagem_tambem_aplica_a_qualidade_padrao(): void
    {
        $gm = $this->modelo([
            'extra' => ['style' => 'DESIGN'],
            'qualities' => [['key' => 'quality', 'extra' => ['rendering_speed' => 'QUALITY']]],
            'default_quality' => 'quality',
        ], 'image');

        $spec = $this->flow()->imagePayloadBase($gm)['magnific'];

        $this->assertSame(['style' => 'DESIGN', 'rendering_speed' => 'QUALITY'], $spec['extra']);
    }
}
