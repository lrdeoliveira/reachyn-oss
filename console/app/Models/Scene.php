<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * CENA (F4) — uma linha da escaleta: liga personagem × cenário e carrega a dramaturgia + o
 * cabeçalho de cena (Tema 3 do curso).
 */
class Scene extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'project_id', 'ordem', 'ato', 'scenario_id', 'character_ids', 'element_ids',
        'local', 'int_ext', 'tempo',
        'motivacao', 'objetivo_cena', 'conflito_cena', 'virada', 'tamanho', 'resumo', 'narracao',
    ];

    protected $casts = [
        'character_ids' => 'array',
        'element_ids' => 'array',
        'virada' => 'boolean',
        'ordem' => 'integer',
        'ato' => 'integer',
    ];

    /** Os PLANOS da cena (decupagem), na ordem em que serão filmados. Cena sem plano nenhum
     *  continua valendo: aí ela inteira vira um plano só, que é como o produto funcionava antes. */
    public function shots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('ordem');
    }
}
