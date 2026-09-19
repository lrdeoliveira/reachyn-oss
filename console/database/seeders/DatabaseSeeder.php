<?php

namespace Database\Seeders;

use App\Models\Organization;
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
        // ORG cliente-zero (dogfooding RedFoxCode) — plano interno 'unlimited' + exempt (não debita).
        $redfoxOrg = Organization::firstOrCreate(
            ['slug' => 'redfox-org'],
            ['name' => 'RedFoxCode', 'plan' => 'unlimited', 'billing_status' => 'exempt'],
        );

        // Primeira MARCA da org (conteúdo).
        $redfox = Tenant::firstOrCreate(
            ['slug' => 'redfox'],
            ['name' => 'RedFoxCode', 'organization_id' => $redfoxOrg->id],
        );

        // Operador único (Luciano) — acessa o painel /admin.
        // email_verified_at: o User é MustVerifyEmail, então SEM isto o seeder cria uma conta que
        // não passa do "Verifique seu e-mail" — e em dev não há SMTP pra receber o link. Conta
        // semeada = confiável por definição (quem roda o seeder já é dono do banco).
        User::firstOrCreate(
            ['email' => 'lrdeoliveira@live.com'],
            [
                'name' => 'Luciano',
                'organization_id' => $redfoxOrg->id,
                'tenant_id' => $redfox->id,
                'role' => 'operator',
                'password' => Hash::make('change-me'),
                'email_verified_at' => now(),
            ],
        );

        // Prompts (🎬 Roteiristas + 🎥 Diretores) da aba Prompts. Precisa rodar DEPOIS do tenant
        // acima: as migrations que os criam rodam antes de existir tenant e pulam pra sempre —
        // em banco do zero o app ficava sem nenhuma persona. Idempotente.
        $this->call(PromptsSeeder::class);

        // Catálogo de modelos. Idempotente (updateOrCreate por slug).
        //
        // ⚠️ Os seeders do agregador (KieModelsSeeder, KieImageSeeder, KieVideoSeeder,
        // KieTextSeeder) NÃO rodam mais: o agregador saiu em 2026-08-03 e as migrations
        // desativaram as 110 linhas dele. Continuar semeando reinstalaria o catálogo morto —
        // e um `db:seed` de rotina traria de volta motores que ninguém pode chamar, com preço,
        // prontos pra alguém ativar no Filament por engano. Os arquivos ficam no repo como
        // registro histórico (ver docs/SAIDA-DO-AGREGADOR.md); o que sai é a CHAMADA.
        $this->call(CurrentModelsSeeder::class);

        // Templates GLOBAIS de criação (aba Rápido / Quick Start — benchmark Nordy+RunningHub).
        // tenant_id NULL = visíveis a todas as marcas. Idempotente (updateOrCreate por slug).
        $this->call(CreationTemplatesSeeder::class);
    }
}
