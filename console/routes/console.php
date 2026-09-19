<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// LGPD (art. 18, VI): purga as contas cuja carência de exclusão (7 dias) venceu.
// Diário, sem sobreposição (purge irreversível).
Schedule::command('reachyn:process-account-deletions')
    ->dailyAt('03:20')
    ->withoutOverlapping();

// S2 (PLANO-UX-INTERFACE): avisos diários — aprovações pendentes, créditos baixos, trial
// expirando. 11:00 UTC = 08:00 em Brasília (hora útil, não madrugada — é e-mail de engajamento).
Schedule::command('reachyn:send-daily-notices')
    ->dailyAt('11:00')
    ->withoutOverlapping();

// Publicações AGENDADAS: dispara as que venceram. A cada minuto — é a menor granularidade que o
// cliente escolhe no calendário. Sem sobreposição por garantia; o claim condicional do próprio
// comando (scheduled → running) já impede publicar duas vezes mesmo se duas rodadas se cruzarem.
Schedule::command('reachyn:publish-due')
    ->everyMinute()
    ->withoutOverlapping();

// SAÚDE DAS LINHAS DE TEXTO: sonda cada nível ativo e liga/desliga o aviso de instabilidade.
// De hora em hora — em 2026-08-03 as quatro linhas mais caras ficaram fora por mais de UM DIA
// antes de alguém marcar o flag à mão, cobrando premium por entrega de reserva o tempo todo.
// Custa uma geração de ~8 tokens por nível ativo. Sem sobreposição: a sonda é sequencial.
Schedule::command('reachyn:check-text-health')
    ->hourly()
    ->withoutOverlapping();
