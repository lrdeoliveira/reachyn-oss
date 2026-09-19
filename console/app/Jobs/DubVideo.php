<?php

namespace App\Jobs;

use App\Http\Controllers\Api\StudioController;
use App\Models\Draft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Dublagem de vídeo via ElevenLabs (assíncrona: dispara → poll → baixa → galeria).
 * Porta do /api/studio/dub do reachyn-os. Requer worker de fila.
 */
class DubVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200; // ~20 min (ElevenLabs leva 5-15)

    public function __construct(public int $draftId, public string $videoUrl, public string $lang) {}

    public function handle(): void
    {
        $key = (string) config('services.elevenlabs.key');
        if ($key === '') {
            return;
        }
        // AUD-014: defesa em profundidade — só dubla URL http(s) do nosso domínio de mídia.
        if (! StudioController::isOwnMediaUrl($this->videoUrl)) {
            return;
        }
        $h = ['xi-api-key' => $key];

        $start = Http::withHeaders($h)->asMultipart()->post('https://api.elevenlabs.io/v1/dubbing', [
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
            $status = Http::withHeaders($h)->get("https://api.elevenlabs.io/v1/dubbing/{$id}")->json('status');
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

        $res = Http::withHeaders($h)->get("https://api.elevenlabs.io/v1/dubbing/{$id}/audio/{$this->lang}");
        if (! $res->successful()) {
            return;
        }

        $url = StudioController::storeMedia($res->body(), 'mp4', 'dub');

        $d = Draft::find($this->draftId);
        if (! $d) {
            return;
        }
        $media = $d->media ?? [];
        $media[] = ['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url];
        $d->update(['media' => $media]);
    }
}
