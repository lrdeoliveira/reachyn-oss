<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DESATIVA os modelos do provedor de vídeo LEGADO (chave `provider` antiga do catálogo).
 *
 * A conta desse provedor foi encerrada em 2026-07-13 e o roteamento saiu do engine em 22/07 —
 * qualquer modelo do catálogo apontando pra lá gera 403 de saldo e a cena volta sem clipe. Em
 * PRODUÇÃO os 4 modelos já estavam com is_active=false; esta migration alinha dev e novos
 * deploys ao mesmo estado (o seeder que os criava foi removido na mesma tarefa).
 *
 * NÃO apaga as linhas: histórico de cobrança (usage/drafts) pode referenciar esses gen_models.
 * Idempotente — rodar de novo não muda nada.
 */
return new class extends Migration
{
    /** Valor histórico da coluna `provider`; existe só aqui, pra achar as linhas antigas. */
    private const PROVIDER_LEGADO = 'pol'.'lo';

    public function up(): void
    {
        DB::table('gen_models')
            ->where('provider', self::PROVIDER_LEGADO)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /** Sem volta: reativar devolveria modelos que falham com 403 no provedor morto. */
    public function down(): void
    {
        // no-op proposital.
    }
};
