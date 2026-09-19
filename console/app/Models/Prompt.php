<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Prompt extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'title', 'kind', 'content'];

    /** Personas de estilo visual (o select de persona do Studio). NULL = prompt de texto. */
    public function scopePersonas($query, ?string $kind = null)
    {
        $query->whereNotNull('kind');

        return $kind ? $query->where('kind', $kind) : $query;
    }
}
