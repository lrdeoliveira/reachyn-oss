<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Arquivo de publicações — snapshot PERMANENTE do que foi ao ar.
 *
 * Multi-tenant: trait BelongsToTenant (global scope por tenant_id + auto-fill).
 * Cada cliente só enxerga as próprias publicações.
 */
class Publication extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'keyword',
        'content_text', 'media', 'networks', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'networks' => 'array',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Grava (ou atualiza, em republish) o snapshot permanente de uma publicação.
     *
     * Monta `networks` a partir dos `results` do PublishService/Zernio e deriva o
     * status (publicado = todos ok; parcial = alguns; falhou = nenhum). NÃO lança
     * exceção: o arquivo é secundário — a publicação NUNCA pode falhar por causa
     * dele (try/catch + log).
     *
     * @param  array<int,array{kind?:string,url?:string}>  $media       mídia que foi publicada [{kind,url}]
     * @param  array<int,array{platform?:string,ok?:bool,id?:string,url?:string,detail?:string}>  $results  resultado por rede
     */
    public static function record(
        int $tenantId,
        string $sourceType,
        int|string $sourceId,
        string $keyword,
        ?string $contentText,
        array $media,
        array $results,
    ): ?self {
        try {
            // MERGE por plataforma com o snapshot já gravado: publicar/republicar um SUBCONJUNTO
            // de redes (ex.: LinkedIn agora, Instagram depois) NÃO apaga as redes já publicadas.
            // Bug anterior: o updateOrCreate substituía `networks` inteiro pelo results da última
            // run, então só a última rede sobrevivia. Agora as redes do results atual sobrescrevem
            // a própria entrada; as demais (publicadas antes) são preservadas.
            $existing = static::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('source_type', $sourceType)
                ->where('source_id', (string) $sourceId)
                ->first();

            $byPlatform = [];
            if ($existing && is_array($existing->networks)) {
                foreach ($existing->networks as $n) {
                    $p = (string) ($n['platform'] ?? '');
                    if ($p !== '') {
                        $byPlatform[$p] = $n;
                    }
                }
            }
            foreach ($results as $res) {
                $p = (string) ($res['platform'] ?? '');
                $ok = (bool) ($res['ok'] ?? false);
                $byPlatform[$p] = [
                    'platform' => $p,
                    'ok' => $ok,
                    'post_id' => $res['id'] ?? null,
                    'url' => $res['url'] ?? null,         // permalink do post, se o Zernio devolver
                    // preserva a data anterior se a re-publicação não trouxe sucesso novo
                    'published_at' => $ok ? now()->toIso8601String() : ($byPlatform[$p]['published_at'] ?? null),
                    'detail' => $res['detail'] ?? null,  // motivo da falha, se houver
                    // conteúdo publicado NESTA rede (texto + mídia) — o detalhe mostra por rede.
                    'text' => $res['text'] ?? ($byPlatform[$p]['text'] ?? null),
                    'media' => $res['media'] ?? ($byPlatform[$p]['media'] ?? null),
                ];
            }
            $networks = array_values($byPlatform);

            $okCount = count(array_filter($networks, fn ($n) => ! empty($n['ok'])));
            $total = count($networks);
            $status = $okCount === 0
                ? 'falhou'
                : ($okCount === $total ? 'publicado' : 'parcial');

            // Normaliza a mídia para [{kind,url}] (vem de approval=type/draft=kind).
            $mediaNorm = [];
            foreach ($media as $m) {
                $url = $m['url'] ?? null;
                if (! $url) {
                    continue;
                }
                $mediaNorm[] = [
                    'kind' => (string) ($m['kind'] ?? $m['type'] ?? 'image'),
                    'url' => (string) $url,
                ];
            }

            // updateOrCreate por origem: republish atualiza o mesmo registro (não duplica).
            return static::updateOrCreate(
                ['source_type' => $sourceType, 'source_id' => (string) $sourceId],
                [
                    'tenant_id' => $tenantId,
                    'keyword' => $keyword,
                    'content_text' => $contentText,
                    'media' => $mediaNorm,
                    'networks' => $networks,
                    'status' => $status,
                    'published_at' => now(),
                ],
            );
        } catch (\Throwable $e) {
            // Arquivo é secundário — a publicação não pode quebrar por causa dele.
            Log::warning('Publication::record falhou', [
                'tenant_id' => $tenantId, 'source' => "{$sourceType}:{$sourceId}", 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
