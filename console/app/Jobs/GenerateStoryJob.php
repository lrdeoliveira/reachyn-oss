<?php

namespace App\Jobs;

use App\Models\Draft;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\EngineClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Gera a HISTÓRIA (roteiro de N cenas) de forma ASSÍNCRONA. O roteiro vem de um reasoning model
 * (MiniMax-M3) e, em histórias longas, leva ~1-2min — feito no request, prendia o browser/proxy.
 * Aqui o worker chama o engine (/v1/story) e grava story.scenes + status='ready'; o gerador de
 * Histórias faz polling do rascunho até o status sair de 'generating'. Cota 'story': reservada no
 * controller (reserve-then-consume) e ESTORNADA aqui se a geração falhar.
 */
class GenerateStoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Teto: o engine já bounda a geração em ~200s; damos margem e falhamos rápido, liberando o worker.
    public int $timeout = 260;

    public int $tries = 1;

    public function __construct(
        public int $draftId,
        public int $tenantId,
        public string $theme,
        public string $character,
        public string $scenario,
        public string $lang,
        public int $sceneCount, // 0 = default do engine
        public string $persona = '', // craft/voz do ROTEIRISTA escolhido (vazio = mestre padrão do engine)
        public ?array $structure = null, // S2: espinha dramática aprovada (passo 1); null = geração direta
        public ?string $textModel = null, // modelo de TEXTO do seletor (provider_model_id; null = default engine)
        public ?int $textCost = null,     // custo em créditos cobrado no controller (estornado no markFailed)
        public string $title = '',        // título escolhido pelo operador; vazio = a IA escolhe no roteiro
    ) {}

    public function handle(UsageService $usage): void
    {
        $payload = ['theme' => $this->theme, 'character' => $this->character, 'scenario' => $this->scenario, 'lang' => $this->lang];
        if ($this->sceneCount > 0) {
            $payload['scenes'] = $this->sceneCount; // engine clampa; 0 = default
        }
        if ($this->persona !== '') {
            $payload['persona'] = $this->persona; // roteirista de nicho escolhido (substitui a craft-intro no engine)
        }
        if (is_array($this->structure) && $this->structure !== []) {
            $payload['structure'] = $this->structure; // S2: o engine distribui as cenas pelos atos aprovados
        }
        if ($this->textModel) {
            $payload['gen_lines'] = ['text' => ['model' => $this->textModel]]; // modelo do seletor de texto
        }

        $res = EngineClient::make(240) // o engine se limita a ~200s; estoura aqui → falha + estorna
            ->post('/v1/story', $payload);

        $scenes = $res->successful() ? $res->json('scenes') : null;
        if (! is_array($scenes) || $scenes === []) {
            Log::warning('GenerateStoryJob: sem cenas', ['draft' => $this->draftId, 'status' => $res->status()]);
            $this->markFailed($usage, 'a história não retornou cenas');

            return;
        }
        // Título: o do operador manda; vazio = o que a IA escolheu no roteiro (mesmo padrão do
        // Estúdio de Animação/Movies — se o Luciano não escolher, a IA escolhe).
        $engineTitle = mb_substr(trim((string) ($res->json('title') ?? '')), 0, 120);
        $title = $this->title !== '' ? $this->title : $engineTitle;

        // Read-modify-write da coluna JSON sob LOCK: grava as cenas novas + status=ready, preservando
        // elenco (cast) e a ref do personagem (character_ref) entre regenerações.
        DB::transaction(function () use ($scenes, $title) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $prev = is_array($d->story) ? $d->story : [];
            $story = [
                'theme' => $this->theme,
                'character' => $this->character,
                'scenario' => $this->scenario,
                'lang' => $this->lang,
                'title' => $title,
                'scenes' => $scenes,
                'status' => 'ready',
            ];
            if (! empty($prev['character_ref'])) {
                $story['character_ref'] = $prev['character_ref'];
            }
            if (! empty($prev['cast'])) {
                $story['cast'] = $prev['cast'];
            }
            if (! empty($prev['scenario_ref'])) {
                $story['scenario_ref'] = $prev['scenario_ref']; // imagem-âncora do cenário base
            }
            // O título vira o NOME da história em toda a UI (galeria/aprovações leem draft.keyword).
            $d->update(['story' => $story, 'keyword' => $title !== '' ? $title : $d->keyword]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateStoryJob falhou', ['draft' => $this->draftId, 'error' => $e->getMessage()]);
        $this->markFailed(app(UsageService::class), 'a geração da história falhou');
    }

    // Estorna a cota e marca story.status=error no rascunho (o front sai do "gerando" e mostra o erro).
    private function markFailed(UsageService $usage, string $msg): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'story', 1);
            $usage->refund($t, 'text', 1, $this->textCost); // estorna o roteiro cobrado no controller
        }
        DB::transaction(function () use ($msg) {
            $d = Draft::lockForUpdate()->find($this->draftId);
            if (! $d) {
                return;
            }
            $story = is_array($d->story) ? $d->story : [];
            $story['status'] = 'error';
            $story['error'] = $msg;
            $d->update(['story' => $story]);
        });
    }
}
