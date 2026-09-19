<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\DubVideo;
use App\Jobs\GenerateCarouselPlanJob;
use App\Jobs\GenerateCarouselSlideJob;
use App\Jobs\GenerateCarouselVideoJob;
use App\Jobs\GenerateImageJob;
use App\Jobs\GenerateStoryClipJob;
use App\Jobs\GenerateStoryImageJob;
use App\Jobs\GenerateStoryJob;
use App\Jobs\GenerateVideoJob;
use App\Jobs\GenerateVoxSceneJob;
use App\Jobs\PublishDraftJob;
use App\Models\Character;
use App\Models\Draft;
use App\Models\GenModel;
use App\Models\Profile;
use App\Models\Prompt;
use App\Models\Scenario;
use App\Models\Tenant;
use App\Services\Concerns\BuildsShortMontage;
use App\Services\PublishService;
use App\Services\UsageService;
use App\Support\Audit;
use App\Support\Composer;
use App\Support\EngineClient;
use App\Support\FormatVariant;
use App\Support\GenPayload;
use App\Support\IdentityLock;
use App\Support\MotionPrompt;
use App\Support\Networks;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Porta do dashboard antigo (reachyn-os): fluxo pesquisar→conteúdo→mídia→aprovar→publicar
 * em torno de um "rascunho" (Draft). Substitui os /api/studio/* do Next antigo, agora
 * autenticado (Sanctum) + tenant-scoped, chamando o engine Go.
 */
class StudioController extends Controller implements HasMiddleware
{
    use BuildsShortMontage; // montagem /v1/storyvideo compartilhada com o Estúdio de Animação
    use Concerns\ResolvesTextModel;

    // RBK-002: gate de assinatura só nos métodos que GASTAM crédito de IA / publicam.
    // Leitura/config (show, *Update, search-keys/config, mediaDelete, upload, publishStatus) ficam livres.
    public static function middleware(): array
    {
        return [
            // Freemium: pesquisa normal (+ resumo embutido) liberada também no TRIAL.
            // A cota diária do trial é aplicada dentro de research() (tryConsumeDaily).
            new Middleware('trial-or-subscribed', only: ['research']),
            // Gerar/publicar e derivados que gastam IA: exigem plano pago (trial NÃO libera).
            // deepResearch fica aqui: pesquisa profunda continua Studio-only (check interno premium).
            // Histórias (stickman): agora em TODOS os planos pagos — cobrado por CRÉDITOS (F4), não
            // mais exclusivo do Studio. O custo está nos débitos por cena (image/video/short).
            // GERAÇÃO (créditos) — exige item de plano. 'submit' NÃO está aqui: publicar é produto à parte.
            new Middleware('subscribed', only: [
                'deepResearch', 'imagePrompt', 'mediaPrompts', 'searchTest',
                'text', 'media', 'veo', 'thumbnail', 'clip', 'dub', 'voiceClone',
                'story', 'storyScenes', 'storyReference', 'storyReferenceUrl', 'storyCast', 'storyCastGenerate',
                'storyClearMedia', 'storyAudio', 'storyImage', 'storyEditImage', 'storyClip', 'storyVideo', 'storyTexts',
                'storyExport', 'storyFinalUpload',
                // Fusão FoxAssets (2026-08-01): inpaint/refino local gastam crédito de imagem
                // igual a `media` — mesmo gate. `frameToBase` fica FORA (não consome crédito).
                'inpaint', 'refineLocal',
                // 🎞️ Motion: a tela estática sai pelo `media` (já gated); `motionClip` é o clipe.
                'motionClip',
            ]),
            // PUBLICAÇÃO (R$45/conta) — publicar exige o item de publicação, não o plano de geração.
            // Conteúdo pode ser gerado (plano) OU subido pronto (/studio/upload, sem gate).
            new Middleware('publishing', only: ['submit']),
        ];
    }

    public function __construct(private UsageService $usage) {}

    private function engine(): PendingRequest
    {
        // X-Admin-Token em TODAS as chamadas /v1/* (o engine passou a exigir o token
        // compartilhado nas rotas de geração, não só /v1/admin). Defesa em profundidade.
        return EngineClient::make(600);
    }

