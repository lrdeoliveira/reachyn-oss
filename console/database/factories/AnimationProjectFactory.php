<?php

namespace Database\Factories;

use App\Models\AnimationProject;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Projeto de desenho animado. O default é o começo do fluxo (`parsing`, sem elementos); os
 * states levam às etapas seguintes sem precisar rodar os jobs.
 *
 * Etapas do auto (AnimationFlow::advance): elementos → keyframes → vídeos → montagem.
 *
 * @extends Factory<AnimationProject>
 */
class AnimationProjectFactory extends Factory
{
    protected $model = AnimationProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'title' => fake()->sentence(3),
            'mode' => 'animacao',
            'sequence_mode' => 'solto',
            'script' => fake()->paragraph(),
            'status' => 'parsing',
            'auto' => false,
            'elements' => null,
            'storyboard' => null,
            'final_url' => '',
        ];
    }

    /** Modo automático ligado — o "agente" que encadeia as etapas (e onde o loop de 2026-07-15 vivia). */
    public function auto(): static
    {
        return $this->state(fn () => ['auto' => true]);
    }

    /**
     * Etapa de VÍDEO: elementos e keyframes prontos, faltando os clipes.
     *
     * @param  list<array<string,mixed>>  $scenes  storyboard já com keyframe_url
     */
    public function animating(array $scenes): static
    {
        return $this->state(fn () => [
            'status' => 'animating',
            'elements' => ['characters' => [['name' => 'herói', 'ref_url' => 'https://s3/ref.jpg']]],
            'storyboard' => $scenes,
        ]);
    }

    /** Cena com o clipe pronto. */
    public static function scenePronta(int $i = 1): array
    {
        return ['keyframe_url' => "https://s3/k{$i}.jpg", 'video_url' => "https://s3/v{$i}.mp4"];
    }

    /** Cena aguardando o clipe (nunca tentada). */
    public static function scenePendente(int $i = 2): array
    {
        return ['keyframe_url' => "https://s3/k{$i}.jpg", 'video_url' => ''];
    }

    /** Cena cujo clipe FALHOU — a que fazia o auto re-despachar pra sempre. */
    public static function sceneComErro(int $i = 2): array
    {
        return ['keyframe_url' => "https://s3/k{$i}.jpg", 'video_url' => '', 'video_status' => 'error'];
    }

    /** Cena com clipe em geração (job rodando). */
    public static function sceneGerando(int $i = 2): array
    {
        return ['keyframe_url' => "https://s3/k{$i}.jpg", 'video_url' => '', 'video_status' => 'generating'];
    }
}
