<?php

use App\Support\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Estende o fix de 2026_06_18 (fix_rls_nullif_cast) a `characters` e `profiles`.
 *
 * O 06_18 trocou o cast direto por NULLIF em 5 tabelas, mas as tabelas criadas DEPOIS dele
 * (characters 06_28, profiles 06_26) nasceram com o cast quebrado de novo — a correção não
 * era um padrão compartilhado, então cada create_*_table recopiou a versão antiga da condição.
 *
 * Sintoma: `current_setting('app.current_tenant', true)::bigint` estoura com 22P02 quando o
 * setting é '' — e ele É '' em todo request de OPERADOR (TenantScope::resolveClientTenantId
 * devolve null → bypass), jobs, CLI e webhooks. Ou seja: /api/characters e /api/profiles
 * respondiam 500 para o operador. O `OR ... = ''` antes do cast não protege: o Postgres não
 * garante ordem de avaliação num OR e avalia o cast mesmo assim.
 *
 * Mantém a MESMA semântica do 06_18 (fail-open quando vazio/nulo) — aqui só o crash é corrigido.
 * Idempotente (DROP IF EXISTS + CREATE).
 */
return new class extends Migration
{
    private array $tables = ['characters', 'profiles'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $t) {
            // A tabela pode não existir se o fix rodar antes do create (ordem lexicográfica garante
            // que não, mas migrate:fresh em banco parcial não deve quebrar).
            if (! DB::selectOne('SELECT to_regclass(?) AS t', ["public.{$t}"])->t) {
                continue;
            }
            TenantRls::protect($t);   // condição canônica — ver App\Support\TenantRls
        }
    }

    public function down(): void
    {
        // Não reverte para o cast quebrado de propósito (igual ao 06_18).
    }
};
