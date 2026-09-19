<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesTextModel;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateShotFrameJob;
use App\Models\GenModel;
use App\Models\Prompt;
use App\Models\Scenario;
use App\Models\Scene;
use App\Models\Shot;
use App\Services\UsageService;
use App\Support\EngineClient;
use App\Support\GenPayload;
use App\Support\Plano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PLANOS da cena (decupagem). A cena diz o que acontece; o plano diz de onde a câmera vê.
 *
 * Cena SEM plano nenhum continua valendo — vira um plano só na produção, que é como o produto
 * funcionava antes desta camada existir. Ninguém é obrigado a decupar.
 */
class ShotController extends Controller
{
    use ResolvesTextModel;

    private function tenantId(Request $r): int
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t->id;
    }

    private function cena(Request $r, int|string $sceneId): Scene
    {
        return Scene::where('id', $sceneId)->where('tenant_id', $this->tenantId($r))->firstOrFail();
    }

    /** O vocabulário de decupagem, pro front montar os seletores sem duplicar a lista. */
    public function vocabulario(): JsonResponse
    {
        return response()->json(Plano::vocabulario());
    }

    public function store(Request $r): JsonResponse
    {
        $data = $this->validar($r, ['scene_id' => 'required|integer']);
        $cena = $this->cena($r, $data['scene_id']);

        $shot = Shot::create([
            'tenant_id' => $cena->tenant_id,
            'scene_id' => $cena->id,
            'ordem' => (int) $cena->shots()->max('ordem') + 1,
        ] + $this->campos($data));

        return response()->json(['ok' => true, 'shot' => $shot], 201);
    }

    public function update(Request $r, string $id): JsonResponse
    {
        $shot = Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
        $shot->update($this->campos($this->validar($r)));

        return response()->json(['ok' => true, 'shot' => $shot->fresh()]);
    }

    public function destroy(Request $r, string $id): JsonResponse
    {
        Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail()->delete();

        return response()->json(['ok' => true]);
    }

    /** Reordena por lista de ids — a ordem dos planos É a ordem da montagem dentro da cena. */
    public function reorder(Request $r): JsonResponse
    {
        $ids = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        $tid = $this->tenantId($r);
        foreach (array_values($ids) as $i => $id) {
            Shot::where('id', $id)->where('tenant_id', $tid)->update(['ordem' => $i + 1]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/scenes/{id}/decupar → a IA quebra a cena em PLANOS.
     *
     * O texto de quem decupa é a persona "🎬 Decupagem" da aba Prompts, como todo o resto do fluxo
     * — o rigor da cobertura se ajusta editando lá, sem deploy. ACRESCENTA aos planos existentes,
     * como o /plan faz com as cenas: repetir é seguro, nada é apagado.
     */
    public function decupar(Request $r, string $id): JsonResponse
    {
        $cena = $this->cena($r, $id);
        $n = (int) ($r->validate(['planos' => 'nullable|integer|min:2|max:12'])['planos'] ?? 4);

        $persona = Prompt::where('tenant_id', $cena->tenant_id)->where('title', 'like', '%Decupagem%')->first();
        $sys = trim((string) ($persona->content ?? ''));
        if ($sys === '') {
            return response()->json(['ok' => false, 'error' => "A persona '🎬 Decupagem' não está na aba Prompts."], 404);
        }

        $cenario = $cena->scenario_id
            ? Scenario::where('id', $cena->scenario_id)->where('tenant_id', $cena->tenant_id)->value('name')
            : null;

        $msg = "CENA: ".trim(implode(' / ', array_filter([$cena->int_ext, $cena->local, $cena->tempo])))
            ."\nCENÁRIO: ".($cenario ?: '—')
            ."\nAÇÃO: ".trim((string) $cena->resumo)
            ."\nOBJETIVO: ".trim((string) $cena->objetivo_cena)
            ."\nCONFLITO: ".trim((string) $cena->conflito_cena)
            ."\nNARRAÇÃO: ".trim((string) $cena->narracao)
            ."\nNÚMERO DE PLANOS: {$n}"
            // O vocabulário vai JUNTO: sem ele o modelo inventa rótulo ("plano dramático") e o
            // valor cai fora da allowlist na hora de gravar — plano sem decupagem nenhuma.
            ."\n\nVALORES ACEITOS (use EXATAMENTE estas chaves):"
            ."\nfuncao: ".implode(', ', array_keys(Plano::FUNCAO))
            ."\nenquadramento: ".implode(', ', array_keys(Plano::ENQUADRAMENTO))
            ."\nangulo: ".implode(', ', array_keys(Plano::ANGULO))
            ."\naltura: ".implode(', ', array_keys(Plano::ALTURA))
            ."\nmovimento: ".implode(', ', array_keys(Plano::MOVIMENTO));

        $res = EngineClient::make(180)->post('/v1/chat', [
            'system' => $sys,
            'message' => $msg,
            'json' => true,
            'maxTokens' => 3000,
            'gen_lines' => $this->textGenLines($this->textModelFor($r, $r->user()->tenant->plan ?? null)),
        ]);

        $lista = $this->planosDoJson((string) $res->json('text'));
        if ($lista === []) {
            return response()->json(['ok' => false, 'error' => 'A IA não devolveu uma decupagem utilizável. Tente de novo.'], 502);
        }

        $ordem = (int) $cena->shots()->max('ordem');
        foreach (array_slice($lista, 0, $n) as $p) {
            Shot::create([
                'tenant_id' => $cena->tenant_id,
                'scene_id' => $cena->id,
                'ordem' => ++$ordem,
                // Valor fora do vocabulário vira NULL em vez de sujar o banco: o plano sai sem
                // aquele traço e o resto continua de pé.
                'funcao' => $this->limpo('funcao', $p['funcao'] ?? null),
                'enquadramento' => $this->limpo('enquadramento', $p['enquadramento'] ?? null),
                'angulo' => $this->limpo('angulo', $p['angulo'] ?? null),
                'altura' => $this->limpo('altura', $p['altura'] ?? null),
                'movimento' => $this->limpo('movimento', $p['movimento'] ?? null),
                'acao' => mb_substr(trim((string) ($p['acao'] ?? '')), 0, 600),
                'duracao' => max(1, min(30, (int) ($p['duracao'] ?? 5))),
            ]);
        }

        return response()->json(['ok' => true, 'criados' => min(count($lista), $n), 'shots' => $cena->shots()->get()]);
    }

    /**
     * POST /api/shots/{id}/ancora (multipart: file) → guarda o RENDER da malha no ângulo deste
     * plano e o usa como âncora dele.
     *
     * POR QUE UM ENDPOINT PRÓPRIO: o upload genérico do estúdio cria um Draft por arquivo, e um
     * render de âncora por plano encheria a galeria de imagens técnicas. Aqui o dono é o plano.
     */
    public function ancora(Request $r, string $id): JsonResponse
    {
        $shot = Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
        $r->validate(['file' => 'required|file|mimes:png,jpg,jpeg|max:10240']);

        $url = StudioController::storeUploadedFile($r->file('file'), 'png', 'image');
        $shot->update(['ancora_url' => $url]);

        return response()->json(['ok' => true, 'shot' => $shot->fresh()]);
    }

    /** GET /api/shots/{id} — o polling do quadro lê o estado sem recarregar a escaleta inteira. */
    public function show(Request $r, string $id): JsonResponse
    {
        $shot = Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();

        return response()->json(['ok' => true, 'shot' => $shot]);
    }

    /**
     * POST /api/shots/{id}/quadro → gera o QUADRO do plano OBEDECENDO a âncora de ângulo
     * (Fase 2.1 do docs/ESTUDIO-3D.md): o motor local transforma o render da malha em
     * contorno e desenha a cena por cima — o ângulo deixa de ser pedido por texto.
     *
     * Exige o motor `img-local-pose` ativo (só existe onde o ComfyUI está de pé — o modelo
     * nasce inativo no catálogo, decisão do §7 do plano). Async porque o quadro leva ~104s
     * medidos: o padrão síncrono estouraria qualquer timeout razoável.
     */
    /**
     * AJUSTES RÁPIDOS do quadro — vocabulário FECHADO, não prosa.
     *
     * Vem do loop de aprovação que os guias de storyboard usam ("darker mood", "wider camera
     * angles"…). A diferença é que aqui cada item vira uma frase CONCRETA em inglês colada ao
     * prompt, em vez de um adjetivo solto: "mais dramático" não muda nada num difusor, "key light
     * from a single hard source, deep shadows, high contrast" muda.
     *
     * Allowlist de propósito: valor livre viraria campo morto no prompt, sem erro e sem efeito.
     */
    private const AJUSTES = [
        'escuro' => 'Darker mood: lower key, deeper shadows, less fill light.',
        'claro' => 'Brighter exposure: lifted shadows, softer contrast, more ambient light.',
        'dramatico' => 'More dramatic light: one hard key source, deep falloff, high contrast.',
        'aberto' => 'Wider framing: more environment around the subject, subject smaller in frame.',
        'fechado' => 'Tighter framing: closer to the subject, less headroom, more of the face.',
        'chuva' => 'Stronger rain atmosphere: wet surfaces, visible droplets, damp diffusion in the air.',
        'neblina' => 'Atmospheric haze: volumetric light shafts, depth separation between planes.',
        'quente' => 'Warmer grade: amber highlights, warm skin, cooler shadows for separation.',
        'frio' => 'Cooler grade: teal shadows, desaturated palette, colder ambient light.',
        'mao' => 'Handheld feel: slight off-axis framing, imperfect horizon, documentary immediacy.',
    ];

    /** GET /api/shots/ajustes — o vocabulário de ajuste, pro front montar os botões sem duplicar. */
    public function ajustes(): JsonResponse
    {
        return response()->json(['ok' => true, 'ajustes' => array_map(
            fn ($k, $v) => ['key' => $k, 'frase' => $v],
            array_keys(self::AJUSTES), self::AJUSTES,
        )]);
    }

    /**
     * POST /api/shots/{id}/upscale — sobe a resolução do quadro JÁ aprovado deste plano.
     *
     * A ponte que faltava: o quadro do storyboard nasce pequeno (é um painel numa folha), e pra
     * virar âncora de vídeo ele precisa de detalhe. Reusa /studio/enhance, que já faz upscale —
     * aqui só amarra o resultado de volta ao PLANO, que é o que transforma "uma imagem melhor"
     * em "o keyframe deste plano".
     */
    public function upscale(Request $r, string $id): JsonResponse
    {
        $shot = Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
        $atual = trim((string) $shot->quadro_url);
        if ($atual === '') {
            return response()->json(['ok' => false, 'error' => 'Este plano ainda não tem quadro para melhorar.'], 422);
        }

        // Reusa o endpoint de pós-processamento — mesma cobrança, mesma rota de modelos, um
        // caminho só de upscale no produto.
        $req = Request::create('/api/studio/enhance', 'POST', [
            'imageUrl' => $atual,
            'op' => $r->input('pro') ? 'upscale_pro' : 'upscale',
        ]);
        $req->setUserResolver(fn () => $r->user());
        $resp = app(StudioController::class)->enhance($req);
        $data = json_decode($resp->getContent(), true);
        if (! ($data['ok'] ?? false) || empty($data['url'])) {
            return response()->json(['ok' => false, 'error' => $data['error'] ?? 'Não foi possível melhorar o quadro.'], 502);
        }

        // O quadro melhorado SUBSTITUI o do plano: é ele que vira a âncora do clipe. O anterior
        // continua na galeria — nada se perde, e dá pra voltar atrás.
        $shot->update(['quadro_url' => $data['url'], 'quadro_status' => 'pronto']);

        return response()->json(['ok' => true, 'url' => $data['url'], 'shot' => $shot->fresh()]);
    }

    public function quadro(Request $r, string $id, UsageService $usage): JsonResponse
    {
        $shot = Shot::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
        if (! $shot->ancora_url) {
            return response()->json(['ok' => false, 'error' => 'Este plano não tem âncora de ângulo — ancore a malha primeiro (📐).'], 422);
        }
        if ($shot->quadro_status === 'gerando') {
            return response()->json(['ok' => false, 'error' => 'Já existe um quadro sendo gerado para este plano.'], 409);
        }

        $t = $r->user()->tenant;
        $gm = GenModel::resolveSelectable('img-local-pose', 'image', $t->plan);
        if (! $gm) {
            return response()->json(['ok' => false, 'error' => 'O motor local de pose não está ativo neste ambiente.'], 422);
        }

        $weight = $usage->weightFor('image');
        if (! $usage->tryConsume($t, 'image', $weight, $gm->cost_credits)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração de imagem.'], 402);
        }

        // O prompt é a cena vista DESTE plano: o que acontece (ação do plano, ou o resumo da
        // cena) + a frase de câmera SEM o movimento — quadro é still; "slow dolly in" num
        // frame parado só confunde o modelo. O ângulo em si quem impõe é a âncora (Canny).
        $cena = Scene::find($shot->scene_id);
        $camera = Plano::frase([
            'funcao' => $shot->funcao, 'enquadramento' => $shot->enquadramento,
            'angulo' => $shot->angulo, 'altura' => $shot->altura,
        ]);
        $oQueAcontece = trim((string) ($shot->acao ?: $cena?->resumo ?: ''));
        if ($oQueAcontece === '') {
            return response()->json(['ok' => false, 'error' => 'Descreva a ação do plano (ou o resumo da cena) antes de gerar o quadro.'], 422);
        }
        $prompt = $oQueAcontece.($camera !== '' ? '. '.$camera : '').'. Cinematic still frame, high quality.';

        // REFINO DO PAINEL: o ajuste escolhido entra como frase concreta no fim do prompt. É o que
        // permite mexer em UM quadro em vez de refazer a folha inteira — o furo dos fluxos que
        // pedem "regenerate the ENTIRE storyboard" a cada correção. Aqui a folha é composta de
        // imagens separadas, então regerar um painel custa uma imagem, não N.
        $ajuste = (string) $r->input('ajuste', '');
        if ($ajuste !== '' && isset(self::AJUSTES[$ajuste])) {
            $prompt .= ' '.self::AJUSTES[$ajuste];
        }

        $payload = array_merge([
            'prompt' => $prompt,
            'aspect' => '16:9',
            'imageUrls' => [$shot->ancora_url],
        ], GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null)));

        $shot->update(['quadro_status' => 'gerando']);
        GenerateShotFrameJob::dispatch($shot->id, $t->id, $payload, $weight, $gm->cost_credits);

        return response()->json(['ok' => true, 'shot' => $shot->fresh()]);
    }

    /** Validação comum: vocabulário fechado nos 4 campos de decupagem (allowlist). */
    private function validar(Request $r, array $extra = []): array
    {
        $regras = $extra + [
            'acao' => 'nullable|string|max:600',
            'ancora_url' => 'nullable|string|max:500',
            'duracao' => 'nullable|integer|min:1|max:30',
        ];
        foreach (['funcao', 'enquadramento', 'angulo', 'altura', 'movimento'] as $campo) {
            $regras[$campo] = ['nullable', 'string', 'max:24', function ($attr, $v, $fail) use ($campo) {
                if (! Plano::valido($campo, $v)) {
                    $fail("Valor inválido para {$campo}.");
                }
            }];
        }

        return $r->validate($regras);
    }

    /** Só os campos do plano (sem scene_id), já filtrados pelo que veio na requisição. */
    private function campos(array $data): array
    {
        return array_intersect_key($data, array_flip(['funcao', 'enquadramento', 'angulo', 'altura', 'movimento', 'acao', 'ancora_url', 'duracao']));
    }

    private function limpo(string $campo, mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return ($v !== '' && Plano::valido($campo, $v)) ? $v : null;
    }

    /** Lê `planos[]` do texto do modelo — tolerante a cerca ```json e texto em volta. */
    private function planosDoJson(string $raw): array
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
        $lista = is_array($j) ? ($j['planos'] ?? $j['shots'] ?? null) : null;

        return is_array($lista) ? array_values(array_filter($lista, 'is_array')) : [];
    }
}
