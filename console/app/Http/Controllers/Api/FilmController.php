<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFilmBlockJob;
use App\Jobs\GenerateFilmBoardJob;
use App\Jobs\GenerateFilmBoardPanelJob;
use App\Jobs\GenerateFilmClipJob;
use App\Jobs\GenerateFilmElementJob;
use App\Jobs\GenerateFilmKeyframeJob;
use App\Jobs\GenerateFilmPlanJob;
use App\Jobs\GenerateFilmQuickJob;
use App\Jobs\GenerateVideoJob;
use App\Models\Character;
use App\Models\Draft;
use App\Models\GenModel;
use App\Services\UsageService;
use App\Support\BoardImage;
use App\Support\EngineClient;
use App\Support\GenPayload;
use App\Support\Networks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * FILME CONTÍNUO (plano-sequência) — aba /filme. Diferente da Histórias (cenas com corte),
 * o filme é UMA jornada de câmera sem cortes: N trechos encadeados por KEYFRAMES
 * COMPARTILHADOS (o clipe i vai do keyframe i ao i+1 — primeiro+último frame no Kling via
 * KIE, validado com geração real 2026-07-03). Keyframes são âncoras fixas → clipes geram em
 * PARALELO, sem drift. Estado em draft.film; polling via GET /api/studio/draft.
 * Cobrança: keyframe = bucket image (tier do modelo); clipe = bucket video (p5/p10 do tier);
 * montagem = bucket short. Reserve-then-consume em tudo (secure-baseline #8).
 */
class FilmController extends Controller implements HasMiddleware
{
    // textModelFor()/textGenLines() do seletor de modelo do plano (roteiro) — mesmo trait do
    // StudioController. Sem ele o plan()/texts() chamavam método indefinido → 500 no "Planejar".
    use Concerns\ResolvesTextModel;

    public function __construct(private UsageService $usage) {}

    public static function middleware(): array
    {
        // Tudo aqui gasta crédito de IA (ou orquestra quem gasta) → exige plano pago.
        return [new Middleware('subscribed')];
    }

    private function engine()
    {
        return EngineClient::make(600);
    }

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function draft(Request $r, int|string $id): Draft
    {
        return Draft::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    /** Tier de qualidade escolhido (fonte única: GenPayload — unificação 2026-07-16). */
    private function quality(?GenModel $gm, ?string $want): ?array
    {
        return GenPayload::quality($gm, $want);
    }

    /** O modelo aceita PRIMEIRO+ÚLTIMO frame (refs em array)? → modo KEYFRAME.
     *  Regra única em GenModel::isTailCapable (compartilhada com o Estúdio de Animação e o catálogo). */
    private function isTailCapable(?GenModel $m): bool
    {
        return $m?->isTailCapable() ?? false;
    }

    /** Modelo de vídeo do FILME: QUALQUER modelo padrão (kie/minimax) — com tail → modo keyframe
     *  (sem drift, paralelo); sem tail → modo CORRENTE (mais barato: cada trecho parte do último
     *  frame do anterior, em sequência). Request `model` (slug) ou o 1º com tail (ou o 1º ativo). */
    private function filmVideoModel(Request $r, ?string $plan): ?GenModel
    {
        $chosen = GenModel::resolveSelectable($r->input('model'), 'video', $plan);
        // minimax = Hailuo direto (modo Simples, i2v corrente). google/veo fica fora: o Filme
        // precisa de i2v ancorado em keyframe próprio, e o Veo não entra nesse contrato.
        // A regra mora em GenModel::isClipCapable (fonte única, compartilhada com a Animação).
        if ($chosen?->isClipCapable()) {
            return $chosen;
        }
        $all = GenModel::active()->kind('video')->forPlan($plan)->orderBy('sort_order')->get();

        return $all->first(fn ($m) => $this->isTailCapable($m))
            ?? $all->first(fn ($m) => $m->isClipCapable());
    }

    /** gen_lines.video pro engine (fonte única: GenPayload — unificação 2026-07-16). */
    private function filmGenLine(GenModel $vm, ?array $q): array
    {
        return GenPayload::videoGenLine($vm, $q);
    }

    /** POST /api/studio/film { draftId?, brief, style?, aspect?, clipDuration?, beats?, model?,
     *  quality?, masterRef?, masterDesc? } → gera o PLANO DE FILMAGEM (assíncrono; grava
     *  draft.film com status generating e o job escreve os beats). Texto não debita mídia. */
    public function plan(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $brief = trim((string) $r->input('brief'));
        if ($brief === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o objetivo do filme (o que ele mostra/vende).'], 422);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($brief, 0, 80)]);

        $beats = max(2, min(24, (int) $r->input('beats', 6)));
        $clipDur = in_array($r->input('clipDuration'), ['5', '10'], true) ? (string) $r->input('clipDuration') : '5';
        $aspect = $r->input('aspect') === '9:16' ? '9:16' : '16:9'; // comercial: default horizontal
        $style = (string) $r->input('style', 'realista');
        // ELENCO/refs-mestre: até 3 imagens (produto + apresentador + …) — todas entram como
        // identidade fixa em TODO keyframe. masterRef (legado, single) soma com masterRefs[].
        $masterRefs = array_slice(array_values(array_filter(array_unique(array_merge(
            [(string) $r->input('masterRef', '')],
            array_map('strval', (array) $r->input('masterRefs', []))
        )), fn ($u) => $u !== '' && StudioController::isOwnMediaUrl($u))), 0, 3);
        $vm = $this->filmVideoModel($r, $t->plan);
        if (! $vm) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de vídeo disponível no seu plano.'], 422);
        }

        // REPLANO preserva a IDENTIDADE: os elementos (Fase 1) e as refs travadas não dependem do
        // roteiro — só as cenas/keyframes são refeitas. Antes, replanejar zerava tudo (o usuário
        // perdia os elementos + refs que já tinha gerado). Aqui carregamos o film existente e
        // mesclamos as refs dos elementos com o que o front mandou (cap 3).
        $existing = is_array($d->film) ? $d->film : [];
        $keepRefs = array_values(array_filter((array) ($existing['master_refs'] ?? []),
            fn ($u) => is_string($u) && $u !== '' && StudioController::isOwnMediaUrl($u)));
        $mergedRefs = array_slice(array_values(array_unique(array_merge($masterRefs, $keepRefs))), 0, 3);

        $film = [
            'status' => 'generating',
            'brief' => $brief,
            'style' => $style,
            'aspect' => $aspect,
            'clip_duration' => $clipDur,
            'model' => $vm->slug,
            // keyframe = 1º+último frame (sem drift, paralelo) · chain = modo CORRENTE (mais
            // barato: qualquer modelo i2v; cada trecho parte do último frame do anterior, serial).
            'mode' => $this->isTailCapable($vm) ? 'keyframe' : 'chain',
            'quality' => (string) $r->input('quality', ''),
            'master_ref' => $mergedRefs[0] ?? '',
            'master_refs' => $mergedRefs,
            'elements' => array_values((array) ($existing['elements'] ?? [])), // 🎭 preserva a Fase 1
            // masterDesc: descrição do sujeito fixo. Se o usuário só subiu a IMAGEM-mestre (carro/
            // produto) sem descrever, injeta um genérico pra o plano tratar o sujeito como fixo e
            // NÃO inventar detalhes no texto (raiz do drift). A aparência real vem da referência.
            'master_desc' => mb_substr((string) $r->input('masterDesc', ''), 0, 900)
                ?: ($masterRefs !== [] ? 'the subject shown in the reference image (its exact appearance comes from that image)' : ''),
            // palette = DIREÇÃO DE ARTE (S3): paleta de cor do projeto, aplicada a todo keyframe (coesão).
            'palette' => mb_substr((string) $r->input('palette', ''), 0, 200),
            'persona' => mb_substr((string) $r->input('persona', ''), 0, 1500), // 🎥 Diretor (aba Prompts)
            'beats' => [],
            'keyframes' => [],
            'final_url' => '',
        ];
        $d->update(['film' => $film, 'keyword' => mb_substr($brief, 0, 80)]);

        // 🗣️ Voz da Marca do tenant prefixa o Diretor: as locuções do plano são copy da marca.
        $planPersona = trim((string) $film['persona']);
        if (($bv = trim((string) ($t->brand_voice ?? ''))) !== '') {
            $planPersona = trim('VOZ DA MARCA (aplique SEMPRE — tom, vocabulário e restrições da marca): '.mb_substr($bv, 0, 1200)."\n\n".$planPersona);
        }
        // PLANO DE FILMAGEM cobrado por MODELO (seletor textModel; default Equilibrado); o job estorna se falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        GenerateFilmPlanJob::dispatch($d->id, $t->id, [
            'brief' => $brief,
            'style' => $style,
            'masterDesc' => $film['master_desc'],
            'persona' => $planPersona, // 🎥 Diretor escolhido (vazio = cinematógrafo padrão)
            'lang' => (string) ($t->content_lang ?? 'pt-BR'),
            'clipDuration' => $clipDur,
            'beats' => $beats,
            'gen_lines' => $this->textGenLines($tm), // modelo do seletor de texto
        ], $tm?->cost_credits);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Plano de filmagem em geração — os trechos aparecem em instantes.']);
    }

    /** POST /api/studio/film-keyframe { draftId, index, prompt?, quality? } → gera/regenera o
     *  KEYFRAME index (0..N). Refs i2i: [imagem-mestre, keyframe anterior] (re-ancora identidade
     *  + continuidade espacial). Consome bucket 'image' com o tier do modelo de referência. */
    public function keyframe(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        if ($beats === [] || $i < 0 || $i > count($beats) || ! array_key_exists($i, $kf)) {
            return response()->json(['ok' => false, 'error' => 'keyframe inexistente — gere o plano primeiro'], 422);
        }
        // Prompt do keyframe: override do request > frame_prompt do beat (o último usa o final).
        $prompt = trim((string) $r->input('prompt'))
            ?: ($i < count($beats) ? (string) ($beats[$i]['frame_prompt'] ?? '') : (string) ($film['final_frame_prompt'] ?? ''));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'trecho sem descrição de keyframe'], 422);
        }
        // A HISTÓRIA COMPLETA manda: trava textual das identidades (cores/modelos/figurino dos
        // Elementos) junto do frame_prompt — as refs visuais ancoram, mas o texto garante quando
        // a ref falta ou o modelo re-interpreta (bug real: roteiro "carro vermelho" → saiu verde).
        if ($lock = $this->elementsLockText($film, 240)) {
            $prompt = mb_substr($prompt, 0, 700).' '.$lock;
        }
        // Refs i2i PRIORIZADAS e NOMEADAS (personagem/objeto antes de cenário; até 2 quando há
        // keyframe anterior) + keyframe ANTERIOR (continuidade espacial). Cap 3 (limite do i2i).
        // O prompt ENUMERA o que cada imagem é — refs anônimas faziam o modelo reinterpretar.
        $idRefs = array_slice($this->identityRefs($film), 0, ($temAnterior = $i > 0 && ($kf[$i - 1] ?? '') !== '' && StudioController::isOwnMediaUrl($kf[$i - 1])) ? 2 : 3);
        $refs = array_map(fn ($it) => $it['url'], $idRefs);
        if ($temAnterior) {
            $refs[] = $kf[$i - 1];
        }
        if ($idRefs !== []) {
            $enum = $this->refsEnumText($idRefs);
            if ($temAnterior) {
                $enum .= ' The LAST reference image is the previous keyframe — use it ONLY for spatial/lighting continuity, not as an identity.';
            }
            $prompt = mb_substr($prompt, 0, 600).' '.$enum;
        }

        $weight = $this->usage->weightFor('image');
        // FIDELIDADE AO STORYBOARD: se um board foi gerado, o keyframe usa o MESMO modelo do board
        // (img-referencia/nano-banana), mesmo SEM refs. Antes, keyframe sem refs caía no img-padrao
        // (MiniMax image-01, t2i) enquanto o board sempre usa nano-banana → modelos e estilos
        // divergiam do storyboard. Sem board, mantém o img-padrao (t2i mais barato) no caso sem refs.
        $hasBoard = trim((string) ($film['board_url'] ?? '')) !== '';
        $imgGm = ($refs !== [] || $hasBoard)
            ? GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
            : GenModel::resolveSelectable(GenModel::DEFAULT_T2I, 'image', $t->plan);
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $payload = ['prompt' => $prompt, 'aspect' => $film['aspect'] ?? '16:9', 'style' => (string) ($film['style'] ?? 'realista')];
        if ($refs !== []) {
            $payload['imageUrls'] = $refs;
            // ANCORAGEM (anti-drift): o engine cola o IDENTITY LOCK — o sujeito vem EXATO das refs
            // (masters primeiro = identidade; keyframe anterior por último = só continuidade espacial),
            // sem re-desenhar o carro/produto a cada trecho. Só na geração; a EDIÇÃO (keyframe-edit) não liga.
            $payload['anchorIdentity'] = true;
        }
        // FICHA DE CENA (S1): plano/luz/emoção do beat → o engine compõe a cinematografia na geração do
        // keyframe (editar a ficha muda a próxima imagem). Keyframe final (i==count) herda o último beat.
        $beatSpec = $beats[$i]['spec'] ?? ($i >= count($beats) && $beats !== [] ? ($beats[count($beats) - 1]['spec'] ?? null) : null);
        if ($spec = StudioController::sanitizeSceneSpec($r->input('spec') ?? $beatSpec)) {
            $payload['spec'] = $spec;
        }
        // DIREÇÃO DE ARTE (S3): paleta do projeto (guardada no filme) → coesão de cor entre keyframes.
        if ($pal = mb_substr(trim((string) ($film['palette'] ?? '')), 0, 200)) {
            $payload['palette'] = $pal;
        }
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $q));
        GenerateFilmKeyframeJob::dispatch($d->id, $t->id, $i, $payload, $weight, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Keyframe em geração.']);
    }

    /** Tipos de storyboard-sheet (destilado das técnicas de canal): contínua (uma ação
     *  desmembrada em quadros), sequencial (cortes rápidos do mesmo tema) e plano-sequência
     *  (câmera ininterrupta acompanhando o sujeito). Descrição em EN entra no prompt do board. */
    private const BOARD_TYPES = [
        'continua' => 'a single continuous action broken into sequential panels — each panel is the next instant of the same motion',
        'sequencial' => 'several fast, distinct shots of the same subject/theme — rapid cuts, varied angles and framings',
        'plano' => 'an uninterrupted camera move following the subject through space, panel by panel',
    ];

    /** Grade do board que melhor acomoda N painéis (contact sheet ~quadrado). Teto 16 painéis. */
    /**
     * Grade do board: a MENOR que acomoda os painéis — sem células sobrando.
     *
     * Já tentei forçar grade QUADRADA aqui (pra célula e filme terem a mesma proporção e o recorte
     * não cortar). Foi pior por dois motivos: (a) o modelo ignora a grade pedida de qualquer jeito
     * — pediu-se 4x4 num 9:16 e ele desenhou 2x6 —, e (b) quadrada sobra célula preta à toa (10
     * painéis num 4x4 = 6 células vazias). O board não precisa de mais coisa dentro dele.
     *
     * O corte deixou de ser problema por outro caminho: o painel agora CABE no keyframe (fit com
     * barras, em BoardImage::cropPanel) em vez de ser cortado pra caber. E a grade que vale é a
     * MEDIDA na imagem entregue (BoardImage::detectGrid), não esta — esta só orienta o prompt.
     */
    private function boardGrid(int $n): array
    {
        return match (true) {
            $n <= 4 => [2, 2],
            $n <= 6 => [3, 2],
            $n <= 9 => [3, 3],
            $n <= 12 => [4, 3],
            default => [4, 4],
        };
    }

    /** Aspectos que o provedor de imagem aceita (aspectKie no engine) → razão L/A. */
    private const SHEET_ASPECTS = [
        '9:16' => 0.5625, '2:3' => 0.6667, '3:4' => 0.75, '4:5' => 0.8,
        '1:1' => 1.0, '4:3' => 1.3333, '3:2' => 1.5, '16:9' => 1.7778,
    ];

    /**
     * O painel do board serve como PRIMEIRO FRAME de vídeo? Só se ele já estiver no aspecto do
     * filme. Devolve a razão da célula quando ela está FORA (com folga de ~16%), null quando serve.
     *
     * 🐛 Por que isto existe (2026-07-22): o modelo desenha a grade que ele quer, não a que a gente
     * pede — pedimos 4x3 e veio 2x5, com célula 1.87 (deitada) num filme 9:16. O recorte então
     * encaixava esse painel deitado no 9:16 COM BARRA PRETA, e o i2v animava a barra junto: os
     * blocos saíram todos com faixa preta. Melhor recusar e dizer o que fazer do que queimar
     * crédito de vídeo num frame que já nasce errado.
     */
    private function boardCellOffAspect(array $film): ?float
    {
        $board = trim((string) ($film['board_url'] ?? ''));
        $cols = (int) ($film['board_cols'] ?? 0);
        $rows = (int) ($film['board_rows'] ?? 0);
        if ($board === '' || $cols < 1 || $rows < 1 || ! StudioController::isOwnMediaUrl($board)) {
            return null; // sem board não há o que validar (o caller cai no keyframe)
        }
        try {
            $raw = Http::timeout(20)->get($board)->body();
        } catch (\Throwable) {
            return null; // best-effort: indisponível não é motivo pra bloquear
        }
        $celula = BoardImage::cellAspect($raw, $cols, $rows);
        if ($celula === null) {
            return null;
        }
        $alvo = self::SHEET_ASPECTS[(string) ($film['aspect'] ?? '16:9')] ?? self::SHEET_ASPECTS['16:9'];

        return abs(log($celula / $alvo)) > 0.16 ? $celula : null;
    }

    /**
     * Aspecto de folha (dos que o provedor aceita) que faz a célula sair mais perto do aspecto do
     * filme, para uma grade dada: folha ideal = filme × (cols/rows).
     */
    private static function sheetFor(float $filme, int $cols, int $rows): string
    {
        $alvo = $filme * ($cols / $rows);
        $melhor = '16:9';
        $menor = INF;
        foreach (self::SHEET_ASPECTS as $nome => $razao) {
            $erro = abs(log($alvo / $razao));
            if ($erro < $menor) {
                $menor = $erro;
                $melhor = $nome;
            }
        }

        return $melhor;
    }

    /**
     * Escolhe grade + ASPECTO DA FOLHA pra que cada célula nasça no aspecto do FILME.
     *
     * 🐛 O quadro saía deitado num filme 9:16. A causa é aritmética: a célula tem razão
     * (L/cols)/(A/rows) = razão_da_folha × (rows/cols). Gerando a folha SEMPRE no aspecto do filme,
     * a célula só bate quando cols == rows — e grade quadrada com muitos painéis vira 4 colunas
     * estreitas, que o modelo se recusa a desenhar (pedimos 4x4, veio 2x6 deitado).
     *
     * Invertendo a conta, o problema some: folha = filme × (cols/rows). Um filme 9:16 com grade
     * 4x3 quer folha 3:4 — que o provedor aceita — e aí a célula sai 0,75 × 3/4 = 0,5625 = 9:16
     * EXATO, numa grade larga e natural de desenhar. Nada de barra, nada de corte.
     *
     * Entre as grades que cabem, prefere a que (a) chega mais perto de um aspecto suportado e
     * (b) desperdiça menos célula.
     *
     * @return array{0:int,1:int,2:string} [cols, rows, aspectoDaFolha]
     */
    public function boardLayout(int $n, string $filmAspect): array
    {
        $filme = self::SHEET_ASPECTS[$filmAspect] ?? self::SHEET_ASPECTS['16:9'];
        $melhor = null;
        for ($c = 2; $c <= 4; $c++) {
            for ($r = 2; $r <= 4; $r++) {
                if ($c * $r < $n) {
                    continue;
                }
                $folha = self::sheetFor($filme, $c, $r);
                // Erro em escala LOG: 5% pra cima e 5% pra baixo pesam igual.
                $erro = abs(log(($filme * ($c / $r)) / self::SHEET_ASPECTS[$folha]));
                $nota = $erro + 0.02 * ($c * $r - $n); // desempate: menos célula sobrando
                if ($melhor === null || $nota < $melhor[0]) {
                    $melhor = [$nota, $c, $r, $folha];
                }
            }
        }
        if ($melhor === null) {
            [$c, $r] = $this->boardGrid($n);

            return [$c, $r, $filmAspect];
        }

        return [$melhor[1], $melhor[2], $melhor[3]];
    }

    /** Trava TEXTUAL de identidade: condensa os Elementos do filme (Fase 1) numa linha "Nome: visual"
     *  que entra na parte FIXA dos prompts (board/keyframe). A HISTÓRIA COMPLETA manda: se o roteiro
     *  diz "carro vermelho", a cor vai ESCRITA no prompt — a ref visual sozinha não segura quando
     *  falta ou quando o modelo re-interpreta o painel (bug real: board pintou o carro de verde). */
    private function elementsLockText(array $film, int $cap = 300): string
    {
        $parts = [];
        foreach (array_slice(array_values((array) ($film['elements'] ?? [])), 0, 8) as $el) {
            $name = trim((string) ($el['name'] ?? ''));
            $vp = trim((string) preg_replace('/\s+/', ' ', (string) ($el['visual_prompt'] ?? '')));
            if ($name === '' || $vp === '') {
                continue;
            }
            // 1ª sentença do visual_prompt (cap 90) — carrega o essencial: cor/modelo/figurino.
            $first = preg_split('/(?<=[.!?;])\s/', $vp)[0] ?? $vp;
            $parts[] = $name.': '.mb_substr($first, 0, 90);
        }
        if ($parts === []) {
            return '';
        }

        return mb_substr('FIXED IDENTITIES — follow the story exactly; never change stated colors, models or wardrobe: '.implode(' | ', $parts).'.', 0, $cap);
    }

    /**
     * Refs de IDENTIDADE do filme, PRIORIZADAS e NOMEADAS (caso real: "os carros dos elementos
     * estão diferentes do board, e os pilotos também"). Dois problemas resolvidos aqui:
     * (1) o i2i só aceita 3 refs e o master_refs era cego — um CENÁRIO podia expulsar o carro;
     *     personagem/objeto entram primeiro, cenário por último.
     * (2) as refs iam ANÔNIMAS — o modelo recebia 3 imagens sem saber o que era o quê; agora cada
     *     uma leva o NOME do elemento pro prompt enumerar ("image 1 = Carro — reproduce EXACTLY").
     * Fallback: master_refs legado (upload solto, sem nome).
     */
    private function identityRefs(array $film, int $cap = 3): array
    {
        $subjects = [];
        $scenes = [];
        foreach ((array) ($film['elements'] ?? []) as $el) {
            $u = trim((string) ($el['ref_url'] ?? ''));
            if ($u === '' || ! StudioController::isOwnMediaUrl($u)) {
                continue;
            }
            $item = ['url' => $u, 'name' => mb_substr(trim((string) ($el['name'] ?? '')) ?: 'the subject', 0, 60)];
            if (($el['role'] ?? '') === 'scene') {
                $scenes[] = $item;
            } else {
                $subjects[] = $item;
            }
        }
        $list = array_merge($subjects, $scenes);
        if ($list === []) {
            foreach ((array) ($film['master_refs'] ?? [($film['master_ref'] ?? '')]) as $u) {
                if (is_string($u) && $u !== '' && StudioController::isOwnMediaUrl($u)) {
                    $list[] = ['url' => $u, 'name' => 'the subject'];
                }
            }
        }
        $seen = [];
        $out = [];
        foreach ($list as $it) {
            if (isset($seen[$it['url']])) {
                continue;
            }
            $seen[$it['url']] = true;
            $out[] = $it;
            if (count($out) >= $cap) {
                break; // o engine repassa o array inteiro; o cap é decisão do caller por modelo
            }
        }

        return $out;
    }

    /** Trechos que viram PAINÉIS do board: os beats + o QUADRO FINAL (encerramento) quando existe.
     *  O keyframe final é uma CENA própria (ex: "piloto de pé no carro com a bandeira") e ficava
     *  FORA do board (caso real 2026-07-16: 7 cards de texto × 6 painéis de imagem); no recorte
     *  ele herdava o último painel — encerramento errado. Fonte única: board, slice, 🩹 e a UI. */
    private function boardPanelBeats(array $film): array
    {
        $beats = array_values((array) ($film['beats'] ?? []));
        $ff = trim((string) ($film['final_frame_prompt'] ?? ''));
        if ($ff !== '' && $beats !== []) {
            $beats[] = ['title' => 'encerramento', 'frame_prompt' => $ff];
        }

        return $beats;
    }

    /** Enumeração das refs pro prompt ("image 1 = Carro — ..."): o modelo sabe o que cada imagem É. */
    private function refsEnumText(array $refs): string
    {
        if ($refs === []) {
            return '';
        }
        $parts = [];
        foreach ($refs as $k => $it) {
            $parts[] = 'image '.($k + 1).' = '.$it['name'];
        }

        return 'THE REFERENCE IMAGES ARE THE FIXED IDENTITIES, in order: '.implode('; ', $parts).'. Reproduce EACH exactly as shown wherever it appears — same design, colors, proportions and lettering; do not redesign or reinterpret them.';
    }

    /** Monta o prompt do STORYBOARD-SHEET (EN — o i2i entende melhor): um grid COLSxROWS onde
     *  cada painel é um beat, MESMA identidade em todos, sem texto desenhado (senão o i2v carimba
     *  texto no vídeo). Referências numeradas + prompt NEGATIVO (a técnica dos canais). */
    private function buildBoardPrompt(array $film, array $beats, string $type, int $cols, int $rows, array $refs = [], bool $longPrompt = false): string
    {
        $n = min(count($beats), $cols * $rows);
        $typeDesc = self::BOARD_TYPES[$type] ?? self::BOARD_TYPES['continua'];
        $style = (string) ($film['style'] ?? 'realista');
        $aspect = (string) ($film['aspect'] ?? '16:9');
        $subject = trim((string) ($film['master_desc'] ?? ''));

        // Header + footer + NEGATIVO são FIXOS (sempre cabem); os painéis usam o orçamento que
        // sobra até ~1400 chars — teto seguro pra o t2i (MiniMax rejeita prompt ≥1500). Assim o
        // "sem texto" (crítico: senão o i2v carimba texto no vídeo) NUNCA é cortado.
        // SEM "numbered panels": o recorte é geométrico (boardSlice), número desenhado só vira
        // artefato no keyframe — e a instrução brigava com o NEGATIVE "no numbers", fazendo o
        // modelo tratar números legítimos do SUJEITO (o nº de corrida do carro) como descartáveis
        // e trocá-los painel a painel (bug real: "o board gerou número diferente de carro").
        // "cinematic still frames" (não "storyboard sheet contact") + proibição explícita de tarja:
        // o board real saiu com barras de título "PANEL 1: PREPARATION" desenhadas na arte — o
        // modelo copiava o formato "Panel N:" da lista abaixo como legenda visual, e a tarja
        // contaminava o keyframe no recorte.
        // "thin gutters" convidava o modelo a DESENHAR a calha — vinham linhas pontilhadas/
        // tracejadas separando os painéis (board real 2026-07-22), e o recorte ainda levava um
        // pedaço delas pro keyframe. A calha tem de ser AUSÊNCIA de imagem, não um traço.
        $head = "Create ONE image: a grid of EXACTLY {$cols} columns and {$rows} rows ({$n} cinematic still frames, each cell {$aspect}) separated by thin plain black gutters. Use exactly {$cols} columns — never merge cells or change the column count. The gutters are EMPTY SPACE, never drawn: no panel borders, no outlines, no dashed or dotted separator lines, no grid strokes. Do NOT draw any caption bars, title strips, panel headers, numbers or labels — the frame descriptions below are layout instructions only, NEVER text to render. All frames are ONE continuous story with the SAME character(s) — keep identity, wardrobe and colors IDENTICAL in every frame. If a subject carries printed numbers, text or logos as part of its own design (a racing number on a car, a jersey number, a storefront sign), reproduce them EXACTLY THE SAME in every frame where the subject appears. Moving subjects keep ONE consistent direction of travel across ALL frames (180-degree rule) — never mirror a subject between frames. Read left-to-right, top-to-bottom. Board type: {$typeDesc}.";
        // Grade nem sempre fecha exata (ex: 7 painéis num 3x3): mapa POSICIONAL célula a célula.
        // A frase vaga "unused cells at the end ficam pretas" desalinhava a última linha (painéis
        // deslocados + preto no MEIO — board real 2026-07-17) e o recorte geométrico cortava
        // metade painel, metade preto. Com o mapa linha a linha o modelo não tem onde errar.
        if ($n < $cols * $rows) {
            $rowsTxt = [];
            for ($r0 = 0; $r0 < $rows; $r0++) {
                $cells = [];
                for ($c0 = 0; $c0 < $cols; $c0++) {
                    $idx = $r0 * $cols + $c0;
                    $cells[] = $idx < $n ? '['.($idx + 1).']' : 'BLACK';
                }
                $rowsTxt[] = 'row '.($r0 + 1).' = '.implode(' ', $cells);
            }
            $head .= ' The grid layout is EXACT, cell by cell: '.implode('; ', $rowsTxt).' — where BLACK means that cell stays solid black, completely empty. Never shift, merge, resize or add cells.';
        }
        // A ORIENTAÇÃO DA CÉLULA tem de ser dita, e dita forte. "Compose each panel for 9:16"
        // sozinho era ignorado: "cinematic frames" puxa o modelo pro deitado, e ele entregava
        // painel landscape num filme vertical. A folha agora é dimensionada pra célula sair no
        // aspecto certo (boardLayout) — o texto aqui confirma o que a geometria já pede.
        $vertical = $aspect === '9:16';
        $forma = $vertical
            ? 'EVERY frame is a TALL VERTICAL portrait frame — clearly taller than wide. Never landscape, never square.'
            : 'EVERY frame is a WIDE HORIZONTAL landscape frame — clearly wider than tall. Never vertical, never square.';
        $foot = "Each of the {$n} cells is a {$aspect} frame. {$forma} Visual style: {$style}.";
        if ($subject !== '') {
            $foot .= ' Main subject: '.mb_substr($subject, 0, 120).'.';
        }
        // Refs NOMEADAS (não anônimas): o modelo sabe que a image 1 é O CARRO — antes recebia 3
        // imagens sem legenda e reinterpretava (carros/pilotos do board diferentes das refs).
        if ($refs !== []) {
            $foot .= ' '.$this->refsEnumText($refs);
        }
        $neg = 'NEGATIVE: no caption bars, no title strips, no panel headers, no numbers or labels, no subtitles, no watermarks, no UI text added on top of the artwork; no game HUD; no photo borders; no dashed or dotted lines, no panel outlines, no grid lines, no sketch guides. The ONLY text allowed is lettering that is physically part of a subject (e.g. the racing number painted on the car) — identical in every frame'
            .(str_contains(mb_strtolower($style), 'realis') ? '; no cartoon or Pixar look' : '').'.';

        // Teto do prompt: 950 cabe em qualquer modelo (o engine prependa ~500 chars de estilo;
        // 950+500 < 1500, o limite do MiniMax). Com modelo KIE (nano-banana, o caso normal do
        // board) o teto sobe pra 3500 — verificado na doc (docs.kie.ai): nano-banana-2 aceita
        // 20000 chars; o KIE mais apertado (gpt-image) aceita 5000, e 3500+~500 de estilo cabe.
        // (O teto antigo de 1700 era chute: head+refs+trava = ~2200 fixos e o piso de 200 chars
        //  engolia os painéis — o board real saiu com painéis 3-6 INVENTADOS pelo modelo.)
        $teto = $longPrompt ? 3500 : 950;
        $panels = [];
        for ($i = 0; $i < $n; $i++) {
            $fp = trim((string) ($beats[$i]['frame_prompt'] ?? '')) ?: trim((string) ($beats[$i]['title'] ?? ''));
            // "[N]" em vez de "Panel N:" — o formato antigo era copiado como legenda na arte.
            $panels[] = '['.($i + 1).'] '.mb_substr($fp, 0, $longPrompt ? 160 : 110);
        }
        $panelsRaw = implode(' ', $panels);
        // A HISTÓRIA COMPLETA manda: as identidades dos Elementos (cores/modelos/figurino) entram
        // ESCRITAS na parte fixa — a ref visual sozinha não segura (bug real: carro vermelho no
        // roteiro → board pintou verde). PRIORIDADE: os painéis SÃO o board e entram inteiros;
        // a trava usa só o que SOBRA (bug real 2026-07-16: a trava fixa de 700 + refs estouravam
        // o teto e os painéis caíam no piso de 200 — só o [1] chegava no modelo).
        $lockCap = min($longPrompt ? 700 : 300,
            $teto - mb_strlen($head) - mb_strlen($foot) - mb_strlen($neg) - mb_strlen($panelsRaw) - 37);
        if ($lockCap >= 120 && ($lock = $this->elementsLockText($film, $lockCap))) {
            $foot .= ' '.$lock;
        }
        $budget = max(200, $teto - mb_strlen($head) - mb_strlen($foot) - mb_strlen($neg) - 6);
        $panelsText = 'The frames, in reading order: '.mb_substr($panelsRaw, 0, $budget);

        return $head."\n".$panelsText."\n".$foot."\n".$neg;
    }

    /** POST /api/studio/film-board { draftId, type?, cols?, rows? } → 🎬 MODO STORYBOARD-SHEET:
     *  gera UMA imagem multi-painel (grid) onde cada painel é um beat, com identidade consistente.
     *  É a referência ÚNICA que o Filme rápido (multi_shots) lê pra gerar a sequência inteira numa
     *  tacada — mais barato que N keyframes + N trechos. Consome bucket 'image' (tier do modelo). */
    public function board(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        if (count($beats) < 2) {
            return response()->json(['ok' => false, 'error' => 'planeje o filme primeiro (o board usa os trechos como painéis)'], 422);
        }
        $type = array_key_exists((string) $r->input('type'), self::BOARD_TYPES) ? (string) $r->input('type') : 'continua';
        // Painéis = trechos + o quadro final (o encerramento é cena própria, tem painel próprio).
        $panelBeats = $this->boardPanelBeats($film);
        // Grade + aspecto da FOLHA escolhidos juntos, pra a célula nascer no aspecto do filme
        // (ver boardLayout). Vale pros dois formatos: 9:16 e 16:9 — a conta é a mesma, muda só o
        // aspecto do filme que entra nela. A inversão manual antiga (3x2→2x3 no vertical) saiu:
        // ela mexia na grade sem mexer na folha, então só trocava um desalinhamento por outro.
        [$dc, $dr, $folha] = $this->boardLayout(count($panelBeats), (string) ($film['aspect'] ?? '16:9'));
        $cols = max(2, min(4, (int) $r->input('cols', $dc)));
        $rows = max(2, min(4, (int) $r->input('rows', $dr)));
        // Grade forçada pelo request → a folha tem de ser recalculada pra ela, senão a célula volta
        // a sair fora do aspecto do filme (o override reintroduziria exatamente o bug do painel deitado).
        if ($cols !== $dc || $rows !== $dr) {
            $folha = self::sheetFor(self::SHEET_ASPECTS[(string) ($film['aspect'] ?? '16:9')] ?? self::SHEET_ASPECTS['16:9'], $cols, $rows);
        }

        // Refs i2i PRIORIZADAS e NOMEADAS (personagem/objeto antes de cenário, cap 3, cada uma com
        // o nome do elemento no prompt) — o board para de reinterpretar carro/piloto.
        $idRefs = $this->identityRefs($film, 5); // o slice por modelo (KIE 5 / MiniMax 3) vem abaixo
        // Modelo resolvido ANTES do prompt: com KIE (nano-banana, o caso normal) o prompt pode ser
        // longo (teto 1700) e as refs sobem pra 5 — com 7 elementos, cap 3 deixava o Carro 22 verde
        // FORA das âncoras e o board inventava a livery dele. MiniMax (fallback) mantém 950/3.
        $imgGm = GenModel::referenceImageModel($t->plan);
        $isKie = $imgGm?->provider === 'kie';
        $idRefs = array_slice($idRefs, 0, $isKie ? 5 : 3);
        $refs = array_map(fn ($it) => $it['url'], $idRefs);

        $prompt = $this->buildBoardPrompt($film, $panelBeats, $type, $cols, $rows, $idRefs, $isKie);

        $weight = $this->usage->weightFor('image');
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        // Canvas do board = a FOLHA calculada, não o aspecto do filme. O que precisa sair no
        // aspecto do filme é a CÉLULA (é ela que vira keyframe e alimenta o i2v), e a célula só
        // bate quando folha = filme × (cols/rows) — ver boardLayout. Forçar a folha no aspecto do
        // filme era justamente o que produzia painel deitado num filme 9:16.
        // (O cuidado antigo continua valendo: o Kling i2v herda o aspecto da imagem que recebe —
        // mas ele recebe o RECORTE do painel, que sai no aspecto do filme, não a folha inteira.)
        $payload = ['prompt' => $prompt, 'aspect' => $folha, 'style' => (string) ($film['style'] ?? 'realista')];
        if ($refs !== []) {
            $payload['imageUrls'] = $refs;
            $payload['anchorIdentity'] = true;
        }
        if ($pal = mb_substr(trim((string) ($film['palette'] ?? '')), 0, 200)) {
            $payload['palette'] = $pal;
        }
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $q));
        // Persiste os ajustes do board (o Filme rápido lê board_mode/board_url pra ler o board).
        $film['board_mode'] = true;
        $film['board_type'] = $type;
        $film['board_cols'] = $cols;
        $film['board_rows'] = $rows;
        $d->update(['film' => $film]);

        GenerateFilmBoardJob::dispatch($d->id, $t->id, $payload, $weight, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Storyboard em geração — um único board com todos os painéis.']);
    }

    /** Carrega o board do filme e recorta o painel $index (0-based) — geometria única em
     *  App\Support\BoardImage. $ratio null = célula inteira (conserto por painel); default =
     *  center-crop ao aspecto do filme (keyframe/abertura do Filme rápido). Retorna a URL
     *  persistida, ou null se GD faltar, o board não ler ou a grade for inválida. */
    private function cropBoardPanel(array $film, int $index, bool $fullCell = false): ?string
    {
        $board = trim((string) ($film['board_url'] ?? ''));
        $cols = (int) ($film['board_cols'] ?? 0);
        $rows = (int) ($film['board_rows'] ?? 0);
        if ($board === '' || $cols < 1 || $rows < 1) {
            return null;
        }
        $raw = @file_get_contents($board);
        if ($raw === false) {
            return null;
        }
        $ratio = $fullCell ? null : (((string) ($film['aspect'] ?? '16:9')) === '9:16' ? 9 / 16 : 16 / 9);
        $bytes = BoardImage::cropPanel($raw, $index, $cols, $rows, $ratio);

        return $bytes !== null ? StudioController::storeMedia($bytes, 'jpg', 'image') : null;
    }

    /**
     * POST /api/studio/film-board-slice { draftId } → RECORTA o storyboard-sheet em keyframes.
     * Fidelidade máxima ao storyboard: o painel i (que você VIU no board) VIRA o keyframe i, sem
     * regenerar nada — custo ZERO de IA (só o corte). Cada célula da grade é re-cortada ao CENTRO no
     * aspecto do filme (16:9/9:16): isso apara as calhas/linhas da grade E garante o aspecto certo pro
     * i2v — uma célula crua fica ~quadrada (num board 16:9 3×2, cada célula é ~1.18:1), e usá-la direto
     * faria o vídeo sair fora de proporção (o Kling herda o aspecto da imagem). Tudo no console via GD.
     */
    public function boardSlice(Request $r): JsonResponse
    {
        $this->tenant($r); // garante o escopo/plano do tenant (SSO)
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $board = trim((string) ($film['board_url'] ?? ''));
        if ($board === '' || ! StudioController::isOwnMediaUrl($board)) {
            return response()->json(['ok' => false, 'error' => 'Gere o storyboard primeiro.'], 422);
        }
        if (! function_exists('imagecreatefromstring')) {
            return response()->json(['ok' => false, 'error' => 'Processamento de imagem indisponível no servidor.'], 500);
        }
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $cols = (int) ($film['board_cols'] ?? 0);
        $rows = (int) ($film['board_rows'] ?? 0);
        if ($cols < 1 || $rows < 1 || $kf === []) {
            return response()->json(['ok' => false, 'error' => 'Storyboard sem grade válida — gere o board de novo.'], 422);
        }
        // 🚧 Recortar painel fora do aspecto do filme entrega quadro COM BARRA PRETA — e é o quadro
        // que depois vira primeiro frame do vídeo, com a barra animada junto. O recorte é grátis,
        // então é justamente o botão tentador que levava o operador ao vídeo estragado. Recusa aqui,
        // no passo de custo zero, e manda pro caminho que sai certo.
        if (($celula = $this->boardCellOffAspect($film)) !== null) {
            $asp = (string) ($film['aspect'] ?? '16:9');

            return response()->json(['ok' => false, 'error' => 'board_off_aspect', 'message' => 'Os quadros do storyboard saíram em '.($celula > 1 ? 'DEITADO' : 'EM PÉ').' ('.round($celula, 2).':1) e o filme é '.$asp.
                '. Recortar assim entrega quadro com faixa preta — e o vídeo herda a faixa. Use "Gerar em '.$asp.'" (uma imagem por quadro) ou gere o storyboard de novo.',
            ], 422);
        }

        $raw = @file_get_contents($board);
        if ($raw === false) {
            return response()->json(['ok' => false, 'error' => 'Não foi possível ler a imagem do storyboard.'], 422);
        }
        $ratio = ((string) ($film['aspect'] ?? '16:9')) === '9:16' ? 9 / 16 : 16 / 9;
        // Nº de painéis = o mesmo que o buildBoardPrompt desenhou: min(painéis, células da grade)
        // — painéis = trechos + quadro final (o encerramento tem painel próprio no board).
        $panels = min(max(1, count($this->boardPanelBeats($film))), $cols * $rows);
        $slots = count($kf); // keyframes a preencher (trechos + 1 no modo keyframe)
        $done = 0;
        for ($p = 0; $p < $panels && $p < $slots; $p++) {
            $bytes = BoardImage::cropPanel($raw, $p, $cols, $rows, $ratio);
            if ($bytes !== null) {
                $kf[$p] = StudioController::storeMedia($bytes, 'jpg', 'image');
                $done++;
            }
        }
        if ($done === 0) {
            return response()->json(['ok' => false, 'error' => 'Não foi possível recortar o storyboard.'], 422);
        }
        // O keyframe FINAL não tem painel próprio (há 1 keyframe a mais que trechos) → herda o último
        // painel, pra o filme fechar completo (o último trecho vira estático, o usuário regera se quiser).
        if ($slots > $panels && ($kf[$panels - 1] ?? '') !== '') {
            for ($p = $panels; $p < $slots; $p++) {
                $kf[$p] = $kf[$panels - 1];
            }
        }

        // Grava sob lock (várias cenas podem ter escrito no meio do upload dos cortes).
        DB::transaction(function () use ($d, $kf) {
            $fresh = Draft::lockForUpdate()->find($d->id);
            if (! $fresh) {
                return;
            }
            $f = is_array($fresh->film) ? $fresh->film : [];
            $f['keyframes'] = array_values($kf);
            $fresh->update(['film' => $f]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'count' => $done, 'message' => "Storyboard recortado em {$done} keyframe(s) — 100% fiel, sem gastar crédito."]);
    }

    /**
     * POST /api/studio/film-board-panel-edit { draftId, index, prompt, quality? } → 🩹 CONSERTA UM
     * painel do board sem regenerar o resto (caso real: "o piloto do painel 3 ficou sem capacete").
     * Recorta a CÉLULA inteira do painel → i2i "mude SÓ isto" (mesmo modelo do board + identidade
     * dos Elementos) → o job cola o painel editado de volta no board (os outros ficam byte a byte).
     * Cobra 1 imagem (tier do modelo de referência); estorno no job em falha.
     */
    public function boardPanelEdit(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $board = trim((string) ($film['board_url'] ?? ''));
        $cols = (int) ($film['board_cols'] ?? 0);
        $rows = (int) ($film['board_rows'] ?? 0);
        $i = (int) $r->input('index', -1);
        $panels = min(max(1, count($this->boardPanelBeats($film))), max(1, $cols * $rows));
        if ($board === '' || ! StudioController::isOwnMediaUrl($board) || $cols < 1 || $rows < 1) {
            return response()->json(['ok' => false, 'error' => 'Gere o storyboard primeiro.'], 422);
        }
        if ($i < 0 || $i >= $panels) {
            return response()->json(['ok' => false, 'error' => 'painel inexistente'], 422);
        }
        $instr = mb_substr(trim((string) $r->input('prompt')), 0, 300);
        if ($instr === '') {
            return response()->json(['ok' => false, 'error' => 'Diga o que corrigir neste painel (ex: "o piloto está sem capacete — adicione o capacete").'], 422);
        }
        // Recorta a célula INTEIRA (sem re-crop de aspecto — o conserto cola de volta e precisa
        // cobrir a região toda do painel, senão sobraria borda do desenho antigo).
        $panelUrl = $this->cropBoardPanel($film, $i, true);
        if ($panelUrl === null) {
            return response()->json(['ok' => false, 'error' => 'Não foi possível recortar o painel.'], 422);
        }
        $imgGm = GenModel::referenceImageModel($t->plan);
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $weight = $this->usage->weightFor('image');
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        // Dois níveis de conserto: o PONTUAL congela a composição (perfeito pra "faltou o
        // capacete"); o RECOMPOR libera posições/ângulo/enquadramento — sem ele, pedir mudança
        // de encenação ("os carros de frente um pro outro") não fazia NADA, porque o próprio
        // scaffold proibia mexer na composição (caso real 2026-07-17, 3 tentativas sem efeito).
        $recompose = $r->boolean('recompose');
        $prompt = ($recompose
            ? 'Redraw this storyboard panel applying this change: '.$instr
                .'. Keep the SAME characters and vehicles (exact identity, colors, lettering), the same art style, lighting and environment — but you MAY rearrange the composition: positions, camera angle and framing change as needed to fulfil the request.'
            : 'Edit this storyboard panel. Change ONLY this: '.$instr
                .'. Keep EVERYTHING else exactly the same — characters, identity, colors, proportions, background, composition and art style.')
            .GenPayload::EDIT_GUARD;
        // A HISTÓRIA COMPLETA manda também no conserto (o capacete volta com a cor certa).
        if ($lock = $this->elementsLockText($film, 240)) {
            $prompt .= ' '.$lock;
        }
        $payload = array_merge([
            'prompt' => $prompt,
            'imageUrls' => [$panelUrl],
            'anchorIdentity' => true,
            'style' => (string) ($film['style'] ?? 'realista'),
        ], GenPayload::imagePayloadBase($imgGm, $q));
        GenerateFilmBoardPanelJob::dispatch($d->id, $t->id, $i, $payload, $weight, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Conserto do painel em andamento — o board atualiza sozinho.']);
    }

    // ══════════════════════ FASE 1 — ELEMENTOS DO FILME (identidade travada) ══════════════════════
    // Extrai os "atores/itens" do filme (o carro, o produto, o cenário) do roteiro e gera 1 imagem de
    // REFERÊNCIA por elemento. Essas refs viram os master_refs que o board() e o keyframe() já ancoram
    // → tudo passa a mostrar o MESMO carro. É o que faltava (a causa do "board e keyframe divergiam").

    /** POST /api/studio/film-elements { draftId } → EXTRAI os elementos do roteiro (via /v1/scriptparse). */
    public function filmElements(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $brief = trim((string) ($film['brief'] ?? ''));
        if ($brief === '' && $beats === []) {
            return response()->json(['ok' => false, 'error' => 'Planeje o filme primeiro.'], 422);
        }
        $script = $brief;
        foreach ($beats as $b) {
            $fp = trim((string) ($b['frame_prompt'] ?? '')) ?: trim((string) ($b['title'] ?? ''));
            if ($fp !== '') {
                $script .= "\n- ".mb_substr($fp, 0, 200);
            }
        }
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->timeout(180)->post('/v1/scriptparse', [
            'script' => mb_substr($script, 0, 4000),
            'style' => (string) ($film['style'] ?? 'realista'),
            'lang' => (string) ($t->content_lang ?? 'pt-BR'),
            'maxScenes' => count($beats) ?: 6,
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'Não foi possível extrair os elementos agora.'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);
        // Achata subjects (characters) + objetos (props) + cenários (locations) numa lista (cap 6).
        $out = [];
        foreach (['characters' => 'subject', 'props' => 'object', 'locations' => 'scene'] as $key => $role) {
            foreach ((array) $res->json($key) as $el) {
                $name = trim((string) ($el['name'] ?? ''));
                $vp = trim((string) ($el['visual_prompt'] ?? ''));
                if ($name === '' || $vp === '') {
                    continue;
                }
                $out[] = ['name' => mb_substr($name, 0, 80), 'role' => $role,
                    'kind' => mb_substr((string) ($el['kind'] ?? ''), 0, 40),
                    'visual_prompt' => mb_substr($vp, 0, 1000), 'ref_url' => '', 'status' => ''];
                if (count($out) >= 6) {
                    break 2;
                }
            }
        }
        if ($out === []) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'Não identifiquei elementos claros — descreva melhor o filme.'], 422);
        }
        $film['elements'] = $out;
        $d->update(['film' => $film]);

        return response()->json(['ok' => true, 'elements' => $out]);
    }

    /** POST /api/studio/film-element { draftId, index, quality? } → gera a REFERÊNCIA de UM elemento. */
    public function filmElement(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $els = array_values((array) ($film['elements'] ?? []));
        $i = (int) $r->input('index', -1);
        if (! isset($els[$i]) || trim((string) ($els[$i]['visual_prompt'] ?? '')) === '') {
            return response()->json(['ok' => false, 'error' => 'elemento inexistente'], 422);
        }
        $weight = $this->usage->weightFor('image');
        $imgGm = GenModel::referenceImageModel($t->plan);
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $payload = $this->elementImagePayload($film, (array) $els[$i], $imgGm, $q);
        $els[$i]['status'] = 'generating';
        $film['elements'] = $els;
        $d->update(['film' => $film]);
        GenerateFilmElementJob::dispatch($d->id, $t->id, $i, $payload, $weight, $cost);

        return response()->json(['ok' => true, 'index' => $i]);
    }

    /** POST /api/studio/film-elements-gen { draftId, quality? } → gera TODAS as refs pendentes (lote). */
    public function filmElementsGen(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $els = array_values((array) ($film['elements'] ?? []));
        $imgGm = GenModel::referenceImageModel($t->plan);
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        $weight = $this->usage->weightFor('image');
        $n = 0;
        foreach ($els as $i => $el) {
            if (trim((string) ($el['ref_url'] ?? '')) !== '' || ($el['status'] ?? '') === 'generating') {
                continue;
            }
            if (trim((string) ($el['visual_prompt'] ?? '')) === '') {
                continue;
            }
            if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
                break;
            }
            $payload = $this->elementImagePayload($film, (array) $el, $imgGm, $q);
            $els[$i]['status'] = 'generating';
            GenerateFilmElementJob::dispatch($d->id, $t->id, $i, $payload, $weight, $cost);
            $n++;
        }
        $film['elements'] = $els;
        $d->update(['film' => $film]);

        return response()->json(['ok' => true, 'dispatched' => $n]);
    }

    /** POST /api/studio/film-element-set { draftId, index, url } → fixa a ref de um elemento a partir
     *  de uma imagem da galeria/computador (curadoria, sem custo). Reconstrói os master_refs. */
    public function filmElementSet(Request $r): JsonResponse
    {
        $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $url = trim((string) $r->input('url'));
        if ($url === '' || ! StudioController::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422);
        }
        $i = (int) $r->input('index', -1);
        $ok = DB::transaction(function () use ($d, $i, $url) {
            $fresh = Draft::lockForUpdate()->find($d->id);
            if (! $fresh) {
                return false;
            }
            $film = is_array($fresh->film) ? $fresh->film : [];
            $els = array_values((array) ($film['elements'] ?? []));
            if (! isset($els[$i])) {
                return false;
            }
            $els[$i] = array_merge((array) $els[$i], ['ref_url' => $url, 'status' => 'ready']);
            $film['elements'] = $els;
            $refs = [];
            foreach ($els as $e) {
                $u = trim((string) ($e['ref_url'] ?? ''));
                if ($u !== '') {
                    $refs[] = $u;
                }
            }
            $refs = array_slice(array_values(array_unique($refs)), 0, 3);
            $film['master_refs'] = $refs;
            $film['master_ref'] = $refs[0] ?? '';
            $fresh->update(['film' => $film]);

            return true;
        });

        return $ok ? response()->json(['ok' => true]) : response()->json(['ok' => false, 'error' => 'elemento inexistente'], 422);
    }

    /** POST /api/studio/film-element-character { draftId, index, characterId } → 🎭 usa um
     *  personagem da BIBLIOTECA como identidade de um elemento: a imagem-base vira a referência
     *  (i2i) e o lock do personagem vira o visual_prompt — sem custo. Paridade com
     *  AnimationController::elementCharacter (Estúdio de Animação). O NOME do elemento não muda
     *  (o board/keyframes referenciam o elenco pelo nome do roteiro). */
    public function filmElementCharacter(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $c = Character::where('tenant_id', $t->id)->find((int) $r->input('characterId'));
        if (! $c) {
            return response()->json(['ok' => false, 'error' => 'personagem não encontrado'], 404);
        }
        $ref = $c->refImageUrl();
        if ($ref === '') {
            return response()->json(['ok' => false, 'error' => Character::SEM_IMAGEM], 422);
        }
        $i = (int) $r->input('index', -1);
        $ok = DB::transaction(function () use ($d, $i, $c, $ref) {
            $fresh = Draft::lockForUpdate()->find($d->id);
            if (! $fresh) {
                return false;
            }
            $film = is_array($fresh->film) ? $fresh->film : [];
            $els = array_values((array) ($film['elements'] ?? []));
            if (! isset($els[$i])) {
                return false;
            }
            $els[$i]['ref_url'] = $ref;
            $els[$i]['character_id'] = $c->id;
            $els[$i]['status'] = 'ready';
            if (trim((string) $c->lock) !== '') {
                $els[$i]['visual_prompt'] = mb_substr(trim((string) $c->lock), 0, 1000);
            }
            $film['elements'] = $els;
            $refs = [];
            foreach ($els as $e) {
                $u = trim((string) ($e['ref_url'] ?? ''));
                if ($u !== '') {
                    $refs[] = $u;
                }
            }
            $refs = array_slice(array_values(array_unique($refs)), 0, 3);
            $film['master_refs'] = $refs;
            $film['master_ref'] = $refs[0] ?? '';
            $fresh->update(['film' => $film]);

            return true;
        });

        return $ok ? response()->json(['ok' => true]) : response()->json(['ok' => false, 'error' => 'elemento inexistente'], 422);
    }

    /**
     * POST /api/studio/film-element-save { draftId, index?, name, visualPrompt, role? } → ADICIONA
     * (sem index) ou EDITA (com index) um elemento do filme — curadoria manual, SEM custo. Caso
     * real: a extração não identificou o capacete como elemento → cada painel desenhou um capacete
     * diferente; adicionando "Capacete: [descrição com cor/design]" a identidade trava (a descrição
     * entra ESCRITA no prompt do board/keyframes via elementsLockText + a ref quando gerada).
     * Editar mantém a ref existente (o operador regenera se a descrição mudou o visual).
     */
    public function filmElementSave(Request $r): JsonResponse
    {
        $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $name = mb_substr(trim((string) $r->input('name')), 0, 80);
        $vp = mb_substr(trim((string) $r->input('visualPrompt')), 0, 1000);
        $role = in_array($r->input('role'), ['subject', 'object', 'scene'], true) ? (string) $r->input('role') : 'object';
        if ($name === '' || $vp === '') {
            return response()->json(['ok' => false, 'error' => 'dê um nome e a descrição visual (com cor/design) do elemento'], 422);
        }
        $hasIndex = $r->filled('index');
        $i = (int) $r->input('index', -1);
        $err = DB::transaction(function () use ($d, $hasIndex, $i, $name, $vp, $role) {
            $fresh = Draft::lockForUpdate()->find($d->id);
            if (! $fresh) {
                return 'rascunho não encontrado';
            }
            $film = is_array($fresh->film) ? $fresh->film : [];
            $els = array_values((array) ($film['elements'] ?? []));
            if ($hasIndex) {
                if (! isset($els[$i])) {
                    return 'elemento inexistente';
                }
                $els[$i] = array_merge((array) $els[$i], ['name' => $name, 'visual_prompt' => $vp, 'role' => $role]);
            } else {
                if (count($els) >= 8) {
                    return 'máximo de 8 elementos — remova um antes de adicionar';
                }
                $els[] = ['name' => $name, 'role' => $role, 'kind' => '', 'visual_prompt' => $vp, 'ref_url' => '', 'status' => ''];
            }
            $film['elements'] = $els;
            $fresh->update(['film' => $film]);

            return null;
        });

        return $err === null ? response()->json(['ok' => true]) : response()->json(['ok' => false, 'error' => $err], 422);
    }

    /** POST /api/studio/film-element-remove { draftId, index } → remove um elemento (sem custo) e
     *  reconstrói os master_refs (a ref dele sai da âncora de identidade do board/keyframes). */
    public function filmElementRemove(Request $r): JsonResponse
    {
        $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $ok = DB::transaction(function () use ($d, $i) {
            $fresh = Draft::lockForUpdate()->find($d->id);
            if (! $fresh) {
                return false;
            }
            $film = is_array($fresh->film) ? $fresh->film : [];
            $els = array_values((array) ($film['elements'] ?? []));
            if (! isset($els[$i])) {
                return false;
            }
            unset($els[$i]);
            $els = array_values($els);
            $film['elements'] = $els;
            // Reconstrói os master_refs a partir das refs restantes (mesma regra do filmElementSet).
            $refs = [];
            foreach ($els as $e) {
                $u = trim((string) ($e['ref_url'] ?? ''));
                if ($u !== '') {
                    $refs[] = $u;
                }
            }
            $refs = array_slice(array_values(array_unique($refs)), 0, 3);
            $film['master_refs'] = $refs;
            $film['master_ref'] = $refs[0] ?? '';
            $fresh->update(['film' => $film]);

            return true;
        });

        return $ok ? response()->json(['ok' => true]) : response()->json(['ok' => false, 'error' => 'elemento inexistente'], 422);
    }

    /** Payload /v1/image da REFERÊNCIA de um elemento — no PADRÃO DO FILME (caso real: o capacete
     *  saiu pronto mas num universo visual próprio). A ref nova ancora no que o filme JÁ tem:
     *  refs dos outros elementos como direção de arte (i2i, SEM anchorIdentity — o sujeito é NOVO,
     *  travar identidade nas refs faria o modelo redesenhar o carro), paleta do projeto e as
     *  identidades escritas (elementsLockText) pra casar cor/época/acabamento. */
    private function elementImagePayload(array $film, array $el, ?GenModel $imgGm, ?array $q): array
    {
        $lead = ($el['role'] ?? '') === 'scene'
            ? 'A clean establishing reference photo of this location, no people, empty: '
            : 'A clean, well-lit reference photo of this subject, full view, centered, plain neutral background: ';
        $prompt = $lead.trim((string) ($el['visual_prompt'] ?? ''));
        // Direção de arte do FILME: refs já geradas dos OUTROS elementos (cap 2) guiam material/
        // cor/época — instrução explícita de que são a linguagem visual, não o sujeito a copiar.
        $refs = [];
        foreach ((array) ($film['elements'] ?? []) as $other) {
            $u = trim((string) ($other['ref_url'] ?? ''));
            if ($u !== '' && $u !== trim((string) ($el['ref_url'] ?? '')) && StudioController::isOwnMediaUrl($u)) {
                $refs[] = $u;
            }
        }
        $refs = array_slice(array_values(array_unique($refs)), 0, 2);
        if ($refs !== []) {
            $prompt .= ' This subject belongs to the SAME production as the reference image(s): match their art direction, color grading, materials, era and finish exactly. Do NOT copy the subjects shown in the references — create the described subject in that same visual language.';
        }
        // Identidades escritas dos outros elementos (o capacete nasce combinando com o carro nº 7).
        if ($lock = $this->elementsLockText($film, 240)) {
            $prompt .= ' '.$lock;
        }
        $payload = [
            'prompt' => $prompt,
            'aspect' => (string) ($film['aspect'] ?? '16:9'),
            'style' => (string) ($film['style'] ?? 'realista'),
        ];
        if ($refs !== []) {
            $payload['imageUrls'] = $refs; // i2i de direção de arte (sem anchorIdentity de propósito)
        }
        // Paleta do projeto (mesma que o board/keyframes usam) — coesão de cor.
        if ($pal = mb_substr(trim((string) ($film['palette'] ?? '')), 0, 200)) {
            $payload['palette'] = $pal;
        }
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $q));

        return $payload;
    }

    /** POST /api/studio/film-clip { draftId, index, quality? } → gera o TRECHO index: i2v do
     *  keyframe index ao index+1 (primeiro+último frame). Consome bucket 'video' (p5/p10). */
    public function clip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        if (! isset($beats[$i])) {
            return response()->json(['ok' => false, 'error' => 'trecho inexistente'], 422);
        }
        $vm = GenModel::resolveSelectable((string) ($film['model'] ?? ''), 'video', $t->plan) ?? $this->filmVideoModel($r, $t->plan);
        if (! $vm) {
            return response()->json(['ok' => false, 'error' => 'modelo de vídeo do filme indisponível'], 422);
        }
        // MODO do filme: keyframe (1º+último frame) ou CORRENTE (chain — modelos baratos: o
        // trecho i parte do ÚLTIMO frame real do trecho i-1, extraído agora; geração em SEQUÊNCIA).
        $mode = (string) ($film['mode'] ?? 'keyframe');
        if ($mode === 'chain') {
            if ($i === 0) {
                $start = (string) ($kf[0] ?? '');
                if ($start === '' || ! StudioController::isOwnMediaUrl($start)) {
                    return response()->json(['ok' => false, 'error' => 'Gere o keyframe inicial antes do primeiro trecho.'], 422);
                }
            } else {
                $prev = (string) ($beats[$i - 1]['clip_url'] ?? '');
                if ($prev === '' || ! StudioController::isOwnMediaUrl($prev)) {
                    return response()->json(['ok' => false, 'error' => 'No modo corrente os trechos saem em SEQUÊNCIA — gere o trecho anterior primeiro.'], 422);
                }
                $res = $this->engine()->post('/v1/lastframe', ['videoUrl' => $prev]);
                $start = $res->successful() ? (string) $res->json('url') : '';
                if ($start === '') {
                    return response()->json(['ok' => false, 'error' => 'não foi possível extrair o frame do trecho anterior'], 502);
                }
            }
            $end = '';
        } else {
            // Filme antigo planejado como keyframe com modelo SEM tail real (o isTailCapable já
            // promoveu o Seedance por engano): gerar mandaria o K final como ref solta e o trecho
            // sairia espelhado/derivado — melhor parar com instrução do que gerar lixo cobrado.
            if (! $this->isTailCapable($vm)) {
                return response()->json(['ok' => false, 'error' => 'Este modelo de vídeo não controla o último frame do trecho. Replaneje o filme (ele vira modo corrente, sem 1º/último frame) ou replaneje escolhendo um modelo com controle de primeiro/último frame.'], 422);
            }
            $start = (string) ($kf[$i] ?? '');
            $end = (string) ($kf[$i + 1] ?? '');
            if ($start === '' || ! StudioController::isOwnMediaUrl($start) || $end === '' || ! StudioController::isOwnMediaUrl($end)) {
                return response()->json(['ok' => false, 'error' => 'Gere os keyframes deste trecho (o inicial e o final) antes do clipe.'], 422);
            }
        }
        $dur = in_array($film['clip_duration'] ?? '5', ['5', '10'], true) ? (string) $film['clip_duration'] : '5';
        $q = $this->quality($vm, (string) ($r->input('quality') ?: ($film['quality'] ?? '')));
        $cost = $q ? ((($dur === '10' ? ($q['p10'] ?? null) : ($q['p5'] ?? null))) ?? $vm->cost_credits) : $vm->cost_credits;
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        $payload = [
            'prompt' => (string) ($beats[$i]['move_prompt'] ?? ''),
            'imageUrl' => $start,
            'endImageUrl' => $end,
            'duration' => $dur,
            'aspect' => (string) ($film['aspect'] ?? '16:9'),
            'gen_lines' => $this->filmGenLine($vm, $q),
        ];
        // kling_elements (F3, validado com geração real 2026-07-04): identidade NATIVA dentro do
        // vídeo — o modelo recebe 2-4 imagens do protagonista e o prompt referencia @hero (a API
        // exige no MÍNIMO 2 imagens por elemento). Vai no `extra` do spec (o engine já mescla no
        // createTask). Só nos modelos com suporte (os mesmos do modo keyframe).
        $masters = array_values(array_filter((array) ($film['master_refs'] ?? []), fn ($u) => is_string($u) && $u !== '' && StudioController::isOwnMediaUrl($u)));
        // @hero (kling_elements): identidade NATIVA no vídeo. A API exige ≥2 imagens do elemento —
        // antes só ligava com ≥2 masters, então quem subia UMA foto (carro/produto) ficava sem o lock
        // e o sujeito derivava. Agora COMPLETA as imagens do elemento com o keyframe INICIAL do trecho
        // (mostra o mesmo sujeito): 1 master + keyframe = 2 imagens → @hero passa a valer no caso comum.
        // ⚠️ Payload de API externa (KIE/Kling): validar com geração real antes de expor em prod.
        $heroImgs = array_values(array_unique(array_merge(
            array_slice($masters, 0, 4),
            ($start !== '' && StudioController::isOwnMediaUrl($start)) ? [$start] : []
        )));
        if ($this->isTailCapable($vm) && count($masters) >= 1 && count($heroImgs) >= 2 && is_array($payload['gen_lines']['video']['kie'] ?? null)) {
            $payload['gen_lines']['video']['kie']['extra'] = array_merge(
                (array) ($payload['gen_lines']['video']['kie']['extra'] ?? []),
                ['kling_elements' => [[
                    'name' => 'hero',
                    'description' => mb_substr((string) (($film['master_desc'] ?? '') ?: 'the main subject of the film'), 0, 200),
                    'element_input_urls' => array_slice($heroImgs, 0, 4),
                ]]],
            );
            $payload['prompt'] = trim((string) $payload['prompt']).' The shot features @hero — keep @hero exact identity, colors and proportions.';
        }
        GenerateFilmClipJob::dispatch($d->id, $t->id, $i, $payload, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Trecho em geração — aparece em alguns minutos.']);
    }

    /** Movimentos com câmera programada. Espelha CAM_MOVES do ffmpeg-service (media/ffmpeg-service/
     *  server.py), que por sua vez espelha moveDirectives do engine. Allowlist aqui é defesa em
     *  camada: o valor já vem de um <select>, mas nada além destas keys pode chegar ao ffmpeg. */
    private const CAM_MOVES = ['static', 'push_in', 'pull_out', 'pan_left', 'pan_right', 'tilt_up', 'tilt_down'];

    /**
     * POST /api/studio/film-camclip { draftId, index, move } → 📷 CÂMERA PROGRAMADA (sem IA de vídeo).
     *
     * Plano contemplativo (abertura, hero shot, encerramento, detalhe) não tem nada se movendo de
     * verdade: pagar i2v ali é queimar crédito e ainda arriscar drift de identidade. Aqui o clipe sai
     * da arte JÁ APROVADA com a câmera por cima — custo zero de crédito (só CPU), instantâneo e sem
     * drift, porque nenhum modelo redesenha nada.
     *
     * Fonte da imagem: o painel do board do trecho (a arte que o cliente viu e aprovou); sem board,
     * cai no keyframe inicial. Movimento fora da lista → 422 com os suportados, e a UI oferece o i2v.
     */
    public function camClip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        if (! isset($beats[$i])) {
            return response()->json(['ok' => false, 'error' => 'trecho inexistente'], 422);
        }
        $move = trim((string) $r->input('move', ''));
        if (! in_array($move, self::CAM_MOVES, true)) {
            return response()->json([
                'ok' => false,
                'error' => 'Este movimento precisa da IA de vídeo (a câmera programada só faz zoom, giro e inclinação).',
                'supported' => self::CAM_MOVES,
            ], 422);
        }
        // Painel do board = a arte aprovada, mesma fonte dos blocos. Sem board, o keyframe inicial.
        $src = $this->cropBoardPanel($film, $i) ?: (string) ($kf[$i] ?? '');
        if ($src === '' || ! StudioController::isOwnMediaUrl($src)) {
            return response()->json(['ok' => false, 'error' => 'Gere o storyboard ou o keyframe deste trecho antes.'], 422);
        }
        // Custo ZERO: tryConsume com 0 mantém o feature-gate do plano e o analytics, mas não debita
        // (UsageService só chama a carteira quando cost > 0) — nada de linha de 0 crédito no extrato.
        if (! $this->usage->tryConsume($t, 'video', 1, 0)) {
            return response()->json(['ok' => false, 'error' => 'Vídeo não está incluso no seu plano.'], 402);
        }
        $dur = in_array($film['clip_duration'] ?? '5', ['5', '10'], true) ? (int) $film['clip_duration'] : 5;
        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->acceptJson()->timeout(90) // ~5s de clipe sai em segundos; 90 é folga, não expectativa
                ->post('/camclip', [
                    'image_url' => $src,
                    'move' => $move,
                    'duration' => $dur,
                    'aspect' => (string) ($film['aspect'] ?? '16:9'),
                ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'A câmera programada não respondeu. Tente de novo.'], 502);
        }
        $url = $res->successful() ? trim((string) $res->json('url')) : '';
        if ($url === '') {
            return response()->json(['ok' => false, 'error' => 'A câmera programada falhou neste trecho.'], 502);
        }
        DB::transaction(function () use ($d, $i, $url, $move) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            if (isset($beats[$i])) {
                $beats[$i]['clip_url'] = $url;
                $beats[$i]['clip_engine'] = 'camera';  // marca a origem: a montagem e a UI diferenciam
                $beats[$i]['clip_move'] = $move;
                $film['beats'] = $beats;
                $locked->update(['film' => $film]);
            }
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'url' => $url, 'move' => $move]);
    }

    /** O modelo suporta multi_shots (Kling, vários cortes numa ÚNICA geração)? Mesmo padrão de
     *  isTailCapable — flag derivada, white-label (não expõe o campo/provedor ao cliente). */
    private function isMultiShotCapable(?GenModel $m): bool
    {
        return $m && $m->provider === 'kie' && ($m->capabilities['kie']['multi_prompt_field'] ?? '') !== '';
    }

    /**
     * POST /api/studio/film-quick { draftId, quality? } → ⚡ FILME RÁPIDO (Sprint D): gera o
     * filme INTEIRO numa ÚNICA geração Kling multi_shots (cada corte = o move_prompt de um
     * beat; keyframe[0] = única referência aceita — só 1º frame, validado com geração real).
     * Mais barato e mais coeso que encadear trechos. Exige plano + keyframe de abertura prontos
     * e um modelo com suporte (isMultiShotCapable). Grava em film.quick_clip_url — o "Montar o
     * filme" usa este clipe único no lugar da concatenação de beats[].clip_url.
     */
    public function quick(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        if ($beats === []) {
            return response()->json(['ok' => false, 'error' => 'planeje o filme primeiro'], 422);
        }
        // 🎬 MODO STORYBOARD-SHEET: a referência única é o BOARD multi-painel (não o keyframe de
        // abertura) — o modelo multi_shots lê o board inteiro como direcional da sequência.
        // Intent explícito do request vence; só cai no board_mode gravado quando o param não vem
        // (senão o botão normal do Filme rápido, que manda useBoard:false, seria sequestrado).
        $useBoard = $r->has('useBoard') ? $r->boolean('useBoard') : (bool) ($film['board_mode'] ?? false);
        // Quando a abertura é o grid inteiro (fallback), o Kling mostra o board no vídeo → evitar.
        $openingIsBoardGrid = false;
        if ($useBoard) {
            $board = (string) ($film['board_url'] ?? '');
            if ($board === '' || ! StudioController::isOwnMediaUrl($board)) {
                return response()->json(['ok' => false, 'error' => 'gere o storyboard (board) antes do vídeo'], 422);
            }
            // Mesma trava dos blocos: painel fora do aspecto do filme vira frame com barra preta,
            // e o i2v anima a barra. Não cobra vídeo por um frame que já nasce errado.
            if (($celula = $this->boardCellOffAspect($film)) !== null) {
                $asp = (string) ($film['aspect'] ?? '16:9');

                return response()->json(['ok' => false, 'error' => 'board_off_aspect', 'message' => 'O storyboard saiu com os quadros em '.($celula > 1 ? 'DEITADO' : 'EM PÉ').' ('.round($celula, 2).':1), mas o filme é '.$asp.
                    '. Gere o storyboard de novo antes de gerar o vídeo — senão ele nasce com faixa preta.',
                ], 422);
            }
            // ⚠️ O Kling i2v usa image_urls[0] como PRIMEIRO FRAME VISÍVEL. Se passássemos o grid
            // inteiro, o vídeo ABRIRIA mostrando o storyboard. Recortamos o PAINEL 1 (a cena 1, mesma
            // arte do board) e usamos ele como abertura; os cortes seguintes vêm dos shots.
            $panel = $this->cropBoardPanel($film, 0);
            if ($panel !== null && StudioController::isOwnMediaUrl($panel)) {
                $opening = $panel;
            } else {
                $opening = $board; // fallback raro (GD ausente/board ilegível): mantém funcional
                $openingIsBoardGrid = true;
            }
        } else {
            $opening = (string) ($kf[0] ?? '');
            if ($opening === '' || ! StudioController::isOwnMediaUrl($opening)) {
                return response()->json(['ok' => false, 'error' => 'gere o keyframe de abertura antes do filme rápido'], 422);
            }
        }
        $vm = GenModel::resolveSelectable((string) ($film['model'] ?? ''), 'video', $t->plan);
        if (! $this->isMultiShotCapable($vm)) {
            // fallback: qualquer modelo ativo do plano com suporte a multi_shots.
            $vm = GenModel::active()->kind('video')->forPlan($t->plan)->orderBy('sort_order')->get()
                ->first(fn ($m) => $this->isMultiShotCapable($m));
        }
        if (! $this->isMultiShotCapable($vm)) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de vídeo do seu plano suporta o Filme rápido (múltiplos cortes numa geração).'], 422);
        }
        // Shots = o move_prompt de cada beat (câmera/ação daquele trecho). Doc oficial do modo
        // multi_shots: até 5 cortes, prompt ≤500 chars/corte, total 3-15s (o engine distribui a
        // duração por corte e clampa cada texto — DistributeShotDurations).
        $shots = array_values(array_filter(array_map(fn ($b) => mb_substr(trim((string) ($b['move_prompt'] ?? '')), 0, 500), $beats)));
        $shots = array_slice($shots, 0, 5);
        if (count($shots) < 2) {
            return response()->json(['ok' => false, 'error' => 'o Filme rápido exige ao menos 2 trechos com movimento descrito'], 422);
        }
        // No modo board a referência agora é a CENA 1 (painel recortado): instrui a começar nela e
        // seguir os beats em ordem — sem citar "grid/storyboard" (confundiria com a arte anterior).
        // Só no fallback raro (abertura = grid inteiro) mantém a instrução de "ler painel a painel".
        if ($useBoard && ! $openingIsBoardGrid) {
            $shots[0] = mb_substr(trim('Start exactly on the reference frame (scene 1) and animate it; then continue the sequence beat by beat, keeping the same character identity, wardrobe and colors. Maintain ONE consistent screen direction across all cuts (180-degree rule); never mirror or flip a scene. '.$shots[0]), 0, 500);
        } elseif ($useBoard) {
            $shots[0] = mb_substr(trim('Use the reference image as a storyboard board — interpret its panels as directional and follow them in order starting at panel 1. Maintain ONE consistent screen direction across all cuts (180-degree rule); never mirror or flip a scene. '.$shots[0]), 0, 500);
        }
        // Preço: sem tier próprio pra "até 15s" no catálogo — cobra o tier de 10s (o mais
        // próximo, arredondado pra cima) do modelo escolhido.
        $q = $this->quality($vm, (string) $r->input('quality'));
        $cost = $q ? (($q['p10'] ?? null) ?? $vm->cost_credits) : $vm->cost_credits;
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        // Duração TOTAL desejada (doc: 3-15s) — ~3s por corte, capada no teto do modo.
        $totalSeconds = max(3, min(15, count($shots) * 3));
        $payload = [
            'shots' => $shots,
            'imageUrl' => $opening,
            'totalSeconds' => $totalSeconds,
            'aspect' => (string) ($film['aspect'] ?? '16:9'),
            'gen_lines' => $this->filmGenLine($vm, $q),
        ];
        // @hero (kling_elements): trava a identidade do sujeito entre os cortes da geração ÚNICA —
        // no Filme rápido o único frame de referência é a abertura, então sem isto o sujeito deriva
        // corte a corte. Completa com masters (se houver) + keyframe de abertura (≥2 imagens do mesmo
        // sujeito). ⚠️ payload de API externa (KIE/Kling multi_shots): validar com geração real.
        $masters = array_values(array_filter((array) ($film['master_refs'] ?? []), fn ($u) => is_string($u) && $u !== '' && StudioController::isOwnMediaUrl($u)));
        // A abertura agora é uma cena real (painel 1 recortado) → serve de âncora @hero junto das
        // refs-mestre. Exceção: no fallback raro em que a abertura é o grid inteiro, o grid NÃO é foto
        // do sujeito → usa só as refs-mestre (a própria consistência do board trava o resto).
        $heroImgs = $openingIsBoardGrid
            ? array_slice($masters, 0, 4)
            : array_values(array_unique(array_merge(array_slice($masters, 0, 3), [$opening])));
        if (count($heroImgs) >= 2 && is_array($payload['gen_lines']['video']['kie'] ?? null)) {
            $payload['gen_lines']['video']['kie']['extra'] = array_merge(
                (array) ($payload['gen_lines']['video']['kie']['extra'] ?? []),
                ['kling_elements' => [[
                    'name' => 'hero',
                    'description' => mb_substr((string) (($film['master_desc'] ?? '') ?: 'the main subject of the film'), 0, 200),
                    'element_input_urls' => array_slice($heroImgs, 0, 4),
                ]]],
            );
            // Re-clampa a base a 440 antes de anexar a nota @hero (~50 chars) — o corte tem teto de
            // 500/corte (o engine re-clampa), senão a nota de identidade seria a parte cortada.
            $payload['shots'] = array_map(fn ($s) => trim(mb_substr($s, 0, 440).' Keep @hero exact identity, colors and proportions.'), $payload['shots']);
        }
        GenerateFilmQuickJob::dispatch($d->id, $t->id, $payload, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Filme rápido em geração — uma única tacada, aparece em alguns minutos.']);
    }

    /** Partição dos trechos em BLOCOS: até 3 cortes por bloco (~5s por corte — a zona sem drift
     *  do i2v — dentro do teto de 15s da geração única multi_shots). 6 trechos = 2 blocos;
     *  12 trechos = 4 blocos = ~1min de filme. */
    private function blockPlan(int $nBeats): array
    {
        // Tamanhos EQUILIBRADOS (3,2,2… nunca 1): a partição gulosa deixava bloco de 1 corte no
        // fim (7 cenas → 3+3+1) e o engine rejeita multi_shots com <2 cortes ("filme rápido:
        // exige ao menos 2 cortes" — caso real 2026-07-17, bloco do encerramento nunca gerava).
        $nBlocks = max(1, (int) ceil($nBeats / 3));
        $base = intdiv($nBeats, $nBlocks);
        $extra = $nBeats % $nBlocks; // os primeiros $extra blocos levam +1 corte
        $plan = [];
        $i = 0;
        for ($b = 0; $b < $nBlocks; $b++) {
            $size = $base + ($b < $extra ? 1 : 0);
            $plan[] = [$i, $i + $size - 1];
            $i += $size;
        }

        return $plan;
    }

    /**
     * POST /api/studio/film-blocks { draftId, block?, quality? } → 🎬 FILME EM BLOCOS: divide o
     * filme em blocos de até 3 cortes e gera CADA bloco numa única geração multi_shots (coesa por
     * construção: uma geração = um mundo, sem drift de identidade entre os cortes internos), com
     * abertura no painel do board (ou keyframe) do primeiro trecho do bloco + identidade @hero.
     * Nasceu do caso real 2026-07-16: trechos i2v de 10s derivavam no meio (nº do carro derretia,
     * cor desbotava) e cada emenda saltava — o problema era estrutural, não do modelo. `block`
     * presente = regera SÓ aquele bloco (conserto). Consome bucket 'video' por bloco (tier 10s).
     */
    public function filmBlocks(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        if (count($beats) < 2) {
            return response()->json(['ok' => false, 'error' => 'planeje o filme primeiro'], 422);
        }
        $vm = GenModel::resolveSelectable((string) ($film['model'] ?? ''), 'video', $t->plan);
        if (! $this->isMultiShotCapable($vm)) {
            $vm = GenModel::active()->kind('video')->forPlan($t->plan)->orderBy('sort_order')->get()
                ->first(fn ($m) => $this->isMultiShotCapable($m));
        }
        if (! $this->isMultiShotCapable($vm)) {
            return response()->json(['ok' => false, 'error' => 'Nenhum modelo de vídeo do plano suporta gerar blocos (múltiplos cortes numa geração).'], 422);
        }
        // Os blocos cobrem TODOS os painéis do board — trechos + o ENCERRAMENTO (quadro final).
        // Sem isso o filme nunca concluía: 6 trechos = 2 blocos e a cena final (ex: piloto com a
        // bandeira) ficava fora de qualquer bloco (caso real 2026-07-17: "precisamos de 3 blocos
        // para concluir o filme"). Índice do painel == índice aqui (mesma fonte boardPanelBeats).
        $beats = $this->boardPanelBeats($film);
        $plan = $this->blockPlan(count($beats));
        $hasBoard = trim((string) ($film['board_url'] ?? '')) !== '' && (int) ($film['board_cols'] ?? 0) > 0;
        // 🚧 Painel fora do aspecto do filme = frame com barra preta, e o i2v anima a barra junto
        // (caso real: TODOS os blocos saíram com faixa). Para antes de cobrar o vídeo.
        if ($hasBoard && ($celula = $this->boardCellOffAspect($film)) !== null) {
            $asp = (string) ($film['aspect'] ?? '16:9');

            return response()->json(['ok' => false, 'error' => 'board_off_aspect', 'message' => 'O storyboard saiu com os quadros em '.($celula > 1 ? 'DEITADO' : 'EM PÉ').' ('.round($celula, 2).':1), mas o filme é '.$asp.
                '. Usar esses painéis faria o vídeo nascer com faixa preta. Gere o storyboard de novo, ou gere os keyframes por trecho (cada um sai em '.$asp.') e rode os blocos a partir deles.',
            ], 422);
        }
        // Regen de UM bloco (conserto) ou todos. Blocos existentes só valem se a partição não mudou.
        $blocks = array_values((array) ($film['blocks'] ?? []));
        $samePartition = count($blocks) === count($plan)
            && collect($plan)->every(fn ($p, $k) => (int) ($blocks[$k]['from'] ?? -1) === $p[0] && (int) ($blocks[$k]['to'] ?? -1) === $p[1]);
        $only = $r->filled('block') ? (int) $r->input('block') : null;
        if ($only !== null && (! $samePartition || ! array_key_exists($only, $plan))) {
            return response()->json(['ok' => false, 'error' => 'bloco inexistente — gere os blocos de novo'], 422);
        }
        if (! $samePartition) {
            $blocks = array_map(fn ($p) => ['from' => $p[0], 'to' => $p[1], 'url' => ''], $plan);
        }

        $q = $this->quality($vm, (string) ($r->input('quality') ?: ($film['quality'] ?? '')));
        $cost = $q ? (($q['p10'] ?? null) ?? $vm->cost_credits) : $vm->cost_credits;
        $masters = array_values(array_filter((array) ($film['master_refs'] ?? []), fn ($u) => is_string($u) && $u !== '' && StudioController::isOwnMediaUrl($u)));
        $todo = $only !== null ? [$only] : array_keys($plan);
        $dispatched = 0;
        foreach ($todo as $bi) {
            [$from, $to] = $plan[$bi];
            // Abertura do bloco: o PAINEL do board do primeiro trecho (mesma arte que o cliente
            // aprovou) — fallback: keyframe recortado/gerado. Sem âncora visual, o bloco não sai.
            $opening = $hasBoard ? ($this->cropBoardPanel($film, $from) ?? '') : '';
            if ($opening === '' && ($kf[$from] ?? '') !== '' && StudioController::isOwnMediaUrl((string) $kf[$from])) {
                $opening = (string) $kf[$from];
            }
            if ($opening === '' || ! StudioController::isOwnMediaUrl($opening)) {
                return response()->json(['ok' => false, 'error' => 'Gere o storyboard (ou os keyframes) antes dos blocos — o bloco '.($bi + 1).' está sem imagem de abertura.'], 422);
            }
            if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
                return response()->json(['ok' => false, 'error' => $dispatched > 0
                    ? "Limite de vídeos atingido — {$dispatched} bloco(s) entraram, os demais não."
                    : 'Limite de vídeos do plano atingido.'], 402);
            }
            $shots = [];
            foreach (array_slice($beats, $from, $to - $from + 1) as $b) {
                $shots[] = mb_substr(trim((string) ($b['move_prompt'] ?? '') ?: (string) ($b['frame_prompt'] ?? '')), 0, 500);
            }
            // Regra de 180°: o multi_shots (/v1/filmquick) NÃO passa pelo GenerateFilmClip — a
            // trava anti-espelhamento de lá não valia aqui, e cada bloco decidia sozinho a
            // direção ("um carro pra um lado e um pro outro", caso real 2026-07-17).
            $shots[0] = mb_substr(trim('Start exactly on the reference frame (the current scene) and animate it; then continue the sequence beat by beat, keeping the same character identity, wardrobe and colors. Maintain ONE consistent screen direction for the WHOLE sequence: subjects and vehicles keep travelling the SAME way in every cut (180-degree rule); never mirror or flip a scene. '.$shots[0]), 0, 500);
            $payload = [
                'shots' => $shots,
                'imageUrl' => $opening,
                // ~5s por corte (anti-drift), teto 15s do modo multi_shots.
                'totalSeconds' => max(3, min(15, count($shots) * 5)),
                'aspect' => (string) ($film['aspect'] ?? '16:9'),
                'gen_lines' => $this->filmGenLine($vm, $q),
            ];
            $heroImgs = array_values(array_unique(array_merge(array_slice($masters, 0, 3), [$opening])));
            if (count($heroImgs) >= 2 && is_array($payload['gen_lines']['video']['kie'] ?? null)) {
                $payload['gen_lines']['video']['kie']['extra'] = array_merge(
                    (array) ($payload['gen_lines']['video']['kie']['extra'] ?? []),
                    ['kling_elements' => [[
                        'name' => 'hero',
                        'description' => mb_substr((string) (($film['master_desc'] ?? '') ?: 'the main subject of the film'), 0, 200),
                        'element_input_urls' => array_slice($heroImgs, 0, 4),
                    ]]],
                );
                $payload['shots'] = array_map(fn ($s) => trim(mb_substr($s, 0, 440).' Keep @hero exact identity, colors and proportions.'), $payload['shots']);
            }
            $blocks[$bi]['url'] = ''; // em geração — a UI mostra o bloco como pendente
            GenerateFilmBlockJob::dispatch($d->id, $t->id, $bi, $payload, $cost);
            $dispatched++;
        }
        DB::transaction(function () use ($d, $blocks) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $f = is_array($locked->film) ? $locked->film : [];
            $f['blocks'] = array_values($blocks);
            $locked->update(['film' => $f]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'blocks' => array_values($blocks),
            'message' => $only !== null ? 'Bloco '.($only + 1).' em regeração.' : "{$dispatched} bloco(s) em geração — cada um é uma tacada única e coesa."]);
    }

    /** POST /api/studio/film-clear-media { draftId } → LIMPA as mídias geradas do filme
     *  (keyframes + clipes dos trechos + itens intermediários da galeria). O PLANO (textos)
     *  fica intacto — é o "recomeçar a produção" com o mesmo roteiro. */
    public function clearMedia(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        DB::transaction(function () use ($d) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            foreach ($beats as $i => $b) {
                $beats[$i]['clip_url'] = '';
            }
            $film['beats'] = $beats;
            $film['keyframes'] = array_fill(0, count($beats) + 1, '');
            $film['final_url'] = '';
            $film['quick_clip_url'] = '';
            $film['blocks'] = [];
            $film['board_url'] = '';
            $media = array_values(array_filter($locked->media ?? [], fn ($m) => ! isset($m['scene'])));
            $locked->update(['film' => $film, 'media' => $media]);
        });

        return response()->json(['ok' => true]);
    }

    /** PATCH /api/studio/film-beats { draftId, beats: [{title?, frame_prompt?, move_prompt?,
     *  voiceover?}], finalFramePrompt? } → autosave dos TEXTOS do plano (mesmo contrato do
     *  storyScenes: merge SÓ dos campos de texto, sob lock; clip_url/keyframes NUNCA são
     *  tocados aqui — jobs atômicos é que escrevem mídia). */
    public function beats(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $incoming = (array) $r->input('beats', []);
        DB::transaction(function () use ($d, $incoming, $r) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $existing = array_values((array) ($film['beats'] ?? []));
            foreach ($incoming as $idx => $b) {
                if (! is_array($b) || ! isset($existing[$idx])) {
                    continue;
                }
                foreach (['title', 'frame_prompt', 'move_prompt', 'voiceover'] as $f) {
                    if (array_key_exists($f, $b)) {
                        $existing[$idx][$f] = (string) $b[$f];
                    }
                }
                // spec = FICHA DE CENA (S1): plano/movimento/luz/emoção do beat. Sanitizado (allowlist);
                // ausente = mantém o que veio do plano (preservado em $existing).
                if (array_key_exists('spec', $b)) {
                    $existing[$idx]['spec'] = StudioController::sanitizeSceneSpec($b['spec']);
                }
                // approved = trecho TRAVADO (S4): o front confirma antes de regerar keyframe/clipe.
                if (array_key_exists('approved', $b)) {
                    $existing[$idx]['approved'] = (bool) $b['approved'];
                }
            }
            $film['beats'] = $existing;
            if ($r->has('finalFramePrompt')) {
                $film['final_frame_prompt'] = (string) $r->input('finalFramePrompt');
            }
            // Roteiro técnico: título do filme e direção musical também são editáveis no plano.
            if ($r->has('title')) {
                $film['title'] = mb_substr((string) $r->input('title'), 0, 160);
            }
            if ($r->has('musicPrompt')) {
                $film['music_prompt'] = mb_substr((string) $r->input('musicPrompt'), 0, 400);
            }
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true]);
    }

    /** Seções regeneráveis do plano (espelha filmSectionField do engine): campo por beat que a
     *  seção reescreve; roteiro também troca o título do filme; musica só o music_prompt. */
    private const PLAN_SECTIONS = ['roteiro' => 'title', 'storyboard' => 'frame_prompt', 'narracao' => 'voiceover', 'camera' => 'move_prompt', 'musica' => ''];

    /** POST /api/studio/film-section { draftId, section } → REGENERA uma seção do plano
     *  (roteiro|storyboard|narracao|camera|musica) mantendo TODO o resto (o plano atual vai de
     *  contexto — a história completa manda). Síncrono (texto, ~30-90s) e cobra 1 texto. */
    public function section(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $section = (string) $r->input('section');
        if (! array_key_exists($section, self::PLAN_SECTIONS)) {
            return response()->json(['ok' => false, 'error' => 'seção inválida'], 422);
        }
        if ($beats === []) {
            return response()->json(['ok' => false, 'error' => 'planeje o filme primeiro'], 422);
        }
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->timeout(180)->post('/v1/filmsection', [
            'brief' => (string) ($film['brief'] ?? ''),
            'style' => (string) ($film['style'] ?? 'realista'),
            'lang' => (string) ($t->content_lang ?? 'pt-BR'),
            'clipDuration' => (string) ($film['clip_duration'] ?? '10'),
            'section' => $section,
            'beats' => array_map(fn ($b) => [
                'title' => (string) ($b['title'] ?? ''),
                'frame_prompt' => (string) ($b['frame_prompt'] ?? ''),
                'move_prompt' => (string) ($b['move_prompt'] ?? ''),
                'voiceover' => (string) ($b['voiceover'] ?? ''),
            ], $beats),
            'finalFramePrompt' => (string) ($film['final_frame_prompt'] ?? ''),
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'Não foi possível regenerar a seção agora.'], 502);
        }
        $values = array_map(fn ($v) => (string) $v, (array) $res->json('beat_values'));
        $field = self::PLAN_SECTIONS[$section];
        if ($field !== '' && count($values) < count($beats)) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'A seção voltou incompleta — tente de novo.'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);
        // Merge sob lock: só o campo da seção muda; trechos TRAVADOS (approved) não são tocados.
        DB::transaction(function () use ($d, $section, $field, $values, $res) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $existing = array_values((array) ($film['beats'] ?? []));
            if ($field !== '') {
                foreach ($existing as $i => $b) {
                    if (! empty($b['approved']) || ! array_key_exists($i, $values)) {
                        continue;
                    }
                    $existing[$i][$field] = $values[$i];
                }
                $film['beats'] = $existing;
            }
            if ($section === 'roteiro' && ($ti = trim((string) $res->json('title'))) !== '') {
                $film['title'] = mb_substr($ti, 0, 160);
            }
            if ($section === 'storyboard' && ($ff = trim((string) $res->json('final_frame_prompt'))) !== '') {
                $film['final_frame_prompt'] = $ff;
            }
            if ($section === 'musica' && ($mp = trim((string) $res->json('music_prompt'))) !== '') {
                $film['music_prompt'] = mb_substr($mp, 0, 400);
            }
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/film-beat-add { draftId } → ADICIONA um trecho em branco no FIM (+1
     *  keyframe vazio no fim, mantendo keyframes = trechos+1). */
    public function beatAdd(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        DB::transaction(function () use ($d) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            if (count($beats) >= 24) {
                return; // teto do plano
            }
            // O trecho novo COMEÇA no que era o keyframe FINAL — herda a descrição dele (a imagem
            // K_N existente continua alinhada ao texto). O usuário descreve o novo encerramento.
            $beats[] = [
                'title' => 'Novo trecho',
                'frame_prompt' => (string) ($film['final_frame_prompt'] ?? ''),
                'move_prompt' => '',
                'voiceover' => '',
                'clip_url' => '',
            ];
            $kf = array_values((array) ($film['keyframes'] ?? []));
            $kf[] = '';
            $film['beats'] = $beats;
            $film['keyframes'] = $kf;
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/film-beat-remove { draftId, index } → REMOVE o trecho index (e o
     *  keyframe FINAL dele, index+1 — o inicial fica e passa a abrir o trecho seguinte).
     *  Mexer na estrutura pede regenerar os clipes vizinhos (a continuidade muda). */
    public function beatRemove(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        DB::transaction(function () use ($d, $i) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            if (! isset($beats[$i]) || count($beats) <= 2) {
                return; // mínimo 2 trechos
            }
            array_splice($beats, $i, 1);
            // Remove o keyframe DE MESMO ÍNDICE (K_i, o que abria o trecho removido): assim a
            // imagem que sobra em cada posição continua alinhada à descrição (beats[j].frame_prompt
            // descreve K_j). Remover K_{i+1} desalinhava imagem↔texto de todos os seguintes.
            $kf = array_values((array) ($film['keyframes'] ?? []));
            if (array_key_exists($i, $kf)) {
                array_splice($kf, $i, 1);
            }
            $film['beats'] = $beats;
            $film['keyframes'] = $kf;
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/film-beat-move { draftId, index, dir: -1|1 } → TROCA o trecho de lugar
     *  com o vizinho (textos+clipe juntos; keyframes ficam posicionais — regenerar depois). */
    public function beatMove(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $dir = (int) $r->input('dir', 0) > 0 ? 1 : -1;
        DB::transaction(function () use ($d, $i, $dir) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $beats = array_values((array) ($film['beats'] ?? []));
            $j = $i + $dir;
            if (! isset($beats[$i]) || ! isset($beats[$j])) {
                return;
            }
            // Troca o CONTEÚDO do trecho (título/movimento/locução/clipe) mas mantém o
            // frame_prompt POSICIONAL — ele descreve o keyframe daquela posição (a imagem não
            // se move), senão imagem↔texto desalinham.
            $fpI = $beats[$i]['frame_prompt'] ?? '';
            $fpJ = $beats[$j]['frame_prompt'] ?? '';
            [$beats[$i], $beats[$j]] = [$beats[$j], $beats[$i]];
            $beats[$i]['frame_prompt'] = $fpI;
            $beats[$j]['frame_prompt'] = $fpJ;
            $film['beats'] = $beats;
            $locked->update(['film' => $film]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/film-texts { draftId, platforms[] } → gera o TEXTO do post por rede a
     *  partir do brief + locução do filme (mesmo contrato do storyTexts: grava draft.texts —
     *  é o que a página Aprovar/Publicar consome). Gate de assinatura; texto não debita mídia. */
    public function texts(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        if ($beats === []) {
            return response()->json(['ok' => false, 'error' => 'planeje o filme primeiro'], 422);
        }
        $platforms = Networks::only($r->input('platforms', []));
        if ($platforms === []) {
            $platforms = ['youtube', 'instagram', 'linkedin'];
        }
        $lang = (string) ($t->content_lang ?? 'pt-BR');
        $brief = (string) ($film['brief'] ?: $d->keyword);
        $script = trim(implode("\n", array_filter(array_map(fn ($b) => trim((string) ($b['voiceover'] ?? '')), $beats))));
        $facts = trim($brief."\n\n".$script);

        $texts = $d->texts ?? [];
        $meta = $d->texts_meta ?? [];
        $gerados = 0;    // redes que ganharam texto NESTA chamada
        $semSaldo = false;
        foreach ($platforms as $p) {
            if (! isset($tmTexts)) {
                $tmTexts = $this->textModelFor($r, $t->plan); // resolve 1× (mesmo modelo pra todas as redes)
            }
            if (! $this->usage->tryConsume($t, 'text', 1, $tmTexts?->cost_credits)) {
                $semSaldo = true;
                break; // sem saldo: mantém o que já gerou
            }
            $res = $this->engine()->post('/v1/text', ['keyword' => $brief, 'brief' => $brief, 'facts' => $facts, 'platform' => $p, 'lang' => $lang, 'persona' => StudioController::textPersona($r, $t), 'gen_lines' => $this->textGenLines($tmTexts)]);
            if (! $res->successful()) {
                $this->usage->refund($t, 'text', 1, $tmTexts?->cost_credits);

                continue; // tolerante: uma rede que falha não derruba as outras
            }
            $post = (string) $res->json('post');
            if (trim($post) === '') {
                $this->usage->refund($t, 'text', 1, $tmTexts?->cost_credits); // texto vazio não é entrega

                continue;
            }
            $this->ajustaSeReserva($t, $tmTexts, $res);
            $texts[$p] = $post;
            $meta[$p] = ['rank' => (float) $res->json('rank_summary'), 'grounding' => (float) $res->json('grounding'), 'flags' => $res->json('flags') ?? [], 'lang' => $lang];
            $gerados++;
        }
        $d->update(['texts' => $texts, 'texts_meta' => $meta]);
        StudioController::servirRedesNaMidiaFinal($d, $platforms); // rede com legenda tem de ter a mídia junto

        // Mesmo contrato do storyTexts: ok:true com ZERO texto mandava a tela pro Aprovar vazio
        // (as abas de rede e o preview da mídia saem de draft.texts). Zero entrega = erro.
        $temAlgum = collect($texts)->contains(fn ($v, $k) => $k !== 'blog' && trim((string) $v) !== '');
        if ($gerados === 0 && ! $temAlgum) {
            return $semSaldo
                ? response()->json(['ok' => false, 'error' => 'Sem créditos para gerar os textos do post — recarregue o plano e tente de novo.'], 402)
                : response()->json(['ok' => false, 'error' => 'A geração dos textos do post falhou — tente de novo.'], 502);
        }

        return response()->json(['ok' => true, 'texts' => $texts, 'generated' => $gerados]);
    }

    /** POST /api/studio/film-keyframe-edit { draftId, index, prompt, quality? } → AJUSTA o
     *  keyframe (i2i in place — o mesmo scaffold de edição das Histórias: aplica SÓ a mudança
     *  pedida e congela o resto). Consome bucket 'image' (tier do i2i de referência). */
    public function keyframeEdit(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        $cur = (string) ($kf[$i] ?? '');
        if ($i < 0 || $cur === '' || ! StudioController::isOwnMediaUrl($cur)) {
            return response()->json(['ok' => false, 'error' => 'Gere o keyframe antes de ajustar.'], 422);
        }
        $prompt = trim((string) $r->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o que ajustar no keyframe.'], 422);
        }
        $weight = $this->usage->weightFor('image');
        $imgGm = GenModel::referenceImageModel($t->plan, fallbackT2I: false);
        $q = $this->quality($imgGm, (string) $r->input('quality'));
        $cost = ($q['p'] ?? null) ?? $imgGm?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $edit = GenPayload::editPrompt($prompt);
        $payload = ['prompt' => $edit, 'aspect' => $film['aspect'] ?? '16:9', 'style' => (string) ($film['style'] ?? 'realista'), 'imageUrls' => [$cur]];
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $q));
        GenerateFilmKeyframeJob::dispatch($d->id, $t->id, $i, $payload, $weight, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Ajuste do keyframe em andamento.']);
    }

    /** POST /api/studio/film-keyframe-set { draftId, index, url } → FIXA o keyframe numa imagem
     *  escolhida (galeria) — troca direta, sem custo de IA. É a "forma de fixar" quando a
     *  geração erra: você aponta a imagem certa e o trecho usa ela. */
    public function keyframeSet(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        $url = (string) $r->input('url', '');
        if ($i < 0 || ! array_key_exists($i, $kf)) {
            return response()->json(['ok' => false, 'error' => 'keyframe inexistente'], 422);
        }
        if ($url === '' || ! StudioController::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422); // anti-SSRF
        }
        DB::transaction(function () use ($d, $i, $url) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $kf = array_values((array) ($film['keyframes'] ?? []));
            if (array_key_exists($i, $kf)) {
                $kf[$i] = $url;
                $film['keyframes'] = $kf;
                $locked->update(['film' => $film]);
            }
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'url' => $url]);
    }

    /** POST /api/studio/film-keyframe-from-clip { draftId, index } → RE-ANCORA o keyframe
     *  `index` no ÚLTIMO frame REAL do clipe do trecho index-1 (modo corrente, F2): se o clipe
     *  não aterrissou exatamente no keyframe planejado, o próximo trecho parte de onde o
     *  anterior REALMENTE terminou. Sem custo de IA (só extração de frame). */
    public function keyframeFromClip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        $beats = array_values((array) ($film['beats'] ?? []));
        $kf = array_values((array) ($film['keyframes'] ?? []));
        $i = (int) $r->input('index', -1);
        $clip = (string) ($beats[$i - 1]['clip_url'] ?? '');
        if ($i < 1 || ! array_key_exists($i, $kf) || $clip === '' || ! StudioController::isOwnMediaUrl($clip)) {
            return response()->json(['ok' => false, 'error' => 'Gere o clipe do trecho anterior antes de re-ancorar este keyframe.'], 422);
        }
        $res = $this->engine()->post('/v1/lastframe', ['videoUrl' => $clip]);
        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            return response()->json(['ok' => false, 'error' => 'não foi possível extrair o frame'], 502);
        }
        DB::transaction(function () use ($d, $i, $url) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $film = is_array($locked->film) ? $locked->film : [];
            $kf = array_values((array) ($film['keyframes'] ?? []));
            if (array_key_exists($i, $kf)) {
                $kf[$i] = $url;
                $film['keyframes'] = $kf;
                $locked->update(['film' => $film]);
            }
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'url' => $url]);
    }

    /** POST /api/studio/film-assemble { draftId, music?, musicPrompt?, narration?, voice_id?,
     *  audioQuality?, audioStyle?, subtitles?, platforms? } → MONTA o filme (concat dos trechos
     *  prontos + trilha e/ou LOCUÇÃO contínua com legenda — F2). Consome bucket 'short'. */
    public function assemble(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $film = is_array($d->film) ? $d->film : [];
        // 🎬 Filme em BLOCOS: se todos os blocos estão prontos, eles SÃO o filme (cada bloco =
        // uma geração multi_shots coesa) — a montagem emenda os blocos, com prioridade sobre o
        // rápido/trechos (é o caminho recomendado desde 2026-07-16).
        $blocks = array_values((array) ($film['blocks'] ?? []));
        $blockUrls = array_map(fn ($b) => (string) ($b['url'] ?? ''), $blocks);
        $blocksOk = $blocks !== []
            && ! in_array('', $blockUrls, true)
            && count(array_filter($blockUrls, StudioController::isOwnMediaUrl(...))) === count($blockUrls)
            // cobre TODOS os painéis (trechos + encerramento — mesma partição do filmBlocks)
            && (int) ($blocks[count($blocks) - 1]['to'] ?? -1) === count($this->boardPanelBeats($film)) - 1;
        // ⚡ Filme rápido (Sprint D): se há um clipe único (multi_shots), ele JÁ É o filme
        // inteiro — a montagem só aplica narração/grade/letterbox/endcard por cima (concat de 1).
        $quick = (string) ($film['quick_clip_url'] ?? '');
        if ($blocksOk) {
            $clips = $blockUrls;
        } elseif ($quick !== '' && StudioController::isOwnMediaUrl($quick)) {
            $clips = [$quick];
        } else {
            $clips = [];
            foreach ((array) ($film['beats'] ?? []) as $b) {
                $u = (string) ($b['clip_url'] ?? '');
                if ($u !== '' && StudioController::isOwnMediaUrl($u)) {
                    $clips[] = $u;
                }
            }
            if (count($clips) < 2) {
                return response()->json(['ok' => false, 'error' => 'Gere os clipes dos trechos antes de montar o filme (mínimo 2) — ou use o Filme rápido.'], 422);
            }
        }
        if (! $this->usage->tryConsume($t, 'short', 1)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        // 💳 Estúdio de Efeitos (B0 — tudo cobrado, bucket effect; estorno único no job):
        // transição 1/corte · filtro 2 · VFX 2/trecho · SFX 3/trecho · ambience 3.
        [$transPayload, $fxCount] = StudioController::transitionParams($r, max(0, count($clips) - 1));
        $grade = StudioController::gradeFrom($r);
        $ambience = mb_substr(trim((string) $r->input('ambiencePrompt', '')), 0, 300);
        // VFX/SFX POR TRECHO (F4): arrays alinhados aos clipes (allowlist/trim server-side).
        $vfx = [];
        $sfx = [];
        foreach ($clips as $i => $u) {
            $vk = (array) $r->input('vfx', []);
            $sk = (array) $r->input('sfx', []);
            $vfx[] = in_array($vk[$i] ?? '', StudioController::VFX_KINDS, true) ? (string) $vk[$i] : '';
            $sfx[] = mb_substr(trim((string) ($sk[$i] ?? '')), 0, 200);
        }
        $fxCount += ($grade !== 'natural' ? 2 : 0) + ($ambience !== '' ? 3 : 0)
            + 2 * count(array_filter($vfx)) + 3 * count(array_filter($sfx));
        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1);

            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para os efeitos ('.$fxCount.').'], 402);
        }
        $platforms = Networks::only($r->input('platforms', []));
        // Endcard (cartela final ~2s, imagem já composta logo+CTA): anti-SSRF, só nosso storage.
        $endcardUrl = (string) $r->input('endcardUrl', '');
        if ($endcardUrl !== '' && ! StudioController::isOwnMediaUrl($endcardUrl)) {
            $endcardUrl = '';
        }
        $payload = [
            'clipUrls' => $clips,
            'music' => filter_var($r->input('music', true), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => mb_substr((string) $r->input('musicPrompt', ''), 0, 300),
            'aspect' => (string) ($film['aspect'] ?? '16:9'),
            // Acabamento (F2): grade+intensidade/grain/letterbox/endcard — todos opcionais.
            'grade' => $grade,
            'gradeStrength' => StudioController::gradeStrengthFrom($r),
            'grain' => filter_var($r->input('grain', false), FILTER_VALIDATE_BOOLEAN),
            'letterbox' => filter_var($r->input('letterbox', false), FILTER_VALIDATE_BOOLEAN),
            'endcardUrl' => $endcardUrl,
            // 🔉 SFX/ambiente (Sprint D): texto livre, camada BEM baixa sob a trilha/narração.
            'ambiencePrompt' => $ambience,
            // 🎇 F4: efeitos por trecho (alinhados aos clipes; sanitizados acima).
            'vfx' => $vfx,
            'sfx' => $sfx,
            // 🎨 Color-match (S3): casa a exposição entre os trechos do plano-sequência (anti-drift de cor).
            'colorMatch' => filter_var($r->input('colorMatch', false), FILTER_VALIDATE_BOOLEAN),
            // 🌊 Fluidez: interpolação de movimento no vídeo final (fps 2× — movimento mais fluido).
            'smooth' => filter_var($r->input('smooth', false), FILTER_VALIDATE_BOOLEAN),
            // Transições entre trechos (F1) — default global + override por corte.
            'transitionDefault' => $transPayload['transitionDefault'],
            'transitionDur' => $transPayload['transitionDur'],
            'transitionCuts' => $transPayload['transitionCuts'],
        ];
        // NARRAÇÃO CONTÍNUA (F2): a locução do roteiro (voiceovers dos trechos em sequência)
        // entra por cima do filme montado; legenda word-level opcional. Voz/tier/estilo do
        // MESMO catálogo kind=audio das Histórias.
        if (filter_var($r->input('narration', false), FILTER_VALIDATE_BOOLEAN)) {
            $script = trim(implode("\n", array_filter(array_map(
                fn ($b) => trim((string) ($b['voiceover'] ?? '')), (array) ($film['beats'] ?? [])
            ))));
            if ($script === '') {
                $this->usage->refund($t, 'short', 1);

                return response()->json(['ok' => false, 'error' => 'Este plano não tem locução — replaneje o filme pra gerar o roteiro de narração.'], 422);
            }
            $am = GenModel::active()->kind('audio')->forPlan($t->plan)->orderBy('sort_order')->first();
            $aq = $this->quality($am, (string) $r->input('audioQuality'));
            $payload['narration'] = true;
            $payload['script'] = $script;
            $payload['voiceId'] = (string) ($r->input('voice_id') ?: $t->voice_id);
            $payload['subtitles'] = filter_var($r->input('subtitles', true), FILTER_VALIDATE_BOOLEAN);
            if ($am) {
                $payload['ttsModel'] = $am->provider_model_id;
            }
            if (! empty($aq['extra']['output_format'])) {
                $payload['ttsFormat'] = (string) $aq['extra']['output_format'];
            }
            $style = (string) $r->input('audioStyle');
            if (in_array($style, ['dramatico', 'calmo', 'energetico', 'locutor'], true)) {
                $payload['ttsStyle'] = $style;
            }
            // Estilo COMPLETO da legenda + SINCRONISMO — os MESMOS parâmetros/clamps das Histórias.
            $payload = array_merge($payload, StudioController::subtitleStyleFrom($r), [
                'audioDelay' => min(max((float) $r->input('audioDelay', 0), 0.0), 2.0),
                'subtitleOffset' => min(max((float) $r->input('subtitleOffset', 0), -1.0), 1.0),
            ]);
        }
        // Job genérico: chama /v1/filmassemble e anexa o vídeo FINAL à galeria (sem `scene` →
        // publicável, igual ao Short da Histórias).
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/filmassemble', $payload, 'short', 1, 'filme', $platforms, null, 'video', $fxCount);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Filme em montagem — aparece na galeria em alguns minutos.']);
    }

    /** Último vídeo PRONTO de um rascunho por estilo ('filme' = montagem final da aba Filme;
     *  'aventura' = junção da aba Aventuras) — é o que a galeria trata como vídeo final. */
    private function latestFinalVideo(Draft $d, string $style): string
    {
        foreach (array_reverse((array) ($d->media ?? [])) as $it) {
            if (($it['kind'] ?? '') === 'video' && ($it['style'] ?? '') === $style
                && StudioController::isOwnMediaUrl((string) ($it['url'] ?? ''))) {
                return (string) $it['url'];
            }
        }

        return '';
    }

    /** GET /api/studio/films → home da aba 🎞️ MOVIES: TODOS os filmes do tenant (inclusive os
     *  em andamento), do mais recente pro mais antigo, com resumo leve pro card de projeto
     *  (estilo "projetos" do Flow). Aventuras (mode=adventure) ficam de fora — têm aba própria. */
    public function films(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $out = [];
        $drafts = Draft::where('tenant_id', $t->id)->whereNotNull('film')
            ->orderByDesc('updated_at')->limit(100)->get();
        foreach ($drafts as $d) {
            $film = is_array($d->film) ? $d->film : [];
            if (($film['mode'] ?? '') === 'adventure') {
                continue;
            }
            $blocks = (array) ($film['blocks'] ?? []);
            // Miniatura do card: board > 1º keyframe pronto > ref-mestre (o que existir primeiro).
            $thumb = (string) ($film['board_url'] ?? '');
            if ($thumb === '') {
                foreach ((array) ($film['keyframes'] ?? []) as $kf) {
                    if ((string) $kf !== '') {
                        $thumb = (string) $kf;
                        break;
                    }
                }
            }
            if ($thumb === '') {
                $thumb = (string) ($film['master_ref'] ?? '');
            }
            $out[] = [
                'id' => $d->id,
                'title' => (string) (($film['title'] ?? '') ?: $d->keyword),
                'status' => (string) ($film['status'] ?? ''),
                'aspect' => (string) ($film['aspect'] ?? '16:9'),
                'beats' => count((array) ($film['beats'] ?? [])),
                'blocks_done' => count(array_filter($blocks, fn ($b) => (string) ($b['url'] ?? '') !== '')),
                'blocks_total' => count($blocks),
                'has_board' => (string) ($film['board_url'] ?? '') !== '',
                'final_url' => $this->latestFinalVideo($d, 'filme'),
                'thumb' => $thumb,
                'updated_at' => optional($d->updated_at)->toIso8601String(),
            ];
        }

        return response()->json(['ok' => true, 'films' => $out]);
    }

    /** DELETE /api/studio/film { draftId } → 🗑️ exclui um FILME inteiro (aba Movies): o
     *  rascunho sai da home e a mídia dele (keyframes/board/clipes/montagem) é removida do
     *  nosso storage em best-effort (mesmo padrão do mediaListDelete — URL morta/externa é
     *  ignorada). Só rascunho DE FILME do próprio tenant; a confirmação é do front. */
    public function destroy(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId')); // escopado por tenant + firstOrFail (404)
        if (! is_array($d->film) || $d->film === []) {
            return response()->json(['ok' => false, 'message' => 'Este rascunho não é um filme.'], 422);
        }
        $base = rtrim((string) config('filesystems.disks.media.url'), '/');
        foreach ((array) ($d->media ?? []) as $m) {
            $url = (string) ($m['url'] ?? '');
            if ($url === '' || ! StudioController::isOwnMediaUrl($url)) {
                continue;
            }
            try {
                $key = ltrim((string) parse_url(substr($url, 0, strlen($base)) === $base ? substr($url, strlen($base)) : $url, PHP_URL_PATH), '/');
                if ($key !== '') {
                    Storage::disk('media')->delete($key);
                }
            } catch (\Throwable) {
                // objeto já não existe / storage indisponível → a exclusão do rascunho segue
            }
        }
        $d->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /api/studio/adventure-films → insumos da aba 🗺️ AVENTURAS: os FILMES PRONTOS do
     *  tenant (rascunhos com a montagem final feita — vídeo style 'filme' na galeria) + as
     *  aventuras já criadas (film.mode='adventure'), pra listar/pollar na aba. */
    public function adventureFilms(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $films = [];
        $adventures = [];
        $drafts = Draft::where('tenant_id', $t->id)->whereNotNull('film')
            ->orderByDesc('updated_at')->limit(200)->get();
        foreach ($drafts as $d) {
            $film = is_array($d->film) ? $d->film : [];
            if (($film['mode'] ?? '') === 'adventure') {
                $adventures[] = [
                    'id' => $d->id,
                    'title' => (string) ($film['title'] ?? $d->keyword),
                    'parts' => array_values((array) ($film['parts'] ?? [])),
                    'aspect' => (string) ($film['aspect'] ?? '16:9'),
                    'url' => $this->latestFinalVideo($d, 'aventura'),
                    'created_at' => optional($d->created_at)->toIso8601String(),
                ];

                continue;
            }
            $final = $this->latestFinalVideo($d, 'filme');
            if ($final === '') {
                continue; // só filme com montagem final pronta entra na aventura
            }
            $films[] = [
                'id' => $d->id,
                'title' => (string) (($film['title'] ?? '') ?: $d->keyword),
                'aspect' => (string) ($film['aspect'] ?? '16:9'),
                'url' => $final,
                'updated_at' => optional($d->updated_at)->toIso8601String(),
            ];
        }

        return response()->json(['ok' => true, 'films' => $films, 'adventures' => $adventures]);
    }

    /** POST /api/studio/adventure { title?, draftIds[2..10], transitionDefault?, transitionDur?,
     *  music?, musicPrompt? } → 🗺️ JUNTA filmes prontos numa AVENTURA (história longa, ex: 5
     *  filmes de 1-2min): cria um rascunho novo (film.mode='adventure') e manda os finais na
     *  ordem dada pro /v1/filmassemble. A trilha de cada filme JÁ vem no próprio vídeo → music
     *  default OFF (ligar só pra trilha única por cima). Consome bucket 'short' (+ transições). */
    public function adventure(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('draftIds', [])), fn ($v) => $v > 0));
        if (count($ids) < 2 || count($ids) > 10) {
            return response()->json(['ok' => false, 'error' => 'Escolha de 2 a 10 filmes prontos pra juntar na aventura.'], 422);
        }
        // Ownership + final pronto de CADA filme, preservando a ordem escolhida (dedupe implícito
        // no keyBy — repetir o mesmo filme 2x na sequência é permitido e usa o mesmo final).
        $byId = Draft::where('tenant_id', $t->id)->whereIn('id', array_unique($ids))->get()->keyBy('id');
        $clips = [];
        $aspect = '';
        foreach ($ids as $id) {
            $d = $byId->get($id);
            $film = is_array($d?->film) ? $d->film : [];
            $final = $d ? $this->latestFinalVideo($d, 'filme') : '';
            if ($d === null || ($film['mode'] ?? '') === 'adventure' || $final === '') {
                return response()->json(['ok' => false, 'error' => "O filme #{$id} não tem a montagem final pronta — monte ele na aba Filme antes de juntar."], 422);
            }
            $clips[] = $final;
            $aspect = $aspect ?: (string) ($film['aspect'] ?? '16:9');
        }
        if (! $this->usage->tryConsume($t, 'short', 1)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        [$transPayload, $fxCount] = StudioController::transitionParams($r, count($clips) - 1);
        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1);

            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para as transições ('.$fxCount.').'], 402);
        }
        $title = mb_substr(trim((string) $r->input('title')), 0, 80) ?: 'Aventura';
        $nd = Draft::create(['tenant_id' => $t->id, 'keyword' => $title, 'film' => [
            'mode' => 'adventure', 'title' => $title, 'parts' => $ids, 'aspect' => $aspect,
        ]]);
        $payload = array_merge([
            'clipUrls' => $clips,
            'music' => filter_var($r->input('music', false), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => mb_substr((string) $r->input('musicPrompt', ''), 0, 300),
            'aspect' => $aspect,
        ], $transPayload);
        GenerateVideoJob::dispatch($nd->id, $t->id, '/v1/filmassemble', $payload, 'short', 1, 'aventura', [], null, 'video', $fxCount);

        return response()->json(['ok' => true, 'draftId' => $nd->id, 'message' => 'Aventura em montagem — o filmão aparece aqui e na galeria em alguns minutos.']);
    }
}
