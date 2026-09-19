<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateMeshJob;
use App\Models\AssetVersion;
use App\Models\Draft;
use App\Services\UsageService;
use App\Support\EngineClient;
use App\Support\GaleriaMalha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HISTÓRICO DE IMAGENS de personagem, cenário e elemento — e a volta pra uma delas.
 *
 * Antes a gente sobrescrevia: regerar trocava a URL e a anterior sumia do produto (o arquivo
 * continuava no storage, mas ninguém sabia o endereço). Agora a tomada anterior fica a um clique.
 */
class AssetVersionController extends Controller
{
    private function tenantId(Request $r): int
    {
        $t = $r->user()->tenant;
        abort_unless($t, 404, 'Usuário sem tenant.');

        return $t->id;
    }

    /** O dono, já checado contra o tenant — versão de outra marca não abre. */
    private function dono(Request $r, string $tipo, int $id)
    {
        $classe = AssetVersion::DONOS[$tipo] ?? null;
        abort_unless($classe, 422, 'Tipo de asset inválido.');

        return $classe::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
    }

    /** GET /api/asset-versions?tipo=character&id=14 → da mais nova pra mais velha. */
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo' => 'required|string|in:'.implode(',', array_keys(AssetVersion::DONOS)),
            'id' => 'required|integer',
        ]);
        $this->dono($r, $d['tipo'], (int) $d['id']);

        return response()->json(
            AssetVersion::where('tenant_id', $this->tenantId($r))
                ->where('owner_type', $d['tipo'])->where('owner_id', $d['id'])
                ->latest('id')->limit(30)->get()
        );
    }

    /**
     * POST /api/asset-versions/{id}/restore → volta o asset para esta versão.
     *
     * A versão ATUAL vira histórico automaticamente (o trait `GuardaVersoes` dispara no update), e
     * a linha restaurada é apagada pra não duplicar — senão a mesma URL apareceria duas vezes na
     * lista, uma como atual e outra como antiga.
     */
    public function restore(Request $r, string $id): JsonResponse
    {
        $v = AssetVersion::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail();
        $dono = $this->dono($r, $v->owner_type, (int) $v->owner_id);

        $dono->update([$v->campo => $v->url]);
        $v->delete();

        return response()->json(['ok' => true, 'asset' => $dono->fresh()]);
    }

    /**
     * POST /api/mesh-upload (multipart: tipo, id, file) → sobe a MALHA (GLB) de um personagem ou
     * elemento. Um endpoint só pros dois donos: é o mesmo arquivo, o mesmo storage e a mesma
     * checagem de tenant — dois controllers seriam duas cópias da mesma coisa.
     *
     * A GERAÇÃO da malha fica de fora: exige GPU local (ComfyUI/Trellis) ou API 3D paga. Aqui ela
     * entra pronta, de onde o usuário quiser (3D Gen Studio, Tripo, Blender).
     */
    public function meshUpload(Request $r): JsonResponse
    {
        $d = $r->validate([
            'tipo' => 'required|string|in:'.implode(',', array_keys(AssetVersion::DONOS)),
            'id' => 'required|integer',
            // GLB é o que os geradores de malha cospem (Trellis, Tripo, Hunyuan) e o que o
            // three.js lê sem conversor. 80MB cobre malha de personagem com textura embutida.
            'file' => 'required|file|mimetypes:model/gltf-binary,application/octet-stream|max:81920',
            // FBX opcional no mesmo passe: formato nativo das engines (Unity importa sem pacote).
            'fbx' => 'nullable|file|mimetypes:application/octet-stream|max:40960',
        ]);
        abort_unless($d['tipo'] !== 'scenario', 422, 'Cenário não tem malha.');
        $dono = $this->dono($r, $d['tipo'], (int) $d['id']);

        $file = $r->file('file');
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'glb', 422, 'Envie um arquivo .glb.');

        $dados = ['mesh_url' => StudioController::storeUploadedFile($file, 'glb', 'mesh')];

        if ($fbx = $r->file('fbx')) {
            abort_unless(strtolower($fbx->getClientOriginalExtension()) === 'fbx', 422, 'O arquivo extra deve ser .fbx.');
            // Assinatura binária do FBX — extensão não prova nada (mesma régua do "glTF" no GLB).
            $magic = (string) file_get_contents($fbx->getRealPath(), false, null, 0, 18);
            abort_unless(str_starts_with($magic, 'Kaydara FBX Binary'), 422, 'Arquivo não é um FBX binário.');
            $dados['fbx_url'] = StudioController::storeUploadedFile($fbx, 'fbx', 'mesh');
        }
        $dono->update($dados);

        // Malha SUBIDA à mão entra na galeria igual à gerada: pro acervo, a origem não muda o que
        // a peça é. Fazer só na geração deixaria metade das malhas invisível no acervo.
        GaleriaMalha::publica((int) $r->user()->tenant->id, $d['tipo'], (int) $dono->id, $dono->name ?? null, (string) $dados['mesh_url']);

        return response()->json(['ok' => true, 'asset' => $dono->fresh()]);
    }

    /**
     * POST /api/mesh-generate {tipo, id, imageUrl} — a malha NASCE no Estúdio (TRELLIS no ComfyUI)
     * a partir de uma imagem do acervo, em vez de vir de fora por upload.
     *
     * Entra pelo MESMO caminho do meshUpload (grava `mesh_url` no dono), então tudo que já existe
     * pra malha — viewer, âncora de ângulo, receitas do Blender, exportação Unity — funciona sem
     * saber de onde ela veio.
     *
     * ASSÍNCRONO desde 2026-08-02. Antes este endpoint chamava o engine DENTRO do request e
     * segurava a conexão HTTP a geração inteira. Em produção isso NUNCA ia terminar: medimos 285s
     * numa geração e o clique das 14:38 virou HTTP 499 no nginx (o navegador desistiu e fechou a
     * conexão) enquanto o engine só concluiu às 14:40 — a malha saía, mas ninguém a recolhia.
     * Nenhum navegador/proxy espera 4-5 minutos. Agora o controller só RESERVA a cota, marca
     * `mesh_status = 'gerando'` e enfileira; a resposta volta na hora, SEM a malha, e o front cai
     * no polling — igual imagem lenta, vídeo e música, que já eram assim.
     */
    public function meshGenerate(Request $r, UsageService $usage): JsonResponse
    {
        $d = $r->validate([
            'tipo' => 'required|string|in:'.implode(',', array_keys(AssetVersion::DONOS)),
            'id' => 'required|integer',
            'imageUrl' => 'required|string',
        ]);
        abort_unless($d['tipo'] !== 'scenario', 422, 'Cenário não tem malha.');
        $dono = $this->dono($r, $d['tipo'], (int) $d['id']);
        $this->destravaMalha($dono);

        // Uma malha por vez, por asset: cada geração ocupa a GPU por minutos e o duplo-clique
        // (ou o retry de quem achou que travou) só faria a segunda atropelar a primeira.
        if ((string) $dono->mesh_status === 'gerando') {
            return response()->json(['ok' => false, 'error' => 'Já existe uma malha sendo gerada para este item.'], 409);
        }

        // Anti-SSRF: a imagem tem que ser do NOSSO acervo — o engine vai baixá-la e mandar pro
        // ComfyUI, então URL de terceiro aqui viraria fetch cego a partir da nossa infra.
        $img = StudioController::ownMediaUrl($d['imageUrl']);
        abort_unless($img, 422, 'Imagem inválida (use uma imagem do seu acervo).');

        // PERSONAGEM vai com o lock: o gerador 3D só olha a imagem e INVENTA o lado que ela
        // não mostra (caso real 2026-07-31: MEL, fêmea, virou malha com anatomia de macho a
        // partir da foto-base que escondia a barriga). Com o lock, o engine gera primeiro uma
        // FICHA DE MALHA (corpo inteiro, perfil, fundo branco, guarda de sexo do motor de
        // imagem) e a malha nasce dela. Elemento (objeto) segue direto da imagem, como antes.
        $payload = ['imageUrl' => $img];
        if ($d['tipo'] === 'character') {
            $payload['lock'] = (string) ($dono->lock ?: $dono->description ?: '');
            $payload['style'] = (string) ($dono->style ?: '');
        }

        // COBRANÇA reserve-then-consume: reserva ANTES de enfileirar, o job estorna se a malha não
        // vier. O gerador roda no Estúdio Local (GPU própria), então o custo em créditos é 0 — a
        // tela sempre prometeu "não gasta crédito" e isso não muda. O que a reserva faz aqui é
        // (a) o feature-gate por plano e (b) o registro de consumo, que é o teto contra pedir GPU
        // sem fim (baseline de segurança: cota de consumo em runtime).
        $t = $r->user()->tenant;
        $weight = $usage->weightFor('image');
        if (! $usage->tryConsume($t, 'image', $weight, 0)) {
            return response()->json(['ok' => false, 'error' => 'Limite do plano atingido para geração.'], 402);
        }

        // A partir daqui a resposta é IMEDIATA: quem termina o trabalho é o worker.
        // `mesh_msg` zera junto: o motivo é da rodada, não do asset — deixá-lo mostraria o erro
        // antigo colado na geração nova enquanto ela ainda está rodando.
        $dono->update(['mesh_status' => 'gerando', 'mesh_msg' => null]);
        GenerateMeshJob::dispatch($d['tipo'], (int) $dono->id, (int) $t->id, $payload, $weight, 0);

        // Volta SEM `mesh_url` de propósito — é o sinal de "caiu no polling". O front acompanha
        // `mesh_status` ('gerando' → null com a malha | 'aviso' | 'erro') em /api/characters
        // e /api/elements, exatamente como já faz com `status` e `quadro_status`.
        return response()->json(['ok' => true, 'async' => true, 'asset' => $dono->fresh()]);
    }

    /**
     * Auto-heal de malha "presa": asset marcado como gerando mas parado há mais tempo que o pior
     * caso de uma geração (worker morreu sem limpar o estado). Sem isto o botão ficaria travado
     * pra sempre e o usuário não teria saída. 30 min = folga larga sobre os ~5 min medidos.
     * Mesma régua do unstick() do CharacterController.
     */
    private function destravaMalha(object $dono): void
    {
        if ((string) $dono->mesh_status === 'gerando' && $dono->updated_at && $dono->updated_at->lt(now()->subMinutes(30))) {
            // Sem motivo do juiz: aqui o worker sumiu sem se explicar, e inventar uma causa seria
            // pior que dizer só que falhou. A frase genérica da tela cabe exatamente neste caso.
            $dono->update(['mesh_status' => 'erro', 'mesh_msg' => null]);
        }
    }

    /**
     * DELETE /api/mesh/{tipo}/{id} — EXCLUI a malha do dono (e o FBX, que deriva dela).
     *
     * Existia gerar, trocar e regenerar — mas não excluir: uma malha que saiu errada ficava
     * presa no asset até alguém subir outra por cima (caso real 2026-07-31: a MEL, fêmea,
     * ganhou malha com anatomia de macho — o gerador 3D parte SÓ da imagem e inventa o lado
     * que ela não mostra; imagem-base que não mostra a barriga deixa o prior decidir).
     * Só limpa os PONTEIROS: o arquivo continua no storage, mesma regra do histórico de
     * imagens — o endereço some do produto, o bem não é destruído.
     */
    public function meshDelete(Request $r, string $tipo, int $id): JsonResponse
    {
        abort_unless(array_key_exists($tipo, AssetVersion::DONOS), 422, 'Tipo de asset inválido.');
        abort_unless($tipo !== 'scenario', 422, 'Cenário não tem malha.');
        $dono = $this->dono($r, $tipo, $id);
        abort_unless((string) $dono->mesh_url !== '', 404, 'Este item não tem malha.');

        // mesh_status e mesh_msg juntos: excluir a malha limpa também o resíduo de 'erro'/'aviso'
        // da última geração — senão o card voltaria pra "sem malha" ainda mostrando o alerta antigo.
        $dono->update(['mesh_url' => null, 'fbx_url' => null, 'mesh_status' => null, 'mesh_msg' => null]);

        // Sai da galeria junto: card apontando pra malha excluída é exatamente a "mídia morta"
        // que a galeria tem um caminho inteiro (clean-broken) só pra varrer. Não sujar é melhor.
        GaleriaMalha::retira((int) $r->user()->tenant->id, $tipo, (int) $dono->id, $dono->name ?? null);

        return response()->json(['ok' => true, 'asset' => $dono->fresh()]);
    }

    /** GET /api/mesh-health — o gerador de malha está instalado no Estúdio? A aba 3D usa isto
     *  pra só mostrar o botão quando ele existe, em vez de falhar depois de minutos. */
    public function meshHealth(): JsonResponse
    {
        $res = EngineClient::make(30)->get('/v1/mesh/health');

        // `ok` = GERADOR de malha (ComfyUI) · `render` = blender-bridge (turntable/direções).
        // São capacidades independentes: o bridge roda em container e funciona em produção,
        // o gerador exige COMFY_URL. Engine fora do ar = as duas desligadas.
        return response()->json($res->successful() ? $res->json() : ['ok' => false, 'render' => false]);
    }

    /**
     * GET /api/mesh/{tipo}/{id} → serve a MALHA pelo console.
     *
     * POR QUE UM PROXY: o render roda no navegador e o `GLTFLoader` busca o arquivo por fetch —
     * que exige CORS. O storage responde imagem (via <img>, sem CORS) mas barra o fetch, então a
     * malha vinha bloqueada. Servir pelo console resolve sem mexer na infra.
     *
     * NÃO aceita URL do cliente de propósito: recebe tipo+id e busca a URL no BANCO. Um proxy que
     * repassa URL arbitrária é SSRF pronta — daria pra pedir ao servidor que buscasse endereço
     * interno e devolvesse o corpo.
     */
    public function mesh(Request $r, string $tipo, string $id)
    {
        $dono = $this->dono($r, $tipo, (int) $id);
        $url = (string) ($dono->mesh_url ?? '');
        abort_if($url === '', 404, 'Este asset não tem malha.');

        // Sink em arquivo temporário: a malha (até 80MB) não passa inteira pela memória do PHP.
        $tmp = tempnam(sys_get_temp_dir(), 'mesh');
        $resp = Http::timeout(60)->sink($tmp)->get($url);
        if (! $resp->successful()) {
            @unlink($tmp);
            abort(502, 'Não foi possível ler a malha no storage.');
        }

        return response()->file($tmp, [
            'Content-Type' => 'model/gltf-binary',
            'Cache-Control' => 'private, max-age=600',
        ])->deleteFileAfterSend(true);
    }

    /**
     * GET /api/mesh-fbx/{tipo}/{id} → serve o FBX pelo console (mesmo desenho anti-SSRF do
     * mesh(): a URL vem do BANCO, nunca do cliente).
     */
    public function meshFbx(Request $r, string $tipo, string $id)
    {
        $dono = $this->dono($r, $tipo, (int) $id);
        $url = (string) ($dono->fbx_url ?? '');
        abort_if($url === '', 404, 'Este asset não tem FBX.');

        // Mesmo desenho do mesh(): sink em arquivo temporário, memória limitada.
        $tmp = tempnam(sys_get_temp_dir(), 'fbx');
        $resp = Http::timeout(60)->sink($tmp)->get($url);
        if (! $resp->successful()) {
            @unlink($tmp);
            abort(502, 'Não foi possível ler o FBX no storage.');
        }

        return response()->file($tmp, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=600',
        ])->deleteFileAfterSend(true);
    }

    /**
     * POST /api/mesh/{tipo}/{id}/render {receita, params?} → receitas 3D do blender-bridge via
     * engine: turntable (mp4) | direcoes (8 PNGs alpha) | angulo (1 PNG). Fase 2 do
     * docs/ESTUDIO-3D.md: Blender com BOTÃO — sem app aberto, sem agente.
     *
     * A mesh_url sai do BANCO (nunca do request — mesma regra anti-SSRF do proxy mesh()); o
     * engine manda pro bridge no host, persiste no Scality e devolve as URLs, que entram na
     * Galeria como qualquer mídia gerada.
     */
    public function meshRender(Request $r, string $tipo, string $id): JsonResponse
    {
        $d = $r->validate([
            'receita' => 'required|string|in:turntable,direcoes,angulo',
            'params' => 'sometimes|array',
        ]);
        $dono = $this->dono($r, $tipo, (int) $id);
        $url = (string) ($dono->mesh_url ?? '');
        abort_if($url === '', 422, 'Este asset não tem malha — suba um .glb primeiro.');

        // Params: só chaves conhecidas; os VALORES são allowlistados/clampados de novo no bridge
        // (defesa em profundidade — nenhum texto livre vira argumento do Blender).
        $params = array_intersect_key(
            (array) ($d['params'] ?? []),
            array_flip(['size', 'frames', 'count', 'angulo', 'altura', 'enquadramento'])
        );

        $res = Http::baseUrl(rtrim((string) config('services.engine.url'), '/'))
            ->withHeaders(['X-Admin-Token' => (string) config('services.engine.admin_token')])
            ->acceptJson()->timeout(600)
            ->post('/v1/mesh/render', ['meshUrl' => $url, 'receita' => $d['receita'], 'params' => (object) $params]);
        if (! $res->successful()) {
            Log::warning('[mesh] render 3D falhou', ['status' => $res->status(), 'body' => mb_substr((string) $res->body(), 0, 1000)]);

            return response()->json(['ok' => false, 'error' => (string) ($res->json('error') ?: 'O render 3D falhou — o estúdio local está ligado?')], 502);
        }
        $files = (array) $res->json('files', []);
        $this->meshToGallery($r, $dono, (string) $d['receita'], $files);

        return response()->json(['ok' => true, 'files' => $files]);
    }

    /** Renders 3D persistidos → um Draft na Galeria (mesmo padrão do sprite). */
    private function meshToGallery(Request $r, object $dono, string $receita, array $files): void
    {
        $t = $r->user()?->tenant;
        if (! $t || $files === []) {
            return;
        }
        try {
            $media = [];
            foreach ($files as $f) {
                $url = (string) ($f['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $video = ($f['mime'] ?? '') === 'video/mp4' || str_ends_with($url, '.mp4');
                $item = ['id' => (int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(3)), 'kind' => $video ? 'video' : 'image', 'url' => $url];
                if (! $video) {
                    $item = array_merge($item, Draft::imageMeta($url));
                }
                $media[] = $item;
            }
            if ($media === []) {
                return;
            }
            // Cada clique é um artefato novo — o sufixo de hora evita colisão de keyword.
            $d = Draft::create(['tenant_id' => $t->id, 'keyword' => mb_substr('3d '.$receita.' '.($dono->name ?? '').' '.now()->format('His'), 0, 80)]);
            $d->update(['media' => $media]);
        } catch (\Throwable $e) {
            Log::warning('[mesh] falha ao anexar render 3D à galeria', ['error' => $e->getMessage()]);
        }
    }

    /** DELETE /api/asset-versions/{id} → some do histórico (o arquivo no storage não é tocado). */
    public function destroy(Request $r, string $id): JsonResponse
    {
        AssetVersion::where('id', $id)->where('tenant_id', $this->tenantId($r))->firstOrFail()->delete();

        return response()->json(['ok' => true]);
    }
}
