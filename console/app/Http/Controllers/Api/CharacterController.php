<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesTextModel;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateCharacterJob;
use App\Jobs\GenerateModelSheetJob;
use App\Models\Character;
use App\Models\GenModel;
use App\Models\Prompt;
use App\Services\ModelSheetService;
use App\Services\UsageService;
use App\Support\AssetPrompt;
use App\Support\EngineClient;
use App\Support\GenPayload;
use App\Support\MoldePersonagem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;

/**
 * Biblioteca de PERSONAGENS reutilizáveis (aba Personagens do Studio). CRUD por tenant +
 * geração da imagem-BASE (retrato frontal, t2i), do MODEL SHEET híbrido (turnaround i2i +
 * bíblia de texto) e edição i2i da base. A geração é ASSÍNCRONA (GenerateCharacterJob): a
 * imagem i2i é lenta e estouraria o proxy no request — o front faz polling de show() até
 * status sair de '' (gerando). Espelha o padrão do StudioController (tenant-scoped + quota).
 */
class CharacterController extends Controller implements HasMiddleware
{
    use ResolvesTextModel;

    // Modelo (gen_models.slug) FIXO por operação no personagem — a base é t2i, sheet/edit são i2i.
    // Não há escolha de modelo aqui na Fase 1 (a UI de seletor de imagem chega na Fase 2/KIE);
    // o que muda já é a COBRANÇA por modelo (resolve cost_credits do catálogo). Se o catálogo ainda
    // não tem o modelo, cai no custo fixo por tipo (CREDIT_COST) — fallback seguro.
    // Base do personagem = a ÂNCORA de identidade de todo o model sheet e das cenas — fallback no
    // TOPO do catálogo (qualidade > custo; gera 1× por personagem). Cliente pode escolher outro.
    private const MODEL_T2I = 'img-ultra';       // imagem-base (text-to-image)

    private const MODEL_I2I = 'img-referencia';  // edição da base + figurino manual (i2i default)

    // i2i do MODEL SHEET: especialista de identidade (ideogram/character). A COBRANÇA dos shots do
    // sheet usa este modelo (o ModelSheetService gera com ele; flat-lays de produto saem no default,
    // custo menor — a diferença fica como margem). Espelha ModelSheetService::SHEET_I2I_MODEL.
    private const MODEL_I2I_SHEET = ModelSheetService::SHEET_I2I_MODEL;

    // Gate de assinatura só nos métodos que GASTAM crédito de IA (geração de imagem/texto). CRUD/leitura livres.
    // `extractFromBase`/`personaChat` entraram no mesmo gate (fusão FoxAssets, 2026-08-01): os dois
    // consomem crédito de texto (usage->tryConsume) igual aos outros — deixar de fora do `subscribed`
    // seria abrir uma exceção não pretendida na cobrança.
    public static function middleware(): array
    {
        return [
            new Middleware('subscribed', only: ['generateBase', 'generateSheet', 'editBase', 'fromImage', 'regeneratePanel', 'addOutfit', 'extractFromBase', 'personaChat']),
        ];
    }

    public function __construct(private UsageService $usage) {}

