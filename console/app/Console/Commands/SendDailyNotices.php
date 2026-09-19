<?php

namespace App\Console\Commands;

use App\Mail\DailyNotice;
use App\Models\Approval;
use App\Models\Organization;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * S2 (PLANO-UX-INTERFACE): avisos diários por e-mail — roda 1×/dia no scheduler.
 *  1. Aprovações pendentes  → digest por organização (só se a fila > 0; throttle 20h).
 *  2. Créditos acabando     → saldo < 15% da cota mensal do plano (throttle 7 dias).
 *  3. Trial expirando       → trial_ends_at nos próximos 3 dias (throttle 30 dias = 1 aviso).
 * Opt-out por usuário em notify_prefs (approvals_digest / credits_low / trial_ending).
 */
class SendDailyNotices extends Command
{
    protected $signature = 'reachyn:send-daily-notices';

    protected $description = 'Envia os avisos diários (aprovações pendentes, créditos baixos, trial expirando)';

    public function handle(Notifier $notifier): int
    {
        $studio = rtrim((string) config('services.studio.url'), '/');
        $n = 0;

        foreach (Organization::with('users')->get() as $org) {
            // 1) aprovações pendentes (todas as marcas da org)
            $tenantIds = $org->tenants()->pluck('id');
            $pend = $tenantIds->isEmpty() ? 0 : Approval::withoutGlobalScopes()
                ->whereIn('tenant_id', $tenantIds)->where('status', 'pendente')->count();
            if ($pend > 0) {
                $notifier->send($org, 'approvals_digest', new DailyNotice(
                    "⏳ {$pend} peça(s) aguardando sua aprovação",
                    'Tem conteúdo esperando por você',
                    "Há {$pend} peça(s) na fila de aprovação. Elas só vão ao ar depois do seu OK.",
                    $studio.'/aprovacoes',
                    'Revisar e aprovar',
                ), 'approvals_digest:org:'.$org->id, 72000); // 20h — no máx. 1 por dia
                $n++;
            }

            // 2) créditos acabando (< 15% da cota mensal; só planos com cota)
            $monthly = $org->monthlyCredits();
            if ($monthly > 0 && $org->credit_balance >= 0 && $org->credit_balance < (int) ceil($monthly * 0.15)) {
                $notifier->send($org, 'credits_low', new DailyNotice(
                    '⚠️ Seus créditos estão acabando',
                    'Créditos quase no fim',
                    "Restam {$org->credit_balance} créditos de {$monthly} do seu plano este mês. Pra não interromper as gerações, você pode comprar um pacote avulso ou subir de plano.",
                    $studio.'/plano',
                    'Ver plano e créditos',
                ), 'credits_low:org:'.$org->id, 7 * 86400); // 1 aviso por semana
                $n++;
            }

            // 3) trial expirando em ≤ 3 dias
            if ($org->trial_ends_at !== null && $org->trial_ends_at->isFuture() && now()->diffInDays($org->trial_ends_at, false) <= 3) {
                $notifier->send($org, 'trial_ending', new DailyNotice(
                    '⏰ Seu período de teste termina em breve',
                    'Seu teste grátis está acabando',
                    'Seu período de avaliação do Reachyn termina em '.$org->trial_ends_at->timezone('America/Sao_Paulo')->format('d/m').'. Escolha um plano pra continuar gerando e publicando sem interrupção.',
                    $studio.'/plano',
                    'Escolher meu plano',
                ), 'trial_ending:org:'.$org->id, 30 * 86400); // 1 aviso por trial
                $n++;
            }
        }

        $this->info("Avisos processados: {$n} organizações notificadas.");

        return self::SUCCESS;
    }
}
