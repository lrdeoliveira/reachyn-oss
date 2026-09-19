<?php

namespace App\Support;

/**
 * Prompt da IMAGEM-ÂNCORA de cada tipo de asset — personagem, cenário e elemento.
 *
 * Existe porque os três textos viviam soltos dentro do controller de cada aba, e o botão único do
 * Roteiro ("Criar personagens, cenários e elementos") precisa gerar exatamente a MESMA imagem que
 * a aba geraria. Duplicar os textos garantiria dois resultados divergindo no primeiro ajuste — e
 * a âncora de identidade é justamente o que não pode variar.
 *
 * Nenhum deles carrega técnica: o `style` viaja no payload e o engine cola prefixo e sufixo
 * (provider/image/styles.go). O texto daqui descreve só ENQUADRAMENTO e limpeza da âncora.
 */
class AssetPrompt
{
    /**
     * TÉCNICAS válidas — espelha engine/internal/provider/image/styles.go (a fonte da verdade) e
     * lib/imageStyles.ts no front. `logo` fica de fora de propósito: é tratamento de logotipo, com
     * botão e preset próprios, não técnica de asset de filme.
     *
     * A lista vivia recortada dentro do CharacterController com 11 das 20 técnicas: escolher
     * "épico", "livro" ou "colagem" num personagem passava pela allowlist do front, era rejeitado
     * em silêncio aqui e a base saía "realista" — a história inteira em colagem e o elenco em foto.
     */
    public const STYLES = [
        'realista', '3d', 'anime', 'comic', 'aquarela', 'cyberpunk', 'minimalista', 'vintage',
        'produto', 'pintura', 'pixel', 'noir', 'epico', 'macro', 'livro', 'arquitetura',
        'editorial', 'colagem', 'claymation',
    ];

    /** Técnica válida ou o default do engine. Allowlist anti-tamper: slug fora da lista o engine
     *  ignoraria em silêncio, e a imagem sairia num estilo que ninguém pediu. */
    public static function normStyle(?string $style): string
    {
        return in_array((string) $style, self::STYLES, true) ? (string) $style : 'realista';
    }

    /** Técnicas em que a âncora tem de sair como FOTOGRAFIA — precisam do reforço anti-3D. */
    private const FOTOGRAFICAS = ['realista', 'produto', 'editorial', 'macro', 'arquitetura', 'noir', 'vintage'];

    /**
     * Retrato-âncora canônico: UM sujeito, corpo inteiro, pose neutra, fundo neutro, sem texto/
     * props — é a ÂNCORA i2i de TODO o model sheet e das cenas, então precisa ser limpa, bem
     * iluminada e com o corpo inteiro visível (senão o turnaround/poses nascem cortados).
     * ⚠️ NÃO usar "character reference/character/design": esse framing vence o estilo e joga o
     * t2i pra 3D/Pixar mesmo em realista (bicho fofo virava boneco 3D). Falamos de "subject" e
     * reforçamos foto real anti-3D só nos estilos fotográficos (validado por reprodução).
     */
    public static function personagem(string $desc, string $style): string
    {
        $isPhoto = in_array($style, self::FOTOGRAFICAS, true);
        // Estilos foto LIDERAM com "studio reference photograph of a real-life subject" (validado por
        // reprodução: dá foto realista consistente; "reference of a subject" às vezes saía com pelo liso/
        // fosco). Estilizados usam "reference of a subject" (sem viés de foto).
        $lead = $isPhoto
            ? 'Full-body studio reference photograph of a single real-life subject'
            : 'Full-body reference of a single subject';
        $prompt = $lead.', standing in a neutral relaxed front-facing pose, '
            .'centered, feet on the ground, the entire body visible from head to toe: '.$desc
            .'. Plain neutral light-gray studio background, soft even frontal lighting, no cast shadows, sharp focus, '
            .'no text, no watermark, no extra subjects, no props.';
        if ($isPhoto) {
            $prompt .= ' This is a real photograph of a real-life subject with natural, realistic textures, '
                .'visible fur/hair detail and micro-texture — NOT a 3D render, NOT CGI, NOT a cartoon, NOT an '
                .'illustration, NOT a toy figurine or sculpture.';
        }

        return $prompt;
    }

    /**
     * Ambiente SEM personagem: o cenário é cenário. Sem gente no quadro a imagem serve de âncora
     * para qualquer cena depois — com uma figura dentro ela contaminaria o i2i.
     */
    public static function cenario(string $desc): string
    {
        return 'Establishing shot of this location, empty of people: '.$desc
            .'. No characters, no people, no animals in frame. Wide angle, natural depth, '
            .'consistent lighting, photorealistic detail, no text and no watermark.';
    }

    /**
     * O objeto SOZINHO, inteiro e sem gente: é âncora de identidade, e uma mão segurando ou um
     * ambiente em volta contaminariam o i2i de toda cena que usar o elemento depois. A escala
     * relacional já vem escrita na descrição, pelo 📦 Molde: Objeto.
     */
    public static function elemento(string $desc): string
    {
        return rtrim(trim($desc), " \t\n.")
            .'. The object alone, centered, entire object visible in frame, plain neutral light-gray '
            .'studio background, soft even lighting, sharp focus, no people, no hands, no text, '
            .'no watermark, no extra objects.';
    }
}
