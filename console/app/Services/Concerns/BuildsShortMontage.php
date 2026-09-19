<?php

namespace App\Services\Concerns;

use App\Http\Controllers\Api\StudioController;
use App\Models\GenModel;
use App\Models\Tenant;
use App\Support\GenPayload;
use App\Support\Networks;
use Illuminate\Http\Request;

/**
 * 🎞️ MONTAGEM DE SHORT COMPARTILHADA (/v1/storyvideo) — builder ÚNICO do payload de montagem
 * (narração TTS + legenda + música + Estúdio de Efeitos completo), usado pelas Histórias/
 * Quadrinhos clássicos (StudioController::storyVideo) E pelos modos narrados do Estúdio de
 * Animação (AnimationFlow). Regra da unificação: feature nova de montagem entra AQUI — nunca
 * mais em cópia por aba (docs/PLANO-UNIFICACAO-HISTORIAS-QUADRINHOS.md).
 */
trait BuildsShortMontage
{
    /** Modelo de ÁUDIO (narração/TTS) do catálogo: request `audioModel` (slug, kind=audio) ou o 1º
     *  ativo do plano. null = catálogo sem áudio (legado — o engine usa o default interno). O tier
     *  de qualidade (bitrate) vem de videoQuality() sobre este modelo. */
    private function audioModel(Request $r, ?string $plan): ?GenModel
    {
        $chosen = $r->input('audioModel');
        if (is_string($chosen) && trim($chosen) !== '') {
            $gm = GenModel::resolveSelectable($chosen, 'audio', $plan);
            if ($gm) {
                return $gm;
            }
        }

        return GenModel::active()->kind('audio')->forPlan($plan)->orderBy('sort_order')->first();
    }

    /** Qualidade escolhida de um modelo com tiers (vídeo/imagem/áudio v2): acha em
     *  capabilities.kie.qualities pelo input `$param` do request; ausente/inválido → default_quality
     *  (ou a 1ª). Retorna {key,label,extra,p|p5,p10} ou null se o modelo não tem qualidades
     *  (legado/sem seletor). */
    private function videoQuality(?GenModel $vm, Request $r, string $param = 'quality'): ?array
    {
        // Fonte única: GenPayload (unificação 2026-07-16 — era quadruplicado com Filme/Mídia).
        return GenPayload::quality($vm, (string) $r->input($param));
    }

    /** Params de TTS pro engine a partir do modelo/tier de áudio: ttsModel (modelo de síntese) +
     *  ttsFormat (bitrate do tier — extra.output_format) + ttsStyle (preset de ENTREGA da voz,
     *  request `audioStyle` allowlistado; o mapeamento real vive no serviço de mídia). Vazios =
     *  defaults históricos do serviço. */
    private function ttsParams(Request $r, ?GenModel $am, ?array $aq): array
    {
        $p = [];
        if ($am) {
            $p['ttsModel'] = $am->provider_model_id;
        }
        if (! empty($aq['extra']['output_format'])) {
            $p['ttsFormat'] = (string) $aq['extra']['output_format'];
        }
        $style = (string) $r->input('audioStyle');
        if (in_array($style, ['dramatico', 'calmo', 'energetico', 'locutor'], true)) { // 'neutro' = default (omitido)
            $p['ttsStyle'] = $style;
        }

        return $p;
    }

    /**
     * Monta o payload COMPLETO do /v1/storyvideo a partir dos beats já construídos (cada beat:
     * video_url|image_url + script [+ sfx/vfx]) e do request (efeitos, legenda, sync, música…).
     * Retorna [payload, fxCount (efeitos COBRÁVEIS — o caller reserva no bucket 'effect'),
     * platforms (redes-alvo do item da galeria)].
     */
    private function buildShortMontage(Request $r, Tenant $t, array $beats, string $lang, ?string $aspectDefault = null, string $voiceFallback = ''): array
    {
        // 💳 Estúdio de Efeitos (B0 — tudo cobrado, bucket effect; estorno único no job se falhar):
        // transição 1/corte · filtro 2 · VFX 2/cena · SFX 3/cena · ambience 3. 'natural'/vazio = 0.
        [$transPayload, $fxCount] = StudioController::transitionParams($r, max(0, count($beats) - 1));
        $grade = StudioController::gradeFrom($r);
        $ambience = mb_substr(trim((string) $r->input('ambiencePrompt', '')), 0, 300);
        $fxCount += ($grade !== 'natural' ? 2 : 0) + ($ambience !== '' ? 3 : 0);
        foreach ($beats as $b) {
            $fxCount += (($b['vfx'] ?? '') !== '' ? 2 : 0) + (($b['sfx'] ?? '') !== '' ? 3 : 0);
        }
        foreach ($beats as $i => $b) { // transição de saída viaja NO beat (engine repassa ao serviço)
            $beats[$i]['transition'] = $transPayload['transitionCuts'][$i] ?? '';
        }
        // Redes-alvo do Short — gravadas no item da galeria para o publish seletivo por rede.
        $platforms = Networks::only($r->input('platforms', []));
        // Modelo/qualidade da NARRAÇÃO (tier `audioQuality` do request): mesmo catálogo do preview
        // por cena. Sem custo extra aqui — a narração está inclusa no bucket 'short'.
        $am = $this->audioModel($r, $t->plan);
        $aq = $this->videoQuality($am, $r, 'audioQuality');
        $payload = array_merge([
            'beats' => $beats,
            'voiceId' => (string) ($r->input('voice_id') ?: ($voiceFallback !== '' ? $voiceFallback : $t->voice_id)),
            'lang' => $lang,
            'music' => filter_var($r->input('music', true), FILTER_VALIDATE_BOOLEAN),
            'musicPrompt' => mb_substr((string) $r->input('musicPrompt', ''), 0, 300), // estilo da trilha (vazio = default)
            'sungNarration' => filter_var($r->input('sungNarration', false), FILTER_VALIDATE_BOOLEAN), // a voz CANTA o roteiro (letra = script)
            'aspect' => $r->filled('aspect') ? ($r->input('aspect') === '16:9' ? '16:9' : '9:16') : ($aspectDefault ?: '9:16'),
            // Sincronismo (ajustes finos, clamps server-side): atraso da narração dentro de cada
            // cena (0..2s) + deslocamento extra da legenda relativo ao áudio (-1..+1s).
            'audioDelay' => min(max((float) $r->input('audioDelay', 0), 0.0), 2.0),
            'subtitleOffset' => min(max((float) $r->input('subtitleOffset', 0), -1.0), 1.0),
            // Acabamento (paridade Histórias/Filme): filtro+intensidade, grain, ambience,
            // color-match, fluidez e endcard (URL do NOSSO storage apenas).
            'grade' => $grade,
            'gradeStrength' => StudioController::gradeStrengthFrom($r),
            'grain' => filter_var($r->input('grain', false), FILTER_VALIDATE_BOOLEAN),
            'ambiencePrompt' => $ambience,
            'colorMatch' => filter_var($r->input('colorMatch', false), FILTER_VALIDATE_BOOLEAN),
            'smooth' => filter_var($r->input('smooth', false), FILTER_VALIDATE_BOOLEAN),
            'endcardUrl' => ($ec = (string) $r->input('endcardUrl', '')) !== '' && StudioController::isOwnMediaUrl($ec) ? $ec : '',
            'transitionDefault' => $transPayload['transitionDefault'],
            'transitionDur' => $transPayload['transitionDur'],
        ], $this->ttsParams($r, $am, $aq), StudioController::subtitleStyleFrom($r)); // estilo da legenda (allowlist + clamps)

        return [$payload, $fxCount, $platforms];
    }
}
