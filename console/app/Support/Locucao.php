<?php

namespace App\Support;

/**
 * Quanto tempo uma NARRAÇÃO leva falada — a régua que decide se a fala cabe no clipe.
 *
 * POR QUE EXISTE: a montagem ancora cada locução na SUA cena e, quando a fala é mais longa que o
 * trecho, estica o vídeo (slow-motion até 1,35× e, no que sobrar, congela o último frame). Isso
 * acontece DEPOIS de o clipe estar pago. O canvas já avisava na tela, mas o servidor aceitava o
 * lote calado — quem gera pela API (agente, script, MCP) só descobria no filme montado.
 *
 * MEDIDO no piloto "O Sinal na Colina" (2026-07-29), 4 cenas de locução PT-BR contra os cortes
 * reais do filme entregue: 12,2 caracteres/s e 2,4 palavras/s (desvio de ±9% e ±11% entre cenas).
 * O filme tinha clipes de 6,04s e saiu com 38,2s — 58% de inflação, com 2,5s de imagem congelada.
 *
 * Estimar por caractere E por palavra e ficar com o MAIOR é de propósito: texto de palavra longa
 * ("frequência", "transmissor") engana a conta por palavra, e texto de palavra curta engana a
 * conta por caractere. Errar para mais aqui custa uma escolha de duração; errar para menos custa
 * um clipe congelado que já foi pago.
 */
class Locucao
{
    /** Caracteres por segundo de locução narrativa em PT-BR (medido 2026-07-29). */
    public const CHARS_POR_SEGUNDO = 12.2;

    /** Palavras por segundo de locução narrativa em PT-BR (medido 2026-07-29). */
    public const PALAVRAS_POR_SEGUNDO = 2.4;

    /** Folga aceita antes de considerar que a fala não cabe (segundos). */
    public const FOLGA = 0.5;

    /** Segundos que esta narração leva falada. 0 quando não há texto. */
    public static function segundos(string $texto): float
    {
        $t = trim($texto);
        if ($t === '') {
            return 0.0;
        }
        $palavras = count(preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return max(
            mb_strlen($t) / self::CHARS_POR_SEGUNDO,
            $palavras / self::PALAVRAS_POR_SEGUNDO,
        );
    }

    /** A fala cabe num clipe de `$duracao` segundos? Sem texto = cabe. */
    public static function cabe(string $texto, float $duracao): bool
    {
        $fala = self::segundos($texto);

        return $fala <= 0 || $duracao <= 0 || $fala <= $duracao + self::FOLGA;
    }

    /**
     * Aviso pronto pra tela quando a fala estoura o clipe — '' quando cabe.
     *
     * `$duracoes` = as durações que o modelo oferece (o catálogo do vídeo manda: hoje 5 e 10).
     * Havendo uma que comporte a fala, o aviso aponta ELA; não havendo, diz quantas palavras
     * cabem, porque aí a saída é editar o texto ou quebrar a cena em duas.
     */
    public static function aviso(string $texto, float $duracao, array $duracoes = [5, 10]): string
    {
        if (self::cabe($texto, $duracao)) {
            return '';
        }
        $fala = self::segundos($texto);
        $maiores = array_values(array_filter($duracoes, fn ($d) => (float) $d > $duracao && $fala <= (float) $d + self::FOLGA));
        sort($maiores);
        $seg = (int) round($fala);
        $dur = rtrim(rtrim(number_format($duracao, 1, ',', ''), '0'), ',');

        return $maiores !== []
            ? "Fala de ~{$seg}s num clipe de {$dur}s — use {$maiores[0]}s, ou a cena vai esticar."
            : "Fala de ~{$seg}s num clipe de {$dur}s: a cena estica e o fim congela. Corte para ~"
                .(int) round($duracao * self::PALAVRAS_POR_SEGUNDO).' palavras ou divida em duas cenas.';
    }
}
