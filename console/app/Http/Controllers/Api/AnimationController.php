<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesTextModel;
use App\Http\Controllers\Controller;
use App\Jobs\AnimationFrameJob;
use App\Jobs\AnimationParseJob;
use App\Jobs\MotionTransferJob;
use App\Models\AnimationProject;
use App\Models\Character;
use App\Models\Draft;
use App\Models\GenModel;
use App\Models\Scenario;
use App\Services\AnimationFlow;
use App\Services\StoryboardService;
use App\Services\UsageService;
use App\Support\EngineClient;
use App\Support\GenPayload;
use App\Support\Networks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 🎬 ESTÚDIO DE ANIMAÇÃO — API do wizard "roteiro → desenho pronto" (aba /animacao do web).
 * Fluxo: store (roteiro → parse assíncrono) → element/elements (refs curáveis) →
 * frame/frames (storyboard) → scene/scenes (i2v + diálogo multi-voz) → assemble (filme) —
 * ou auto (o "agente": cada job terminado avança o próximo passo sozinho, AnimationFlow).
 * Cobrança reserve-then-consume por etapa (os jobs estornam em falha).
 */
class AnimationController extends Controller
{
    use ResolvesTextModel;

    public function __construct(private UsageService $usage, private AnimationFlow $flow) {}

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function project(Request $r, int|string $id): AnimationProject
    {
        return AnimationProject::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    /** GET /api/animation — lista os projetos do tenant (mais recentes primeiro). */
    public function index(Request $r): JsonResponse
    {
        $items = AnimationProject::where('tenant_id', $this->tenant($r)->id)
            ->orderByDesc('updated_at')->limit(50)
            ->get(['id', 'title', 'mode', 'style', 'quality', 'status', 'final_url', 'updated_at']);

        return response()->json(['ok' => true, 'projects' => $items]);
    }

    /** POST /api/animation { script, mode?, style?, lang?, quality?, aspect?, scenes?, voice_id?,
     *  persona?, pace?, textModel?, auto? } → cria o projeto e dispara o PARSE do roteiro
     *  (assíncrono; polling via GET). `script` aceita roteiro pronto OU só a ideia/tema — o
     *  parser escreve a história. mode=historia|quadrinhos = narração única (voice_id = narrador). */
    public function store(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $script = trim((string) $r->input('script'));
        if (mb_strlen($script) < 10) {
            return response()->json(['ok' => false, 'error' => 'Cole o roteiro (ou descreva a ideia da história).'], 422);
        }
        $mode = in_array($r->input('mode'), AnimationProject::MODES, true) ? (string) $r->input('mode') : 'animacao';
        // 🔗 Sequência (todos os formatos): default `encadeado` no Desenho animado — as cenas fluem
        // em vez de saírem soltas (a queixa que motivou a feature). Nos narrados o default é `solto`
        // (keyframes em paralelo = storyboard mais rápido); encadear é opt-in (fica serial). Quadrinhos
        // não tem clipe → `plano` cai em `encadeado`.
        $seq = in_array($r->input('sequenceMode'), AnimationProject::SEQUENCE_MODES, true)
            ? (string) $r->input('sequenceMode')
            : ($mode === 'animacao' ? 'encadeado' : 'solto');
        $sequenceMode = ($mode === 'quadrinhos' && $seq === 'plano') ? 'encadeado' : $seq;
        $style = in_array($r->input('style'), array_keys(AnimationFlow::STYLE_LEADS), true) ? (string) $r->input('style') : '3d';
        $quality = in_array($r->input('quality'), ['economico', 'padrao', 'premium'], true) ? (string) $r->input('quality') : 'padrao';
        $maxScenes = $mode === 'animacao' ? 12 : 20; // modos narrados são mais baratos por cena → teto maior
        $scenes = max(1, min($maxScenes, (int) $r->input('scenes', 6)));

        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $p = AnimationProject::create([
            'tenant_id' => $t->id,
            'title' => mb_substr((string) $r->input('title', ''), 0, 120),
            'mode' => $mode,
            'sequence_mode' => $sequenceMode,
            'style' => $style,
            'lang' => $r->input('lang') === 'en-US' ? 'en-US' : 'pt-BR',
            'quality' => $quality,
            'image_model' => mb_substr(trim((string) $r->input('imageModel', '')), 0, 64), // 🧠 slug do catálogo; vazio = tier
            'video_model' => mb_substr(trim((string) $r->input('videoModel', '')), 0, 64),
            'aspect' => $r->input('aspect') === '9:16' ? '9:16' : '16:9',
            'palette' => mb_substr((string) $r->input('palette', ''), 0, 200),
            'music_prompt' => mb_substr((string) $r->input('musicPrompt', ''), 0, 300),
            'voice_id' => mb_substr(trim((string) $r->input('voice_id', '')), 0, 64),
            'script' => mb_substr($script, 0, 24000),
            'status' => 'parsing',
            // MODO AUTOMÁTICO DESATIVADO (2026-07-18): já causou loop de re-despacho em prod
            // (2026-07-15, projeto #12, ~2.400 chamadas ao provedor em 6h30). Gera-se passo a passo.
            'auto' => false,
        ]);
        AnimationParseJob::dispatch($p->id, $t->id, [
            'script' => $p->script,
            'style' => AnimationFlow::STYLE_LEADS[$style],
            'lang' => $p->lang,
            'maxScenes' => $scenes,
            'narration' => $p->isNarrated(), // modos Histórias/Quadrinhos: cenas ganham narração de voz única
            'persona' => $this->personaWithStructure($r, $t), // 🗣️ Voz da Marca + roteirista/ritmo + estrutura aprovada
            'gen_lines' => $this->textGenLines($tm),
        ], $tm?->cost_credits);

        return response()->json(['ok' => true, 'projectId' => $p->id, 'status' => 'parsing']);
    }

    /** Persona efetiva do parser: Voz da Marca + roteirista/ritmo (textPersona) + a ESTRUTURA
     *  dramática aprovada na Sala de Roteiro (o parser distribui as cenas pelos atos). */
    private function personaWithStructure(Request $r, $t): string
    {
        $persona = StudioController::textPersona($r, $t);
        $st = StudioController::sanitizeStructure($r->input('structure'));
        if ($st) {
            $persona .= ($persona !== '' ? "\n\n" : '')
                ."ESTRUTURA DRAMÁTICA APROVADA (siga: distribua as cenas por estes atos e viradas):\n"
                .json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $persona;
    }

    /** GET /api/animation/{id} — o projeto inteiro + estimativa de custo por etapa. */
    public function show(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);

        return response()->json(['ok' => true, 'project' => $p, 'quote' => $this->flow->quote($p, $t->plan)]);
    }

    /** PATCH /api/animation/{id} — edições da curadoria (título/paleta/qualidade, elementos,
     *  cenas). Aceita elements/storyboard PARCIAIS: {elements: {characters: {2: {visual_prompt: "..."}}}}. */
    public function update(Request $r, int $id): JsonResponse
    {
        $this->project($r, $id); // 404/escopo de tenant ANTES do lock
        // Merge de elements/storyboard sob LOCK: os jobs escrevem ref_url/keyframe_url em
        // paralelo — read-modify-write sem lock clobberaria o resultado de um job no meio.
        $p = DB::transaction(function () use ($r, $id) {
            $p = AnimationProject::lockForUpdate()->findOrFail($id);
            $this->applyUpdate($r, $p);

            return $p;
        });

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    private function applyUpdate(Request $r, AnimationProject $p): void
    {
        $upd = [];
        foreach (['title' => 120, 'palette' => 200, 'music_prompt' => 300] as $f => $max) {
            if ($r->has($f === 'music_prompt' ? 'musicPrompt' : $f)) {
                $upd[$f] = mb_substr((string) $r->input($f === 'music_prompt' ? 'musicPrompt' : $f), 0, $max);
            }
        }
        if (in_array($r->input('quality'), ['economico', 'padrao', 'premium'], true)) {
            $upd['quality'] = (string) $r->input('quality');
        }
        // 🔗 Trocar a sequência no meio do projeto (pra validar cada método: muda aqui e regenere os
        // keyframes/cenas). Vale em todos os formatos; Quadrinhos não tem clipe → `plano` = `encadeado`.
        if (in_array($r->input('sequenceMode'), AnimationProject::SEQUENCE_MODES, true)) {
            $seq = (string) $r->input('sequenceMode');
            $upd['sequence_mode'] = ($p->mode === 'quadrinhos' && $seq === 'plano') ? 'encadeado' : $seq;
        }
        if ($r->has('voice_id')) { // voz do NARRADOR (modos narrados; vazio = voz padrão da conta)
            $upd['voice_id'] = mb_substr(trim((string) $r->input('voice_id')), 0, 64);
        }
        foreach (['imageModel' => 'image_model', 'videoModel' => 'video_model'] as $in => $col) {
            if ($r->has($in)) { // 🧠 troca de modelo no meio do projeto (vazio = volta pro tier)
                $upd[$col] = mb_substr(trim((string) $r->input($in)), 0, 64);
            }
        }
        // Elementos: só campos editáveis (name/visual_prompt/voice_id) — nunca ref_url/status por aqui.
        if (is_array($r->input('elements'))) {
            $els = (array) $p->elements;
            foreach ((array) $r->input('elements') as $type => $items) {
                if (! in_array($type, ['characters', 'locations', 'props'], true) || ! is_array($items)) {
                    continue;
                }
                foreach ($items as $i => $patch) {
                    if (! isset($els[$type][(int) $i]) || ! is_array($patch)) {
                        continue;
                    }
                    foreach (['name' => 80, 'visual_prompt' => 1200, 'voice_id' => 64] as $f => $max) {
                        if (array_key_exists($f, $patch)) {
                            $els[$type][(int) $i][$f] = mb_substr(trim((string) $patch[$f]), 0, $max);
                        }
                    }
                }
            }
            $upd['elements'] = $els;
        }
        // Cenas: ação/prompts/diálogo/spec/locked (curadoria); nunca URLs/status por aqui.
        if (is_array($r->input('storyboard'))) {
            $sb = array_values((array) $p->storyboard);
            foreach ((array) $r->input('storyboard') as $i => $patch) {
                if (! isset($sb[(int) $i]) || ! is_array($patch)) {
                    continue;
                }
                foreach (['title' => 160, 'action' => 800, 'image_prompt' => 1500, 'end_image_prompt' => 1500, 'video_prompt' => 500, 'location' => 80, 'narration' => 600] as $f => $max) {
                    if (array_key_exists($f, $patch)) {
                        $sb[(int) $i][$f] = mb_substr(trim((string) $patch[$f]), 0, $max);
                    }
                }
                if (array_key_exists('locked', $patch)) {
                    $sb[(int) $i]['locked'] = (bool) $patch['locked'];
                }
                if (array_key_exists('spec', $patch)) {
                    $sb[(int) $i]['spec'] = StudioController::sanitizeSceneSpec($patch['spec']);
                }
                if (is_array($patch['dialogue'] ?? null)) {
                    $lines = [];
                    foreach ($patch['dialogue'] as $dl) {
                        $line = mb_substr(trim((string) ($dl['line'] ?? '')), 0, 500);
                        if ($line === '') {
                            continue;
                        }
                        $lines[] = ['character' => mb_substr(trim((string) ($dl['character'] ?? '')), 0, 80), 'line' => $line];
                    }
                    $sb[(int) $i]['dialogue'] = $lines;
                }
                if (is_array($patch['characters'] ?? null)) {
                    $sb[(int) $i]['characters'] = array_values(array_map(fn ($n) => mb_substr(trim((string) $n), 0, 80), $patch['characters']));
                }
            }
            $upd['storyboard'] = $sb;
        }
        if ($upd !== []) {
            $p->update($upd);
        }
    }

    /** DELETE /api/animation/{id} — remove o projeto (o Draft final, se existir, permanece). */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $this->project($r, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/element { type, index } — gera/regenera a ref de UM elemento. */
    public function element(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $type = (string) $r->input('type');
        if (! in_array($type, ['characters', 'locations', 'props'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo inválido'], 422);
        }
        $err = $this->flow->dispatchElement($p, $t, $type, (int) $r->input('index', -1), (bool) $r->boolean('unlink'));
        // Sentinela: NÃO é erro do usuário, é uma decisão que ele precisa tomar. A tela pergunta
        // ("isso desvincula da biblioteca — continuar?") e repete com unlink=true. Antes eu tinha
        // bloqueado de vez: virou beco sem saída, com 6 cliques seguidos em 422 (2026-07-21).
        if ($err === 'BIBLIOTECA') {
            return response()->json([
                'ok' => false,
                'precisaDesvincular' => true,
                'error' => 'Este personagem vem da sua biblioteca. Gerar uma imagem nova aqui DESVINCULA o elemento — a imagem oficial dele continua intacta em Personagens, e esta história passa a usar uma variante própria.',
            ], 409);
        }
        if ($err) {
            return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') ? 402 : 422);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/element-character { index, characterId } — 🎭 usa um personagem
     *  da BIBLIOTECA como identidade de um elemento: a imagem-base vira a referência (i2i) e o
     *  lock do personagem vira o visual_prompt (o CHARACTER LOCK dos keyframes) — sem custo.
     *  O NOME do elemento não muda (as cenas referenciam o elenco pelo nome do roteiro). */
    public function elementCharacter(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $this->project($r, $id); // 404/escopo ANTES do lock
        $c = Character::where('tenant_id', $t->id)->find((int) $r->input('characterId'));
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'personagem não encontrado'], 404);
        }
        $ref = $c->refImageUrl();
        if ($ref === '') {
            return response()->json(['ok' => false, 'error' => Character::SEM_IMAGEM], 422);
        }
        $i = (int) $r->input('index', -1);
        $p = DB::transaction(function () use ($id, $i, $c, $ref) {
            $p = AnimationProject::lockForUpdate()->findOrFail($id);
            $els = (array) $p->elements;
            if (! isset($els['characters'][$i])) {
                return null;
            }
            $els['characters'][$i]['ref_url'] = $ref;
            $els['characters'][$i]['character_id'] = $c->id;
            $els['characters'][$i]['status'] = '';
            if (trim((string) $c->lock) !== '') {
                $els['characters'][$i]['visual_prompt'] = mb_substr(trim((string) $c->lock), 0, 1200);
            }
            $p->update(['elements' => $els]);

            return $p;
        });
        if (! $p) {
            return response()->json(['ok' => false, 'error' => 'elemento inexistente'], 422);
        }

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/elements — gera TODAS as refs pendentes (lote). */
    public function elements(Request $r, int $id): JsonResponse
    {
        $force = (bool) $r->boolean('force');
        $n = $this->flow->dispatchAllElements($this->project($r, $id), $this->tenant($r), $force);

        // `dispatched: 0` NÃO é sucesso do ponto de vista de quem clicou: o botão respondia 200 e
        // nada acontecia (todos já tinham imagem e eram pulados em silêncio). Agora a resposta
        // explica, e a tela mostra.
        return response()->json([
            'ok' => true,
            'dispatched' => $n,
            'aviso' => $n === 0 ? $this->porQueNadaFoiGerado($this->project($r, $id), $force) : null,
        ]);
    }

    /** Por que "gerar todas" não despachou nada. Três motivos possíveis, e a diferença importa:
     *  lista vazia pede REEXTRAIR, lista cheia pede REGERAR, e em geração pede só esperar.
     *  Antes a resposta era 200 mudo pros três — indistinguível de botão quebrado. */
    private function porQueNadaFoiGerado(AnimationProject $p, bool $force): string
    {
        $els = (array) $p->elements;
        $total = count((array) ($els['characters'] ?? [])) + count((array) ($els['locations'] ?? [])) + count((array) ($els['props'] ?? []));
        if ($total === 0) {
            return 'A lista de elementos está vazia. Use "🧩 Reextrair do roteiro" para trazê-los de volta, ou "+ adicionar" para criar um à mão.';
        }

        return $force
            ? 'Nada a refazer — os elementos ou já estão em geração, ou vieram da sua biblioteca de Personagens.'
            : 'Todos os elementos já têm imagem. Use "🔄 Regerar todas" para refazê-las.';
    }

    /** POST /api/animation/{id}/reparse-elements → REEXTRAI os elementos do roteiro.
     *
     *  Contrapartida obrigatória do excluir: a lista de elementos virou editável, e a extração só
     *  rodava na criação do projeto — quem apagasse demais ficava sem volta, com o storyboard
     *  referenciando personagens que não existiam mais. Custa uma geração de TEXTO (o parser lê o
     *  roteiro de novo), não de imagem. Preserva o storyboard e o passo atual: reextrair elemento
     *  não é recomeçar o filme. */
    public function reparseElements(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        if (trim((string) $p->script) === '') {
            return response()->json(['ok' => false, 'error' => 'este projeto não tem roteiro para reextrair'], 422);
        }
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        AnimationParseJob::dispatch($p->id, $t->id, [
            'script' => $p->script,
            'style' => AnimationFlow::STYLE_LEADS[$p->style] ?? AnimationFlow::STYLE_LEADS['3d'],
            'lang' => $p->lang,
            'maxScenes' => max(1, count((array) $p->storyboard)),
            'narration' => $p->isNarrated(),
            'persona' => '',
            'gen_lines' => $this->textGenLines($tm),
        ], $tm?->cost_credits, elementsOnly: true);

        return response()->json(['ok' => true, 'aviso' => 'Reextraindo os elementos do roteiro… aparecem em alguns instantes.']);
    }

    /** POST /api/animation/{id}/element-add { type, name, visualPrompt } → ACRESCENTA um elemento.
     *
     *  A lista vinha só do que a IA extraiu do roteiro; personagem, locação ou objeto que ela não
     *  pegou (ou que você decidiu incluir depois) não tinha como entrar. Sem custo — só cria a
     *  ficha; a imagem se gera depois pelo card, como qualquer outro. */
    public function elementAdd(Request $r, int $id): JsonResponse
    {
        $type = (string) $r->input('type');
        if (! in_array($type, ['characters', 'locations', 'props'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo inválido'], 422);
        }
        $nome = trim((string) $r->input('name'));
        $desc = trim((string) $r->input('visualPrompt'));
        if ($nome === '') {
            return response()->json(['ok' => false, 'error' => 'dê um nome ao elemento'], 422);
        }
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o elemento — é a descrição que vira a imagem'], 422);
        }
        $p = $this->project($r, $id);

        $erro = null;
        DB::transaction(function () use ($p, $type, $nome, $desc, &$erro) {
            $fresh = AnimationProject::lockForUpdate()->find($p->id);
            if (! $fresh) {
                $erro = 'projeto inexistente';

                return;
            }
            $els = (array) $fresh->elements;
            $lista = array_values((array) ($els[$type] ?? []));
            if (count($lista) >= 12) {
                $erro = 'limite de 12 elementos por tipo';

                return;
            }
            $lista[] = [
                'name' => mb_substr($nome, 0, 80),
                'visual_prompt' => mb_substr($desc, 0, 1200),
                'ref_url' => '',
                'status' => '',
            ];
            $els[$type] = $lista;
            $fresh->update(['elements' => $els]);
        });

        return $erro
            ? response()->json(['ok' => false, 'error' => $erro], 422)
            : response()->json(['ok' => true]);
    }

    /** DELETE /api/animation/{id}/element { type, index } → REMOVE o elemento da lista.
     *
     *  Não existia: dava pra gerar e regerar, nunca pra tirar. Elemento que a IA extraiu errado do
     *  roteiro (ou que você não quer no filme) ficava lá pra sempre, entrando como referência de
     *  todas as cenas que o citam. Sem custo — é edição de lista, não geração. */
    public function elementRemove(Request $r, int $id): JsonResponse
    {
        $type = (string) $r->input('type');
        if (! in_array($type, ['characters', 'locations', 'props'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo inválido'], 422);
        }
        $i = (int) $r->input('index', -1);
        $p = $this->project($r, $id);

        $erro = null;
        DB::transaction(function () use ($p, $type, $i, &$erro) {
            $fresh = AnimationProject::lockForUpdate()->find($p->id);
            if (! $fresh) {
                $erro = 'projeto inexistente';

                return;
            }
            $els = (array) $fresh->elements;
            if (! isset($els[$type][$i])) {
                $erro = 'elemento inexistente';

                return;
            }
            if (($els[$type][$i]['status'] ?? '') === 'generating') {
                $erro = 'esse elemento está sendo gerado — aguarde para remover';

                return;
            }
            array_splice($els[$type], $i, 1);
            $fresh->update(['elements' => $els]);
        });

        return $erro
            ? response()->json(['ok' => false, 'error' => $erro], 422)
            : response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/frame { index } — gera/regenera o keyframe de UMA cena. */
    public function frame(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        $sc = array_values((array) $p->storyboard)[$i] ?? null;
        if ($sc && ($sc['locked'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'cena travada — destrave para regenerar'], 422);
        }
        if ($err = $this->flow->dispatchFrame($p, $this->tenant($r), $i)) {
            return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') ? 402 : 422);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/animation/{id}/storyboard — compõe a FOLHA DE STORYBOARD do projeto e devolve a URL.
     *
     * É o documento que se confere ANTES de montar: os painéis são os keyframes que já existem e os
     * rótulos (número, tempo, plano, ação, fala) saem do próprio storyboard do projeto. NÃO gera
     * imagem e NÃO gasta crédito — é composição, igual ao model sheet.
     *
     * ⚠️ Por que composta e não desenhada pela IA: a aba Movies foi removida em 2026-07-22
     * justamente porque o modelo NÃO obedece a grade pedida (pediram 4x4, veio 2x6 com painéis
     * deitados). Aqui a grade é desenhada pelo compositor, então ela não tem como vir errada.
     */
    public function storyboard(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $cenas = array_values((array) $p->storyboard);
        if ($cenas === []) {
            return response()->json(['ok' => false, 'error' => 'projeto ainda sem cenas'], 422);
        }

        // 5s = a duração de clipe padrão do fluxo. O timecode da folha é uma RÉGUA de leitura
        // (quando cada cena entra), não a duração final do corte — que só a montagem conhece.
        $paineis = StoryboardService::painelsDeAnimacao($cenas, 5.0);
        $url = (new StoryboardService)->compor(
            $paineis,
            (string) ($p->title ?: 'Storyboard'),
            trim(count($paineis).' cenas · '.(string) $p->style),
            [
                ['k' => 'projeto', 'v' => (string) ($p->title ?: '—')],
                ['k' => 'cenas', 'v' => (string) count($paineis)],
                ['k' => 'estilo', 'v' => (string) ($p->style ?: '—')],
            ],
        );
        if ($url === null) {
            return response()->json(['ok' => false, 'error' => 'não foi possível compor a folha'], 502);
        }

        return response()->json(['ok' => true, 'url' => $url, 'panels' => count($paineis)]);
    }

    /** POST /api/animation/{id}/frame-end { index } — gera/regenera o keyframe FINAL de UMA cena
     *  (para animar keyframe→keyframe fora). Ancora no keyframe inicial (que precisa existir). */
    public function frameEnd(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        $sc = array_values((array) $p->storyboard)[$i] ?? null;
        if ($sc && ($sc['locked'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'cena travada — destrave para regenerar'], 422);
        }
        if ($err = $this->flow->dispatchFrameEnd($p, $this->tenant($r), $i)) {
            return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') ? 402 : 422);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/frames-end — gera o keyframe FINAL de todas as cenas com inicial pronto. */
    public function framesEnd(Request $r, int $id): JsonResponse
    {
        $n = $this->flow->dispatchAllEndFrames($this->project($r, $id), $this->tenant($r));

        return response()->json(['ok' => true, 'dispatched' => $n]);
    }

    /** POST /api/animation/{id}/frame-set { index, url } — 📌 FIXA uma imagem da galeria como o
     *  keyframe da cena (sem custo; URL só do NOSSO storage — anti-SSRF, igual ao clássico). */
    public function frameSet(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $url = trim((string) $r->input('url', ''));
        if (! StudioController::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }
        $i = (int) $r->input('index', -1);
        $sc = array_values((array) $p->storyboard)[$i] ?? null;
        if (! $sc) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        if ($sc['locked'] ?? false) {
            return response()->json(['ok' => false, 'error' => 'cena travada — destrave para trocar a imagem'], 422);
        }
        $this->flow->patchScene($p, $i, ['keyframe_url' => $url, 'keyframe_status' => '']);

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/scene-video-set { index, url } — 🎬 usa um vídeo da galeria como
     *  o clipe da cena (sem custo; URL do nosso storage). */
    public function sceneVideoSet(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        if (! isset(array_values((array) $p->storyboard)[$i])) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        // Aceita UPLOAD direto (clipe animado por fora) OU uma URL própria já na galeria — assim o
        // passo "Cenas" sobe o clipe de fora numa chamada só (paridade com sceneRef).
        if ($r->hasFile('file')) {
            $r->validate(['file' => 'file|mimes:mp4,mov|max:102400']); // AUD-006/AUD-024: 100MB
            $file = $r->file('file');
            $ext = strtolower((string) ($file->guessExtension() ?: 'mp4'));
            $url = StudioController::storeUploadedFile($file, $ext, 'video');
        } else {
            $url = trim((string) $r->input('url', ''));
            if (! StudioController::isOwnMediaUrl($url)) {
                return response()->json(['ok' => false, 'error' => 'URL de vídeo inválida'], 422);
            }
        }
        $this->flow->patchScene($p, $i, ['video_url' => $url, 'video_status' => 'ready']);

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/motion { index, motionRefUrl? | file } — 🕺 MOTION TRANSFER (RunningHub):
     *  transfere o movimento de um vídeo-guia pro personagem do keyframe da cena. PREMIUM e LENTO (~8 min)
     *  → assíncrono, cobra crédito alto (config services.motion.cost_credits) e estorna se falhar. */
    public function motion(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        $sc = (array) (array_values((array) $p->storyboard)[$i] ?? []);
        if ($sc === []) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        // A cena precisa de um keyframe pronto (é o personagem-alvo que vai ganhar o movimento).
        $keyframe = (string) ($sc['keyframe_url'] ?? '');
        if ($keyframe === '' || ! StudioController::isOwnMediaUrl($keyframe)) {
            return response()->json(['ok' => false, 'error' => 'gere o keyframe da cena antes de aplicar movimento'], 422);
        }
        // Vídeo-guia: upload direto (curto) OU uma URL própria já no nosso storage.
        if ($r->hasFile('file')) {
            $r->validate(['file' => 'file|mimes:mp4,mov|max:51200']); // 50MB — o guia é curto
            $file = $r->file('file');
            $ext = strtolower((string) ($file->guessExtension() ?: 'mp4'));
            $motionUrl = StudioController::storeUploadedFile($file, $ext, 'video');
        } else {
            $motionUrl = trim((string) $r->input('motionRefUrl', ''));
            if (! StudioController::isOwnMediaUrl($motionUrl)) {
                return response()->json(['ok' => false, 'error' => 'vídeo-guia inválido'], 422);
            }
        }

        $cfg = (array) config('services.motion');
        if (empty($cfg['workflow_id'])) {
            return response()->json(['ok' => false, 'error' => 'movimento indisponível'], 404);
        }
        $cost = (int) ($cfg['cost_credits'] ?? 300);
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para vídeo.'], 402);
        }

        $this->flow->patchScene($p, $i, ['video_status' => 'generating']);
        $nodes = [
            ['nodeId' => (string) $cfg['image_node'], 'fieldName' => 'image', 'fieldValue' => $keyframe],  // 106 = imagem-alvo
            ['nodeId' => (string) $cfg['video_node'], 'fieldName' => 'video', 'fieldValue' => $motionUrl],  // 130 = vídeo-guia
        ];
        MotionTransferJob::dispatch($p->id, $t->id, $i, (string) $cfg['workflow_id'], $nodes, (string) ($cfg['instance_type'] ?? 'default'), 1, $cost);

        return response()->json(['ok' => true, 'status' => 'generating', 'project' => $p->fresh()]);
    }

    /** POST /api/animation/structure { theme, lang?, persona?, pace? } — 🎬 SALA DE ROTEIRO
     *  (paridade com story-structure): destila o tema numa ESPINHA dramática editável ANTES de
     *  criar o projeto. Texto puro (sem cota de mídia); a estrutura aprovada vai no create e é
     *  dobrada na direção de roteiro do parser. */
    public function structure(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $theme = trim((string) $r->input('theme'));
        if ($theme === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o tema/ideia da história'], 422);
        }
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        // O engine aceita `gen_lines` em /v1/storystructure, mas o console nunca enviava aqui: a
        // espinha dramática usava o default do engine e ignorava o seletor de modelo de texto da UI.
        // Aqui o projeto ainda não existe (estrutura vem ANTES do create), então o modelo sai do
        // request + plano do tenant — mesma fonte usada nos outros call sites de texto.
        $tm = $this->textModelFor($r, $t->plan);
        $res = EngineClient::make(120)
            ->post('/v1/storystructure', [
                'theme' => $theme,
                'lang' => $lang,
                'persona' => StudioController::textPersona($r, $t),
                'gen_lines' => $this->textGenLines($tm),
            ]);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível montar a estrutura agora'], 502);
        }

        return response()->json(['ok' => true, 'structure' => StudioController::sanitizeStructure($res->json())]);
    }

    /** POST /api/animation/{id}/frame-variations { index, count? } — 🎲 N candidatas do keyframe
     *  (paridade com image-variations): SÍNCRONO de propósito (N≤4 × ~10-15s), cobra POR imagem
     *  (bucket image, reserve-then-consume; falhou → estorna só ela). O usuário escolhe uma e o
     *  front fixa via frame-set (sem custo). */
    public function frameVariations(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        $payload = $this->flow->framePayload($p, $i);
        if (! $payload) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        $gm = $this->flow->imageModel($p, 'frame', $t->plan);
        $weight = $this->usage->weightFor('image');
        $count = max(2, min(4, (int) $r->input('count', 3)));
        $urls = [];
        $failed = 0;
        $engine = fn () => EngineClient::make(120);
        for ($k = 0; $k < $count; $k++) {
            if (! $this->usage->tryConsume($t, 'image', $weight, $gm?->cost_credits)) {
                break; // sem saldo no meio → devolve o que já gerou (igual ao clássico)
            }
            try {
                $url = (string) $engine()->post('/v1/image', $payload)->json('url');
            } catch (\Throwable) {
                $url = '';
            }
            if ($url === '') {
                $this->usage->refund($t, 'image', $weight, $gm?->cost_credits);
                $failed++;

                continue;
            }
            $urls[] = $url;
        }
        if ($urls === []) {
            return response()->json(['ok' => false, 'error' => 'não foi possível gerar as variações agora'], 502);
        }

        return response()->json(['ok' => true, 'urls' => $urls, 'failed' => $failed]);
    }

    /** POST /api/animation/{id}/frame-edit { index, prompt } — ✏️ EDITA o keyframe por i2i
     *  (paridade com story-edit-image): scaffold "mude só isto", sem anchorIdentity, custo do
     *  modelo i2i de referência. Assíncrono (reusa o AnimationFrameJob). */
    public function frameEdit(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        $sc = array_values((array) $p->storyboard)[$i] ?? null;
        $cur = (string) ($sc['keyframe_url'] ?? '');
        if (! $sc || $cur === '') {
            return response()->json(['ok' => false, 'error' => 'gere o keyframe antes de editar'], 422);
        }
        if ($sc['locked'] ?? false) {
            return response()->json(['ok' => false, 'error' => 'cena travada — destrave para editar'], 422);
        }
        $edit = mb_substr(trim((string) $r->input('prompt')), 0, 500);
        if ($edit === '') {
            return response()->json(['ok' => false, 'error' => 'descreva a mudança'], 422);
        }
        $gm = GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
            ?? $this->flow->imageModel($p, 'frame', $t->plan);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        $payload = array_merge([
            // Scaffold do clássico: muda SÓ o pedido, preserva o resto (personagem/cores/fundo).
            'prompt' => GenPayload::editPrompt($edit),
            'aspect' => $p->aspect,
            'style' => $p->style,
            'imageUrls' => [$cur],
        ], $this->flow->imagePayloadBase($gm));
        $this->flow->patchScene($p, $i, ['keyframe_status' => 'generating']);
        AnimationFrameJob::dispatch($p->id, $t->id, $i, $payload, $weight, $gm?->cost_credits);

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/review — 🩺 SCRIPT DOCTOR (paridade com story-review): critica o
     *  roteiro atual (título + narração/ação por cena). Texto puro — não gera nem debita mídia. */
    public function review(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $scenes = [];
        foreach (array_values((array) $p->storyboard) as $sc) {
            $scenes[] = [
                'title' => (string) ($sc['title'] ?? ''),
                'voiceover' => trim((string) ($sc['narration'] ?? '')) ?: (string) ($sc['action'] ?? ''),
            ];
        }
        if ($scenes === []) {
            return response()->json(['ok' => false, 'error' => 'Estruture o roteiro antes de pedir a crítica.'], 422);
        }
        // Idem: `gen_lines` é aceito em /v1/storyreview e nunca era enviado — o script doctor
        // rodava no modelo default do engine, à revelia do seletor de texto.
        $res = EngineClient::make(120)
            ->post('/v1/storyreview', [
                'theme' => $p->title !== '' ? $p->title : mb_substr($p->script, 0, 200),
                'lang' => $p->lang,
                'scenes' => $scenes,
                'gen_lines' => $this->textGenLines($this->textModelFor($r, $t->plan)),
            ]);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível revisar agora'], 502);
        }

        return response()->json(['ok' => true, 'overall' => (string) $res->json('overall'), 'notes' => (array) $res->json('notes')]);
    }

    /** Seções regeneráveis do plano (paridade com o Filme; espelha filmSectionField do engine):
     *  campo da CENA que a seção reescreve; musica reescreve só o music_prompt do projeto. */
    private const PLAN_SECTIONS = ['roteiro' => 'title', 'storyboard' => 'image_prompt', 'narracao' => 'narration', 'camera' => 'video_prompt', 'musica' => ''];

    /** POST /api/animation/{id}/section { section } → REGENERA uma seção do plano
     *  (roteiro|storyboard|narracao|camera|musica) mantendo TODO o resto (a história completa
     *  manda). Mapeia cena↔beat pro /v1/filmsection do engine (o MESMO do Filme — unificação):
     *  title↔title · image_prompt↔frame_prompt · video_prompt↔move_prompt · narration↔voiceover.
     *  Síncrono (texto, ~30-90s) e cobra 1 texto; diálogos/keyframes/clipes NUNCA são tocados. */
    public function section(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $sb = array_values((array) $p->storyboard);
        $section = (string) $r->input('section');
        if (! array_key_exists($section, self::PLAN_SECTIONS)) {
            return response()->json(['ok' => false, 'error' => 'seção inválida'], 422);
        }
        if ($sb === []) {
            return response()->json(['ok' => false, 'error' => 'estruture o roteiro primeiro'], 422);
        }
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = EngineClient::make(180)
            ->post('/v1/filmsection', [
                'brief' => trim((string) $p->title) !== '' ? $p->title."\n".mb_substr((string) $p->script, 0, 1800) : mb_substr((string) $p->script, 0, 2000),
                'style' => (string) $p->style,
                'lang' => (string) $p->lang,
                'clipDuration' => '5',
                'section' => $section,
                'beats' => array_map(fn ($sc) => [
                    'title' => (string) ($sc['title'] ?? ''),
                    'frame_prompt' => trim((string) ($sc['image_prompt'] ?? '')) ?: (string) ($sc['action'] ?? ''),
                    'move_prompt' => trim((string) ($sc['video_prompt'] ?? '')) ?: (string) ($sc['action'] ?? ''),
                    'voiceover' => (string) ($sc['narration'] ?? ''),
                ], $sb),
                'finalFramePrompt' => '',
                'gen_lines' => $this->textGenLines($tm),
            ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'Não foi possível regenerar a seção agora.'], 502);
        }
        $values = array_map(fn ($v) => (string) $v, (array) $res->json('beat_values'));
        $field = self::PLAN_SECTIONS[$section];
        if ($field !== '' && count($values) < count($sb)) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'A seção voltou incompleta — tente de novo.'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);
        // Merge sob lock: só o campo da seção muda (keyframe_url/clip_url/dialogue ficam intactos).
        DB::transaction(function () use ($p, $section, $field, $values, $res) {
            $fresh = AnimationProject::lockForUpdate()->find($p->id);
            if (! $fresh) {
                return;
            }
            $patch = [];
            if ($field !== '') {
                $sb = array_values((array) $fresh->storyboard);
                foreach ($sb as $i => $sc) {
                    // Cena TRAVADA (🔒 locked) não é tocada — paridade com o approved do Filme.
                    if (! empty($sc['locked']) || ! array_key_exists($i, $values)) {
                        continue;
                    }
                    $sb[$i][$field] = $values[$i];
                }
                $patch['storyboard'] = $sb;
            }
            if ($section === 'musica' && ($mp = trim((string) $res->json('music_prompt'))) !== '') {
                $patch['music_prompt'] = mb_substr($mp, 0, 400);
            }
            if ($patch !== []) {
                $fresh->update($patch);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/element-scenario { index, scenarioId } — 🌍 usa um CENÁRIO da
     *  biblioteca como referência de uma locação (imagem-âncora vira ref; sem custo). */
    public function elementScenario(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $this->project($r, $id);
        $s = Scenario::where('tenant_id', $t->id)->find((int) $r->input('scenarioId'));
        if (! $s || (string) $s->image_url === '') {
            return response()->json(['ok' => false, 'error' => 'cenário sem imagem-âncora'], 422);
        }
        $i = (int) $r->input('index', -1);
        $p = DB::transaction(function () use ($id, $i, $s) {
            $p = AnimationProject::lockForUpdate()->findOrFail($id);
            $els = (array) $p->elements;
            if (! isset($els['locations'][$i])) {
                return null;
            }
            $els['locations'][$i]['ref_url'] = (string) $s->image_url;
            $els['locations'][$i]['status'] = '';
            // Vínculo com a biblioteca: além de rastrear o que usa o quê, é ele que impede o
            // AnimationElementJob de re-cadastrar este cenário como novo numa regeração.
            $els['locations'][$i]['scenario_id'] = $s->id;
            if (trim((string) $s->description) !== '') {
                $els['locations'][$i]['visual_prompt'] = mb_substr(trim((string) $s->description), 0, 1200);
            }
            $p->update(['elements' => $els]);

            return $p;
        });
        if (! $p) {
            return response()->json(['ok' => false, 'error' => 'locação inexistente'], 422);
        }

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/frames — gera os keyframes pendentes (lote). */
    public function frames(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        if ($p->status === 'elements') {
            $p->update(['status' => 'storyboard']);
        }
        $n = $this->flow->dispatchAllFrames($p, $this->tenant($r));

        return response()->json(['ok' => true, 'dispatched' => $n]);
    }

    /** Opções por clipe (paridade clássica): duration 5|10 e "melhorar antes de animar". */
    private function clipOpts(Request $r): array
    {
        return [
            'duration' => in_array($r->input('duration'), ['5', '10'], true) ? (string) $r->input('duration') : null,
            'upscale' => filter_var($r->input('upscale', false), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** POST /api/animation/{id}/scene { index, model?, duration?, upscale? } — anima UMA cena
     *  (diálogo + i2v + mux; upscale = melhora o keyframe antes, custo do modelo de edição). */
    public function scene(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $model = $r->filled('model') ? (string) $r->input('model') : null;
        if ($err = $this->flow->dispatchScene($p, $this->tenant($r), (int) $r->input('index', -1), $model, $this->clipOpts($r))) {
            return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') ? 402 : 422);
        }

        return response()->json(['ok' => true]);
    }

    /** POST /api/animation/{id}/scene-add { index? } — insere uma cena EM BRANCO após index (ou no
     *  fim). Paridade com story-scene-add. Sem custo (nasce sem mídia). */
    public function sceneAdd(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        if ($err = $this->flow->editStoryboard($p, 'add', (int) $r->input('index', -1))) {
            return response()->json(['ok' => false, 'error' => $err], 422);
        }

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/scene-remove { index } — remove a cena (paridade story-scene-remove). */
    public function sceneRemove(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        if ($err = $this->flow->editStoryboard($p, 'remove', (int) $r->input('index', -1))) {
            return response()->json(['ok' => false, 'error' => $err], 422);
        }

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/scene-move { index, dir } — reordena a cena (dir -1 sobe, +1 desce). */
    public function sceneMove(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $dir = (int) $r->input('dir', 1) < 0 ? -1 : 1;
        if ($err = $this->flow->editStoryboard($p, 'move', (int) $r->input('index', -1), $dir)) {
            return response()->json(['ok' => false, 'error' => $err], 422);
        }

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/scene-ref — referência PRÓPRIA da cena (âncora i2i). multipart file
     *  OU { index, url } (do nosso storage/galeria); { index, url:"" } limpa. Paridade com
     *  story-reference / story-reference-url. Sem custo. */
    public function sceneRef(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $i = (int) $r->input('index', -1);
        if (! isset(array_values((array) $p->storyboard)[$i])) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        if ($r->hasFile('file')) {
            $r->validate(['file' => 'file|mimes:jpg,jpeg,png,webp|max:10240']); // AUD-006/AUD-024
            $file = $r->file('file');
            $ext = strtolower((string) ($file->guessExtension() ?: 'jpg'));
            $url = StudioController::storeUploadedFile($file, $ext, 'image');
        } else {
            $url = trim((string) $r->input('url', ''));
            if ($url !== '' && ! StudioController::isOwnMediaUrl($url)) {
                return response()->json(['ok' => false, 'error' => 'URL de imagem inválida (use uma mídia sua)'], 422);
            }
        }
        $this->flow->patchScene($p, $i, ['ref_url' => $url]);

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/final-upload — sobe o vídeo final montado POR FORA (editor externo)
     *  como o resultado do projeto: vira um Draft (galeria → aprovar → publicar). Paridade com
     *  story-final-upload; fecha o ciclo do export ZIP. multipart: file (mp4/mov), platforms[]?. */
    public function finalUpload(Request $r, int $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $r->validate(['file' => 'required|file|mimes:mp4,mov|max:204800']); // AUD-006/AUD-024
        $file = $r->file('file');
        $ext = strtolower((string) ($file->guessExtension() ?: 'mp4'));
        $url = StudioController::storeUploadedFile($file, $ext, 'video');

        $platforms = Networks::only($r->input('platforms', []));
        $styleTag = in_array($p->mode, ['historia', 'quadrinhos'], true) ? $p->mode : 'animacao';

        $d = Draft::create([
            'tenant_id' => $t->id,
            'keyword' => mb_substr($p->title !== '' ? $p->title : 'Desenho animado', 0, 80),
            'video_url' => $url,
            'story' => ['theme' => $p->title ?: 'Desenho animado', 'lang' => $p->lang, 'status' => 'ready', 'scenes' => []],
            'media' => [['id' => Draft::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => $styleTag, 'platforms' => $platforms]],
        ]);
        $p->update(['final_url' => $url, 'final_draft_id' => $d->id, 'status' => 'done', 'auto' => false, 'error' => '']);

        return response()->json(['ok' => true, 'project' => $p->fresh()]);
    }

    /** POST /api/animation/{id}/scenes { duration?, upscale? } — anima as cenas pendentes (lote). */
    public function scenes(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        if (in_array($p->status, ['elements', 'storyboard'], true)) {
            $p->update(['status' => 'animating']);
        }
        $n = $this->flow->dispatchAllScenes($p, $this->tenant($r), $this->clipOpts($r));

        return response()->json(['ok' => true, 'dispatched' => $n]);
    }

    /** POST /api/animation/{id}/assemble — monta o vídeo final. Modo animacao: { music?,
     *  musicPrompt?, transition? } → /v1/filmassemble. Modos NARRADOS (historia/quadrinhos): o
     *  request aceita o pacote COMPLETO de montagem do storyVideo (voice_id, audioStyle/Quality,
     *  música, transições, grade, grain, ambience, colorMatch, smooth, legenda, sfx[]/vfx[],
     *  platforms…) → /v1/storyvideo via builder compartilhado. */
    public function assemble(Request $r, int $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $t = $this->tenant($r);
        if ($p->isNarrated()) {
            if ($err = $this->flow->dispatchStoryAssemble($p, $t, $r)) {
                return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') || str_contains($err, 'insuficientes') ? 402 : 422);
            }

            return response()->json(['ok' => true, 'status' => 'assembling']);
        }
        $opts = [
            'music' => filter_var($r->input('music', true), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => (string) $r->input('musicPrompt', ''),
            'transition' => (string) $r->input('transition', ''),
        ];
        if ($err = $this->flow->dispatchAssemble($p, $t, $opts)) {
            return response()->json(['ok' => false, 'error' => $err], str_contains($err, 'Limite') ? 402 : 422);
        }

        return response()->json(['ok' => true, 'status' => 'assembling']);
    }

    /** POST /api/animation/{id}/auto — MODO AUTOMÁTICO DESATIVADO (2026-07-18): já entrou em loop
     *  de re-despacho em produção (2026-07-15). Gere passo a passo: /elements → /frames → /scenes →
     *  /assemble. Endpoint mantido (não removido) só pra não quebrar clientes antigos com 404 cru. */
    public function auto(Request $r, int $id): JsonResponse
    {
        $this->project($r, $id); // 404/escopo de tenant, mesmo desativado

        return response()->json(['ok' => false, 'error' => 'modo automático desativado — gere passo a passo (elementos → storyboard → cenas → montagem)'], 410);
    }
}
