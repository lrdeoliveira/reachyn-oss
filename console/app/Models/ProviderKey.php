<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Chave de um provedor de GERAÇÃO (operador), cifrada em repouso.
 * Gerida na página admin "Chaves de geração" e empurrada pro engine.
 */
class ProviderKey extends Model
{
    protected $fillable = ['provider', 'api_key', 'base_url', 'model'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
