<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Já vem com uma Organization: Tenant::limits() lê `organization?->limits()` e, sem org,
     * cai em PLAN_LIMITS['starter'] (video=0) — o dispatch morreria no feature-gate.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
        ];
    }
}
