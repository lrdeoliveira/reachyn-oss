<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PROJETO (F4) — o agrupador de uma escaleta (história / mini-GDD). Contém as cenas ordenadas.
 */
class Project extends Model
{
    use BelongsToTenant;

    // image_model/image_style: o PADRÃO VISUAL da história, escolhido uma vez no Roteiro e
    // herdado por todo personagem, cenário e elemento criados a partir dela (2026-08-30).
    protected $fillable = ['tenant_id', 'name', 'argumento', 'image_model', 'image_style'];

    /** @return HasMany<Scene, $this> */
    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class)->orderBy('ordem')->orderBy('id');
    }
}
