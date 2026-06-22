<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'job_id', 'keyword', 'preview_text',
        'image_url', 'video_url', 'resume_url', 'cancel_url', 'meta', 'status',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