    private function engine(): PendingRequest
    {
        return EngineClient::make(120);
    }

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    private function character(Request $r, int|string $id): Character
    {
        return Character::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    private function normStyle(?string $style): string
    {
        return AssetPrompt::normStyle($style);
    }

    /** Retrato-âncora canônico do personagem. O texto vive em App\Support\AssetPrompt porque o
     *  botão único do Roteiro gera a MESMA base por fora desta aba — duas cópias divergiriam no
     *  primeiro ajuste, e a âncora de identidade é o que não pode variar. Reusado em prompts()
     *  pra reconstruir o texto sem gerar nada. */
    private function basePromptText(string $desc, string $style): string
    {
        return AssetPrompt::personagem($desc, $style);
    }

    /** GET /api/characters → lista os personagens do tenant (mais recentes primeiro). */
    public function index(Request $r): JsonResponse
    {
        $items = Character::where('tenant_id', $this->tenant($r)->id)
            ->latest('updated_at')
            ->limit(300)
            // `archetype`/`logline`/`mesh_url`/`fbx_url` vão junto (fusão FoxAssets, 2026-08-01): a
            // tela de Personagens monta a ficha a partir DESTA lista — sem eles no payload o form
            // abria vazio e "Salvar ficha" mandava null, apagando o papel e a logline.
            ->get(['id', 'name', 'description', 'style', 'image_model', 'text_model', 'base_url', 'sheet_url', 'mesh_url', 'mesh_status', 'mesh_msg', 'fbx_url', 'sheets', 'sheet_pending', 'lock', 'bible', 'archetype', 'logline', 'status', 'updated_at'])
            ->each(fn (Character $c) => $this->unstick($c)); // destrava status preso na leitura

        return response()->json($items);
    }

    /** GET /api/characters/{id} → um personagem (usado no POLLING da geração assíncrona). */
    public function show(Request $r, string $id): JsonResponse
    {
        return response()->json($this->unstick($this->character($r, $id)));
    }

    /** POST /api/characters { name?, description?, style? } → cria um personagem (sem gerar nada). */
    public function store(Request $r): JsonResponse
    {
        $data = $r->validate([
            'name' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'style' => 'nullable|string|max:40',
            'image_model' => 'nullable|string|max:60',
            'text_model' => 'nullable|string|max:60',
            // F1 (fluxo image→cena): ficha metodológica.
            'archetype' => 'nullable|string|in:heroi,mentor,guardiao_limiar,arauto,camaleao,sombra,picaro',
            'logline' => 'nullable|string|max:240',
            'bible' => 'nullable|array',
        ]);
        $c = Character::create([
            'tenant_id' => $this->tenant($r)->id,
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Personagem',
            'description' => trim((string) ($data['description'] ?? '')),
            'style' => $this->normStyle($data['style'] ?? 'realista'),
            'image_model' => trim((string) ($data['image_model'] ?? '')) ?: null,
            'text_model' => trim((string) ($data['text_model'] ?? '')) ?: null,
            'archetype' => $data['archetype'] ?? null,
            'logline' => trim((string) ($data['logline'] ?? '')) ?: null,
            'bible' => $data['bible'] ?? null,
        ]);

        return response()->json(['ok' => true, 'character' => $c], 201);
    }

    /** PATCH /api/characters/{id} { name?, description?, style? } → edita os campos de texto. */
    public function update(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        $data = $r->validate([
            'name' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
            'style' => 'nullable|string|max:40',
            'image_model' => 'nullable|string|max:60',
            'text_model' => 'nullable|string|max:60',
            // F1: ficha metodológica.
            'archetype' => 'nullable|string|in:heroi,mentor,guardiao_limiar,arauto,camaleao,sombra,picaro',
            'logline' => 'nullable|string|max:240',
            'bible' => 'nullable|array',
            // F2: a base gerada localmente pela ficha aponta pra /api/image/file/... (URL local).
            'base_url' => 'nullable|string|max:1000',
        ]);
        $upd = [];
        if (array_key_exists('name', $data)) {
            $upd['name'] = trim((string) $data['name']) ?: 'Personagem';
        }
        if (array_key_exists('description', $data)) {
            $upd['description'] = trim((string) $data['description']);
        }
        if (array_key_exists('style', $data)) {
            $upd['style'] = $this->normStyle($data['style']);
        }
        if (array_key_exists('image_model', $data)) {
            $upd['image_model'] = trim((string) $data['image_model']) ?: null;
        }
        if (array_key_exists('text_model', $data)) {
            $upd['text_model'] = trim((string) $data['text_model']) ?: null;
        }
        if (array_key_exists('archetype', $data)) {
            $upd['archetype'] = $data['archetype'] ?: null;
        }
        if (array_key_exists('logline', $data)) {
            $upd['logline'] = trim((string) $data['logline']) ?: null;
        }
        // Bible VAZIA não apaga a salva (mesma classe do spec do cenário): a ficha vive num
        // <details> que não remonta, então {} chega por tela stale, nunca por intenção.
        if (array_key_exists('bible', $data) && ! empty($data['bible'])) {
            $upd['bible'] = $data['bible'];
        }
        if (array_key_exists('base_url', $data)) {
            $upd['base_url'] = trim((string) $data['base_url']) ?: null;
        }
        if ($upd !== []) {
            $c->update($upd);
        }

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** DELETE /api/characters/{id} → remove o personagem (registro local; não apaga mídia do S3). */
    public function destroy(Request $r, string $id): JsonResponse
    {
        $this->character($r, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/characters/persona-chat { persona, message, json?, textModel? } → roda uma PERSONA
     *  da aba Prompts (o `title` dela) contra uma mensagem e devolve o texto.
     *
     *  A persona é resolvida pelo TÍTULO, dentro do tenant — o front não manda o system, então
     *  ninguém injeta prompt de sistema arbitrário por aqui. */
    public function personaChat(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $data = $r->validate([
            'persona' => 'required|string|max:120',   // trecho do título da persona (aba Prompts)
            'message' => 'required|string|max:4000',
            'json' => 'nullable|boolean',
            'textModel' => 'nullable|string|max:80',
        ]);

        $persona = Prompt::where('tenant_id', $t->id)
            ->where('title', 'like', '%'.$data['persona'].'%')
            ->first();
        if (! $persona || trim((string) $persona->content) === '') {
            return response()->json(['ok' => false, 'error' => "Persona '{$data['persona']}' não encontrada na aba Prompts."], 404);
        }

        $res = $this->engine()->timeout(150)->post('/v1/chat', [
            'system' => (string) $persona->content,
            'message' => trim($data['message']),
            'json' => (bool) ($data['json'] ?? false),
            // 4000, não o default 2000: a ficha do Arquiteto (desejo/conflito/antagonista/físico/
            // psicológico/arco) passa folgado dos 2000 e voltava CORTADA — JSON truncado não
            // parseia, e o botão dizia "respondeu fora do formato" sem que o modelo tivesse errado.
            'maxTokens' => 4000,
            'gen_lines' => $this->textGenLines($this->textModelFor($r, $t->plan ?? null)),
        ]);
        $text = trim((string) $res->json('text'));
        if (! $res->successful() || $text === '') {
            return response()->json(['ok' => false, 'error' => 'A persona não respondeu.'], 502);
        }

        return response()->json(['ok' => true, 'text' => $text, 'persona' => $persona->title]);
    }

    /**
     * POST /api/characters/{id}/extract { lang?, textModel? } → lê a FOTO JÁ ENVIADA (a base) e
     * extrai a identidade: character lock + bíblia, por vision. Custa só TEXTO.
     *
     * NÃO gera model sheet — a prancha continua no botão dela, depois que a identidade estiver boa.
     */
    public function extractFromBase(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url) {
            return response()->json(['ok' => false, 'error' => 'Suba a foto do personagem primeiro (📤 Subir base).'], 422);
        }
        $lang = (string) $r->input('lang', 'pt-BR');

        // A vision recebe a imagem INLINE (o provedor não baixa do nosso storage), então a foto
        // volta pra cá pra virar data URL. `visionDataUrl` reduz pra 1024px — foto de celular em
        // base64 estoura o teto de 1MB do engine (AUD-011) e o personagem ficava SEM lock em
        // silêncio.
        $tmp = tempnam(sys_get_temp_dir(), 'extract');
        try {
            $resp = Http::timeout(30)->get($c->base_url);
            if (! $resp->successful() || $resp->body() === '') {
                return response()->json(['ok' => false, 'error' => 'Não foi possível ler a foto enviada. Suba de novo.'], 502);
            }
            file_put_contents($tmp, $resp->body());
            $dataUrl = StudioController::visionDataUrl($tmp)
                ?? 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($tmp));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Não foi possível ler a foto enviada. Suba de novo.'], 502);
        } finally {
            @unlink($tmp);
        }

        // Cobrança por MODELO de texto, com estorno na falha — mesmo contrato do generateSheet.
        if (! is_string($r->input('textModel')) || trim((string) $r->input('textModel')) === '') {
            $r->merge(['textModel' => (string) ($c->text_model ?: '')]);
        }
        $tm = $this->textModelFor($r, $this->tenant($r)->plan);
        if ($tm && $tm->slug !== $c->text_model) {
            $c->forceFill(['text_model' => $tm->slug])->save();
        }
        if (! $this->usage->tryConsume($this->tenant($r), 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }

        try {
            $resp = $this->engine()->timeout(150)->post('/v1/characterbible', [
                'name' => $c->name, 'style' => $c->style, 'lang' => $lang, 'imageDataUrl' => $dataUrl,
                // A FICHA vai junto com a foto. Extrair olhava só a imagem, então tudo que o autor
                // escreveu e a foto não mostra sumia do lock — a MEL era "Female Dachshund" na
                // description e virou "sex unspecified" no lock, e o vídeo devolveu um cão macho.
                'description' => (string) $c->description,
                'gen_lines' => $this->textGenLines($tm),
            ]);
            $b = $resp->successful() ? $resp->json() : null;
            if (! is_array($b) || trim((string) ($b['lock'] ?? '')) === '') {
                $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);

                return response()->json(['ok' => false, 'error' => 'A IA não reconheceu o personagem na foto. Tente outra imagem.'], 502);
            }
            $this->ajustaSeReserva($this->tenant($r), $tm, $resp);
        } catch (\Throwable $e) {
            $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'Não foi possível extrair agora.'], 502);
        }

        // Identidade vive no `lock`; a description NÃO recebe o lock (senão o Editar mostra o
        // CHARACTER LOCK repetido).
        $bible = array_intersect_key($b, array_flip(['subject', 'palette', 'traits', 'accessories', 'expressions']));
        $c->update(['lock' => (string) $b['lock'], 'bible' => $bible]);

        // 2º passo: a identidade vira a DESCRIÇÃO que regera o personagem SEM a foto. O molde da
        // categoria (persona da aba Prompts) é que sabe o que cada tipo de sujeito exige. Best-effort:
        // falhar aqui não desfaz o lock, que é o que importa.
        $escolhido = trim((string) $r->input('molde'));
        $molde = ($escolhido !== '' && str_contains($escolhido, 'Molde:') && mb_strlen($escolhido) <= 60)
            ? $escolhido
            : MoldePersonagem::detectar($bible, (string) $b['lock']);
        $aplicado = null;
        if ($molde && $sys = MoldePersonagem::conteudo($this->tenant($r)->id, $molde)) {
            if ($this->usage->tryConsume($this->tenant($r), 'text', 1, $tm?->cost_credits)) {
                try {
                    $d = $this->engine()->timeout(150)->post('/v1/chat', [
                        'system' => $sys,
                        'message' => "NOME: {$c->name}\nESTILO: {$c->style}\nLOCK: ".$b['lock']
                            ."\nFICHA: ".json_encode($bible, JSON_UNESCAPED_UNICODE),
                        'maxTokens' => 1200,
                        'gen_lines' => $this->textGenLines($tm),
                    ]);
                    $txt = trim((string) $d->json('text'));
                    if ($d->successful() && $txt !== '') {
                        // A descrição entra no lugar do `desc` do prompt-base canônico — por isso
                        // o molde devolve SÓ o sujeito, sem câmera/fundo/negativos.
                        $this->ajustaSeReserva($this->tenant($r), $tm, $d);
                        $c->update(['description' => mb_substr($txt, 0, 4000)]);
                        $aplicado = $molde;
                    } else {
                        $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
                    }
                } catch (\Throwable $e) {
                    $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
                }
            }
        }

        return response()->json(['ok' => true, 'character' => $c->fresh(), 'molde' => $aplicado]);
    }

