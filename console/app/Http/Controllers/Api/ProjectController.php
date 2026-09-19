<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesTextModel;
use App\Http\Controllers\Controller;
use App\Models\Character;
use App\Models\Element;
use App\Models\Project;
use App\Models\Prompt;
use App\Models\Scenario;
use App\Models\Scene;
use App\Services\ElencoDaHistoria;
use App\Support\AssetPrompt;
use App\Support\EngineClient;
use App\Support\Screenplay;
use App\Support\ScriptDoctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PROJETOS/ESCALETAS (F4). CRUD por tenant. O index já traz as cenas ordenadas (a escaleta).
 */
class ProjectController extends Controller
{
    use ResolvesTextModel;

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function project(Request $r, int|string $id): Project
    {
        return Project::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    public function index(Request $r): JsonResponse
    {
        return response()->json(
            Project::where('tenant_id', $this->tenant($r)->id)
                ->with('scenes.shots')->latest('updated_at')->limit(100)->get()
        );
    }

    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'name' => 'required|string|max:120',
            'argumento' => 'nullable|string|max:4000',
        ]);
        $p = Project::create([
            'tenant_id' => $this->tenant($r)->id,
            'name' => trim($data['name']),
            'argumento' => trim((string) ($data['argumento'] ?? '')) ?: null,
        ]);

        return response()->json(['ok' => true, 'project' => $p->load('scenes.shots')], 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $p = $this->project($r, $id);
        $data = $r->validate([
            'name' => 'nullable|string|max:120',
            'argumento' => 'nullable|string|max:4000',
            'image_model' => 'nullable|string|max:80',
            'image_style' => 'nullable|string|max:40',
        ]);
        // PADRÃO VISUAL da história (modelo + técnica): trocar aqui vale das próximas gerações em
        // diante — o que já tem imagem não é refeito sozinho (regerar é decisão e custa crédito).
        if (array_key_exists('image_model', $data)) {
            $p->image_model = trim((string) $data['image_model']) ?: null;
        }
        if (array_key_exists('image_style', $data)) {
            $p->image_style = AssetPrompt::normStyle($data['image_style']);
        }
        if (($n = trim((string) ($data['name'] ?? ''))) !== '') {
            $p->name = $n;
        }
        // Premissa VAZIA não apaga a salva (2026-07-29): o dirty-check do front (426b0ab) trata
        // o caminho da tela, mas qualquer outro chamador — retry, MCP, tela nova — reabriria o
        // mesmo apagão. É o prompt do filme inteiro; ninguém "limpa" isso de propósito.
        if (array_key_exists('argumento', $data) && trim((string) $data['argumento']) !== '') {
            $p->argumento = trim((string) $data['argumento']);
        }
        $p->save();

        return response()->json(['ok' => true, 'project' => $p->load('scenes.shots')]);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        $this->project($r, $id)->delete(); // cascade apaga as cenas

        return response()->json(['ok' => true]);
    }

    /** GET /api/projects/{id}/bible → a "Bíblia do Projeto" (mini-GDD) em Markdown, pra baixar. */
    public function bible(Request $r, string $id): Response
    {
        $tid = $this->tenant($r)->id;
        $p = Project::where('id', $id)->where('tenant_id', $tid)->with('scenes.shots')->firstOrFail();

        $charIds = collect($p->scenes)->flatMap(fn ($s) => $s->character_ids ?? [])->unique()->values()->all();
        $scenIds = collect($p->scenes)->pluck('scenario_id')->filter()->unique()->values()->all();
        $chars = Character::where('tenant_id', $tid)->whereIn('id', $charIds)->get()->keyBy('id');
        $scens = Scenario::where('tenant_id', $tid)->whereIn('id', $scenIds)->get()->keyBy('id');

        $md = $this->buildBible($p, $chars, $scens);
        $slug = \Illuminate\Support\Str::slug($p->name) ?: 'projeto';

        return response($md, 200)
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="biblia-'.$slug.'.md"');
    }

    /**
     * GET /api/projects/{id}/screenplay?format=fountain|fdx → o roteiro NO FORMATO DO MERCADO.
     *
     * A escaleta era um beco sem saída: dava pra escrever, revisar e produzir aqui dentro, mas o
     * único arquivo que saía era a Bíblia em Markdown — que nenhum produtor ou montador abre na
     * ferramenta dele. Fountain e FDX são o que Final Draft, WriterDuet, Celtx, Arc Studio,
     * Highland e Beat leem, e descrevem a estrutura que as `scenes` já têm.
     */
    public function screenplay(Request $r, string $id): Response
    {
        $tid = $this->tenant($r)->id;
        $p = Project::where('id', $id)->where('tenant_id', $tid)->with('scenes.shots')->firstOrFail();
        $fdx = strtolower((string) $r->query('format')) === 'fdx';

        $scens = Scenario::where('tenant_id', $tid)
            ->whereIn('id', collect($p->scenes)->pluck('scenario_id')->filter()->unique()->values()->all())
            ->get(['id', 'name'])->keyBy('id');

        $slug = \Illuminate\Support\Str::slug($p->name) ?: 'roteiro';

        return response($fdx ? Screenplay::fdx($p, $scens) : Screenplay::fountain($p, $scens), 200)
            ->header('Content-Type', $fdx ? 'application/xml; charset=utf-8' : 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$slug.($fdx ? '.fdx' : '.fountain').'"');
    }

    private function buildBible(Project $p, $chars, $scens): string
    {
        $L = ['# '.$p->name, '', '> Bíblia do Projeto (mini-GDD) — gerada no FoxAssets.'];
        if ($p->argumento) {
            $L[] = '';
            $L[] = '## Argumento';
            $L[] = '';
            $L[] = $p->argumento;
        }

        if ($chars->isNotEmpty()) {
            $L[] = '';
            $L[] = '## Personagens';
            foreach ($chars as $c) {
                $L[] = '';
                $L[] = '### '.$c->name.($c->archetype ? ' _('.$c->archetype.')_' : '');
                if ($c->logline) {
                    $L[] = '';
                    $L[] = '> '.$c->logline;
                }
                $b = (array) ($c->bible ?? []);
                $this->kv($L, 'Desejo', $this->join([$this->get($b, 'desejo.objetivo'), $this->get($b, 'desejo.subjetivo')]));
                $this->kv($L, 'Conflito', $this->join([$this->get($b, 'conflito.tipo'), $this->get($b, 'conflito.natureza'), $this->get($b, 'conflito.descricao')]));
                $this->kv($L, 'Antagonista', $this->get($b, 'antagonista'));
                $this->kv($L, 'Físico', $this->join([$this->get($b, 'fisico.apelido'), $this->get($b, 'fisico.idade'), $this->get($b, 'fisico.aparencia')]));
                $this->kv($L, 'Voz', $this->join([$this->get($b, 'fisico.voz.timbre'), $this->get($b, 'fisico.voz.ritmo'), $this->get($b, 'fisico.voz.vocabulario')]));
                $this->kv($L, 'Psicológico', $this->join([$this->get($b, 'psicologico.personalidade'), $this->get($b, 'psicologico.passado')]));
                $this->kv($L, 'Arco', $this->join([$this->get($b, 'arco.quer_longo'), $this->get($b, 'arco.quer_agora'), $this->get($b, 'arco.obstaculo')]));
            }
        }

        if ($scens->isNotEmpty()) {
            $L[] = '';
            $L[] = '## Cenários';
            foreach ($scens as $sc) {
                $L[] = '';
                $L[] = '### '.$sc->name;
                $s = (array) ($sc->spec ?? []);
                $this->kv($L, 'Função dramática', $this->get($s, 'funcao_dramatica'));
                $this->kv($L, 'Tempo/espaço', $this->join([$this->get($s, 'tempo_espaco.tempo'), $this->get($s, 'tempo_espaco.espaco')]));
                $this->kv($L, 'Atmosfera', $this->join([$this->get($s, 'atmosfera.mood'), $this->get($s, 'atmosfera.luz'), $this->get($s, 'atmosfera.clima'), $this->get($s, 'atmosfera.paleta')]));
                $riscos = $this->get($s, 'riscos');
                if (is_array($riscos) && $riscos) {
                    $this->kv($L, 'Riscos', implode('; ', array_map('strval', $riscos)));
                }
            }
        }

        $L[] = '';
        $L[] = '## Escaleta';
        $n = 1;
        foreach ($p->scenes as $cena) {
            $cab = array_filter([$cena->local, $cena->int_ext, $cena->tempo]);
            $head = $cab ? ' — '.implode('/', $cab) : '';
            $L[] = '';
            $L[] = '### Cena '.$n.$head.($cena->virada ? ' _(virada)_' : '');
            if ($cena->scenario_id && $scens->has($cena->scenario_id)) {
                $this->kv($L, 'Cenário', $scens[$cena->scenario_id]->name);
            }
            $nomes = collect($cena->character_ids ?? [])->map(fn ($id) => $chars[$id]->name ?? null)->filter()->implode(', ');
            if ($nomes) {
                $this->kv($L, 'Personagens', $nomes);
            }
            $this->kv($L, 'Objetivo', $cena->objetivo_cena);
            $this->kv($L, 'Conflito', $cena->conflito_cena);
            $this->kv($L, 'Resumo', $cena->resumo);
            $n++;
        }

        return implode("\n", $L)."\n";
    }

    /**
     * POST /api/projects/{id}/plan { brief, cenas?, lang? } → a IA escreve a ESCALETA inteira.
     *
     * POR QUE EXISTE: montar a escaleta era 100% manual — "Adicionar cena" e preencher local,
     * INT/EXT, tempo, objetivo, conflito e resumo, uma a uma. Para um filme de 12 cenas isso é
     * meia hora de digitação antes de qualquer geração, e é justamente o trabalho que o modelo
     * faz bem: dar estrutura dramática a uma ideia.
     *
     * A IA recebe o ELENCO e os CENÁRIOS disponíveis com seus ids e escolhe quem entra em cada
     * cena — é isso que faz a escaleta gerada já sair ancorada (character_ids → IDENTITY LOCK +
     * âncora de imagem no canvas). Ids inventados são descartados na validação abaixo.
     *
     * NÃO apaga o que existe: as cenas geradas entram DEPOIS das atuais (`ordem` continua de onde
     * parou), então rodar de novo acrescenta em vez de destruir trabalho.
     */
    public function plan(Request $r, string $id): JsonResponse
    {
        $t = $this->tenant($r);
        $p = $this->project($r, $id);
        $data = $r->validate([
            'brief' => 'required|string|max:4000',
            'cenas' => 'nullable|integer|min:2|max:24',
            'lang' => 'nullable|string|max:10',
            // PADRÃO VISUAL da história: escolhido aqui, no Roteiro, e herdado por todo personagem,
            // cenário e elemento que este plano criar. É o que faz o filme sair com UMA estética.
            'image_model' => 'nullable|string|max:80',
            'image_style' => 'nullable|string|max:40',
        ]);
        $n = (int) ($data['cenas'] ?? 8);
        $padraoVisual = array_filter([
            'image_model' => trim((string) ($data['image_model'] ?? '')) ?: null,
            'image_style' => isset($data['image_style']) ? AssetPrompt::normStyle($data['image_style']) : null,
        ]);
        if ($padraoVisual !== []) {
            $p->update($padraoVisual);
        }

        $chars = Character::where('tenant_id', $t->id)->get(['id', 'name', 'description'])
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->name, 'quem' => mb_substr(trim((string) $c->description), 0, 200)])->all();
        $cens = Scenario::where('tenant_id', $t->id)->get(['id', 'name', 'description'])
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->name, 'onde' => mb_substr(trim((string) $c->description), 0, 200)])->all();
        $elms = Element::where('tenant_id', $t->id)->get(['id', 'name', 'description'])
            ->map(fn ($c) => ['id' => $c->id, 'nome' => $c->name, 'oque' => mb_substr(trim((string) $c->description), 0, 200)])->all();

        // Quem escreve as cenas é a persona "📝 Escaleta" da aba Prompts — não um texto preso
        // aqui dentro. Era a única etapa do método que exigia rebuild pra ajustar, justamente a
        // que decide a forma do filme inteiro.
        if (! $sys = $this->personaContent($t->id, 'Escaleta')) {
            return response()->json(['ok' => false, 'error' => "A persona '📝 Escaleta' não está na aba Prompts."], 404);
        }

        $user = "PREMISSA: {$data['brief']}\n\nNÚMERO DE CENAS: {$n}\n\nELENCO: ".json_encode($chars, JSON_UNESCAPED_UNICODE)
            ."\n\nCENÁRIOS: ".json_encode($cens, JSON_UNESCAPED_UNICODE)
            ."\n\nELEMENTOS: ".json_encode($elms, JSON_UNESCAPED_UNICODE);

        $res = EngineClient::make(180)->post('/v1/chat', [
            'system' => $sys,
            'message' => $user,
            'json' => true,
            'maxTokens' => 6000,     // 12 cenas com dramaturgia passam folgado dos 2000 do default
            'gen_lines' => $this->textGenLines($this->textModelFor($r, $t->plan ?? null)),
        ]);
        $cenas = $this->cenasDoJson((string) $res->json('text'));
        if ($cenas === []) {
            return response()->json(['ok' => false, 'error' => 'A IA não devolveu uma escaleta utilizável. Tente de novo.'], 502);
        }

        $idsChar = array_column($chars, 'id');
        $idsCen = array_column($cens, 'id');

        // CENÁRIOS QUE A HISTÓRIA PEDE: a IA descreve os lugares que ainda não existem na
        // biblioteca e nós criamos a ficha (sem imagem — ela sai depois, na aba Cenários, como a
        // base do personagem). Sem isto, quem começa com a biblioteca vazia — o caso normal —
        // recebia a escaleta inteira com `scenario_id` nulo e tinha que cadastrar tudo na mão
        // antes de ter qualquer âncora de lugar.
        $porNome = [];
        foreach ($cens as $c) {
            $porNome[mb_strtolower($c['nome'])] = $c['id'];
        }
        $novosCen = 0;
        foreach ($this->listaDoJson((string) $res->json('text'), 'cenarios') as $c) {
            $nome = trim((string) ($c['nome'] ?? ''));
            if ($nome === '' || isset($porNome[mb_strtolower($nome)])) {
                continue;   // já existe (ou veio sem nome) — não duplica a ficha
            }
            $novo = Scenario::create([
                'tenant_id' => $t->id,
                'name' => mb_substr($nome, 0, 80),
                'description' => mb_substr(trim((string) ($c['descricao'] ?? '')), 0, 2000),
            ]);
            $porNome[mb_strtolower($nome)] = $novo->id;
            $idsCen[] = $novo->id;
            $novosCen++;
        }
        // PERSONAGENS QUE A HISTÓRIA PEDE (2026-07-28, espelho do bloco acima): antes, personagem
        // que a IA inventava era DESCARTADO em silêncio pela interseção de ids — o fluxo "prompt
        // do filme → roteiro → fichas" exige que ele vire ficha na biblioteca (sem imagem; a base
        // e o lock saem depois, na aba Personagens). A cena referencia o novo pelo NOME (`elenco`),
        // mesmo par id/nome que cenário já usa (`scenario_id`/`cenario`).
        $porNomeChar = [];
        foreach ($chars as $c) {
            $porNomeChar[mb_strtolower($c['nome'])] = $c['id'];
        }
        $novosChar = 0;
        foreach ($this->listaDoJson((string) $res->json('text'), 'personagens') as $c) {
            $nome = trim((string) ($c['nome'] ?? ''));
            if ($nome === '' || isset($porNomeChar[mb_strtolower($nome)])) {
                continue;
            }
            $novo = Character::create([
                'tenant_id' => $t->id,
                'name' => mb_substr($nome, 0, 80),
                'description' => mb_substr(trim((string) ($c['descricao'] ?? '')), 0, 2000),
            ]);
            $porNomeChar[mb_strtolower($nome)] = $novo->id;
            $idsChar[] = $novo->id;
            $novosChar++;
        }
        // ELEMENTOS QUE A HISTÓRIA PEDE (2026-08-30, terceiro espelho): a coluna `scenes.element_ids`
        // existia desde o catálogo de objetos e NUNCA era preenchida pelo plano — o objeto que a
        // história reconhece de uma cena pra outra (o carro, a carta, o brinquedo) só entrava se
        // alguém cadastrasse na mão e amarrasse cena a cena. Sem isto o "botão único" não teria o
        // que gerar, e o elo de identidade do objeto ficava aberto.
        $porNomeElm = [];
        foreach ($elms as $c) {
            $porNomeElm[mb_strtolower($c['nome'])] = $c['id'];
        }
        $novosElm = 0;
        foreach ($this->listaDoJson((string) $res->json('text'), 'elementos') as $c) {
            $nome = trim((string) ($c['nome'] ?? ''));
            if ($nome === '' || isset($porNomeElm[mb_strtolower($nome)])) {
                continue;
            }
            $cat = (string) ($c['categoria'] ?? '');
            $novo = Element::create([
                'tenant_id' => $t->id,
                'name' => mb_substr($nome, 0, 120),
                // Categoria inventada pelo modelo não entra: a tela lista por departamento e um
                // valor fora do enum sumiria do filtro.
                'categoria' => array_key_exists($cat, Element::CATEGORIAS) ? $cat : 'prop',
                'description' => mb_substr(trim((string) ($c['descricao'] ?? '')), 0, 2000),
            ]);
            $porNomeElm[mb_strtolower($nome)] = $novo->id;
            $novosElm++;
        }

        $ordem = (int) $p->scenes()->max('ordem');
        $criadas = [];
        foreach (array_slice($cenas, 0, $n) as $c) {
            $criadas[] = Scene::create([
                'tenant_id' => $t->id,
                'project_id' => $p->id,
                'ordem' => ++$ordem,
                // Ids inventados pelo modelo não viram vínculo: sem esta interseção a cena
                // apontaria pra personagem que não existe e a âncora sumiria em silêncio.
                // `elenco` (nomes) é como a IA referencia os personagens que ELA criou — o
                // mapa nome→id acabou de ganhar as fichas novas, então o merge resolve os dois.
                'character_ids' => array_values(array_unique(array_merge(
                    array_intersect(array_map('intval', (array) ($c['character_ids'] ?? [])), $idsChar),
                    array_values(array_filter(array_map(
                        fn ($n) => $porNomeChar[mb_strtolower(trim((string) $n))] ?? null,
                        (array) ($c['elenco'] ?? [])
                    ))),
                ))),
                // Os OBJETOS em quadro, pelo nome — mesmo par nome→id de `elenco`/`cenario`.
                'element_ids' => array_values(array_unique(array_filter(array_map(
                    fn ($n) => $porNomeElm[mb_strtolower(trim((string) $n))] ?? null,
                    (array) ($c['objetos'] ?? [])
                )))),
                // id válido manda; senão cai no nome (que é como a IA referencia o que ela mesma criou).
                'scenario_id' => in_array((int) ($c['scenario_id'] ?? 0), $idsCen, true)
                    ? (int) $c['scenario_id']
                    : ($porNome[mb_strtolower(trim((string) ($c['cenario'] ?? '')))] ?? null),
                'local' => mb_substr(trim((string) ($c['local'] ?? '')), 0, 120),
                'int_ext' => in_array(strtoupper((string) ($c['int_ext'] ?? '')), ['INT', 'EXT'], true) ? strtoupper((string) $c['int_ext']) : null,
                'tempo' => mb_substr(trim((string) ($c['tempo'] ?? '')), 0, 40),
                'resumo' => mb_substr(trim((string) ($c['resumo'] ?? '')), 0, 1000),
                'narracao' => mb_substr(trim((string) ($c['narracao'] ?? '')), 0, 1200),
                'objetivo_cena' => mb_substr(trim((string) ($c['objetivo_cena'] ?? '')), 0, 300),
                'conflito_cena' => mb_substr(trim((string) ($c['conflito_cena'] ?? '')), 0, 300),
                'virada' => (bool) ($c['virada'] ?? false),
            ]);
        }
        // A premissa fica no projeto: é o contexto de tudo que vier depois (regerar, Bíblia).
        if (trim((string) $p->argumento) === '') {
            $p->update(['argumento' => mb_substr($data['brief'], 0, 4000)]);
        }

        return response()->json([
            'ok' => true, 'criadas' => count($criadas),
            // Fichas derivadas do roteiro — a UI avisa "dê rosto a eles" apontando pras abas.
            'novos_personagens' => $novosChar, 'novos_cenarios' => $novosCen, 'novos_elementos' => $novosElm,
            'project' => $p->fresh()->load('scenes.shots'),
        ]);
    }

    /**
     * POST /api/projects/{id}/cast { image_model?, image_style? } → 🎭 BOTÃO ÚNICO: dá rosto a
     * TODO personagem, cenário e elemento que as cenas desta história citam e que ainda não tem
     * imagem — num modelo e numa técnica só, os da história.
     *
     * É a etapa que faltava entre o roteiro e a montagem: o `plan` cria as FICHAS (texto), mas até
     * aqui dar rosto a elas era abrir três abas e clicar item por item, cada uma com o seu modelo e
     * o seu estilo — a mesma história saía com três estéticas. Assíncrono como as abas: dispara os
     * jobs e as telas de Personagens/Cenários/Elementos acompanham no polling de sempre.
     *
     * Não regera o que já tem imagem (refazer é decisão do autor, na aba do asset).
     */
    public function cast(Request $r, string $id, ElencoDaHistoria $elenco): JsonResponse
    {
        $p = $this->project($r, $id);
        $data = $r->validate([
            'image_model' => 'nullable|string|max:80',
            'image_style' => 'nullable|string|max:40',
        ]);
        // A escolha feita agora no Roteiro vira o padrão da história — as próximas fichas nascem nela.
        $padrao = array_filter([
            'image_model' => trim((string) ($data['image_model'] ?? '')) ?: null,
            'image_style' => isset($data['image_style']) ? AssetPrompt::normStyle($data['image_style']) : null,
        ]);
        if ($padrao !== []) {
            $p->update($padrao);
        }

        $p->load('scenes');
        if ($p->scenes->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'Escreva as cenas antes — é delas que sai quem e onde.'], 422);
        }

        return response()->json(['ok' => true] + $elenco->gerarFaltantes($p->fresh()->load('scenes')));
    }

    /**
     * POST /api/projects/{id}/review → o 📐 DOUTOR DE ROTEIRO lê a escaleta e devolve o
     * diagnóstico (veredito + notas por cena). Não grava nada: aponta, quem corrige é o autor.
     *
     * O texto da persona vem da tabela `prompts` (aba Prompts), como as outras três personas-método
     * — o rigor da revisão se ajusta editando ali, sem deploy. A escaleta é montada AQUI, do banco:
     * o navegador não manda o roteiro de volta, então não há como revisar uma versão que não é a
     * que está salva.
     */
    public function review(Request $r, string $id): JsonResponse
    {
        $tid = $this->tenant($r)->id;
        $p = Project::where('id', $id)->where('tenant_id', $tid)->with('scenes.shots')->firstOrFail();

        $total = $p->scenes->count();
        if ($total === 0) {
            return response()->json(['ok' => false, 'error' => 'Esta história ainda não tem cenas pra revisar.'], 422);
        }

        if (! $sys = $this->personaContent($tid, 'Doutor de Roteiro')) {
            return response()->json(['ok' => false, 'error' => "A persona '📐 Doutor de Roteiro' não está na aba Prompts."], 404);
        }

        $charIds = collect($p->scenes)->flatMap(fn ($s) => $s->character_ids ?? [])->unique()->values()->all();
        $scenIds = collect($p->scenes)->pluck('scenario_id')->filter()->unique()->values()->all();
        $chars = Character::where('tenant_id', $tid)->whereIn('id', $charIds)->get(['id', 'name'])->keyBy('id');
        $scens = Scenario::where('tenant_id', $tid)->whereIn('id', $scenIds)->get(['id', 'name'])->keyBy('id');

        $res = EngineClient::make(180)->post('/v1/chat', [
            'system' => $sys,
            'message' => ScriptDoctor::escaleta($p, $chars, $scens),
            'json' => true,
            // Uma nota por cena mais o veredito passa dos 2000 do default numa escaleta de 24.
            'maxTokens' => 6000,
            'gen_lines' => $this->textGenLines($this->textModelFor($r, $this->tenant($r)->plan ?? null)),
        ]);

        $diag = ScriptDoctor::diagnostico((string) $res->json('text'), $total);
        if (! $res->successful() || ($diag['veredito'] === '' && $diag['notas'] === [])) {
            return response()->json(['ok' => false, 'error' => 'O Doutor de Roteiro não devolveu um diagnóstico utilizável. Tente de novo.'], 502);
        }

        return response()->json(['ok' => true] + $diag);
    }

    /**
     * Texto da persona da aba Prompts, por trecho do título — a fonte única das instruções de IA
     * deste controller (📝 Escaleta escreve, 📐 Doutor de Roteiro revisa). Null quando a linha não
     * está lá ou está vazia; cabe a quem chama devolver o 404 dizendo QUAL persona falta, porque a
     * correção é sempre a mesma: recriar na aba Prompts (ou `php artisan migrate`).
     */
    private function personaContent(int $tenantId, string $trechoDoTitulo): ?string
    {
        $p = Prompt::where('tenant_id', $tenantId)->where('title', 'like', '%'.$trechoDoTitulo.'%')->first();
        $txt = trim((string) ($p->content ?? ''));

        return $txt !== '' ? $txt : null;
    }

    /** Extrai `cenas[]` do texto do modelo. */
    private function cenasDoJson(string $raw): array
    {
        return $this->listaDoJson($raw, 'cenas') ?: $this->listaDoJson($raw, 'scenes');
    }

    /** Lê uma lista nomeada do JSON do modelo. Tolerante de propósito: às vezes vem em cerca
     *  ```json ou com um parágrafo antes — o que importa é o objeto no meio. */
    private function listaDoJson(string $raw, string $chave): array
    {
        $txt = trim($raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $txt, $m)) {
            $txt = trim($m[1]);
        }
        $j = json_decode($txt, true);
        if (! is_array($j)) {
            $i = strpos($txt, '{');
            $f = strrpos($txt, '}');
            $j = ($i !== false && $f !== false && $f > $i) ? json_decode(substr($txt, $i, $f - $i + 1), true) : null;
        }
        $lista = is_array($j) ? ($j[$chave] ?? null) : null;

        return is_array($lista) ? array_values(array_filter($lista, 'is_array')) : [];
    }

    /** Adiciona "- **Label:** valor" só quando o valor não é vazio. */
    private function kv(array &$L, string $label, ?string $value): void
    {
        $value = trim((string) $value);
        if ($value !== '') {
            $L[] = '- **'.$label.':** '.$value;
        }
    }

    /** Lê um caminho pontilhado (a.b.c) de um array; null se ausente. */
    private function get(array $a, string $path)
    {
        foreach (explode('.', $path) as $k) {
            if (! is_array($a) || ! array_key_exists($k, $a)) {
                return null;
            }
            $a = $a[$k];
        }

        return $a;
    }

    /** Junta as partes string não-vazias com " · ". */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter(array_map(fn ($x) => is_string($x) ? trim($x) : '', $parts)));
    }
}
