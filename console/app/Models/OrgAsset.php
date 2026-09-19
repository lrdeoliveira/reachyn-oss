<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Asset guardado da marca (Hub "My Assets"). Por-marca de verdade → BelongsToTenant (TenantScope +
 * auto-fill tenant_id) + RLS no banco. `meta` guarda a proveniência (draft/projeto/personagem/prompt).
 */
class OrgAsset extends Model
{
    use BelongsToTenant;

    public const KINDS = ['image', 'video', 'audio', 'keyframe', 'logo', 'other'];

    public const SOURCES = ['upload', 'generation', 'easyapp', 'import'];

    protected $fillable = ['tenant_id', 'user_id', 'kind', 'source', 'url', 'thumb_url', 'meta', 'favorite'];

    protected $casts = [
        'meta' => 'array',
        'favorite' => 'boolean',
    ];
}
