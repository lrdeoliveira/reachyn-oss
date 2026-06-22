<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Connection extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'platform', 'label', 'secret', 'status', 'detail'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted']; // porta do lib/crypto.ts (segredos de conexão)
    }
}
