<?php

namespace App\Jobs;

use App\Models\AnimationProject;
use App\Models\Character;
use App\Models\Scenario;
use App\Models\Tenant;
use App\Services\AnimationFlow;
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
 * 🎬 Estúdio de Animação — passo 2: gera a REFERÊNCIA visual de UM elemento (personagem,
 * locação ou objeto) via /v1/image. Personagem pronto também vira registro na aba
 * Personagens (characters), com o visual_prompt como IDENTITY LOCK — reutilizável em
 * outros projetos. Cota reservada no dispatch (AnimationFlow); estornada aqui se falhar.
 */
class AnimationElementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public int $tenantId,
        public string $type,   // characters|locations|props
        public int $index,
        public array $payload, // body do /v1/image
        public int $weight,
        public ?int $costCredits = null,
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
        $res = EngineClient::make(360)
            ->post('/v1/image', $this->payload);

        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            Log::warning('AnimationElementJob: geração sem URL', ['project' => $this->projectId, 'type' => $this->type, 'i' => $this->index, 'status' => $res->status()]);
            $this->refund($usage);
            $this->patch(['status' => 'error']);
            app(AnimationFlow::class)->advance(AnimationProject::find($this->projectId) ?? $p);

            return;
        }

        // 🏞️ Locação ganha registro na biblioteca de Cenários — MESMA regra do personagem logo
        // abaixo. Sem isto o ambiente gerado morria dentro do projeto: dava para USAR um cenário
        // salvo numa locação (element-scenario), mas nada nunca ALIMENTAVA a biblioteca, e nenhuma
        // tela chamava POST /api/scenarios. Resultado: personagem se acumulava entre projetos e
        // cenário se perdia junto com o projeto. Idempotente pelo scenario_id no elemento.
        $scenarioId = null;
        if ($this->type === 'locations') {
            $el = (array) ((((array) $p->elements)[$this->type] ?? [])[$this->index] ?? []);
            if (empty($el['scenario_id'])) {
                try {
                    $s = Scenario::create([
                        'tenant_id' => $this->tenantId,
                        'name' => mb_substr((string) ($el['name'] ?? 'Cenário'), 0, 80),
                        // O visual_prompt é a âncora textual do ambiente — é ele que volta como
                        // referência quando o cenário for reusado em outro projeto.
                        'description' => (string) ($el['visual_prompt'] ?? ''),
                        'image_url' => $url,
                    ]);
                    $scenarioId = $s->id;
                } catch (\Throwable $e) {
                    Log::warning('AnimationElementJob: falha ao criar Scenario', ['error' => $e->getMessage()]);
                }
            }
        }

        // Personagem ganha registro na aba Personagens (identidade reutilizável). Idempotente.
        $characterId = null;
        if ($this->type === 'characters') {
            $el = (array) ((((array) $p->elements)[$this->type] ?? [])[$this->index] ?? []);
            if (empty($el['character_id'])) {
                try {
                    $c = Character::create([
                        'tenant_id' => $this->tenantId,
                        'name' => mb_substr((string) ($el['name'] ?? 'Personagem'), 0, 80),
                        'description' => (string) ($el['visual_prompt'] ?? ''),
                        'style' => (string) $p->style,
                        'base_url' => $url,
                        'lock' => (string) ($el['visual_prompt'] ?? ''),
                        // status VAZIO = pronto. A semântica aqui é INVERTIDA em relação ao
                        // elemento: no elemento `status` descreve o resultado ('ready'/'error');
                        // no Character ele descreve a geração EM CURSO ('base'/'sheet', '' = ocioso).
                        // Gravar 'ready' aqui marcava o personagem como eternamente ocupado e a aba
                        // Personagens tem trava GLOBAL — um só personagem "ocupado" desabilitava
                        // todos os botões de todos os cards, inclusive o de excluir.
                        'status' => '',
                    ]);
                    $characterId = $c->id;
                } catch (\Throwable $e) {
                    Log::warning('AnimationElementJob: falha ao criar Character', ['error' => $e->getMessage()]);
                }
            }
        }
        $patch = ['ref_url' => $url, 'status' => 'ready'];
        if ($characterId) {
            $patch['character_id'] = $characterId;
        }
        if ($scenarioId) {
            $patch['scenario_id'] = $scenarioId; // vínculo com a biblioteca (e trava o re-cadastro)
        }
        $this->patch($patch);
        app(AnimationFlow::class)->advance(AnimationProject::find($this->projectId) ?? $p);
    }

    /** Read-modify-write do elemento sob lock (vários elementos geram em paralelo). */
    private function patch(array $patch): void
    {
        DB::transaction(function () use ($patch) {
            $p = AnimationProject::lockForUpdate()->find($this->projectId);
            if (! $p) {
                return;
            }
            $els = (array) $p->elements;
            if (! isset($els[$this->type][$this->index])) {
                return;
            }
            $els[$this->type][$this->index] = array_merge($els[$this->type][$this->index], $patch);
            $p->update(['elements' => $els]);
        });
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('AnimationElementJob falhou', ['project' => $this->projectId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        $this->patch(['status' => 'error']);
    }

    private function refund(UsageService $usage): void
    {
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
