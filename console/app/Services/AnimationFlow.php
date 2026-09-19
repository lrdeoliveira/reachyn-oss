<?php

namespace App\Services;

use App\Http\Controllers\Api\StudioController;
use App\Jobs\AnimationAssembleJob;
use App\Jobs\AnimationElementJob;
use App\Jobs\AnimationFrameJob;
use App\Jobs\AnimationSceneJob;
use App\Models\AnimationProject;
use App\Models\GenModel;
use App\Models\Tenant;
use App\Services\Concerns\BuildsShortMontage;
use App\Support\GenPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🎬 ESTÚDIO DE ANIMAÇÃO — o "diretor de produção": mapa de modelos por qualidade, montagem
 * dos payloads do engine, cobrança (reserve-then-consume; o job estorna se falhar) e o MODO
 * AUTOMÁTICO (advance): quando o projeto está em auto, cada job que termina chama advance()
 * e o próximo passo é despachado sozinho — o equivalente ao "agente" do benchmark.
 * Centralizado aqui pra o controller (curadoria manual) e os jobs (auto) usarem os MESMOS
 * builders — regenerar uma cena na mão ou no automático produz o mesmo payload.
 */
class AnimationFlow
{
    use BuildsShortMontage; // montagem /v1/storyvideo dos modos narrados (Histórias/Quadrinhos)

    /** Custo da montagem SLIDES-ONLY (Quadrinhos): só TTS + CPU do ffmpeg — o bucket 'short'
     *  cheio (calibrado pro Short com clipes i2v) mataria a promessa do formato barato. */
    public const SLIDES_ONLY_COST = 40;

    /** Modelos por papel × qualidade (slug do catálogo gen_models; fallback se indisponível no plano).
     *
     * POR QUE 'char' e 'ref' PODEM IR PRA CLI e 'frame' NÃO (verificado 2026-07-20):
     * - char/ref (elementos: personagem, locação, objeto) são **t2i puro** — o elementPayload não
     *   monta `imageUrls`. Nascem do zero, sem ancoragem, então o motor de assinatura (custo
     *   marginal ZERO) entrega o mesmo trabalho sem risco de identidade.
     * - frame (keyframe da cena) usa **até 4 referências** (personagens da cena + locação +
     *   IDENTITY LOCK). O mmx aceita **UMA** — duas dão erro na API. Migrar o frame perderia a
     *   ancoragem multi-personagem, que é exatamente o que segura o anti-drift. Fica no i2i pago.
     *
     * 'premium' segue inteiro no pago de propósito: é o tier que o cliente escolhe PARA ter o
     * modelo caro; trocar por CLI esvaziaria a promessa do tier.
     */
    public const IMG_MODELS = [
        'economico' => ['char' => GenModel::DEFAULT_T2I, 'ref' => GenModel::DEFAULT_T2I, 'frame' => 'img-referencia'],
        'padrao' => ['char' => GenModel::DEFAULT_T2I, 'ref' => GenModel::DEFAULT_T2I, 'frame' => 'img-referencia'],
        'premium' => ['char' => 'img-pro',        'ref' => 'img-pro',        'frame' => 'img-pro'],
    ];

    /**
     * Tier de qualidade da Animação → slug do catálogo.
     *
     * ⚠️ 2026-08-04: apontava pros slugs do agregador (vid-economico/vid-natural/vid-fluido), os
     * TRÊS desativados na saída do KIE. `resolveSelectable` filtra por `active()`, então os três
     * tiers devolviam null e a Animação caía no fallback — que também filtrava por provider='kie'
     * e também devolvia null. Repontados pro catálogo cli-bridge, mantendo a escada de preço
     * (30 → 40 → 90 créditos) que os rótulos econômico/padrão/premium prometem.
     */
    public const VID_MODELS = ['economico' => 'hf-wan2-7', 'padrao' => 'hf-kling3-0', 'premium' => 'hf-seedance-2-0'];

    /** Último recurso quando o tier escolhido saiu do ar: o clipe mais barato do catálogo. */
    public const VID_FALLBACK = 'hf-wan2-7';

    /** Providers que geram CLIPE no engine — espelha GenModel::isClipCapable(). */
    public const PROVIDERS_CLIPE = ['cli-bridge', 'magnific', 'minimax'];

    /** Style-packs: lead EN prependado aos prompts visuais (elemento e keyframe). */
    public const STYLE_LEADS = [
        '3d' => '3D animated cartoon style, cute and expressive characters, soft rounded shapes, big expressive eyes, cinematic lighting, high-quality render.',
        'cartoon' => '2D animated cartoon style, clean bold outlines, flat vibrant colors, expressive faces.',
        'stickman' => 'minimalist stick-figure animation style, clean lines on simple background.',
        'anime' => 'anime style, detailed expressive eyes, dynamic composition, cel shading.',
        'realista' => 'photorealistic live-action cinematic style, natural skin texture, real-world physics, shallow depth of field. NOT 3D render, NOT CGI, NOT illustration.',
    ];

    public function __construct(private UsageService $usage) {}

    public function imageModel(AnimationProject $p, string $role, ?string $plan): ?GenModel
    {
        // 🧠 Modelo ESCOLHIDO no projeto vence o tier (controle de custo do usuário);
        // slug inválido/indisponível no plano cai no mapa por qualidade normalmente.
        if (($chosen = trim((string) $p->image_model)) !== '') {
            $gm = GenModel::resolveSelectable($chosen, 'image', $plan);
            if ($gm) {
                return $gm;
            }
        }
        $slug = self::IMG_MODELS[$p->quality][$role] ?? self::IMG_MODELS['padrao'][$role];

        return GenModel::resolveSelectable($slug, 'image', $plan)
            ?? GenModel::resolveSelectable(GenModel::DEFAULT_T2I, 'image', $plan)
            ?? GenModel::active()->kind('image')->forPlan($plan)->orderBy('sort_order')->first();
    }

    public function videoModel(AnimationProject $p, ?string $plan, ?string $override = null): ?GenModel
    {
        // 🧠 Override do request > modelo escolhido no projeto > tier de qualidade.
        $slug = $override ?: (trim((string) $p->video_model) ?: (self::VID_MODELS[$p->quality] ?? self::VID_MODELS['padrao']));
        $chosen = GenModel::resolveSelectable($slug, 'video', $plan);
        // 🎬 Cada cena é i2v ANCORADO no keyframe: modelo que não faz clipe a partir de imagem
        // (vid-premium/Veo é t2v) não serve aqui e cai no default. Antes de 2026-07-22 ele passava,
        // e a cena morria no engine com um 403 de saldo de outra conta — ver GenModel::isClipCapable.
        if ($chosen && ! $chosen->isClipCapable()) {
            Log::warning('AnimationFlow: modelo de vídeo não faz clipe a partir de imagem — usando o default', [
                'slug' => $chosen->slug, 'provider' => $chosen->provider, 'project' => $p->id,
            ]);
            $chosen = null;
        }

        // Último recurso: o clipe mais barato que o engine SABE rotear. Antes era
        // `provider='kie'` — depois da saída do agregador nenhum modelo ativo satisfazia isso, e
        // os dois fallbacks devolviam null juntos (o slug 'vid-economico' também é kie e saiu do
        // ar), deixando a Animação sem modelo nenhum. Filtro por capacidade, não por marca.
        return $chosen
            ?? GenModel::resolveSelectable(self::VID_FALLBACK, 'video', $plan)
            ?? GenModel::active()->kind('video')->forPlan($plan)
                ->whereIn('provider', self::PROVIDERS_CLIPE)->orderBy('cost_credits')->orderBy('sort_order')->first();
    }

