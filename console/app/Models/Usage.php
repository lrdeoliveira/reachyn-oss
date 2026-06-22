<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Usage extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $table = 'usages';

    public $timestamps = false; // a tabela usages não tem created_at/updated_at

    protected $fillable = ['tenant_id', 'period', 'kind', 'count'];

    protected function casts(): array
    {
        return ['count' => 'integer'];
    }
}
