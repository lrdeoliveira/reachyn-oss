<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PLANO — como a câmera vê um pedaço da cena. A cena guarda a dramaturgia (o que acontece e por
 * quê); o plano guarda a decupagem (de onde se olha, quão perto, com que movimento).
 */
class Shot extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'scene_id', 'ordem', 'funcao',
        'enquadramento', 'angulo', 'altura', 'movimento', 'acao', 'ancora_url',
        'quadro_url', 'quadro_status', 'duracao',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'duracao' => 'integer',
    ];

    /** @return BelongsTo<Scene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }
}
