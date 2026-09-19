<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GenModel;
use App\Models\Publication;
use App\Services\PublishService;
use App\Services\UsageService;
use App\Services\ZernioService;
use App\Support\EngineClient;
use App\Support\Networks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Arquivo de publicações — o que o cliente já publicou (snapshot permanente).
 *
 * Multi-tenant: TODA query filtra por tenant_id explícito (defesa em profundidade)
 * E o model Publication aplica o global scope do trait BelongsToTenant. O cliente
 * logado NUNCA enxerga publicação de outro tenant.
 */
class PublicationController extends Controller
{
    // Filtro da LISTA de publicações: aceita o legado 'blog' (publicações antigas gravadas assim),
    // que NÃO é rede publicável. Para publicar, o filtro é Networks::only().

    /** GET /api/publications?q=&network=&status=&page= → lista do tenant (mais recentes primeiro),
     *  com busca por tema, filtro por rede/status e paginação (24 por página). */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $per = 24;
        $page = max(1, (int) $request->query('page', 1));

        $query = Publication::where('tenant_id', $tenantId); // explícito + global scope do trait
        if (($q = trim((string) $request->query('q', ''))) !== '') {
            $query->where('keyword', 'ilike', '%'.$q.'%');
        }
        if (in_array($status = (string) $request->query('status', ''), ['publicado', 'parcial', 'falhou'], true)) {
            $query->where('status', $status);
        }
        if (in_array($network = (string) $request->query('network', ''), Networks::filterable(), true)) {
            // networks é json (não jsonb) — cast pro operador de containment do Postgres.
            $query->whereRaw('networks::jsonb @> ?', [json_encode([['platform' => $network]])]);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('published_at')
            ->offset(($page - 1) * $per)
            ->limit($per)
            ->get()
            ->map(function (Publication $p) {
                $media = $p->media ?? [];

                return [
                    'id' => $p->id,
                    'keyword' => $p->keyword,
                    'status' => $p->status,
                    // Lista devolve só platform+ok por rede — o snapshot completo (texto/mídia
                    // por rede) fica no detalhe; com paginação isso corta o payload da grade.
                    'networks' => array_values(array_map(fn ($n) => [
                        'platform' => $n['platform'] ?? '',
                        'ok' => (bool) ($n['ok'] ?? false),
                    ], (array) ($p->networks ?? []))),
                    'thumb' => $media[0] ?? null,      // 1ª mídia como capa
                    'media_count' => count($media),
                    'published_at' => optional($p->published_at)->toIso8601String(),
                ];
            });

        // no-store: snapshot dinâmico do tenant — o browser NUNCA deve servir versão cacheada
        // (evita mostrar redes/itens desatualizados após republicar).
        return response()->json([
            'ok' => true,
            'items' => $items,
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
            'has_more' => $page * $per < $total,
        ])->header('Cache-Control', 'no-store');
    }

    /** GET /api/publications/{publication} → detalhe completo. */
    public function show(Request $request, Publication $publication): JsonResponse
    {
        // Isolamento por tenant (além do global scope): 403 se for de outro tenant.
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        return response()->json(['ok' => true, 'item' => [
            'id' => $publication->id,
            'keyword' => $publication->keyword,
            'status' => $publication->status,
            'content_text' => $publication->content_text,
            'media' => $publication->media ?? [],
            'networks' => $publication->networks ?? [],
            'published_at' => optional($publication->published_at)->toIso8601String(),
        ]])->header('Cache-Control', 'no-store'); // dado dinâmico: sem cache no browser
    }

