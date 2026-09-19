<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * S6 (PLANO-UX-INTERFACE): Fila de jobs — visão do operador sobre jobs pendentes/rodando e
 * falhas (tabelas `jobs`/`failed_jobs`, queue=database), com reprocessar/descartar. Mata o
 * "cadê meu vídeo?" sem SQL na mão. Descoberto via discoverPages (app/Filament/Pages).
 */
class JobQueueMonitor extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Fila de jobs';

    protected static ?string $title = 'Fila de jobs';

    protected string $view = 'filament.pages.job-queue-monitor';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $pending = DB::table('jobs')->orderBy('id')->limit(50)->get()->map(function ($j) {
            $name = (string) (json_decode((string) $j->payload)?->displayName ?? '?');

            return [
                'id' => $j->id,
                'name' => class_basename($name),
                'queue' => $j->queue,
                'attempts' => $j->attempts,
                'running' => $j->reserved_at !== null,
                'since' => now()->subSeconds(max(0, now()->timestamp - (int) $j->created_at))->diffForHumans(),
            ];
        });
        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get()->map(function ($j) {
            $name = (string) (json_decode((string) $j->payload)?->displayName ?? '?');

            return [
                'uuid' => $j->uuid,
                'name' => class_basename($name),
                'queue' => $j->queue,
                'failed_at' => $j->failed_at,
                'error' => mb_substr((string) strtok((string) $j->exception, "\n"), 0, 180),
            ];
        });

        return ['pending' => $pending, 'failed' => $failed];
    }

    public function retry(string $uuid): void
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Notification::make()->title('Job devolvido pra fila.')->success()->send();
    }

    public function forget(string $uuid): void
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        Notification::make()->title('Falha descartada.')->success()->send();
    }

    public function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        Notification::make()->title('Todas as falhas devolvidas pra fila.')->success()->send();
    }
}
