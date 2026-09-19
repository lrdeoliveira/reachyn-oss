<?php

namespace App\Jobs;

use App\Models\AnimationProject;
use App\Models\Tenant;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 🎬 Estúdio de Animação — passo 1: parseia o ROTEIRO no engine (/v1/scriptparse) e grava
 * elementos + storyboard no projeto. Depois faz o CAST automático de vozes (voice_hint →
 * voz do catálogo por gênero, best-effort) — o usuário refina no passo 2. Cota de texto
 * reservada no controller; estornada aqui se falhar.
 */
class AnimationParseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public array $payload,           // body do /v1/scriptparse
        public ?int $costCredits = null, // custo do modelo de texto escolhido
        // REEXTRAÇÃO: reescreve SÓ os elementos, preservando o storyboard e o passo atual. Existe
        // porque a lista de elementos passou a ser editável (excluir/adicionar) e quem apagasse
        // demais ficava sem volta — o parse só rodava na criação do projeto.
        public bool $elementsOnly = false,
    ) {
        $this->onQueue('animation'); // ver AnimationSceneJob: fan-out isolado do resto da fila
    }

    public function handle(UsageService $usage): void
    {
        $p = AnimationProject::find($this->projectId);
        if (! $p) {
            $this->refund($usage);

            return;
        }
        $res = EngineClient::make(300)
            ->post('/v1/scriptparse', $this->payload);

        if (! $res->successful() || ! is_array($res->json('scenes')) || $res->json('scenes') === []) {
            Log::warning('AnimationParseJob: parse sem cenas', ['project' => $this->projectId, 'status' => $res->status()]);
            $this->refund($usage);
            $p->update(['status' => 'error', 'error' => 'Não consegui estruturar esse roteiro — revise o texto e tente de novo.', 'auto' => false]);

            return;
        }

        $voices = $this->voices();
        $elements = ['characters' => [], 'locations' => [], 'props' => []];
        foreach ((array) $res->json('characters') as $c) {
            $elements['characters'][] = [
                'name' => (string) ($c['name'] ?? ''),
                'kind' => (string) ($c['kind'] ?? ''),
                'visual_prompt' => (string) ($c['visual_prompt'] ?? ''),
                'voice_hint' => (string) ($c['voice_hint'] ?? ''),
                'voice_id' => $this->castVoice((string) ($c['voice_hint'] ?? ''), $voices),
                'ref_url' => '', 'character_id' => null, 'status' => '',
            ];
        }
        foreach (['locations', 'props'] as $k) {
            foreach ((array) $res->json($k) as $e) {
                $elements[$k][] = [
                    'name' => (string) ($e['name'] ?? ''),
                    'visual_prompt' => (string) ($e['visual_prompt'] ?? ''),
                    'ref_url' => '', 'status' => '',
                ];
            }
        }
        $storyboard = [];
        foreach ((array) $res->json('scenes') as $sc) {
            $storyboard[] = [
                'title' => (string) ($sc['title'] ?? ''),
                'action' => (string) ($sc['action'] ?? ''),
                'image_prompt' => (string) ($sc['image_prompt'] ?? ''),
                'narration' => (string) ($sc['narration'] ?? ''), // modos narrados: voz única lida na montagem
                'video_prompt' => (string) ($sc['video_prompt'] ?? ''),
                'dialogue' => array_values((array) ($sc['dialogue'] ?? [])),
                'characters' => array_values((array) ($sc['characters'] ?? [])),
                'location' => (string) ($sc['location'] ?? ''),
                'props' => array_values((array) ($sc['props'] ?? [])),
                'spec' => is_array($sc['spec'] ?? null) ? $sc['spec'] : null,
                'keyframe_url' => '', 'keyframe_status' => '',
                'end_image_prompt' => '', 'end_keyframe_url' => '', 'end_keyframe_status' => '', // 🔚 frame final (animar fora)
                'audio_url' => '', 'video_url' => '', 'video_status' => '',
                'duration' => 0, 'locked' => false,
            ];
        }
        if ($this->elementsOnly) {
            // NÃO toca em storyboard nem em status: o usuário pode estar no meio do projeto, com
            // cenas já escritas e keyframes gerados. Reextrair elemento não é recomeçar o filme.
            $p->update(['elements' => $elements, 'error' => '']);

            return;
        }
        $p->update([
            'title' => $p->title !== '' ? $p->title : mb_substr((string) $res->json('title'), 0, 120),
            'elements' => $elements,
            'storyboard' => $storyboard,
            'status' => 'elements',
            'error' => '',
        ]);
        app(AnimationFlow::class)->advance($p);
    }

    /** Vozes do catálogo (engine /v1/voices) — best-effort; vazio se indisponível. */
    private function voices(): array
    {
        try {
            $res = EngineClient::make(30)->get('/v1/voices');

            // O engine devolve {"voices":[...]} — pegar a LISTA, não o envelope.
            return $res->successful() ? (array) $res->json('voices') : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** CAST automático: voice_hint (PT) → voz por gênero, distribuindo vozes distintas. */
    private function castVoice(string $hint, array &$voices): string
    {
        if ($voices === []) {
            return '';
        }
        $h = mb_strtolower($hint);
        $fem = (bool) preg_match('/\b(menina|mulher|garota|mãe|mae|vovó|vovo|fem|female|girl|woman)\b/u', $h);
        foreach ($voices as $k => $v) {
            $g = mb_strtolower((string) ($v['gender'] ?? $v['Gender'] ?? ''));
            if (($fem && str_starts_with($g, 'f')) || (! $fem && ! str_starts_with($g, 'f'))) {
                unset($voices[$k]); // cada personagem ganha uma voz DIFERENTE enquanto houver

                return (string) ($v['id'] ?? $v['ID'] ?? '');
            }
        }
        $first = array_key_first($voices);
        if ($first === null) {
            return '';
        }
        $v = $voices[$first];
        unset($voices[$first]);

        return (string) ($v['id'] ?? $v['ID'] ?? '');
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AnimationParseJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        AnimationProject::find($this->projectId)?->update(['status' => 'error', 'error' => 'Falha ao estruturar o roteiro.', 'auto' => false]);
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'text', 1, $this->costCredits);
        }
    }
}
