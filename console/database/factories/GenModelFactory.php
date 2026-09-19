<?php

namespace Database\Factories;

use App\Models\GenModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * O catálogo de modelos real vive em seeders (CurrentModelsSeeder, Kie*Seeder), que NÃO rodam
 * sob RefreshDatabase. Sem um GenModel ativo, AnimationFlow::videoModel() não resolve nada e
 * todo dispatch morre com "gere o keyframe da cena antes de animar" — erro enganoso, que não
 * tem relação com a causa real.
 *
 * @extends Factory<GenModel>
 */
class GenModelFactory extends Factory
{
    protected $model = GenModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'display_name' => fake()->words(2, true),
            'kind' => 'video',
            'provider' => 'kie',
            'provider_model_id' => 'test-model',
            'cost_credits' => 105,
            'is_active' => true, // a coluna nasce false (secure-by-default); em teste queremos usável
        ];
    }

    /** Modelo de vídeo do tier `padrao` — o que AnimationFlow::VID_MODELS resolve por default. */
    public function videoPadrao(): static
    {
        return $this->state(fn () => ['slug' => 'vid-natural', 'kind' => 'video']);
    }

    public function image(string $slug = 'img-natural'): static
    {
        return $this->state(fn () => ['slug' => $slug, 'kind' => 'image']);
    }

    /** Desligado: para testar que modelo inativo não é selecionável. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
