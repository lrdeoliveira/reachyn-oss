<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardaVersoes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cenário reutilizável da biblioteca do tenant (F5 — "Elementos" do storyboard): imagem-âncora +
 * descrição. Entra como ref i2i de cena nas Histórias/Quadrinhos pra manter o MESMO ambiente/luz
 * entre cenas e episódios (mesmo papel da base do personagem, mas pro cenário).
 */
class Scenario extends Model
{
    use BelongsToTenant;
    use GuardaVersoes;

    protected $fillable = ['tenant_id', 'name', 'description', 'image_url', 'status', 'image_model', 'style', 'spec'];

    protected $casts = [
        'spec' => 'array', // F3: ambientação metodológica (função dramática/tríade/atmosfera/riscos)
    ];

    /** Gerando a imagem-âncora agora? Mesma semântica do Character::status ('' = ocioso/pronto). */
    public function isBusy(): bool
    {
        return $this->status !== '' && $this->status !== 'error';
    }

    /** Imagens versionadas: trocar uma delas guarda a anterior (ver GuardaVersoes). */
    public function camposVersionados(): array
    {
        return ['image_url'];
    }

    public function tipoDeAsset(): string
    {
        return 'scenario';
    }
}
