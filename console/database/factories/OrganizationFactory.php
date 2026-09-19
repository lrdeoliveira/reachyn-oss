<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Default: plano com TUDO incluso e sem cobrança. É o que a maioria dos testes quer —
     * `starter` (o default do banco) tem video=0/short=0 e barra o dispatch no feature-gate
     * de Tenant::limits(), o que costuma mascarar o que o teste realmente mede.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'plan' => 'unlimited',
            'billing_status' => 'exempt', // debit() vira no-op (logExempt) — nenhum teste precisa de saldo
            'credit_balance' => 0,
        ];
    }

    /** Org pagante de verdade: use quando o teste for SOBRE cobrança/saldo. */
    public function paying(string $plan = 'pro', int $balance = 100000): static
    {
        return $this->state(fn () => [
            'plan' => $plan,
            'billing_status' => 'active',
            'credit_balance' => $balance,
        ]);
    }

    /** Plano só-imagem: video=0 no PLAN_LIMITS — para testar o gate de feature por plano. */
    public function starter(): static
    {
        return $this->state(fn () => ['plan' => 'starter', 'billing_status' => 'active']);
    }
}
