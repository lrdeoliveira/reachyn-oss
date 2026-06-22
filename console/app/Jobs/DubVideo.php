<?php

namespace App\Jobs;

use App\Models\Draft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Dublagem de vídeo via provedor de voz (assíncrona: dispara → poll → baixa → galeria).
 * Porta do /api/studio/dub do reachyn-os. Requer worker de fila.
 */
class DubVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // ~20 min (a dublagem leva 5-15)

    public function __construct(public int $draftId, public string $videoUrl, public string $lang) {}

    public function handle(): void
    {
        $key = (string) config('services.voice.key');
        $base = rtrim((string) config('services.voice.base'), '/');
        if ($key === '' || $base === '') {
            return;
        }
        // AUD-014: defesa em profundidade — só dubla URL http(s) do nosso domínio de mídia.
        if (! \App\Http\Controllers\Api\StudioController::isOwnMediaUrl($this->videoUrl)) {
            return;
        }
        $h = ['x-api-key' => $key];

        $start = Http::withHeaders($h)->asMultipart()->post($base.'/v1/dubbing', [
            ['name' => 'source_url', 'contents' => $this->videoUrl],
            ['name' => 'target_lang', 'contents' => $this->lang],
        ])->json();
        $id = $start['dubbing_id'] ?? null;
        if (! $id) {
            return;
        }

        $done = false;
        for ($i = 0; $i < 90; $i++) {
            sleep(10);
            $status = Http::withHeaders($h)->get("{$base}/v1/dubbing/{$id}")->json('status');
            if ($status === 'dubbed') {
                $done = true;
                break;
            }
            if ($status === 'failed') {
                return;
            }
        }
        if (! $done) {
            return;
        }

        $res = Http::withHeaders($h)->get("{$base}/v1/dubbing/{$id}/audio/{$this->lang}");
        if (! $res->successful()) {
            return;
        }

        $url = \App\Http\Controllers\Api\StudioController::storeMedia($res->body(), 'mp4', 'dub');

        $d = Draft::find($this->draftId);
        if (! $d) {
            return;
        }
        $media = $d->media ?? [];
        $media[] = ['id' => (string) (int) (microtime(true) * 1000), 'kind' => 'video', 'url' => $url];
        $d->update(['media' => $media]);
    }
}