    public function styleLead(AnimationProject $p): string
    {
        return self::STYLE_LEADS[$p->style] ?? self::STYLE_LEADS['3d'];
    }

    /**
     * 🗣️ Preset de entrega da voz a partir da EMOÇÃO que a ficha de cena já descreve.
     *
     * O roteiro escreve direção de atuação de verdade — "fascínio sussurrado, respiração contida",
     * "triunfo silencioso, voz firme e grave" — e isso era jogado fora: qualquer emoção virava
     * `dramatico` (stability 0.3, style 0.6 no ElevenLabs), ou seja, uma fala sussurrada recebia
     * configuração de fala intensa. Aqui a direção escolhe entre os presets que já existem no
     * ffmpeg-service (TTS_STYLE_SETTINGS), em vez de ser descartada.
     *
     * Sem emoção descrita continua vazio = neutro (comportamento histórico).
     */
    public static function ttsStyleFor(string $emotion): string
    {
        $e = mb_strtolower(trim($emotion));
        if ($e === '') {
            return '';
        }

        // 1º) O que a direção diz sobre a VOZ vence o que ela diz sobre a ATITUDE. "triunfo
        // silencioso, sorriso lento, VOZ FIRME E GRAVE" é fala firme: o "silencioso" qualifica o
        // triunfo (sem alarde), não a entrega. Ler só a atitude fez a cena 2 do projeto 20 sair
        // sussurrada quando o roteiro pedia gravidade — e foi assim que a voz "ficou horrível".
        foreach (['voz firme', 'voz grave', 'voz forte', 'voz alta', 'firme e grave', 'imponent', 'poderos', 'autorit', 'ameaçador', 'ameacador'] as $t) {
            if (str_contains($e, $t)) {
                return 'dramatico';
            }
        }

        // 2º) SUSSURRO é literal, não sinônimo de contenção. Só entra quando a direção diz mesmo
        // que a personagem sussurra — é este preset que vira a audio tag [whispers] no Eleven v3,
        // e sussurrar por engano estraga a fala inteira.
        foreach (['sussurr', 'cochich', 'baixinho', 'quase inaudív', 'quase inaudiv'] as $t) {
            if (str_contains($e, $t)) {
                return 'sussurro';
            }
        }

        // 3º) Contenção sem sussurro: voz estável e sem exagero, mas em volume normal.
        foreach (['silencios', 'contid', 'calm', 'sereno', 'suave', 'melanc', 'triste', 'cansad', 'íntim', 'intim'] as $t) {
            if (str_contains($e, $t)) {
                return 'calmo';
            }
        }

        foreach (['euforia', 'eufóric', 'euforic', 'gritan', 'grito', 'raiva', 'fúria', 'furia', 'pânico', 'panico', 'desesper', 'empolg', 'animad', 'urgent', 'afliç', 'aflit'] as $t) {
            if (str_contains($e, $t)) {
                return 'energetico';
            }
        }

        return 'dramatico';
    }

    /** provider/model/kie do payload de IMAGEM (fonte única: GenPayload — unificação 2026-07-16;
     *  sem quality explícita o extra da qualidade PADRÃO do catálogo é aplicado — fix hailuo/02). */
    public function imagePayloadBase(?GenModel $gm): array
    {
        return GenPayload::imagePayloadBase($gm);
    }

    /** gen_lines.video pro /v1/filmclip (fonte única: GenPayload — unificação 2026-07-16). */
    public function videoGenLine(?GenModel $vm): array
    {
        return GenPayload::videoGenLine($vm);
    }

    /** Duração (string) que o MODELO aceita: usa a desejada se o catálogo (capabilities.durations)
     *  suportar; senão a menor duração >= desejada (arredonda pra cima), ou a maior disponível.
     *  Evita o KIE recusar (ex hailuo só [6,10]) e cair no fallback nativo sem saldo. */
    private function validDuration(?GenModel $vm, string $want): string
    {
        $durs = array_values(array_filter(array_map('intval', (array) ($vm?->capabilities['durations'] ?? []))));
        if ($durs === [] || in_array((int) $want, $durs, true)) {
            return $want;
        }
        sort($durs);
        foreach ($durs as $d) {
            if ($d >= (int) $want) {
                return (string) $d;
            }
        }

        return (string) end($durs);
    }

    /** Payload /v1/image da REFERÊNCIA de um elemento (t2i, prompt autocontido + style-pack). */
    public function elementPayload(AnimationProject $p, string $type, array $el): array
    {
        // FORMATO = o do PROJETO. Era fixo por tipo (3:4 personagem, 16:9 locação, 1:1 objeto),
        // convenção de "ficha de referência" que ignorava o seletor: num filme 9:16 os três saíam
        // em formatos que não batiam nem com a escolha do usuário nem entre si. Além de confundir,
        // a referência num formato e a cena em outro obriga o i2i a recompor — justamente o que a
        // referência deveria evitar. Predizível vence convenção: o que você escolhe é o que sai.
        $aspect = $p->aspect;
        $lead = $this->styleLead($p);
        $suffix = match ($type) {
            'characters' => ' Full body, standing, neutral pose, facing camera, plain neutral background, character reference sheet quality.',
            'locations' => ' Establishing view of the environment, no people, no characters.',
            default => ' Product-style reference image of the object alone, plain neutral background, no people.',
        };
        $gm = $this->imageModel($p, $type === 'characters' ? 'char' : 'ref', Tenant::find($p->tenant_id)?->plan);

        return array_merge([
            'prompt' => $lead.' '.trim((string) ($el['visual_prompt'] ?? '')).$suffix,
            'aspect' => $aspect,
            'style' => $p->style,
        ], $this->imagePayloadBase($gm));
    }

