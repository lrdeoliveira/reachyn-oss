<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎬 Projeto do Estúdio de Animação (roteiro → desenho pronto). `elements` guarda os
 * elementos extraídos do roteiro (personagens/locações/objetos, cada um com visual_prompt
 * editável + ref_url + status); `storyboard` guarda as cenas (ação, diálogo, Ficha de Cena,
 * keyframe_url, audio_url, video_url, locked, status por etapa).
 */
class AnimationProject extends Model
{
    use HasFactory;

    /** Modos do wizard: animacao (multi-voz + filmassemble) | historia (narração única + i2v +
     *  storyvideo) | quadrinhos (narração única + slides Ken Burns + storyvideo 40 créd). */
    public const MODES = ['animacao', 'historia', 'quadrinhos'];

    /** Sequência — como as cenas se ligam (vale em todos os formatos; Quadrinhos ignora `plano`
     *  por ser slides-only): solto (cenas independentes) | encadeado (keyframes ancorados no
     *  anterior) | plano (encadeado + clipe com frame final → plano-sequência sem corte). */
    public const SEQUENCE_MODES = ['solto', 'encadeado', 'plano'];

    protected $fillable = [
        'tenant_id', 'title', 'mode', 'sequence_mode', 'style', 'lang', 'quality', 'image_model', 'video_model',
        'aspect', 'palette', 'music_prompt', 'voice_id', 'script', 'elements', 'storyboard',
        'status', 'auto', 'error', 'final_draft_id', 'final_url',
    ];

    /** Modos NARRADOS (Histórias/Quadrinhos): voz única, TTS central na montagem /v1/storyvideo. */
    public function isNarrated(): bool
    {
        return in_array($this->mode, ['historia', 'quadrinhos'], true);
    }

    /** 🔗 Encadeia os KEYFRAMES (o de cada cena ancora no da anterior)? Vale nos modos de sequência
     *  `encadeado` e `plano`, em QUALQUER formato (todos geram keyframes — o narrado também flui
     *  melhor encadeado). Impõe geração SERIAL dos keyframes. */
    public function chainsFrames(): bool
    {
        return in_array($this->sequence_mode, ['encadeado', 'plano'], true);
    }

    /** 🔗 Encadeia os CLIPES (o clipe vai do keyframe desta cena ao da próxima — endImageUrl →
     *  movimento contínuo)? Só no `plano`, e só onde há clipe: animacao e historia (Quadrinhos é
     *  slides-only, sem i2v). No Desenho animado troca o lip-sync por i2v+mux; na História narrada
     *  não há custo (já é muda, narração central) — o plano rende ainda mais lá. */
    public function chainsClips(): bool
    {
        return $this->sequence_mode === 'plano' && $this->mode !== 'quadrinhos';
    }

    protected $casts = [
        'elements' => 'array',
        'storyboard' => 'array',
        'auto' => 'boolean',
    ];
}