    private function tenant(Request $r)
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t;
    }

    /** Modelo de imagem T2I escolhido pelo cliente (request `model`, slug), validando kind=image +
     *  subtype=text_to_image + plano. Slug ausente/inválido → fallback `$fallback` (a geração nunca
     *  trava; imagem tem default seguro). i2i não tem seletor na Fase 2 (continua nano-banana). */
    private function imageT2IModel(Request $r, ?string $plan, string $fallback, ?string &$trocado = null): ?GenModel
    {
        $chosen = $r->input('model');
        if (is_string($chosen) && trim($chosen) !== '') {
            $gm = GenModel::resolveSelectable($chosen, 'image', $plan);
            if ($gm && $gm->subtype === 'text_to_image') {
                return $gm;
            }
            // Escolha explícita que não resolve (slug inválido, modelo desligado no Filament ou
            // acima do plano) CAI NO PADRÃO de propósito — plano menor não deve travar a geração
            // (CliBridgeImageModelTest::test_plano_menor_cai_no_t2i_padrao_sem_erro). O custo sai
            // do modelo que REALMENTE rodou, então ninguém é cobrado a mais. O que faltava era
            // avisar: o usuário escolhia um motor e recebia outro sem nada na tela.
            $trocado = trim($chosen);
        }

        return GenModel::resolveSelectable($fallback, 'image', $plan);
    }

    /** Persona (estilo) a aplicar na geração, resolvida em TEXTO — o engine recebe o conteúdo
     *  pronto, não um slug: quem guarda o catálogo é o console, onde o cliente edita e cria as
     *  dele na aba Prompts.
     *
     *  Duas origens, nessa ordem: `personaId` (persona salva — o global scope BelongsToTenant
     *  garante que só resolve prompt do próprio tenant, e o kind tem de bater com o alvo) e
     *  `persona` (texto digitado na hora, quando o cliente escolhe "Escrever a minha").
     *  Nenhuma das duas → string vazia = sem persona, comportamento de antes. */
    private function resolvePersona(Request $r, string $kind): string
    {
        if ($id = $r->input('personaId')) {
            $p = Prompt::where('tenant_id', $r->user()->tenant_id)->personas($kind)->find($id);

            return (string) ($p?->content ?? '');
        }

        return mb_substr(trim((string) $r->input('persona', '')), 0, 4000);
    }

    /** Refinadores DISPONÍVEIS agora (GET /api/studio/refiners) — lê o /health do bridge e
     *  devolve só os adapters realmente presentes no host. Assim a UI nunca oferece um motor
     *  que não responde (o cursor/agy podem sumir num reinstall de CLI).
     *
     *  Nomenclatura: desde 2026-07-20 o select mostra o nome REAL da CLI (ordem do Luciano,
     *  já que só ele usa o Reachyn hoje). ⚠️ Contraria a guideline #6 (white-label) — se
     *  entrar cliente, voltar o mapa pra 'Studio B/C/D' aqui e nos display_name do seeder.
     *  Bridge fora do ar ou sem token → lista vazia → o select some da UI e a geração
     *  segue sem refino. */
    public function refiners(Request $r): JsonResponse
    {
        $this->tenant($r); // exige tenant (rota autenticada)

        $rotulos = ['mmx' => 'mmx', 'cursor' => 'cursor', 'agy' => 'agy'];
        $url = (string) config('services.cli_bridge.url', '');
        if ($url === '') {
            return response()->json(['ok' => true, 'refiners' => []]);
        }

        $out = Cache::remember('studio:refiners', 300, function () use ($url, $rotulos) {
            try {
                $resp = Http::timeout(5)->get(rtrim($url, '/').'/health');
                if (! $resp->successful()) {
                    return [];
                }
                $provs = $resp->json('providers') ?? [];
                $list = [];
                foreach ($rotulos as $id => $label) {
                    if (($provs[$id]['present'] ?? false) && ($provs[$id]['can_enhance'] ?? false)) {
                        $list[] = ['id' => $id, 'label' => $label];
                    }
                }

                return $list;
            } catch (\Throwable $e) {
                return []; // bridge fora do ar não pode derrubar a tela de geração
            }
        });

        return response()->json(['ok' => true, 'refiners' => $out]);
    }

    /** CLI do host que REESCREVE o pedido aplicando a persona antes de gerar (cli-bridge).
     *  Allowlist fechada — o valor vai virar nome de processo no host, então nada de texto
     *  livre aqui. Vazio/inválido = sem refino (a persona é só apensada ao prompt). */
    private function resolveRefiner(Request $r): string
    {
        $v = (string) $r->input('refiner', '');

        return in_array($v, ['mmx', 'cursor', 'agy'], true) ? $v : '';
    }

    /** Monta o gen_lines.video do payload a partir do modelo de vídeo escolhido. Provider nativo →
     *  só o primary (engine resolveVideoModel). KIE → provider='kie' + o SPEC de input (capabilities.kie,
     *  schema-driven); o engine trata o fallback internamente se o agregador falhar.
     *  O spec NUNCA é exposto ao cliente — vem do catálogo e só trafega console→engine. */
    private function videoGenLine(GenModel $vm, ?array $quality = null): array
    {
        // Fonte única: GenPayload (unificação 2026-07-16 — era quadruplicado com Filme/Animação).
        return GenPayload::videoGenLine($vm, $quality);
    }

    // videoQuality() / audioModel() / ttsParams() vivem no trait BuildsShortMontage —
    // compartilhados com o Estúdio de Animação (modos Histórias/Quadrinhos).

    /** Persona EFETIVA de texto (Sprint A Hollywood): 🗣️ Voz da Marca do tenant (sempre, se
     *  definida) + o 🎬 Roteirista escolhido (request `persona`). Vira a craft-intro do redator
     *  no engine (/v1/text, /v1/summarize) — o contrato JSON/limites ficam intactos lá. */
    public static function textPersona(Request $r, $t): string
    {
        $parts = [];
        if (($bv = trim((string) ($t->brand_voice ?? ''))) !== '') {
            $parts[] = 'VOZ DA MARCA (aplique SEMPRE — tom, vocabulário e restrições da marca): '.mb_substr($bv, 0, 1200);
        }
        if (($p = trim((string) $r->input('persona'))) !== '') {
            $parts[] = mb_substr($p, 0, 2000);
        }
        // RITMO (S2 — composição de eixos): compõe com a Voz da Marca + roteirista/diretor sem
        // substituí-los. Afeta densidade de voiceover e o pacing dos beats. Vazio = normal.
        $paces = [
            'contemplativo' => 'PACING — contemplative: let scenes breathe, fewer words per beat, lingering moments, calm rhythm; favor mood and silence over information density.',
            'frenetico' => 'PACING — frenetic: fast, punchy, high-energy; short snappy voiceover, quick beat-to-beat momentum, no lingering; every second earns attention.',
        ];
        if (($pc = trim((string) $r->input('pace'))) !== '' && isset($paces[$pc])) {
            $parts[] = $paces[$pc];
        }

        return implode("\n\n", $parts);
    }

    /** POST /api/studio/music { draftId, prompt, lyrics?, instrumental? } → gera UMA faixa (MiniMax;
     *  music-2.6-free é grátis na API) e anexa à galeria do rascunho como áudio (kind=audio, a galeria
     *  renderiza com <audio controls>). Assíncrono (worker → engine /v1/music). Cobrança PROVISÓRIA no
     *  bucket 'image' (barato; o provedor é grátis) — bucket/pricing próprio de música vem depois. */
    public function studioMusic(Request $r)
    {
        $t = $this->tenant($r);
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'música']);
        $prompt = trim((string) $r->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva a música (estilo, mood, tema).'], 422);
        }
        $instrumental = filter_var($r->input('instrumental', false), FILTER_VALIDATE_BOOLEAN);
        $lyrics = $instrumental ? '' : (string) $r->input('lyrics', '');
        // RESERVE-THEN-CONSUME no bucket 'audio' (separado de imagem); o refund em falha usa o MESMO bucket (Job).
        if (! $this->usage->tryConsume($t, 'audio', 1)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido.'], 402);
        }
        $payload = ['prompt' => $prompt, 'lyrics' => $lyrics, 'instrumental' => $instrumental];
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/music', $payload, 'audio', 1, 'music', [], null, 'audio');

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Música em geração — aparece na galeria em instantes.']);
    }

    /** POST /api/studio/tts { draftId?, text, voice_id?, lang? } → narração AVULSA (TTS), fora do
     *  fluxo de Histórias (ver storyAudio() pra narração por cena de uma história). Sem draftId,
     *  cria um rascunho novo (mesmo padrão de media()). Síncrono — o /v1/tts do engine é rápido. */
    public function tts(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $text = trim((string) $r->input('text'));
        if ($text === '') {
            return response()->json(['ok' => false, 'error' => 'Informe o texto da narração.'], 422);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($text, 0, 80)]);

        // Modelo/qualidade da narração (catálogo kind=audio): tier escolhido = bitrate + preço.
        $am = $this->audioModel($r, $t->plan);
        $aq = $this->videoQuality($am, $r);
        $aCost = ($aq['p'] ?? null) ?? $am?->cost_credits;
        // RESERVE-THEN-CONSUME (AUD-002) — bucket 'audio' (separado de imagem), o mesmo do preview de
        // narração de Histórias (storyAudio) e da música (studioMusic). Custo = tier do catálogo.
        if (! $this->usage->tryConsume($t, 'audio', 1, $aCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $voiceId = (string) ($r->input('voice_id') ?: $t->voice_id);
        $lang = (string) ($r->input('lang') ?: ($t->content_lang ?? 'pt-BR'));
        // ⏱️ timestamps=true → o engine devolve também words [{word,start,end}] + duration: o
        // relógio real da fala, pra sincronizar legenda/corte em montagem EXTERNA à nossa.
        $quer = $r->boolean('timestamps');
        $res = $this->engine()->post('/v1/tts', array_merge(['text' => $text, 'voiceId' => $voiceId, 'lang' => $lang], $quer ? ['timestamps' => true] : [], $this->ttsParams($r, $am, $aq)));
        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            $this->usage->refund($t, 'audio', 1, $aCost); // geração falhou → estorna

            return response()->json(['ok' => false, 'error' => 'geração de narração falhou'], 502);
        }
        $media = $this->attach($d, 'audio', $url, ['style' => 'narracao']);

        $out = ['ok' => true, 'draftId' => $d->id, 'url' => $url, 'media' => $media];
        if ($quer) {
            $out['words'] = (array) $res->json('words');
            $out['duration'] = (float) $res->json('duration');
        }

        return response()->json($out);
    }

    private function draft(Request $r, int|string $id): Draft
    {
        return Draft::where('id', $id)->where('tenant_id', $this->tenant($r)->id)->firstOrFail();
    }

    /** POST /api/studio/research { keyword } → pesquisa + resumo e cria o rascunho. */
    public function research(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }

        // Cota DIÁRIA de pesquisa no TRIAL (anti denial-of-wallet). Assinantes/exempt não
        // passam por aqui — pesquisa faz parte do plano pago.
        $trialResearch = $t->inTrial() && ! $t->hasActivePlan();
        if ($trialResearch && ! $this->usage->tryConsumeDaily($t, 'research', UsageService::RESEARCH_TRIAL_DAILY)) {
            return response()->json([
                'ok' => false,
                'error' => 'research_daily_limit',
                'message' => 'Limite de pesquisas do teste atingido hoje ('.UsageService::RESEARCH_TRIAL_DAILY.'/dia). Assine um plano para liberar.',
            ], 402);
        }

        $sources = (array) $r->input('sources', ['web']);
        if ($sources === []) {
            $sources = ['web'];
        }
        $payload = ['keyword' => $keyword, 'sources' => $sources];
        // BYOK: chaves de pesquisa do próprio tenant (vazias = usa as do sistema)
        $keys = array_filter((array) ($t->search_keys ?? []), fn ($v) => trim((string) $v) !== '');
        if ($keys !== []) {
            $payload['keys'] = $keys;
        }
        // Principal/fallback por função (tenant ou defaults) → engine respeita a ordem.
        $payload['lines'] = $t->searchLines();
        $res = $this->engine()->post('/v1/research', $payload);
        if (! $res->successful()) {
            if ($trialResearch) {
                $this->usage->refundDaily($t, 'research'); // não cobra a cota do trial por falha nossa
            }

            return response()->json(['ok' => false, 'error' => 'pesquisa falhou'], 502);
        }
        $results = $res->json('results') ?? [];
        $answer = (string) $res->json('answer');

        $summary = $answer;
        $brief = '';
        if ($results !== []) {
            // RESUMO cobrado por MODELO (seletor textModel; default Equilibrado). Sem saldo → devolve
            // a pesquisa SEM resumo (o cliente resume depois pelo botão, quando tiver créditos).
            $tm = $this->textModelFor($r, $t->plan);
            if ($this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
                $sum = $this->engine()->post('/v1/summarize', ['keyword' => $keyword, 'sources' => $results, 'persona' => self::textPersona($r, $t), 'gen_lines' => $this->textGenLines($tm)]);
                if ($sum->successful()) {
                    $this->ajustaSeReserva($t, $tm, $sum);
                    $summary = (string) ($sum->json('summary') ?: $answer);
                    $brief = (string) $sum->json('brief');
                } else {
                    $this->usage->refund($t, 'text', 1, $tm?->cost_credits); // falha nossa não cobra
                }
            }
        }

        $research = ['answer' => $answer, 'summary' => $summary, 'brief' => $brief, 'results' => $results];
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword, 'research' => $research]);

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'research' => $research]);
    }

    /** POST /api/studio/resummarize { draftId, persona? } → REGERA o resumo da pesquisa com o
     *  roteirista escolhido (ex.: 📄 Resumidor Executivo), reusando as fontes já coletadas no
     *  rascunho — sem nova pesquisa, sem custo de busca. */
    public function resummarize(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $research = is_array($d->research) ? $d->research : [];
        $results = is_array($research['results'] ?? null) ? $research['results'] : [];
        if ($results === []) {
            return response()->json(['ok' => false, 'error' => 'este rascunho não tem fontes de pesquisa para resumir'], 422);
        }
        // Cobrado por MODELO (seletor textModel; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $sum = $this->engine()->post('/v1/summarize', ['keyword' => $d->keyword, 'sources' => $results, 'persona' => self::textPersona($r, $t), 'gen_lines' => $this->textGenLines($tm)]);
        if (! $sum->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'não foi possível regerar o resumo'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $sum);
        $research['summary'] = (string) ($sum->json('summary') ?: ($research['summary'] ?? ''));
        $research['brief'] = (string) ($sum->json('brief') ?: ($research['brief'] ?? ''));
        $d->update(['research' => $research]);

        return response()->json(['ok' => true, 'research' => $research]);
    }

    /** GET|POST /api/studio/brand-voice → lê/salva a 🗣️ Voz da Marca do tenant (tom, vocabulário,
     *  restrições) — prefixada em TODA geração de texto: posts, resumos, roteiros e locução. */
    public function brandVoice(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if ($r->isMethod('post')) {
            $t->brand_voice = mb_substr(trim((string) $r->input('brand_voice')), 0, 1200);
            $t->save();
            Audit::log('studio.brand_voice', ['tenant_id' => $t->id, 'len' => mb_strlen((string) $t->brand_voice)]);
        }

        return response()->json(['ok' => true, 'brand_voice' => (string) ($t->brand_voice ?? '')]);
    }

    /** GET|POST /api/studio/brand-kit → lê/salva o 🎨 Brand Kit VISUAL do tenant (cor, contraste, logo,
     *  handle) usado pelo compositor de posts. Pareia com a Voz da Marca (brand_voice). Tudo opcional:
     *  campos vazios caem nos defaults (Tenant::brandKit) — o compositor nunca fica sem identidade. */
    public function brandKit(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if ($r->isMethod('post')) {
            $hex = fn ($v) => preg_match('/^#[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', (string) $v) ? (string) $v : null;
            $t->brand_primary = $hex($r->input('brand_primary'));
            $t->brand_ink = $hex($r->input('brand_ink'));
            $t->brand_handle = ($h = mb_substr(trim((string) $r->input('brand_handle')), 0, 40)) !== '' ? $h : null;
            // logo: só aceita URL do NOSSO storage (anti-SSRF); vazio limpa.
            $logo = trim((string) $r->input('brand_logo_url'));
            $t->brand_logo_url = ($logo !== '' && self::isOwnMediaUrl($logo)) ? $logo : null;
            $t->save();
            Audit::log('studio.brand_kit', ['tenant_id' => $t->id]);
        }

        return response()->json(['ok' => true, 'brand_kit' => $t->brandKit()]);
    }

    /** POST /api/studio/compose { draftId, imageUrl, format?, kicker?, titulo|slides[], subtitulo?, cta?, platforms[]? }
     *  Compositor de posts NATIVO: veste uma imagem crua (do nosso storage) com a identidade da marca
     *  (título, logo, CTA) via next/og no serviço web, e anexa o(s) PNG(s) como nova(s) mídia(s) do draft.
     *  `slides[]` (>1 título) gera um CARROSSEL coeso (mesma arte+marca, pager N/M, kicker no 1º slide e
     *  CTA no último). Não gasta crédito de IA (é composição, não geração) — só compute+storage. Token interno. */
    public function compose(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $draftId = $r->input('draftId');
        if (! $draftId) {
            return response()->json(['ok' => false, 'error' => 'Selecione um rascunho.'], 422);
        }
        $d = $this->draft($r, $draftId);

        // Imagem base: precisa ser do NOSSO storage (anti-SSRF, igual media()).
        $imageUrl = (string) $r->input('imageUrl', '');
        if ($imageUrl === '' || ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'Imagem de base inválida.'], 422);
        }

        // Título único OU carrossel (slides[] = 1 título por slide). Cap 10 slides.
        $slides = array_values(array_filter(
            array_map(fn ($s) => trim((string) $s), (array) $r->input('slides', [])),
            fn ($s) => $s !== ''
        ));
        if ($slides === []) {
            $one = trim((string) $r->input('titulo'));
            if ($one === '') {
                return response()->json(['ok' => false, 'error' => 'Informe o título do post.'], 422);
            }
            $slides = [$one];
        }
        $slides = array_slice($slides, 0, 10);
        $total = count($slides);

        $valid = ['feed', 'retrato', 'story', 'paisagem'];
        $format = in_array($r->input('format'), $valid, true) ? (string) $r->input('format') : 'feed';
        $platforms = Networks::only($r->input('platforms', []));

        if (! Composer::disponivel()) {
            return response()->json(['ok' => false, 'error' => 'Compositor indisponível (config).'], 503);
        }
        $brand = $t->brandKit();
        $kicker = mb_substr(trim((string) $r->input('kicker')), 0, 40);
        $subtitulo = mb_substr(trim((string) $r->input('subtitulo')), 0, 300);
        $cta = mb_substr(trim((string) $r->input('cta')), 0, 40);

        $items = [];
        $media = $d->media ?? [];
        foreach ($slides as $i => $titulo) {
            $content = [
                'imageUrl' => $imageUrl,
                'titulo' => mb_substr($titulo, 0, 200),
                // Carrossel: kicker só no 1º slide, CTA só no último, subtítulo só no post único.
                'kicker' => ($total === 1 || $i === 0) ? ($kicker ?: null) : null,
                'subtitulo' => $total === 1 ? ($subtitulo ?: null) : null,
                'cta' => ($total === 1 || $i === $total - 1) ? ($cta ?: null) : null,
            ];
            if ($total > 1) {
                $content['pager'] = ['index' => $i + 1, 'total' => $total];
            }
            $png = Composer::render(['format' => $format, 'brand' => $brand, 'content' => $content]);
            if ($png === null) {
                if ($items === []) {
                    return response()->json(['ok' => false, 'error' => 'Falha ao compor a imagem.'], 502);
                }
                break; // carrossel parcial: preserva os slides já compostos
            }
            $url = self::storeMedia($png, 'png', 'image');
            $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'composto', 'platforms' => $platforms, 'composed' => true], Draft::imageMeta($url));
            if ($total > 1) {
                $item['carousel'] = ['index' => $i + 1, 'total' => $total];
            }
            $media = $this->attachImageLocked($d->id, $item, null);
            $items[] = $item;
        }

        Audit::log('studio.compose', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'format' => $format, 'slides' => count($items)]);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $items[0] ?? null, 'items' => $items, 'media' => $media]);
    }

    /**
     * POST /api/studio/carousel { draftId?, topic, slides?, format?, mode?, lang?, platforms?, personaId?/persona? }
     * 🎠 Gera o PLANO EDITORIAL do carrossel (headline vencedora + arquitetura narrativa de N slides
     * + brief de imagem por slide). ASSÍNCRONO: o front faz polling do rascunho até carousel.status
     * sair de 'generating'.
     *
     * NÃO gera imagem nenhuma e NÃO gasta crédito de imagem. O plano existe pra ser revisado antes
     * do render — que é onde o dinheiro é gasto (N slides = N imagens).
     */
    public function carousel(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $topic = trim((string) $r->input('topic'));
        if ($topic === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o tema ou cole o conteúdo do carrossel.'], 422);
        }
        $slides = (int) $r->input('slides', 9);
        if (! in_array($slides, [5, 7, 9, 12], true)) {
            $slides = 9;
        }
        $format = in_array($r->input('format'), ['feed', 'retrato', 'story', 'paisagem'], true) ? (string) $r->input('format') : 'retrato';
        $mode = $r->input('mode') === 'arte-total' ? 'arte-total' : 'editorial';
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        $platforms = Networks::only($r->input('platforms', []));

        $d = $r->input('draftId') ? $this->draft($r, $r->input('draftId')) : null;
        if (! $d) {
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($topic, 0, 80)]);
        }

        // O plano é UMA geração de texto — cobrada pelo modelo do seletor, estornada se falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }

        $payload = [
            'topic' => $topic,
            'slides' => $slides,
            'lang' => $lang,
            'persona' => self::textPersona($r, $t),
            'brand' => [
                'name' => (string) $t->name,
                'handle' => (string) ($t->brand_handle ?? ''),
                'primary' => (string) ($t->brand_primary ?? Tenant::BRAND_PRIMARY_DEFAULT),
                'has_logo' => trim((string) $t->brand_logo_url) !== '',
            ],
            // As referências da marca mandam nos briefs de imagem. Sem brief lido, o engine cai num
            // registro sóbrio — nunca no escuro por default (erro clássico de carrossel gerado).
            'visual_brief' => (string) ($t->visual_brief ?? ''),
        ];

        $d->update(['carousel' => [
            'topic' => $topic,
            'format' => $format,
            'mode' => $mode,
            'lang' => $lang,
            'platforms' => $platforms,
            'slide_count' => $slides,
            'status' => 'generating',
            // Assinatura de rodapé repetida em todos os slides: é o que faz o feed parecer coleção
            // curada em vez de N imagens soltas.
            'signature' => trim(now()->format('Y.m.d').' · '.(string) ($t->brand_handle ?? ''), ' ·'),
        ]]);

        GenerateCarouselPlanJob::dispatch($d->id, $t->id, $payload, $tm?->provider_model_id, $tm?->cost_credits);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'status' => 'generating']);
    }

    /**
     * POST /api/studio/carousel-render { draftId, tone?, only? }
     * 🎠 Renderiza os slides do plano já aprovado. A CAPA vai primeiro e vira referência visual dos
     * internos (que aí rodam em paralelo) — sem isso, saem N imagens avulsas em vez de uma peça.
     *
     * `only` (índice 0-based) re-renderiza UM slide sozinho, usando a capa já pronta como
     * referência: é o caminho barato pra consertar um slide fraco sem repagar o carrossel inteiro.
     *
     * Custo: 1 imagem POR SLIDE, reservado aqui de uma vez (reserve-then-consume). O usuário vê o
     * total antes de confirmar; se a capa falhar, o job estorna tudo.
     */
    public function carouselRender(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $c = is_array($d->carousel) ? $d->carousel : [];
        $slides = $c['slides'] ?? [];
        if (! is_array($slides) || $slides === []) {
            return response()->json(['ok' => false, 'error' => 'Gere o plano do carrossel antes de renderizar.'], 422);
        }
        $mode = ($c['mode'] ?? 'editorial') === 'arte-total' ? 'arte-total' : 'editorial';
        $format = in_array($c['format'] ?? '', ['feed', 'retrato', 'story', 'paisagem'], true) ? (string) $c['format'] : 'retrato';
        if ($mode === 'editorial' && ! Composer::disponivel()) {
            return response()->json(['ok' => false, 'error' => 'Compositor indisponível (config).'], 503);
        }
        // Registro tonal: claro por padrão. Escurecer um feed cujas referências são claras é o erro
        // mais comum de carrossel gerado — então o escuro é escolha explícita, nunca default.
        if (in_array($r->input('tone'), ['light', 'dark'], true)) {
            $c['tone'] = (string) $r->input('tone');
            $d->update(['carousel' => $c]);
        }

        // Quais slides entram nesta rodada.
        $only = $r->has('only') ? (int) $r->input('only') : null;
        if ($only !== null && ! isset($slides[$only])) {
            return response()->json(['ok' => false, 'error' => 'Slide inexistente.'], 422);
        }
        $capaPronta = trim((string) ($slides[0]['image_url'] ?? '')) !== '';
        if ($only !== null && $only !== 0 && ! $capaPronta) {
            return response()->json(['ok' => false, 'error' => 'Renderize a capa primeiro — ela é a referência visual dos demais slides.'], 422);
        }
        $alvos = $only !== null ? [$only] : array_keys($slides);

        // Modelo e custo: iguais aos da geração de imagem avulsa (i2i quando há referência).
        $weight = $this->usage->weightFor('image');
        $gmT2I = $this->imageT2IModel($r, $t->plan, GenModel::DEFAULT_T2I);
        $gmI2I = GenModel::resolveSelectable('img-referencia', 'image', $t->plan);
        $custo = ($gmI2I?->cost_credits) ?? ($gmT2I?->cost_credits);

        // Reserva a cota de TODOS os slides desta rodada de uma vez: o usuário sabe o custo total
        // antes, e não descobre no meio que acabou a cota com o carrossel pela metade.
        $reservados = 0;
        foreach ($alvos as $_) {
            if (! $this->usage->tryConsume($t, 'image', $weight, $custo)) {
                for ($i = 0; $i < $reservados; $i++) {
                    $this->usage->refund($t, 'image', $weight, $custo);
                }

                return response()->json([
                    'ok' => false,
                    'error' => 'Limite do plano atingido: um carrossel de '.count($alvos).' slides consome '.count($alvos).' imagens.',
                ], 402);
            }
            $reservados++;
        }

        // Marca os alvos como "renderizando" pra tela sair do estado parado imediatamente.
        foreach ($alvos as $i) {
            $slides[$i]['status'] = 'rendering';
            unset($slides[$i]['error']);
        }
        $c['slides'] = $slides;
        $d->update(['carousel' => $c]);

        $payloads = [];
        foreach ($alvos as $i) {
            $payloads[$i] = $this->carouselSlidePayload($slides[$i], $c, $mode, $format, $gmT2I, $gmI2I, $t, $r, $capaPronta ? (string) $slides[0]['image_url'] : '');
        }

        if ($only !== null) {
            GenerateCarouselSlideJob::dispatch($d->id, $t->id, $only, $payloads[$only], $mode, $format, $weight, $custo);
        } else {
            // Capa primeiro; os internos viajam junto e o job os libera assim que a capa grava.
            $rest = [];
            foreach ($alvos as $i) {
                if ($i !== 0) {
                    $rest[] = ['index' => $i, 'payload' => $payloads[$i]];
                }
            }
            GenerateCarouselSlideJob::dispatch($d->id, $t->id, 0, $payloads[0], $mode, $format, $weight, $custo, $rest);
        }

        Audit::log('studio.carousel.render', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'slides' => count($alvos), 'mode' => $mode]);

        return response()->json([
            'ok' => true, 'draftId' => $d->id, 'slides' => count($alvos), 'mode' => $mode,
            'message' => $only !== null ? 'Slide em geração.' : 'Capa em geração — os demais slides entram assim que ela ficar pronta.',
        ]);
    }

    /**
     * Palavras/segundo de LEITURA SILENCIOSA de texto curto na tela. Adulto lê prosa a ~3,3–4,2
     * p/s; texto editorial curto, quebrado em blocos e com realce, lê mais rápido que prosa —
     * mas o espectador também precisa VER a imagem. 3,6 é o meio-termo que sobreviveu ao teste.
     */
    private const CARROSSEL_LEITURA_PPS = 3.6;

    /**
     * Motor de VÍDEO do formato Vox.
     *
     * ⚠️ TENTEI TROCAR PRO GEMINI OMNI (2026-08-04) E NÃO DÁ — registrado aqui pra ninguém tentar
     * de novo. O método de origem do formato usa Omni como padrão "por ter custo mais
     * interessante" (40 créditos contra 90), e a troca parecia óbvia. Só que o Omni NÃO aceita
     * quadro inicial: a CLI recusa com "Model does not accept --start-image".
     *
     * E o Vox é i2v por definição — a colagem do GPT Image 2 É a peça, o clipe só a anima. Motor
     * sem quadro inicial não serve, por mais barato que seja. Descoberto do jeito caro: as 6
     * imagens da peça foram geradas e pagas antes de os 6 clipes falharem.
     *
     * Um motor mais barato ainda é desejável (o vídeo é 79% do preço), mas o requisito é
     * inegociável: precisa aceitar `start_image`. O catálogo do bridge agora reporta isso com
     * honestidade (ver higgsmodels.go — a detecção era otimista e mentia).
     *
     * Slug do catálogo, não id do provedor: preço e disponibilidade por plano vêm daqui.
     *
     * ⚠️ 2026-08-04 (histórico): era 'vid-realista' (Seedance 2 pelo agregador). A saída do
     * agregador desativou aquela linha e ninguém repontou esta constante — `resolveSelectable`
     * filtra por `active()`, então o preset devolvia "O motor do formato Vox não está disponível
     * no seu plano" pra TODO mundo. Referência a slug por string é ponteiro solto: o catálogo muda
     * por migration e o código não acompanha. Ver CatalogoIntegridadeTest, que falha quando um
     * slug citado no código sai do ar.
     */
    private const VOX_VIDEO_SLUG = 'hf-seedance-2-0';

    /**
     * 📏 RÉGUA DE LOCUÇÃO da MONTAGEM (fala por cena sobre clipe de duração FIXA).
     *
     * Mesmos números do engine (charsPerSec/narrationRespiro em engine/internal/content/
     * longform.go) e da tela (VideoStudio.tsx): ~12,2 caracteres por segundo, MEDIDO em locução
     * PT-BR, menos ~0,6s de respiro (entrada/saída). Aqui o teto NÃO é fixo em 65 como no Vox:
     * o clipe externo tem a duração que tem, então o teto sai de `duração_real × 12,2`.
     *
     * ⚠️ Mudou a régua no Go? Muda aqui e na tela junto — são as três metades da mesma verdade.
     */
    private const MONTAGEM_CHARS_POR_SEG = 12.2;

    private const MONTAGEM_RESPIRO_SEG = 0.6;

    /**
     * 💳 Saldo do provedor de IA, pra luz de status na tela.
     *
     * O cliente precisa saber ANTES de clicar em gerar. Sem isto ele só descobre que a conta zerou
     * quando a peça sai pela metade — caso real 2026-08-04: 3 de 6 cenas entregues, sem motivo
     * visível na tela. `ok:false` = não deu pra saber; a tela não mostra nada nesse caso (mostrar
     * "0" sem ter perguntado assusta à toa e ensina a ignorar o indicador).
     */
    public function saldoProvedor()
    {
        $res = $this->engine()->get('/v1/saldo');
        if (! $res->successful()) {
            return response()->json(['ok' => false]);
        }

        return response()->json([
            'ok' => (bool) $res->json('ok'),
            'credits' => (float) $res->json('credits'),
        ]);
    }

    /**
     * ✅ ROTEIRO DO VOX, sem gerar mídia. `POST /api/studio/vox-roteiro {prompt, scenes, aspect}`
     * devolve `{ok, beats:[{caption, script, image_prompt}]}` pra tela mostrar e o cliente editar.
     *
     * POR QUE ISTO EXISTE: até aqui a única forma de ler o roteiro era pagar a peça inteira e
     * assistir. Roteiro ruim descoberto no fim = peça toda perdida — em 2026-08-04 foram TRÊS
     * peças (≈600 créditos) pra entregar uma. Escrever o roteiro custa uma chamada de texto;
     * gerar a peça custa uma imagem + um clipe POR CENA. Separar as duas etapas põe a revisão do
     * lado barato.
     *
     * NÃO consome cota de geração de mídia de propósito: é texto, e cobrar por ler o roteiro
     * empurraria o cliente de volta pro "gera e torce", que é o comportamento caro.
     */
    public function voxRoteiro(Request $r)
    {
        $data = $r->validate([
            'prompt' => 'required|string|max:4000',
            'scenes' => 'nullable|integer|min:2|max:12',
            'aspect' => 'nullable|string|max:10',
            'lang' => 'nullable|string|max:10',
            // 📰 Regras EXTRAS de estrutura (seção ROTEIRO da aba /video (estilo Vox)): texto livre que o
            // engine APENDA ao prompt de segmentação padrão — nunca o substitui. Teto aqui e
            // de novo no engine (voxExtra): o corpo vem do navegador.
            'script_rules' => 'nullable|string|max:2000',
            // 🎨 ESTILO da peça (unificação Vídeo+Vox, 2026-08-06). 'vox' = o preset editorial
            // (colagem de papel, motor próprio); qualquer outro valor = caminho GENÉRICO, que é
            // o que a aba Vídeo sempre fez. Só o 'vox' vira `preset` no engine.
            'style' => 'nullable|string|max:40',
            // ⏱️ Duração da CENA — dela sai o teto de caracteres do script (beatScriptSize no
            // engine). O Vox é 6s por doutrina, mas aceita 8s desde 2026-08-30: é o beat do Vox
            // Factory (a ferramenta do Google Labs Flow que a casa usa), cujo teto de 90
            // caracteres sai da MESMA régua de 12,2 c/s. Sem o 8 aqui, um roteiro escrito lá caía
            // em silêncio no clipe de 6s (65 caracteres de fala) e a narração truncava.
            'duration' => 'nullable|string|max:4',
        ]);

        $vox = ($data['style'] ?? 'vox') === 'vox';
        $duration = in_array($data['duration'] ?? '', ['5', '6', '8', '10'], true) ? (string) $data['duration'] : ($vox ? '6' : '5');
        $payload = [
            'prompt' => $data['prompt'],
            'scenes' => (int) ($data['scenes'] ?? 6),
            'aspect' => $data['aspect'] ?? '9:16',
            'lang' => $data['lang'] ?? 'pt-BR',
            // No Vox só 6 ou 8 (o formato é de beat curto); pedido fora disso volta pra doutrina.
            'duration' => $vox ? (in_array($duration, ['6', '8'], true) ? $duration : '6') : $duration,
        ];
        if ($vox) {
            $payload['preset'] = 'vox';
        }
        if ($rules = mb_substr(trim((string) ($data['script_rules'] ?? '')), 0, 2000)) {
            $payload['extra_rules'] = $rules;
        }

        $res = $this->engine()->post('/v1/beats', $payload);

        $beats = $res->successful() ? (array) $res->json('beats') : [];
        if ($beats === []) {
            return response()->json([
                'ok' => false,
                'error' => 'Não consegui escrever o roteiro agora — tente de novo em instantes.',
            ], 422);
        }

        return response()->json(['ok' => true, 'beats' => $beats]);
    }

    /**
     * 🎬 MONTADOR da aba /video. `POST /api/studio/vox-montar {clipUrls[], draftId?, music?,
     * musicPrompt?, aspect?}` → concatena clipes JÁ NO NOSSO STORAGE (subidos em /api/studio/upload
     * ou escolhidos da galeria) na ordem dada e anexa o filme à galeria como peça Vox.
     *
     * POR QUE ISTO EXISTE: o Luciano gera clipes fora (ex.: Google Flow) e precisava de um editor
     * externo só pra juntar os MP4s. O caminho de montagem JÁ existia inteiro (/v1/filmassemble →
     * ffmpeg-service /concat-clips, o mesmo da aba Filme) — faltava só uma porta sem exigir o
     * fluxo de keyframes do Filme.
     *
     * 🎙️ 2026-08-06 — deixou de ser SÓ concat: aceita `scripts[]` (a fala de cada clipe),
     * `narration`, `subtitles`, `voice_id` e `durations[]`. O clipe externo agora vira peça
     * completa (voz ancorada por cena + legenda word-level + trilha). Sem `scripts` o
     * comportamento é byte-a-byte o de antes — concat puro.
     *
     * Anti-SSRF: cada URL passa por ownMediaUrl — só mídia do NOSSO domínio entra no concat.
     */
    public function voxMontar(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $r->validate([
            'clipUrls' => 'required|array|min:2|max:12',
            'clipUrls.*' => 'string|max:2048',
            'musicPrompt' => 'nullable|string|max:300',
            // 🎙️ MONTAGEM COMPLETA (2026-08-06): a fala de CADA clipe. Alinhada por índice com
            // clipUrls; item vazio = cena muda. Vazio/ausente = concat puro, como sempre foi.
            'scripts' => 'nullable|array|max:12',
            'scripts.*' => 'nullable|string|max:600',
            // Duração REAL de cada clipe (s), medida no arquivo. Serve pra CORTAR a fala que não
            // cabe: o /concat-clips NÃO estica o clipe — fala maior vaza pra próxima cena e a
            // última é cortada (ver longform.go:169 e anchor_voice_starts no ffmpeg-service).
            'durations' => 'nullable|array|max:12',
            'durations.*' => 'nullable|numeric',
            'voice_id' => 'nullable|string|max:64',
        ]);

        // Só URLs do nosso storage E com extensão de vídeo — um .jpg no meio derrubaria o concat
        // no ffmpeg com um erro ilegível, minutos depois. Recusa clara agora é mais barata.
        $clips = [];
        foreach ((array) $r->input('clipUrls') as $u) {
            $url = self::ownMediaUrl((string) $u);
            if ($url === null) {
                return response()->json(['ok' => false, 'error' => 'Um dos clipes não é do seu acervo — suba o arquivo primeiro.'], 422);
            }
            $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            if (! in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true)) {
                return response()->json(['ok' => false, 'error' => 'Só clipes de vídeo entram na montagem.'], 422);
            }
            $clips[] = $url;
        }

        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'vox-montagem']);

        // Mesmo preço da montagem da aba Filme: 1 short (a trilha gerada já está no bucket).
        if (! $this->usage->tryConsume($t, 'short', 1)) {
            return response()->json(['ok' => false, 'error' => 'Montagem de vídeo não está incluída no seu plano (ou o limite acabou).'], 402);
        }

        $aspect = in_array($r->input('aspect'), ['9:16', '16:9'], true) ? (string) $r->input('aspect') : '9:16';
        $payload = [
            'clipUrls' => $clips,
            // Trilha OPCIONAL (default ligada, como no Filme): o /v1/filmassemble gera a música
            // e mixa por baixo do áudio original dos clipes.
            'music' => filter_var($r->input('music', true), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => mb_substr(trim((string) $r->input('musicPrompt', '')), 0, 300),
            'aspect' => $aspect,
        ];

        // ── 🎙️ MONTAGEM COMPLETA — narração POR CENA + legenda (2026-08-06) ──────────────────
        // Até aqui o montador SÓ concatenava: quem exportava os clipes de fora (Google Flow etc.)
        // subia o vídeo cru e não tinha como completar a peça. O caminho já existia inteiro e
        // ninguém o usava daqui: `scripts` (locução por cena) → /v1/filmassemble → ffmpeg-service
        // /concat-clips, que ancora cada fala no início da sua cena (anchor_voice_starts) e queima
        // a legenda word-level. Sem `scripts` o payload é byte-a-byte o de antes (concat puro).
        $scripts = [];
        foreach ((array) $r->input('scripts', []) as $s) {
            $scripts[] = mb_substr(trim((string) $s), 0, 600);
        }
        $scripts = array_slice($scripts, 0, count($clips));
        if (array_filter($scripts) !== []) {
            // 📏 TETO POR CENA, cobrado no servidor. O concat NÃO estica o clipe: fala maior que a
            // cena vaza pra próxima e a última é cortada. A tela já barra antes (contador N/teto),
            // mas o corpo vem do navegador — aqui é a rede de baixo, com a MESMA régua do engine
            // (charsPerSec = 12,2 em engine/internal/content/longform.go).
            $durs = (array) $r->input('durations', []);
            foreach ($scripts as $i => $s) {
                if ($s === '') {
                    continue;
                }
                $dur = (float) ($durs[$i] ?? 0);
                if ($dur <= 0) {
                    continue; // duração desconhecida → não dá pra calcular teto; segue como veio
                }
                $teto = (int) floor(max(0.0, $dur - self::MONTAGEM_RESPIRO_SEG) * self::MONTAGEM_CHARS_POR_SEG);
                if ($teto > 0 && mb_strlen($s) > $teto) {
                    return response()->json([
                        'ok' => false,
                        'error' => sprintf('A fala da cena %d tem %d caracteres e só cabem %d nos %.1fs do clipe — encurte antes de montar (a montagem não estica o vídeo: a fala vazaria pra cena seguinte).', $i + 1, mb_strlen($s), $teto, $dur),
                    ], 422);
                }
            }
            $narration = filter_var($r->input('narration', true), FILTER_VALIDATE_BOOLEAN);
            if ($narration) {
                // Voz validada contra a allowlist do provedor (mesma regra do media()): id ruim
                // tem de virar 422 agora, não um job cobrado que morre no provedor minutos depois.
                $voiceId = $this->voiceIdEscolhida($r, $t);
                if ($voiceId === false) {
                    return response()->json(['ok' => false, 'error' => 'Voz de narração inválida — escolha um narrador da lista.'], 422);
                }
                $payload['scripts'] = $scripts;
                $payload['narration'] = true;
                $payload['voiceId'] = $voiceId;
                // Legenda word-level só existe COM narração (é o áudio que dá os tempos) — o
                // ffmpeg-service já impõe isso (`subtitles and narration`); dizer aqui evita
                // mandar um estilo de legenda que ninguém leria.
                if (filter_var($r->input('subtitles', false), FILTER_VALIDATE_BOOLEAN)) {
                    $payload['subtitles'] = true;
                    $payload = array_merge($payload, self::subtitleStyleFrom($r));
                }
            }
        }
        // style 'vox' → a galeria classifica como peça Vox (catOf em galeria/page.tsx), não
        // como clipe solto — mesma razão do preset vox no media().
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/filmassemble', $payload, 'short', 1, 'vox', Networks::only($r->input('platforms', [])), null, 'video');

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Filme em montagem — aparece na Galeria (categoria Vox) em alguns minutos.']);
    }

    /**
     * 💾 STORYBOARD do Vox persistido no rascunho (V2 — regeneração por beat). `POST
     * /api/studio/vox-storyboard {beats[], draftId?, tema?, aspect?, vox_style_extra?,
     * vox_direction_extra?}` → grava em story.vox e devolve o estado salvo.
     *
     * POR QUE PERSISTIR: o fluxo cena a cena atravessa VÁRIOS requests (gerar cena → olhar →
     * regenerar → montar), e o storyboard só no estado do navegador morria num F5 — com as cenas
     * já pagas apontando pra um roteiro que não existe mais. `story.vox` é uma CHAVE PRÓPRIA
     * dentro do json `story` existente (nada de tabela/coluna nova): o fluxo de Histórias só lê
     * story.scenes, então os dois coexistem no mesmo rascunho.
     *
     * clip_url é PRESERVADO por índice quando script e image_prompt não mudaram — editar a frase
     * ou a ilustração invalida a cena (ela foi gerada do texto antigo) e derruba o clipe daquele
     * índice, que volta a pedir geração. Manter clipe de texto velho seria mentir na tela.
     */
    public function voxStoryboard(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $r->validate([
            'beats' => 'required|array|min:1|max:12',
            'tema' => 'nullable|string|max:4000',
            'aspect' => 'nullable|string|max:10',
            'vox_style_extra' => 'nullable|string|max:2000',
            'vox_direction_extra' => 'nullable|string|max:2000',
            // 🎙️ Narrador escolhido na tela. Vazio = padrão da conta (voz do tenant).
            'voice_id' => 'nullable|string|max:64',
            // 🎨 Unificação Vídeo+Vox (2026-08-06): a peça guarda o ESTILO, o MOTOR e a DURAÇÃO
            // da cena junto do storyboard. O fluxo cena a cena atravessa dias — a cena regerada
            // amanhã tem de sair no mesmo estilo/motor que estava na tela quando o roteiro foi
            // aprovado, senão a emenda aparece na montagem.
            'style' => 'nullable|string|max:40',
            'model' => 'nullable|string|max:64',
            'duration' => 'nullable|string|max:4',
        ]);
        // Valida ANTES de gravar: id inválido salvo no rascunho só apareceria lá na frente, na
        // geração do filme, com a peça inteira paga.
        $voiceId = $this->voiceIdEscolhida($r, $t);
        if ($voiceId === false) {
            return response()->json(['ok' => false, 'error' => 'Voz de narração inválida — escolha um narrador da lista.'], 422);
        }
        $beats = self::voxBeatsFrom($r); // mesma allowlist/sanitização do caminho monolítico
        if ($beats === []) {
            return response()->json(['ok' => false, 'error' => 'Storyboard vazio — gere os capítulos antes de salvar.'], 422);
        }

        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr(trim((string) $r->input('tema', '')), 0, 120) ?: 'vox']);

        $vox = null;
        DB::transaction(function () use ($d, $r, $beats, $voiceId, &$vox) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $old = $story['vox']['beats'] ?? [];
            foreach ($beats as $i => $b) {
                $mesmo = isset($old[$i])
                    && ($old[$i]['script'] ?? null) === $b['script']
                    && ($old[$i]['image_prompt'] ?? null) === $b['image_prompt'];
                if ($mesmo && ! empty($old[$i]['clip_url'])) {
                    $beats[$i]['clip_url'] = (string) $old[$i]['clip_url'];
                }
            }
            $story['vox'] = [
                'tema' => mb_substr(trim((string) $r->input('tema', '')), 0, 4000),
                'aspect' => in_array($r->input('aspect'), ['9:16', '16:9'], true) ? (string) $r->input('aspect') : '9:16',
                'style_extra' => mb_substr(trim((string) $r->input('vox_style_extra', '')), 0, 2000),
                'direction_extra' => mb_substr(trim((string) $r->input('vox_direction_extra', '')), 0, 2000),
                // 🎙️ Narrador da peça, já validado. Guardado JUNTO do storyboard (e não só no
                // navegador) porque o fluxo atravessa dias: o filme gerado amanhã tem de sair na
                // mesma voz que a tela mostrava quando o roteiro foi aprovado.
                // ⚠️ Não invalida clip_url: o clipe cena a cena é MUDO (/v1/voxscene não narra),
                // então trocar de voz não torna cena nenhuma mentirosa.
                'voice_id' => $voiceId,
                // 🎨 Estilo/motor/duração da peça (unificação Vídeo+Vox). 'vox' = preset
                // editorial; qualquer outro = caminho genérico da aba Vídeo. `model` só existe
                // no genérico (o Vox tem motor próprio, VOX_VIDEO_SLUG).
                'style' => mb_substr(trim((string) $r->input('style', 'vox')), 0, 40) ?: 'vox',
                'model' => mb_substr(trim((string) $r->input('model', '')), 0, 64),
                // 8s entrou em 2026-08-30: é o beat do Vox Factory (Google Labs Flow), cujo teto
                // de 90 caracteres sai da mesma régua de 12,2 c/s que a nossa.
                'duration' => in_array($r->input('duration'), ['5', '6', '8', '10'], true) ? (string) $r->input('duration') : '6',
                'beats' => $beats,
            ];
            $upd = ['story' => $story];
            // ✍️ keyword = TEMA do Vox quando o rascunho ainda tem um placeholder: é a keyword
            // que o /v1/text usa pra escrever a copy de publicação (seção "Texto de publicação"
            // da aba /video (estilo Vox)) — placeholder ali = legenda genérica. Keyword REAL (de pesquisa/outro
            // fluxo no mesmo rascunho) não é tocada: não é nossa pra sobrescrever.
            $tema = mb_substr(trim((string) $r->input('tema', '')), 0, 200);
            if ($tema !== '' && in_array($locked->keyword, ['', 'vox', 'vox-montagem', 'upload', 'galeria', 'Publicação'], true)) {
                $upd['keyword'] = $tema;
            }
            $locked->update($upd);
            $vox = $story['vox'];
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'vox' => $vox]);
    }

    /**
     * 🎬 UMA cena do Vox (V2). `POST /api/studio/vox-cena {draftId, index}` → gera (ou REGERA)
     * a imagem + o clipe do beat `index` do storyboard salvo (story.vox), de forma assíncrona.
     *
     * A unidade de custo vira a CENA: no monolítico, uma cena torta custava a peça inteira de
     * novo. Cobrança espelha o media() por cena — bucket 'video', peso 1, custo = preço do tier
     * 5s do motor do formato (o clipe Vox é de 6s, mesma chave p5 que o media() usa pra "6") —
     * e é reservada AQUI (reserve-then-consume), com estorno no job se a geração falhar.
     *
     * O beat vem do RASCUNHO, não do corpo: o que se gera é o que está salvo/aprovado — corpo
     * livre aqui seria prompt injection com cobrança.
     */
    public function voxCena(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $r->validate([
            'draftId' => 'required',
            'index' => 'required|integer|min:0|max:11',
        ]);
        $d = $this->draft($r, $r->input('draftId'));
        $vox = is_array($d->story) ? ($d->story['vox'] ?? null) : null;
        $i = (int) $r->input('index');
        $beat = $vox['beats'][$i] ?? null;
        if (! is_array($beat) || trim((string) ($beat['image_prompt'] ?? '')) === '') {
            return response()->json(['ok' => false, 'error' => 'Cena sem storyboard salvo — gere e salve o storyboard antes.'], 422);
        }
        // 🎨 ESTILO da peça (unificação Vídeo+Vox): vem do storyboard SALVO, não do corpo — quem
        // manda é o que foi aprovado. 'vox' = preset editorial (motor próprio); qualquer outro =
        // caminho genérico da aba Vídeo (o motor é o escolhido no seletor).
        $estilo = (string) ($vox['style'] ?? 'vox') ?: 'vox';
        $ehVox = $estilo === 'vox';
        // MESMO motor do preset (VOX_VIDEO_SLUG): cena avulsa gerada por outro modelo sairia com
        // outra cara e denunciaria a emenda na montagem. No genérico o motor é o do storyboard.
        $slug = $ehVox ? self::VOX_VIDEO_SLUG : (string) ($vox['model'] ?? '');
        $vm = $slug !== '' ? GenModel::resolveSelectable($slug, 'video', $t->plan) : null;
        if (! $vm) {
            return response()->json([
                'ok' => false,
                'error' => $ehVox
                    ? 'O motor do formato Vox não está disponível no seu plano.'
                    : 'O motor de vídeo escolhido não está disponível no seu plano — escolha outro e salve o storyboard.',
            ], 422);
        }
        if (! $ehVox && ! $vm->isClipCapable()) {
            return response()->json(['ok' => false, 'error' => 'Modelo de vídeo indisponível neste formato.'], 422);
        }
        $q = $this->videoQuality($vm, $r);
        $cost = ($q['p5'] ?? null) ?? $vm->cost_credits;
        $cost = $cost !== null ? (int) $cost : null;
        // RESERVE-THEN-CONSUME (AUD-002): 1 unidade do bucket 'video' + custo do modelo.
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }

        $aspect = in_array($vox['aspect'] ?? '', ['9:16', '16:9'], true) ? (string) $vox['aspect'] : '9:16';
        if ($ehVox) {
            $payload = [
                'image_prompt' => mb_substr(trim((string) $beat['image_prompt']), 0, 1200),
                'aspect' => $aspect,
                // ⏱️ A duração vem do STORYBOARD, não fixa: o teto de caracteres do script foi
                // calculado com ela (beatScriptSize no engine). Fixar 6 aqui, como estava até
                // 2026-08-30, gerava clipe de 6s para um roteiro escrito para 8 — e a fala de 90
                // caracteres não cabe em 5,4s: na montagem ela vaza para a cena seguinte e, na
                // última, é cortada no mix. Falha silenciosa: o clipe sai bonito, o filme é que
                // sai com a narração truncada.
                'duration' => in_array($vox['duration'] ?? '', ['6', '8'], true) ? (string) $vox['duration'] : '6',
                'gen_lines' => $this->videoGenLine($vm, $q),
            ];
            // Extras salvos com o storyboard — a cena regerada mantém a direção da peça.
            if (($extra = (string) ($vox['style_extra'] ?? '')) !== '') {
                $payload['vox_style_extra'] = $extra;
            }
            if (($extra = (string) ($vox['direction_extra'] ?? '')) !== '') {
                $payload['vox_direction_extra'] = $extra;
            }
            $endpoint = '/v1/voxscene';
        } else {
            // 🎬 CENA GENÉRICA (estilo != vox): a MESMA chamada que a aba Vídeo sempre fez —
            // /v1/video de UMA cena, muda e sem legenda (narração/legenda/trilha entram na peça
            // inteira ou na montagem, nunca no clipe solto, que seria pagar a voz duas vezes).
            // `scenes:1` + tudo desligado cai no generateSingleClip do engine.
            $payload = [
                'prompt' => mb_substr(trim((string) ($beat['image_prompt'] ?: $beat['script'])), 0, 1200),
                'scenes' => 1,
                'aspect' => $aspect,
                'duration' => in_array($vox['duration'] ?? '', ['5', '10'], true) ? (string) $vox['duration'] : '5',
                'style' => $estilo,
                'narration' => false,
                'subtitles' => false,
                'music' => false,
                'gen_lines' => $this->videoGenLine($vm, $q),
            ];
            // A direção de arte (persona) salva com o storyboard vale pra cena avulsa também —
            // sem ela, a cena regerada sairia com outra cara no meio da peça.
            if (($extra = (string) ($vox['style_extra'] ?? '')) !== '') {
                $payload['persona'] = $extra;
            }
            $endpoint = '/v1/video';
        }
        GenerateVoxSceneJob::dispatch($d->id, $t->id, $i, $payload, 'video', 1, $cost, $endpoint, $estilo);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Cena em geração — aparece no storyboard em alguns minutos.']);
    }

    /**
     * Beats APROVADOS vindos da tela, saneados pro engine.
     *
     * Só passa o que o formato usa (caption/script/image_prompt/image_prompt_b/sfx) e descarta o resto:
     * o corpo vem do navegador, então campo extra aqui é campo que o cliente pode injetar no prompt
     * do modelo. Beat sem `script` é descartado — é ele que vira a narração, e cena muda no meio da
     * peça é pior que cena a menos.
     *
     * ⚠️ `image_prompt_b` PRECISA estar nesta allowlist. É a arte do segundo plano do beat (ver
     * voxSegundoPlano no engine): se ele cair aqui, o roteiro aprovado volta pro engine sem a
     * segunda imagem e a peça perde metade da densidade visual — sem erro, sem log, só um vídeo
     * mais pobre que o gerado sem aprovação. Campo novo do beat entra AQUI também, sempre.
     *
     * Vazio ⇒ o engine segmenta como sempre (fluxo de uma tacada só, intacto).
     *
     * @return array<int,array{caption:string,script:string,image_prompt:string,image_prompt_b:string}>
     */
    private static function voxBeatsFrom(Request $r): array
    {
        $out = [];
        foreach ((array) $r->input('beats', []) as $b) {
            if (! is_array($b)) {
                continue;
            }
            $script = trim((string) ($b['script'] ?? ''));
            if ($script === '') {
                continue;
            }
            $out[] = [
                'caption' => mb_substr(trim((string) ($b['caption'] ?? '')), 0, 200),
                'script' => mb_substr($script, 0, 600),
                'image_prompt' => mb_substr(trim((string) ($b['image_prompt'] ?? '')), 0, 1200),
                'image_prompt_b' => mb_substr(trim((string) ($b['image_prompt_b'] ?? '')), 0, 1200),
                'sfx' => mb_substr(trim((string) ($b['sfx'] ?? '')), 0, 120),
            ];
        }

        // Teto igual ao de cenas: lista gigante vinda do navegador vira custo de IA por cena.
        return array_slice($out, 0, 12);
    }

    /**
     * PISO de tempo (s) que um QUADRO do revelado fica na tela, derivado do bloco que ele acabou
     * de revelar. É piso, não duração final: quando há narração, quem manda é a fala, e este
     * valor só impede que o corte venha antes de dar pra LER o que apareceu.
     *
     * Por que não é um valor fixo: os tetos de palavras do formato (docs/REGRAS-DO-CARROSSEL.md)
     * vão de ≤6 num chapéu a ≤18 numa âncora. Com tempo uniforme, ou o chapéu se arrasta ou a
     * âncora passa antes de dar pra ler — e ninguém volta o Reels.
     *
     * O respiro extra vai pro ÚLTIMO quadro de capa e de assinatura: a capa é o gancho (decide o
     * scroll) e o CTA precisa assentar antes do corte final.
     */
    public static function carrosselDuracaoBloco(string $bloco, string $papel, bool $ultimo): float
    {
        $palavras = count(preg_split('/\s+/u', trim($bloco), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $respiro = $ultimo ? match ($papel) {
            'capa' => 0.8,
            'assinatura' => 1.0,
            default => 0.0,
        } : 0.0;

        return round(min(9.0, max(2.0, 0.8 + $palavras / self::CARROSSEL_LEITURA_PPS + $respiro)), 2);
    }

    /**
     * Texto de um bloco pronto pra fala. Fecha a frase com ponto: sem pontuação final o TTS sobe
     * a entonação como se fosse pergunta, e a peça inteira soa hesitante.
     */
    public static function carrosselFala(string $bloco): string
    {
        $txt = rtrim(trim($bloco), " \t\n\r.;:,");

        return $txt === '' ? '' : $txt.'.';
    }

    /**
     * POST /api/studio/carousel-video { draftId, narration?, music?, aspect? }
     * 🎞️ Monta os slides JÁ RENDERIZADOS do carrossel num vídeo vertical.
     *
     * DE ONDE VEM O VÍDEO: reusa a montagem do Short (`/v1/storyvideo` → ffmpeg-service
     * `/shortform`), que já transforma imagem em clipe com Ken Burns, sincroniza narração e
     * concatena. Nada de pipeline nova — o que faltava era a fiação e a duração por slide.
     *
     * NÃO GASTA IMAGEM: os PNGs dos slides já existem (`slide_url`). O vídeo é compute, não
     * geração — por isso o custo é o de montagem slides-only (40 créd), o mesmo já praticado na
     * história sem clipe (ver storyVideo), e não os 440 do Short com vídeo.
     *
     * LEGENDA DESLIGADA SEMPRE: o texto já está DESENHADO no slide pelo compositor. Queimar
     * legenda por cima escreveria a mesma frase duas vezes na tela.
     */
    public function carouselVideo(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $c = is_array($d->carousel) ? $d->carousel : [];
        $slides = is_array($c['slides'] ?? null) ? $c['slides'] : [];
        $prontos = array_filter($slides, fn ($s) => ($s['status'] ?? '') === 'ready' && trim((string) ($s['image_url'] ?? '')) !== '');
        if (count($prontos) < 2) {
            return response()->json([
                'ok' => false,
                'error' => 'Renderize o carrossel antes: o vídeo precisa de pelo menos 2 slides prontos.',
            ], 422);
        }
        if (! Composer::disponivel()) {
            return response()->json(['ok' => false, 'error' => 'Compositor indisponível (config).'], 503);
        }

        // Narração LIGADA por padrão: um carrossel virado vídeo sem voz é um slideshow mudo, e no
        // feed ele perde pro carrossel original — que ao menos o leitor controla no próprio ritmo.
        // É a voz que dá razão ao formato existir. Desligar é escolha explícita.
        $narracao = filter_var($r->input('narration', true), FILTER_VALIDATE_BOOLEAN);

        // 💳 Montagem slides-only: mesmo preço da história sem clipe (o custo real é TTS + CPU).
        // Não há geração de imagem aqui — os fundos já existem e compor é CPU.
        $custo = 40;
        if (! $this->usage->tryConsume($t, 'short', 1, $custo)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }

        $lang = (string) ($c['lang'] ?? ($t->content_lang ?? 'pt-BR'));
        // Os beats reais são montados no job (compor N quadros é lento); aqui só o resto do payload.
        [$montagem, $fxCount, $platforms] = $this->buildShortMontage($r, $t, [], $lang, '9:16');
        unset($montagem['beats']);
        if ($platforms === []) {
            $platforms = Networks::only((array) ($c['platforms'] ?? []));
        }

        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1, $custo);

            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para os efeitos ('.$fxCount.').'], 402);
        }

        $c['video'] = ['status' => 'rendering'];
        $d->update(['carousel' => $c]);

        GenerateCarouselVideoJob::dispatch($d->id, $t->id, $montagem, $platforms, $custo, $fxCount, $narracao);

        Audit::log('studio.carousel.video', [
            'tenant_id' => $t->id, 'draft_id' => $d->id,
            'slides' => count($prontos), 'narracao' => $narracao,
        ]);

        return response()->json([
            'ok' => true, 'draftId' => $d->id, 'slides' => count($prontos),
            'message' => 'Vídeo do carrossel em montagem — aparece na galeria em alguns minutos.',
        ]);
    }

    /**
     * Monta o body do /v1/image de UM slide. No modo editorial o pedido é só o FUNDO (o texto entra
     * na composição), e por isso o prompt proíbe explicitamente letra na imagem: fundo com texto
     * fantasma da IA por baixo do texto real é o defeito mais visível do formato.
     *
     * @param  array<string,mixed>  $slide
     * @param  array<string,mixed>  $c
     * @return array<string,mixed>
     */
    private function carouselSlidePayload(array $slide, array $c, string $mode, string $format, ?GenModel $gmT2I, ?GenModel $gmI2I, Tenant $t, Request $r, string $capaUrl): array
    {
        $img = (array) ($slide['image'] ?? []);
        $brief = trim((string) ($img['subject'] ?? ''));
        $partes = [];
        foreach (['subject' => 'Subject', 'composition' => 'Composition', 'lighting' => 'Lighting', 'color_treatment' => 'Color treatment', 'style' => 'Style', 'mood' => 'Mood', 'metaphor' => 'Metaphor'] as $k => $label) {
            if ($v = trim((string) ($img[$k] ?? ''))) {
                $partes[] = $label.': '.$v;
            }
        }
        if ($partes === []) {
            // Sem brief (o engine degradou nessa etapa) o slide ainda renderiza: cai no tema.
            $partes[] = 'Subject: an editorial photograph that illustrates "'.mb_substr((string) ($c['topic'] ?? ''), 0, 160).'"';
        }
        $prompt = implode('. ', $partes);
        if ($evitar = trim((string) ($img['avoid'] ?? ''))) {
            $prompt .= '. AVOID: '.$evitar;
        }
        if ($mode === 'editorial') {
            // O texto é composto por cima. Letra gerada aqui vira ruído sob o texto real.
            $prompt .= '. This image is a BACKGROUND PLATE: render NO text, NO lettering, NO captions, NO logos, NO watermarks and NO user interface anywhere in the frame. Leave the lower two thirds visually calm so typography can be composed on top.';
        } else {
            $blocks = array_values(array_filter(array_map(fn ($b) => trim((string) $b), (array) ($slide['blocks'] ?? []))));
            if ($blocks !== []) {
                $prompt .= '. Render this exact text inside the image as crisp, correctly spelled typography, without paraphrasing or omitting any block: "'.implode('" / "', $blocks).'"';
            }
        }

        $aspect = ['feed' => '1:1', 'retrato' => '4:5', 'story' => '9:16', 'paisagem' => '16:9'][$format] ?? '4:5';
        $refs = [];
        if ($capaUrl !== '' && (int) ($slide['index'] ?? 1) !== 1 && self::isOwnMediaUrl($capaUrl)) {
            $refs[] = $capaUrl;
        }
        $payload = ['prompt' => $prompt, 'aspect' => $aspect, 'style' => 'realista'];
        $gm = $refs !== [] ? $gmI2I : $gmT2I;
        if ($refs !== []) {
            $payload['imageUrls'] = $refs;
        }
        // provider/model (+ spec do motor quando o provider é schema-driven) pela FONTE ÚNICA.
        // Era montado à mão aqui com um ramo `provider === 'kie'` — morto desde a saída do
        // agregador, e que deixava o spec do Magnific de fora justamente por ser um ramo próprio.
        $payload = array_merge($payload, GenPayload::imagePayloadBase($gm));

        return $payload;
    }

    /**
     * POST /api/studio/visual-brief { refs?[] }
     * 🖼️ Lê as referências visuais da marca (visão) e guarda o briefing no tenant: paleta, registro
     * tonal, tipografia, estilo de imagem e assinatura de rodapé. É o bloco que comanda os briefs
     * de imagem do carrossel — as referências mandam, o assunto obedece.
     *
     * Sem `refs` no request, usa os assets do Hub marcados como referência da marca.
     */
    public function visualBrief(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $refs = array_values(array_filter(
            (array) $r->input('refs', []),
            fn ($u) => is_string($u) && self::isOwnMediaUrl($u)   // anti-SSRF: só mídia nossa
        ));
        if ($refs === []) {
            return response()->json(['ok' => false, 'error' => 'Escolha ao menos uma imagem de referência da marca.'], 422);
        }
        $res = EngineClient::make(150)->post('/v1/visualbrief', ['refs' => array_slice($refs, 0, 6)]);
        $brief = $res->successful() ? trim((string) $res->json('brief')) : '';
        if ($brief === '') {
            return response()->json(['ok' => false, 'error' => 'Não foi possível ler as referências.'], 502);
        }
        $t->update(['visual_brief' => $brief, 'visual_brief_at' => now()]);
        Audit::log('studio.visualbrief', ['tenant_id' => $t->id, 'refs' => count($refs)]);

        return response()->json(['ok' => true, 'brief' => $brief, 'at' => $t->visual_brief_at]);
    }

    /** POST /api/studio/shot-card { draftId, shot:{ imageUrl, projectTitle, shotNumber, shotTitle?, camera?, lighting?, lens?,
     *  movement?, duration?, format?, audio?, voiceover?, directorNote?, keyIdeas?[] } }
     *  Gera um SHOT-CARD (model sheet de storyboard) da cena via next/og (template StoryboardCard) e anexa o PNG como
     *  mídia do draft. Documento por cena — quadro + specs + Director's Note, estilo prancha de produção. O cliente
     *  monta o `shot`; aqui só validamos, compomos (token interno) e salvamos. Não gasta crédito de IA. */
    public function shotCard(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $draftId = $r->input('draftId');
        if (! $draftId) {
            return response()->json(['ok' => false, 'error' => 'Selecione um rascunho.'], 422);
        }
        $d = $this->draft($r, $draftId);

        $shot = (array) $r->input('shot', []);
        $imageUrl = (string) ($shot['imageUrl'] ?? '');
        if ($imageUrl === '' || ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem da cena antes do shot-card.'], 422);
        }
        $projectTitle = trim((string) ($shot['projectTitle'] ?? ''));
        if ($projectTitle === '') {
            return response()->json(['ok' => false, 'error' => 'Falta o título do projeto.'], 422);
        }

        $token = (string) config('services.web.compose_token');
        if ($token === '') {
            return response()->json(['ok' => false, 'error' => 'Compositor indisponível (config).'], 503);
        }
        $webUrl = rtrim((string) config('services.web.url'), '/').'/api/compose';

        // Sanitiza/limita todos os campos de texto (o cliente monta o shot; não confiar no tamanho).
        $str = fn ($v, $n) => (($s = mb_substr(trim((string) ($v ?? '')), 0, $n)) !== '' ? $s : null);
        $ideas = array_values(array_filter(array_map(
            fn ($s) => mb_substr(trim((string) $s), 0, 80),
            array_slice((array) ($shot['keyIdeas'] ?? []), 0, 8)
        ), fn ($s) => $s !== ''));

        $brand = $t->brandKit();
        $payloadShot = [
            'imageUrl' => $imageUrl,
            'projectTitle' => mb_substr($projectTitle, 0, 80),
            'sequence' => $str($shot['sequence'] ?? null, 40),
            'shotNumber' => max(1, (int) ($shot['shotNumber'] ?? 1)),
            'shotTitle' => $str($shot['shotTitle'] ?? null, 80),
            'camera' => $str($shot['camera'] ?? null, 60),
            'lighting' => $str($shot['lighting'] ?? null, 60),
            'lens' => $str($shot['lens'] ?? null, 40),
            'movement' => $str($shot['movement'] ?? null, 120),
            'duration' => $str($shot['duration'] ?? null, 20),
            'format' => $str($shot['format'] ?? null, 12),
            'audio' => $str($shot['audio'] ?? null, 120),
            'voiceover' => $str($shot['voiceover'] ?? null, 400),
            'directorNote' => $str($shot['directorNote'] ?? null, 600),
            'keyIdeas' => $ideas,
            'primary' => is_array($brand) ? ($brand['primary'] ?? null) : null,
        ];

        try {
            $resp = Http::withHeaders(['X-Compose-Token' => $token])
                ->timeout(30)
                ->post($webUrl, ['type' => 'shotcard', 'shot' => $payloadShot]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'Falha ao compor o shot-card.'], 502);
        }
        if (! $resp->successful() || ! str_starts_with((string) $resp->header('Content-Type'), 'image/')) {
            return response()->json(['ok' => false, 'error' => 'O compositor não retornou uma imagem.'], 502);
        }

        $url = self::storeMedia($resp->body(), 'png', 'image');
        $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'shotcard', 'platforms' => [], 'composed' => true], Draft::imageMeta($url));
        $media = $this->attachImageLocked($d->id, $item, null);

        Audit::log('studio.shotcard', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'shot' => (int) ($shot['shotNumber'] ?? 1)]);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
    }

    /** POST /api/studio/deepsearch { keyword } → pesquisa profunda (Jina DeepSearch). Plano Studio. */
    public function deepResearch(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if (! ($t->limits()['premium'] ?? false)) {
            return response()->json(['ok' => false, 'error' => 'Pesquisa profunda é exclusiva do plano Studio.'], 402);
        }
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }
        $payload = ['keyword' => $keyword];
        $keys = array_filter((array) ($t->search_keys ?? []), fn ($v) => trim((string) $v) !== '');
        if ($keys !== []) {
            $payload['keys'] = $keys;
        }
        // Principal/fallback por função (tenant ou defaults) → engine respeita a ordem.
        $payload['lines'] = $t->searchLines();
        $res = $this->engine()->post('/v1/deepsearch', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'pesquisa profunda falhou'], 502);
        }
        $summary = (string) $res->json('summary');
        $research = [
            'answer' => $summary,
            'summary' => $summary,
            'brief' => (string) ($res->json('brief') ?: $summary),
            'results' => $res->json('results') ?? [],
        ];
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword, 'research' => $research]);

        return response()->json(['ok' => true, 'draftId' => $draft->id, 'research' => $research]);
    }

    /** GET /api/studio/search-keys → status (configurado ou não) de cada provedor de pesquisa. */
    public function searchKeys(Request $r): JsonResponse
    {
        $saved = (array) ($this->tenant($r)->search_keys ?? []);
        $configured = [];
        foreach (['tavily', 'brave', 'jina', 'scrapecreators'] as $k) {
            $configured[$k] = isset($saved[$k]) && trim((string) $saved[$k]) !== '';
        }

        return response()->json(['ok' => true, 'configured' => $configured]);
    }

    /** POST /api/studio/search-keys → salva as chaves BYOK (merge das não-vazias) ou limpa. */
    public function searchKeysUpdate(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        if ($r->boolean('clear')) {
            $t->update(['search_keys' => null]);

            return response()->json(['ok' => true, 'cleared' => true]);
        }
        $keys = (array) ($t->search_keys ?? []);
        foreach (['tavily', 'brave', 'jina', 'scrapecreators'] as $k) {
            $v = trim((string) $r->input($k, ''));
            if ($v !== '') {
                $keys[$k] = $v;
            }
        }
        $t->update(['search_keys' => $keys ?: null]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/studio/search-config → configuração de principal/fallback por função.
     * Retorna as linhas efetivas (tenant OU defaults), status das chaves BYOK e os
     * defaults recomendados (pra UI exibir "recomendado").
     */
    public function searchConfig(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $saved = (array) ($t->search_keys ?? []);
        $keysConfigured = [];
        foreach (['tavily', 'brave', 'jina', 'scrapecreators'] as $k) {
            $keysConfigured[$k] = isset($saved[$k]) && trim((string) $saved[$k]) !== '';
        }

        return response()->json([
            'ok' => true,
            'lines' => $t->searchLines(),
            'keys_configured' => $keysConfigured,
            'recommended' => Tenant::SEARCH_LINES_DEFAULT,
        ]);
    }

    /**
     * POST /api/studio/search-config → salva SÓ as linhas (principal/fallback) por função.
     * As chaves BYOK continuam no searchKeysUpdate (POST /api/studio/search-keys).
     * Valida provedor por função; fallback "" ou provedor válido ≠ primary. Inválido → 422.
     */
    public function searchConfigUpdate(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        if ($r->boolean('clear')) {
            $t->update(['search_lines' => null]);

            return response()->json(['ok' => true, 'cleared' => true, 'lines' => $t->fresh()->searchLines()]);
        }

        $providers = Tenant::SEARCH_PROVIDERS;
        $lines = [];
        foreach ($providers as $fn => $valid) {
            $in = (array) $r->input("lines.$fn", $r->input($fn, []));
            $primary = trim((string) ($in['primary'] ?? ''));
            $fallback = trim((string) ($in['fallback'] ?? ''));

            if ($primary === '' || ! in_array($primary, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor principal inválido para '$fn' (permitidos: ".implode(', ', $valid).').',
                ], 422);
            }
            if ($fallback !== '' && ! in_array($fallback, $valid, true)) {
                return response()->json([
                    'ok' => false,
                    'error' => "Provedor de fallback inválido para '$fn' (permitidos: ".implode(', ', $valid).' ou vazio).',
                ], 422);
            }
            if ($fallback !== '' && $fallback === $primary) {
                return response()->json([
                    'ok' => false,
                    'error' => "O fallback de '$fn' não pode ser igual ao principal.",
                ], 422);
            }
            $lines[$fn] = ['primary' => $primary, 'fallback' => $fallback];
        }

        $t->update(['search_lines' => $lines]);

        return response()->json(['ok' => true, 'lines' => $lines]);
    }

    /**
     * POST /api/studio/search-test { provider, key? } → testa uma chave de provedor de pesquisa.
     * A key efetiva = a enviada no body OU a salva do tenant (BYOK) OU a do sistema (no engine).
     * Repassa o veredito do engine ({ ok, latency_ms, error }).
     */
    public function searchTest(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $provider = trim((string) $r->input('provider'));
        $valid = ['tavily', 'brave', 'jina', 'scrapecreators'];
        if (! in_array($provider, $valid, true)) {
            return response()->json(['ok' => false, 'error' => 'provedor inválido'], 422);
        }

        $payload = ['provider' => $provider];
        // Precedência: key do body > key salva do tenant > (engine usa a do sistema).
        $key = trim((string) $r->input('key', ''));
        if ($key === '') {
            $key = trim((string) (((array) ($t->search_keys ?? []))[$provider] ?? ''));
        }
        if ($key !== '') {
            $payload['key'] = $key;
        }

        $res = $this->engine()->post('/v1/search-test', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'teste de chave falhou'], 502);
        }

        return response()->json([
            'ok' => (bool) $res->json('ok'),
            'latency_ms' => $res->json('latency_ms'),
            'error' => $res->json('error'),
        ]);
    }

    /** POST /api/studio/imageprompt { draftId } → sugere descrição de imagem a partir do resumo. */
    public function imagePrompt(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $summary = $d->research['summary'] ?? $d->research['brief'] ?? '';
        if (trim((string) $summary) === '') {
            return response()->json(['ok' => false, 'error' => 'Sem resumo de pesquisa neste rascunho.'], 400);
        }
        $res = $this->engine()->post('/v1/imageprompt', ['keyword' => $d->keyword, 'summary' => $summary]);

        return response()->json(['ok' => true, 'prompt' => (string) $res->json('prompt')]);
    }

    /**
     * POST /api/studio/mediaprompts { draftId, kind?, aspect?, duration? } → sugere o(s) prompt(s)
     * de mídia JÁ condizentes com o tipo/tamanho/duração escolhidos. kind="image"|"video" gera só
     * o do tipo pedido; vazio = ambos (retrocompat). O prompt de vídeo é orientado a movimento/câmera.
     * Retrocompat: também devolve `prompt` (= imagePrompt) para consumidores antigos.
     */
    public function mediaPrompts(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $summary = $d->research['summary'] ?? $d->research['brief'] ?? '';
        if (trim((string) $summary) === '') {
            return response()->json(['ok' => false, 'error' => 'Sem resumo de pesquisa neste rascunho.'], 400);
        }
        // Textos das publicações priorizados por rank (aderência ao resumo) desc → o engine
        // combina resumo + textos, dando mais peso aos posts mais aderentes ao resumo.
        $meta = $d->texts_meta ?? [];
        $ordered = collect($d->texts ?? [])
            ->filter(fn ($t) => trim((string) $t) !== '')
            ->sortByDesc(fn ($t, $p) => (float) ($meta[$p]['rank'] ?? 0))
            ->values()->all();
        // tipo/tamanho/duração: o prompt é gerado só DEPOIS de definidos, p/ nascer condizente.
        $kind = in_array($r->input('kind'), ['image', 'video'], true) ? $r->input('kind') : '';
        $aspect = (string) $r->input('aspect', '');
        $duration = (string) $r->input('duration', '');
        $res = $this->engine()->post('/v1/mediaprompts', [
            'keyword' => $d->keyword, 'summary' => $summary, 'texts' => $ordered,
            'kind' => $kind, 'aspect' => $aspect, 'duration' => $duration,
        ]);
        $imagePrompt = (string) $res->json('imagePrompt');
        $videoPrompt = (string) $res->json('videoPrompt');

        return response()->json([
            'ok' => true,
            'imagePrompt' => $imagePrompt,
            'videoPrompt' => $videoPrompt,
            'prompt' => $imagePrompt, // retrocompat
        ]);
    }

    /** GET /api/studio/voices → vozes de narração disponíveis (da conta do provedor de voz). */
    public function voices(): JsonResponse
    {
        $res = $this->engine()->get('/v1/voices');
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível listar as vozes agora', 'voices' => []]);
        }

        return response()->json(['ok' => true, 'voices' => (array) $res->json('voices')]);
    }

    /**
     * 🎙️ IDs das vozes que o provedor lista (allowlist do seletor de narrador), em cache curto.
     *
     * Por que cache: a allowlist é consultada em TODA geração com voz (media/vox) e a lista muda
     * de mês em mês — uma ida ao provedor por clique só somaria latência ao caminho caro.
     *
     * null = não deu pra saber (engine/provedor fora do ar). É diferente de "lista vazia": com
     * null quem valida FALHA-ABERTO (aceita o id no formato certo), senão uma indisponibilidade
     * momentânea do provedor derrubaria toda geração narrada — Cache::remember trata null como
     * miss, então a próxima chamada tenta de novo.
     *
     * @return array<int,string>|null
     */
    private function voiceIdsDisponiveis(): ?array
    {
        return Cache::remember('studio:voice_ids', 300, function (): ?array {
            $res = $this->engine()->get('/v1/voices');
            if (! $res->successful()) {
                return null;
            }
            $ids = [];
            foreach ((array) $res->json('voices') as $v) {
                $id = trim((string) (is_array($v) ? ($v['id'] ?? '') : ''));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }

            return $ids ?: null;
        });
    }

    /**
     * 🎙️ VOZ da narração pedida no corpo, VALIDADA — ou `false` quando o id não presta.
     *
     * Regra: vazio (ou igual à do tenant) = a voz do tenant, o "padrão da conta". Id diferente só
     * passa se estiver na allowlist do provedor (voiceIdsDisponiveis) — o corpo vem do navegador e
     * um id qualquer viraria uma voz aleatória (ou um 4xx do provedor no meio do job, minutos
     * depois de cobrar). Formato conferido antes, pra nem ir ao provedor com lixo.
     *
     * @return string|false
     */
    private function voiceIdEscolhida(Request $r, Tenant $t)
    {
        $padrao = (string) ($t->voice_id ?? '');
        $pedida = trim((string) $r->input('voice_id', ''));
        if ($pedida === '' || $pedida === $padrao) {
            return $padrao;
        }
        // A voz clonada do tenant pode NÃO estar na lista pública do provedor — por isso ela é
        // comparada antes, e só o resto passa pela allowlist.
        if (! preg_match('/^[A-Za-z0-9_-]{8,64}$/', $pedida)) {
            return false;
        }
        $ids = $this->voiceIdsDisponiveis();
        if ($ids === null) {
            return $pedida; // falha-aberto: provedor fora do ar não pode travar a geração
        }

        return in_array($pedida, $ids, true) ? $pedida : false;
    }

    /** PATCH /api/studio/research { draftId, summary } → edita o resumo de referência. */
    public function researchUpdate(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $research = $d->research ?? [];
        $research['summary'] = (string) $r->input('summary');
        $d->update(['research' => $research]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/studio/draft?id= → estado do rascunho. */
    public function show(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->query('id'));

        return response()->json(['ok' => true, 'draft' => [
            'id' => $d->id,
            'keyword' => $d->keyword,
            'research' => $d->research,
            'texts' => $d->texts ?? (object) [],
            'texts_meta' => $d->texts_meta ?? (object) [],
            'media' => $d->media ?? [],
            'image_prompt' => $d->image_prompt,
            'image_url' => $d->image_url,
            'video_url' => $d->video_url,
            'status' => $d->status,
            'story' => $d->story,
            'film' => $d->film, // FILME contínuo (aba /filme) — polling do plano/keyframes/clipes
            'carousel' => $d->carousel, // 🎠 plano + estado de render dos slides (polling da aba Mídia)
        ]]);
    }

    /**
     * POST /api/studio/story { theme, character?, scenes? } → gera história de stickman em N cenas
     * (image/video prompt + voiceover por cena) via engine /v1/story; persiste no rascunho.
     * `scenes` opcional (clamp 3..20; ausente = default do engine). Sem limite fixo de 10.
     * Cria um rascunho na hora se não houver (mídia/história funcionam sem pesquisa prévia).
     */
    public function story(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $theme = trim((string) $r->input('theme'));
        if ($theme === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o tema/ideia da história'], 422);
        }
        $character = trim((string) $r->input('character'));
        // TÍTULO (opcional): nome da história. Se o operador não escolher, a IA escolhe no roteiro
        // (mesmo padrão do Estúdio de Animação/Movies) — persiste entre regenerações se já houver um.
        $title = mb_substr(trim((string) $r->input('title', '')), 0, 120);
        // ROTEIRISTA (opcional): craft/voz do roteirista escolhido (aba Prompts → "🎬 Roteirista: ...").
        // Substitui a craft-intro padrão no engine, mantendo estrutura+lock+JSON. Cap de segurança.
        $persona = self::textPersona($r, $t); // 🗣️ Voz da Marca + roteirista/diretor do request
        // Cenário BASE (opcional): mundo/ambiente compartilhado por todas as cenas. O override por
        // cena vive em scenes[].scenario (autosave). Cap de tamanho aqui; o engine também clampa.
        $scenario = mb_substr(trim((string) $r->input('scenario')), 0, 600);
        // idioma da narração/cenas: 'pt-BR' ou 'en-US'; inválido/ausente → padrão da conta (tenant) ou pt-BR.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = $t->content_lang ?? 'pt-BR';
        }
        // Nº de cenas: clamp 3..50 (alinhado ao engine; 0/ausente = default 8). Teto alto (sem limite
        // prático); acima de ~18 o engine trunca a geração e completa via top-up.
        $sceneCount = (int) $r->input('scenes', 0);
        if ($sceneCount > 0) {
            $sceneCount = max(3, min(50, $sceneCount));
        }

        $d = $r->input('draftId') ? $this->draft($r, $r->input('draftId')) : null;
        if (! $d) {
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($title !== '' ? $title : $theme, 0, 80)]);
        }

        // Teto MENSAL de histórias (custo alto: N cenas com mídia) — Studio = 8/mês. A cota é
        // 1 unidade por HISTÓRIA (independe do nº de cenas); o gasto por cena é gateado pelos
        // buckets image/video/short na geração de cada mídia (anti denial-of-wallet).
        // RESERVE-THEN-CONSUME: reserva atômica antes de gerar; estorna se a geração falhar.
        if (! $this->usage->tryConsume($t, 'story', 1)) {
            return response()->json([
                'ok' => false,
                'error' => 'story_quota_exceeded',
                'message' => 'Você atingiu o limite de histórias do mês ('.($t->limits()['story'] ?? 0).'). O limite renova no início do próximo mês.',
            ], 402);
        }

        // Geração ASSÍNCRONA (etapa 2): o roteiro vem de reasoning model (M3) e em histórias longas
        // leva ~1-2min — feito no request, prendia o browser/proxy. Marcamos o rascunho como
        // 'generating' (preservando cenas atuais, elenco e ref do personagem) e DISPARAMOS o job;
        // o front faz polling do rascunho até story.status sair de 'generating'.
        $prev = is_array($d->story) ? $d->story : [];
        // Sem título novo no request → mantém o que já existia (ex.: o que a IA escolheu antes),
        // pra não perder o nome da história numa regeneração de seção.
        if ($title === '' && ! empty($prev['title'])) {
            $title = (string) $prev['title'];
        }
        $story = ['theme' => $theme, 'character' => $character, 'scenario' => $scenario, 'lang' => $lang, 'persona' => $persona, 'title' => $title, 'status' => 'generating'];
        if (! empty($prev['scenes'])) {
            $story['scenes'] = $prev['scenes']; // não some a história da tela durante a regeneração
        }
        if (! empty($prev['character_ref'])) {
            $story['character_ref'] = $prev['character_ref'];
        }
        if (! empty($prev['cast'])) {
            $story['cast'] = $prev['cast'];
        }
        if (! empty($prev['scenario_ref'])) {
            $story['scenario_ref'] = $prev['scenario_ref']; // imagem-âncora do cenário base
        }
        // S2: espinha dramática aprovada (passo 1). Vem do request OU foi guardada no draft.story.
        $structure = self::sanitizeStructure($r->input('structure') ?? ($prev['structure'] ?? null));
        if ($structure) {
            $story['structure'] = $structure; // preserva pra reexibir/regerar
            $d->update(['story' => $story]);
        }

        // ROTEIRO cobrado por MODELO (seletor textModel; default Equilibrado); o job estorna se falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        GenerateStoryJob::dispatch($d->id, $t->id, $theme, $character, $scenario, $lang, $sceneCount, $persona, $structure, $tm?->provider_model_id, $tm?->cost_credits, $title);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'status' => 'generating']);
    }

    /** POST /api/studio/ideas { niche, mode?, month?, count?, lang? } → 💡 F1 da Fábrica de Conteúdo:
     *  GERADOR DE IDEIAS. nicho → banco de ideias de vídeo viral (título + gatilho + formato +
     *  dificuldade + nota). Texto puro, síncrono; cobra 1 crédito de texto por modelo (estornado
     *  se o engine falhar). As ideias são efêmeras — o front exibe e o usuário escolhe uma pra cair
     *  no roteiro pré-preenchido (não persiste rascunho aqui). */
    public function ideas(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $niche = trim((string) $r->input('niche'));
        if ($niche === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o nicho/tema do seu canal'], 422);
        }
        $mode = in_array($r->input('mode'), ['sazonal', 'dor', 'desbloqueio', 'maluca'], true) ? (string) $r->input('mode') : '';
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        $count = max(8, min(30, (int) $r->input('count', 24)));
        // Cobrado por MODELO de texto (seletor; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->post('/v1/ideas', [
            'niche' => $niche,
            'mode' => $mode,
            'month' => mb_substr(trim((string) $r->input('month')), 0, 40),
            'lang' => $lang,
            'count' => $count,
            'persona' => self::textPersona($r, $t), // roteirista escolhido (aba Prompts), se houver
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'não foi possível gerar ideias agora'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);

        return response()->json(['ok' => true, 'ideas' => self::sanitizeIdeas($res->json('ideas')), 'mode' => $mode]);
    }

    /** Sanitiza o banco de ideias do engine: allowlist de campos, strings curtas, score 0..10,
     *  dificuldade normalizada, até 40 itens. Defesa contra output inesperado do modelo. */
    public static function sanitizeIdeas($ideas): array
    {
        if (! is_array($ideas)) {
            return [];
        }
        $out = [];
        foreach (array_slice($ideas, 0, 40) as $it) {
            if (! is_array($it)) {
                continue;
            }
            $title = mb_substr(trim((string) ($it['title'] ?? '')), 0, 140);
            if ($title === '') {
                continue;
            }
            $diff = mb_strtolower(trim((string) ($it['difficulty'] ?? '')));
            $diff = in_array($diff, ['fácil', 'facil', 'médio', 'medio', 'difícil', 'dificil'], true) ? $diff : 'médio';
            $out[] = [
                'title' => $title,
                'trigger' => mb_substr(trim((string) ($it['trigger'] ?? '')), 0, 120),
                'format' => mb_substr(trim((string) ($it['format'] ?? '')), 0, 60),
                'difficulty' => $diff,
                'score' => max(0, min(10, (int) ($it['score'] ?? 0))),
                'why' => mb_substr(trim((string) ($it['why'] ?? '')), 0, 240),
            ];
        }

        return $out;
    }

    /** POST /api/studio/optimize { topic, platform?, lang? } → 🚀 F4 da Fábrica de Conteúdo: PACOTE DE
     *  OTIMIZAÇÃO. tema/título + rede → 5 títulos com nota, descrição SEO, hashtags, tags (YouTube)
     *  e conceitos de thumbnail. Texto puro; cobra 1 crédito de texto por modelo (estorno em falha). */
    public function optimize(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $topic = trim((string) $r->input('topic'));
        if ($topic === '') {
            return response()->json(['ok' => false, 'error' => 'informe o tema ou título do vídeo'], 422);
        }
        $platform = in_array($r->input('platform'), Networks::keys(), true) ? (string) $r->input('platform') : 'youtube';
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        // Cobrado por MODELO de texto (seletor; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->post('/v1/optimize', [
            'topic' => $topic,
            'platform' => $platform,
            'lang' => $lang,
            'persona' => self::textPersona($r, $t),
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'não foi possível gerar o pacote agora'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);

        return response()->json(['ok' => true, 'platform' => $platform, 'pack' => self::sanitizePack($res->json(), $platform)]);
    }

    /** Sanitiza o pacote de otimização do engine: allowlist, limites de tamanho, score 0..10, e
     *  até 5 títulos / 10 hashtags / 20 tags / 3 thumbnails. Defesa contra output inesperado. */
    public static function sanitizePack($p, string $platform): array
    {
        $p = is_array($p) ? $p : [];
        $strs = function ($arr, int $max, int $len): array {
            $out = [];
            foreach (array_slice(is_array($arr) ? $arr : [], 0, $max) as $s) {
                $s = mb_substr(trim((string) $s), 0, $len);
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return $out;
        };
        $titles = [];
        foreach (array_slice(is_array($p['titles'] ?? null) ? $p['titles'] : [], 0, 5) as $it) {
            if (! is_array($it)) {
                continue;
            }
            $text = mb_substr(trim((string) ($it['text'] ?? '')), 0, 120);
            if ($text === '') {
                continue;
            }
            $titles[] = ['text' => $text, 'score' => max(0, min(10, (int) ($it['score'] ?? 0)))];
        }
        $thumbs = [];
        foreach (array_slice(is_array($p['thumbnails'] ?? null) ? $p['thumbnails'] : [], 0, 3) as $tc) {
            if (! is_array($tc)) {
                continue;
            }
            $concept = mb_substr(trim((string) ($tc['concept'] ?? '')), 0, 300);
            if ($concept === '') {
                continue;
            }
            $thumbs[] = [
                'concept' => $concept,
                'text' => mb_substr(trim((string) ($tc['text'] ?? '')), 0, 40),
                'colors' => mb_substr(trim((string) ($tc['colors'] ?? '')), 0, 80),
            ];
        }

        return [
            'platform' => $platform,
            'titles' => $titles,
            'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 2000),
            'hashtags' => $strs($p['hashtags'] ?? null, 10, 40),
            'tags' => $platform === 'youtube' ? $strs($p['tags'] ?? null, 20, 40) : [],
            'thumbnails' => $thumbs,
        ];
    }

    /** POST /api/studio/repurpose { source, lang? } → ♻️ F6 da Fábrica de Conteúdo: TRANSFORMAÇÃO DE FORMATO.
     *  conteúdo longo (roteiro/transcrição/tema) → 5 shorts + carrossel + thread + pin. Texto puro;
     *  cobra 1 crédito de texto por modelo (estorno em falha). */
    public function repurpose(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $source = trim((string) $r->input('source'));
        if (mb_strlen($source) < 30) {
            return response()->json(['ok' => false, 'error' => 'cole o conteúdo longo (roteiro, transcrição ou tema detalhado) — pelo menos algumas frases'], 422);
        }
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        // Cobrado por MODELO de texto (seletor; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->post('/v1/repurpose', [
            'source' => mb_substr($source, 0, 8000),
            'lang' => $lang,
            'persona' => self::textPersona($r, $t),
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'não foi possível reaproveitar o conteúdo agora'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);

        return response()->json(['ok' => true, 'pack' => self::sanitizeRepurpose($res->json())]);
    }

    /** Sanitiza o pacote de reaproveitamento: allowlist, limites, e até 5 shorts / 10 slides /
     *  10 tweets. Defesa contra output inesperado do modelo. */
    public static function sanitizeRepurpose($p): array
    {
        $p = is_array($p) ? $p : [];
        $strs = function ($arr, int $max, int $len): array {
            $out = [];
            foreach (array_slice(is_array($arr) ? $arr : [], 0, $max) as $s) {
                $s = mb_substr(trim((string) $s), 0, $len);
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return $out;
        };
        $shorts = [];
        foreach (array_slice(is_array($p['shorts'] ?? null) ? $p['shorts'] : [], 0, 5) as $sc) {
            if (! is_array($sc)) {
                continue;
            }
            $title = mb_substr(trim((string) ($sc['title'] ?? '')), 0, 120);
            if ($title === '') {
                continue;
            }
            $shorts[] = [
                'title' => $title,
                'timestamp' => mb_substr(trim((string) ($sc['timestamp'] ?? '')), 0, 120),
                'hook' => mb_substr(trim((string) ($sc['hook'] ?? '')), 0, 200),
            ];
        }
        $carousel = is_array($p['carousel'] ?? null) ? $p['carousel'] : [];

        return [
            'shorts' => $shorts,
            'carousel' => [
                'title' => mb_substr(trim((string) ($carousel['title'] ?? '')), 0, 120),
                'slides' => $strs($carousel['slides'] ?? null, 10, 200),
            ],
            'thread' => $strs($p['thread'] ?? null, 10, 280),
            'pin' => mb_substr(trim((string) ($p['pin'] ?? '')), 0, 500),
        ];
    }

    /** POST /api/studio/calendar { niche, mode?, days? } → 🗓️ F5 da Fábrica de Conteúdo: CALENDÁRIO EDITORIAL
     *  + SÉRIES. nicho → plano de 30 dias (mode "mes") ou série de N episódios (mode "serie"). Texto
     *  puro; cobra 1 crédito de texto por modelo (estorno em falha). */
    public function calendar(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $niche = trim((string) $r->input('niche'));
        if ($niche === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o nicho/tema do seu canal'], 422);
        }
        $mode = $r->input('mode') === 'serie' ? 'serie' : 'mes';
        $days = (int) $r->input('days', $mode === 'serie' ? 10 : 30);
        $days = $mode === 'serie' ? max(3, min(20, $days)) : max(7, min(30, $days));
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        // Cobrado por MODELO de texto (seletor; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->post('/v1/calendar', [
            'niche' => $niche,
            'mode' => $mode,
            'days' => $days,
            'lang' => $lang,
            'persona' => self::textPersona($r, $t),
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'não foi possível montar o plano agora'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);

        return response()->json(['ok' => true, 'plan' => self::sanitizePlan($res->json(), $mode)]);
    }

    /** Sanitiza o plano editorial (mode "mes" = calendário; "serie" = episódios): allowlist,
     *  limites, prioridade normalizada, até 31 dias / 20 episódios. */
    public static function sanitizePlan($p, string $mode): array
    {
        $p = is_array($p) ? $p : [];
        if ($mode === 'serie') {
            $s = is_array($p['series'] ?? null) ? $p['series'] : [];
            $eps = [];
            foreach (array_slice(is_array($s['episodes'] ?? null) ? $s['episodes'] : [], 0, 20) as $i => $ep) {
                if (! is_array($ep)) {
                    continue;
                }
                $title = mb_substr(trim((string) ($ep['title'] ?? '')), 0, 140);
                if ($title === '') {
                    continue;
                }
                $eps[] = [
                    'ep' => (int) ($ep['ep'] ?? ($i + 1)),
                    'title' => $title,
                    'hook' => mb_substr(trim((string) ($ep['hook'] ?? '')), 0, 200),
                    'difficulty' => mb_substr(trim((string) ($ep['difficulty'] ?? '')), 0, 20),
                ];
            }

            return ['mode' => 'serie', 'series' => [
                'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 140),
                'episodes' => $eps,
                'teaser' => mb_substr(trim((string) ($s['teaser'] ?? '')), 0, 500),
            ]];
        }
        $days = [];
        foreach (array_slice(is_array($p['days'] ?? null) ? $p['days'] : [], 0, 31) as $i => $d) {
            if (! is_array($d)) {
                continue;
            }
            $prio = mb_strtolower(trim((string) ($d['priority'] ?? '')));
            $prio = in_array($prio, ['alta', 'média', 'media', 'baixa'], true) ? $prio : 'média';
            $days[] = [
                'day' => (int) ($d['day'] ?? ($i + 1)),
                'title' => mb_substr(trim((string) ($d['title'] ?? '')), 0, 140),
                'format' => mb_substr(trim((string) ($d['format'] ?? '')), 0, 60),
                'priority' => $prio,
                'rest' => (bool) ($d['rest'] ?? false),
            ];
        }

        return ['mode' => 'mes', 'days' => $days];
    }

    /** POST /api/studio/story-structure { draftId?, theme, pace? } → 🎬 SALA DE ROTEIRO passo 1 (S2):
     *  destila o tema numa ESPINHA dramática (logline, pergunta, atos, viradas) como dados editáveis.
     *  Texto puro (sem cota de mídia; gate de assinatura). O passo 2 (gerar história) recebe a
     *  estrutura aprovada. Grava em draft.story.structure. */
    public function storyStructure(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $theme = trim((string) $r->input('theme'));
        if ($theme === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o tema/ideia da história'], 422);
        }
        $d = $r->input('draftId') ? $this->draft($r, $r->input('draftId')) : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($theme, 0, 80)]);
        $lang = in_array($r->input('lang'), ['pt-BR', 'en-US'], true) ? (string) $r->input('lang') : ($t->content_lang ?? 'pt-BR');
        // O engine SEMPRE aceitou `gen_lines` em /v1/storystructure, mas o console nunca enviava:
        // a espinha dramática caía no modelo default do engine e a escolha do seletor de texto da
        // UI não valia nesta etapa (enquanto valia no resto). Mesmo padrão dos demais call sites.
        $tm = $this->textModelFor($r, $t->plan);
        $res = $this->engine()->post('/v1/storystructure', [
            'theme' => $theme,
            'lang' => $lang,
            'persona' => self::textPersona($r, $t), // roteirista + ritmo (compõe)
            'gen_lines' => $this->textGenLines($tm),
        ]);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível montar a estrutura agora'], 502);
        }
        $structure = self::sanitizeStructure($res->json());
        $story = is_array($d->story) ? $d->story : [];
        $story['structure'] = $structure;
        $story['theme'] = $theme;
        $d->update(['story' => $story, 'keyword' => mb_substr($theme, 0, 80)]);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'structure' => $structure]);
    }

    /** Sanitiza a ESPINHA dramática (S2): allowlist logline/dramatic_question/acts[]/turning_points[],
     *  strings curtas (vem do engine E do request de regeneração). Vazio/inválido = null. */
    public static function sanitizeStructure($st): ?array
    {
        if (! is_array($st)) {
            return null;
        }
        $out = [
            'logline' => mb_substr(trim((string) ($st['logline'] ?? '')), 0, 400),
            'dramatic_question' => mb_substr(trim((string) ($st['dramatic_question'] ?? '')), 0, 400),
            'acts' => [],
            'turning_points' => [],
        ];
        foreach (array_slice((array) ($st['acts'] ?? []), 0, 6) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $out['acts'][] = [
                'name' => mb_substr(trim((string) ($a['name'] ?? '')), 0, 80),
                'summary' => mb_substr(trim((string) ($a['summary'] ?? '')), 0, 400),
                'charge' => in_array(trim((string) ($a['charge'] ?? '')), ['+', '-'], true) ? trim((string) $a['charge']) : '',
            ];
        }
        foreach (array_slice((array) ($st['turning_points'] ?? []), 0, 8) as $tp) {
            if (($v = mb_substr(trim((string) $tp), 0, 200)) !== '') {
                $out['turning_points'][] = $v;
            }
        }
        if ($out['logline'] === '' && $out['acts'] === []) {
            return null;
        }

        return $out;
    }

    /** PATCH /api/studio/story-scenes { draftId, scenes } → salva as cenas editadas (voiceover,
     *  image_url, audio_url) no rascunho. Autosave do gerador de Histórias — NÃO gasta crédito de
     *  IA. URLs de mídia só são aceitas se forem do nosso storage (anti-SSRF/spoof). */
    public function storyScenes(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $incoming = (array) $r->input('scenes', []);
        // MERGE só dos campos de TEXTO, sob LOCK. As URLs de mídia (image_url/video_url/audio_url)
        // são gravadas exclusivamente pelos caminhos atômicos do servidor (media/storyClip/storyAudio);
        // este autosave NUNCA as toca — assim o front não apaga um vídeo/imagem recém-gerado por
        // outra requisição (lost-update). O lock evita corrida com os jobs que escrevem as URLs.
        DB::transaction(function () use ($d, $incoming) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $existing = $story['scenes'] ?? [];
            $scenes = [];
            foreach ($incoming as $idx => $sc) {
                if (! is_array($sc)) {
                    continue;
                }
                $base = $existing[$idx] ?? [];
                $scenes[$idx] = array_merge($base, [
                    'title' => (string) ($sc['title'] ?? ($base['title'] ?? '')),
                    'image_prompt' => (string) ($sc['image_prompt'] ?? ($base['image_prompt'] ?? '')),
                    'video_prompt' => (string) ($sc['video_prompt'] ?? ($base['video_prompt'] ?? '')),
                    'voiceover' => (string) ($sc['voiceover'] ?? ($base['voiceover'] ?? '')),
                    // scenario = override de CENÁRIO desta cena (vazio = usa o cenário base da história).
                    'scenario' => mb_substr((string) ($sc['scenario'] ?? ($base['scenario'] ?? '')), 0, 600),
                    // use_base = incluir a base (Cena de Referência) nesta cena (default true).
                    'use_base' => array_key_exists('use_base', $sc) ? (bool) $sc['use_base'] : ($base['use_base'] ?? true),
                    // spec = FICHA DE CENA (S1): plano/movimento/luz/emoção. Sanitizado (allowlist);
                    // ausente no request = mantém o que veio da geração (preservado no $base).
                    'spec' => array_key_exists('spec', $sc) ? self::sanitizeSceneSpec($sc['spec']) : ($base['spec'] ?? null),
                    // approved = cena TRAVADA (S4): o front confirma antes de regerar/limpar a mídia.
                    'approved' => array_key_exists('approved', $sc) ? (bool) $sc['approved'] : ($base['approved'] ?? false),
                ]); // array_merge preserva image_url/video_url/audio_url/refs já no $base
            }
            $story['scenes'] = array_values($scenes);
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/story-reference (multipart: file, draftId?, castIndex?|sceneIndex?) → carrega
     *  uma imagem de REFERÊNCIA; vira base i2i. Destino: cena (scenes[sceneIndex].refs[]) se sceneIndex
     *  dado, senão personagem do elenco (cast[castIndex].ref_url) ou character_ref legado. Sem custo. */
    public function storyReference(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $r->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240']); // AUD-006/AUD-024
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'historia']);
        $file = $r->file('file');
        $ext = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        $url = self::storeUploadedFile($file, $ext, 'image');
        $this->applyRef($r, $d->id, $url);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'url' => $url]);
    }

    /** POST /api/studio/story-reference-url { draftId?, url, castIndex?|sceneIndex? } → usa uma imagem
     *  JÁ existente (da galeria, nosso storage) como referência. Sem upload e sem custo de IA. */
    public function storyReferenceUrl(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $url = (string) $r->input('url', '');
        if ($url === '' || ! self::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'historia']);
        $this->applyRef($r, $d->id, $url);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'url' => $url]);
    }

    /** POST /api/studio/story-scene-image-set { draftId, index, url } → FIXA a imagem da cena
     *  numa mídia da galeria (troca direta, sem custo — paridade com o 📌 do Filme). O autosave
     *  NUNCA toca URLs de mídia; por isso este endpoint atômico próprio (own-media + lock). */
    public function storySceneImageSet(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $url = (string) $r->input('url', '');
        if ($url === '' || ! self::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422); // anti-SSRF
        }
        DB::transaction(function () use ($d, $i, $url) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$i])) {
                return;
            }
            $scenes[$i]['image_url'] = $url;
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'index' => $i, 'url' => $url]);
    }

    /** POST /api/studio/story-scene-video-set { draftId, index, url } → FIXA um vídeo da galeria
     *  como o CLIPE desta cena (troca direta, sem custo — paridade com o fixar de imagem). O join
     *  do Short usa scenes[i].video_url, então o vídeo fixado entra no lugar do i2v. */
    public function storySceneVideoSet(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $url = (string) $r->input('url', '');
        if ($url === '' || ! self::isOwnMediaUrl($url)) {
            return response()->json(['ok' => false, 'error' => 'vídeo inválido'], 422); // anti-SSRF
        }
        DB::transaction(function () use ($d, $i, $url) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$i])) {
                return;
            }
            $scenes[$i]['video_url'] = $url;
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'index' => $i, 'url' => $url]);
    }

    /** Roteia uma URL de referência ao destino certo: refs da CENA (sceneIndex) têm precedência;
     *  senão personagem do ELENCO (castIndex) ou character_ref legado. */
    private function applyRef(Request $r, int $draftId, string $url): void
    {
        // Cenário BASE (imagem-âncora do AMBIENTE, compartilhada por todas as cenas).
        if ($r->boolean('scenarioBase')) {
            $this->applyScenarioRef($draftId, $url);

            return;
        }
        $sceneIndex = $this->sceneIndexOf($r);
        if ($sceneIndex !== null) {
            $this->applySceneRef($draftId, $url, $sceneIndex);

            return;
        }
        $this->applyCharacterRef($draftId, $url, $this->castIndexOf($r));
    }

    /** Grava a imagem-âncora do CENÁRIO BASE (story.scenario_ref) sob lock — usada como ref i2i por
     *  TODAS as cenas (1 slot reservado, junto com até 2 personagens). */
    private function applyScenarioRef(int $draftId, string $url): void
    {
        DB::transaction(function () use ($draftId, $url) {
            $locked = Draft::lockForUpdate()->find($draftId);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $story['scenario_ref'] = $url;
            $locked->update(['story' => $story]);
        });
    }

    /** castIndex (0..2) do request, ou null se ausente/fora do range — direciona a referência a um
     *  personagem do ELENCO (cast[i].ref_url) em vez do character_ref único legado. */
    private function castIndexOf(Request $r): ?int
    {
        if (! $r->has('castIndex')) {
            return null;
        }
        $i = (int) $r->input('castIndex');

        return ($i >= 0 && $i <= 2) ? $i : null;
    }

    /** sceneIndex (>=0) do request, ou null se ausente/negativo — direciona a referência às refs
     *  de uma CENA específica (scenes[i].refs[]) em vez do elenco/character_ref. */
    private function sceneIndexOf(Request $r): ?int
    {
        if (! $r->has('sceneIndex')) {
            return null;
        }
        $i = (int) $r->input('sceneIndex');

        return $i >= 0 ? $i : null;
    }

    /** Grava uma URL de referência no rascunho, sob lock: em cast[castIndex].ref_url quando
     *  castIndex dado (elenco), senão em character_ref (referência única legada). */
    private function applyCharacterRef(int $draftId, string $url, ?int $castIndex): void
    {
        DB::transaction(function () use ($draftId, $url, $castIndex) {
            $locked = Draft::lockForUpdate()->find($draftId);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            if ($castIndex !== null) {
                $cast = $story['cast'] ?? [];
                $cast[$castIndex] = array_merge($cast[$castIndex] ?? [], ['ref_url' => $url]);
                $story['cast'] = array_values($cast);
            } else {
                $story['character_ref'] = $url;
            }
            $locked->update(['story' => $story]);
        });
    }

    /** Anexa uma URL de referência a scenes[sceneIndex].refs[] (cenário/props da cena), sob lock.
     *  Dedup + cap em 3 (limite prático do nano-banana, junto com a base). Cena inexistente = no-op. */
    private function applySceneRef(int $draftId, string $url, int $sceneIndex): void
    {
        DB::transaction(function () use ($draftId, $url, $sceneIndex) {
            $locked = Draft::lockForUpdate()->find($draftId);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$sceneIndex])) {
                return;
            }
            $refs = array_values(array_filter((array) ($scenes[$sceneIndex]['refs'] ?? []), 'is_string'));
            if (! in_array($url, $refs, true)) {
                $refs[] = $url;
            }
            $scenes[$sceneIndex]['refs'] = array_slice($refs, 0, 3);
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });
    }

    /** POST /api/studio/story-scene-ref-remove { draftId, index, url } → remove UMA ref de cenário
     *  de scenes[index].refs (sob lock). Não apaga a mídia da galeria (a ref pode ser reusada). */
    public function storySceneRefRemove(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId')); // valida tenant
        $i = (int) $r->input('index', -1);
        $url = (string) $r->input('url', '');
        $did = $d->id;
        DB::transaction(function () use ($did, $i, $url) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$i])) {
                return;
            }
            $refs = array_values(array_filter((array) ($scenes[$i]['refs'] ?? []), fn ($u) => is_string($u) && $u !== $url));
            $scenes[$i]['refs'] = $refs;
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/story-base-ref-clear { draftId, target: 'scenario'|'cast'|'character', castIndex? }
     *  → limpa a IMAGEM de uma base (cenário, personagem do elenco, ou ref legada) SEM apagar o item
     *  nem a mídia da galeria (a imagem pode ser reusada). Sob lock. Sem custo. */
    public function storyBaseRefClear(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $target = (string) $r->input('target');
        $castIndex = $this->castIndexOf($r);
        $did = $d->id;
        DB::transaction(function () use ($did, $target, $castIndex) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            if ($target === 'scenario') {
                unset($story['scenario_ref']);
            } elseif ($target === 'character') {
                unset($story['character_ref']);
            } elseif ($target === 'cast' && $castIndex !== null) {
                $cast = $story['cast'] ?? [];
                if (isset($cast[$castIndex])) {
                    $cast[$castIndex]['ref_url'] = '';
                    $story['cast'] = array_values($cast);
                }
            }
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/story-scene-add { draftId } → anexa uma CENA em branco ao fim da história,
     *  herdando os prompts de imagem/vídeo da última cena (mantém o padrão global). Sem custo. */
    public function storySceneAdd(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $did = $d->id;
        DB::transaction(function () use ($did) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = array_values($story['scenes'] ?? []);
            $last = end($scenes) ?: [];
            $scenes[] = [
                'title' => 'Nova cena',
                'image_prompt' => (string) ($last['image_prompt'] ?? ''), // herda o padrão (já contém o lock)
                'video_prompt' => (string) ($last['video_prompt'] ?? ''),
                'voiceover' => '',
                'refs' => [],
            ];
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id]);
    }

    /** POST /api/studio/story-scene-remove { draftId, index } → remove scenes[index] e tira da
     *  galeria a mídia gerada dessa cena (image/video/audio). Sob lock. Sem custo. */
    public function storySceneRemove(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $did = $d->id;
        DB::transaction(function () use ($did, $i) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = array_values($story['scenes'] ?? []);
            if (! isset($scenes[$i])) {
                return;
            }
            // URLs de mídia da cena que saem da galeria junto (refs ficam — podem ser reusadas).
            $urls = array_filter([
                (string) ($scenes[$i]['image_url'] ?? ''),
                (string) ($scenes[$i]['video_url'] ?? ''),
                (string) ($scenes[$i]['audio_url'] ?? ''),
            ]);
            array_splice($scenes, $i, 1);
            $story['scenes'] = array_values($scenes);
            $upd = ['story' => $story];
            if ($urls !== []) {
                $upd['media'] = array_values(array_filter($locked->media ?? [], fn ($m) => ! in_array(($m['url'] ?? null), $urls, true)));
            }
            $locked->update($upd);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id]);
    }

    /** POST /api/studio/story-scene-move { draftId, index, dir } → reordena uma cena trocando-a
     *  com a vizinha (dir: up|down). Sob lock. Sem custo. */
    public function storySceneMove(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        $dir = (string) $r->input('dir');
        $j = $dir === 'up' ? $i - 1 : ($dir === 'down' ? $i + 1 : -1);
        $did = $d->id;
        DB::transaction(function () use ($did, $i, $j) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = array_values($story['scenes'] ?? []);
            if (! isset($scenes[$i]) || ! isset($scenes[$j])) {
                return;
            }
            [$scenes[$i], $scenes[$j]] = [$scenes[$j], $scenes[$i]];
            $story['scenes'] = $scenes;
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id]);
    }

    /** PATCH /api/studio/story-cast { draftId, cast:[{name,desc}] } → salva nomes/descrições do
     *  ELENCO (até 3 personagens). Autosave SEM custo. As ref_url são gravadas só pelos caminhos
     *  atômicos (gerar/upload) — este merge as PRESERVA (não deixa o front apagar uma referência). */
    public function storyCast(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $incoming = array_slice((array) $r->input('cast', []), 0, 3);
        DB::transaction(function () use ($d, $incoming) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $existing = $story['cast'] ?? [];
            $cast = [];
            foreach ($incoming as $idx => $c) {
                if (! is_array($c)) {
                    continue;
                }
                $base = $existing[$idx] ?? [];
                $cast[$idx] = [
                    'name' => mb_substr((string) ($c['name'] ?? ($base['name'] ?? '')), 0, 60),
                    'desc' => mb_substr((string) ($c['desc'] ?? ($base['desc'] ?? '')), 0, 600),
                    'ref_url' => (string) ($base['ref_url'] ?? ''), // preservada (gravada por gerar/upload)
                ];
            }
            $story['cast'] = array_values($cast);
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id]);
    }

    /** POST /api/studio/story-cast-generate { draftId, index, desc?, style? } → gera o CHARACTER
     *  SHEET (retrato canônico, fundo neutro) do personagem [index] via t2i e grava cast[index].
     *  ref_url — essa ref vira a âncora i2i das cenas. Consome 1 do bucket 'image'. */
    public function storyCastGenerate(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $i = (int) $r->input('index', -1);
        if ($i < 0 || $i > 2) {
            return response()->json(['ok' => false, 'error' => 'personagem inválido'], 422);
        }
        $story = is_array($d->story) ? $d->story : [];
        $cast = $story['cast'] ?? [];
        $desc = trim((string) ($r->input('desc') ?: ($cast[$i]['desc'] ?? '')));
        if ($desc === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva o personagem antes de gerar a referência.'], 422);
        }
        $style = (string) $r->input('style', 'realista');
        // RESERVE-THEN-CONSUME (AUD-002) bucket 'image'. Estorna se a geração falhar.
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        // Character sheet: UM personagem, fundo neutro, sem texto/props — vira a referência i2i.
        $prompt = 'Character reference sheet of a single character: '.$desc
            .'. Full body, front view, centered, plain neutral light-gray background, soft even lighting, no text, no extra characters, no props.';
        $url = $this->engine()->post('/v1/image', ['prompt' => $prompt, 'aspect' => '3:4', 'style' => $style])->json('url');
        if (! $url) {
            $this->usage->refund($t, 'image', $weight);

            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }
        DB::transaction(function () use ($d, $i, $url, $desc) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $cast = $story['cast'] ?? [];
            $cast[$i] = array_merge($cast[$i] ?? [], ['desc' => $desc, 'ref_url' => $url]);
            $story['cast'] = array_values($cast);
            $locked->update(['story' => $story]);
        });

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'url' => $url]);
    }

    /** POST /api/studio/story-clear-media { draftId, index, kind } → remove a mídia GERADA de uma
     *  cena (kind: image|video|audio): limpa scenes[index].{kind}_url e tira o item da galeria.
     *  Sob lock; sem custo de IA. */
    /** POST /api/studio/story-clear-all-media { draftId } → LIMPA as mídias geradas de TODAS as
     *  cenas (imagem/vídeo/áudio) e tira da galeria os itens intermediários (com `scene`). O
     *  ROTEIRO (títulos/prompts/voiceovers) fica intacto — é o "recomeçar a produção". */
    public function storyClearAllMedia(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        DB::transaction(function () use ($d) {
            $locked = Draft::lockForUpdate()->find($d->id);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            foreach ($scenes as $i => $sc) {
                unset($scenes[$i]['image_url'], $scenes[$i]['video_url'], $scenes[$i]['audio_url']);
            }
            $story['scenes'] = array_values($scenes);
            $media = array_values(array_filter($locked->media ?? [], fn ($m) => ! isset($m['scene'])));
            $locked->update(['story' => $story, 'media' => $media]);
        });

        return response()->json(['ok' => true]);
    }

    public function storyClearMedia(Request $r): JsonResponse
    {
        $kind = (string) $r->input('kind');
        if (! in_array($kind, ['image', 'video', 'audio'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo inválido'], 422);
        }
        $d = $this->draft($r, $r->input('draftId')); // valida tenant
        $i = (int) $r->input('index', -1);
        $did = $d->id;
        DB::transaction(function () use ($did, $i, $kind) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            if (! isset($scenes[$i])) {
                return;
            }
            $field = $kind.'_url';
            $url = (string) ($scenes[$i][$field] ?? '');
            $scenes[$i][$field] = '';
            $story['scenes'] = $scenes;
            $upd = ['story' => $story];
            if ($url !== '') { // tira o item correspondente da galeria
                $upd['media'] = array_values(array_filter($locked->media ?? [], fn ($m) => ($m['url'] ?? null) !== $url));
            }
            $locked->update($upd);
        });

        return response()->json(['ok' => true]);
    }

    /** POST /api/studio/story-audio { draftId, index, voice_id?, quality?, audioStyle? } → gera o
     *  áudio (preview) do voiceover da cena e o anexa à cena. Consome 1 do bucket 'audio' cobrando
     *  o tier do catálogo kind=audio (cota de runtime anti denial-of-wallet, secure-baseline #8). */
    public function storyAudio(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = $story['scenes'] ?? [];
        $i = (int) $r->input('index', -1);
        if (! isset($scenes[$i]) || trim((string) ($scenes[$i]['voiceover'] ?? '')) === '') {
            return response()->json(['ok' => false, 'error' => 'cena sem narração para gerar'], 422);
        }
        // Modelo/qualidade da narração (catálogo kind=audio): tier escolhido = bitrate + preço.
        $am = $this->audioModel($r, $t->plan);
        $aq = $this->videoQuality($am, $r);
        $aCost = ($aq['p'] ?? null) ?? $am?->cost_credits;
        // RESERVE-THEN-CONSUME (AUD-002): o preview de narração também gasta crédito de IA (TTS) — bucket 'audio'.
        if (! $this->usage->tryConsume($t, 'audio', 1, $aCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $voiceId = (string) ($r->input('voice_id') ?: $t->voice_id);
        $lang = (string) ($story['lang'] ?? ($t->content_lang ?? 'pt-BR'));
        $res = $this->engine()->post('/v1/tts', array_merge(['text' => $scenes[$i]['voiceover'], 'voiceId' => $voiceId, 'lang' => $lang], $this->ttsParams($r, $am, $aq)));
        $url = $res->successful() ? (string) $res->json('url') : '';
        if ($url === '') {
            $this->usage->refund($t, 'audio', 1, $aCost); // geração falhou → estorna

            return response()->json(['ok' => false, 'error' => 'geração de narração falhou'], 502);
        }
        // Persiste a URL na cena E anexa o áudio à galeria (kind=audio), atomicamente sob lock —
        // recarrega o rascunho travado para não atropelar mídia/cenas gravadas em paralelo.
        $did = $d->id;
        DB::transaction(function () use ($url, $i, $did) {
            $locked = Draft::lockForUpdate()->find($did);
            if (! $locked) {
                return;
            }
            $story = is_array($locked->story) ? $locked->story : [];
            $scenes = $story['scenes'] ?? [];
            $upd = [];
            if (isset($scenes[$i])) {
                $scenes[$i]['audio_url'] = $url;
                $story['scenes'] = $scenes;
                $upd['story'] = $story;
            }
            $media = $locked->media ?? [];
            if (! collect($media)->contains(fn ($m) => ($m['url'] ?? null) === $url)) {
                $media[] = ['id' => self::mediaId(), 'kind' => 'audio', 'url' => $url, 'style' => 'narracao', 'platforms' => [], 'scene' => $i + 1];
                $upd['media'] = $media;
            }
            if ($upd !== []) {
                $locked->update($upd);
            }
        });

        return response()->json(['ok' => true, 'index' => $i, 'url' => $url]);
    }

    /** POST /api/studio/story-clip { draftId, index, duration? } → gera o VÍDEO (image-to-video)
     *  da cena a partir da imagem JÁ gerada + o video_prompt. ASSÍNCRONO (i2v leva minutos e
     *  estourava o proxy): o worker chama /v1/video (scenes=1, i2v) e grava em scenes[index].
     *  video_url; o front faz polling do rascunho. Consome 1 do bucket 'video'. */
    public function storyClip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = $story['scenes'] ?? [];
        $i = (int) $r->input('index', -1);
        if (! isset($scenes[$i])) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        $img = (string) ($scenes[$i]['image_url'] ?? '');
        if ($img === '' || ! self::isOwnMediaUrl($img)) {
            return response()->json(['ok' => false, 'error' => 'Gere a imagem da cena antes de animar o vídeo.'], 422);
        }
        $duration = in_array($r->input('duration'), ['5', '10'], true) ? (string) $r->input('duration') : '5';
        // Modelo de vídeo escolhido (opcional) — clipe i2v de UMA cena. Só modelos que o engine
        // roteia: o premium (Veo) é fluxo à parte e não faz sentido por-cena (custo alto × N
        // cenas). Resolve server-side. (Exigia provider==='kie' até 2026-08-04 — sem nenhum kie
        // ativo, animar cena de história com modelo escolhido caía sempre neste 422.)
        $vm = GenModel::resolveSelectable($r->input('model'), 'video', $t->plan);
        if ($r->filled('model') && (! $vm || ! $vm->isClipCapable())) {
            return response()->json(['ok' => false, 'error' => 'Para cenas de história, escolha um modelo de vídeo padrão.'], 422);
        }
        // Qualidade (resolução) escolhida (v2): preço por duração (p5/p10) quando o modelo tem tiers;
        // sem tiers (modelo legado) → cost_credits fixo do modelo, como antes.
        $q = $this->videoQuality($vm, $r);
        $cost = $q
            ? ((($duration === '10' ? ($q['p10'] ?? null) : ($q['p5'] ?? null))) ?? $vm?->cost_credits)
            : $vm?->cost_credits; // null = custo fixo por tipo (fallback)
        // RESERVE-THEN-CONSUME (AUD-002) bucket 'video' (clipe i2v), cobrando o custo do MODELO+tier.
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        // SÓ o video_prompt (movimento da cena). NUNCA cair pro image_prompt — ele é a descrição
        // ESTÁTICA do personagem (o "boneco cinza"), e usá-lo fazia o vídeo virar o personagem em
        // vez de animar a cena. Vazio = o engine anima a imagem (i2v) com movimento natural sutil.
        $prompt = (string) ($scenes[$i]['video_prompt'] ?? '');
        // CENÁRIO efetivo da cena = override da cena OU o cenário BASE da história (reforça o
        // ambiente na geração do vídeo, não só no texto do roteiro).
        $sceneScenario = trim((string) ($scenes[$i]['scenario'] ?? '')) ?: trim((string) ($story['scenario'] ?? ''));
        if ($sceneScenario !== '') {
            $prompt = 'Scene setting / scenario: '.$sceneScenario.'. '.$prompt;
        }
        $aspect = $r->input('aspect') === '16:9' ? '16:9' : '9:16'; // formato Short (9:16) | Normal (16:9)
        // Estilo PRÓPRIO do clipe (videoStyleDirective do engine, allowlist). 'realista' = padrão
        // histórico da Histórias (sem diretiva — anima a imagem ao natural).
        $vStyles = ['realista', 'cinematografico', 'dinamico', 'documental', 'timelapse', 'anime', '3d', 'noir', 'vintage', 'aereo', 'slowmotion', 'cyberpunk', 'vlog'];
        $vStyle = in_array($r->input('videoStyle'), $vStyles, true) ? (string) $r->input('videoStyle') : 'realista';
        $payload = [
            'prompt' => $prompt,
            'imageUrl' => $img,   // i2v: anima a imagem JÁ gerada da cena
            'scenes' => 1,        // 1 clipe (atalho i2v direto no engine)
            'duration' => $duration,
            'aspect' => $aspect,
            'style' => $vStyle,
        ];
        // modelo escolhido → gen_lines.video (KIE schema-driven, ver videoGenLine), com o `extra`
        // do tier de qualidade mesclado no spec (resolução/mode).
        if ($vm) {
            $payload['gen_lines'] = $this->videoGenLine($vm, $q);
        }
        GenerateStoryClipJob::dispatch($d->id, $t->id, $i, $payload, 'video', 1, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Vídeo da cena em geração — aparece em alguns minutos.']);
    }

    /** POST /api/studio/story-image { draftId, index, prompt?, imageUrls?, aspect?, style? } → gera a
     *  IMAGEM de UMA cena de forma ASSÍNCRONA (job grava story.scenes[index].image_url; o front faz
     *  polling). i2i (nano-banana com refs) leva ~2min e, síncrono, estourava o proxy (HTTP 499).
     *  Consome 1 do bucket 'image'. As refs (imageUrls) são validadas como mídia NOSSA (anti-SSRF). */
    public function storyImage(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = $story['scenes'] ?? [];
        $i = (int) $r->input('index', -1);
        if (! isset($scenes[$i])) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        $prompt = trim((string) $r->input('prompt')) ?: (string) ($scenes[$i]['image_prompt'] ?? $d->keyword);
        $imgAspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9', '4:5'], true) ? (string) $r->input('aspect') : '9:16';
        $style = (string) $r->input('style', 'minimalista');
        // Refs i2i: só URLs do NOSSO storage (anti-SSRF), cap 3 (limite nano-banana). Inválidas → t2i.
        $imageUrls = array_slice(array_values(array_filter((array) $r->input('imageUrls', []), fn ($u) => is_string($u) && self::isOwnMediaUrl($u))), 0, 3);

        $weight = $this->usage->weightFor('image');
        // Modelo: com refs = i2i img-referencia (KIE nano-banana-2); senão t2i ESCOLHÍVEL (request
        // `model`), fallback img-padrao.
        $imgGm = $imageUrls !== []
            ? GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
            : $this->imageT2IModel($r, $t->plan, GenModel::DEFAULT_T2I);
        // Qualidade (resolução) escolhida — no t2i E no i2i (img-referencia tem tiers 1K/2K/4K).
        // Preço do tier (`p`) tem precedência sobre o cost_credits do modelo.
        $imgQ = $this->videoQuality($imgGm, $r);
        $imgCost = ($imgQ['p'] ?? null) ?? $imgGm?->cost_credits;
        // RESERVE-THEN-CONSUME (AUD-002): reserva antes de enfileirar; o job estorna se a geração falhar.
        if (! $this->usage->tryConsume($t, 'image', $weight, $imgCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $payload = ['prompt' => $prompt, 'aspect' => $imgAspect, 'style' => $style];
        if ($imageUrls !== []) {
            $payload['imageUrls'] = $imageUrls; // i2i: refs da cena (o engine ancora o personagem nelas)
            // ANCORAGEM (anti-drift): o engine cola o IDENTITY LOCK — o personagem/produto vem EXATO
            // da referência (a base é a 1ª ref = identidade; encadeamento entra depois = continuidade),
            // sem re-desenhar a cada cena. Só na geração de cena; a EDIÇÃO i2i (story-edit-image) não liga.
            $payload['anchorIdentity'] = true;
        }
        // FICHA DE CENA (S1): plano/luz/emoção → o engine compõe a cinematografia na geração (editar a
        // ficha muda a próxima imagem). Request > o spec guardado na cena (fallback).
        if ($spec = self::sanitizeSceneSpec($r->input('spec') ?? ($scenes[$i]['spec'] ?? null))) {
            $payload['spec'] = $spec;
        }
        // DIREÇÃO DE ARTE (S3): paleta do projeto (request > a guardada na história) → coesão de cor.
        if ($pal = mb_substr(trim((string) ($r->input('palette') ?? ($story['palette'] ?? ''))), 0, 200)) {
            $payload['palette'] = $pal;
        }
        // provider/model/spec explícitos TAMBÉM no i2i (antes ia só imageUrls e o engine caía no
        // roteamento default) — é o que permite aplicar o tier de resolução no i2i. O engine
        // mantém o fallback interno quando o caminho ancorado falha com refs (robustez preservada).
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $imgQ));
        GenerateStoryImageJob::dispatch($d->id, $t->id, $i, $payload, $style, $weight, null, $imgCost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'message' => 'Imagem da cena em geração — aparece em alguns segundos.']);
    }

    /**
     * POST /api/studio/motion-join { draftId?, clipUrls[], aspect?, music? } — JUNTA os clipes de
     * motion já gerados num vídeo só e anexa à galeria.
     *
     * POR QUE EXISTE: o Motion gerava UMA tela e animava — uma peça de 4 a 15s. Peça de verdade
     * tem várias telas em sequência, e juntar exigia baixar cada clipe e montar num editor fora.
     * Aqui a montagem é a MESMA do filme contínuo (/concat-clips do ffmpeg-service, normalizando
     * resolução, fps e faixa de áudio) — reusar em vez de escrever outra concatenação evita duas
     * montagens divergindo no primeiro ajuste.
     *
     * NÃO gera nada e NÃO cobra crédito de IA: os clipes já foram pagos quando foram gerados.
     * É compute, igual à folha de storyboard.
     */
    public function motionJoin(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // Só mídia NOSSA: o ffmpeg-service baixa cada URL, e aceitar link de fora seria SSRF.
        $clips = array_values(array_filter(
            (array) $r->input('clipUrls', []),
            fn ($u) => is_string($u) && self::isOwnMediaUrl($u),
        ));
        if (count($clips) < 2) {
            return response()->json(['ok' => false, 'error' => 'Junte pelo menos 2 clipes.'], 422);
        }
        $aspect = $r->input('aspect') === '16:9' ? '16:9' : '9:16';

        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->acceptJson()->timeout(600)
                ->post('/concat-clips', [
                    'clip_urls' => $clips,
                    'aspect' => $aspect,
                    // O motion carrega o próprio som quando tem; trilha nova por cima brigaria com
                    // o que já está nos clipes. Só entra se o operador pedir.
                    'music' => (bool) $r->input('music', false),
                ]);
            $url = $res->successful() ? trim((string) $res->json('url')) : '';
            if ($url === '') {
                Log::warning('motion-join: concat falhou', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 200)]);

                return response()->json(['ok' => false, 'error' => 'Não foi possível juntar os clipes.'], 502);
            }
        } catch (\Throwable $e) {
            Log::warning('motion-join: exception', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'Não foi possível juntar os clipes.'], 502);
        }

        $d = $r->input('draftId')
            ? $this->draft($r, $r->input('draftId'))
            : Draft::create(['tenant_id' => $t->id, 'keyword' => 'motion (montagem)']);
        $item = ['id' => self::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'motion',
            'platforms' => Networks::only($r->input('platforms', []))];
        $d->update(['media' => array_merge($d->media ?? [], [$item])]);
        Audit::log('studio.motion_join', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'clipes' => count($clips)]);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'url' => $url, 'clipes' => count($clips)]);
    }

    /** POST /api/studio/story-edit-image { draftId, index, imageUrl, prompt, refIndex?, aspect?, style? }
     *  → EDITA UMA imagem (i2i nano-banana) conforme a instrução. refIndex presente = edita a
     *  referência scenes[index].refs[refIndex] NO LUGAR; ausente = edita a IMAGEM da cena. Async
     *  (mesmo job da geração, com refIndex). A imagem editada é validada como mídia NOSSA (anti-SSRF). */
    public function storyEditImage(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = $story['scenes'] ?? [];
        $i = (int) $r->input('index', -1);
        if (! isset($scenes[$i])) {
            return response()->json(['ok' => false, 'error' => 'cena inexistente'], 422);
        }
        $imageUrl = (string) $r->input('imageUrl', '');
        if ($imageUrl === '' || ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422);
        }
        $prompt = trim((string) $r->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o que alterar na imagem'], 422);
        }
        $refIndex = $r->has('refIndex') ? (int) $r->input('refIndex') : null;
        if ($refIndex !== null) {
            $refs = array_values(array_filter((array) ($scenes[$i]['refs'] ?? []), 'is_string'));
            if (! isset($refs[$refIndex])) {
                return response()->json(['ok' => false, 'error' => 'referência inexistente'], 422);
            }
        }
        $imgAspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9', '4:5'], true) ? (string) $r->input('aspect') : '9:16';
        $style = (string) $r->input('style', 'minimalista');

        $weight = $this->usage->weightFor('image');
        // Edição é sempre i2i nano-banana → custo do modelo i2i.
        $imgCost = GenModel::resolveSelectable('img-referencia', 'image', $t->plan)?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $imgCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        // i2i: edita a imagem dada conforme a instrução (nano-banana edit). SCAFFOLD DE EDIÇÃO:
        // a instrução crua se diluía no prompt estilizado e o modelo devolvia a imagem quase igual
        // ("mandei editar e não aconteceu nada") — o comando explícito prioriza a MUDANÇA pedida e
        // congela o resto.
        $edit = GenPayload::editPrompt($prompt);
        $payload = ['prompt' => $edit, 'aspect' => $imgAspect, 'style' => $style, 'imageUrls' => [$imageUrl]];
        GenerateStoryImageJob::dispatch($d->id, $t->id, $i, $payload, $style, $weight, $refIndex, $imgCost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'index' => $i, 'refIndex' => $refIndex, 'message' => 'Edição da imagem em andamento.']);
    }

    /**
     * POST /api/studio/image-variations { draftId?, prompt, aspect?, style?, model?, quality?,
     * imageUrls?, count? } → 🎲 gera N (2-4, padrão 3) imagens CANDIDATAS síncronas do mesmo
     * prompt/estilo, anexa cada uma como item comum da galeria (sem vínculo com cena/keyframe —
     * o cliente escolhe uma via story-scene-image-set ou film-keyframe-set, sem custo extra) e
     * devolve as URLs pro front mostrar lado a lado. Custo = N× o tier normal, transparente
     * (reserve-then-consume POR imagem; se uma falhar, as outras seguem — estorna só a que falhou).
     * Reusa a MESMA resolução de modelo/qualidade do storyImage/media (kind=image).
     *
     * SÍNCRONO de propósito (decisão 2026-07-04): o cliente precisa ver as N candidatas JUNTAS
     * pra comparar; um fluxo assíncrono (job + polling) só complica a UX aqui. Risco de timeout
     * descartado: N≤4 imagens × ~10-15s = ~40-60s, MUITO abaixo do teto do console
     * (php-fpm request_terminate_timeout=700, nginx fastcgi_read_timeout=600). Denial-of-wallet
     * coberto pelo throttle apertado (6/min → teto ~24 img/min) + cota por imagem.
     */
    public function imageVariations(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $prompt = trim((string) $r->input('prompt'));
        if ($prompt === '') {
            return response()->json(['ok' => false, 'error' => 'descreva o que gerar'], 422);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($prompt, 0, 80)]);

        $imgAspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9', '4:5'], true) ? (string) $r->input('aspect') : '9:16';
        $style = (string) $r->input('style', 'realista');
        $imageUrls = array_slice(array_values(array_filter((array) $r->input('imageUrls', []), fn ($u) => is_string($u) && self::isOwnMediaUrl($u))), 0, 3);
        $count = max(2, min(4, (int) $r->input('count', 3)));

        $weight = $this->usage->weightFor('image');
        $imgGm = $imageUrls !== []
            ? GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
            : $this->imageT2IModel($r, $t->plan, GenModel::DEFAULT_T2I);
        $imgQ = $this->videoQuality($imgGm, $r);
        $imgCost = ($imgQ['p'] ?? null) ?? $imgGm?->cost_credits;

        $payload = ['prompt' => $prompt, 'aspect' => $imgAspect, 'style' => $style];
        if ($imageUrls !== []) {
            $payload['imageUrls'] = $imageUrls;
            $payload['anchorIdentity'] = true; // variações ancoradas: preservam a identidade da ref (anti-drift)
        }
        // FICHA DE CENA (S1): plano/luz/emoção da cena → o engine compõe a cinematografia na geração.
        if ($spec = self::sanitizeSceneSpec($r->input('spec'))) {
            $payload['spec'] = $spec;
        }
        // DIREÇÃO DE ARTE (S3): paleta do projeto → coesão de cor entre as variações.
        if ($pal = mb_substr(trim((string) $r->input('palette')), 0, 200)) {
            $payload['palette'] = $pal;
        }
        $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $imgQ));

        $items = [];
        $failed = 0;
        for ($n = 0; $n < $count; $n++) {
            if (! $this->usage->tryConsume($t, 'image', $weight, $imgCost)) {
                break; // limite do plano atingido no meio do lote — devolve o que já foi gerado
            }
            $url = $this->engine()->post('/v1/image', $payload)->json('url');
            if (! $url) {
                $this->usage->refund($t, 'image', $weight, $imgCost);
                $failed++;

                continue;
            }
            $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => $style, 'platforms' => []], Draft::imageMeta($url));
            $this->attachImageLocked($d->id, $item, null); // sem storyIndex: item comum da galeria, não vira cena/keyframe sozinho
            $items[] = $item;
        }

        if ($items === []) {
            return response()->json(['ok' => false, 'error' => 'nenhuma variação foi gerada'], 502);
        }

        return response()->json(['ok' => true, 'draftId' => $d->id, 'items' => $items, 'failed' => $failed]);
    }

    /** POST /api/studio/image-filter { draftId?, imageUrl, grade, gradeStrength? } → 🎨 F2:
     *  filtro Instagram (color grade determinístico + intensidade) numa FOTO já gerada — o
     *  resultado vira um NOVO item da galeria (o original fica). Síncrono (ffmpeg, ~1s).
     *  Custo: 2 créd (bucket effect), estornado se o serviço falhar. */
    public function imageFilter(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $imageUrl = (string) $r->input('imageUrl', '');
        if ($imageUrl === '' || ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'imagem inválida'], 422);
        }
        $grade = self::gradeFrom($r);
        if ($grade === 'natural') {
            return response()->json(['ok' => false, 'error' => 'escolha um filtro'], 422);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'filtro']);
        if (! $this->usage->tryConsume($t, 'effect', 2)) {
            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para o filtro.'], 402);
        }
        $url = $this->engine()->post('/v1/imagefilter', [
            'imageUrl' => $imageUrl, 'grade' => $grade, 'strength' => self::gradeStrengthFrom($r),
        ])->json('url');
        if (! $url) {
            $this->usage->refund($t, 'effect', 2);

            return response()->json(['ok' => false, 'error' => 'não foi possível aplicar o filtro agora'], 502);
        }
        $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'filtro-'.$grade, 'platforms' => []], Draft::imageMeta($url));
        $this->attachImageLocked($d->id, $item, null);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'url' => $url]);
    }

    /** POST /api/studio/story-video { draftId, voice_id?, music? } → JUNTA as cenas num Short:
     *  cada cena entra como seu clipe i2v (video_url) ou, na falta dele, como slide da imagem;
     *  sobre tudo vai narração (TTS do voiceover) + legenda + música opcional. ASSÍNCRONO: o
     *  worker chama /v1/storyvideo e anexa o vídeo à galeria; o front faz polling. */
    public function storyVideo(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        // 🎇 Efeitos POR CENA (F4): sfx[i] (prompt curto) e vfx[i] (kind allowlistado) chegam
        // alinhados às CENAS; como cenas sem mídia são puladas, o mapeamento é feito aqui dentro.
        $sfxIn = array_values((array) $r->input('sfx', []));
        $vfxIn = array_values((array) $r->input('vfx', []));
        $beats = [];
        foreach (array_values((array) ($story['scenes'] ?? [])) as $si => $sc) {
            $vid = (string) ($sc['video_url'] ?? '');
            $img = (string) ($sc['image_url'] ?? '');
            $script = (string) ($sc['voiceover'] ?? '');
            $b = null;
            if ($vid !== '' && self::isOwnMediaUrl($vid)) {
                $b = ['video_url' => $vid, 'script' => $script]; // preferido: clipe i2v da cena
            } elseif ($img !== '' && self::isOwnMediaUrl($img)) {
                $b = ['image_url' => $img, 'script' => $script]; // fallback: slide da imagem
            }
            if ($b === null) {
                continue;
            }
            $b['sfx'] = mb_substr(trim((string) ($sfxIn[$si] ?? '')), 0, 200);
            $b['vfx'] = in_array($vfxIn[$si] ?? '', self::VFX_KINDS, true) ? (string) $vfxIn[$si] : '';
            $beats[] = $b;
        }
        if ($beats === []) {
            return response()->json(['ok' => false, 'error' => 'Gere o vídeo (ou ao menos a imagem) das cenas antes de juntar.'], 422);
        }
        // RESERVE-THEN-CONSUME (AUD-002) bucket 'short' (vídeo produzido). Estornado no job se falhar.
        // 🖼️ Montagem SLIDES-ONLY (Quadrinhos / história sem nenhum clipe i2v): o custo real é só
        // TTS + CPU do ffmpeg — cobrar os 440 créd do Short com vídeo aqui mataria a promessa do
        // formato (episódio barato). Slides-only = 40 créd; com qualquer clipe = custo cheio.
        $slidesOnly = array_filter($beats, fn ($b) => isset($b['video_url'])) === [];
        $shortCost = $slidesOnly ? 40 : null;
        if (! $this->usage->tryConsume($t, 'short', 1, $shortCost)) {
            return response()->json(['ok' => false, 'error' => 'Limite de vídeos do plano atingido.'], 402);
        }
        // 🎞️ Payload + efeitos: builder COMPARTILHADO com o Estúdio de Animação (modos narrados).
        [$payload, $fxCount, $platforms] = $this->buildShortMontage(
            $r, $t, $beats, (string) ($story['lang'] ?? ($t->content_lang ?? 'pt-BR'))
        );
        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1, $shortCost);

            return response()->json(['ok' => false, 'error' => 'Créditos insuficientes para os efeitos ('.$fxCount.').'], 402);
        }
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/storyvideo', $payload, 'short', 1, 'historia', $platforms, $shortCost, 'video', $fxCount);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo da história em geração — aparece na galeria em alguns minutos.']);
    }

    /** POST /api/studio/story-review { draftId } → 🎬 SCRIPT DOCTOR (S2): critica o roteiro atual
     *  (títulos + voiceover das cenas) e devolve notas acionáveis por cena + veredito geral. Texto
     *  puro — NÃO regenera nada nem debita cota de mídia (só gate de assinatura). O operador lê e
     *  edita à mão. */
    public function storyReview(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = array_values((array) ($story['scenes'] ?? []));
        if ($scenes === []) {
            return response()->json(['ok' => false, 'error' => 'Gere a história antes de pedir a crítica.'], 422);
        }
        // Só título + voiceover pro engine (o script doctor julga o arco, não a mídia).
        $payload = [
            'theme' => (string) ($story['theme'] ?? $d->keyword),
            'lang' => (string) ($story['lang'] ?? $t->content_lang ?? 'pt-BR'),
            'scenes' => array_map(fn ($s) => [
                'title' => (string) ($s['title'] ?? ''),
                'voiceover' => (string) ($s['voiceover'] ?? ''),
            ], $scenes),
            // Idem storystructure: o engine aceita `gen_lines` em /v1/storyreview desde sempre e o
            // console nunca mandava — o script doctor ignorava o modelo de texto escolhido na UI.
            'gen_lines' => $this->textGenLines($this->textModelFor($r, $t->plan)),
        ];
        $res = $this->engine()->post('/v1/storyreview', $payload);
        if (! $res->successful()) {
            return response()->json(['ok' => false, 'error' => 'não foi possível gerar a crítica agora'], 502);
        }

        return response()->json(['ok' => true, 'overall' => (string) $res->json('overall'), 'notes' => (array) $res->json('notes')]);
    }

    /**
     * POST /api/studio/post-networks { draftId, platforms[] } → fixa as redes DEFINITIVAS do post.
     *
     * A escolha de redes (as badges do Estúdio de Animação / Movies) só mandava no que era GERADO —
     * desmarcar não desfazia nada. Como o Aprovar monta as abas a partir de `draft.texts` e o
     * PublishService itera sobre `draft.texts`, uma legenda antiga de rede desmarcada continuava
     * na tela E ia ao ar: o operador tirava o LinkedIn e ele "persistia". Aqui a seleção vira a
     * verdade — a legenda das redes de fora sai, e a mídia final passa a servir as que ficaram.
     *
     * Não gera nada e não gasta crédito: é só o estado do post.
     */
    public function postNetworks(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $platforms = Networks::only($r->input('platforms', []));
        if ($platforms === []) {
            return response()->json(['ok' => false, 'error' => 'Escolha ao menos uma rede.'], 422);
        }

        $texts = array_intersect_key((array) ($d->texts ?? []), array_flip($platforms));
        $meta = array_intersect_key((array) ($d->texts_meta ?? []), array_flip($platforms));
        $removidas = array_values(array_diff(array_keys((array) ($d->texts ?? [])), $platforms));
        $d->update(['texts' => $texts, 'texts_meta' => $meta]);
        self::servirRedesNaMidiaFinal($d, $platforms);

        return response()->json([
            'ok' => true,
            'platforms' => array_keys($texts),
            'removed' => $removidas, // redes cuja legenda saiu do post (a UI avisa)
        ]);
    }

    /** POST /api/studio/story-texts { draftId, platforms[] } → gera o TEXTO do post (título/
     *  descrição/caption) por rede a partir do tema + roteiro (voiceovers das cenas). Grava em
     *  draft.texts[rede] + texts_meta — é o que o fluxo Aprovar→Publicar consome (o publish só
     *  posta em rede COM texto). Gate de assinatura (geração de texto não debita cota de mídia). */
    public function storyTexts(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $story = is_array($d->story) ? $d->story : [];
        $scenes = $story['scenes'] ?? [];
        if (! is_array($scenes) || $scenes === []) {
            return response()->json(['ok' => false, 'error' => 'gere a história primeiro'], 422);
        }
        $platforms = Networks::only($r->input('platforms', []));
        if ($platforms === []) {
            $platforms = ['youtube', 'instagram', 'linkedin'];
        }
        $lang = (string) ($story['lang'] ?? ($t->content_lang ?? 'pt-BR'));
        $theme = (string) ($story['theme'] ?: $d->keyword);
        // Base factual do post = tema + roteiro (voiceovers das cenas concatenados).
        $script = trim(implode("\n", array_filter(array_map(fn ($s) => (string) ($s['voiceover'] ?? ''), $scenes))));
        $facts = trim($theme."\n\n".$script);

        $texts = $d->texts ?? [];
        $meta = $d->texts_meta ?? [];
        // Conteúdo cobrado por MODELO e POR REDE (cada rede = 1 geração de texto). Sem saldo → para
        // nas redes restantes (as já geradas ficam). Falha do engine estorna a daquela rede.
        $tm = $this->textModelFor($r, $t->plan);
        $gerados = 0;    // redes que ganharam texto NESTA chamada
        $semSaldo = false;
        foreach ($platforms as $p) {
            if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
                $semSaldo = true;
                break; // sem saldo: mantém o que já gerou
            }
            $res = $this->engine()->post('/v1/text', ['keyword' => $theme, 'brief' => $theme, 'facts' => $facts, 'platform' => $p, 'lang' => $lang, 'persona' => self::textPersona($r, $t), 'gen_lines' => $this->textGenLines($tm)]);
            if (! $res->successful()) {
                $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

                continue; // tolerante: uma rede que falha não derruba as outras
            }
            $post = (string) $res->json('post');
            if (trim($post) === '') {
                $this->usage->refund($t, 'text', 1, $tm?->cost_credits); // texto vazio não é entrega

                continue;
            }
            $this->ajustaSeReserva($t, $tm, $res);
            $texts[$p] = $post;
            $meta[$p] = ['rank' => (float) $res->json('rank_summary'), 'grounding' => (float) $res->json('grounding'), 'flags' => $res->json('flags') ?? [], 'lang' => $lang];
            $gerados++;
        }
        $d->update(['texts' => $texts, 'texts_meta' => $meta]);
        self::servirRedesNaMidiaFinal($d, $platforms); // rede com legenda tem de ter a mídia junto

        // ZERO texto novo + rascunho ainda sem legenda nenhuma = FALHA, e tem que dizer isso. Antes
        // respondia ok:true com texts vazio, e a tela seguia pro Aprovar — que monta as abas de rede
        // (e o preview da mídia) a partir de texts. Resultado: página vazia, "sem vídeo e sem redes".
        $temAlgum = collect($texts)->contains(fn ($v, $k) => $k !== 'blog' && trim((string) $v) !== '');
        if ($gerados === 0 && ! $temAlgum) {
            return $semSaldo
                ? response()->json(['ok' => false, 'error' => 'Sem créditos para gerar os textos do post — recarregue o plano e tente de novo.'], 402)
                : response()->json(['ok' => false, 'error' => 'A geração dos textos do post falhou — tente de novo.'], 502);
        }

        return response()->json(['ok' => true, 'texts' => $texts, 'generated' => $gerados]);
    }

    /**
     * GET /api/studio/story-export?draftId=X → empacota num ZIP os arquivos brutos de TODAS as
     * cenas (imagem + narração + vídeo de cada cena) + o roteiro.txt + o Short final (se houver),
     * para o cliente montar o vídeo no editor dele (Final Cut Pro etc.).
     *
     * Só baixa mídia do NOSSO storage (isOwnMediaUrl) — URL externa/morta é ignorada. Cada arquivo
     * é puxado por STREAM pra um temp (sink, sem carregar em memória) e adicionado ao ZIP; os temps
     * são apagados após fechar o ZIP, e o próprio ZIP é apagado depois do envio (deleteFileAfterSend).
     * Tenant-scoped via draft() (404 se não for do tenant). Premium (gate no middleware).
     */
    public function storyExport(Request $r): BinaryFileResponse|JsonResponse
    {
        $draftId = (string) $r->input('draftId', '');
        if ($draftId === '') {
            return response()->json(['ok' => false, 'error' => 'rascunho não informado'], 422);
        }
        $d = $this->draft($r, $draftId);
        $story = is_array($d->story) ? $d->story : [];
        $scenes = is_array($story['scenes'] ?? null) ? $story['scenes'] : [];

        $tmp = sys_get_temp_dir();
        $zipPath = (string) tempnam($tmp, 'rxzip_');
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['ok' => false, 'error' => 'não foi possível preparar o arquivo'], 500);
        }

        // roteiro.txt: tema + narração de cada cena (referência pra montagem/legendagem no editor).
        $linhas = ['HISTÓRIA: '.(string) (($story['theme'] ?? '') ?: $d->keyword), ''];
        foreach ($scenes as $i => $sc) {
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $linhas[] = "── CENA {$n} — ".(string) ($sc['title'] ?? '');
            $linhas[] = trim((string) ($sc['voiceover'] ?? ''));
            $linhas[] = '';
        }
        $zip->addFromString('roteiro.txt', implode("\n", $linhas));

        // Puxa um arquivo do nosso storage pra um temp e o adiciona ao ZIP com nome amigável.
        $temps = [];
        $count = 0;
        $add = function (string $url, string $entry) use ($zip, $tmp, &$temps, &$count) {
            if ($url === '' || ! self::isOwnMediaUrl($url)) {
                return;
            }
            $t = (string) tempnam($tmp, 'rxmed_');
            try {
                $resp = Http::timeout(180)->sink($t)->get($url);
                if ($resp->successful() && is_file($t) && filesize($t) > 0) {
                    $zip->addFile($t, $entry);
                    $temps[] = $t;
                    $count++;

                    return;
                }
            } catch (\Throwable $e) {
                // arquivo indisponível → ignora (não derruba o export inteiro)
            }
            @unlink($t);
        };

        foreach ($scenes as $i => $sc) {
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $add((string) ($sc['image_url'] ?? ''), "cena-{$n}-imagem.".self::extOf((string) ($sc['image_url'] ?? ''), 'jpg'));
            $add((string) ($sc['audio_url'] ?? ''), "cena-{$n}-narracao.".self::extOf((string) ($sc['audio_url'] ?? ''), 'mp3'));
            $add((string) ($sc['video_url'] ?? ''), "cena-{$n}-video.".self::extOf((string) ($sc['video_url'] ?? ''), 'mp4'));
        }

        // Short final (se já montado): último kind=video SEM scene e style=historia.
        $final = collect($d->media ?? [])
            ->filter(fn ($m) => ($m['kind'] ?? '') === 'video' && empty($m['scene']) && ($m['style'] ?? '') === 'historia')
            ->sortByDesc(fn ($m) => (int) ($m['id'] ?? 0))
            ->first();
        if ($final && ! empty($final['url'])) {
            $add((string) $final['url'], 'historia-final.'.self::extOf((string) $final['url'], 'mp4'));
        }

        $zip->close();
        foreach ($temps as $t) {
            @unlink($t); // os bytes já estão dentro do ZIP
        }

        if ($count === 0) {
            @unlink($zipPath);

            return response()->json(['ok' => false, 'error' => 'Gere as imagens, narrações ou vídeos das cenas antes de baixar.'], 422);
        }

        return response()->download($zipPath, 'historia-'.$d->id.'.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    /** Extensão (allowlist de mídia) derivada do path da URL; fallback informado. */
    private static function extOf(string $url, string $fallback): string
    {
        $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'm4a', 'mp3'], true) ? $ext : $fallback;
    }

    /**
     * POST /api/studio/story-final-upload (multipart: file, draftId?, platforms[]) → recebe o vídeo
     * FINAL já montado pelo cliente (no Final Cut Pro etc.) e o grava como o Short final do rascunho
     * (kind=video, SEM scene, style=historia) — exatamente o formato que o fluxo Aprovar→Publicar
     * consome. Não gasta cota de IA (é upload, não geração). Premium (gate no middleware).
     */
    public function storyFinalUpload(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // AUD-006/AUD-024: vídeo real (mp4/mov), teto de 200MB pro arquivo final montado.
        $r->validate(['file' => 'required|file|mimes:mp4,mov|max:204800']);
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'envie o arquivo de vídeo'], 400);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'historia']);

        $ext = strtolower((string) ($file->guessExtension() ?: 'mp4'));
        $url = self::storeUploadedFile($file, $ext, 'video');

        // Redes-alvo (publicação seletiva por rede) — mesmo contrato do Short gerado.
        $platforms = Networks::only($r->input('platforms', []));

        $item = ['id' => self::mediaId(), 'kind' => 'video', 'url' => $url, 'style' => 'historia', 'platforms' => $platforms];
        $d->update(['media' => array_merge($d->media ?? [], [$item])]);

        Audit::log('studio.story_final_upload', ['tenant_id' => $t->id, 'draft_id' => $d->id]);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'id' => $item['id'], 'url' => $url]);
    }

    /** POST /api/studio/draft { keyword } → cria um rascunho EM BRANCO (sem pesquisa).
     *  Caminho "criar conteúdo sem pesquisa": o tema vira a referência e a geração de
     *  texto roda com brief/facts vazios — text() já trata research ausente. */
    public function createBlank(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $keyword = trim((string) $r->input('keyword'));
        if ($keyword === '') {
            return response()->json(['ok' => false, 'error' => 'informe um tema'], 400);
        }
        $draft = Draft::create(['tenant_id' => $t->id, 'keyword' => $keyword]);

        return response()->json(['ok' => true, 'draftId' => $draft->id]);
    }

    /**
     * Qual arquivo o post vai levar → [url, kind]. ESPELHA a escolha do PublishService
     * (`publishDraft`): vídeo tem precedência sobre imagem, clipes intermediários de história
     * (item com `scene`) ficam de fora, e vale o ÚLTIMO item de cada tipo.
     *
     * ⚠️ Lê `drafts.media[]`, não as colunas `image_url`/`video_url`: o `attach()` — usado pelo
     * upload e pelo adopt — grava SÓ em `media[]`. Descrever a partir das colunas devolveria vazio
     * justamente no upload manual, que é o caso que esta feature existe para consertar. As colunas
     * ficam como fallback pros rascunhos do fluxo gerado, que as preenchem.
     *
     * @return array{0: string, 1: string}
     */
    private function mediaQueVaiSerPublicada(Draft $d): array
    {
        $gallery = is_array($d->media) ? $d->media : [];
        $videos = array_values(array_filter($gallery, fn ($m) => ($m['kind'] ?? '') === 'video' && empty($m['scene'])));
        if ($videos !== []) {
            $ultimo = end($videos);
            if (trim((string) ($ultimo['url'] ?? '')) !== '') {
                return [trim((string) $ultimo['url']), 'video'];
            }
        }
        $imagens = array_values(array_filter($gallery, fn ($m) => ($m['kind'] ?? '') === 'image'));
        if ($imagens !== []) {
            $ultima = end($imagens);
            if (trim((string) ($ultima['url'] ?? '')) !== '') {
                return [trim((string) $ultima['url']), 'image'];
            }
        }
        // Fallback: fluxo gerado, que preenche as colunas do rascunho.
        if (trim((string) $d->video_url) !== '') {
            return [trim((string) $d->video_url), 'video'];
        }

        return [trim((string) $d->image_url), 'image'];
    }

    /**
     * 👁️ Descrição da MÍDIA do rascunho, pra ancorar o texto no arquivo que será publicado.
     *
     * MEMOIZADA em research['media_desc'] (chave própria, não colide com summary/brief): gerar o
     * texto de 4 redes dispara text() 4 vezes, e sem cache seriam 4 leituras de visão pagas pela
     * MESMA foto. A chave guarda junto a URL lida (`media_desc_url`) — trocou a mídia do rascunho,
     * a descrição é refeita; sem isso a legenda nova descreveria a foto velha, que é pior que não
     * descrever nada.
     *
     * Falha NÃO derruba a geração: sem descrição o texto volta ao comportamento antigo (só
     * keyword + pesquisa). Perder a âncora é degradação; recusar o post por causa dela seria
     * transformar um extra em bloqueio.
     */
    private function mediaDescription(Draft $d): string
    {
        [$url, $kind] = $this->mediaQueVaiSerPublicada($d);
        if ($url === '') {
            return '';
        }

        $research = $d->research ?? [];
        if (! empty($research['media_desc']) && ($research['media_desc_url'] ?? '') === $url) {
            return (string) $research['media_desc'];
        }

        try {
            $res = $this->engine()->post('/v1/describe', ['url' => $url, 'kind' => $kind]);
            $desc = $res->successful() ? trim((string) $res->json('description')) : '';
        } catch (\Throwable $e) {
            $desc = '';
        }
        if ($desc === '') {
            return '';
        }

        $d->update(['research' => array_merge($research, ['media_desc' => $desc, 'media_desc_url' => $url])]);

        return $desc;
    }

    /** POST /api/studio/text { draftId, platform } → gera o texto da plataforma. */
    public function text(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $platform = (string) $r->input('platform');
        // #3 idioma por rede: 'pt-BR' ou 'en-US'; valor inválido/ausente → padrão da conta (tenant) ou pt-BR.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = $r->user()->tenant->content_lang ?? 'pt-BR';
        }
        $brief = $d->research['summary'] ?? '';                            // resumo destilado (orientação)
        $facts = $d->research['brief'] ?? ($d->research['summary'] ?? ''); // material cru das fontes (base factual)
        // 🖼️ A MÍDIA do post entra como âncora do texto. Sem isto, o upload manual (compositor
        // "➕ Novo post") gerava a legenda só a partir do keyword — brief e facts chegam VAZIOS
        // quando não houve pesquisa —, então a IA escrevia sobre um arquivo que nunca viu e o
        // resultado parecia aleatório porque era.
        $visual = $this->mediaDescription($d);

        // Cobrado por MODELO (seletor textModel; default Equilibrado). Estornado se o engine falhar.
        $tm = $this->textModelFor($r, $t->plan);
        if (! $this->usage->tryConsume($t, 'text', 1, $tm?->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de texto.'], 402);
        }
        $res = $this->engine()->post('/v1/text', ['keyword' => $d->keyword, 'brief' => $brief, 'facts' => $facts, 'visual' => $visual, 'platform' => $platform, 'lang' => $lang, 'persona' => self::textPersona($r, $t), 'gen_lines' => $this->textGenLines($tm)]);
        if (! $res->successful()) {
            $this->usage->refund($t, 'text', 1, $tm?->cost_credits);

            return response()->json(['ok' => false, 'error' => 'geração de texto falhou'], 502);
        }
        $this->ajustaSeReserva($t, $tm, $res);
        $post = (string) $res->json('post');
        $imagePrompt = (string) $res->json('image_prompt');
        $grounding = (float) $res->json('grounding');
        $rank = (float) $res->json('rank_summary');
        $flags = $res->json('flags') ?? [];

        $texts = array_merge($d->texts ?? [], [$platform => $post]);
        // FIXA o metadado do texto por plataforma: rank vs resumo + grounding vs fontes + flags + idioma (#3).
        $textsMeta = array_merge($d->texts_meta ?? [], [$platform => ['rank' => $rank, 'grounding' => $grounding, 'flags' => $flags, 'lang' => $lang]]);
        $d->update([
            'texts' => $texts,
            'texts_meta' => $textsMeta,
            'image_prompt' => $d->image_prompt ?: $imagePrompt,
        ]);

        return response()->json(['ok' => true, 'platform' => $platform, 'post' => $post, 'image_prompt' => $imagePrompt, 'grounding' => $grounding, 'rank_summary' => $rank, 'flags' => $flags, 'lang' => $lang]);
    }

    /** PATCH /api/studio/text { draftId, platform, text } → salva o texto editado. */
    public function textUpdate(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $texts = array_merge($d->texts ?? [], [(string) $r->input('platform') => (string) $r->input('text')]);
        $d->update(['texts' => $texts]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/studio/media { draftId?, kind, prompt, aspect, style, voice_id, ... } → gera e acumula.
     *
     * Fluxo de VÍDEO UNIFICADO (kind="video"): um único endpoint atende desde o clipe simples
     * (scenes=1, sem narração/legenda/música) até o short completo (multi-cena, voz, legenda,
     * trilha). As "flags" do contrato front→console:
     *   scenes(1..12) · narration(bool) · voice_id · lang(pt-BR|en-US) · subtitles(bool)
     *   music(bool) · premium(bool) · duration(6|10) · prompt/videoPrompt · style · imageUrl
     *
     * Roteamento por custo/destino:
     *   premium=true  → /v1/veo  (bucket 'veo',  peso weightFor('veo')=10) — mesmo que veo().
     *   premium=false → /v1/video (bucket 'video'); o PESO depende da complexidade:
     *       (scenes>1 || narration || music) → weightFor('short')=5  (vídeo "produzido")
     *       senão                            → weightFor('video')=3  (clipe simples)
     *
     * Retrocompat: kind="image" segue intacto; kind="video" sem flags = clipe simples
     * (scenes=1, tudo off); kind="shortvideo" é ALIAS de video com defaults narration/
     * subtitles/music = true.
     */
    public function media(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $kind = (string) $r->input('kind');
        $prompt = (string) $r->input('prompt');

        // Imagem de input opcional (i2i/i2v/base do short). Vem de upload OU da galeria —
        // ambos geram URL do NOSSO S3. Validação anti-SSRF: se preenchida, EXIGE que seja
        // do nosso domínio de mídia (isOwnMediaUrl). URL externa arbitrária → 422.
        $imageUrl = (string) $r->input('imageUrl', '');
        if ($imageUrl !== '' && ! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }
        // Elenco de referências (i2i multi-personagem) p/ a geração de IMAGEM: array de URLs do
        // nosso S3. Cada uma é validada (anti-SSRF); cap em 3 (limite prático do nano-banana). Se
        // vazio, cai no imageUrl legado (1 referência). O caminho de VÍDEO segue usando $imageUrl.
        $imageUrls = array_values(array_filter(array_map('strval', (array) $r->input('imageUrls', []))));
        if ($imageUrls === [] && $imageUrl !== '') {
            $imageUrls = [$imageUrl];
        }
        foreach ($imageUrls as $u) {
            if (! self::isOwnMediaUrl($u)) {
                return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
            }
        }
        $imageUrls = array_slice($imageUrls, 0, 3);

        // PERSONAGENS da biblioteca (IDENTITY LOCK). O engine resolve o `lock` atual de cada id e
        // injeta no prompt — aqui só repassamos os ids, como as abas Imagem/Vídeo já faziam pelo
        // caminho direto. Sem isto, gerar "a Mel" na Mídia do post dependia de descrevê-la no
        // texto e sair parecida por sorte, enquanto o Estúdio travava a identidade de verdade.
        $charIds = array_values(array_filter(array_map('intval', (array) $r->input('charIds', []))));
        $charIds = array_slice($charIds, 0, 3);

        $draftId = $r->input('draftId');
        if ($draftId) {
            $d = $this->draft($r, $draftId);
        } else {
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr($prompt ?: 'mídia avulsa', 0, 80)]);
        }

        // #2 mídia por plataforma: redes a que esta mídia se destina (vazio = serve todas, retrocompat).
        $platforms = Networks::only($r->input('platforms', []));

        // ── IMAGEM ─────────────────────────────────────────────────────────────
        if ($kind === 'image') {
            $weight = $this->usage->weightFor('image');
            // Modelo de imagem: i2i (com refs) = nano-banana (sem seletor); t2i (sem refs) =
            // modelo ESCOLHÍVEL pelo cliente (request `model`, slug) com fallback img-padrao (Fase 2).
            $modeloTrocado = null;
            $imgGm = $imageUrls !== []
                ? GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
                : $this->imageT2IModel($r, $t->plan, GenModel::DEFAULT_T2I, $modeloTrocado);
            // Qualidade (resolução) escolhida — só no t2i (o i2i usa o roteamento default). Preço da
            // qualidade (`p`) tem precedência sobre o cost_credits do modelo.
            $imgQ = ($imageUrls === [] && $imgGm) ? $this->videoQuality($imgGm, $r) : null;
            $imgCost = ($imgQ['p'] ?? null) ?? $imgGm?->cost_credits;
            // RESERVE-THEN-CONSUME: reserva atômica ANTES de chamar o engine; estorna se falhar.
            if (! $this->usage->tryConsume($t, 'image', $weight, $imgCost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            // formato da imagem: 9:16 | 1:1 | 16:9 | 4:5 (default 9:16 — vertical é o formato
            // primário do produto, o mesmo do vídeo; ver storyImage/storyEditImage).
            $imgAspect = in_array($r->input('aspect'), ['9:16', '1:1', '16:9', '4:5'], true) ? (string) $r->input('aspect') : '9:16';
            // i2i: repassa imageUrl quando presente (imagem de referência/base da geração).
            $payload = ['prompt' => $prompt ?: $d->keyword, 'aspect' => $imgAspect, 'style' => $r->input('style', 'realista')];
            if ($persona = $this->resolvePersona($r, 'image')) {
                $payload['persona'] = $persona; // direção de estilo; o engine apensa no CompilePrompt
            }
            if ($refiner = $this->resolveRefiner($r)) {
                $payload['refiner'] = $refiner; // CLI que reescreve o pedido antes de gerar
            }
            if ($charIds !== []) {
                // O LOCK É RESOLVIDO AQUI, não no engine: quem sabe o `lock` atual de cada
                // personagem é o console (IdentityLock lê a tabela do tenant). Mandar `charIds`
                // no payload seria campo morto — o engine não conhece esse nome.
                $payload['prompt'] = IdentityLock::aplicar((string) $payload['prompt'], $t->id, $charIds);
            }
            if ($imageUrls !== []) {
                $payload['imageUrls'] = $imageUrls; // elenco (1+ refs) → i2i nano-banana multi-personagem
                // PAPÉIS das refs: com 2+ imagens, sem dizer o que cada uma É o modelo copia tudo
                // de todas e o resultado vira colagem. A instrução por papel diz o que aproveitar
                // (e o que ignorar) em cada uma. 1 ref sem papel declarado = identidade, como antes.
                $papeis = array_values(array_filter(array_map('strval', (array) $r->input('imageRoles', []))));
                if (count($imageUrls) > 1 || $papeis !== []) {
                    $payload['prompt'] = ($payload['prompt'] ?? '').self::refsRoleText($imageUrls, $papeis);
                }
            } elseif ($imgGm) {
                // t2i: provider/model escolhidos (i2i fica no roteamento i2i default do engine).
                $payload = array_merge($payload, GenPayload::imagePayloadBase($imgGm, $imgQ));
            }
            // storyIndex (opcional): quando a imagem é de uma CENA da história, grava o image_url
            // na cena no MESMO passo atômico — assim o front não precisa de um save à parte.
            $storyIndex = $r->has('storyIndex') ? (int) $r->input('storyIndex') : null;

            // MOTOR LENTO → ASSÍNCRONO. O `cursor` do CLI bridge leva 110-145s, e o Cloudflare
            // corta a requisição em ~100s com 524: a imagem era gerada e salva, mas o navegador
            // via erro. Enfileira e devolve o draftId na hora; o front faz polling do rascunho,
            // igual a vídeo/GIF/música. Gatilho é `capabilities.async` no catálogo, não o slug —
            // motor lento novo entra pelo Filament, sem deploy.
            if ($imgGm && ($imgGm->capabilities['async'] ?? false)) {
                GenerateImageJob::dispatch(
                    $d->id, $t->id, $payload, (string) $r->input('style', 'realista'),
                    $weight, $platforms, $imgCost, $storyIndex, $imgGm?->display_name,
                    self::gradeFrom($r), self::gradeStrengthFrom($r)
                );

                // Sem `item` de propósito: é o que faz o front cair no polling (ver Studio.tsx).
                return response()->json(array_filter([
                    'ok' => true, 'draftId' => $d->id,
                    'message' => 'Gerando imagem… este motor leva ~2min; ela aparece na galeria quando ficar pronta.',
                    'aviso' => self::avisoModeloTrocado($modeloTrocado, $imgGm),
                ], fn ($v) => $v !== null));
            }

            $url = $this->engine()->post('/v1/image', $payload)->json('url');
            if (! $url) {
                $this->usage->refund($t, 'image', $weight, $imgCost); // geração falhou → estorna

                return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
            }
            // 🎨 Cor (color grade) escolhida no card de Imagem: acabamento determinístico aplicado
            // por cima da geração, igual à montagem do vídeo — e igualmente embutido (sem cobrar
            // o bucket `effect`). 'natural' = no-op. Falha do filtro devolve a imagem original.
            $url = self::gradeGeneratedImage($url, self::gradeFrom($r), self::gradeStrengthFrom($r));
            // `model` + dimensões: é o que deixa a galeria organizável (qual motor fez o quê, em
            // que tamanho). Guarda o display_name, que é o nome que o usuário vê no seletor.
            $item = array_merge(
                ['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => (string) $r->input('style', 'realista'), 'platforms' => $platforms],
                $imgGm ? ['model' => $imgGm->display_name] : [],
                Draft::imageMeta($url)
            );
            // Imagem de CENA é intermediária: marca `scene` (igual aos clipes/áudios de cena) pra NÃO
            // vazar pra aba Aprovar/Publicar — de Histórias só o Short final é publicável.
            if ($storyIndex !== null) {
                $item['scene'] = $storyIndex + 1;
            }
            // APPEND ATÔMICO (lock): várias cenas podem gerar imagem em paralelo; sem o lock, o
            // read-modify-write do array `media` perdia itens (só o último sobrevivia). Bug corrigido.
            $media = $this->attachImageLocked($d->id, $item, $storyIndex);

            return response()->json(array_filter([
                'ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media,
                'aviso' => self::avisoModeloTrocado($modeloTrocado, $imgGm),
            ], fn ($v) => $v !== null));
        }

        // ── LOGO ───────────────────────────────────────────────────────────────
        // Logotipo = imagem t2i com um SCAFFOLD de logo (preset: minimalista/mascote/emblema/
        // moderno/lettering) + estilo 'logo' (tratamento vetorial genérico no engine). Síncrono
        // como a imagem. Texto em IA é fraco → presets priorizam ícone; o de lettering avisa.
        if ($kind === 'logo') {
            $weight = $this->usage->weightFor('image');
            $logoGm = $imageUrls !== []
                ? GenModel::resolveSelectable('img-referencia', 'image', $t->plan)
                : $this->imageT2IModel($r, $t->plan, GenModel::DEFAULT_T2I);
            $logoQ = ($imageUrls === [] && $logoGm) ? $this->videoQuality($logoGm, $r) : null; // qualidade (resolução)
            $logoCost = ($logoQ['p'] ?? null) ?? $logoGm?->cost_credits;
            if (! $this->usage->tryConsume($t, 'image', $weight, $logoCost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            $logoAspect = in_array($r->input('aspect'), ['1:1', '16:9', '9:16', '4:5'], true) ? (string) $r->input('aspect') : '1:1';
            $subject = $prompt !== '' ? $prompt : $d->keyword;
            $payload = ['prompt' => $this->logoPrompt((string) $r->input('preset', 'minimalista'), $subject), 'aspect' => $logoAspect, 'style' => 'logo'];
            if ($imageUrls !== []) {
                $payload['imageUrls'] = $imageUrls; // i2i: refina/estiliza um logo existente
            } elseif ($logoGm) {
                $payload = array_merge($payload, GenPayload::imagePayloadBase($logoGm, $logoQ));
            }
            $url = $this->engine()->post('/v1/image', $payload)->json('url');
            if (! $url) {
                $this->usage->refund($t, 'image', $weight, $logoCost);

                return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
            }
            $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'logo', 'platforms' => $platforms], $logoGm ? ['model' => $logoGm->display_name] : [], Draft::imageMeta($url));
            $media = $this->attachImageLocked($d->id, $item, null);

            return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
        }

        // ── GIF ────────────────────────────────────────────────────────────────
        // GIF animado = clipe CURTO em loop (i2v se houver imagem-base; senão t2v) convertido em
        // .gif no ffmpeg-service (/v1/gif no engine). Usa o pipeline de vídeo (lento) → ASSÍNCRONO
        // via GenerateVideoJob, anexado à galeria como IMAGEM (gif anima em <img>). Bucket 'video'.
        if ($kind === 'gif') {
            $weight = 1;
            $cost = null; // custo fixo do bucket 'video' (1 clipe curto + conversão)
            if (! $this->usage->tryConsume($t, 'video', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            // Seedance (modelo de vídeo default) suporta 1:1; default gif = 1:1 (mais "gif").
            $gifAspect = in_array($r->input('aspect'), ['1:1', '16:9', '9:16'], true) ? (string) $r->input('aspect') : '1:1';
            $payload = ['prompt' => $prompt ?: $d->keyword, 'style' => (string) $r->input('style', 'realista'), 'aspect' => $gifAspect];
            if ($imageUrl !== '') {
                $payload['imageUrl'] = $imageUrl; // i2v: anima a partir de uma imagem-base do nosso S3
            }
            // mediaKind='image' → o gif entra na galeria como imagem e anima nativamente no <img>.
            GenerateVideoJob::dispatch($d->id, $t->id, '/v1/gif', $payload, 'video', $weight, 'gif', $platforms, $cost, 'image');

            return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'GIF em geração — aparece na galeria em alguns instantes.']);
        }

        // ── VÍDEO UNIFICADO ────────────────────────────────────────────────────
        // 'shortvideo' = alias de 'video' com defaults de short (narração/legenda/música ON).
        if (! in_array($kind, ['video', 'shortvideo'], true)) {
            return response()->json(['ok' => false, 'error' => 'tipo de mídia inválido'], 400);
        }
        $isShortAlias = $kind === 'shortvideo';

        // Validações das flags do contrato:
        // duração de cada clipe: '5' ou '10' (o provider gera 5s ou 10s; '6' legado vira 5). Default '5'.
        $duration = (string) $r->input('duration', '5');
        if (! in_array($duration, ['5', '6', '10'], true)) {
            $duration = '5';
        }
        // scenes: nº de cenas (cada cena = 1 clipe). TETO por DURAÇÃO TOTAL = 5 min (300s) — cada
        // cena custa geração de IA, então o limite barra gasto excessivo: 5s→50 cenas, 10s→30 cenas.
        $perScene = $duration === '10' ? 10 : 5;
        $maxScenes = intdiv(300, $perScene);
        $scenes = (int) $r->input('scenes', 1);
        $scenes = max(1, min($maxScenes, $scenes ?: 1));
        // bools via FILTER_VALIDATE_BOOLEAN (aceita "true"/"1"/"on"/etc.); alias liga os defaults.
        $bool = fn (string $field, bool $default) => $r->has($field)
            ? filter_var($r->input($field), FILTER_VALIDATE_BOOLEAN)
            : $default;
        $narration = $bool('narration', $isShortAlias);
        $subtitles = $bool('subtitles', $isShortAlias);
        $music = $bool('music', $isShortAlias);
        // Modelo de vídeo escolhido (opcional). Resolve server-side (ativo/kind/plano). provider
        // 'google' (Veo) = premium; senão modelo padrão. Sem modelo → cai no flag 'premium' legado.
        $videoModel = GenModel::resolveSelectable($r->input('model'), 'video', $t->plan);
        if ($r->filled('model') && ! $videoModel) {
            return response()->json(['ok' => false, 'error' => 'Modelo de vídeo indisponível no seu plano.'], 422);
        }
        // 📰 PRESET VOX: o formato tem MOTOR PRÓPRIO, escolhido por teste (Seedance 2 — o único
        // que manteve a câmera travada sobre a colagem; ver engine/internal/content/vox.go).
        // Sem isto, quem não escolhesse modelo ficava SEM gen_lines, o engine ficava sem provider
        // e TODOS os clipes falhavam com "poucos clipes ok (0/N)" — o preset conhecia o motor
        // dele e ninguém o selecionava. Escolha explícita do cliente continua ganhando.
        if ($r->input('preset') === 'vox' && ! $videoModel) {
            $videoModel = GenModel::resolveSelectable(self::VOX_VIDEO_SLUG, 'video', $t->plan);
            if (! $videoModel) {
                return response()->json([
                    'ok' => false,
                    'error' => 'O motor do formato Vox não está disponível no seu plano.',
                ], 422);
            }
        }
        $premium = $videoModel ? ($videoModel->provider === 'google') : $bool('premium', false);
        // 📰 O preset Vox e o caminho PREMIUM (Veo) são incompatíveis: o Veo sai por outro
        // endpoint, que não conhece preset nenhum. Sem esta guarda, pedir os dois devolvia um
        // vídeo COMUM e o cliente recebia a peça errada achando que era Vox — falha silenciosa,
        // do tipo que não aparece em log. Recusa explícita em vez de entrega errada.
        if ($premium && $r->input('preset') === 'vox') {
            return response()->json([
                'ok' => false,
                'error' => 'O formato Vox tem motor próprio e não usa o vídeo premium. Escolha um dos dois.',
            ], 422);
        }
        // 🎙️ NARRADOR: voz pedida no corpo (seletor da aba /video (estilo Vox) e do Studio) validada contra a
        // allowlist do provedor; vazio = a voz do tenant. Aqui, ANTES de reservar cota — id ruim
        // tem de virar 422 na hora, não um job cobrado que morre no provedor minutos depois.
        $voiceId = $this->voiceIdEscolhida($r, $t);
        if ($voiceId === false) {
            return response()->json(['ok' => false, 'error' => 'Voz de narração inválida — escolha um narrador da lista.'], 422);
        }
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        $style = (string) $r->input('style', 'realista');

        // ── PREMIUM → Veo (mesma lógica do método veo()) ───────────────────────
        if ($premium) {
            $weight = $this->usage->weightFor('veo');
            $cost = $videoModel?->cost_credits; // null → custo fixo do bucket 'veo'
            // RESERVE-THEN-CONSUME (AUD-002), cobrando o custo do MODELO premium.
            if (! $this->usage->tryConsume($t, 'veo', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Vídeo premium (Veo) não está incluído no seu plano.'], 402);
            }
            $brief = $d->research['summary'] ?? $d->research['brief'] ?? '';
            $veoStyle = (string) $r->input('style', 'cinematografico'); // Veo usa default cinematográfico
            // ASSÍNCRONO (Veo leva ~5-11min → estourava o proxy). Worker gera; front faz polling.
            GenerateVideoJob::dispatch($d->id, $t->id, '/v1/veo', [
                'keyword' => $d->keyword,
                'brief' => $brief,
                'style' => $veoStyle,
                'lang' => $lang,
                // Veo + narração própria: voz do tenant por cima do Veo mudo + legenda.
                'narration' => $narration,
                'voiceId' => $narration ? $voiceId : '',
            ], 'veo', $weight, $veoStyle, $platforms, $cost);

            return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo premium em geração — aparece na galeria em alguns minutos.']);
        }

        // ── NORMAL → /v1/video ─────────────────────────────────────────────────
        // BUCKET: short (multi-cena OU narração OU música) custa ~7× o clipe simples → bucket
        // próprio 'short', separado de 'video' (ver docs/custos-e-planos.md). 1 op = 1 unidade.
        // O premium (google/Veo) já foi tratado acima; aqui entra todo o resto do catálogo.
        // A guarda é por PROVIDER ROTEÁVEL, não por um provider específico: cobrar por um motor
        // que o engine não sabe chamar é cobrar e não entregar. Até 2026-08-04 esta linha exigia
        // provider==='kie' — e depois da saída do agregador NENHUM modelo de vídeo ativo é 'kie',
        // então TODA escolha de modelo caía neste 422 e quem não escolhia ia sem gen_lines.
        if ($videoModel && ! $videoModel->isClipCapable()) {
            return response()->json(['ok' => false, 'error' => 'Modelo de vídeo indisponível neste formato.'], 422);
        }
        $isProduced = $scenes > 1 || $narration || $music;
        $bucket = $isProduced ? 'short' : 'video';
        $weight = 1;
        // VÍDEO v2: preço por QUALIDADE (resolução) × DURAÇÃO. A qualidade escolhida traz o preço do
        // clipe pra 5s (p5) e 10s (p10) — já com margem; casa com a cobrança por segundo/vídeo da KIE.
        // Sem qualidades (legado) → cost_credits do modelo. Refund usa o MESMO $cost.
        $chosenQ = $this->videoQuality($videoModel, $r);
        $durKey = $duration === '10' ? 'p10' : 'p5';
        $perClip = $chosenQ[$durKey] ?? $chosenQ['p5'] ?? $videoModel?->cost_credits;
        // Clipe simples = 1 clipe. Short PRODUCED (N cenas) cobra POR CENA: cada clipe é uma
        // geração paga, e N clipes sob 1 unidade é denial-of-wallet (guideline #11). Isto valia só
        // pro provider 'kie' até 2026-08-04 — com o agregador fora, todo short multi-cena passava
        // a cair no `else` e era cobrado como UMA unidade, gerando N. Modelo sem preço unitário
        // (legado, $perClip null) segue no custo fixo do bucket.
        if (! $isProduced) {
            $cost = $perClip;
        } elseif ($videoModel) {
            $cost = $perClip !== null ? $perClip * max(1, $scenes) : null;
        } else {
            $cost = null;
        }
        // RESERVE-THEN-CONSUME (AUD-002): debita o bucket do tipo antes de chamar o engine.
        if (! $this->usage->tryConsume($t, $bucket, $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }

        // videoPrompt tem precedência sobre prompt; fallback final = keyword.
        $videoPrompt = (string) $r->input('videoPrompt');
        $effectivePrompt = $videoPrompt !== '' ? $videoPrompt : ($prompt !== '' ? $prompt : $d->keyword);

        $payload = [
            'prompt' => $effectivePrompt,
            'scenes' => $scenes,
            'narration' => $narration,
            // Voz JÁ resolvida (pedida ∈ allowlist, ou a do tenant). Até 2026-08-05 esta linha
            // repassava o corpo cru e, sem seletor, ia VAZIA — o engine narrava com a voz padrão
            // dele em vez da voz da conta.
            'voiceId' => $voiceId,
            'lang' => $lang,
            'subtitles' => $subtitles,
            'music' => $music,
            'duration' => $duration,
            'style' => $style,
            // formato do vídeo: só 9:16 (vertical) ou 16:9 (horizontal); default 9:16.
            'aspect' => $r->input('aspect') === '16:9' ? '16:9' : '9:16',
            // Sprint B (acabamento Hollywood): color grade + grain — allowlist do grade.
            'grade' => self::gradeFrom($r),
            'gradeStrength' => self::gradeStrengthFrom($r),
            'grain' => filter_var($r->input('grain', false), FILTER_VALIDATE_BOOLEAN),
        ];
        if ($persona = $this->resolvePersona($r, 'video')) {
            $payload['persona'] = $persona; // direção de estilo do clipe (kind=video)
        }
        // 📰 PRESET editorial: "vox" (jornalismo explicativo animado) troca DE UMA VEZ a
        // segmentação, o motor de imagem, a linguagem visual (colagem de papel), o prompt de
        // movimento e o acabamento — o formato é um conjunto coerente, não opções soltas.
        // Allowlist: valor livre viraria campo morto no engine, sem erro nenhum e sem efeito.
        if ($r->input('preset') === 'vox') {
            $payload['preset'] = 'vox';
            // O `style` é o que a GALERIA usa pra classificar (catOf em galeria/page.tsx) — e o
            // default aqui é 'realista', que jogaria a peça no monte dos clipes simples. O Vox é
            // peça editorial ACABADA (voz conduzindo + colagem), não clipe solto: marcar no
            // SERVIDOR, e não confiar no front mandar, é o que garante que ela sempre caia na
            // aba certa, venha de onde vier a chamada.
            $style = 'vox';
            $payload['style'] = 'vox';
            // A peça é conduzida pela VOZ e o quadro é gráfico: legenda queimada por cima da
            // colagem briga com a arte, e o formato não a usa. Ligar continua sendo possível
            // explicitamente, mas o default do preset é sem.
            if (! $r->has('subtitles')) {
                $payload['subtitles'] = false;
            }
            // 🎨/🎥 Extras da aba /video (estilo Vox): direção de arte e direção de movimento LIVRES, que o
            // engine APENDA depois das direções padrão do formato (nunca no lugar delas — meia
            // direção Vox não é meio Vox). Saneados aqui (trim + teto) e de novo no engine
            // (voxExtra): o corpo vem do navegador. Vazios = padrão Vox intacto.
            if ($extra = mb_substr(trim((string) $r->input('vox_style_extra', '')), 0, 2000)) {
                $payload['vox_style_extra'] = $extra;
            }
            if ($extra = mb_substr(trim((string) $r->input('vox_direction_extra', '')), 0, 2000)) {
                $payload['vox_direction_extra'] = $extra;
            }
        }
        // ✅ ROTEIRO APROVADO: quando a tela mandou os beats que o cliente leu (e talvez editou),
        // eles vão inteiros pro engine, que PULA a segmentação. Sem isto o servidor reescreveria o
        // roteiro na hora de gerar e entregaria uma peça diferente da aprovada — e o cliente só
        // descobriria assistindo.
        //
        // ⚠️ Isto ficava DENTRO do `if preset === 'vox'` até a unificação Vídeo+Vox (2026-08-06).
        // Com o storyboard virando o layout de TODA a aba, um roteiro aprovado num estilo não-vox
        // seria descartado em silêncio e o engine re-segmentaria — a falha muda que a casa já
        // conhece. O engine aceita `beats` sem preset nenhum (ver longform.go: o ramo
        // `len(opt.Beats) > 0` é anterior a qualquer preset).
        if ($beats = self::voxBeatsFrom($r)) {
            $payload['beats'] = $beats;
        }
        // Legenda LIGADA → repassa o estilo escolhido (posição/fonte/cor/borda/caixa), pro vídeo já
        // sair com a legenda no visual certo (mesmo estilo das Histórias). Desligada → nem envia.
        if ($subtitles) {
            $payload = array_merge($payload, self::subtitleStyleFrom($r));
        }
        // base/i2v: repassa imageUrl quando presente (e já validada como nossa acima).
        if ($imageUrl !== '') {
            $payload['imageUrl'] = $imageUrl;
        }
        if ($charIds !== []) {
            // Mesmo motivo do card de Imagem: o lock se resolve AQUI (o engine não lê `charIds`).
            $payload['prompt'] = IdentityLock::aplicar((string) ($payload['prompt'] ?? ''), $t->id, $charIds);
        }
        // 🌊 Fluidez (48 fps) NÃO entra aqui: o /v1/video do engine não tem o campo `smooth` (só
        // /v1/storyvideo e /v1/filmassemble, que montam vários clipes). Mandar seria campo morto —
        // o toggle "Fluido" da aba Vídeo do Estúdio caía nesse buraco. Pra existir na Mídia, o
        // engine precisa aceitar smooth em /v1/video primeiro.
        // modelo escolhido → gen_lines.video (ver videoGenLine/GenPayload, que propaga o provider
        // e o spec do motor). Vale tanto pro clipe simples quanto pros clipes do short produzido.
        // Sem gen_lines o engine fica SEM provider e todo clipe morre em "erro de config" — era o
        // que acontecia com qualquer modelo não-'kie' desde a saída do agregador.
        if ($videoModel) {
            $payload['gen_lines'] = $this->videoGenLine($videoModel, $chosenQ); // mescla a resolução da qualidade escolhida
        }
        // ASSÍNCRONO: o worker chama o engine (minutos) e anexa o vídeo; o front faz polling.
        // Síncrono aqui estourava o timeout do proxy → resposta cortada → "undefined" na UI.
        GenerateVideoJob::dispatch($d->id, $t->id, '/v1/video', $payload, $bucket, $weight, $style, $platforms, $cost);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo em geração — aparece na galeria em alguns minutos.']);
    }

    /**
     * 🎞️ MOTION — anima uma TELA ESTÁTICA JÁ APROVADA num clipe de motion graphics (Fatia 2).
     *
     * O GATE DE APROVAÇÃO existe por dinheiro: a tela estática custa pouco (uma imagem), o vídeo
     * custa caro. Refazer a tela não passa por aqui e não cobra vídeo; só quando o usuário aprova
     * o quadro é que este endpoint enfileira o clipe. É o que evita queimar crédito num quadro
     * errado — e é por isso que ele é um endpoint separado do `media`, e não uma flag dele.
     *
     * O prompt NÃO vem do cliente: chega estrutura + duração + frases, e o MotionPrompt monta o
     * texto com a marcação de tempo e as duas regras fixas. Estrutura e técnica são allowlist
     * fechada porque viram texto no prompt.
     *
     * Roteia pelo MESMO caminho de vídeo que já funciona (/v1/video + modelo KIE) — o Higgsfield
     * ainda não é roteado pelo engine.
     */
    public function motionClip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        // Anti-SSRF: a URL da tela vem do CLIENTE. Só mídia NOSSA vira referência do clipe.
        $tela = self::ownMediaUrl($r->input('imageUrl'));
        if ($tela === null) {
            return response()->json(['ok' => false, 'error' => 'Tela aprovada inválida — anime uma imagem gerada aqui.'], 422);
        }

        $estrutura = MotionPrompt::estrutura($r->input('structure'));
        $tecnica = MotionPrompt::tecnica($r->input('style'));
        $pedida = MotionPrompt::duracao($r->input('seconds'));
        $frases = MotionPrompt::frases($r->input('lines', []));
        if ($estrutura === 'cartelas' && $frases === []) {
            return response()->json(['ok' => false, 'error' => 'Escreva ao menos uma frase para as cartelas.'], 422);
        }

        // Modelo de vídeo: o engine não tem default (2026-07-22) — sem gen_lines a chamada falha.
        // Precisa ser um provider que o engine ROTEIA; qualquer outro seria cobrado e não aplicado.
        // (Até 2026-08-04 exigia provider==='kie' — sem nenhum kie ativo, o card Motion inteiro
        // ficou inalcançável: todo clique morria neste 422.)
        $videoModel = GenModel::resolveSelectable($r->input('model'), 'video', $t->plan);
        if (! $videoModel || ! $videoModel->isClipCapable()) {
            return response()->json(['ok' => false, 'error' => 'Escolha um modelo de vídeo disponível no seu plano.'], 422);
        }

        [$duration, $segundos] = MotionPrompt::clipe($pedida);
        $chosenQ = $this->videoQuality($videoModel, $r);
        $cost = ($chosenQ[$duration === '10' ? 'p10' : 'p5'] ?? $chosenQ['p5'] ?? null) ?? $videoModel->cost_credits;

        $draftId = $r->input('draftId');
        $d = $draftId
            ? $this->draft($r, $draftId)
            : Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr((string) $r->input('description') ?: 'motion', 0, 80)]);

        // Clipe simples (1 cena, sem narração/música) → bucket 'video', 1 operação.
        // RESERVE-THEN-CONSUME: debita antes de enfileirar.
        if (! $this->usage->tryConsume($t, 'video', 1, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para vídeo.'], 402);
        }

        $prompt = MotionPrompt::build((string) $r->input('description', ''), $estrutura, $segundos, $frases, $tecnica);

        $payload = [
            'prompt' => $prompt,
            'scenes' => 1,
            'narration' => false,   // motion não tem locução (regra fixa do prompt)
            'subtitles' => false,   // o texto já está na tela / nas cartelas
            'music' => false,
            'duration' => $duration,
            'style' => $tecnica,
            'aspect' => $r->input('aspect') === '16:9' ? '16:9' : '9:16',
            'imageUrl' => $tela,    // ⬅️ o coração do card: anima A PARTIR do quadro aprovado
            'gen_lines' => $this->videoGenLine($videoModel, $chosenQ),
        ];

        GenerateVideoJob::dispatch(
            $d->id, $t->id, '/v1/video', $payload, 'video', 1, $tecnica,
            Networks::only($r->input('platforms', [])), $cost
        );

        return response()->json([
            'ok' => true,
            'draftId' => $d->id,
            'seconds' => $segundos,
            // O seletor oferece 4-15s (limite do modelo), mas o caminho roteado hoje só produz
            // clipe de 6s ou 10s. Devolver a duração efetiva evita prometer o que não sai.
            'message' => $segundos === $pedida
                ? 'Animando a tela aprovada — o clipe aparece na galeria em alguns minutos.'
                : "Animando a tela aprovada em {$segundos}s (a duração mais próxima que o motor de vídeo entrega) — o clipe aparece na galeria em alguns minutos.",
        ]);
    }

    /** Scaffold de LOGOTIPO por preset. `$subject` é o conceito do usuário (ex: "uma raposa para
     *  marca de tecnologia"); o preset define o LOOK. Texto em IA é fraco → presets são icônicos
     *  (o de lettering pede letras curtas/nítidas). O estilo 'logo' (engine) faz o resto do tratamento. */
    private function logoPrompt(string $preset, string $subject): string
    {
        $subject = trim($subject) ?: 'a brand';
        $tpl = [
            'minimalista' => 'A minimalist flat vector logo of %s. Simple geometric icon, clean lines, minimal solid colors, generous negative space, modern and timeless brand mark.',
            'mascote' => 'A friendly mascot logo of %s. Bold cartoon character emblem, thick clean outlines, vibrant colors, expressive and memorable brand mascot.',
            'emblema' => 'A vintage emblem/badge logo of %s. Circular badge with fine linework, retro heritage crest style, symmetric, seal-like brand emblem.',
            'moderno' => 'A modern app-icon style logo of %s. Smooth gradient, rounded geometric icon, glossy premium tech look, bold and clean.',
            'lettering' => 'A clean lettermark/monogram logo of %s. Bold geometric letterforms, tight kerning, iconic wordmark; keep any letters short, crisp and correctly spelled.',
        ];

        return sprintf($tpl[$preset] ?? $tpl['minimalista'], $subject);
    }

    /** Transições entre cenas/trechos (F1) — kinds permitidos (espelha TRANSITIONS do
     *  ffmpeg-service). Duração-preservada (fadeblack/fadewhite) vale nas Histórias; xfade
     *  completo só no Filme (o serviço degrada sozinho quando não pode). */
    public const TRANSITION_KINDS = ['cut', 'fadeblack', 'fadewhite', 'fade', 'slideleft', 'slideright', 'wipeleft', 'wiperight', 'circleopen', 'zoomin'];

    /** Filtros de color grade (F2 — "filtros Instagram", allowlist compartilhada Histórias/Filme/
     *  Mídia). 'natural' = sem filtro (grátis); os demais cobram 2 créd (bucket effect). */
    public const GRADES = ['natural', 'cinema_quente', 'teal_orange', 'noir', 'vintage',
        'dourado', 'gelo', 'pastel', 'tropical', 'drama', 'pb_suave', 'retro_vhs'];

    /** VFX por cena/trecho (F4) — efeitos visuais determinísticos do ffmpeg-service. */
    public const VFX_KINDS = ['shake', 'zoom_pulse', 'punch_in', 'glitch', 'vhs', 'freeze'];

    /** Sanitiza o grade do request (allowlist) — desconhecido/vazio cai em 'natural'. */
    public static function gradeFrom(Request $r): string
    {
        return in_array($r->input('grade'), self::GRADES, true) ? (string) $r->input('grade') : 'natural';
    }

    /** Intensidade do filtro (F2): 1..99 = blend com o original; 0/100/ausente = look cheio. */
    public static function gradeStrengthFrom(Request $r): int
    {
        $v = (int) $r->input('gradeStrength', 100);

        return ($v > 0 && $v < 100) ? $v : 0;
    }

    /** Papéis que uma imagem de referência pode ter numa geração i2i. Uma referência sem papel é
     *  ambígua: o modelo não sabe se deve copiar o ASSUNTO dela, o ENQUADRAMENTO ou só a paleta —
     *  e costuma copiar tudo, virando quase uma cópia da referência. Nomear o papel é o que torna
     *  útil mandar mais de uma. */
    public const REF_ROLES = ['identidade', 'composicao', 'estilo'];

    /** Instrução por papel, em inglês (o i2i entende melhor) — cada uma diz o que APROVEITAR
     *  daquela imagem e, tão importante quanto, o que IGNORAR. */
    /*  Redação POSITIVA de propósito ("take ONLY X from it"), sem "ignore o assunto"/"não copie".
     *  A versão com negativas fez o modelo devolver quase uma cópia da 2ª referência: citar o que
     *  NÃO fazer coloca aquilo no prompt e o modelo atende. Mesma lição já registrada na sessão de
     *  2026-07-16 (conflito positivo×negativo). Verificado: raposa+deserto virava só o deserto. */
    private const REF_ROLE_TEXT = [
        'identidade' => 'take the SUBJECT from it — reproduce that exact character/product, same design, colors, proportions and markings',
        'composicao' => 'take ONLY the framing from it — camera angle, subject placement and depth',
        'estilo' => 'take ONLY the look from it — color palette, lighting quality, texture and finish',
    ];

    /** Enumera as referências por PAPEL pro prompt ("image 1 = ..."). Espelha o refsEnumText do
     *  FilmController, mas ali toda ref é identidade; aqui cada uma tem função própria. */
    public static function refsRoleText(array $urls, array $roles): string
    {
        if ($urls === []) {
            return '';
        }
        $parts = [];
        foreach (array_values($urls) as $k => $_) {
            $papel = $roles[$k] ?? 'identidade';
            $papel = in_array($papel, self::REF_ROLES, true) ? $papel : 'identidade';
            $parts[] = 'from image '.($k + 1).', '.self::REF_ROLE_TEXT[$papel];
        }

        return ' USE THE REFERENCE IMAGES FOR DIFFERENT THINGS, in order: '.implode('; ', $parts)
            .'. The scene described above is what to render; the references only supply those specific aspects.';
    }

    /** Aviso de que a geração usou um motor DIFERENTE do escolhido (o pedido não resolveu pro
     *  plano ou saiu do catálogo, e o fallback assumiu). Trocar em silêncio era o problema: o
     *  cliente escolhia um motor, recebia outro e não tinha como saber. White-label: mostra o
     *  nome de exibição do catálogo, nunca o provedor. */
    public static function avisoModeloTrocado(?string $pedido, ?GenModel $usado): ?string
    {
        if (! $pedido || ! $usado || $usado->slug === $pedido) {
            return null;
        }

        return 'O modelo escolhido não está disponível no seu plano — geramos com o '
            .($usado->display_name ?: 'padrão').'.';
    }

    /** Proporção DA IMAGEM DE ORIGEM, encaixada na allowlist de aspect.
     *
     *  Existe porque toda operação de pós-processamento (tirar fundo, reiluminar, fundo novo…)
     *  caía num `1:1` fixo: uma foto 9:16 de Reels voltava QUADRADA, com o enquadramento
     *  destruído — e vertical é justamente o formato mais usado. Editar uma imagem não deve
     *  mudar o formato dela; quem quer outro formato tem o "Adaptar" na galeria.
     *  Se não der pra medir (imagem externa, arquivo grande), devolve o fallback. */
    public static function aspectOfImage(string $url, string $fallback = '1:1'): string
    {
        $m = Draft::imageMeta($url);
        $w = (int) ($m['w'] ?? 0);
        $h = (int) ($m['h'] ?? 0);
        if ($w <= 0 || $h <= 0) {
            return $fallback;
        }
        $ratio = $w / $h;
        $best = $fallback;
        $dist = PHP_FLOAT_MAX;
        foreach (['9:16' => 9 / 16, '4:5' => 4 / 5, '1:1' => 1.0, '16:9' => 16 / 9] as $k => $v) {
            $d = abs($ratio - $v);
            if ($d < $dist) {
                $dist = $d;
                $best = $k;
            }
        }

        return $best;
    }

    /** Aplica o color grade numa imagem RECÉM-GERADA (t2i/i2i), devolvendo a URL já tratada.
     *
     *  Diferente do `imageFilter()` avulso, aqui o filtro faz parte da geração e por isso é
     *  EMBUTIDO — não cobra o bucket `effect`. É o mesmo contrato do vídeo, onde escolher o
     *  grade na montagem não custa crédito à parte: a cor é parâmetro da peça, não um extra.
     *  Best-effort de propósito — se o ffmpeg-service falhar, devolve a imagem original em vez
     *  de derrubar (e desperdiçar) uma geração que já foi paga e deu certo. */
    public static function gradeGeneratedImage(string $url, string $grade, int $strength = 0): string
    {
        if ($url === '' || $grade === '' || $grade === 'natural') {
            return $url;
        }
        try {
            $res = EngineClient::make(60)
                ->post('/v1/imagefilter', ['imageUrl' => $url, 'grade' => $grade, 'strength' => $strength]);
            $out = $res->json('url');
            if (is_string($out) && $out !== '') {
                return $out;
            }
            // Silêncio aqui foi o que escondeu a falha: o filtro não aplicava e a imagem saía sem
            // cor nenhuma, sem erro em lugar nenhum. Best-effort NÃO pode ser indiagnosticável.
            Log::warning('gradeGeneratedImage: filtro não devolveu URL; imagem fica sem cor', [
                'grade' => $grade, 'strength' => $strength, 'status' => $res->status(),
                'body' => mb_substr((string) $res->body(), 0, 300), 'imageUrl' => $url,
            ]);

            return $url;
        } catch (\Throwable $e) {
            Log::warning('gradeGeneratedImage falhou; mantém a imagem sem filtro', [
                'grade' => $grade, 'erro' => $e->getMessage(),
            ]);

            return $url;
        }
    }

    /** Sanitiza transições do request -> [camposDePayload, nEfeitosCobraveis]. transitionCuts[i]
     *  = transição entre o segmento i e i+1 ('' = default). Cobrança: 1 créd (bucket effect) por
     *  corte com transição efetiva (diferente de cut). */
    public static function transitionParams(Request $r, int $nCuts): array
    {
        $default = in_array($r->input('transitionDefault'), self::TRANSITION_KINDS, true) ? (string) $r->input('transitionDefault') : 'cut';
        $dur = min(1.5, max(0.2, (float) $r->input('transitionDur', 0.5)));
        $cuts = [];
        foreach (array_values((array) $r->input('transitionCuts', [])) as $i => $k) {
            if ($i >= $nCuts) {
                break;
            }
            $cuts[] = in_array($k, self::TRANSITION_KINDS, true) ? (string) $k : '';
        }
        $count = 0;
        for ($i = 0; $i < $nCuts; $i++) {
            $k = ($cuts[$i] ?? '') !== '' ? $cuts[$i] : $default;
            if ($k !== 'cut') {
                $count++;
            }
        }

        return [[
            'transitionDefault' => $default === 'cut' ? '' : $default,
            'transitionDur' => $dur,
            'transitionCuts' => $cuts,
        ], $count];
    }

    /** Estilo da legenda QUEIMADA (posição/fonte/tamanho/cor/borda/caixa) sanitizado do request —
     *  MESMO contrato de /v1/storyvideo e /v1/video. Allowlist (posição/fonte) + clamps (tamanho/
     *  borda/opacidade) + validação de cor (#RRGGBB): protege o filtro do ffmpeg de valor arbitrário.
     *  Usado tanto na Mídia (vídeo já sai com a legenda no estilo) quanto nas Histórias. */
    public static function subtitleStyleFrom(Request $r): array
    {
        return [
            'subtitlePos' => in_array($r->input('subtitlePos'), ['top', 'middle'], true) ? $r->input('subtitlePos') : 'bottom',
            'subtitleSize' => max(0, min(72, (int) $r->input('subtitleSize', 0))),
            'subtitleColor' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r->input('subtitleColor')) ? (string) $r->input('subtitleColor') : '',
            'subtitleBorder' => max(0, min(12, (int) $r->input('subtitleBorder', 0))),
            'subtitleBorderColor' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r->input('subtitleBorderColor')) ? (string) $r->input('subtitleBorderColor') : '',
            'subtitleFont' => in_array($r->input('subtitleFont'), ['sans', 'serif', 'mono', 'dejavu', 'dejavu-serif', 'noto'], true) ? $r->input('subtitleFont') : '',
            'subtitleOpacity' => max(0, min(90, (int) $r->input('subtitleOpacity', 0))),
            'subtitleBg' => filter_var($r->input('subtitleBg', false), FILTER_VALIDATE_BOOLEAN),
            'subtitleBgColor' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r->input('subtitleBgColor')) ? (string) $r->input('subtitleBgColor') : '',
            'subtitleBgOpacity' => max(0, min(100, (int) $r->input('subtitleBgOpacity', 60))),
            // 🎞️ Legenda ANIMADA (overlay Remotion no caption-service): preset allowlistado; vazio =
            // legenda queimada de sempre. accent = cor de realce da palavra ativa (só modo animado).
            'subtitleAnim' => in_array($r->input('subtitleAnim'), ['pop', 'karaoke', 'bounce', 'vox'], true) ? $r->input('subtitleAnim') : '',
            'subtitleAccentColor' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $r->input('subtitleAccentColor')) ? (string) $r->input('subtitleAccentColor') : '',
        ];
    }

    /** POST /api/studio/enhance { draftId?, imageUrl, op } — PÓS-PROCESSA uma imagem já existente da
     *  galeria (NÃO gera do zero): op=upscale (melhorar) | upscale_pro (melhorar Pro/mais nítido) |
     *  remove_bg (remover fundo). Cobra créditos (bucket 'image', reserve-then-consume, estorna se
     *  falhar) e ANEXA o resultado à galeria do rascunho. `imageUrl` DEVE ser do nosso S3 (anti-SSRF).
     *  Os modelos vivem no catálogo com kind='edit' (isolado dos seletores de geração de imagem/vídeo). */
    /**
     * POST /api/studio/reformat { imageUrl, formats[] } → 🖼️ mesma arte em VÁRIAS proporções.
     *
     * Custo ZERO de IA (GD puro): a peça já aprovada é reenquadrada, não regerada. Antes, publicar
     * a mesma arte no feed, no story e no YouTube custava 3 gerações — e as três saíam diferentes
     * entre si, porque cada geração é um sorteio. Aqui é literalmente a mesma imagem.
     *
     * Corte pequeno vira cover centrado; corte agressivo (1:1 → 9:16, ~44%) conteria o sujeito
     * fora do quadro, então a peça inteira entra sobre um fundo dela mesma borrado — a lição do
     * pad_fit do ffmpeg-service, sem a tarja preta.
     */
    public function reformat(Request $r): JsonResponse
    {
        $this->tenant($r); // escopo/plano do tenant (SSO)
        $imageUrl = trim((string) $r->input('imageUrl', ''));
        if (! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422); // anti-SSRF
        }
        $pedidos = array_values(array_unique(array_filter(
            (array) $r->input('formats', []),
            fn ($f) => is_string($f) && isset(FormatVariant::SIZES[$f])
        )));
        if ($pedidos === []) {
            return response()->json([
                'ok' => false, 'error' => 'Escolha ao menos um formato.',
                'supported' => array_keys(FormatVariant::SIZES),
            ], 422);
        }
        $raw = @file_get_contents($imageUrl);
        if ($raw === false || $raw === '') {
            return response()->json(['ok' => false, 'error' => 'Não deu pra ler a imagem.'], 502);
        }
        $variants = [];
        foreach ($pedidos as $f) {
            [$w, $h] = FormatVariant::SIZES[$f];
            $bytes = FormatVariant::render($raw, $w, $h);
            if ($bytes === null) {
                continue; // GD ausente ou imagem ilegível — o caller vê o que faltou pela lista
            }
            $variants[] = [
                'format' => $f,
                'url' => self::storeMedia($bytes, 'jpg', 'image'),
                'w' => $w,
                'h' => $h,
            ];
        }
        if ($variants === []) {
            return response()->json(['ok' => false, 'error' => 'Não foi possível reenquadrar esta imagem.'], 500);
        }

        return response()->json(['ok' => true, 'variants' => $variants]);
    }

    public function enhance(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $imageUrl = trim((string) $r->input('imageUrl', ''));
        if (! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }
        $op = (string) $r->input('op');
        if (! in_array($op, ['upscale', 'upscale_pro', 'remove_bg', 'relight'], true)) {
            return response()->json(['ok' => false, 'error' => 'operação inválida'], 400);
        }
        // REILUMINAR: refaz a luz da foto sem trocar o assunto — a instrução são os params
        // (direção, dureza, temperatura, intensidade), não um prompt. Caminho próprio porque o
        // Magnific não tem equivalente; roda pelo bridge, no modelo hf-nano-banana-2-relight.
        if ($op === 'relight') {
            return $this->relight($r, $t, $imageUrl);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'mídia avulsa']);
        $weight = $this->usage->weightFor('image');
        // ⚠️ O recraft (edit-upscale / edit-remove-bg) está em OUTAGE no KIE ("internal error").
        // Rota pros modelos que funcionam: upscale → topaz (edit-upscale-pro); remover fundo →
        // i2i nano-banana-2 (troca por fundo cinza liso). Reduz a origem antes (pós-proc engasga com 4k).
        $src = self::downscaledImageUrl($imageUrl, 1536);
        $styleTag = $op === 'remove_bg' ? 'sem-fundo' : 'melhorada';
        $url = null;
        $cost = null;

        if ($op === 'remove_bg') {
            $cost = GenModel::resolveSelectable('img-referencia', 'image', $t->plan)?->cost_credits;
            if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            // 360 > 300 do engine > 280 do bridge (cursor): cada elo de fora dá folga pro de
            // dentro, pra que o erro venha explicado de quem sabe o motivo, não como timeout seco.
            $url = $this->engine()->timeout(360)->post('/v1/image', [
                'prompt' => 'Keep the EXACT same subject completely unchanged. ONLY replace the entire '
                    .'background with a plain seamless solid light-gray studio backdrop; remove all scenery, '
                    .'furniture, decorations and anything behind. Even studio lighting, no cast shadows.',
                // Preserva o formato de quem entrou: tirar o fundo de uma foto 9:16 devolvia
                // quadrado e cortava o assunto. Ver aspectOfImage().
                'aspect' => self::aspectOfImage($src),
                'style' => 'realista',
                'imageUrls' => [$src],
                'anchorIdentity' => true,
            ])->json('url');
        } else { // upscale/upscale_pro → topaz (o recraft crisp-upscale também está fora)
            $gm = GenModel::resolveSelectable('edit-upscale-pro', 'edit', $t->plan);
            if (! $gm) {
                return response()->json(['ok' => false, 'error' => 'operação indisponível'], 404);
            }
            $cost = $gm->cost_credits;
            if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
                return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
            }
            $url = $this->engine()->timeout(300)->post('/v1/enhance', GenPayload::enhancePayload($gm, $src))->json('url');
        }
        if (! $url) {
            $this->usage->refund($t, 'image', $weight, $cost); // processamento falhou → estorna

            return response()->json(['ok' => false, 'error' => 'processamento não retornou imagem'], 502);
        }
        $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => $styleTag, 'platforms' => []], Draft::imageMeta($url));
        $media = $this->attachImageLocked($d->id, $item, null);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
    }

    /**
     * Direção da luz, no código de 3 letras que o modelo publica: [profundidade][altura][lado].
     *   1ª  f = frente (front) · m = lateral (middle) · b = contraluz (back)
     *   2ª  u = alta (up) · m = na altura do rosto · d = baixa (down)
     *   3ª  l = esquerda · m = centro · r = direita
     * Ex.: "fdl" = de frente, baixa, pela esquerda. A allowlist é explícita porque valor fora do
     * enum é DESCARTADO em silêncio pelo bridge — a peça sairia com a luz padrão e o cliente
     * pagaria por um ajuste que não aconteceu.
     */
    private const LUZ_DIRECOES = [
        'mdl', 'mdr', 'mul', 'mur', 'bml', 'fml', 'fmr', 'bmm', 'mml', 'mmr', 'fmm', 'bmr', 'mdm',
        'mum', 'bdr', 'fdl', 'bur', 'ful', 'bdl', 'fdr', 'bul', 'fur', 'bdm', 'fdm', 'bum', 'fum',
    ];

    private const LUZ_QUALIDADES = ['hard', 'sharp', 'soft'];

    /**
     * REILUMINAR (op=relight): refaz a iluminação da foto mantendo o assunto.
     *
     * Diferente do relight do easyapp — que descreve a luz num prompt i2i e torce pelo resultado —
     * aqui o modelo é dedicado e recebe a direção como PARÂMETRO: dá para pedir contraluz alta
     * pela direita e receber exatamente isso, com a identidade preservada (verificado no par
     * antes/depois de 29/08).
     *
     * Cobra como as demais operações de imagem (reserve-then-consume, estorna se falhar).
     */
    private function relight(Request $r, $t, string $imageUrl): JsonResponse
    {
        $gm = GenModel::resolveSelectable('hf-nano-banana-2-relight', 'image', $t->plan);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'operação indisponível'], 404);
        }

        $direcao = (string) $r->input('light_source', 'fml');
        $qualidade = (string) $r->input('light_quality', 'soft');
        if (! in_array($direcao, self::LUZ_DIRECOES, true) || ! in_array($qualidade, self::LUZ_QUALIDADES, true)) {
            return response()->json(['ok' => false, 'error' => 'direção ou tipo de luz inválido'], 422);
        }
        // Intensidade 0-100: o schema declara integer sem faixa, e um valor absurdo faria o
        // upstream recusar o job DEPOIS de já termos reservado o crédito.
        $brilho = max(0, min(100, (int) $r->input('brightness', 50)));
        $cor = trim((string) $r->input('color', 'neutral'));
        if ($cor === '' || mb_strlen($cor) > 40) {
            $cor = 'neutral';
        }

        $weight = $this->usage->weightFor('image');
        $cost = $gm->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }

        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'mídia avulsa']);
        // Reduz a origem como o resto da pós-produção: o modelo devolve em alta, e mandar 4k na
        // entrada só engorda o upload sem melhorar o resultado.
        $src = self::downscaledImageUrl($imageUrl, 1536);

        $url = $this->engine()->timeout(360)->post('/v1/enhance', [
            'imageUrl' => $src,
            'provider' => 'cli-bridge',
            'model' => $gm->provider_model_id,
            'ext' => 'png',
            'params' => [
                'light_source' => $direcao,
                'light_quality' => $qualidade,
                'brightness' => (string) $brilho,   // o bridge só aceita extras como string
                'color' => $cor,
                'remove_bg' => $r->boolean('remove_bg') ? 'true' : 'false',
            ],
        ])->json('url');

        if (! $url) {
            $this->usage->refund($t, 'image', $weight, $cost);

            return response()->json(['ok' => false, 'error' => 'a reiluminação não retornou imagem'], 502);
        }

        $item = array_merge(
            ['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => 'reiluminada', 'platforms' => []],
            Draft::imageMeta($url)
        );
        $media = $this->attachImageLocked($d->id, $item, null);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
    }

    /**
     * POST /api/studio/easyapp { kind, imageUrl, draftId?, aspect?, params? } — EasyApp 1-clique de
     * pós-produção de imagem (aba Rápido / painel Ajustes, benchmark Nordy). `kind`:
     *  - upscale     → delega ao enhance() (topaz/KIE-edit; melhora nitidez)
     *  - bg_remove   → delega ao enhance() (i2i fundo cinza liso)
     *  - relight     → i2i que troca SÓ a iluminação (params.mood opcional)
     *  - product_bg  → i2i que troca SÓ o fundo do produto (params.background opcional)
     *  - bg_change   → i2i que troca SÓ o fundo, sujeito intacto (params.background opcional)
     *  - cloth_change→ i2i que troca SÓ a roupa; EXIGE characterId da biblioteca (identidade travada; params.outfit)
     *  - face_detail → i2i que REFINA o rosto existente (nunca swap de terceiro — anti-deepfake)
     * Cobra créditos (bucket 'image', reserve-then-consume, estorna se falhar) e anexa o resultado à
     * galeria do rascunho. `imageUrl` DEVE ser do nosso S3 (anti-SSRF). Mesmo contrato de resposta do
     * enhance() ({ok, draftId, item, media}).
     */
    public function easyapp(Request $r): JsonResponse
    {
        $kind = (string) $r->input('kind');
        // upscale/bg_remove: REUSA o enhance() (mesmo modelo/engine/cobrança) — só traduz kind→op.
        if ($kind === 'upscale') {
            $r->merge(['op' => 'upscale']);

            return $this->enhance($r);
        }
        if ($kind === 'bg_remove') {
            $r->merge(['op' => 'remove_bg']);

            return $this->enhance($r);
        }
        // i2i via /v1/image (mesmo caminho i2i do remove_bg do enhance). relight/product_bg + os de
        // IDENTIDADE (P1, com guardrails anti-deepfake): cloth_change (SÓ com personagem da biblioteca),
        // face_detail (REFINO, nunca swap), bg_change (troca fundo, sujeito intacto).
        $imgKinds = ['relight', 'product_bg', 'cloth_change', 'face_detail', 'bg_change'];
        if (! in_array($kind, $imgKinds, true)) {
            return response()->json(['ok' => false, 'error' => 'operação inválida'], 400);
        }

        $t = $this->tenant($r);
        $imageUrl = trim((string) $r->input('imageUrl', ''));
        if (! self::isOwnMediaUrl($imageUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }

        // GUARDRAIL cloth_change: exige personagem da biblioteca do PRÓPRIO tenant (identidade âncora).
        // Proibido face-swap de terceiro — face_detail é refino, não troca de pessoa.
        $lock = '';
        if ($kind === 'cloth_change') {
            $c = $r->input('characterId') ? Character::where('tenant_id', $t->id)->find((int) $r->input('characterId')) : null;
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Trocar a roupa exige um personagem da sua biblioteca (identidade travada).'], 422);
            }
            $lock = mb_substr(trim((string) $c->lock), 0, 1200);
        }

        // Sem `aspect` no request, herda o formato DA IMAGEM que está sendo editada — não um
        // 1:1 fixo. A grade da Mídia chama easyapp sem aspect (só o Rápido manda), então
        // "Reiluminar"/"Fundo novo" numa foto 9:16 devolviam quadrado. Ver aspectOfImage().
        $aspect = in_array($r->input('aspect'), ['1:1', '9:16', '16:9', '4:5'], true)
            ? (string) $r->input('aspect')
            : self::aspectOfImage($imageUrl);
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'mídia avulsa']);
        $weight = $this->usage->weightFor('image');
        $src = self::downscaledImageUrl($imageUrl, 1536); // pós-proc engasga com 4k; re-valida isOwnMediaUrl

        $mood = mb_substr(trim((string) ($r->input('params.mood') ?? $r->input('mood', ''))), 0, 120);
        $bg = mb_substr(trim((string) ($r->input('params.background') ?? $r->input('background', ''))), 0, 200);
        $outfit = mb_substr(trim((string) ($r->input('params.outfit') ?? $r->input('outfit', ''))), 0, 200);

        [$prompt, $styleTag] = match ($kind) {
            'relight' => [
                'Keep the EXACT same subject, framing and background unchanged. ONLY change the LIGHTING: '
                    .($mood !== '' ? "relight to this mood/lighting: {$mood}. " : 'relight with more cinematic, flattering studio lighting. ')
                    .'Adjust light direction, color temperature and shadows realistically. Do NOT alter identity, pose, composition or the background contents.',
                'relight',
            ],
            'product_bg' => [
                'Keep the EXACT same product completely unchanged (shape, label, text, colors, proportions). '
                    .'ONLY replace the background with: '.($bg !== '' ? $bg : 'a clean, tasteful studio backdrop that complements the product')
                    .'. Blend lighting and soft contact shadows realistically. Do NOT alter, distort or restyle the product itself.',
                'novo-fundo',
            ],
            'bg_change' => [
                'Keep the EXACT same subject completely unchanged (identity, face, body, pose, clothing, framing). '
                    .'ONLY replace the background with: '.($bg !== '' ? $bg : 'a clean, tasteful backdrop')
                    .'. Match lighting and perspective realistically. Do NOT alter the subject in any way.',
                'novo-fundo',
            ],
            'cloth_change' => [
                'Keep the EXACT same person and identity: '.($lock !== '' ? $lock.'. ' : '')
                    .'Preserve the face, hair, body proportions, pose, framing and background completely unchanged. '
                    .'ONLY change the OUTFIT/clothing to: '.($outfit !== '' ? $outfit : 'a different, tasteful outfit that fits the scene')
                    .'. Do NOT change the person, their face or the background.',
                'nova-roupa',
            ],
            'face_detail' => [
                'Refine and enhance the facial DETAIL and skin texture of the existing subject: sharpen eyes, '
                    .'natural skin pores and hair strands, remove blur and compression artifacts. '
                    .'This is a REFINEMENT only — keep the EXACT same identity, features, expression, pose and background. '
                    .'Do NOT replace the face with a different person, do NOT beautify beyond realism.',
                'rosto-detalhado',
            ],
        };

        $cost = GenModel::resolveSelectable('img-referencia', 'image', $t->plan)?->cost_credits;
        if (! $this->usage->tryConsume($t, 'image', $weight, $cost)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para este tipo de mídia.'], 402);
        }
        $url = $this->engine()->timeout(300)->post('/v1/image', [
            'prompt' => $prompt,
            'aspect' => $aspect,
            'style' => 'realista',
            'imageUrls' => [$src],
            'anchorIdentity' => true,
        ])->json('url');
        if (! $url) {
            $this->usage->refund($t, 'image', $weight, $cost); // processamento falhou → estorna

            return response()->json(['ok' => false, 'error' => 'processamento não retornou imagem'], 502);
        }
        $item = array_merge(['id' => self::mediaId(), 'kind' => 'image', 'url' => $url, 'style' => $styleTag, 'platforms' => []], Draft::imageMeta($url));
        $media = $this->attachImageLocked($d->id, $item, null);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'item' => $item, 'media' => $media]);
    }

    /** DELETE /api/studio/media { draftId, id } → remove item da galeria. */
    public function mediaDelete(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $id = (string) $r->input('id');
        $media = array_values(array_filter($d->media ?? [], fn ($m) => ($m['id'] ?? null) !== $id));
        $d->update(['media' => $media]);

        return response()->json(['ok' => true, 'media' => $media]);
    }

    /** POST /api/studio/media-clear { draftId } → LIMPA toda a mídia do rascunho atual (zera o
     *  array `media`). Só remove as referências deste rascunho — NÃO apaga objetos do S3 nem mídia
     *  já publicada. Serve pra desafogar a galeria da Mídia sem precisar trocar de rascunho. */
    public function mediaClear(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $d->update(['media' => []]);

        return response()->json(['ok' => true, 'media' => []]);
    }

    /**
     * DELETE /api/media/item { draft_id, id } → remove um item de mídia da GALERIA.
     *
     * Diferente do mediaDelete (que opera no rascunho "atual"), aqui o item pode estar
     * em QUALQUER rascunho do tenant — a galeria achata a mídia de todos. Por isso o
     * draft_id vem do front.
     *
     * Isolamento multi-tenant: o Draft é carregado escopado por tenant_id explícito
     * (defesa em profundidade) + global scope do trait BelongsToTenant; 404 se não for
     * do tenant logado. Só remove mídia de rascunho do próprio tenant.
     *
     * Se a URL for do nosso storage (s3.example.com / disco `media`), tenta apagar
     * o objeto no Scality/S3 também — em try/catch, pra não falhar se o objeto já sumiu.
     */
    public function mediaListDelete(Request $r): JsonResponse
    {
        $draftId = $r->input('draft_id', $r->input('draftId'));
        $d = $this->draft($r, $draftId); // escopado por tenant + firstOrFail (404)
        $id = (string) $r->input('id');

        $removed = collect($d->media ?? [])->firstWhere('id', $id);
        $media = array_values(array_filter($d->media ?? [], fn ($m) => ($m['id'] ?? null) !== $id));
        $d->update(['media' => $media]);

        // Apaga o objeto no nosso storage (Scality/S3) só se a URL for do nosso domínio
        // de mídia. URLs mortas/externas (fal.media expirado etc.) são ignoradas.
        $url = (string) ($removed['url'] ?? '');
        if ($url !== '' && self::isOwnMediaUrl($url)) {
            try {
                $base = rtrim((string) config('filesystems.disks.media.url'), '/');
                $key = ltrim((string) parse_url(substr($url, 0, strlen($base)) === $base ? substr($url, strlen($base)) : $url, PHP_URL_PATH), '/');
                if ($key !== '') {
                    Storage::disk('media')->delete($key);
                }
            } catch (\Throwable $e) {
                // objeto já não existe / storage indisponível → seguimos (o item já saiu do array)
            }
        }

        return response()->json(['ok' => true, 'media' => $media]);
    }

    /**
     * Valida o alvo da publicação (redes com texto, perfis do tenant, subreddit) e devolve o
     * payload de `publish`. Compartilhado por submit (publicar agora) e schedule (agendar) — os
     * dois precisam exatamente das mesmas regras, e duplicá-las era garantir que divergissem.
     *
     * @return array{0: array<string,mixed>|null, 1: JsonResponse|null} [payload, erro]
     */
    private function publishTarget(Request $r, Draft $d, Tenant $t, string $state): array
    {
        $texts = $d->texts ?? [];
        $withText = array_keys(array_filter($texts, fn ($v) => trim((string) $v) !== ''));
        if ($withText === []) {
            return [null, response()->json(['ok' => false, 'error' => 'nada pra publicar (gere textos primeiro)'], 400)];
        }
        // #5 publicar em lote/seleção: se o cliente mandou `platforms`, publica SÓ essas (∩ redes com texto);
        // sem `platforms` = todas com texto (retrocompat). A seleção fica em publish.platforms e o
        // PublishService a respeita no loop.
        $req = array_values(array_filter((array) $r->input('platforms', []), 'is_string'));
        $platforms = $req !== [] ? array_values(array_intersect($withText, $req)) : $withText;
        if ($platforms === []) {
            return [null, response()->json(['ok' => false, 'error' => 'selecione ao menos uma rede com texto'], 400)];
        }
        // Perfis-alvo (multi-perfil): só ids que pertencem ao tenant. Vazio = perfil padrão (retrocompat).
        $reqProfiles = array_values(array_filter((array) $r->input('profiles', []), 'is_numeric'));
        $profileIds = $reqProfiles !== []
            ? Profile::where('tenant_id', $t->id)->whereIn('id', $reqProfiles)->pluck('id')->map(fn ($i) => (string) $i)->values()->all()
            : [];

        // 👽 ALVO do Reddit: COMUNIDADE (`r/nome` ou só `nome`) ou o PRÓPRIO PERFIL (`u/nome`).
        // O prefixo é preservado aqui e traduzido na hora de publicar (PublishService::
        // redditSubreddit converte `u/x` no `u_x` que o protocolo do Reddit usa). Guardar o que o
        // cliente escreveu — e não o valor já traduzido — é o que deixa o repost repetir a MESMA
        // escolha e a tela mostrar de volta o que ele digitou.
        $alvo = ltrim(trim((string) $r->input('reddit_subreddit', '')), '/');
        if ($alvo !== '' && ! preg_match('#^(?:[ru]/)?[A-Za-z0-9_]{2,21}$#', $alvo)) {
            return [null, response()->json(['ok' => false, 'error' => 'alvo do Reddit inválido — use r/comunidade ou u/perfil (letras, números e _)'], 422)];
        }
        $subreddit = $alvo;
        // FORMATO do post no Reddit (texto | imagem) — ver PublishService::REDDIT_TEXTO pro que
        // cada um perde. Allowlist no serviço; valor desconhecido vira 'texto'.
        $redditFormato = PublishService::redditFormato($r->input('reddit_formato'));
        // Título do post no Reddit (opcional). Teto de 300 é o limite DURO da API — acima disso o
        // Reddit recusa o post inteiro. Corta aqui pra falha nunca chegar lá; vazio = 1ª linha.
        $redditTitulo = mb_substr(trim((string) $r->input('reddit_title', '')), 0, 300);
        // 👽 COMUNIDADE OBRIGATÓRIA quando o Reddit está na seleção. Antes, vazio significava
        // "subreddit padrão da conta" no provedor — e "padrão" é uma comunidade que o cliente não
        // escolheu: a peça saía publicada no lugar errado, com ok=true, sem nada no log (caso real
        // 2026-08-04, publicação 24). Publicar no alvo errado é pior que não publicar: não dá pra
        // despublicar da cabeça de quem viu. Pedir a comunidade é uma pergunta; o post errado é
        // um estrago.
        if (in_array('reddit', $platforms, true) && $subreddit === '') {
            return [null, response()->json(['ok' => false, 'error' => 'Escolha a comunidade do Reddit (subreddit) antes de publicar.'], 422)];
        }

        return [[
            'state' => $state,
            'platforms' => array_values($platforms),
            'profile_ids' => $profileIds, // vazio = perfil padrão
            'reddit_subreddit' => $subreddit, // alvo do Reddit: r/comunidade ou u/perfil
            'reddit_formato' => $redditFormato, // texto (corpo completo) | imagem (foto no feed)
            'reddit_title' => $redditTitulo, // título escolhido; vazio = 1ª linha do texto da rede
            'started_at' => now()->toIso8601String(),
        ], null];
    }

    /** POST /api/studio/submit { draftId } → publica cada plataforma do rascunho (Zernio). */
    public function submit(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));

        // AUD-004: anti-duplo-publish. Bloqueia se já está rodando e transiciona o state
        // de forma ATÔMICA (só dispara o job se ESTE request foi quem marcou 'running').
        abort_if(($d->publish['state'] ?? null) === 'running', 409, 'Publicação já em andamento.');

        [$payload, $erro] = $this->publishTarget($r, $d, $t, 'running');
        if ($erro !== null) {
            return $erro;
        }
        $platforms = $payload['platforms'];
        // UPDATE condicional (PostgreSQL): vence a corrida só quem encontrar
        // publish->>'state' != 'running'. O operador ->> extrai o campo como texto
        // (funciona em colunas json e jsonb). null/qualquer-coisa != "running" passa.
        $claimed = Draft::where('id', $d->id)
            ->where('tenant_id', $t->id)
            ->where(function ($q) {
                $q->whereNull('publish')
                    ->orWhereRaw("publish->>'state' IS NULL")
                    ->orWhereRaw("publish->>'state' <> 'running'");
            })
            ->update(['publish' => json_encode($payload)]);

        abort_if($claimed !== 1, 409, 'Publicação já em andamento.');

        PublishDraftJob::dispatch($d->id);

        return response()->json(['ok' => true, 'async' => true, 'state' => 'running', 'platforms' => array_values($platforms)]);
    }

    /**
     * POST /api/studio/schedule { draftId, scheduledAt, platforms?, profiles?, reddit_subreddit? }
     * → AGENDA a publicação em vez de disparar agora.
     *
     * O estado vive no mesmo `publish` do submit (state='scheduled'), então o anti-duplo-publish
     * e o PublishService continuam valendo sem exceção nova. Só o gatilho muda: em vez de
     * despachar o job, grava o instante e deixa o `publish:due` (worker) despachar na hora.
     */
    public function schedule(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));

        abort_if(($d->publish['state'] ?? null) === 'running', 409, 'Publicação já em andamento.');

        $quando = trim((string) $r->input('scheduledAt', ''));
        try {
            $at = $quando !== '' ? Carbon::parse($quando) : null;
        } catch (\Throwable $e) {
            $at = null;
        }
        if ($at === null) {
            return response()->json(['ok' => false, 'error' => 'Informe a data e a hora da publicação.'], 422);
        }
        // 60s de folga: o relógio do navegador costuma andar alguns segundos à frente/atrás, e
        // recusar "daqui a 10 segundos" por causa disso seria erro sem causa visível pro cliente.
        if ($at->lt(now()->subMinute())) {
            return response()->json(['ok' => false, 'error' => 'Essa data já passou.'], 422);
        }
        if ($at->gt(now()->addYear())) {
            return response()->json(['ok' => false, 'error' => 'Agende para no máximo um ano à frente.'], 422);
        }

        [$payload, $erro] = $this->publishTarget($r, $d, $t, 'scheduled');
        if ($erro !== null) {
            return $erro;
        }
        $payload['scheduled_at'] = $at->toIso8601String();
        unset($payload['started_at']); // ainda não começou — started_at é do disparo real

        // Mesmo claim condicional do submit: quem estiver publicando agora não é reagendado.
        $claimed = Draft::where('id', $d->id)
            ->where('tenant_id', $t->id)
            ->where(function ($q) {
                $q->whereNull('publish')
                    ->orWhereRaw("publish->>'state' IS NULL")
                    ->orWhereRaw("publish->>'state' <> 'running'");
            })
            ->update(['publish' => json_encode($payload), 'scheduled_at' => $at]);

        abort_if($claimed !== 1, 409, 'Publicação já em andamento.');

        return response()->json([
            'ok' => true, 'state' => 'scheduled',
            'scheduled_at' => $at->toIso8601String(),
            'platforms' => $payload['platforms'],
        ]);
    }

    /** POST /api/studio/unschedule { draftId } → cancela um agendamento que ainda não disparou. */
    public function unschedule(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));

        // Só desmarca o que está agendado: se já virou 'running' o job saiu, e cancelar aqui daria
        // a impressão falsa de ter impedido a publicação.
        $limpo = Draft::where('id', $d->id)
            ->where('tenant_id', $t->id)
            ->whereRaw("publish->>'state' = 'scheduled'")
            ->update(['publish' => null, 'scheduled_at' => null]);

        if ($limpo !== 1) {
            return response()->json(['ok' => false, 'error' => 'Este rascunho não está agendado.'], 409);
        }

        return response()->json(['ok' => true, 'state' => null]);
    }

    /** GET /api/studio/publish-status { draftId } → estado do publish assíncrono. */
    public function publishStatus(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));

        return response()->json(['ok' => true, 'publish' => $d->publish, 'status' => $d->status]);
    }

    /**
     * Sobe bytes no sistema de mídia (Scality/s3.example.com, mesmo storage do ffmpeg-service).
     * Fallback gracioso pro storage local do console se o Scality estiver indisponível.
     */
    /**
     * AUD-013/014: valida que a URL é http(s) e aponta para o nosso domínio de mídia
     * (MEDIA_S3_PUBLIC_BASE / s3.example.com). Bloqueia file://, IP interno e hosts externos.
     */
    /** ID único de um item de mídia na galeria. Antes era `(int)(microtime*1000)`, que podia
     *  colidir entre requests concorrentes no mesmo milissegundo (o `id` identifica o item pra
     *  excluir/fixar). Agora = timestamp-ms (mantém a ordenabilidade por tempo) + sufixo aleatório. */
    public static function mediaId(): string
    {
        return Draft::mediaId();
    }

    /**
     * Faz a MÍDIA FINAL do rascunho servir também as redes $platforms (união, atômica).
     *
     * Gerar legenda pra uma rede é declarar intenção de publicar nela — e o publish faz INTERSEÇÃO
     * entre as redes marcadas e `media[].platforms`. Um vídeo montado com [youtube,instagram,
     * linkedin] fazia a rede escolhida depois (TikTok, X, Threads…) aparecer no Aprovar com
     * "Nenhuma mídia destinada a esta rede" e ser PULADA no publish, em silêncio. Quem escolhe a
     * rede é o operador; a mídia final é uma só e tem de acompanhar.
     *
     * Não toca em: itens de CENA (`scene` — as intermediárias da história) nem em `platforms` vazio
     * (que já significa "serve todas as redes").
     */
    public static function servirRedesNaMidiaFinal(Draft $d, array $platforms): void
    {
        $media = $d->media ?? [];
        if ($media === [] || $platforms === []) {
            return;
        }
        $mudou = false;
        foreach ($media as $i => $m) {
            if (isset($m['scene'])) {
                continue; // cena intermediária não é o que se publica
            }
            $atual = array_values(array_filter((array) ($m['platforms'] ?? []), 'is_string'));
            if ($atual === []) {
                continue; // já serve todas
            }
            $novo = array_values(array_unique(array_merge($atual, $platforms)));
            if (count($novo) !== count($atual)) {
                $media[$i]['platforms'] = $novo;
                $mudou = true;
            }
        }
        if ($mudou) {
            DB::transaction(function () use ($d, $media) {
                $fresh = Draft::lockForUpdate()->find($d->id);
                if ($fresh) {
                    $fresh->update(['media' => $media]);
                    $d->setRawAttributes($fresh->getAttributes(), true);
                }
            });
        }
    }

    /** Anexa um item de imagem à galeria do rascunho de forma ATÔMICA (transação + lock), e —
     *  quando $storyIndex é informado — grava o image_url na cena correspondente no mesmo passo.
     *  O lock evita lost-update do array JSON quando várias cenas geram imagem em paralelo. */
    private function attachImageLocked(int $draftId, array $item, ?int $storyIndex): array
    {
        return DB::transaction(function () use ($draftId, $item, $storyIndex) {
            $d = Draft::lockForUpdate()->find($draftId);
            if (! $d) {
                return [$item];
            }
            $media = array_merge($d->media ?? [], [$item]);
            $upd = ['media' => $media];
            if ($storyIndex !== null) {
                $story = is_array($d->story) ? $d->story : [];
                $scenes = $story['scenes'] ?? [];
                if (isset($scenes[$storyIndex])) {
                    $scenes[$storyIndex]['image_url'] = (string) $item['url'];
                    $story['scenes'] = $scenes;
                    $upd['story'] = $story;
                }
            }
            $d->update($upd);

            return $media;
        });
    }

    public static function isOwnMediaUrl(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        $allowed = ['s3.example.com'];
        if ($base = parse_url((string) config('filesystems.disks.media.url'), PHP_URL_HOST)) {
            $allowed[] = strtolower((string) $base);
        }

        foreach (array_unique(array_filter($allowed)) as $h) {
            if ($host === $h) {
                return true;
            }
        }

        return false;
    }

    /** Versão "normalizadora" de isOwnMediaUrl: devolve a URL (corrigida pro host ATUAL do bucket
     *  se vier com host velho — o storage já passou por túnel efêmero/nomeado/localhost) ou null
     *  se não for mídia nossa. Usada onde a URL vai ser reusada como referência (inpaint, refino,
     *  frame-to-base), não só validada. */
    public static function ownMediaUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (self::isOwnMediaUrl($url)) {
            return $url;
        }
        $base = rtrim((string) config('filesystems.disks.media.url'), '/');
        if ($base === '') {
            return null;
        }
        $prefixo = (string) parse_url($base, PHP_URL_PATH);   // ex.: /public
        $caminho = (string) parse_url($url, PHP_URL_PATH);
        if ($prefixo === '' || ! str_starts_with($caminho, $prefixo)) {
            return null;
        }

        return $base.substr($caminho, strlen($prefixo));
    }

    /** Sanitiza a FICHA DE CENA (spec — S1): allowlist de 4 chaves (shot/movement/light/emotion),
     *  strings curtas. Vindo do usuário (autosave) → não confia em chave/tamanho. Vazio = null (a
     *  geração/edição trata spec nulo como "sem ficha", retrocompatível). Estático: reusado no Filme. */
    public static function sanitizeSceneSpec($spec): ?array
    {
        if (! is_array($spec)) {
            return null;
        }
        $out = [];
        foreach (['shot', 'movement', 'light'] as $k) {
            if (($v = trim((string) ($spec[$k] ?? ''))) !== '') {
                $out[$k] = mb_substr($v, 0, 40); // keys do vocabulário (o engine ignora fora da lista)
            }
        }
        if (($e = trim((string) ($spec['emotion'] ?? ''))) !== '') {
            $out['emotion'] = mb_substr($e, 0, 120); // texto curto livre (direção de ator)
        }

        return $out === [] ? null : $out;
    }

    /** RBK-003/PUB-002: host é YouTube (clip/thumbnail aceitam ingest via yt-dlp). */
    public static function isYouTubeUrl(?string $url): bool
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $host = (string) preg_replace('/^www\./', '', $host);

        return in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be'], true);
    }

    public static function storeMedia(string $contents, string $ext, string $kind): string
    {
        // AUD-006: nunca produzir extensão/Content-Type executável (svg/html/xml…).
        $ext = strtolower(trim($ext, '. '));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'm4a', 'mp3'];
        if (! in_array($ext, $allowed, true)) {
            $ext = in_array($kind, ['video', 'dub'], true) ? 'mp4' : 'jpg';
        }
        $name = "reachyn/uploads/{$kind}_".(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).".{$ext}";
        try {
            Storage::disk('media')->put($name, $contents, 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable $e) {
            // Fallback same-origin (app.reachyn.agency/storage): força download em vez de
            // render inline, p/ que um arquivo malicioso não execute na origem do app.
            $local = 'uploads/'.basename($name);
            Storage::disk('public')->put($local, $contents, [
                'ContentDisposition' => 'attachment; filename="'.basename($name).'"',
            ]);

            return rtrim((string) config('app.url'), '/').'/storage/'.$local;
        }
    }

    /**
     * AUD-024: salva um upload por STREAM (sem file_get_contents do arquivo inteiro),
     * usando o handle do arquivo temporário. Mesma allowlist de extensão do storeMedia.
     */
    public static function storeUploadedFile(UploadedFile $file, string $ext, string $kind): string
    {
        $ext = strtolower(trim($ext, '. '));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'mp4', 'mov', 'm4a', 'mp3'];
        if (! in_array($ext, $allowed, true)) {
            $ext = in_array($kind, ['video', 'dub'], true) ? 'mp4' : 'jpg';
        }
        // 🧭 FOTO DE CELULAR DEITADA: JPEGs de câmera trazem a rotação só no EXIF (orientation).
        // O browser aplica na exibição, mas o modelo i2i recebe os pixels CRUS (deitados) → model
        // sheet/base saíam distorcidos e "de lado". Normaliza a orientação (assa a rotação nos pixels
        // e descarta o EXIF) ANTES de subir. Best-effort: qualquer falha cai no arquivo original.
        if (in_array($kind, ['image'], true) && in_array($ext, ['jpg', 'jpeg'], true)) {
            if ($rotated = self::normalizeImageOrientation($file->getRealPath())) {
                $name = "reachyn/uploads/{$kind}_".(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).'.jpg';
                try {
                    Storage::disk('media')->put($name, file_get_contents($rotated), 'public');
                    @unlink($rotated);

                    return Storage::disk('media')->url($name);
                } catch (\Throwable $e) {
                    @unlink($rotated); // cai no fluxo padrão abaixo
                }
            }
        }
        $name = "reachyn/uploads/{$kind}_".(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).".{$ext}";
        try {
            // putFileAs transmite o arquivo em stream para o disco de mídia (Scality/S3).
            Storage::disk('media')->putFileAs(dirname($name), $file, basename($name), 'public');

            return Storage::disk('media')->url($name);
        } catch (\Throwable $e) {
            // Fallback same-origin: força download (attachment) p/ não renderizar inline.
            $local = 'uploads/'.basename($name);
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk('public')->put($local, $stream, [
                'ContentDisposition' => 'attachment; filename="'.basename($name).'"',
            ]);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return rtrim((string) config('app.url'), '/').'/storage/'.$local;
        }
    }

    /**
     * Baixa uma imagem do NOSSO storage, REDUZ (lado maior ≤ $maxDim) e re-sobe; devolve a URL nova.
     * Usado antes de pós-processadores que engasgam com imagem gigante (recraft remove-bg falha em
     * foto de celular 4000px). Sem redução necessária / GD ausente / falha → devolve a URL original.
     */
    public static function downscaledImageUrl(string $url, int $maxDim = 1536): string
    {
        if (! function_exists('imagecreatefromstring') || ! self::isOwnMediaUrl($url)) {
            return $url;
        }
        try {
            $raw = @file_get_contents($url);
            if ($raw === false) {
                return $url;
            }
            $img = @imagecreatefromstring($raw);
            if (! $img) {
                return $url;
            }
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) <= $maxDim) {
                imagedestroy($img);

                return $url; // já é pequena o bastante
            }
            $scale = $maxDim / max($w, $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $resized = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $tmp = tempnam(sys_get_temp_dir(), 'dsc').'.jpg';
            imagejpeg($resized, $tmp, 92);
            imagedestroy($resized);
            $name = 'reachyn/uploads/image_'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)).'.jpg';
            Storage::disk('media')->put($name, file_get_contents($tmp), 'public');
            @unlink($tmp);

            return Storage::disk('media')->url($name);
        } catch (\Throwable $e) {
            return $url;
        }
    }

    /**
     * Data URL (JPEG) REDUZIDO p/ a IA de VISION — lado maior ≤ $maxDim, orientação EXIF aplicada.
     * O engine tem teto de 1MB por request: mandar a foto full-res (2-3MB → base64 ~4MB) dava 400
     * e o personagem ficava SEM lock. 1024px é de sobra pra descrever o personagem. Retorna a
     * string 'data:image/jpeg;base64,...' ou null (sem gd / decode falho → o chamador cai no cru).
     */
    public static function visionDataUrl(string $path, int $maxDim = 1024): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $raw = @file_get_contents($path);
            if ($raw === false) {
                return null;
            }
            $img = @imagecreatefromstring($raw);
            if (! $img) {
                return null;
            }
            // orientação EXIF (só JPEG a carrega) — a vision precisa da imagem EM PÉ.
            if (function_exists('exif_read_data')) {
                $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
                if (in_array($o, [2, 4, 5, 7], true)) {
                    imageflip($img, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($o) {
                    3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                };
                if ($angle !== 0) {
                    $img = imagerotate($img, $angle, 0);
                }
            }
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, $maxDim / max($w, $h));
            if ($scale < 1) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $resized = imagecreatetruecolor($nw, $nh);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img);
                $img = $resized;
            }
            ob_start();
            imagejpeg($img, null, 85);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);

            return $jpeg !== '' ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Assa a rotação EXIF nos pixels e devolve o caminho de um JPEG temporário UPRIGHT — ou null
     * se não há rotação a fazer (orientation 1/ausente), se falta gd/exif, ou se algo falha.
     * Nunca lança: o chamador cai no arquivo original.
     */
    private static function normalizeImageOrientation(string $path): ?string
    {
        if (! function_exists('exif_read_data') || ! function_exists('imagecreatefromjpeg')) {
            return null;
        }
        try {
            $exif = @exif_read_data($path);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            if ($orientation <= 1) {
                return null; // já está em pé
            }
            $img = @imagecreatefromjpeg($path);
            if (! $img) {
                return null;
            }
            // Espelhamentos (2/4/5/7) + rotações. Cobre os 8 casos do EXIF.
            if (in_array($orientation, [2, 4, 5, 7], true)) {
                imageflip($img, IMG_FLIP_HORIZONTAL);
            }
            $angle = match ($orientation) {
                3, 4 => 180,
                5, 6 => -90,
                7, 8 => 90,
                default => 0,
            };
            if ($angle !== 0) {
                $img = imagerotate($img, $angle, 0);
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ori').'.jpg';
            imagejpeg($img, $tmp, 92); // re-encoda SEM o bloco EXIF → orientação neutra
            imagedestroy($img);

            return is_file($tmp) ? $tmp : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function attach(Draft $d, string $kind, ?string $url, array $extra = []): array
    {
        if (! $url) {
            return $d->media ?? [];
        }
        $item = array_merge(['id' => self::mediaId(), 'kind' => $kind, 'url' => $url], $extra);
        $media = array_merge($d->media ?? [], [$item]);
        $d->update(['media' => $media]);

        return $media;
    }

    /** POST /api/studio/veo { draftId } → vídeo premium (Veo). */
    public function veo(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // Sem rascunho ativo o Veo não tem keyword/contexto pra gerar — erro amigável (não 500).
        $draftId = $r->input('draftId');
        if (! $draftId) {
            return response()->json(['ok' => false, 'error' => 'Selecione ou crie um rascunho antes de gerar o Vídeo Premium.'], 422);
        }
        $d = $this->draft($r, $draftId);
        $weight = $this->usage->weightFor('veo');
        // RESERVE-THEN-CONSUME (AUD-002).
        if (! $this->usage->tryConsume($t, 'veo', $weight)) {
            return response()->json(['ok' => false, 'error' => 'Vídeo premium (Veo) não está incluído no seu plano.'], 402);
        }
        $brief = $d->research['summary'] ?? $d->research['brief'] ?? '';
        $style = (string) $r->input('style', 'cinematografico');
        // idioma do vídeo: só 'pt-BR' ou 'en-US'; valor inválido/ausente → default 'pt-BR'.
        $lang = (string) $r->input('lang');
        if (! in_array($lang, ['pt-BR', 'en-US'], true)) {
            $lang = 'pt-BR';
        }
        $url = $this->engine()->post('/v1/veo', ['keyword' => $d->keyword, 'brief' => $brief, 'style' => $style, 'lang' => $lang])->json('url');
        if (! $url) {
            $this->usage->refund($t, 'veo', $weight);

            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Vídeo premium gerado.', 'media' => $this->attach($d, 'video', $url, ['style' => $style])]);
    }

    /** POST /api/studio/thumbnail { draftId, videoUrl } → thumbnail do vídeo. */
    public function thumbnail(Request $r): JsonResponse
    {
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        // RBK-003: defesa-em-profundidade SSRF — só mídia própria ou YouTube.
        if (! self::isOwnMediaUrl($videoUrl) && ! self::isYouTubeUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo não permitida.'], 422);
        }
        $url = $this->engine()->post('/v1/thumbnail', ['videoUrl' => $videoUrl, 'title' => $d->keyword])->json('url');
        if (! $url) {
            return response()->json(['ok' => false, 'error' => 'geração não retornou URL'], 502);
        }

        return response()->json(['ok' => true, 'media' => $this->attach($d, 'image', $url)]);
    }

    /** POST /api/studio/clip { draftId, videoUrl, n } → corta vídeo longo em N shorts (engine). */
    public function clip(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        if ($videoUrl === '') {
            return response()->json(['ok' => false, 'error' => 'informe a URL do vídeo longo'], 400);
        }
        // RBK-003: aceita mídia própria OU YouTube (ingest); bloqueia URL arbitrária (SSRF).
        if (! self::isOwnMediaUrl($videoUrl) && ! self::isYouTubeUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo não permitida (use um vídeo seu ou um link do YouTube).'], 422);
        }
        $n = max(1, min(5, (int) $r->input('n', 3)));
        // clip gera N vídeos → reserva N×peso(video) DE UMA VEZ (AUD-002). O que não for
        // gerado é estornado no fim, então o cliente nunca paga por clip que falhou.
        $unit = $this->usage->weightFor('video');
        if (! $this->usage->tryConsume($t, 'video', $n * $unit)) {
            return response()->json(['ok' => false, 'error' => 'Clipar vídeo não está incluído no seu plano (ou saldo insuficiente para a quantidade pedida).'], 402);
        }
        $clips = $this->engine()->post('/v1/clip', ['videoUrl' => $videoUrl, 'n' => $n])->json() ?? [];

        $media = $d->media ?? [];
        $added = 0;
        foreach ($clips as $c) {
            if (($c['ok'] ?? false) && ! empty($c['url'])) {
                $media[] = ['id' => self::mediaId(), 'kind' => 'video', 'url' => $c['url']];
                $added++;
            }
        }
        if ($added > 0) {
            $d->update(['media' => $media]);
        }
        // Estorna a parte reservada que não virou clip ($n - $added).
        $notGenerated = $n - $added;
        if ($notGenerated > 0) {
            $this->usage->refund($t, 'video', $notGenerated * $unit);
        }

        return response()->json(['ok' => true, 'message' => "{$added} short(s) gerado(s).", 'media' => $media]);
    }

    /** POST /api/studio/upload (multipart: file, draftId?, kind) → salva no storage e acumula. */
    public function upload(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // AUD-006: allowlist de mídia real (sem svg/html/xml).
        // AUD-024 (DoS): limites de tamanho POR TIPO, bem abaixo dos 200MB anteriores —
        // imagem 10MB, áudio 50MB, vídeo 100MB. Reduz superfície de exaustão de memória/disco.
        $kind = (string) $r->input('kind', 'image');
        $rules = match ($kind) {
            'video' => 'required|file|mimes:mp4,mov|max:102400',          // 100MB
            'audio' => 'required|file|mimes:m4a,mp3|max:51200',           // 50MB
            default => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:10240', // 10MB
        };
        $r->validate(['file' => $rules]);
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'sem arquivo'], 400);
        }
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'upload']);

        // Deriva a extensão do MIME real (nunca confia no nome/extensão do cliente).
        $ext = strtolower((string) ($file->guessExtension() ?: ($kind === 'video' ? 'mp4' : 'jpg')));
        // AUD-024: streaming via Storage::putFileAs — não carrega o arquivo inteiro em memória.
        $url = self::storeUploadedFile($file, $ext, $kind);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'media' => $this->attach($d, $kind, $url)]);
    }

    /**
     * POST /api/studio/adopt { url, kind?, draftId? } — anexa ao rascunho uma mídia que JÁ está no
     * nosso storage (escolhida da galeria), sem passar bytes pelo navegador.
     *
     * 🐛 O que isto substitui: a tela de publicar baixava a mídia com `fetch()` e a reenviava como
     * upload. Só que o storage não devolve `Access-Control-Allow-Origin`, então o navegador
     * bloqueava a leitura cross-origin e o usuário via "Não foi possível carregar essa mídia da
     * galeria" — para QUALQUER item, imagem ou vídeo (relato 2026-08-04). Liberar CORS resolveria o
     * sintoma; o desperdício continuaria: baixar 5 MB e subir os mesmos 5 MB de volta pra um
     * arquivo que já é nosso.
     *
     * Anti-SSRF: `ownMediaUrl` já normaliza e recusa o que não for do nosso domínio de mídia — é a
     * MESMA trava usada nas referências de geração. URL de fora simplesmente não entra.
     */
    public function adopt(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $url = self::ownMediaUrl((string) $r->input('url'));
        if ($url === null) {
            return response()->json(['ok' => false, 'error' => 'Escolha uma mídia da sua galeria.'], 422);
        }
        // O tipo vem do cliente mas é conferido contra a EXTENSÃO real da URL: o que decide o
        // caminho de publicação é isto, e um mp4 rotulado como "image" quebraria a peça lá na frente.
        $ext = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $kind = match (true) {
            in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true) => 'video',
            in_array($ext, ['mp3', 'm4a', 'wav'], true) => 'audio',
            default => 'image',
        };
        $draftId = $r->input('draftId');
        $d = $draftId ? $this->draft($r, $draftId) : Draft::create(['tenant_id' => $t->id, 'keyword' => 'galeria']);

        return response()->json(['ok' => true, 'draftId' => $d->id, 'kind' => $kind, 'media' => $this->attach($d, $kind, $url)]);
    }

    /** POST /api/studio/voice-clone (multipart: file) → clona a voz e salva no tenant.
     *  Sem gate de plano (2026-07-12: custo é por crédito — fala pra todos); a POLÍTICA
     *  permanece: só a PRÓPRIA voz do usuário (anti-deepfake). */
    public function voiceClone(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        // AUD-024 (DoS) + AUD-006: amostra de voz = áudio real, máx 50MB (antes: sem limite).
        $r->validate([
            'file' => 'required|file|mimes:m4a,mp3,wav,ogg|max:51200',
        ]);
        $file = $r->file('file');
        if (! $file) {
            return response()->json(['ok' => false, 'error' => 'envie um arquivo de áudio (amostra da voz)'], 400);
        }
        $key = (string) config('services.elevenlabs.key');
        if ($key === '') {
            return response()->json(['ok' => false, 'error' => 'Clonagem de voz indisponível no momento.'], 502);
        }

        $res = Http::withHeaders(['xi-api-key' => $key])->timeout(120)
            ->attach('files', file_get_contents($file->getRealPath()), $file->getClientOriginalName() ?: 'sample.mp3')
            ->post('https://api.elevenlabs.io/v1/voices/add', [
                'name' => "reachyn-{$t->id}-".time(),
                'description' => 'Voz do cliente (Reachyn)',
            ]);
        if (! $res->successful() || ! $res->json('voice_id')) {
            return response()->json(['ok' => false, 'error' => 'falha ao clonar a voz'], 502);
        }
        $voiceId = $res->json('voice_id');
        $t->update(['voice_id' => $voiceId]);

        // AUD-005: auditoria de consumo premium (sem expor a chave/áudio).
        Audit::log('studio.voice_clone', ['tenant_id' => $t->id, 'voice_id' => $voiceId]);

        return response()->json(['ok' => true, 'voice_id' => $voiceId]);
    }

    /** POST /api/studio/dub { draftId, videoUrl, lang } → dubla o vídeo (assíncrono).
     *  Sem gate de plano (2026-07-12: custo é por crédito — fala pra todos). */
    public function dub(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $d = $this->draft($r, $r->input('draftId'));
        $videoUrl = (string) $r->input('videoUrl');
        $lang = (string) $r->input('lang');
        if ($videoUrl === '' || ! in_array($lang, ['en', 'es', 'fr', 'de', 'it', 'pt'], true)) {
            return response()->json(['ok' => false, 'error' => 'vídeo e idioma válido obrigatórios'], 400);
        }
        // AUD-014: só aceita URL http(s) do nosso domínio de mídia (anti-SSRF de 2ª ordem).
        if (! self::isOwnMediaUrl($videoUrl)) {
            return response()->json(['ok' => false, 'error' => 'URL de vídeo inválida'], 422);
        }

        DubVideo::dispatch($d->id, $videoUrl, $lang);

        // AUD-005: auditoria de consumo premium (dublagem).
        Audit::log('studio.dub', ['tenant_id' => $t->id, 'draft_id' => $d->id, 'lang' => $lang]);

        return response()->json(['ok' => true, 'status' => 'gerando', 'message' => 'Dublando para '.strtoupper($lang).' (~5-15 min). A versão aparece na galeria.']);
    }

    /**
     * GET /api/media/list → galeria de mídia do tenant logado.
     *
     * Isolamento multi-tenant: a query filtra por tenant_id explícito (defesa em
     * profundidade) E o trait BelongsToTenant do Draft já aplica um global scope por
     * tenant. O cliente logado NUNCA vê mídia de outro tenant — o filtro é 100% no
     * banco (tenant_id), pois o path no S3 não carrega o tenant.
     */
    public function mediaList(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $drafts = Draft::where('tenant_id', $t->id) // explícito + global scope do trait
            ->whereNotNull('media')
            ->orderByDesc('updated_at')
            ->get();

        // Achata o array `media` de cada rascunho, anexando origem (draft_id/keyword/data).
        $items = $drafts->flatMap(function (Draft $d) {
            return collect($d->media ?? [])->map(fn (array $m) => [
                'id' => (string) ($m['id'] ?? ''),
                'kind' => (string) ($m['kind'] ?? ''),
                'url' => (string) ($m['url'] ?? ''),
                'style' => $m['style'] ?? null,
                // cena N de uma história = peça INTERMEDIÁRIA (clipe/imagem/narração de 5s, muda). A
                // galeria usa isso p/ NÃO confundir com a história FINAL (vídeo grande montado, com áudio).
                'scene' => $m['scene'] ?? null,
                // Ficha técnica: motor que gerou + dimensões. Itens antigos não têm (só passou a
                // ser gravado em 2026-07-20) — o front trata a ausência, não inventa valor.
                'model' => $m['model'] ?? null,
                'w' => $m['w'] ?? null,
                'h' => $m['h'] ?? null,
                'bytes' => $m['bytes'] ?? null,
                'draft_id' => $d->id,
                'keyword' => $d->keyword,
                'date' => (string) $d->updated_at,
            ]);
        })->values();

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /**
     * 🩹 INPAINT LOCAL — redesenha SÓ a área pintada da máscara (SetLatentNoiseMask no engine).
     *
     * Multipart: draftId + url (imagem NOSSA) + prompt + mask (PNG P&B que o front desenha).
     * A máscara vira arquivo no storage (URL pública) porque o engine baixa refs por URL.
     * Assíncrono; o resultado entra como NOVA mídia no mesmo draft (original preservado).
     *
     * Depende do motor `img-local-inpaint` no catálogo (ComfyUI local) — em ambiente sem esse
     * motor ativo (ex.: VPS sem ComfyUI), `GenModel::resolveSelectable` devolve null e a rota
     * responde 422 de forma limpa em vez de tentar gerar.
     */
    public function inpaint(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $data = $r->validate([
            'draftId' => 'required|integer',
            'url' => 'required|string|max:500',
            'prompt' => 'required|string|max:2000',
            'mask' => 'required|file|mimes:png|max:10240',
        ]);
        $alvo = self::ownMediaUrl($data['url']);
        if (! $alvo) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }
        $gm = GenModel::resolveSelectable('img-local-inpaint', 'image', $t->plan);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'O motor local de conserto não está ativo neste ambiente.'], 422);
        }
        $d = $this->draft($r, (int) $data['draftId']);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        $maskUrl = self::storeUploadedFile($r->file('mask'), 'png', 'image');
        $payload = array_merge([
            'prompt' => (string) $data['prompt'],
            'aspect' => self::aspectOfImage($alvo), // informativo — o inpaint preserva a resolução da ref
            'imageUrls' => [$alvo],
            'maskUrl' => $maskUrl,
        ], GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null)));
        GenerateImageJob::dispatch(
            $d->id, $t->id, $payload, 'realista', $weight, [], $gm->cost_credits,
            null, $gm->display_name, 'natural', 0
        );

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Conserto na fila — a imagem corrigida aparece na galeria em ~2 minutos.']);
    }

    /**
     * 🎨 REFINO LOCAL — i2i de baixa denoise no motor local: poli o "quase bom" SEM trocar
     * identidade, sem crédito de nuvem e sem limite. Irmão do inpaint: mesma fila serial do
     * ComfyUI, mesmo caminho assíncrono; o resultado entra como NOVA mídia no mesmo draft.
     */
    public function refineLocal(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $data = $r->validate([
            'draftId' => 'required|integer',
            'url' => 'required|string|max:500',
            'prompt' => 'sometimes|string|max:2000',
        ]);
        $alvo = self::ownMediaUrl($data['url']);
        if (! $alvo) {
            return response()->json(['ok' => false, 'error' => 'URL de imagem inválida'], 422);
        }
        $gm = GenModel::resolveSelectable('img-local-refino', 'image', $t->plan);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'O motor local de refino não está ativo neste ambiente.'], 422);
        }
        $d = $this->draft($r, (int) $data['draftId']);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }
        // Sem prompt do usuário → polimento padrão: mais detalhe/nitidez, MESMO sujeito e cor
        // (denoise baixo no engine garante; o prompt só orienta o pouco que muda).
        $prompt = trim((string) ($data['prompt'] ?? ''))
            ?: 'enhance fine details, texture and sharpness; keep the exact same subject, composition, colors and lighting';
        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => self::aspectOfImage($alvo),
            'imageUrls' => [$alvo],
        ], GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null)));
        GenerateImageJob::dispatch(
            $d->id, $t->id, $payload, 'realista', $weight, [], $gm->cost_credits,
            null, $gm->display_name, 'natural', 0
        );

        return response()->json(['ok' => true, 'draftId' => $d->id, 'message' => 'Refino na fila — a versão polida aparece na galeria em ~2 minutos.']);
    }

    /**
     * POST /api/media/frame-to-base { url, at, target: character|scenario, id?, name? }
     * → congela UM quadro de um clipe do acervo e o adota como base de personagem/cenário.
     *
     * SEGURANÇA: só mídia NOSSA (ownMediaUrl normaliza host velho e barra URL de terceiro antes
     * de chegar no ffmpeg), `at` preso em [0,1), nome limitado, e a entidade alvo é sempre
     * resolvida DENTRO do tenant.
     */
    public function frameToBase(Request $r): JsonResponse
    {
        $t = $this->tenant($r);
        $data = $r->validate([
            'url' => 'required|string|max:1000',
            'at' => 'nullable|numeric|min:0|max:0.999',
            'target' => 'required|in:character,scenario',
            'id' => 'nullable|integer',           // vazio = cria uma ficha nova
            'name' => 'nullable|string|max:120',
        ]);

        $video = self::ownMediaUrl($data['url']);
        if (! $video) {
            return response()->json(['ok' => false, 'error' => 'Só dá pra usar quadro de vídeo do seu acervo.'], 422);
        }

        try {
            $res = Http::baseUrl(rtrim((string) config('services.ffmpeg.url'), '/'))
                ->withHeaders(['X-Service-Token' => (string) config('services.ffmpeg.token')])
                ->timeout(120)
                ->post('/frames-at', ['video_url' => $video, 'fractions' => [(float) ($data['at'] ?? 0)]]);
            $frame = (string) ($res->json('urls')[0] ?? '');
        } catch (\Throwable $e) {
            Log::warning('[frame-to-base] extração falhou', ['error' => $e->getMessage()]);
            $frame = '';
        }
        if ($frame === '') {
            return response()->json(['ok' => false, 'error' => 'Não foi possível congelar o quadro.'], 502);
        }

        $nome = trim((string) ($data['name'] ?? '')) ?: 'Do vídeo';
        if ($data['target'] === 'character') {
            $c = ! empty($data['id'])
                ? Character::where('tenant_id', $t->id)->find((int) $data['id'])
                : Character::create(['tenant_id' => $t->id, 'name' => $nome]);
            if (! $c) {
                return response()->json(['ok' => false, 'error' => 'Personagem não encontrado.'], 404);
            }
            $c->update(['base_url' => $frame]);

            return response()->json(['ok' => true, 'url' => $frame, 'target' => 'character', 'id' => $c->id, 'name' => $c->name]);
        }

        $s = ! empty($data['id'])
            ? Scenario::where('tenant_id', $t->id)->find((int) $data['id'])
            : Scenario::create(['tenant_id' => $t->id, 'name' => $nome]);
        if (! $s) {
            return response()->json(['ok' => false, 'error' => 'Cenário não encontrado.'], 404);
        }
        $s->update(['image_url' => $frame]);

        return response()->json(['ok' => true, 'url' => $frame, 'target' => 'scenario', 'id' => $s->id, 'name' => $s->name]);
    }

    /**
     * POST /api/media/clean-broken → remove de uma vez todas as mídias MORTAS do tenant.
     * "Morta/quebrada" = URL que não é do nosso storage (fal.media expirado, storage antigo):
     * !isOwnMediaUrl($url). Não há objeto nosso pra apagar no S3, então só limpamos o array
     * `media` de cada rascunho. Multi-tenant: só drafts do tenant logado (explícito + global scope).
     */
    public function mediaCleanBroken(Request $r): JsonResponse
    {
        $t = $this->tenant($r);

        $drafts = Draft::where('tenant_id', $t->id) // explícito + global scope do trait (defesa dupla)
            ->whereNotNull('media')
            ->get();

        $removed = 0;
        foreach ($drafts as $d) {
            $media = $d->media ?? [];
            $kept = array_values(array_filter($media, fn ($m) => self::isOwnMediaUrl($m['url'] ?? '')));
            if (count($kept) !== count($media)) {
                $removed += count($media) - count($kept);
                $d->update(['media' => $kept]);
            }
        }

        return response()->json(['ok' => true, 'removed' => $removed]);
    }
}
