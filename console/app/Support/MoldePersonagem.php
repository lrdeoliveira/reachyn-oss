<?php

namespace App\Support;

use App\Models\Prompt;

/**
 * MOLDE do personagem — qual persona da aba Prompts descreve este sujeito.
 *
 * A extração lê a foto e devolve a identidade (lock + bíblia); o molde é o segundo passo, que
 * transforma isso na DESCRIÇÃO densa usada pra regerar o personagem sem a foto. Cada categoria
 * erra de um jeito diferente — criança sai como adulto em miniatura, objeto sai em escala de
 * vitrine — então o texto que guia a descrição é por categoria, não um só pra tudo.
 *
 * Sem molde reconhecido (um animal, uma criatura), a extração fica só com a identidade: melhor
 * nenhuma descrição do que uma descrição empurrada pro molde errado.
 */
final class MoldePersonagem
{
    /**
     * Trecho do título → padrões que o indicam. A ORDEM importa: criança antes de adulto (uma
     * "young girl" também casa com "girl"), e feminino antes de masculino porque "woman" contém
     * "man" — o `\b` resolve, mas a ordem deixa a intenção explícita pra quem editar depois.
     *
     * @var array<string, array<int, string>>
     */
    private const REGRAS = [
        'Molde: Menino' => ['boy', 'menino', 'garoto', 'male child', 'young male', 'crianca do sexo masculino', 'criança do sexo masculino'],
        'Molde: Menina' => ['girl', 'menina', 'garota', 'female child', 'young female', 'crianca do sexo feminino', 'criança do sexo feminino'],
        'Molde: Mulher' => ['woman', 'mulher', 'female human', 'human female', 'adult female', 'lady'],
        'Molde: Homem' => ['man', 'homem', 'male human', 'human male', 'adult male', 'guy'],
        'Molde: Objeto' => [
            'car', 'vehicle', 'truck', 'motorcycle', 'bicycle', 'chair', 'table', 'sofa', 'couch',
            'furniture', 'prop', 'lamp', 'desk', 'carro', 'veiculo', 'veículo', 'caminhao',
            'caminhão', 'moto', 'bicicleta', 'cadeira', 'mesa', 'sofa', 'sofá', 'poltrona',
            'movel', 'móvel', 'objeto', 'luminaria', 'luminária',
        ],
    ];

    /**
     * Categoria do sujeito a partir do que a vision devolveu. Null = nenhuma casa (animal,
     * criatura, ambíguo) → a extração não aplica molde nenhum.
     *
     * @param  array<string, mixed>|null  $bible
     */
    public static function detectar(?array $bible, ?string $lock = null, ?string $descricao = null): ?string
    {
        // `subject` é o campo que a vision usa pra dizer O QUE é ("Human male, mid-aged"); o lock e
        // a descrição entram como reforço, nessa ordem de confiança.
        $texto = self::normalizar(implode(' ', array_filter([
            is_string($bible['subject'] ?? null) ? $bible['subject'] : '',
            is_string($bible['traits'] ?? null) ? $bible['traits'] : '',
            (string) $lock,
            (string) $descricao,
        ])));
        if ($texto === '') {
            return null;
        }

        foreach (self::REGRAS as $molde => $padroes) {
            foreach ($padroes as $p) {
                if (preg_match('/\b'.preg_quote(self::normalizar($p), '/').'\b/u', $texto)) {
                    return $molde;
                }
            }
        }

        return null;
    }

    /** Texto da persona do molde, dentro do tenant. Null quando a linha não está na aba Prompts. */
    public static function conteudo(int $tenantId, string $trechoDoTitulo): ?string
    {
        $p = Prompt::where('tenant_id', $tenantId)->where('title', 'like', '%'.$trechoDoTitulo.'%')->first();
        $txt = trim((string) ($p->content ?? ''));

        return $txt !== '' ? $txt : null;
    }

    /** minúsculas + sem acento: a vision responde ora em PT ora em EN, e "veículo" tem de casar. */
    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
