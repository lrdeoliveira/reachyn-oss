<?php

namespace Database\Factories;

use App\Models\Draft;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Draft = a unidade de trabalho do Studio (pesquisa → mídia → publicação) e o que a galeria lista.
 *
 * @extends Factory<Draft>
 */
class DraftFactory extends Factory
{
    protected $model = Draft::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'keyword' => fake()->words(3, true),
            'status' => 'ready',
        ];
    }
}