    /**
     * DELETE /api/publications/{publication} → remove SÓ o registro local no Reachyn.
     * NÃO despublica das redes sociais: os posts continuam no ar (o snapshot some daqui).
     * Tenant-scoped (403 se for de outro tenant, além do global scope BelongsToTenant).
     */
    public function destroy(Request $request, Publication $publication): JsonResponse
    {
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        $publication->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/publications/bulk-delete { ids[] } → remove VÁRIOS registros locais de uma vez
     *  (mesmo contrato do destroy: não despublica das redes). Só apaga o que for do tenant. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = array_slice(array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))), 0, 100);
        if ($ids === []) {
            return response()->json(['ok' => false, 'error' => 'nenhuma publicação selecionada'], 422);
        }
        $deleted = Publication::where('tenant_id', $request->user()->tenant_id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    /**
     * POST /api/publications/{publication}/retry → 🔁 reposta SÓ as redes que FALHARAM,
     * usando o snapshot salvo por rede (texto + mídia daquela rede). Não repete as que já
     * publicaram OK. O resultado volta pro mesmo registro via Publication::record (merge
     * por plataforma), atualizando o status (falhou→parcial→publicado).
     */
    public function retry(Request $request, Publication $publication, ZernioService $zernio): JsonResponse
    {
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        $failed = array_values(array_filter((array) ($publication->networks ?? []), fn ($n) => empty($n['ok'])));
        if ($failed === []) {
            return response()->json(['ok' => false, 'error' => 'todas as redes desta publicação já estão no ar'], 422);
        }

        // reportUnconnected=true: a rede JÁ estava falha, então reportar "conta não conectada"
        // é o estado real (não rebaixa nada). Anti-regressão só importa no republish.
        $results = $this->repostNetworks($publication, $failed, $zernio, true);
        if ($results === []) {
            return response()->json(['ok' => false, 'error' => 'as redes que falharam não têm texto salvo para repostar'], 422);
        }

        return $this->recordAndRespond($publication, $results);
    }

    /**
     * POST /api/publications/{publication}/republish → ♻️ reposta TODAS as redes de novo
     * (mesmo as que já estavam no ar), com o snapshot salvo por rede. Cria posts NOVOS nas
     * redes — não remove os antigos. Útil pra republicar um conteúdo ou depois de reconectar
     * contas. Redes sem conta conectada são PULADAS (não rebaixam uma rede que já estava OK).
     */
    public function republish(Request $request, Publication $publication, ZernioService $zernio): JsonResponse
    {
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        $all = array_values((array) ($publication->networks ?? []));
        if ($all === []) {
            return response()->json(['ok' => false, 'error' => 'esta publicação não tem redes para republicar'], 422);
        }

        // reportUnconnected=false: pula redes sem conta conectada — não rebaixa (ok=false/url=null)
        // uma rede que já estava publicada. Só sobrescreve o que realmente for repostado.
        $results = $this->repostNetworks($publication, $all, $zernio, false);
        if ($results === []) {
            return response()->json(['ok' => false, 'error' => 'nenhuma rede conectada com texto salvo para republicar'], 422);
        }

        return $this->recordAndRespond($publication, $results);
    }

    /**
     * POST /api/publications/{publication}/repost { platforms[] } → publica esta publicação nas
     * REDES ESCOLHIDAS, incluindo redes NOVAS que não estavam no post original. Redes já usadas
     * reusam o texto+mídia daquela rede (snapshot); redes novas caem no conteúdo geral do registro.
     * Redes sem conta conectada são reportadas (não publicam). Cria posts NOVOS.
     */
    public function repost(Request $request, Publication $publication, ZernioService $zernio): JsonResponse
    {
        abort_unless($publication->tenant_id === $request->user()->tenant_id, 403, 'Publicação de outro tenant.');

        $platforms = Networks::only(array_map('strval', (array) $request->input('platforms', [])));
        if ($platforms === []) {
            return response()->json(['ok' => false, 'error' => 'Selecione ao menos uma rede.'], 422);
        }

        // snapshot por rede (texto+mídia daquela rede), indexado por plataforma
        $snap = [];
        foreach ((array) ($publication->networks ?? []) as $n) {
            if (! empty($n['platform'])) {
                $snap[(string) $n['platform']] = $n;
            }
        }

        $t = $publication->tenant;
        $lang = $t?->content_lang ?? 'pt-BR';
        $persona = trim((string) ($t?->brand_voice ?? ''));
        // Base pra GERAR a legenda das redes novas: o texto geral do registro OU o 1º texto por rede salvo.
        $base = trim((string) ($publication->content_text ?? ''));
        if ($base === '') {
            foreach ($snap as $n) {
                if (! empty($n['text'])) {
                    $base = trim((string) $n['text']);
                    break;
                }
            }
        }

        $networks = [];
        foreach ($platforms as $p) {
            // Rede já usada COM texto → reusa o texto+mídia daquela rede.
            if (isset($snap[$p]) && trim((string) ($snap[$p]['text'] ?? '')) !== '') {
                $networks[] = $snap[$p];

                continue;
            }
            // Rede NOVA (ou sem texto) → GERA a legenda dela (per-network, via engine); fallback = texto base.
            $entry = $snap[$p] ?? ['platform' => $p];
            $gen = $this->generatePostText($publication, $p, $base, $lang, $persona);
            $entry['text'] = $gen !== '' ? $gen : $base;
            if (empty($entry['media'])) {
                $entry['media'] = $publication->media ?? [];
            }
            $networks[] = $entry;
        }

        $results = $this->repostNetworks($publication, $networks, $zernio, true);
        if ($results === []) {
            return response()->json(['ok' => false, 'error' => 'sem texto para publicar nas redes escolhidas'], 422);
        }

        return $this->recordAndRespond($publication, $results);
    }

    /**
     * Gera a legenda de UMA rede a partir do tema + do texto existente da publicação (via engine /v1/text,
     * respeitando a Voz da Marca e o idioma da conta). Best-effort: falha → devolve '' (cai no texto base).
     */
    private function generatePostText(Publication $publication, string $platform, string $base, string $lang, string $persona): string
    {
        $keyword = trim((string) $publication->keyword);
        if ($keyword === '' && $base === '') {
            return '';
        }
        try {
            // Legenda de rede NOVA no repost: cobrada como texto (custo default; sem seletor aqui).
            // Sem saldo → devolve '' (o repost cai no texto base, sem gerar).
            $t = $publication->tenant;
            $usage = app(UsageService::class);
            $tmCost = GenModel::resolveSelectable('txt-equilibrado', 'text', $t?->plan)?->cost_credits;
            if (! $t || ! $usage->tryConsume($t, 'text', 1, $tmCost)) {
                return '';
            }
            $res = EngineClient::make(120)
                ->post('/v1/text', [
                    'keyword' => $keyword !== '' ? $keyword : mb_substr($base, 0, 80),
                    'brief' => $base,
                    'facts' => $base,
                    'platform' => $platform,
                    'lang' => $lang,
                    'persona' => $persona,
                ]);

            return $res->successful() ? trim((string) $res->json('post')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Reposta uma lista de redes (snapshot texto+mídia por rede) nas contas do perfil padrão.
     * Compartilhado por retry (só falhas) e republish (todas). Blog é ignorado (rede descontinuada).
     *
     * @param  array<int,array<string,mixed>>  $networks  entradas de rede do snapshot a repostar
     * @param  bool  $reportUnconnected  true = inclui um resultado ok=false p/ rede sem conta;
     *                                   false = pula (não rebaixa uma rede já publicada)
     * @return array<int,array{platform:string,ok?:bool,text:string,media:array}>
     */
    private function repostNetworks(Publication $publication, array $networks, ZernioService $zernio, bool $reportUnconnected): array
    {
        $t = $publication->tenant;

        // Contas conectadas do perfil padrão do tenant (o snapshot guarda o nome do perfil,
        // não o id — o repost usa o perfil padrão, que é o caso real de conta reconectada).
        $default = $t?->profiles()->where('is_default', true)->first();
        $accounts = $zernio->listAccounts($default->zernio_profile_id ?? $t?->zernio_profile_id);
        $accMap = [];
        foreach ($accounts as $acc) {
            $accMap[$acc['platform'] ?? ''] = $acc['_id'] ?? null;
        }

        $results = [];
        foreach ($networks as $n) {
            $platform = (string) ($n['platform'] ?? '');
            if ($platform === '' || $platform === 'blog') {
                continue; // sem plataforma, ou blog (rede descontinuada)
            }
            $text = trim((string) ($n['text'] ?? $publication->content_text ?? ''));
            if ($text === '') {
                continue;
            }
            // Mídia do snapshot DESTA rede; publicações antigas (sem snapshot por rede) caem
            // na mídia geral do registro. Anti-SSRF: revalida CADA URL contra o nosso storage
            // (isOwnMediaUrl) — snapshot antigo/adulterado com URL externa NÃO é repostado.
            $mediaSnap = array_values(array_filter(
                is_array($n['media'] ?? null) && ($n['media'] ?? []) !== [] ? $n['media'] : ($publication->media ?? []),
                fn ($m) => ! empty($m['url']) && StudioController::isOwnMediaUrl((string) $m['url']),
            ));
            // Imagens webp → JPEG (Instagram/TikTok só aceitam JPG/PNG; detecção por magic bytes).
            $media = PublishService::normalizeMediaForPublish(
                array_map(fn ($m) => ['type' => (string) ($m['kind'] ?? 'image'), 'url' => (string) $m['url']], $mediaSnap)
            );
            $snap = ['text' => $text, 'media' => $mediaSnap];

            $accountId = $accMap[$platform] ?? null;
            if (! $accountId) {
                if ($reportUnconnected) {
                    $results[] = array_merge(['platform' => $platform, 'ok' => false, 'detail' => 'conta não conectada'], $snap);
                }

                continue; // republish: pula sem tocar no registro (não rebaixa)
            }
            $title = $platform === 'youtube' ? mb_substr($publication->keyword ?: 'Vídeo', 0, 95) : null;
            // Reddit: reusa o alvo (comunidade r/ ou perfil u/) salvo no snapshot da rede, com o
            // MESMO contrato do PublishService (título + forceSelf quando há corpo e mídia).
            $platformData = [];
            if ($platform === 'reddit' && ! empty($n['subreddit'])) {
                $formato = PublishService::redditFormato($n['reddit_formato'] ?? null);
                $platformData = PublishService::redditPlatformData((string) $n['subreddit'], $text, $media !== [], $formato, (string) ($n['reddit_title'] ?? ''));
                $snap['subreddit'] = (string) $n['subreddit'];
                $snap['reddit_formato'] = $formato;
                $snap['reddit_title'] = $platformData['title'];
            } elseif ($platform === 'reddit') {
                // Snapshot antigo (anterior a 2026-08-04) não guardou comunidade porque ela era
                // opcional. Repostar assim mandaria de novo pro "padrão da conta" — o mesmo alvo
                // errado, agora repetido. Falha explícita: o cliente republica pelo Estúdio,
                // onde escolhe a comunidade.
                $results[] = array_merge(['platform' => $platform, 'ok' => false,
                    'detail' => 'sem comunidade (subreddit) no registro — republique pelo Estúdio escolhendo a comunidade'], $snap);

                continue;
            }
            // TikTok FOTO-POST (sem vídeo na mídia): o texto vira o TÍTULO do slideshow (teto 90).
            $isPhotoPost = ! array_filter($media, fn ($m) => ($m['type'] ?? '') === 'video');
            $postText = match (true) {
                $platform === 'tiktok' && $isPhotoPost => PublishService::tiktokPhotoTitle($text),
                $platform === 'reddit' => PublishService::redditContent($text, $media, (bool) ($platformData['forceSelf'] ?? false)),
                default => $text,
            };
            $res = $zernio->createPost($platform, $accountId, $postText, $title, $media, true, $platformData);
            // Reddit no formato imagem: o corpo entra como 1º comentário (ver PublishService —
            // post de imagem não tem corpo). Best-effort: falha aqui não invalida a republicação.
            if ($platform === 'reddit' && ($res['ok'] ?? false) && ! empty($res['id'])
                && empty($platformData['forceSelf']) && $media !== []
                && ($corpo = PublishService::redditBody($text)) !== '') {
                $c = $zernio->commentOnPost((string) $res['id'], $accountId, $corpo);
                $res = array_merge($res, $c['ok'] ? ['comment_id' => $c['commentId'] ?? null] : ['comment_error' => $c['detail'] ?? 'falhou']);
            }
            $results[] = array_merge(['platform' => $platform], $res, $snap);
        }

        return $results;
    }

    /** Grava o resultado do repost no mesmo registro (merge por rede) e devolve o status atualizado. */
    private function recordAndRespond(Publication $publication, array $results): JsonResponse
    {
        $updated = Publication::record(
            $publication->tenant_id,
            $publication->source_type,
            (string) $publication->source_id,
            (string) $publication->keyword,
            $publication->content_text,
            $publication->media ?? [],
            $results,
        );

        $okNow = count(array_filter($results, fn ($r) => ! empty($r['ok'])));

        return response()->json([
            'ok' => true,
            'reposted_ok' => $okNow,
            'reposted_fail' => count($results) - $okNow,
            'status' => $updated?->status ?? $publication->fresh()?->status,
            'networks' => $updated?->networks ?? [],
        ]);
    }
}
