<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `keyword` vira TEXT em drafts, approvals e publications.
 *
 * As 3 migrations originais declararam `$table->string('keyword')` = varchar(255), mas a keyword
 * carrega o BRIEFING do usuário e passa longe disso — em produção há registro com 9214 chars.
 * Alguém já tinha corrigido drafts/approvals **direto no banco de produção, sem migration**:
 * o git seguia dizendo varchar(255), o dev/CI/self-hosted continuavam com varchar(255) e prod
 * divergia em silêncio (dev estouraria 22001 num briefing que prod aceita).
 *
 * Pior: o ALTER manual esqueceu **publications**, que continuou varchar(255) — e o publish copia
 * a keyword do draft (Publication.php: 'keyword' => $keyword). Publicar um dos drafts de 9214
 * chars estouraria `22001 value too long`. Só não quebrou ainda porque publications está vazia.
 *
 * Esta migration versiona o estado que prod já tem e fecha o buraco de publications, alinhando
 * git = dev = prod. varchar(255) → text é widening: sem perda de dado, e no Postgres não reescreve
 * a tabela (mesma representação binária).
 */
return new class extends Migration
{
    private array $tables = ['drafts', 'approvals', 'publications'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return; // sqlite (suíte): string já é TEXT sem limite
        }
        foreach ($this->tables as $t) {
            if (! DB::selectOne('SELECT to_regclass(?) AS t', ["public.{$t}"])->t) {
                continue;
            }
            DB::statement("ALTER TABLE {$t} ALTER COLUMN keyword TYPE text");
            DB::statement("ALTER TABLE {$t} ALTER COLUMN keyword SET DEFAULT ''");
        }
    }

    public function down(): void
    {
        // Sem volta: truncaria silenciosamente as keywords > 255 que já existem em produção.
    }
};
