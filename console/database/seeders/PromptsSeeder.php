<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia a aba Prompts do primeiro tenant: 🎬 Roteiristas + 🎥 Diretores (texto, kind NULL)
 * e as personas de estilo visual (kind image/video), que alimentam o select de persona.
 *
 * POR QUE ISTO EXISTE: esses prompts nasceram como MIGRATIONS (2026_07_02/07_04), mas dependem de
 * um TENANT — que quem cria é o DatabaseSeeder, e o `migrate` roda ANTES do `db:seed`. Em banco do
 * zero as migrations pulavam (sem tenant) e ficavam MARCADAS como executadas → nunca mais rodavam
 * → prompts=0 pra sempre (afetava self-hosted novo, CI e disaster recovery; prod/dev já tinham os
 * dados de quando o tenant existia). Migration não é lugar de dado que depende do seeder.
 *
 * Em vez de DUPLICAR as ~240 linhas de personas (que divergiriam da migration com o tempo), este
 * seeder REEXECUTA o up() das próprias migrations, na ordem cronológica — a fonte do conteúdo
 * segue sendo uma só. Todas usam updateOrInsert por (tenant_id, title), então rodar de novo é
 * seguro: atualiza o que existe, cria o que falta, não duplica.
 *
 * A ordem importa: 05_0000 apaga o "🎬 Roteirista Mestre (Histórias)" que 04_0000 cria (foi
 * substituído pelo "🎬 Roteirista: Geral (Mestre)"). Rodar fora de ordem ressuscitaria o antigo.
 */
class PromptsSeeder extends Seeder
{
    /** Migrations de seed de prompt, em ordem cronológica (= ordem em que o migrate as roda). */
    private const MIGRATIONS = [
        '2026_07_02_040000_seed_roteirista_mestre_prompt',
        '2026_07_02_050000_seed_roteiristas_por_nicho',
        '2026_07_04_010000_seed_diretores_de_filme',
        '2026_07_04_021000_seed_roteiristas_e_diretores_novos',
        '2026_07_20_100100_seed_personas_de_estilo',
    ];

    public function run(): void
    {
        // Sem tenant não há dono pro prompt — as migrations fariam o mesmo no-op.
        if (! DB::table('tenants')->orderBy('id')->value('id')) {
            $this->command?->warn('PromptsSeeder: nenhum tenant — nada semeado.');

            return;
        }

        foreach (self::MIGRATIONS as $name) {
            $path = database_path("migrations/{$name}.php");
            if (! file_exists($path)) {
                continue; // migration removida no futuro: o conteúdo já terá outro dono
            }
            (require $path)->up();
        }

        $n = DB::table('prompts')->whereNull('kind')->count();
        $p = DB::table('prompts')->whereNotNull('kind')->count();
        $this->command?->info("PromptsSeeder: {$n} prompts de texto + {$p} personas de estilo.");
    }
}
