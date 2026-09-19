<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardaVersoes;
use Illuminate\Database\Eloquent\Model;

/**
 * ELEMENTO do catálogo — objeto de cena com ficha e imagem-âncora: veículo, móvel, prop, figurino,
 * animal. Mesma mecânica de identidade que personagem e cenário: a imagem viaja como referência
 * para toda cena que usa o elemento, e é isso que faz o carro da cena 7 ser o carro da cena 2.
 *
 * @property string|null $mesh_status
 * @property string|null $mesh_msg
 */
class Element extends Model
{
    use BelongsToTenant;
    use GuardaVersoes;

    /** Categorias — o "departamento" do catálogo, enxugado pro que um filme gerado por IA usa. */
    public const CATEGORIAS = [
        'prop' => 'Prop (objeto de mão)',
        'veiculo' => 'Veículo',
        'mobiliario' => 'Mobiliário',
        'figurino' => 'Figurino',
        'animal' => 'Animal',
        'cenografia' => 'Cenografia / set',
        'outro' => 'Outro',
    ];

    // mesh_status ('gerando'|'erro'|null): geração de malha é ASSÍNCRONA (minutos) e acompanhada
    // por polling; `status` continua sendo o estado da geração de IMAGEM do elemento.
    // mesh_msg: o que o juiz disse sobre a malha (ressalva do 'aviso' / motivo do 'erro') — sem
    // ele a tela alerta sem dizer o quê, e o usuário reclica num problema que é do dado.
    protected $fillable = ['tenant_id', 'name', 'categoria', 'description', 'image_url', 'mesh_url', 'fbx_url', 'image_model', 'style', 'status', 'mesh_status', 'mesh_msg'];

    /** Imagens versionadas: trocar uma delas guarda a anterior (ver GuardaVersoes). */
    public function camposVersionados(): array
    {
        return ['image_url', 'mesh_url'];
    }

    public function tipoDeAsset(): string
    {
        return 'element';
    }
}
