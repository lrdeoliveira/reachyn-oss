<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\Draft;

/**
 * Publica uma aprovação nas contas conectadas do tenant (via Zernio).
 * Substitui o passo de publish do flow Windmill — agora nativo no console.
 */
class PublishService
{
    public function __construct(private ZernioService $zernio) {}

    /**
     * @return array<int,array{ok:bool,id?:string,detail?:string}> resultados por conta
     */
    public function publishApproval(Approval $a): array
    {
        $accounts = $this->zernio->listAccounts($a->tenant?->zernio_profile_id);

        $media = [];
        if ($a->image_url) {
            $media[] = ['type' => 'image', 'url' => $a->image_url];
        }
        if ($a->video_url) {
            $media[] = ['type' => 'video', 'url' => $a->video_url];
        }

        $target = $a->meta['platform'] ?? null; // se a peça é de 1 plataforma específica
        $results = [];
        foreach ($accounts as $acc) {
            if ($target && ($acc['platform'] ?? null) !== $target) {
                continue;
            }
            $results[] = $this->zernio->createPost(
                $acc['platform'],
                $acc['_id'],
                (string) $a->preview_text,
                $a->keyword ?: null,
                $media,
            );
        }
        return $results;
    }

    /**
     * Publica cada plataforma de um rascunho (texto + mídia da galeria) nas contas do tenant.
     * Porta do submit do dashboard antigo: vídeo manda 1 (mais recente); senão até 4 imagens.
     *
     * @return array<int,array{platform:string,ok:bool,id?:string,detail?:string}>
     */
    public function publishDraft(Draft $d): array
    {
        $accounts = $this->zernio->listAccounts($d->tenant?->zernio_profile_id);
        $accMap = [];
        foreach ($accounts as $acc) {
            $accMap[$acc['platform'] ?? ''] = $acc['_id'] ?? null;
        }

        $gallery = $d->media ?? [];
        $videos = array_values(array_filter($gallery, fn ($m) => ($m['kind'] ?? '') === 'video'));
        $images = array_values(array_filter($gallery, fn ($m) => ($m['kind'] ?? '') === 'image'));
        if ($videos !== []) {
            $last = end($videos);
            $media = [['type' => 'video', 'url' => $last['url']]];
        } else {
            $media = array_map(fn ($m) => ['type' => 'image', 'url' => $m['url']], array_slice($images, -4));
        }
        // Snapshot da mídia publicada, normalizada {kind,url} — o detalhe mostra o conteúdo por rede.
        $netMedia = array_map(fn ($m) => ['kind' => $m['type'] ?? 'image', 'url' => $m['url']], $media);

        $results = [];
        foreach (($d->texts ?? []) as $platform => $content) {
            if (trim((string) $content) === '') {
                continue;
            }
            // Conteúdo publicado NESTA rede (texto + mídia) — guardado no arquivo p/ exibir por rede.
            $snap = ['text' => (string) $content, 'media' => $netMedia];
            if ($platform === 'blog') {
                $results[] = array_merge(['platform' => 'blog', 'ok' => false, 'detail' => 'WordPress não conectado'], $snap);

                continue;
            }
            $accountId = $accMap[$platform] ?? null;
            if (! $accountId) {
                $results[] = array_merge(['platform' => $platform, 'ok' => false, 'detail' => 'conta não conectada'], $snap);

                continue;
            }
            $title = $platform === 'youtube' ? mb_substr($d->keyword ?: 'Vídeo', 0, 95) : null;
            $res = $this->zernio->createPost($platform, $accountId, (string) $content, $title, $media);
            $results[] = array_merge(['platform' => $platform], $res, $snap);
        }

        return $results;
    }
}
