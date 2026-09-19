<?php

namespace App\Support;

use App\Models\Draft;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A malha 3D na GALERIA — vitrine da malha que vive no asset.
 *
 * 🐛 POR QUE EXISTE: a malha era gravada só em `characters.mesh_url` / `elements.mesh_url`, e a
 * galeria (GET /api/media/list) lê EXCLUSIVAMENTE o array `media` dos rascunhos. Resultado: o
 * usuário gerava a malha, ela aparecia na aba 3D e sumia do acervo — nada em "Tudo", nada em
 * filtro nenhum, nenhum download. O sprite já fazia certo (SpriteController::attachToGallery) e
 * serviu de molde aqui, inclusive na idempotência por keyword.
 *
 * `kind = 'mesh3d'`: kind PRÓPRIO, não 'image' nem 'video'. A galeria classificava tudo que não
 * fosse imagem/áudio como vídeo (`isVideo`), então um .glb cairia dentro de um <video> e daria
 * card quebrado — pior que ausente, porque parece mídia morta em vez de formato não suportado.
 *
 * Um rascunho POR ASSET (keyword "malha <nome>"): regerar TROCA o item em vez de empilhar versões
 * que ninguém pediu — quem guarda histórico de malha é o GuardaVersoes do asset. E o `mesh_url` do
 * dono continua sendo a fonte da verdade pro viewer 3D: aqui é vitrine, não dono.
 *
 * Os três caminhos que mexem em malha usam este mesmo lugar (gerar pelo Estúdio, subir um .glb à
 * mão, excluir) — separá-los era garantir que divergissem, e o item órfão apontando pra uma malha
 * já excluída é justamente o "card quebrado" que a galeria tem de nunca mostrar.
 */
class GaleriaMalha
{
    /** A keyword é derivada, nunca inventada duas vezes: é ela que dá a idempotência. */
    public static function keyword(string $tipo, int $donoId, ?string $nome): string
    {
        $nome = trim((string) $nome);

        return mb_substr(trim('malha '.($nome !== '' ? $nome : $tipo.' '.$donoId)), 0, 80);
    }

    /**
     * Publica (ou republica) a malha do asset na galeria do tenant.
     *
     * Best-effort de propósito, como o `imageMeta`: a malha JÁ está gravada no asset e vale. Uma
     * falha aqui não pode derrubar uma geração que custou minutos de GPU e já entregou o que
     * importa — a vitrine é consequência do trabalho, não condição dele.
     */
    public static function publica(int $tenantId, string $tipo, int $donoId, ?string $nome, string $url): void
    {
        try {
            $d = Draft::firstOrCreate(
                ['tenant_id' => $tenantId, 'keyword' => self::keyword($tipo, $donoId, $nome)],
                ['media' => []],
            );

            $media = self::semMalha($d);
            // Sem `probeMeta`: ele mede por ffprobe, que não lê .glb — a chamada só gastaria a
            // viagem pra devolver vazio. O peso é o único dado de ficha que faz sentido numa
            // malha (dimensão e duração não existem aqui) e vem do HEAD do próprio storage.
            $media[] = array_filter(
                ['id' => Draft::mediaId(), 'kind' => 'mesh3d', 'url' => $url, 'style' => $tipo, 'bytes' => self::peso($url)],
                fn ($v) => $v !== null,
            );
            $d->update(['media' => $media]);
        } catch (\Throwable $e) {
            Log::warning('[mesh] falha ao publicar na galeria', ['tipo' => $tipo, 'id' => $donoId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Tira a malha da galeria quando ela é excluída do asset.
     *
     * Sem isto o card sobreviveria apontando pra uma malha que não existe mais — e a galeria já
     * tem um caminho inteiro (`clean-broken`) só pra limpar mídia morta. Não criar a sujeira é
     * melhor que saber limpá-la.
     */
    public static function retira(int $tenantId, string $tipo, int $donoId, ?string $nome): void
    {
        try {
            $d = Draft::where('tenant_id', $tenantId)
                ->where('keyword', self::keyword($tipo, $donoId, $nome))
                ->first();
            if (! $d) {
                return;
            }
            $media = self::semMalha($d);
            // O rascunho só existe pra hospedar a malha. Ficando VAZIO depois da retirada, ele
            // vira lixo — um "malha Fulano" sem nada dentro, acumulando um por exclusão. Some
            // junto. A guarda do vazio é o que garante não levar mídia que alguém somou aqui.
            if ($media === []) {
                $d->delete();

                return;
            }
            $d->update(['media' => $media]);
        } catch (\Throwable $e) {
            Log::warning('[mesh] falha ao retirar da galeria', ['tipo' => $tipo, 'id' => $donoId, 'error' => $e->getMessage()]);
        }
    }

    /** O `media` do rascunho sem nenhum item de malha — a base de publicar e de retirar. */
    private static function semMalha(Draft $d): array
    {
        return array_values(array_filter($d->media ?? [], fn ($m) => ($m['kind'] ?? '') !== 'mesh3d'));
    }

    /** Peso pelo HEAD do storage. Sem resposta, a malha entra sem ficha — nunca o contrário. */
    private static function peso(string $url): ?int
    {
        try {
            $len = Http::timeout(10)->head($url)->header('Content-Length');

            return $len !== '' && $len !== null ? (int) $len : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