    /** Payload /v1/image do KEYFRAME de uma cena: locks textuais + refs multi-imagem + spec + paleta.
     *  $end=true monta o keyframe FINAL da cena (para animar keyframe→keyframe fora): usa
     *  end_image_prompt e ANCORA no keyframe INICIAL desta mesma cena (mesma composição/luz/
     *  identidade, só a pose avança ao fim do movimento). Exige o keyframe inicial já gerado. */
    public function framePayload(AnimationProject $p, int $i, bool $end = false): ?array
    {
        $els = (array) $p->elements;
        $sb = array_values((array) $p->storyboard);
        $sc = $sb[$i] ?? null;
        if (! $sc) {
            return null;
        }
        $byName = [];
        foreach ((array) ($els['characters'] ?? []) as $c) {
            $byName[mb_strtolower((string) ($c['name'] ?? ''))] = $c;
        }
        $locByName = [];
        foreach ((array) ($els['locations'] ?? []) as $l) {
            $locByName[mb_strtolower((string) ($l['name'] ?? ''))] = $l;
        }

        // Refs multi-imagem: ref PRÓPRIA da cena (âncora primária, se o usuário subiu/escolheu uma)
        // + personagens da cena (até 3) + locação (1) — cap 4 (limite prático do i2i).
        $refs = [];
        $locks = [];
        if (($ownRef = trim((string) ($sc['ref_url'] ?? ''))) !== '') {
            $refs[] = $ownRef; // paridade com o story-reference da tela clássica
        }
        foreach (array_slice((array) ($sc['characters'] ?? []), 0, 3) as $nm) {
            $c = $byName[mb_strtolower((string) $nm)] ?? null;
            if (! $c) {
                continue;
            }
            if (($c['ref_url'] ?? '') !== '') {
                $refs[] = $c['ref_url'];
            }
            // IDENTITY LOCK textual verbatim (lição do figurino: i2i sozinho recria genérico).
            $locks[] = 'CHARACTER LOCK — '.$c['name'].' (must match the reference image of this character EXACTLY — same face, body, colors and outfit): '.trim((string) ($c['visual_prompt'] ?? ''));
        }
        // 🏞️ LOCATION LOCK — a IMAGEM e o TEXTO entram por portas separadas (2026-07-23). Antes um
        // único `if` exigia ref_url E vaga no cap de 4 refs para as duas coisas: cena com 3
        // personagens estourava o cap e o cenário sumia INTEIRO, inclusive a âncora textual, que
        // não ocupa slot nenhum e não custa nada. Era assim que o ambiente derivava entre cenas.
        $loc = $locByName[mb_strtolower((string) ($sc['location'] ?? ''))] ?? null;
        if ($loc) {
            if (($loc['ref_url'] ?? '') !== '' && count($refs) < 4) {
                $refs[] = $loc['ref_url'];
            }
            if (trim((string) ($loc['visual_prompt'] ?? '')) !== '') {
                $locks[] = 'LOCATION LOCK — '.$loc['name'].' (same environment, architecture, materials and lighting as established): '.trim((string) ($loc['visual_prompt'] ?? ''));
            }
        }

        // 🔗 SEQUÊNCIA (Desenho animado, modos encadeado/plano): o keyframe da cena ANTERIOR entra
        // como âncora PRIMÁRIA — mesmo cenário, luz e enquadramento fluem cena→cena (o desenho lê
        // como um filme só, não como tomadas soltas). Mesmo mecanismo do Filme (kf[i-1] como ref).
        // Exige geração SERIAL (o keyframe anterior tem que existir antes) — garantida no dispatch.
        if ($p->chainsFrames() && $i > 0) {
            $prevKf = trim((string) ($sb[$i - 1]['keyframe_url'] ?? ''));
            if ($prevKf !== '' && StudioController::isOwnMediaUrl($prevKf)) {
                array_unshift($refs, $prevKf); // continuidade vence: primeira ref, sobrevive ao cap 4
                $locks[] = 'CONTINUITY — the FIRST reference image is the previous shot of this same continuous animation: keep the SAME setting, art style, color palette and character look; this shot continues directly from it.';
            }
        }
        // 🔚 FRAME FINAL: ancora no keyframe INICIAL desta MESMA cena (âncora primária) — o fim do
        // movimento parte da mesma composição/luz/identidade do início, só a pose avança. Prepend
        // como primeira ref (vence o cap 4). Precisa do inicial pronto (garantido no dispatchFrameEnd).
        if ($end) {
            $startKf = trim((string) ($sc['keyframe_url'] ?? ''));
            if ($startKf !== '' && StudioController::isOwnMediaUrl($startKf)) {
                array_unshift($refs, $startKf);
                $locks[] = 'FINAL FRAME — the FIRST reference image is the START frame of this exact shot: keep the SAME setting, framing, lighting, art style and character identity; only advance the subject to the END of the motion.';
            }
        }
        $refs = array_slice(array_values(array_unique($refs)), 0, 4); // cap prático do i2i multi-ref

        // Corpo do prompt: cena inicial (image_prompt) ou, no frame final, end_image_prompt (com
        // fallback pro image_prompt quando o operador não escreveu o prompt do fim).
        $body = trim((string) ($sc['image_prompt'] ?? $sc['action'] ?? ''));
        if ($end) {
            $endBody = trim((string) ($sc['end_image_prompt'] ?? ''));
            $body = $endBody !== '' ? $endBody : $body;
        }
        $prompt = $this->styleLead($p).' '.$body;
        if ($end) {
            $motion = trim((string) ($sc['video_prompt'] ?? $sc['action'] ?? ''));
            $prompt .= "\nThis is the FINAL frame of the shot — the same scene at the END of the motion"
                .($motion !== '' ? ' (after this motion: '.mb_substr($motion, 0, 300).')' : '')
                .'; identical character, environment, palette and framing as the reference, only the subject position/pose advanced.';
        }
        if ($locks !== []) {
            $prompt .= "\n".implode("\n", $locks);
        }
        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => $p->aspect,
            'style' => $p->style,
        ], $this->imagePayloadBase($this->imageModel($p, 'frame', Tenant::find($p->tenant_id)?->plan)));
        if ($refs !== []) {
            $payload['imageUrls'] = array_values($refs);
            $payload['anchorIdentity'] = true;
        }
        if (is_array($sc['spec'] ?? null) && ($sc['spec'] ?? []) !== []) {
            $payload['spec'] = $sc['spec'];
        }
        if (($pal = trim((string) $p->palette)) !== '') {
            $payload['palette'] = mb_substr($pal, 0, 200);
        }

        return $payload;
    }

    /** [linhas de diálogo com voz | null, payload base do /v1/filmclip] de uma cena. */
    public function scenePayloads(AnimationProject $p, int $i, ?string $videoModel = null): ?array
    {
        $sb = array_values((array) $p->storyboard);
        $sc = $sb[$i] ?? null;
        if (! $sc || ($sc['keyframe_url'] ?? '') === '') {
            return null;
        }
        // Modos NARRADOS (Histórias/Quadrinhos): a narração é TTS CENTRAL na montagem
        // (/v1/storyvideo lê beats[].script) — a cena anima MUDA, sem diálogo por cena.
        $lines = [];
        if (! $p->isNarrated()) {
            $els = (array) $p->elements;
            $voiceOf = [];
            foreach ((array) ($els['characters'] ?? []) as $c) {
                $nm = mb_strtolower((string) ($c['name'] ?? ''));
                $voiceOf[$nm] = (string) ($c['voice_id'] ?? '');
            }
            foreach ((array) ($sc['dialogue'] ?? []) as $dl) {
                $text = trim((string) ($dl['line'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $key = mb_strtolower((string) ($dl['character'] ?? ''));
                $emotion = trim((string) ($sc['spec']['emotion'] ?? ''));
                $lines[] = [
                    'text' => $text,
                    'voice_id' => $voiceOf[$key] ?? '',
                    'tts_style' => self::ttsStyleFor($emotion),
                ];
            }
        }

        $vm = $this->videoModel($p, Tenant::find($p->tenant_id)?->plan, $videoModel);
        // BUG (achado 2026-07-18, projeto #15): video_prompt vem do spec.movement (specMoveDirective,
        // SÓ câmera — "the camera holds still...", "dolly move...") e NUNCA vem vazio, então o texto
        // bom abaixo (que pede o PERSONAGEM se mexer) nunca disparava como fallback — o clipe saía com
        // a câmera se movendo (ou nem isso, em movement=static) e o personagem CONGELADO. Agora a
        // instrução de movimento do personagem entra SEMPRE, com a câmera do spec por cima (sem
        // redesenhar a identidade — só "atua a cena", igual o texto antigo).
        $camera = trim((string) ($sc['video_prompt'] ?? ''));
        $move = 'The character in the frame moves and acts out the scene naturally, with clear physical motion.'
            .($camera !== '' ? ' '.$camera : '')
            .$this->clipIdentityLock($p, (array) $sc);
        $clip = [
            'prompt' => $move,
            'imageUrl' => (string) $sc['keyframe_url'],
            // Duração VÁLIDA pro modelo: alguns (ex hailuo/vid-natural) só aceitam [6,10] — mandar '5'
            // fazia o KIE recusar e cair no fallback nativo. O job sobe pra 10 quando o diálogo pede.
            'duration' => $this->validDuration($vm, '5'),
            'aspect' => $p->aspect,
            'gen_lines' => $this->videoGenLine($vm),
        ];
        // 🔗 PLANO-SEQUÊNCIA (modo `plano`): o clipe vai do keyframe DESTA cena ao da PRÓXIMA
        // (endImageUrl → o movimento sai de uma composição e chega na outra, sem corte — igual
        // ao modo keyframe do Filme). Os dois keyframes já existem (etapa anterior), então os
        // clipes seguem em PARALELO. Só com modelo tail-capable (Kling image_urls[0..1]); senão
        // degrada pro encadeamento só de imagem (o keyframe já ancorado no anterior). Última cena
        // não tem "próxima" → clipe normal (fecha o plano).
        if ($p->chainsClips() && $this->isTailCapable($vm)) {
            $nextKf = trim((string) ($sb[$i + 1]['keyframe_url'] ?? ''));
            if ($nextKf !== '' && StudioController::isOwnMediaUrl($nextKf)) {
                $clip['endImageUrl'] = $nextKf;
            }
        }

        return [$lines !== [] ? $lines : null, $clip, $vm];
    }

    /**
     * 🔒 Trava de identidade do CLIPE: o que não pode mudar enquanto a cena se move.
     *
     * O prompt do clipe só dizia o que DEVE acontecer (movimento + câmera) e nada sobre o que deve
     * PERMANECER — a identidade inteira ficava por conta da imagem de referência. Quando o modelo
     * não segura o keyframe, ele inventa: no projeto 19 a peça mágica virou um boneco 3D no meio do
     * clipe, o cristal ganhou um rosto humano e a protagonista trocou de cara entre cenas. O
     * keyframe estava correto nos três casos; faltava a âncora TEXTUAL.
     *
     * ⚠️ Trava IDENTIDADE, nunca MOVIMENTO. Consertando a mão na mão o mesmo clipe, escrever
     * "locked-off camera / the only movement is a soft pulse of light" matou a cena: o movimento
     * caiu de 1,40 para 0,63 e o gesto nunca completou. Por isso aqui não há uma palavra sobre
     * câmera, ritmo ou quantidade de movimento — só sobre o que tem de continuar sendo o que é.
     */
    private function clipIdentityLock(AnimationProject $p, array $sc): string
    {
        $els = (array) $p->elements;
        $nomes = [];
        foreach (array_slice((array) ($sc['characters'] ?? []), 0, 3) as $nm) {
            if (($nm = trim((string) $nm)) !== '') {
                $nomes[] = $nm;
            }
        }
        $loc = trim((string) ($sc['location'] ?? ''));

        $txt = ' Keep every subject and the setting exactly as they appear in the reference frame:'
            .' same faces, same bodies, same outfits, same colors and the same objects.'
            .' Nothing in the frame turns into a different person, creature, character or object,'
            .' and no object grows a face, eyes or limbs.';

        if ($nomes !== []) {
            $txt .= ' The character'.(count($nomes) > 1 ? 's' : '').' ('.implode(', ', $nomes)
                .') keep'.(count($nomes) > 1 ? '' : 's').' the exact same face and costume throughout the shot.';
        }
        if ($loc !== '') {
            $locEl = null;
            foreach ((array) ($els['locations'] ?? []) as $l) {
                if (mb_strtolower(trim((string) ($l['name'] ?? ''))) === mb_strtolower($loc)) {
                    $locEl = $l;
                    break;
                }
            }
            $txt .= ' The setting stays '.$loc;
            // A descrição do cenário (a mesma que ancora o keyframe e vem da biblioteca quando o
            // cenário é reusado) entra curta — é ela que segura arquitetura, materiais e luz.
            $desc = trim((string) ($locEl['visual_prompt'] ?? ''));
            if ($desc !== '') {
                $txt .= ' ('.mb_substr($desc, 0, 180).')';
            }
            $txt .= ': same architecture, materials, palette and lighting.';
        }

        // 🔮 OBJETOS DA CENA, nominalmente. Personagem e cenário sozinhos não bastavam: no teste do
        // projeto 20 o cristal do pedestal virou uma peça metálica dourada no meio do clipe, mesmo
        // com a trava genérica dizendo "same objects". O que funcionou quando escrevi à mão foi
        // nomear o objeto e o que ele É ("the crystal stays exactly a crystal: same faceted
        // shape…") — objeto pequeno e isolado no quadro é o alvo preferido da alucinação.
        $props = [];
        foreach (array_slice((array) ($sc['props'] ?? []), 0, 3) as $nm) {
            $nm = trim((string) $nm);
            if ($nm === '') {
                continue;
            }
            $d = '';
            foreach ((array) ($els['props'] ?? []) as $pr) {
                if (mb_strtolower(trim((string) ($pr['name'] ?? ''))) === mb_strtolower($nm)) {
                    $d = trim((string) ($pr['visual_prompt'] ?? ''));
                    break;
                }
            }
            $props[] = $nm.($d !== '' ? ' ('.mb_substr($d, 0, 120).')' : '');
        }
        if ($props !== []) {
            $txt .= ' The object'.(count($props) > 1 ? 's' : '').' in the scene — '.implode('; ', $props)
                .' — stay'.(count($props) > 1 ? '' : 's').' exactly what they are: same shape, same material,'
                .' same color and same size from the first frame to the last. They never morph into a'
                .' different object, never change material, and never become a creature or a character.';
        }

        return $txt;
    }

    /** O modelo de vídeo aceita PRIMEIRO+ÚLTIMO frame → habilita o plano-sequência (endImageUrl).
     *  Regra única em GenModel::isTailCapable (compartilhada com o Filme e o catálogo). */
    public function isTailCapable(?GenModel $m): bool
    {
        return $m?->isTailCapable() ?? false;
    }

    /** Despacha a REF de UM elemento (cobrança image). Retorna erro legível ou null. */
    /** @param  bool  $unlink  regerar DESVINCULANDO da biblioteca (a âncora oficial fica intacta). */
    public function dispatchElement(AnimationProject $p, Tenant $t, string $type, int $i, bool $unlink = false): ?string
    {
        $els = (array) $p->elements;
        $el = $els[$type][$i] ?? null;
        if (! $el || trim((string) ($el['visual_prompt'] ?? '')) === '') {
            return 'elemento inexistente ou sem descrição';
        }
        // JÁ ESTÁ GERANDO → não cobra nem enfileira de novo. A trava existia só no lote
        // (dispatchAllElements); o botão do card chamava aqui direto e passava por cima dela.
        // Caso real (2026-07-21): o lote foi disparado, o mmx leva ~2min por imagem, o usuário
        // achou que não tinha funcionado e clicou no card — o elemento foi COBRADO E GERADO DUAS
        // VEZES, e o segundo resultado sobrescreveu o primeiro. A trava no lote não basta: quem
        // protege o crédito tem de ser o ponto que gasta.
        if (($el['status'] ?? '') === 'generating') {
            return 'esse elemento já está sendo gerado — aguarde';
        }
        // VINDO DA BIBLIOTECA → só regera DESVINCULANDO, e a pedido explícito.
        //
        // O estrago real (caso Mel, 2026-07-21) não foi regerar: foi trocar a imagem MANTENDO o
        // `character_id`. O vínculo continuava dizendo "este é o personagem X" enquanto a imagem
        // já era outra — um sósia com o crachá certo, e a troca invisível. Bloquear de vez também
        // estava errado (impedia querer uma variante só pra esta história). Então: permite, mas
        // corta o vínculo, para o projeto assumir que aquela imagem é local e a âncora oficial
        // seguir intacta na biblioteca, servindo os outros projetos.
        if (($el['character_id'] ?? null) && ! $unlink) {
            return 'BIBLIOTECA';
        }
        if ($unlink) {
            unset($els[$type][$i]['character_id']);
        }
        $gm = $this->imageModel($p, $type === 'characters' ? 'char' : 'ref', $t->plan);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm?->cost_credits)) {
            return 'Limite do plano atingido para geração de imagem.';
        }
        $els[$type][$i]['status'] = 'generating';
        $p->update(['elements' => $els]);
        AnimationElementJob::dispatch($p->id, $t->id, $type, $i, $this->elementPayload($p, $type, $el), $weight, $gm?->cost_credits);

        return null;
    }

    /** Despacha TODAS as refs pendentes. Retorna quantas foram. */
    /** @param  bool  $force  REFAZ quem já tem imagem. Sem isso, "gerar todas" só serve pra
     *                        primeira vez: elemento com `ref_url` é pulado, e um segundo clique
     *                        devolve `dispatched: 0` — que na tela parecia o botão quebrado.
     *                        Nunca refaz quem está `generating` (evita cobrar duas vezes). */
    public function dispatchAllElements(AnimationProject $p, Tenant $t, bool $force = false): int
    {
        $n = 0;
        foreach (['characters', 'locations', 'props'] as $type) {
            foreach (array_keys((array) (((array) $p->elements)[$type] ?? [])) as $i) {
                $p->refresh();
                $el = ((array) $p->elements)[$type][$i] ?? [];
                if (($el['status'] ?? '') === 'generating') {
                    continue;
                }
                // Personagem VINDO DA BIBLIOTECA (character_id) nunca é regerado, nem no force: a
                // imagem dele é a âncora oficial do personagem, não um rascunho desta história.
                if (($el['ref_url'] ?? '') !== '' && (! $force || ($el['character_id'] ?? null))) {
                    continue;
                }
                if ($this->dispatchElement($p, $t, $type, $i) === null) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Despacha o keyframe da cena $i. $chainNext = quando o job terminar, dispara sozinho o
     *  keyframe da PRÓXIMA cena pendente (serialização do encadeamento — ver AnimationFrameJob). */
    public function dispatchFrame(AnimationProject $p, Tenant $t, int $i, bool $chainNext = false): ?string
    {
        $payload = $this->framePayload($p, $i);
        if (! $payload) {
            return 'cena inexistente';
        }
        $gm = $this->imageModel($p, 'frame', $t->plan);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm?->cost_credits)) {
            return 'Limite do plano atingido para geração de imagem.';
        }
        $this->patchScene($p, $i, ['keyframe_status' => 'generating']);
        AnimationFrameJob::dispatch($p->id, $t->id, $i, $payload, $weight, $gm?->cost_credits, $chainNext);

        return null;
    }

    public function dispatchAllFrames(AnimationProject $p, Tenant $t): int
    {
        // 🔗 Encadeado/plano: os keyframes têm que sair EM ORDEM (cada um ancora no anterior).
        // Semeia só o primeiro pendente com chainNext=true; ele mesmo dispara o próximo ao concluir.
        if ($p->chainsFrames()) {
            return $this->dispatchNextFrame($p, $t);
        }
        $n = 0;
        foreach (array_keys(array_values((array) $p->storyboard)) as $i) {
            $p->refresh();
            $sc = array_values((array) $p->storyboard)[$i] ?? [];
            if (($sc['keyframe_url'] ?? '') !== '' || ($sc['keyframe_status'] ?? '') === 'generating' || ($sc['locked'] ?? false)) {
                continue;
            }
            if ($this->dispatchFrame($p, $t, $i) === null) {
                $n++;
            }
        }

        return $n;
    }

    /** 🔚 Despacha o keyframe FINAL da cena $i (para animar keyframe→keyframe fora). Exige o keyframe
     *  INICIAL pronto (ancora nele). Cobra image; não entra no fluxo automático (advance/chainNext). */
    public function dispatchFrameEnd(AnimationProject $p, Tenant $t, int $i): ?string
    {
        $sb = array_values((array) $p->storyboard);
        $sc = $sb[$i] ?? null;
        if (! $sc) {
            return 'cena inexistente';
        }
        if (trim((string) ($sc['keyframe_url'] ?? '')) === '') {
            return 'gere o keyframe inicial da cena antes do final';
        }
        $payload = $this->framePayload($p, $i, true);
        if (! $payload) {
            return 'cena inexistente';
        }
        $gm = $this->imageModel($p, 'frame', $t->plan);
        $weight = $this->usage->weightFor('image');
        if (! $this->usage->tryConsume($t, 'image', $weight, $gm?->cost_credits)) {
            return 'Limite do plano atingido para geração de imagem.';
        }
        $this->patchScene($p, $i, ['end_keyframe_status' => 'generating']);
        AnimationFrameJob::dispatch($p->id, $t->id, $i, $payload, $weight, $gm?->cost_credits, false, true);

        return null;
    }

    /** Dispara o keyframe FINAL de todas as cenas com inicial pronto e sem final (nem gerando).
     *  Retorna quantos disparou. Os finais são independentes → saem em paralelo (sem serialização). */
    public function dispatchAllEndFrames(AnimationProject $p, Tenant $t): int
    {
        $n = 0;
        foreach (array_keys(array_values((array) $p->storyboard)) as $i) {
            $p->refresh();
            $sc = array_values((array) $p->storyboard)[$i] ?? [];
            if (trim((string) ($sc['keyframe_url'] ?? '')) === '' // sem inicial → nada a ancorar
                || ($sc['end_keyframe_url'] ?? '') !== '' || ($sc['end_keyframe_status'] ?? '') === 'generating'
                || ($sc['locked'] ?? false)) {
                continue;
            }
            if ($this->dispatchFrameEnd($p, $t, $i) === null) {
                $n++;
            }
        }

        return $n;
    }

    /** 🔗 Dispara o PRÓXIMO keyframe pendente (menor índice sem keyframe, não travado, não gerando)
     *  com chainNext=true, serializando o encadeamento. Retorna 1 se disparou, 0 se não há pendente
     *  (ou faltou crédito). Usado no seed do dispatchAllFrames e na continuação do AnimationFrameJob. */
    public function dispatchNextFrame(AnimationProject $p, Tenant $t): int
    {
        $p->refresh();
        foreach (array_values((array) $p->storyboard) as $i => $sc) {
            if (($sc['keyframe_url'] ?? '') !== '' || ($sc['keyframe_status'] ?? '') === 'generating' || ($sc['locked'] ?? false)) {
                continue;
            }

            return $this->dispatchFrame($p, $t, $i, true) === null ? 1 : 0;
        }

        return 0;
    }

    public function dispatchScene(AnimationProject $p, Tenant $t, int $i, ?string $videoModel = null, array $opts = []): ?string
    {
        if ($p->mode === 'quadrinhos') {
            return 'Quadrinhos são slides — não há cena pra animar; monte o episódio direto.';
        }
        $built = $this->scenePayloads($p, $i, $videoModel);
        if (! $built) {
            return 'gere o keyframe da cena antes de animar';
        }
        [$lines, $clip, $vm] = $built;
        if (in_array($opts['duration'] ?? null, ['5', '10'], true)) {
            $clip['duration'] = $this->validDuration($vm, (string) $opts['duration']); // 5s|10s clampado ao modelo
        }
        // ✨ "Melhorar antes de animar" (paridade): upscale do keyframe via /v1/enhance ANTES do
        // i2v — cobrado à parte no bucket image com o custo do modelo de edição (como o clássico).
        $upscale = null;
        if ($opts['upscale'] ?? false) {
            $em = GenModel::resolveSelectable('edit-upscale-pro', 'edit', $t->plan);
            if ($em) {
                $uw = $this->usage->weightFor('image');
                if (! $this->usage->tryConsume($t, 'image', $uw, $em->cost_credits)) {
                    return 'Limite do plano atingido para melhorar a imagem.';
                }
                $upscale = [
                    'payload' => GenPayload::enhancePayload($em, $clip['imageUrl']),
                    'weight' => $uw,
                    'cost' => $em->cost_credits,
                ];
            }
        }
        // 🎬 LIP SYNC: cena COM diálogo (modo animacao) usa talking-head — a boca sincroniza com a
        // fala — no lugar do i2v mudo + áudio colado por cima. Modelo do catálogo 'vid-lipsync';
        // ausente/indisponível no plano = cai no i2v normal. Cobra o custo do lip sync (bucket 'video').
        $lipLine = null;
        $videoCostModel = $vm;
        // No PLANO-SEQUÊNCIA o clipe precisa ir de um keyframe ao próximo (endImageUrl) — o lip-sync
        // gera do keyframe de abertura e não respeita o de saída, quebrando a continuidade. Aí a fala
        // entra por i2v mudo + mux (áudio colado por cima). Só desliga quando o clipe É de fato plano:
        // scenePayloads só põe endImageUrl com modelo tail-capable e havendo próxima cena — assim,
        // plano em modelo sem tail (ou a última cena) mantém a boca sincronizada.
        $planoClip = ($clip['endImageUrl'] ?? '') !== '';
        if (is_array($lines) && $lines !== [] && ! $p->isNarrated() && ! $planoClip) {
            if ($lipModel = GenModel::resolveSelectable('vid-lipsync', 'video', $t->plan)) {
                $lipLine = $this->videoGenLine($lipModel);
                $videoCostModel = $lipModel;
            }
        }
        // 1 clipe de vídeo por cena — o lip-sync anima o keyframe UMA vez com o diálogo inteiro. (Era
        // count($lines), da época do talking-head por fala: cobrava 3 clipes numa cena de 3 falas e
        // entregava 1, porque as falas seguintes falhavam em silêncio. Ver AnimationSceneJob.)
        $weight = $this->usage->weightFor('video');
        if (! $this->usage->tryConsume($t, 'video', $weight, $videoCostModel?->cost_credits)) {
            if ($upscale) {
                $this->usage->refund($t, 'image', $upscale['weight'], $upscale['cost']);
            }

            return 'Limite do plano atingido para geração de vídeo.';
        }
        $nLines = is_array($lines) ? count($lines) : 0;
        if ($nLines > 0 && ! $this->usage->tryConsume($t, 'audio', $nLines, 1)) {
            $this->usage->refund($t, 'video', $weight, $videoCostModel?->cost_credits);
            if ($upscale) {
                $this->usage->refund($t, 'image', $upscale['weight'], $upscale['cost']);
            }

            return 'Limite do plano atingido para narração/fala.';
        }
        // Voz padrão da conta pra quem ficou sem cast (fallback do serviço se vazio).
        if (is_array($lines)) {
            foreach ($lines as $k => $l) {
                if (($l['voice_id'] ?? '') === '') {
                    $lines[$k]['voice_id'] = (string) ($t->voice_id ?? '');
                }
            }
        }
        $am = GenModel::active()->kind('audio')->forPlan($t->plan)->orderBy('sort_order')->first();
        // ⏱️ started_at + stage: é o que a tela usa para dizer HÁ QUANTO TEMPO e EM QUE ETAPA a cena
        // está. Sem isso o card mostrava "animando a cena…" do primeiro ao último segundo, e um job
        // rodando há 8 minutos ficava visualmente idêntico a um travado — foi assim que uma geração
        // normal virou chamado de "está parado".
        $this->patchScene($p, $i, [
            'video_status' => 'generating',
            'video_started_at' => now()->timestamp,
            'video_stage' => $nLines > 0 ? 'dialogo' : 'clipe',
        ]);
        AnimationSceneJob::dispatch($p->id, $t->id, $i, $lines, $clip, $weight, $videoCostModel?->cost_credits, $nLines, $am?->provider_model_id ?? '', $upscale, $lipLine);

        return null;
    }

    public function dispatchAllScenes(AnimationProject $p, Tenant $t, array $opts = []): int
    {
        if ($p->mode === 'quadrinhos') {
            return 0; // slides-only: não existe etapa de animação
        }
        $n = 0;
        foreach (array_keys(array_values((array) $p->storyboard)) as $i) {
            $p->refresh();
            $sc = array_values((array) $p->storyboard)[$i] ?? [];
            if (($sc['video_url'] ?? '') !== '' || ($sc['video_status'] ?? '') === 'generating' || ($sc['keyframe_url'] ?? '') === '') {
                continue;
            }
            if ($this->dispatchScene($p, $t, $i, null, $opts) === null) {
                $n++;
            }
        }

        return $n;
    }

    /** Monta o filme final (cobrança short). Retorna erro legível ou null. Nos modos NARRADOS
     *  a montagem é o /v1/storyvideo (narração+legenda+música+efeitos) — dispatchStoryAssemble. */
    public function dispatchAssemble(AnimationProject $p, Tenant $t, array $opts = []): ?string
    {
        if ($p->isNarrated()) {
            // Caminho sem request explícito (modo automático): defaults do builder.
            return $this->dispatchStoryAssemble($p, $t, new Request);
        }
        $clips = [];
        foreach (array_values((array) $p->storyboard) as $sc) {
            $u = (string) ($sc['video_url'] ?? '');
            if ($u === '') {
                return 'Anime todas as cenas antes de montar o desenho.';
            }
            $clips[] = $u;
        }
        if ($clips === []) {
            return 'Storyboard vazio.';
        }
        if (! $this->usage->tryConsume($t, 'short', 1)) {
            return 'Limite de vídeos do plano atingido.';
        }
        $musicPrompt = trim((string) ($opts['musicPrompt'] ?? $p->music_prompt))
            ?: 'gentle playful orchestral soundtrack for an animated short film, warm and family-friendly';

        // 🔊 AMBIENTE + EFEITO POR CENA (opt-in). O clipe i2v é MUDO: sem isto o único som do
        // desenho é a trilha, e porta, passos e eco de salão simplesmente não existem. O engine e o
        // ffmpeg-service já aceitavam ambiencePrompt/sfx (o Filme usa desde a F4) — a Animação nunca
        // enviava. Vazio = comportamento de antes (só trilha), então nada muda para quem não pedir.
        // Cobrança igual à do Filme (bucket 'effect'): 3 por camada, estornada pelo job se falhar.
        $ambience = mb_substr(trim((string) ($opts['ambiencePrompt'] ?? '')), 0, 300);
        $sfxIn = (array) ($opts['sfx'] ?? []);
        $sfx = [];
        foreach (array_keys($clips) as $i) {
            $sfx[] = mb_substr(trim((string) ($sfxIn[$i] ?? '')), 0, 200);
        }
        $fxCount = ($ambience !== '' ? 3 : 0) + 3 * count(array_filter($sfx));
        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1);

            return 'Limite de efeitos do plano atingido.';
        }

        $payload = [
            'clipUrls' => $clips,
            'music' => (bool) ($opts['music'] ?? true),
            'musicPrompt' => mb_substr($musicPrompt, 0, 300),
            'ambiencePrompt' => $ambience,
            'sfx' => $sfx,
            'aspect' => $p->aspect,
            'transitionDefault' => (string) ($opts['transition'] ?? ''),
            'colorMatch' => true, // anti-drift de cor entre cenas (S3) — sempre no desenho
            // Cena no aspecto do projeto (o caso normal) → pad é no-op. Fora dele, o default cover+crop
            // comia o topo em SILÊNCIO: era assim que o desenho saía decapitado quando a cena vinha 3:4
            // (prod, projeto 12). Com pad o erro fica VISÍVEL (barras) em vez de virar corte.
            'padFit' => true,
        ];
        $p->update(['status' => 'assembling']);
        // fxCount viaja pro job: é ele quem estorna o bucket 'effect' se a montagem falhar.
        AnimationAssembleJob::dispatch($p->id, $t->id, $payload, fxCount: $fxCount);

        return null;
    }

    /** Beats do /v1/storyvideo a partir do storyboard (modos narrados): script = narração da
     *  cena; historia prefere o clipe i2v (fallback slide do keyframe); quadrinhos = sempre
     *  slide. sfx[i]/vfx[i] do request chegam alinhados às CENAS (cena pulada = índice pulado). */
    public function storyBeats(AnimationProject $p, Request $r): array
    {
        $sfxIn = array_values((array) $r->input('sfx', []));
        $vfxIn = array_values((array) $r->input('vfx', []));
        $beats = [];
        foreach (array_values((array) $p->storyboard) as $si => $sc) {
            $vid = (string) ($sc['video_url'] ?? '');
            $img = (string) ($sc['keyframe_url'] ?? '');
            $script = trim((string) ($sc['narration'] ?? ''));
            $b = null;
            if ($p->mode === 'historia' && $vid !== '' && StudioController::isOwnMediaUrl($vid)) {
                $b = ['video_url' => $vid, 'script' => $script]; // preferido: clipe i2v da cena
            } elseif ($img !== '' && StudioController::isOwnMediaUrl($img)) {
                $b = ['image_url' => $img, 'script' => $script]; // slide do keyframe (Ken Burns)
            }
            if ($b === null) {
                continue;
            }
            $b['sfx'] = mb_substr(trim((string) ($sfxIn[$si] ?? '')), 0, 200);
            $b['vfx'] = in_array($vfxIn[$si] ?? '', StudioController::VFX_KINDS, true) ? (string) $vfxIn[$si] : '';
            $beats[] = $b;
        }

        return $beats;
    }

    /** Montagem dos modos NARRADOS via /v1/storyvideo (builder compartilhado com storyVideo):
     *  narração TTS central + legenda + música + Estúdio de Efeitos. Slides-only = 40 créd. */
    public function dispatchStoryAssemble(AnimationProject $p, Tenant $t, Request $r): ?string
    {
        $beats = $this->storyBeats($p, $r);
        if ($beats === []) {
            return $p->mode === 'quadrinhos'
                ? 'Gere o storyboard (keyframes) antes de montar o episódio.'
                : 'Gere os keyframes (e os clipes) das cenas antes de montar.';
        }
        // RESERVE-THEN-CONSUME bucket 'short'; slides-only (Quadrinhos / história sem nenhum
        // clipe) = override de 40 créd — mesma regra do storyVideo clássico.
        $slidesOnly = array_filter($beats, fn ($b) => isset($b['video_url'])) === [];
        $shortCost = $slidesOnly ? self::SLIDES_ONLY_COST : null;
        if (! $this->usage->tryConsume($t, 'short', 1, $shortCost)) {
            return 'Limite de vídeos do plano atingido.';
        }
        [$payload, $fxCount, $platforms] = $this->buildShortMontage($r, $t, $beats, $p->lang, $p->aspect, (string) $p->voice_id);
        if ($payload['musicPrompt'] === '' && trim((string) $p->music_prompt) !== '') {
            $payload['musicPrompt'] = mb_substr(trim((string) $p->music_prompt), 0, 300); // trilha salva no projeto
        }
        if ($fxCount > 0 && ! $this->usage->tryConsume($t, 'effect', $fxCount)) {
            $this->usage->refund($t, 'short', 1, $shortCost);

            return 'Créditos insuficientes para os efeitos ('.$fxCount.').';
        }
        $p->update(['status' => 'assembling']);
        AnimationAssembleJob::dispatch($p->id, $t->id, $payload, '/v1/storyvideo', $shortCost, $fxCount, $platforms, $p->mode);

        return null;
    }

    /**
     * MODO AUTOMÁTICO: chamado pelos jobs ao terminar uma unidade. Reavalia o projeto e
     * despacha a PRÓXIMA etapa quando a atual completou. 402 no meio → para e marca o erro
     * (o usuário retoma manualmente depois de recarregar créditos).
     */
    public function advance(AnimationProject $p): void
    {
        $p->refresh();
        if (! $p->auto || $p->status === 'done' || $p->status === 'error' || $p->status === 'assembling') {
            return;
        }
        $t = Tenant::find($p->tenant_id);
        if (! $t) {
            return;
        }
        $els = (array) $p->elements;
        $allEls = array_merge(...array_map(fn ($k) => array_values((array) ($els[$k] ?? [])), ['characters', 'locations', 'props']));
        $elsPending = array_filter($allEls, fn ($e) => ($e['ref_url'] ?? '') === '');
        $elsRunning = array_filter($allEls, fn ($e) => ($e['status'] ?? '') === 'generating');
        if ($elsPending !== []) {
            if ($elsRunning === [] && $this->dispatchAllElements($p, $t) === 0) {
                $this->haltAuto($p, 'Créditos/limite insuficientes para gerar as referências.');
            }

            return; // ainda em elementos
        }
        $sb = array_values((array) $p->storyboard);
        $framesPending = array_filter($sb, fn ($s) => ($s['keyframe_url'] ?? '') === '');
        $framesRunning = array_filter($sb, fn ($s) => ($s['keyframe_status'] ?? '') === 'generating');
        if ($framesPending !== []) {
            if ($framesRunning === []) {
                // 🔗 Encadeamento serial: um keyframe com ERRO trava a corrente (as próximas cenas
                // ancoram nele). NÃO re-despacha em loop — para o auto com mensagem clara e o usuário
                // regenera aquela cena. (No modo solto os keyframes são independentes → segue o fluxo.)
                if ($p->chainsFrames() && array_filter($sb, fn ($s) => ($s['keyframe_status'] ?? '') === 'error') !== []) {
                    $this->haltAuto($p, 'Uma cena falhou ao gerar o keyframe. Regenere essa cena e retome o desenho.');

                    return;
                }
                $p->update(['status' => 'storyboard']);
                if ($this->dispatchAllFrames($p, $t) === 0) {
                    // 0 = sem crédito OU só restam cenas travadas sem keyframe (nada gerável) — mensagens distintas.
                    $generatable = array_filter($sb, fn ($s) => ($s['keyframe_url'] ?? '') === ''
                        && ($s['keyframe_status'] ?? '') !== 'generating' && ! ($s['locked'] ?? false));
                    $this->haltAuto($p, $generatable !== []
                        ? 'Créditos/limite insuficientes para gerar o storyboard.'
                        : 'Há cenas travadas sem keyframe — destrave ou gere o keyframe delas para continuar.');
                }
            }

            return;
        }
        // Quadrinhos = slides-only: não existe etapa de animação — do storyboard vai direto à montagem.
        if ($p->mode !== 'quadrinhos') {
            $videosPending = array_filter($sb, fn ($s) => ($s['video_url'] ?? '') === '');
            $videosRunning = array_filter($sb, fn ($s) => ($s['video_status'] ?? '') === 'generating');
            if ($videosPending !== []) {
                if ($videosRunning === []) {
                    // ⛔ Mesma regra do keyframe (ver acima): cena com ERRO NÃO pode ser re-despachada
                    // em loop. dispatchAllScenes() não pula video_status='error' (de propósito — o
                    // retry MANUAL do AnimationController depende disso), e o job estorna o crédito ao
                    // falhar, então o haltAuto por saldo nunca dispararia: o auto re-geraria a mesma
                    // cena pra sempre, queimando chamada de provedor a cada volta. Para e avisa; o
                    // usuário regenera aquela cena e retoma. (Prod 2026-07-15: 6h de loop no proj. 12.)
                    if (array_filter($sb, fn ($s) => ($s['video_status'] ?? '') === 'error') !== []) {
                        $this->haltAuto($p, 'Uma cena falhou ao gerar o vídeo. Regenere essa cena e retome o desenho.');

                        return;
                    }
                    $p->update(['status' => 'animating']);
                    if ($this->dispatchAllScenes($p, $t) === 0) {
                        $this->haltAuto($p, 'Créditos/limite insuficientes para animar as cenas.');
                    }
                }

                return;
            }
        }
        if ($p->final_url === '') {
            if ($err = $this->dispatchAssemble($p, $t)) {
                $this->haltAuto($p, $err);
            }
        }
    }

    private function haltAuto(AnimationProject $p, string $msg): void
    {
        Log::warning('AnimationFlow: auto interrompido', ['project' => $p->id, 'motivo' => $msg]);
        $p->update(['auto' => false, 'status' => 'error', 'error' => $msg]);
    }

    /** Estimativa de custo (créditos) por etapa, na qualidade atual — mostrada ANTES de gerar.
     *  Por modo: quadrinhos não tem etapa de cenas (slides) e monta por 40 créd; historia anima
     *  cenas mudas (sem falas — narração inclusa na montagem); animacao = fluxo original. */
    public function quote(AnimationProject $p, ?string $plan): array
    {
        $els = (array) $p->elements;
        $nChars = count((array) ($els['characters'] ?? []));
        $nRefs = count((array) ($els['locations'] ?? [])) + count((array) ($els['props'] ?? []));
        $nScenes = count((array) $p->storyboard);
        $nLines = 0;
        if (! $p->isNarrated()) {
            foreach (array_values((array) $p->storyboard) as $sc) {
                $nLines += count((array) ($sc['dialogue'] ?? []));
            }
        }
        $c = fn (?GenModel $m) => (int) ($m?->cost_credits ?? 0);
        $char = $c($this->imageModel($p, 'char', $plan));
        $ref = $c($this->imageModel($p, 'ref', $plan));
        $frame = $c($this->imageModel($p, 'frame', $plan));
        $vm = $this->videoModel($p, $plan);
        $video = $p->mode === 'quadrinhos' ? 0 : $c($vm);
        // Lip-sync PER-FALA: cena com diálogo custa (nº de falas) × lip-sync; cena de ação = i2v normal.
        $lip = ($p->mode !== 'quadrinhos' && ! $p->isNarrated())
            ? $c(GenModel::resolveSelectable('vid-lipsync', 'video', $plan)) : 0;
        // Plano-sequência: o clipe encadeia keyframes (endImageUrl) e NÃO usa lip-sync — mas só num
        // modelo tail-capable e nas cenas com "próxima" (a ÚLTIMA cena não é plano → volta ao lip).
        // Espelha o $planoClip do dispatchScene pra a estimativa bater com a cobrança real.
        $planoActive = $p->chainsClips() && $this->isTailCapable($vm);
        $scenesVideo = 0;
        if ($p->mode !== 'quadrinhos') {
            $scenes = array_values((array) $p->storyboard);
            $lastIdx = count($scenes) - 1;
            foreach ($scenes as $idx => $sc) {
                $dl = $p->isNarrated() ? 0 : count((array) ($sc['dialogue'] ?? []));
                $isPlanoScene = $planoActive && $idx < $lastIdx;
                $scenesVideo += ($lip > 0 && $dl > 0 && ! $isPlanoScene) ? $dl * $lip : $video;
            }
        }
        $assemble = $p->mode === 'quadrinhos'
            ? self::SLIDES_ONLY_COST
            : (int) app(UsageService::class)->creditCostFor('short');

        return [
            'elements' => $nChars * $char + $nRefs * $ref,
            'frames' => $nScenes * $frame,
            'scenes' => $scenesVideo + $nLines,
            'assemble' => $assemble,
            'total' => $nChars * $char + $nRefs * $ref + $nScenes * $frame + $scenesVideo + $nLines + $assemble,
        ];
    }

    /** Read-modify-write de UMA cena do storyboard sob lock (cenas geram em paralelo). */
    public function patchScene(AnimationProject $p, int $i, array $patch): void
    {
        DB::transaction(function () use ($p, $i, $patch) {
            $fresh = AnimationProject::lockForUpdate()->find($p->id);
            if (! $fresh) {
                return;
            }
            $sb = array_values((array) $fresh->storyboard);
            if (! array_key_exists($i, $sb)) {
                return;
            }
            $sb[$i] = array_merge($sb[$i], $patch);
            $fresh->update(['storyboard' => $sb]);
        });
        $p->refresh();
    }

    /**
     * Edição ESTRUTURAL do storyboard sob lock (paridade com story-scene-add/remove/move da tela
     * clássica): $op = add|remove|move. Cenas travadas (locked) não são movidas/removidas. Retorna
     * erro legível ou null. Cap de 20 cenas no add (mesmo teto do parser).
     */
    public function editStoryboard(AnimationProject $p, string $op, int $i, int $dir = 0): ?string
    {
        $err = null;
        DB::transaction(function () use ($p, $op, $i, $dir, &$err) {
            $fresh = AnimationProject::lockForUpdate()->find($p->id);
            if (! $fresh) {
                $err = 'projeto inexistente';

                return;
            }
            $sb = array_values((array) $fresh->storyboard);
            $n = count($sb);
            if ($op === 'add') {
                if ($n >= 20) {
                    $err = 'limite de 20 cenas atingido';

                    return;
                }
                // cena EM BRANCO (sem mídia): entra logo após $i (ou no fim se $i inválido).
                $blank = ['title' => '', 'action' => '', 'narration' => '', 'image_prompt' => '',
                    'video_prompt' => '', 'location' => '', 'characters' => [], 'keyframe_url' => '',
                    'keyframe_status' => '', 'video_url' => '', 'video_status' => '', 'locked' => false];
                $pos = ($i >= 0 && $i < $n) ? $i + 1 : $n;
                array_splice($sb, $pos, 0, [$blank]);
            } elseif ($op === 'remove') {
                if (! isset($sb[$i])) {
                    $err = 'cena inexistente';

                    return;
                }
                if ($n <= 1) {
                    $err = 'não dá pra remover a única cena';

                    return;
                }
                if ($sb[$i]['locked'] ?? false) {
                    $err = 'cena travada — destrave para remover';

                    return;
                }
                array_splice($sb, $i, 1);
            } elseif ($op === 'move') {
                $j = $i + ($dir < 0 ? -1 : 1);
                if (! isset($sb[$i]) || ! isset($sb[$j])) {
                    $err = 'não dá pra mover além das bordas';

                    return;
                }
                [$sb[$i], $sb[$j]] = [$sb[$j], $sb[$i]];
            } else {
                $err = 'operação inválida';

                return;
            }
            $fresh->update(['storyboard' => array_values($sb)]);
        });
        $p->refresh();

        return $err;
    }
}
