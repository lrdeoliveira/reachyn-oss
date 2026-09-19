<?php

namespace App\Services;

use App\Exceptions\InsufficientCreditsException;
use App\Models\CreditTransaction;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Carteira de créditos da ORGANIZAÇÃO (Fase 2). Saldo ÚNICO compartilhado por todas as marcas
 * (Tenants) da org; rollover total (nada expira). Toda mutação é ATÔMICA (lock de linha +
 * transação) e registrada no ledger append-only. Idempotência por `idempotency_key` —
 * débitos de geração podem reentrar sem dobrar o lançamento.
 *
 * Cada lançamento guarda organization_id (dona do saldo) e, em débitos de geração, tenant_id
 * (qual MARCA gastou — passado em opts['tenant_id']) para o extrato por marca.
 *
 * Orgs `exempt` (interna/dogfooding) NÃO são debitadas: debit() retorna null (liberado, sem custo).
 */
class CreditWallet
{
    public function balance(Organization $org): int
    {
        return (int) $org->credit_balance;
    }

    /** Credita (assinatura/top-up/cortesia/signup). */
    public function grant(Organization $org, int $amount, string $type, array $opts = []): CreditTransaction
    {
        return $this->apply($org, abs($amount), $type, $opts);
    }

    /**
     * Debita uma operação (ex: gen_models.cost_credits).
     * - exempt → null (liberado, sem cobrança).
     * - saldo insuficiente → InsufficientCreditsException (402).
     */
    public function debit(Organization $org, int $amount, string $type = 'debit_generation', array $opts = []): ?CreditTransaction
    {
        if (($org->billing_status ?? null) === 'exempt') {
            // Exempt NÃO cobra, mas REGISTRA (delta 0 + custo-que-seria no meta) — é o que permite
            // monitorar o consumo das contas internas/dogfooding no extrato (pedido Luciano 2026-07-10).
            return $this->logExempt($org, -abs($amount), $type, $opts);
        }

        return $this->apply($org, -abs($amount), $type, $opts);
    }

    /** Estorna créditos (ex: geração falhou no engine após o débito). */
    public function refund(Organization $org, int $amount, array $opts = []): CreditTransaction
    {
        if (($org->billing_status ?? null) === 'exempt') {
            // Espelho do debit: estorno de exempt só REGISTRA (neta o consumo monitorado, sem creditar).
            return $this->logExempt($org, abs($amount), 'refund_generation', $opts);
        }

        return $this->apply($org, abs($amount), 'refund_generation', $opts);
    }

    /** Lançamento de MONITORAMENTO de org exempt: delta 0 (saldo intocado), custo-que-seria em
     *  meta.would_be (negativo = gasto, positivo = estorno) + meta.exempt. Aparece no extrato. */
    private function logExempt(Organization $org, int $wouldBe, string $type, array $opts): CreditTransaction
    {
        return CreditTransaction::create([
            'organization_id' => $org->getKey(),
            'tenant_id' => $opts['tenant_id'] ?? null,
            'delta' => 0,
            'balance_after' => (int) $org->credit_balance,
            'type' => $type,
            'reference_type' => $opts['reference_type'] ?? null,
            'reference_id' => $opts['reference_id'] ?? null,
            'idempotency_key' => $opts['idempotency_key'] ?? null,
            'meta' => array_merge($opts['meta'] ?? [], ['exempt' => true, 'would_be' => $wouldBe]),
        ]);
    }

    /** Ajuste manual do operador (pode ser + ou -). Sempre logado com nota. */
    public function adjust(Organization $org, int $delta, string $note, array $opts = []): CreditTransaction
    {
        $opts['meta'] = array_merge($opts['meta'] ?? [], ['note' => $note]);

        return $this->apply($org, $delta, 'adjustment', $opts);
    }

    /**
     * Núcleo atômico: trava a linha da org, valida saldo, atualiza e registra.
     * Idempotente quando `idempotency_key` é passado (retorna o lançamento existente).
     */
    private function apply(Organization $org, int $delta, string $type, array $opts): CreditTransaction
    {
        $key = $opts['idempotency_key'] ?? null;

        return DB::transaction(function () use ($org, $delta, $type, $opts, $key) {
            if ($key !== null) {
                $existing = CreditTransaction::where('idempotency_key', $key)->first();
                if ($existing) {
                    return $existing; // já aplicado — não duplica
                }
            }

            // Lock pessimista da linha da org (serializa débitos concorrentes entre marcas).
            $locked = Organization::whereKey($org->getKey())->lockForUpdate()->firstOrFail();
            $current = (int) $locked->credit_balance;
            $after = $current + $delta;

            if ($after < 0) {
                throw new InsufficientCreditsException(required: abs($delta), balance: $current);
            }

            $locked->forceFill(['credit_balance' => $after])->save();
            $org->credit_balance = $after; // mantém a instância do chamador coerente

            return CreditTransaction::create([
                'organization_id' => $locked->getKey(),
                'tenant_id' => $opts['tenant_id'] ?? null, // qual marca gastou (débitos de geração)
                'delta' => $delta,
                'balance_after' => $after,
                'type' => $type,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'idempotency_key' => $key,
                'meta' => $opts['meta'] ?? [],
            ]);
        });
    }
}
