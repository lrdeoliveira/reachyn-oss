<?php

namespace App\Services;

use App\Models\ProviderKey;
use App\Support\Networks;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente da API Zernio (publish social white-label).
 * UM token (conta RedFoxCode) → N profiles (1 por tenant). Porta de lib/zernio.ts.
 */
class ZernioService
{
    private const BASE = 'https://api.zernio.com/v1';

    /** Redes que o cliente pode conectar (chave Zernio → rótulo + ícone).
     *  Alias mantido por retrocompatibilidade — a lista vive em App\Support\Networks. */
    public const NETWORKS = Networks::ALL;

    private function key(): string
    {
        // Fonte da verdade: provider_keys (gerida na página Chaves API, cifrada); fallback .env.
        $pk = ProviderKey::where('provider', 'zernio')->first();
        $k = ($pk?->api_key ?: null) ?: config('services.zernio.key');
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
    public function createPost(string $platform, string $accountId, string $content, ?string $title = null, array $mediaItems = [], bool $publishNow = true, array $platformData = []): array
    {
        // Dados específicos da rede (ex.: Reddit exige subreddit) — vão ANINHADOS no item de
        // platform (platformSpecificData), conforme o contrato do Zernio.
        $plat = ['platform' => $platform, 'accountId' => $accountId];
        if ($platformData !== []) {
            $plat['platformSpecificData'] = $platformData;
        }
        $body = [
            'content' => $content,
            'platforms' => [$plat],
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
            $id = $post['_id'] ?? $d['_id'] ?? null;
            $url = self::permalink($post);
            // 🔗 A publicação é ASSÍNCRONA na rede. O create devolve 200 assim que o post entra na
            // fila do Zernio; o link só existe depois que a rede aceitou o upload — no vídeo do
            // YouTube isso levou 9s (caso real 2026-08-04, post 6a720e04). Sem o link a peça
            // publicada aparece sem lugar nenhum pra clicar, e "publiquei e sumiu" é exatamente
            // como o cliente descreve isso. Então espera o link nascer, com teto.
            if ($url === null && $id) {
                $url = $this->aguardaPermalink((string) $id);
            }

            return ['ok' => true, 'id' => $id, 'url' => $url];
        } catch (\Throwable $e) {
            Log::warning('Zernio createPost falhou', ['platform' => $platform, 'account' => $accountId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Link público do post dentro da rede, a partir do objeto `post` do Zernio.
     *
     * ⚠️ O campo é `platformPostUrl`, DENTRO de cada item de `platforms[]` — não `permalink`,
     * `postUrl` nem `url`, que era o que este código procurava e nunca achava (o link vinha null
     * em TODA publicação desde sempre, sem erro nenhum no log). Confirmado na resposta real da
     * API e em docs.zernio.com/posts/create-post. Os nomes antigos ficam como último recurso.
     */
    private static function permalink(array $post): ?string
    {
        foreach ($post['platforms'] ?? [] as $p) {
            $u = $p['platformPostUrl'] ?? $p['postUrl'] ?? $p['url'] ?? null;
            if (is_string($u) && $u !== '') {
                return self::absolutiza($u, (string) ($p['platform'] ?? ''));
            }
        }
        $u = $post['permalink'] ?? $post['postUrl'] ?? $post['url'] ?? null;

        return is_string($u) && $u !== '' ? $u : null;
    }

    /** Host público de cada rede que devolve permalink RELATIVO. */
    private const HOST_DA_REDE = ['reddit' => 'https://www.reddit.com'];

    /**
     * Garante link absoluto.
     *
     * O Reddit devolve o permalink como CAMINHO (`/r/x/comments/…`), não como URL. Gravado assim,
     * o `<a href>` do arquivo aponta pro nosso próprio domínio e o cliente clica num 404 — que é
     * a mesma experiência de não ter link nenhum, só que pior porque parece funcionar.
     * Rede relativa desconhecida: devolve null em vez de um link que leva pro lugar errado.
     */
    private static function absolutiza(string $url, string $platform): ?string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        $host = self::HOST_DA_REDE[$platform] ?? null;

        return $host && str_starts_with($url, '/') ? $host.$url : null;
    }

    /**
     * Link público de um post JÁ PUBLICADO, por ID. null = o Zernio não tem link pra ele (post
     * sumido, ainda subindo, ou rede que não devolve permalink).
     */
    public function postUrl(string $postId): ?string
    {
        try {
            return self::permalink($this->client(30)->get("/posts/{$postId}")->throw()->json('post') ?? []);
        } catch (\Throwable $e) {
            Log::warning('Zernio postUrl falhou', ['post' => $postId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Relê o post até o link aparecer. Devolve null se estourar a espera.
     *
     * Teto curto DE PROPÓSITO: isto roda dentro do job de publicação, então esperar não trava
     * ninguém — mas o post JÁ ESTÁ publicado quando chegamos aqui, e ficar pendurado num upload
     * lento pra buscar um link é pior que entregar a publicação sem ele. Sem link, a peça continua
     * publicada e o arquivo só fica sem o atalho.
     */
    private function aguardaPermalink(string $postId): ?string
    {
        $tentativas = (int) config('services.zernio.permalink_tentativas', 5);
        $espera = (int) config('services.zernio.permalink_espera', 4);
        for ($i = 0; $i < $tentativas; $i++) {
            if ($espera > 0) {
                sleep($espera);
            }
            try {
                $p = $this->client(30)->get("/posts/{$postId}")->throw()->json('post') ?? [];
            } catch (\Throwable $e) {
                continue; // blip na leitura não pode derrubar uma publicação que já deu certo
            }
            if ($u = self::permalink($p)) {
                return $u;
            }
            // A rede recusou depois do aceite: não vai nascer link nenhum, parar de esperar.
            if (in_array($p['status'] ?? '', ['failed', 'error'], true)) {
                return null;
            }
        }
        Log::info('Zernio: post publicado sem link no prazo', ['post' => $postId]);

        return null;
    }

    /**
     * Comenta num post JÁ PUBLICADO (POST /v1/inbox/comments/{postId}), como a própria conta.
     *
     * 👽 É o que destrava o Reddit: lá um post é de UM tipo só — post de imagem mostra a foto no
     * feed e NÃO tem corpo (o `content` vira só o título, verificado com 4 testes reais em
     * 2026-08-04). O texto completo entra logo abaixo, como PRIMEIRO COMENTÁRIO do autor — que é
     * o costume da própria rede. Sem `commentId`, o reply é de primeiro nível no post.
     *
     * ⚠️ NÃO confundir com o `firstComment` do create-post: aquele campo existe só para Facebook e
     * Instagram. Para o Reddit é este endpoint, num segundo passo depois de publicar.
     *
     * Best-effort DE PROPÓSITO: o post já está no ar quando isto roda. Falhar aqui não pode
     * derrubar a publicação (nem disparar estorno) — vira aviso no log e no resultado da rede.
     *
     * @return array{ok:bool,commentId?:string,detail?:string}
     */
    public function commentOnPost(string $postId, string $accountId, string $message): array
    {
        try {
            $d = $this->client(60)->post("/inbox/comments/{$postId}", [
                'accountId' => $accountId,
                'message' => $message,
            ])->throw()->json();

            return ['ok' => (bool) ($d['success'] ?? true), 'commentId' => $d['data']['commentId'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('Zernio commentOnPost falhou', [
                'post' => $postId, 'account' => $accountId, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
