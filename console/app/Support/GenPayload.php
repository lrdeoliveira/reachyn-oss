<?php

namespace App\Support;

use App\Models\GenModel;

/**
 * FONTE ÚNICA dos payloads de geração (unificação 2026-07-16). A lógica de resolver o tier de
 * qualidade, montar o spec do motor e as gen_lines vivia QUADRUPLICADA (FilmController,
 * StudioController, AnimationFlow, BuildsShortMontage) — cada bug corrigido num lado precisava
 * ser lembrado nos outros (caso real 2026-07-15: hailuo/02 sem `resolution` → 422 SÓ na Animação,
 * porque só ela não aplicava o extra da qualidade). Aqui é o único lugar que conhece o formato
 * `capabilities.<provider>` do catálogo; os callers delegam.
 */
final class GenPayload
{
    /** Guarda anti-texto de TODO prompt de edição i2i (✏️ ajustar keyframe, 🩹 painel do board,
     *  edições da Mídia/Animação): a instrução do usuário vem em linguagem natural (PT) e o
     *  modelo às vezes ESCREVE a instrução na arte em vez de aplicá-la (caso real 2026-07-16:
     *  "o carro não andar para trás" virou letreiro no keyframe 7). Anexar SEMPRE ao edit. */
    public const EDIT_GUARD = ' The change requested above is an INSTRUCTION to follow, NEVER text to render: do not write, draw or overlay any words, letters, numbers, captions or labels on the image.';

    /** Prompt de EDIÇÃO i2i ("mude só isto, preserve o resto") + o EDIT_GUARD.
     *
     *  Estava escrito 3× byte a byte — FilmController::keyframeEdit, AnimationController::frameEdit
     *  e StudioController::storyEditImage — e o EDIT_GUARD já morava aqui, ou seja, a extração
     *  tinha parado no meio: a constante era compartilhada, a frase em volta dela não. Ajuste no
     *  scaffold (que é prompt-engineering delicado, ver o caso do letreiro em 2026-07-16) só valia
     *  em 1 dos 3 lugares. */
    public static function editPrompt(string $instrucao): string
    {
        return 'EDIT the provided image. Apply ONLY this change: '.$instrucao
            .'. Keep everything else exactly the same — characters, proportions, colors, background and composition.'
            .self::EDIT_GUARD;
    }

    /** Tier de qualidade do modelo: key desejada → default_quality → 1º tier.
     *  Null quando o modelo não declara `qualities` no spec do motor (sem seletor). */
    public static function quality(?GenModel $gm, ?string $want): ?array
    {
        $qs = $gm?->capabilities[$gm->provider]['qualities'] ?? $gm?->capabilities['kie']['qualities'] ?? null;
        if (! is_array($qs) || ! $qs) {
            return null;
        }
        foreach ($qs as $q) {
            if (($q['key'] ?? '') === (string) $want) {
                return $q;
            }
        }
        $def = $gm->capabilities[$gm->provider]['default_quality'] ?? $gm->capabilities['kie']['default_quality'] ?? ($qs[0]['key'] ?? '');
        foreach ($qs as $q) {
            if (($q['key'] ?? '') === $def) {
                return $q;
            }
        }

        return $qs[0];
    }

    /**
     * Spec do MOTOR a partir do catálogo, com o `extra` da QUALIDADE aplicado.
     *
     * A chave lida em `capabilities` acompanha o provider: hoje `magnific` (a API schema-driven
     * que ficou). A chave `kie` do agregador antigo continua sendo LIDA porque linhas velhas do
     * catálogo ainda a carregam — ler não custa nada e evita que um modelo esquecido perca os
     * params obrigatórios em silêncio.
     * `qualities`/`default_quality` são metadados de catálogo (o seletor da UI), não campos do
     * createTask — saem. O `extra` DE DENTRO da qualidade É campo e ENTRA: `$quality` explícita
     * (escolha do usuário) ou, ausente, a default do catálogo — nunca nenhuma (o hailuo/02 exige
     * `resolution`; sem o extra o provedor devolvia 422 ou escolhia a resolução que quisesse).
     */
    public static function motorSpec(GenModel $gm, ?array $quality = null): array
    {
        $quality ??= self::quality($gm, null); // sem escolha explícita → default/1º tier
        $spec = $gm->capabilities[$gm->provider] ?? $gm->capabilities['kie'] ?? [];
        unset($spec['qualities'], $spec['default_quality']);
        if ($quality && ! empty($quality['extra'])) {
            $spec['extra'] = array_merge($spec['extra'] ?? [], $quality['extra']);
        }

        return $spec;
    }

    /** gen_lines.video pro engine: motor schema-driven = spec + extra do tier; demais = só
     *  primary. Sem fallback legado (removido 2026-07-13, conta sem saldo).
     *
     *  ⚠️ O `provider` viaja SEMPRE (2026-07-22). Antes só alguns eram propagados, e
     *  qualquer outro (o `google` do vid-premium) chegava no engine sem provider — que caía no
     *  roteamento nativo legado (morto) e devolvia 403 de saldo, escondendo o problema real. Allowlist de
     *  propagação é armadilha: quem não sabe rotear é o engine, e agora ele DIZ isso (422). */
    public static function videoGenLine(?GenModel $vm, ?array $quality = null): array
    {
        if (! $vm) {
            return ['video' => new \stdClass];
        }
        $line = ['primary' => $vm->provider_model_id, 'provider' => (string) $vm->provider];
        if ($vm->provider === 'magnific') {
            $line['magnific'] = self::motorSpec($vm, $quality) ?: new \stdClass;
        }

        return ['video' => $line];
    }

    /**
     * Payload do /v1/enhance (upscale, remoção de fundo) a partir do modelo de EDIÇÃO.
     *
     * Estava escrito 3× à mão (StudioController, CharacterController, AnimationFlow) e as três
     * cópias mandavam `kie` — campo que o engine não tem mais desde a saída do agregador — e
     * NENHUMA mandava `provider`. Como o enhance roteia por `provider` ("magnific" → API do
     * Magnific), o upscale ia continuar caindo no caminho de sempre mesmo depois da chave chegar:
     * um bug que só apareceria como "melhorou menos do que devia", sem erro nenhum no log.
     *
     * @param  string  $ext  extensão de saída: "png" (remove fundo) | "jpg" (upscale)
     */
    public static function enhancePayload(GenModel $gm, string $imageUrl, string $ext = 'jpg'): array
    {
        $out = ['imageUrl' => $imageUrl, 'model' => $gm->provider_model_id, 'ext' => $ext, 'provider' => $gm->provider];
        if ($gm->provider === 'magnific') {
            $out['magnific'] = self::motorSpec($gm) ?: new \stdClass;
        }

        return $out;
    }

    /** provider/model(/spec do motor) do payload de IMAGEM (/v1/image) a partir do GenModel. */
    public static function imagePayloadBase(?GenModel $gm, ?array $quality = null): array
    {
        if (! $gm) {
            return [];
        }
        $base = ['provider' => $gm->provider, 'model' => $gm->provider_model_id];
        if ($gm->provider === 'magnific') {
            $base['magnific'] = self::motorSpec($gm, $quality) ?: new \stdClass;
        }

        return $base;
    }
}
