<?php

namespace App\Support;

/**
 * Fonte ÚNICA das redes sociais do produto.
 *
 * Antes desta classe a lista das 11 redes estava copiada em 12 lugares (5× no StudioController,
 * 2× no FilmController e no PublicationController, 1× em AnimationController, BuildsShortMontage
 * e ZernioService) — cada nova rede exigia caçar todas, e uma esquecida vira bug silencioso:
 * a rede some do publish sem erro nenhum. Agora quem valida `platforms[]` chama `only()`.
 *
 * As chaves são as do provedor de publicação (a integração é quem define o vocabulário).
 */
final class Networks
{
    /** As redes publicáveis, com o rótulo e o ícone que o cliente vê. Ordem = ordem na UI. */
    public const ALL = [
        ['key' => 'instagram', 'label' => 'Instagram', 'icon' => '📸'],
        ['key' => 'facebook', 'label' => 'Facebook', 'icon' => '👍'],
        ['key' => 'linkedin', 'label' => 'LinkedIn', 'icon' => '💼'],
        ['key' => 'tiktok', 'label' => 'TikTok', 'icon' => '🎵'],
        ['key' => 'youtube', 'label' => 'YouTube', 'icon' => '▶️'],
        ['key' => 'twitter', 'label' => 'X / Twitter', 'icon' => '𝕏'],
        ['key' => 'threads', 'label' => 'Threads', 'icon' => '🧵'],
        ['key' => 'pinterest', 'label' => 'Pinterest', 'icon' => '📌'],
        ['key' => 'reddit', 'label' => 'Reddit', 'icon' => '👽'],
        ['key' => 'bluesky', 'label' => 'Bluesky', 'icon' => '🦋'],
        ['key' => 'googlebusiness', 'label' => 'Google Business', 'icon' => '📍'],
    ];

    /**
     * 'blog' NÃO é rede publicável — o publish pula explicitamente (PublishService). Existe só
     * para FILTRAR publicações antigas que ficaram gravadas com essa chave. Não entre com ela em
     * validação de `platforms[]`.
     */
    public const LEGACY = ['blog'];

    /**
     * Formato do compositor que cada rede recebe por padrão (chaves de FORMATS em
     * web/app/api/compose/templates.tsx: feed 1:1 · retrato 4:5 · story 9:16 · paisagem 16:9).
     *
     * É o DEFAULT de uma peça só, não uma regra rígida: serve pra adaptar uma arte aprovada a
     * várias redes sem gerar de novo. O critério foi ocupar o máximo de tela no feed de cada uma —
     * vertical onde o consumo é no celular, horizontal onde é player/desktop. Ajustável: mudar
     * aqui muda o default de todo mundo.
     */
    public const DEFAULT_FORMAT = [
        'instagram' => 'retrato',       // 4:5 ocupa mais feed que 1:1 e não é cortado
        'facebook' => 'retrato',
        'threads' => 'retrato',
        'pinterest' => 'retrato',       // o nativo é 2:3; 4:5 é o mais próximo sem cortar
        'tiktok' => 'story',            // tela cheia
        'linkedin' => 'paisagem',       // consumo majoritário em desktop
        'youtube' => 'paisagem',        // player padrão (Shorts é caso à parte, escolhido na peça)
        'twitter' => 'paisagem',
        'bluesky' => 'paisagem',
        'reddit' => 'feed',
        'googlebusiness' => 'feed',
    ];

    /** Só as chaves das redes publicáveis. */
    public static function keys(): array
    {
        return array_column(self::ALL, 'key');
    }

    /** Chaves aceitas em FILTRO de publicações — inclui o legado. Não use para publicar. */
    public static function filterable(): array
    {
        return array_merge(self::keys(), self::LEGACY);
    }

    /**
     * Filtra o que veio do cliente, preservando a ordem do pedido e removendo duplicatas.
     * Substitui o `array_intersect($r->input('platforms'), $validNets)` que estava copiado.
     */
    public static function only(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }
        $valid = self::keys();

        return array_values(array_unique(array_filter(
            $input,
            fn ($p) => is_string($p) && in_array($p, $valid, true)
        )));
    }

    /** Formato padrão da rede (fallback 'feed' para chave desconhecida). */
    public static function formatFor(string $network): string
    {
        return self::DEFAULT_FORMAT[$network] ?? 'feed';
    }
}
