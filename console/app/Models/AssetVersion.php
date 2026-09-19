<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma versão ANTERIOR de uma imagem (ou malha) de personagem, cenário ou elemento.
 *
 * Gravada pelo trait `GuardaVersoes` no evento `updating` do dono — um gancho só, em vez de
 * lembrar de versionar em cada lugar que escreve URL (upload, job de geração, API, restauração).
 */
class AssetVersion extends Model
{
    use BelongsToTenant;

    /** Quem pode ter versão. A chave é o que vai na API e no banco. */
    public const DONOS = ['character' => Character::class, 'scenario' => Scenario::class, 'element' => Element::class];

    protected $fillable = ['tenant_id', 'owner_type', 'owner_id', 'campo', 'url', 'model'];
}