    /** POST /api/characters/{id}/base { description?, style? } → gera a imagem-BASE (retrato
     *  frontal, t2i). Reserva 1 do bucket 'image' (estornado no job se falhar). Assíncrono. */
    public function generateBase(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        $desc = trim((string) ($r->input('description') ?: $c->description));
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o personagem antes de gerar a referência.'], 422);
        }
        $style = $this->normStyle($r->input('style') ?: $c->style);
        // Modelo da BASE: request explícito > o SALVO no personagem (regenerar reusa a escolha
        // original — pedido Luciano) > fallback topo. O slug usado é PERSISTIDO no personagem.
        if (! is_string($r->input('model')) || trim((string) $r->input('model')) === '') {
            $r->merge(['model' => (string) ($c->image_model ?: '')]);
        }
        $gm = $this->imageModel($r, self::MODEL_T2I);
        if (! $res = $this->reserve($r, $gm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        [$weight, $costCredits] = $res;
        $prompt = $this->basePromptText($desc, $style); // ver o docblock do método pro porquê do framing
        $payload = ['prompt' => $prompt, 'aspect' => '3:4', 'style' => $style];
        if ($gm) {
            $payload['provider'] = $gm->provider;       // minimax | kie → o engine roteia
            $payload['model'] = $gm->provider_model_id; // image-01 | nano-banana-2 | seedream/4.5-text-to-image
            if ($gm->provider === 'kie') {
                $payload['kie'] = $gm->capabilities['kie'] ?? new \stdClass; // spec schema-driven (interno)
            }
        }
        if (! $this->claim($c, ['description' => $desc, 'style' => $style, 'status' => 'base', 'image_model' => $gm?->slug])) {
            $this->usage->refund($this->tenant($r), 'image', $weight, $costCredits);

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }
        GenerateCharacterJob::dispatch($c->id, $this->tenant($r)->id, 'base',
            $payload, $weight, $costCredits);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/base-upload (multipart: file) → usa uma imagem gerada FORA do
     *  Reachyn (ferramenta sem API — copiar o prompt de GET .../prompts e colar lá) como imagem-
     *  base do personagem. Sem IA, sem cota — só troca a URL. NÃO apaga o model sheet existente
     *  automaticamente: a base mudou, mas cabe ao cliente decidir se regenera as pranchas. */
    public function baseUpload(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        $r->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240']); // 10MB, mesmo teto do upload genérico (AUD-024)
        $file = $r->file('file');
        $url = StudioController::storeUploadedFile($file, strtolower((string) ($file->guessExtension() ?: 'jpg')), 'image');
        $c->update(['base_url' => $url]);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/sheet { lang? } → gera o MODEL SHEET COMPLETO (turnaround +
     *  expressões + detalhes + acessórios + paleta + poses, i2i da base) + a bíblia (texto, síncrona,
     *  que informa o prompt). Exige base_url. Reserva 1 do bucket 'image' (a bíblia não custa cota). */
    public function generateSheet(Request $r, string $id, ModelSheetService $sheets): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem-base do personagem primeiro.'], 422);
        }
        $lang = (string) $r->input('lang', 'pt-BR');

        // 1) BÍBLIA (texto, SEM cota) — destila lock + paleta + traços + acessórios + expressões. Roda
        //    SÍNCRONO aqui (texto rápido) porque INFORMA o prompt do model sheet (quais expressões/
        //    acessórios incluir na folha). Best-effort: se falhar, segue com prompt genérico completo.
        $bible = null;
        $lock = null;
        // BÍBLIA cobrada por MODELO (seletor textModel; default Equilibrado). Falhou → estorna e
        // segue sem bíblia (best-effort, como antes).
        if (! is_string($r->input('textModel')) || trim((string) $r->input('textModel')) === '') {
            $r->merge(['textModel' => (string) ($c->text_model ?: '')]); // reusa o modelo SALVO na criação
        }
        $tm = $this->textModelFor($r, $this->tenant($r)->plan);
        if ($tm && $tm->slug !== $c->text_model) {
            $c->forceFill(['text_model' => $tm->slug])->save(); // persiste o usado (criação legada/troca)
        }
        $bibleCharged = $this->usage->tryConsume($this->tenant($r), 'text', 1, $tm?->cost_credits);
        try {
            $resp = $bibleCharged ? $this->engine()->post('/v1/characterbible', [
                'name' => $c->name, 'description' => $c->description, 'style' => $c->style, 'lang' => $lang,
                'gen_lines' => $this->textGenLines($tm),
            ]) : null;
            if ($resp && $resp->successful() && is_array($b = $resp->json())) {
                $this->ajustaSeReserva($this->tenant($r), $tm, $resp);
                $lock = (string) ($b['lock'] ?? '');
                $bible = array_intersect_key($b, array_flip(['subject', 'palette', 'traits', 'accessories', 'expressions']));
            } elseif ($bibleCharged) {
                $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
            }
        } catch (\Throwable $e) {
            if ($bibleCharged) {
                $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
            }
            // segue sem bíblia (prompt genérico)
        }

        // 2) MODEL SHEET DETERMINÍSTICO: a IA gera só SHOTS individuais limpos (i2i ancorado na base,
        //    sem texto) e o ffmpeg-service COMPÕE cada folha num template FIXO (grid + rótulos corretos
        //    + swatches). Antes, a IA desenhava a folha inteira → layout mudava a cada geração e o texto
        //    saía alucinado. Salva lock/bible no objeto ANTES (o builder de grupos lê $c->lock/description/bible).
        if ($lock !== null && trim($lock) !== '') {
            $c->lock = $lock;
        }
        if ($bible !== null) {
            $c->bible = $bible;
        }
        $groups = $sheets->groups($c, $bible, $lang);
        $n = $sheets->shotCount($groups); // total de shots de IA (paleta não gera imagem, não conta)

        // 3) cota da imagem — reserva N shots de uma vez. Shots que falharem são estornados no job.
        // 🐛 COBRANÇA AO QUADRADO (corrigido 2026-07-22): o custo aqui é o de UM shot; quem multiplica
        // pelo nº de shots é o UsageService (`custo × peso`). Passar `$perCost * $n` COM peso `$n`
        // cobrava $perCost × n². Caso real em prod: model sheet de 31 shots a 9 créditos debitou
        // 8.649 em vez de 279 (31×). Regra: peso = quantidade · custo = unitário. Vale pro refund.
        $perCost = $this->modelCost($r, self::MODEL_I2I_SHEET);
        $perWeight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $perWeight * $n, $perCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        // 4) salva lock/bible + marca gerando e ZERA as folhas antigas (regeneração do zero) — atômico.
        $upd = ['status' => 'sheet', 'sheet_pending' => $n, 'sheets' => []];
        if ($lock !== null && trim($lock) !== '') {
            $upd['lock'] = $lock;
        }
        if ($bible !== null) {
            $upd['bible'] = $bible;
        }
        if (! $this->claim($c, $upd)) {
            $this->usage->refund($this->tenant($r), 'image', $perWeight * $n, $perCost);

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }

        // 5) UM job orquestrador gera todos os shots (concorrente) + compõe as folhas.
        GenerateModelSheetJob::dispatch($c->id, $this->tenant($r)->id, $bible, $lang, null, $perWeight, $perCost);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** GET /api/characters/{id}/prompts?lang= → os prompts RECONSTRUÍDOS (não persistidos — nunca
     *  gravamos o texto que foi pro modelo, só a imagem resultante) da imagem-base e de cada shot
     *  do model sheet, incluindo o wrapper de estilo/qualidade que o engine cola por baixo (pede
     *  ao engine via /v1/stylewrap). Determinístico: mesmo texto que a geração real usaria AGORA,
     *  a partir de description/style/lock/bible atuais — se o personagem mudar, o prompt muda junto.
     *  Uso: copiar e colar numa ferramenta externa sem API (Midjourney, etc) quando o provedor
     *  principal está indisponível. Leitura pura — sem cota, sem gerar nada. */
    public function prompts(Request $r, string $id, ModelSheetService $sheets): JsonResponse
    {
        $c = $this->character($r, $id);
        $lang = (string) $r->input('lang', 'pt-BR');
        $style = $this->normStyle($c->style);
        $desc = trim((string) $c->description);

        [$prefix, $suffix] = $this->styleWrap($style);
        $wrap = fn (string $p) => trim($prefix.$p.$suffix);

        $base = $desc !== '' ? $wrap($this->basePromptText($desc, $style)) : null;

        $panels = [];
        if ($c->base_url) {
            $groups = $sheets->groups($c, is_array($c->bible) ? $c->bible : null, $lang);
            foreach ($groups as $kind => $g) {
                $panels[] = [
                    'kind' => (string) $kind,
                    'title' => (string) ($g['title'] ?? ''),
                    'subtitle' => (string) ($g['subtitle'] ?? ''),
                    'shots' => array_map(
                        fn ($s) => ['label' => (string) ($s['label'] ?? ''), 'prompt' => $wrap((string) ($s['prompt'] ?? ''))],
                        $g['shots'] ?? []
                    ),
                ];
            }
        }

        return response()->json(['base' => $base, 'lock' => $c->lock, 'style' => $style, 'sheets' => $panels]);
    }

    /** Busca o prefixo+sufixo de estilo no engine (GET /v1/stylewrap) — fonte única (Go), evita
     *  duplicar a tabela de estilos em PHP e ela driftar da real. Falha do engine → sem wrapper
     *  (o prompt ainda sai útil, só sem a direção de qualidade/negativos). */
    private function styleWrap(string $style): array
    {
        try {
            $resp = $this->engine()->get('/v1/stylewrap', ['style' => $style]);
            if ($resp->successful()) {
                return [(string) ($resp->json('prefix') ?? ''), (string) ($resp->json('suffix') ?? '')];
            }
        } catch (\Throwable $e) {
            // segue sem wrapper
        }

        return ['', ''];
    }

    // Pranchas canônicas do MODEL SHEET v3 (ordem fixa; [0]=angles vira o sheet_url primário). Usada
    // pra validar o kind na regeneração/remoção per-prancha e pra saber quantas são numa geração cheia.
    // 'shots' (planos de câmera) é OPT-IN — fica de fora do bundle padrão do generateSheet() (ver lá),
    // mas é válido pra regeneratePanel() (o mesmo botão gera a 1ª vez e regenera depois).
    private const PANEL_KINDS = ['angles', 'head', 'poses', 'palette', 'shots'];

    /**
     * Pranchas do MODEL SHEET v3 (character-design sheet de estúdio, padrão de referência "Giraffo").
     * Cada prancha é uma imagem i2i ancorada na base. O conteúdo é AGRUPADO por tema SEM sobreposição
     * entre pranchas (o v2 espalhava top/bottom e paleta na folha de poses → repetição). Ordem fixa
     * (= PANEL_KINDS); [0]=angles (turnaround) vira o sheet_url primário.
     *
     * Organização (pedido do Luciano, espelha o model sheet de referência):
     *  - angles : turnaround 360° de 8 vistas + TOPO (bird's eye) + BASE (worm's eye) — todos os ângulos e posições.
     *  - head   : detalhe da cabeça em 3 ângulos (frente/perfil/traseira) + grade de EXPRESSÕES faciais.
     *  - poses  : poses de corpo inteiro (em pé, sentada, deitada, pulando, andando) + mão + pé + corpo.
     *  - palette: paleta de cores rotulada (com hex) + acessórios/roupa + materiais e texturas.
     *
     * A PALETA (hex da bíblia) é travada no lock de TODAS as pranchas p/ não driftar cor entre folhas
     * ("devem manter o padrão de cores"). $only filtra p/ 1 prancha (regeneração per-prancha).
     *
     * @param  ?string  $only  kind único a retornar (regeneração); null = as 4 pranchas.
     * @param  string  $tweak  ajuste livre do usuário (edição da prancha), anexado ao prompt canônico.
     * @param  ?string  $lock  CHARACTER LOCK textual (espécie/sexo/traços) — travado em toda prancha.
     * @param  string  $name  nome do personagem (reforça a identidade).
     * @param  string  $desc  descrição do usuário (fallback quando não há lock — garante o sexo/espécie).
     * @return array<int,array{kind:string,aspect:string,prompt:string}>
     */
    private function sheetPanels(?array $bible, string $lang, ?string $only = null, string $tweak = '', ?string $lock = null, string $name = '', string $desc = ''): array
    {
        // Idioma dos RÓTULOS: PT-BR por padrão (fixo, embutido no prompt — pedir "escreva no idioma X"
        // o modelo ignorava). Só en-US troca pra inglês. Os textos vão LITERAIS no prompt (entre aspas)
        // pra o modelo escrever exatamente eles.
        $pt = $lang !== 'en-US';
        $L = fn (string $ptTxt, string $enTxt) => $pt ? $ptTxt : $enTxt;
        $langNote = $pt
            ? ' Every label and section title in the image must be written in Brazilian Portuguese, using ONLY the exact quoted text given for each label, with no extra words appended.'
            : ' Every label and section title in the image must be written in English, using ONLY the exact quoted text given for each label, with no extra words appended.';

        // Snippets derivados da bíblia (best-effort; vazio = folha genérica completa).
        $exprs = ! empty($bible['expressions']) ? array_slice($bible['expressions'], 0, 8) : [];
        $exprTxt = $exprs ? ' ('.implode(', ', $exprs).')' : $L(' (neutro, sorriso, riso, surpreso, bravo, triste)', ' (neutral, smile, laugh, surprised, angry, sad)');
        $acc = ! empty($bible['accessories']) ? array_slice($bible['accessories'], 0, 6) : [];
        $accTxt = $acc ? ' ('.implode(', ', $acc).')' : '';
        $palLabels = [];
        foreach ((array) ($bible['palette'] ?? []) as $sw) {
            $lbl = trim((string) ($sw['label'] ?? ''));
            $hex = trim((string) ($sw['hex'] ?? ''));
            if ($lbl !== '' || $hex !== '') {
                $palLabels[] = trim($lbl.' '.$hex);
            }
        }
        $palList = array_slice($palLabels, 0, 9);
        $palTxt = $palList ? ' ('.implode(', ', $palList).')' : '';

        // Identidade + PALETA TRAVADAS, repetidas em TODA prancha (character lock + color lock). É o
        // que garante o "mesmo personagem, mesmas cores" entre as folhas.
        // IDENTIDADE TEXTUAL: a âncora i2i sozinha não bastava — numa base onde o sexo/espécie não é
        // óbvio, o modelo driftava (ex: cachorro FÊMEA virando macho no model sheet). Injetamos o
        // CHARACTER LOCK textual (ou a descrição do usuário como fallback) e PROIBIMOS trocar o sexo,
        // espelhando o que o outfitPrompt já fazia ("sem isso o modelo gerava outro cachorro").
        $who = trim($name) !== '' ? ' named "'.trim($name).'"' : '';
        $identitySrc = trim((string) $lock) !== '' ? trim((string) $lock) : trim($desc);
        $identityTxt = '';
        if ($identitySrc !== '') {
            $identityTxt = 'The character is defined by this locked description; keep EVERY trait of it exactly — '
                .'especially the species, breed and SEX/GENDER (if it is female keep it female, if male keep it '
                .'male — never swap the sex): '
                .trim((string) preg_replace('/\s*[\r\n]+\s*/', '; ', $identitySrc)).'. ';
        }
        $id = 'This is the exact same character shown in the reference image'.$who.'. '
            .'Keep the identical species, breed, sex, face, body, proportions, colors, outfit and art style in '
            .'every panel — never redesign, re-sex or invent a different character. '
            .$identityTxt
            .'Keep the character\'s natural body type from the reference — if it is a four-legged animal keep it '
            .'four-legged, do not make it bipedal or humanoid. '
            // ANATOMIA NEUTRA: a vista de baixo (worm's-eye) fazia o modelo desenhar genitália masculina —
            // um dachshund FÊMEA "virava macho". A intenção continua; o FRASEADO mudou.
            //
            // A versão anterior dizia "do NOT depict any genitalia or sexual anatomy" e isso custou caro
            // duas vezes: (1) nomear os termos DISPARAVA a moderação dos provedores — a MiniMax barrava
            // com "new_sensitive" e o KIE passou a responder "flagged as sensitive" (502 em todo shot,
            // 2026-07-21), derrubando o model sheet inteiro; (2) proibição só funciona se o modelo
            // souber ignorar a palavra que você acabou de escrever — pedir o que se QUER é mais
            // confiável que listar o que não se quer.
            //
            // Agora descreve o resultado desejado, em linguagem positiva e sem termo que acione filtro.
            .'This is a clean professional character sheet: keep the belly and underside completely smooth, '
            .'featureless and toy-like in every view, exactly as a printed reference sheet would show. '
            .($palList
                ? 'Strictly keep the character\'s exact color palette consistent across the whole sheet'.$palTxt.'. '
                : 'Strictly keep the character\'s exact colors consistent across the whole sheet. ');

        $sheetBase = 'One clean image on a plain light neutral studio background, laid out like a printed '
            .'character-design reference sheet, ultra-detailed, perfectly consistent identity and colors. ';

        $panels = [
            // Prancha 1 — ÂNGULOS & POSIÇÕES: turnaround 360° (8 vistas em DUAS fileiras de 4 pra não cortar
            // as figuras — pedido do Luciano) + topo + base. As DIREÇÕES de encaramento (borda esquerda/
            // direita) e o ESPELHAMENTO dos perfis são explícitos — sem isso o modelo virava os dois perfis
            // (e as 3/4) pro mesmo lado.
            'angles' => ['kind' => 'angles', 'aspect' => '4:3', 'prompt' => $id.$sheetBase
                .'A professional character TURNAROUND sheet: EIGHT full-body views of the SAME character at '
                .'identical scale, arranged in TWO horizontal rows of four views each (a top row and a bottom '
                .'row), each row on its own common ground line, in a neutral relaxed pose (arms slightly away '
                .'from the torso), same height and centering in every view. The character makes ONE smooth 360° '
                .'rotation, turning 45° between consecutive views. DO NOT CROP: every view must show the ENTIRE '
                .'body from the top of the head to the soles of the feet, fully inside the frame, scaled down '
                .'enough to leave a clear empty margin above the head and below the feet; no figure may touch '
                .'or be cut off by the top, bottom or side edges of the image (the two rows give room for this). '
                .'CRITICAL ORIENTATION: the two side profiles must face OPPOSITE horizontal directions '
                .'(perfect mirror images of each other), and the two front three-quarter views must also face '
                .'opposite directions — NEVER both turned to the same side. Reading order: the TOP row holds '
                .'views 1 to 4 (left to right) and the BOTTOM row holds views 5 to 8 (left to right); label each '
                .'underneath with EXACTLY this text: '
                .'1) "'.$L('FRENTE (0°)', 'FRONT (0°)').'" facing straight at the viewer; '
                .'2) "'.$L('3/4 ESQUERDA (45°)', '3/4 LEFT (45°)').'" body turned so the face points toward the LEFT side of the image; '
                .'3) "'.$L('PERFIL ESQUERDO (90°)', 'LEFT PROFILE (90°)').'" full side profile facing the LEFT edge of the image; '
                .'4) "'.$L('3/4 TRASEIRA ESQUERDA (135°)', '3/4 REAR-LEFT (135°)').'" seen mostly from behind, still angled to the LEFT; '
                .'5) "'.$L('COSTAS (180°)', 'BACK (180°)').'" seen fully from the back; '
                .'6) "'.$L('3/4 TRASEIRA DIREITA (225°)', '3/4 REAR-RIGHT (225°)').'" seen mostly from behind, angled to the RIGHT; '
                .'7) "'.$L('PERFIL DIREITO (270°)', 'RIGHT PROFILE (270°)').'" full side profile facing the RIGHT edge of the image, the exact mirror of the left profile; '
                .'8) "'.$L('3/4 DIREITA (315°)', '3/4 RIGHT (315°)').'" body turned so the face points toward the RIGHT side of the image. '
                .'Then ONE smaller extra view, clearly separated, labeled EXACTLY: '
                .'"'.$L('TOPO (VISTA DE CIMA)', 'TOP (BIRD\'S-EYE VIEW)').'" a top-down bird\'s-eye view. '
                // NÃO incluir a vista de baixo (barriga pra cima): expunha a virilha e o modelo desenhava
                // genitália masculina, fazendo a fêmea parecer macho. É a vista menos útil do turnaround.
                .'Do NOT include any bottom-up, belly-up or underside view of the character. '
                .'Soft even studio lighting, no cast shadows, no props. Orthographic turnaround.'.$langNote],

            // Prancha 2 — CABEÇA & EXPRESSÕES: 3 ângulos de cabeça + grade de expressões faciais.
            'head' => ['kind' => 'head', 'aspect' => '4:3', 'prompt' => $id.$sheetBase
                .'A professional HEAD & EXPRESSIONS reference sheet with clearly separated sections, each with '
                .'a title written EXACTLY as given: '
                .'(1) "'.$L('DETALHE DA CABEÇA', 'HEAD DETAIL').'" — three head-and-neck close-ups showing the '
                .'front, the profile and the back of the head (hair, ears, facial features). '
                .'(2) "'.$L('EXPRESSÕES', 'EXPRESSIONS').'" — a tidy grid of head-only close-up face portraits, '
                .'each tightly cropped at the neck with NO shoulders, NO torso and NO body visible; only the '
                .'head and face are shown and only the facial expression changes between them'.$exprTxt.'. Keep '
                .'the identical face, features and colors in every portrait. Soft even lighting.'.$langNote],

            // Prancha 3 — POSES: corpo inteiro (variadas) + mão + pé + corpo.
            'poses' => ['kind' => 'poses', 'aspect' => '16:9', 'prompt' => $id.$sheetBase
                .'A professional POSES reference sheet with clearly separated sections, each with a title '
                .'written EXACTLY as given: '
                .'(1) "'.$L('POSES', 'BODY POSES').'" — a row of full-body poses at identical scale on one '
                .'common ground line: '.$L('em pé, sentado, deitado, pulando, andando e gesticulando', 'standing idle, sitting, lying down, jumping, walking and gesturing').'. '
                .'DO NOT CROP the poses: each figure fully inside the frame from head to feet, with margin, '
                .'never cut off by the edges. '
                .'(2) "'.$L('MÃOS E PÉS', 'HANDS & FEET').'" — close-ups of the character\'s hand (relaxed, open '
                .'and closed) and of the foot or footwear from a couple of angles. Every pose keeps the '
                .'identical character, outfit and colors. Soft even lighting.'.$langNote],

            // Prancha 4 — PALETA & ACESSÓRIOS: swatches + acessórios/roupa + materiais (sem corpo inteiro).
            'palette' => ['kind' => 'palette', 'aspect' => '4:3', 'prompt' => $id.$sheetBase
                .'A professional COLOR PALETTE, ACCESSORIES & MATERIALS reference sheet with clearly separated '
                .'sections, each with a title written EXACTLY as given: '
                .'(1) "'.$L('PALETA DE CORES', 'COLOR PALETTE').'" — a horizontal strip of the character\'s key '
                .'color swatches, each with a small text label'.$palTxt.'. '
                .'(2) "'.$L('ACESSÓRIOS E ROUPA', 'ACCESSORIES & OUTFIT').'" — the signature clothing and '
                .'accessories laid out as separate, labeled items'.$accTxt.'. '
                .'(3) "'.$L('MATERIAIS E TEXTURAS', 'MATERIALS & TEXTURES').'" — small swatches of the '
                .'character\'s key materials and textures (fur or skin, fabric, etc). Do NOT show the full-body '
                .'character in this panel, only the palette strip, the item cutouts and the material swatches. '
                .'Soft even lighting.'.$langNote],

            // Prancha 5 (OPT-IN) — PLANOS DE CÂMERA: 15 enquadramentos cinematográficos clássicos do
            // MESMO personagem, numa grade 3×5. Diferente das outras pranchas (fundo neutro de estúdio),
            // aqui alguns planos SÃO sobre escala/ambiente (over shoulder aberto, panorâmico, zenital,
            // aberto final) — permite um cenário simples e coerente com o personagem nesses casos.
            'shots' => ['kind' => 'shots', 'aspect' => '16:9', 'prompt' => $id.$sheetBase
                .'A professional CINEMATIC SHOTS reference sheet: FIFTEEN small panels of the SAME character, '
                .'arranged in THREE horizontal rows of five panels each, each panel the same size with a clear '
                .'gap between panels, each labeled underneath with EXACTLY this text (no extra words): '
                .'ROW 1 (panels 1-5): '
                .'1) "'.$L('PLANO MÉDIO', 'MEDIUM SHOT').'" — character from the waist up, looking at camera. '
                .'2) "'.$L('PLANO AMERICANO', 'AMERICAN SHOT').'" — character from mid-thigh up. '
                .'3) "'.$L('CLOSE-UP', 'CLOSE-UP').'" — tight close-up on the face only, showing expression. '
                .'4) "'.$L('PLANO PERFIL', 'PROFILE SHOT').'" — full side profile view of the character. '
                .'5) "'.$L('CONTRA-PLONGÉE', 'LOW-ANGLE SHOT').'" — camera looking sharply UP at the character, making them look powerful and imposing. '
                .'ROW 2 (panels 6-10): '
                .'6) "'.$L('PLONGÉE', 'HIGH-ANGLE SHOT').'" — camera looking sharply DOWN at the character from above. '
                .'7) "'.$L('OVER SHOULDER', 'OVER-THE-SHOULDER SHOT').'" — view from behind the character\'s shoulder, looking out at a simple fitting environment/scene. '
                .'8) "'.$L('PLANO PANORÂMICO', 'WIDE ESTABLISHING SHOT').'" — a wide shot of a simple environment fitting the character, with the character small in the frame. '
                .'9) "'.$L('HERO SHOT', 'HERO SHOT').'" — character centered, in an epic, confident pose, low dramatic angle. '
                .'10) "'.$L('OVER SHOULDER ABERTO', 'WIDE OVER-SHOULDER SHOT').'" — from behind the character, character small in frame, the environment dominating. '
                .'ROW 3 (panels 11-15): '
                .'11) "'.$L('OVER SHOULDER FECHADO', 'TIGHT OVER-SHOULDER SHOT').'" — from behind, the character\'s head and shoulders filling most of the frame. '
                .'12) "'.$L('PLANO ZENITAL', 'ZENITHAL SHOT').'" — straight top-down bird\'s-eye view of the character. '
                .'13) "'.$L('EXTREME CLOSE-UP', 'EXTREME CLOSE-UP').'" — extremely tight close-up on just the eyes. '
                .'14) "'.$L('PLANO HOLANDÊS', 'DUTCH ANGLE SHOT').'" — the camera tilted diagonally, horizon at an angle. '
                .'15) "'.$L('PLANO ABERTO FINAL', 'FINAL WIDE SHOT').'" — a grand, cinematic wide establishing shot of the character in their environment, epic closing composition. '
                .'Keep the identical character, face, outfit and colors in every panel — only the camera framing/angle changes. Soft cinematic lighting.'.$langNote],
        ];

        // Ajuste livre do usuário (edição da prancha) entra como instrução final.
        if (($t = trim($tweak)) !== '') {
            foreach ($panels as $k => $p) {
                $panels[$k]['prompt'] = $p['prompt'].' Requested adjustments: '.$t.'.';
            }
        }

        if ($only !== null) {
            return isset($panels[$only]) ? [$panels[$only]] : [];
        }

        return array_values($panels);
    }

    /** POST /api/characters/{id}/edit-base { prompt } → edita a imagem-base via i2i (no lugar).
     *  Exige base_url + prompt. Reserva 1 do bucket 'image'. Assíncrono. */
    public function editBase(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem-base do personagem primeiro.'], 422);
        }
        $prompt = trim((string) $r->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o que alterar na imagem.'], 422);
        }
        $cost = $this->modelCost($r, self::MODEL_I2I);
        if (! $res = $this->reserve($r, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        [$weight, $costCredits] = $res;
        if (! $this->claim($c, ['status' => 'edit'])) {
            $this->usage->refund($this->tenant($r), 'image', $weight, $costCredits);

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }
        GenerateCharacterJob::dispatch($c->id, $this->tenant($r)->id, 'edit',
            ['prompt' => $prompt, 'aspect' => '3:4', 'style' => $c->style, 'imageUrls' => [$c->base_url]], $weight, $costCredits);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/enhance-base { op } → aplica NA BASE (no lugar, atualiza base_url):
     *  remove_bg (tira o fundo → âncora limpa, sem cenário vazando no model sheet) ou upscale/
     *  upscale_pro (melhora a qualidade da referência). SÍNCRONO — o /v1/enhance retorna a URL.
     *  Reserva 1 do bucket 'image'; estorna se falhar. Ideal p/ foto de celular antes do model sheet. */
    public function enhanceBase(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url || ! StudioController::isOwnMediaUrl($c->base_url)) {
            return response()->json(['ok' => false, 'error' => 'Gere ou envie a imagem-base primeiro.'], 422);
        }
        $op = (string) $r->input('op');
        if (! in_array($op, ['remove_bg', 'upscale'], true)) {
            return response()->json(['ok' => false, 'error' => 'operação inválida'], 400);
        }
        $t = $this->tenant($r);
        $weight = $this->usage->weightFor('image');
        // Foto de celular (3-4k px) faz os pós-processadores falharem. Reduz a base pra ≤1536px —
        // é referência i2i, 1536 é de sobra (e mais rápido).
        $src = StudioController::downscaledImageUrl($c->base_url, 1536);
        $url = null;
        $cost = null;

        if ($op === 'remove_bg') {
            // 🎭 Tirar fundo via i2i nano-banana-2 (o recraft/remove-background está em outage no KIE;
            // o nano-banana-2 é confiável e troca o fundo mantendo a pessoa). Não é PNG transparente,
            // é fundo cinza liso — que é exatamente o que a âncora do model sheet precisa.
            $cost = $this->modelCost($r, self::MODEL_I2I); // engine usa o i2i default (nano-banana-2 = img-referencia)
            if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
            }
            $url = $this->engine()->timeout(300)->post('/v1/image', [
                'prompt' => 'Keep this EXACT person completely unchanged — same face, body, hair, skin, '
                    .'clothing and pose. ONLY replace the entire background with a plain seamless solid '
                    .'light-gray studio backdrop. Remove ALL scenery, furniture, decorations, lights and '
                    .'people behind. Even studio lighting, sharp focus, no cast shadows.',
                'aspect' => '3:4',
                'style' => $c->style,
                'imageUrls' => [$src], // engine default i2i = nano-banana-2 (confiável)
                'anchorIdentity' => true,
            ])->json('url');
        } else { // upscale → topaz (edit-upscale-pro); o recraft crisp-upscale também está em outage
            $gm = GenModel::resolveSelectable('edit-upscale-pro', 'edit', $t->plan);
            if (! $gm) {
                return response()->json(['ok' => false, 'error' => 'melhoria indisponível no seu plano'], 404);
            }
            $cost = $gm->cost_credits;
            if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
            }
            $url = $this->engine()->timeout(300)->post('/v1/enhance', GenPayload::enhancePayload($gm, $src))->json('url');
        }

        if (! $url) {
            $this->usage->refund($t, 'image', $weight, $cost);

            return response()->json(['ok' => false, 'error' => 'o processamento não retornou imagem — tente de novo'], 502);
        }
        $c->forceFill(['base_url' => $url])->save();

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/from-image (multipart: file, lang?) → EXTRAIR de uma imagem: a
     *  imagem enviada vira a BASE do personagem, a IA (vision KIE) DESCREVE a imagem e gera o
     *  character lock + bíblia completos, e dispara o MODEL SHEET (as pranchas i2i ancoradas na
     *  imagem). "envio a imagem e a IA gera o character lock e o model sheet". */
    public function fromImage(Request $r, string $id, ModelSheetService $sheets): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        $r->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240']); // AUD-006/AUD-024
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'Envie uma imagem do personagem.'], 400);
        }
        $lang = (string) $r->input('lang', 'pt-BR');

        // 1) persiste a imagem como BASE (âncora i2i do model sheet e das cenas). Reusa o mesmo
        //    streaming pro S3 do upload da Mídia (não carrega o arquivo inteiro na memória).
        $ext = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        $baseUrl = StudioController::storeUploadedFile($file, $ext, 'image');

        // 2) data URL base64 pra VISION (a KIE não baixa do nosso S3 — a imagem vai inline).
        //    ⚠️ REDUZIR antes: o engine tem teto de 1MB por request (AUD-011). Foto de celular
        //    (2-3MB) → base64 ~4MB → estourava o limite → 400 → personagem SEM lock em silêncio.
        //    1024px de lado maior é de sobra pra vision e fica ~150KB. Fallback: base64 cru.
        $dataUrl = StudioController::visionDataUrl($file->getRealPath())
            ?? 'data:'.($file->getMimeType() ?: 'image/jpeg').';base64,'.base64_encode((string) file_get_contents($file->getRealPath()));

        // 3) BÍBLIA/LOCK a partir da IMAGEM (vision), SÍNCRONO (informa os prompts do model sheet).
        //    Best-effort: se a vision falhar, segue com pranchas genéricas (identidade vem da imagem i2i).
        $bible = null;
        $lock = null;
        // BÍBLIA (vision) cobrada por MODELO — mesmo contrato do generateSheet (estorno em falha).
        if (! is_string($r->input('textModel')) || trim((string) $r->input('textModel')) === '') {
            $r->merge(['textModel' => (string) ($c->text_model ?: '')]); // reusa o modelo SALVO na criação
        }
        $tm = $this->textModelFor($r, $this->tenant($r)->plan);
        if ($tm && $tm->slug !== $c->text_model) {
            $c->forceFill(['text_model' => $tm->slug])->save(); // persiste o usado (criação legada/troca)
        }
        $bibleCharged = $this->usage->tryConsume($this->tenant($r), 'text', 1, $tm?->cost_credits);
        try {
            $resp = $bibleCharged ? $this->engine()->timeout(150)->post('/v1/characterbible', [
                'name' => $c->name, 'style' => $c->style, 'lang' => $lang, 'imageDataUrl' => $dataUrl,
                'gen_lines' => $this->textGenLines($tm),
            ]) : null;
            if ($resp && $resp->successful() && is_array($b = $resp->json())) {
                $this->ajustaSeReserva($this->tenant($r), $tm, $resp);
                $lock = (string) ($b['lock'] ?? '');
                $bible = array_intersect_key($b, array_flip(['subject', 'palette', 'traits', 'accessories', 'expressions']));
            } elseif ($bibleCharged) {
                $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
            }
        } catch (\Throwable $e) {
            if ($bibleCharged) {
                $this->usage->refund($this->tenant($r), 'text', 1, $tm?->cost_credits);
            }
            // segue sem bíblia
        }

        // 4) MODEL SHEET determinístico (shots i2i ancorados na imagem enviada + composição). A imagem
        //    enviada É a base; o lock (vision) é a fonte da identidade. Setamos no objeto p/ o builder
        //    de grupos ler base_url/lock/bible antes de persistir.
        $c->base_url = $baseUrl;
        if ($lock !== null && trim($lock) !== '') {
            $c->lock = $lock;
            // NÃO copiar o lock pra description: a identidade vive em $c->lock; jogar o lock inteiro na
            // description fazia o "Editar" mostrar o CHARACTER LOCK repetido (feedback Luciano 2026-07-13).
        }
        if ($bible !== null) {
            $c->bible = $bible;
        }
        $groups = $sheets->groups($c, $bible, $lang);
        $n = $sheets->shotCount($groups);
        $perCost = $this->modelCost($r, self::MODEL_I2I_SHEET);
        $perWeight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $perWeight * $n, $perCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        // 5) grava base + lock/bíblia + marca gerando; UM job orquestrador gera os shots e compõe.
        $upd = ['base_url' => $baseUrl, 'status' => 'sheet', 'sheet_pending' => $n, 'sheets' => []];
        if ($lock !== null && trim($lock) !== '') {
            $upd['lock'] = $lock; // identidade só no lock — description NÃO recebe o lock (evita repetição no Editar)
        }
        if ($bible !== null) {
            $upd['bible'] = $bible;
        }
        if (! $this->claim($c, $upd)) {
            $this->usage->refund($this->tenant($r), 'image', $perWeight * $n, $perCost);

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }
        GenerateModelSheetJob::dispatch($c->id, $this->tenant($r)->id, $bible, $lang, null, $perWeight, $perCost);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/panel { kind, tweak?, lang? } → REGENERA uma única prancha do model
     *  sheet (i2i da base), substituindo-a no lugar (upsert-by-kind no job). NÃO mexe nas outras
     *  pranchas. tweak = ajuste livre do usuário ("deixe as expressões mais alegres"). "editar cada
     *  model sheet". Reserva 1 imagem. */
    public function regeneratePanel(Request $r, string $id, ModelSheetService $sheets): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem-base do personagem primeiro.'], 422);
        }
        // Pranchas LEGADO (geradas pelo sistema antigo) regeneram como o grupo novo equivalente —
        // o job substitui a folha antiga pelo kind novo (turnaround→angles, expressions→head).
        $kind = (string) $r->input('kind');
        $kind = ['turnaround' => 'angles', 'expressions' => 'head'][$kind] ?? $kind;
        if (! in_array($kind, ModelSheetService::REGEN_KINDS, true)) {
            return response()->json(['ok' => false, 'error' => 'Folha inválida.'], 422);
        }
        $lang = (string) $r->input('lang', 'pt-BR');
        $tweak = mb_substr(trim((string) $r->input('tweak', '')), 0, 400); // ajuste livre (botão Editar)
        // Reconstrói SÓ o grupo pedido (mantém lock/paleta travados). O job faz upsert-by-kind:
        // a folha regenerada substitui a antiga do mesmo kind, sem tocar nas outras.
        $groups = $sheets->groups($c, is_array($c->bible) ? $c->bible : null, $lang, $kind, $tweak);
        if ($groups === []) {
            return response()->json(['ok' => false, 'error' => 'Esta folha não se aplica a este personagem (sem roupa/acessórios na bíblia).'], 422);
        }
        $n = $sheets->shotCount($groups); // paleta = 0 shots (só recompõe swatches)
        $perCost = $this->modelCost($r, self::MODEL_I2I_SHEET);
        $perWeight = $this->usage->weightFor('image');
        if ($n > 0 && ! $this->usage->tryConsume($this->tenant($r), 'image', $perWeight * $n, $perCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        // status='sheet' + sheet_pending marcador (≥1) SEM zerar sheets[] (as outras folhas ficam).
        if (! $this->claim($c, ['status' => 'sheet', 'sheet_pending' => max(1, $n)])) {
            if ($n > 0) {
                $this->usage->refund($this->tenant($r), 'image', $perWeight * $n, $perCost);
            }

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }
        GenerateModelSheetJob::dispatch($c->id, $this->tenant($r)->id, is_array($c->bible) ? $c->bible : null, $lang, $kind, $perWeight, $perCost, $tweak);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/panel-upload (multipart: kind + file OU files[]) → usa imagem(ns)
     *  gerada(s) FORA do Reachyn como prancha do model sheet. Sem IA, sem cota.
     *
     *  - `file` (1): sobe a prancha pronta (folha já composta) e substitui o kind.
     *  - `files[]` (2+): sobe as VISTAS individuais, ordena (turnaround reconhece 0°/45°/… no
     *    nome do arquivo), COMPÕE a folha no ffmpeg-service com o template fixo e salva o resultado.
     *
     *  Kinds de INSTÂNCIA ÚNICA (angles/head/poses/accessories/palette/shots/lighting) SUBSTITUEM
     *  a prancha existente; kinds livres (ex 'outfit') sempre entram como prancha NOVA. */
    public function panelUpload(Request $r, string $id, ModelSheetService $sheetsSvc): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        $kind = trim((string) $r->input('kind'));
        if ($kind === '') {
            return response()->json(['ok' => false, 'error' => 'Informe a prancha (kind).'], 422);
        }
        // Retrocompat: turnaround/expressions → kind canônico do v3.
        if ($kind === 'turnaround') {
            $kind = 'angles';
        }
        if ($kind === 'expressions') {
            $kind = 'head';
        }

        $r->validate([
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:10240',
            'files' => 'nullable|array|max:16',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $multi = $r->file('files');
        if (! is_array($multi)) {
            $multi = [];
        }
        $multi = array_values(array_filter($multi, fn ($f) => $f instanceof UploadedFile && $f->isValid()));
        $single = $r->file('file');

        if ($multi === [] && ! ($single instanceof UploadedFile && $single->isValid())) {
            return response()->json(['ok' => false, 'error' => 'Envie 1 prancha (file) ou várias vistas (files[]).'], 422);
        }

        if (count($multi) >= 2) {
            // N vistas → compose no template do kind (turnaround de 10, head, poses…).
            $ordered = $sheetsSvc->orderShotUploads($multi, $kind);
            $urls = [];
            foreach ($ordered as $file) {
                $urls[] = StudioController::storeUploadedFile(
                    $file,
                    strtolower((string) ($file->guessExtension() ?: 'jpg')),
                    'image',
                );
            }
            $lang = (string) $r->input('lang', 'pt-BR');
            $url = $sheetsSvc->composeFromUrls($c, $kind, $urls, $lang);
            if ($url === null || $url === '') {
                return response()->json(['ok' => false, 'error' => 'Não foi possível montar a prancha com as imagens enviadas.'], 502);
            }
        } else {
            $file = $multi[0] ?? $single;
            $url = StudioController::storeUploadedFile(
                $file,
                strtolower((string) ($file->guessExtension() ?: 'jpg')),
                'image',
            );
        }

        $sheets = is_array($c->sheets) ? array_values($c->sheets) : [];
        $singleton = in_array($kind, ModelSheetService::KINDS, true) || in_array($kind, ['shots', 'lighting'], true);
        $replaced = false;
        if ($singleton) {
            foreach ($sheets as &$s) {
                if (($s['kind'] ?? '') === $kind) {
                    $s['url'] = $url;
                    $replaced = true;
                    break;
                }
            }
            unset($s);
        }
        if (! $replaced) {
            $sheets[] = ['kind' => $kind, 'url' => $url];
        }
        // Ordem de EXIBIÇÃO: canônicos primeiro (ordem de KINDS + shots/lighting), extras mantêm a
        // ordem relativa em que entraram (usort é estável desde PHP 8 — não embaralha os figurinos).
        $rank = array_flip([...ModelSheetService::KINDS, 'shots', 'lighting']);
        usort($sheets, fn ($a, $b) => ($rank[$a['kind'] ?? ''] ?? 99) <=> ($rank[$b['kind'] ?? ''] ?? 99));
        $primary = null;
        foreach ($sheets as $s) {
            if (($s['kind'] ?? '') === 'angles') {
                $primary = $s['url'] ?? null;
                break;
            }
        }
        $c->update(['sheets' => array_values($sheets), 'sheet_url' => $primary ?? ($sheets[0]['url'] ?? $c->sheet_url)]);

        return response()->json(['ok' => true, 'character' => $c->fresh(), 'composed' => count($multi) >= 2]);
    }

    /** POST /api/characters/{id}/outfit { description, tweak?, replaceId?, lang? } → prancha MANUAL de
     *  FIGURINO: o usuário DESCREVE uma roupa e a IA veste o personagem (i2i da base, mantém a
     *  identidade/rosto/corpo) numa folha com as peças separadas (flat-lay) + o personagem vestido
     *  (frente/costas). VÁRIOS figurinos coexistem (cada um com id próprio); replaceId regenera um
     *  existente no lugar. NÃO altera a roupa-base nem as cenas. Reserva 1 imagem. */
    public function addOutfit(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($busy = $this->busy($c)) {
            return $busy;
        }
        if (! $c->base_url) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem-base do personagem primeiro.'], 422);
        }
        $desc = mb_substr(trim((string) $r->input('description', '')), 0, 400);
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva a roupa/figurino a adicionar.'], 422);
        }
        $tweak = mb_substr(trim((string) $r->input('tweak', '')), 0, 300);
        $lang = (string) $r->input('lang', 'pt-BR');
        // slot: regenera um figurino existente (replaceId no formato validado) ou cria um id novo.
        $replaceId = (string) $r->input('replaceId', '');
        $panelId = preg_match('/^outfit-[a-z0-9]{4,20}$/', $replaceId)
            ? $replaceId
            : 'outfit-'.substr(md5($desc.uniqid('', true)), 0, 12);
        $p = $this->outfitPrompt(is_array($c->bible) ? $c->bible : null, $lang, $desc, $tweak, $c->lock, (string) $c->name);
        $perCost = $this->modelCost($r, self::MODEL_I2I);
        $perWeight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $perWeight, $perCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        if (! $this->claim($c, ['status' => 'sheet', 'sheet_pending' => 1])) {
            $this->usage->refund($this->tenant($r), 'image', $perWeight, $perCost);

            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }
        GenerateCharacterJob::dispatch($c->id, $this->tenant($r)->id, 'sheet-panel',
            ['prompt' => $p['prompt'], 'aspect' => $p['aspect'], 'style' => $c->style, 'imageUrls' => [$c->base_url], 'anchorIdentity' => true],
            $perWeight, $perCost, 'outfit', $panelId, $desc);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** Prompt da prancha de FIGURINO manual (peças separadas + personagem vestido), i2i da base. Trava a
     *  identidade FORTE: além da imagem i2i, injeta o CHARACTER LOCK textual (quem é o personagem) + o
     *  nome + a paleta — sem isso o modelo recriava um personagem genérico ("gerava outro cachorro").
     *  Também mantém o tipo de corpo (não antropomorfiza um quadrúpede). Só a ROUPA muda pra $desc. */
    private function outfitPrompt(?array $bible, string $lang, string $desc, string $tweak, ?string $lock = null, string $name = ''): array
    {
        $pt = $lang !== 'en-US';
        $L = fn (string $ptTxt, string $enTxt) => $pt ? $ptTxt : $enTxt;
        $langNote = $pt
            ? ' Every label and section title in the image must be written in Brazilian Portuguese, using ONLY the exact quoted text given, with no extra words.'
            : ' Every label and section title in the image must be written in English, using ONLY the exact quoted text given, with no extra words.';
        $palLabels = [];
        foreach ((array) ($bible['palette'] ?? []) as $sw) {
            $lbl = trim((string) ($sw['label'] ?? ''));
            $hex = trim((string) ($sw['hex'] ?? ''));
            if ($lbl !== '' || $hex !== '') {
                $palLabels[] = trim($lbl.' '.$hex);
            }
        }
        $palTxt = $palLabels ? ' (colors: '.implode(', ', array_slice($palLabels, 0, 9)).')' : '';

        // IDENTIDADE FORTE — a imagem i2i sozinha não bastava; injeta o lock textual + nome + paleta e
        // proíbe o modelo de trocar por outro personagem, e de antropomorfizar um animal quadrúpede.
        $who = trim($name) !== '' ? ' named "'.trim($name).'"' : '';
        $identity = 'Reproduce the EXACT SAME character shown in the reference image'.$who.' — same species, '
            .'face, head shape, body type, proportions, art style and colors'.$palTxt.'. ';
        if ($lock !== null && trim($lock) !== '') {
            $lockTxt = trim((string) preg_replace('/\s*[\r\n]+\s*/', '; ', trim($lock)));
            $identity .= 'The character is defined by this locked description; keep every trait of it: '.$lockTxt.'. ';
        }
        $identity .= 'Do NOT invent, redesign or substitute a different or generic character. Keep the '
            .'character\'s natural body type and posture from the reference — if it is a four-legged animal keep '
            .'it four-legged; if it is a human/humanoid keep it human/humanoid. Do NOT turn it into a different species or animal. ONLY the clothing changes to the '
            .'outfit below. ';

        $prompt = $identity
            .'One clean image on a plain light neutral studio background, laid out like a printed COSTUME / '
            .'WARDROBE reference sheet, ultra-detailed. The outfit to dress this same character in: '.$desc.'. '
            .'Two clearly separated sections, each with a title written EXACTLY as given: '
            .'(1) "'.$L('PEÇAS DO FIGURINO', 'OUTFIT PIECES').'" — every clothing item and accessory of this '
            .'outfit laid out separately as a clean flat-lay (like a catalog), each item in its own evenly '
            .'spaced slot with a SINGLE short label directly beneath it. Give EXACTLY one label per item; do '
            .'NOT repeat, duplicate or omit any label, and never place a label under the wrong item. '
            .'(2) "'.$L('PERSONAGEM VESTIDO', 'DRESSED CHARACTER').'" — the SAME character wearing the complete '
            .'outfit, shown full-body from the FRONT and from the BACK, the entire body inside the frame with '
            .'margin (do not crop). Soft even lighting.'.$langNote;
        if (($t = trim($tweak)) !== '') {
            $prompt .= ' Requested adjustments: '.$t.'.';
        }

        return ['aspect' => '4:3', 'prompt' => $prompt];
    }

    /** DELETE /api/characters/{id}/panel { index } → remove UMA prancha do model sheet (por índice no
     *  array sheets[]). Se a removida alimentava o sheet_url primário, reatribui pra 1ª restante.
     *  "remover cada model sheet". Não gasta cota; não apaga a mídia do S3. */
    public function deletePanel(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        $idx = (int) $r->input('index', -1);
        // Espelha o fallback do front: personagem LEGADO sem sheets[] mas com sheet_url = 1 prancha
        // virtual (turnaround). Assim "Remover" na única prancha de um personagem antigo também funciona.
        $sheets = is_array($c->sheets) && $c->sheets !== []
            ? array_values($c->sheets)
            : ($c->sheet_url ? [['kind' => 'turnaround', 'url' => $c->sheet_url]] : []);
        if ($idx < 0 || $idx >= count($sheets)) {
            return response()->json(['ok' => false, 'error' => 'Prancha inexistente.'], 422);
        }
        array_splice($sheets, $idx, 1);
        // sheet_url = a prancha PRIMÁRIA exibida (angles/turnaround). Se sumiu, aponta pra 1ª restante
        // (ou null). Cosmético — a âncora i2i das cenas é base_url, não sheet_url.
        $primary = null;
        foreach ($sheets as $s) {
            if (in_array($s['kind'] ?? '', ['angles', 'turnaround'], true)) {
                $primary = $s['url'] ?? null;
                break;
            }
        }
        $c->update(['sheets' => $sheets, 'sheet_url' => $primary ?? ($sheets[0]['url'] ?? null)]);

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** POST /api/characters/{id}/reset → destrava um personagem preso em "gerando" (status != '' com
     *  job órfão/perdido — ex: worker reiniciou no meio). Zera status + sheet_pending. Não estorna
     *  cota (o job, se ainda existir, estorna a própria parte ao falhar). Idempotente. */
    public function resetStatus(Request $r, string $id): JsonResponse
    {
        $c = $this->character($r, $id);
        if ($c->status !== '' || (int) $c->sheet_pending !== 0) {
            $c->update(['status' => '', 'sheet_pending' => 0]);
        }

        return response()->json(['ok' => true, 'character' => $c->fresh()]);
    }

    /** Auto-heal de status "preso": personagem marcado como gerando (status != '') mas sem ser tocado
     *  há mais que o pior caso de uma geração volta a pronto (job morreu sem limpar → sheet_pending
     *  nunca zerou). Roda na LEITURA (index/show) e no busy(): o card deixa de mostrar "gerando" eterno
     *  — era a causa do "diz que está gerando antes de clicar". Cada prancha renova o updated_at ao
     *  concluir/falhar, então uma geração saudável NUNCA fica parada 30min; só um job órfão fica. 30 min
     *  = folga sobre 4 pranchas em série num worker único (até ~7min/job) + espera de fila. Pra destravar
     *  na hora, o usuário tem o botão ⏹️ Cancelar (resetStatus). */
    private function unstick(Character $c): Character
    {
        if ($c->status !== '' && $c->updated_at && $c->updated_at->lt(now()->subMinutes(30))) {
            $c->update(['status' => '', 'sheet_pending' => 0]);
        }

        return $c;
    }

    /** Bloqueia uma 2ª geração enquanto a anterior corre (status != ''). 409 amigável.
     *  Pré-check RÁPIDO (não atômico) — só evita o trabalho óbvio (bíblia/validação) antes de
     *  reservar cota; a garantia real contra a corrida é o claim() logo antes de disparar os jobs. */
    private function busy(Character $c): ?JsonResponse
    {
        $this->unstick($c); // destrava status preso (job órfão > 15min) antes de bloquear uma nova geração
        if ($c->status !== '') {
            return response()->json(['ok' => false, 'error' => 'Já existe uma geração em andamento para este personagem.'], 409);
        }

        return null;
    }

    /** Transição ATÔMICA de status '' → $extra['status'] (UPDATE ... WHERE status = ''). Fecha a
     *  race condition do busy(): duas requisições quase simultâneas (duplo-clique, retry após 429)
     *  passavam as duas pelo busy() antes de qualquer uma escrever o novo status, disparando jobs
     *  em dobro e resetando sheet_pending/sheets uma da outra no meio do caminho. Só a requisição
     *  que vence o UPDATE (1 linha afetada) segue pra disparar o job; a outra perde a corrida —
     *  o chamador deve estornar a cota já reservada e responder 409. */
    private function claim(Character $c, array $extra): bool
    {
        return Character::where('id', $c->id)->where('status', '')->update($extra) === 1;
    }

    /** Custo em créditos (gen_models.cost_credits) de um modelo de imagem; null = não catalogado
     *  (cai no custo fixo por tipo). Usado nas operações i2i (sheet/edit), de modelo fixo. */
    private function modelCost(Request $r, string $slug): ?int
    {
        return GenModel::resolveSelectable($slug, 'image', $this->tenant($r)->plan)?->cost_credits;
    }

    /** Resolve o modelo de imagem T2I ESCOLHIDO pelo cliente (request `model`, slug), validando
     *  kind=image + subtype=text_to_image + plano. Slug ausente/inválido → cai no `$fallbackSlug`
     *  (a base sempre gera; imagem tem default seguro). Retorna o GenModel (provider/pmid/custo). */
    private function imageModel(Request $r, string $fallbackSlug): ?GenModel
    {
        $plan = $this->tenant($r)->plan;
        $chosen = $r->input('model');
        if (is_string($chosen) && trim($chosen) !== '') {
            $gm = GenModel::resolveSelectable($chosen, 'image', $plan);
            if ($gm && $gm->subtype === 'text_to_image') {
                return $gm;
            }
        }

        return GenModel::resolveSelectable($fallbackSlug, 'image', $plan);
    }

    /** RESERVE-THEN-CONSUME (AUD-002) do bucket 'image', cobrando o custo do MODELO (cost_credits).
     *  Retorna [weight, costCredits] reservado, ou null se estourou o gate do plano. Saldo
     *  insuficiente PROPAGA InsufficientCreditsException (402) do tryConsume. */
    private function reserve(Request $r, ?int $costCredits = null): ?array
    {
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($this->tenant($r), 'image', $weight, $costCredits)) {
            return null;
        }

        return [$weight, $costCredits];
    }
}
