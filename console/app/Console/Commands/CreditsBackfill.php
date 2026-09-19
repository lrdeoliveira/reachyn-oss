<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\CreditWallet;
use Illuminate\Console\Command;

/**
 * Concede o saldo INICIAL de créditos às ORGS existentes (migração para o modelo de créditos).
 * Fase 2: o saldo é da Organization. Idempotente em DOIS níveis: (a) idempotency_key fixa por org;
 * (b) GUARDA — pula orgs que já têm QUALQUER lançamento no ledger (a migração create_organizations
 * já copiou o credit_balance dos tenants → essas orgs não devem ser creditadas de novo).
 * exempt e planos sem concessão (unlimited/0) são pulados. Use --dry-run pra simular.
 */
class CreditsBackfill extends Command
{
    protected $signature = 'reachyn:credits-backfill {--dry-run}';

    protected $description = 'Concede o saldo inicial de créditos do plano às orgs atuais (idempotente)';

    public function handle(CreditWallet $wallet): int
    {
        $dry = (bool) $this->option('dry-run');
        $granted = 0;
        $skipped = 0;

        Organization::query()->chunkById(100, function ($orgs) use ($wallet, $dry, &$granted, &$skipped): void {
            foreach ($orgs as $org) {
                $credits = $org->monthlyCredits();
                // Pula: isenta, sem concessão, OU já migrada/concedida (tem saldo ou ledger).
                if ($org->isExempt() || $credits <= 0 || (int) $org->credit_balance > 0 || $org->creditTransactions()->exists()) {
                    $skipped++;

                    continue;
                }
                $this->line("org {$org->id} ({$org->slug}) plano={$org->plan} → +{$credits}".($dry ? ' [dry-run]' : ''));
                if (! $dry) {
                    $wallet->grant($org, $credits, 'grant_subscription', [
                        'idempotency_key' => 'backfill:initial:org:'.$org->id,
                        'reference_type' => 'backfill',
                        'meta' => ['plan' => $org->plan, 'note' => 'saldo inicial (migração para créditos)'],
                    ]);
                }
                $granted++;
            }
        });

        $this->info(($dry ? '[dry-run] ' : '')."Concedido a {$granted} org(s); {$skipped} pulada(s) (exempt/sem plano/já migrada).");

        return self::SUCCESS;
    }
}
