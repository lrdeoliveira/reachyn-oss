<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardaVersoes;
use Illuminate\Database\Eloquent\Model;

/**
 * Personagem reutilizável da biblioteca do tenant (aba Personagens). Guarda a base reutilizável:
 * descrição + estilo, a imagem-BASE (âncora i2i), o MODEL SHEET (turnaround) e a BÍBLIA destilada
 * (lock canônico em EN + paleta/traços/acessórios/expressões). Usado como base em Histórias/Mídia.
 *
 * @property string|null $mesh_status
 * @property string|null $mesh_msg
 */
class Character extends Model
{
    // AUD-020: global scope tenant_id (defesa em profundidade) + relação tenant().
    use BelongsToTenant;
    use GuardaVersoes;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'style', 'image_model', 'text_model',
        'base_url', 'sheet_url', 'mesh_url', 'fbx_url', 'sheets', 'sheet_pending', 'lock', 'bible', 'status',
        // mesh_status ('gerando'|'erro'|null): a geração de malha é ASSÍNCRONA (leva minutos) e o
        // front acompanha por polling. Separado de `status`, que é a geração de IMAGEM.
        // mesh_msg: o que o JUIZ disse sobre esta malha — a ressalva do 'aviso' ou o motivo do
        // 'erro'. Sem ele a tela mostra alerta sem conteúdo ("falhou, tente de novo") e o usuário
        // reclica num problema que não está na geração, e sim no dado (2026-08-02).
        'mesh_status', 'mesh_msg',
        // F1 (fluxo image→cena): ficha metodológica — papel (arquétipo) + logline p/ listagem.
        'archetype', 'logline',
    ];

    protected $casts = [
        'bible' => 'array',
        'sheets' => 'array', // MODEL SHEET v3: [{kind,url}] das pranchas (angles|head|poses|palette)
    ];

    /** Imagem que ancora a identidade deste personagem numa geração i2i: a BASE, e o model sheet
     *  como segunda opção. Vazio = o personagem ainda não tem imagem nenhuma.
     *
     *  A regra estava copiada em FilmController::filmElementCharacter e
     *  AnimationController::elementCharacter — junto da mensagem de erro, palavra por palavra. É a
     *  única parte da vinculação que NÃO varia entre os dois: o resto (cap do lock, campo `status`,
     *  formato do elemento) diverge por domínio de propósito e continua em cada controller. */
    public function refImageUrl(): string
    {
        return (string) ($this->base_url ?: $this->sheet_url);
    }

    /** Mensagem única de "personagem sem imagem" — estava duplicada verbatim nos dois controllers,
     *  e mensagem duplicada é a que fica desatualizada de um lado só. */
    public const SEM_IMAGEM = 'esse personagem ainda não tem imagem-base — gere na aba Personagens';

    /** Imagens versionadas: trocar uma delas guarda a anterior (ver GuardaVersoes). */
    public function camposVersionados(): array
    {
        return ['base_url', 'sheet_url', 'mesh_url'];
    }

    public function tipoDeAsset(): string
    {
        return 'character';
    }
}
