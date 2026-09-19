<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Perfil de publicação = 1 profile do Zernio (conjunto de contas conectadas) dentro de um tenant.
 * Um tenant tem N perfis; o publish seleciona 1..N pra postar de uma vez. O perfil `is_default`
 * espelha tenants.zernio_profile_id (retrocompat com os fluxos single-profile).
 */
class Profile extends Model
{
    use BelongsToTenant; // global scope tenant_id + auto-fill no creating + relação tenant()

    protected $fillable = ['tenant_id', 'name', 'zernio_profile_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
