<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Tenant cliente-zero (conta demo) — plano studio (premium liberado).
        $redfox = Tenant::firstOrCreate(
            ['slug' => 'redfox'],
            ['name' => 'Example Org', 'plan' => 'studio', 'billing_status' => 'exempt'],
        );

        // Operador único (Luciano) — acessa o painel /admin.
        User::firstOrCreate(
            ['email' => 'lrdeoliveira@live.com'],
            [
                'name' => 'Luciano',
                'tenant_id' => $redfox->id,
                'role' => 'operator',
                'password' => Hash::make('change-me'),
            ],
        );
    }
}
