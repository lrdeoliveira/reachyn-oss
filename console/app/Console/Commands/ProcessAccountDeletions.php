<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AccountDeletionService;
use Illuminate\Console\Command;

/**
 * Processa exclusões de conta vencidas (LGPD) — Reachyn.
 * Roda diariamente: purga as orgs cuja carência de 7 dias expirou.
 * Idempotente (o service só executa se ainda pendente). Use --dry-run para simular.
 */
class ProcessAccountDeletions extends Command
{
    protected $signature = 'reachyn:process-account-deletions {--dry-run}';

    protected $description = 'Purga as contas (organizações) cuja carência de exclusão (LGPD) venceu';

    public function handle(AccountDeletionService $svc): int
    {
        $due = Organization::whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nenhuma exclusão vencida.');

            return self::SUCCESS;
        }

        foreach ($due as $org) {
            if ($this->option('dry-run')) {
                $this->warn("[dry-run] purgaria org #{$org->id} ({$org->slug}) agendada p/ {$org->deletion_scheduled_for}");

                continue;
            }
            $this->info("Purgando org #{$org->id} ({$org->slug})...");
            try {
                $svc->execute($org);
                $this->info('  ok.');
            } catch (\Throwable $e) {
                $this->error("  falhou: {$e->getMessage()}");
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
