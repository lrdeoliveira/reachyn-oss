<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Draft extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'keyword', 'research', 'texts', 'texts_meta', 'media',
        'image_url', 'video_url', 'image_prompt', 'status', 'publish',
    ];

    protected function casts(): array
    {
        return [
            'research' => 'array',
            'texts' => 'array',
            'texts_meta' => 'array',
            'media' => 'array',
            'publish' => 'array',
        ];
    }
}
