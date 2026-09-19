<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lançamento do ledger de créditos (APPEND-ONLY). Nunca editar/apagar um lançamento;
 * correções entram como NOVO lançamento (type=adjustment). Fonte de auditoria do saldo.
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null; // append-only: só created_at

    protected $fillable = [
        'organization_id', 'tenant_id', 'delta', 'balance_after', 'type',
        'reference_type', 'reference_id', 'idempotency_key', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Marca que originou o débito de geração (null em concessões/top-up da org).
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
