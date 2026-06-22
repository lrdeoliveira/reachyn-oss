<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente da API Zernio (publish social white-label).
 * UM token (conta da plataforma) → N profiles (1 por tenant). Porta de lib/zernio.ts.
 */
class ZernioService
{
    private const BASE = 'https://api.zernio.com/v1';

    /** Redes que o cliente pode conectar (chave Zernio → rótulo + ícone). */
    public const NETWORKS = [
        ['key' => 'instagram', 'label' => 'Instagram', 'icon' => '📸'],
        ['key' => 'facebook', 'label' => 'Facebook', 'icon' => '👍'],
        ['key' => 'linkedin', 'label' => 'LinkedIn', 'icon' => '💼'],
        ['key' => 'tiktok', 'label' => 'TikTok', 'icon' => '🎵'],
        ['key' => 'youtube', 'label' => 'YouTube', 'icon' => '▶️'],
        ['key' => 'twitter', 'label' => 'X / Twitter', 'icon' => '𝕏'],
        ['key' => 'threads', 'label' => 'Threads', 'icon' => '🧵'],
        ['key' => 'pinterest', 'label' => 'Pinterest', 'icon' => '📌'],
    ];

    private function key(): string
    {
        // Fonte da verdade: provider_keys (gerida na página Chaves API, cifrada); fallback .env.
        $pk = \App\Models\ProviderKey::where('provider', 'zernio')->first();
        $k = ($pk?->api_key ?: null) ?: (config('services.zernio.key') ?: env('ZERNIO_API_KEY', ''));
        if (! $k) {
            throw new RuntimeException('ZERNIO_API_KEY não configurada.');
        }
        return $k;
    }

    private function client(int $timeout = 15)
    {
        return Http::withToken($this->key())->acceptJson()->timeout($timeout)->baseUrl(self::BASE);
    }

    /** Cria um profile no signup do cliente. Retorna o _id. */
    public function createProfile(string $name, string $description = 'Reachyn'): string
    {
        $r = $this->client()->post('/profiles', compact('name', 'description'))->throw()->json();
        return $r['profile']['_id'];
    }

    public function deleteProfile(string $id): void
    {
        $this->client()->delete("/profiles/{$id}")->throw();
    }

    /** URL de OAuth white-label: cliente autoriza a rede dele e volta pro Reachyn. */
    public function connectUrl(string $platform, string $profileId, string $redirectUrl): string
    {
        $r = $this->client()->get("/connect/{$platform}", compact('profileId', 'redirectUrl'))->throw()->json();
        return $r['authUrl'];
    }

    /** Contas conectadas, filtradas pelo profile do tenant. */
    public function listAccounts(?string $profileId = null): array
    {
        $all = $this->client()->get('/accounts')->throw()->json('accounts') ?? [];
        if (! $profileId) {
            return $all;
        }
        return array_values(array_filter($all, fn ($a) => ($a['profileId']['_id'] ?? null) === $profileId));
    }

    public function disconnectAccount(string $accountId): void
    {
        $this->client()->delete("/accounts/{$accountId}")->throw();
    }

    /**
     * Publica um post numa conta (mídia opcional via URL). publishNow=true publica na hora.
     *
     * @param  array<int,array{type:string,url:string}>  $mediaItems
     * @return array{ok:bool,id?:string,url?:string,detail?:string}
     */
    public function createPost(string $platform, string $accountId, string $content, ?string $title = null, array $mediaItems = [], bool $publishNow = true): array
    {
        $body = [
            'content' => $content,
            'platforms' => [['platform' => $platform, 'accountId' => $accountId]],
            'publishNow' => $publishNow,
        ];
        if ($title) {
            $body['title'] = $title;          // YouTube exige título
        }
        if ($mediaItems) {
            $body['mediaItems'] = $mediaItems;
        }
        try {
            // Publicar (upload de mídia + API da rede) é lento — costuma passar de 15s.
            // Timeout curto fazia o post ser publicado mas a plataforma reportar erro (falso negativo).
            $d = $this->client(120)->post('/posts', $body)->throw()->json();
            $post = $d['post'] ?? $d;
            // Permalink do post, se o Zernio devolver (variantes conhecidas da API). Pode vir
            // null se a rede não retorna URL na hora — o arquivo grava null e fica sem link.
            $url = $post['permalink'] ?? $post['postUrl'] ?? $post['url']
                ?? $post['platforms'][0]['postUrl'] ?? $post['platforms'][0]['url'] ?? null;

            return ['ok' => true, 'id' => $post['_id'] ?? $d['_id'] ?? null, 'url' => $url];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Zernio createPost falhou', ['platform' => $platform, 'account' => $accountId, 'error' => $e->getMessage()]);
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
