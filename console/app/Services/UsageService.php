<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Usage;
use Illuminate\Support\Facades\DB;

/**
 * Uso x quota do tenant no período corrente.
 * Fonte única para o widget do /app, o endpoint /api/usage e o enforcement 402.
 *
 * AUD-002: enforcement com incremento ATÔMICO condicional (sem race condition) —
 *   tryConsume() reserva a cota num único UPDATE que só credita se ainda houver saldo.
 * AUD-012: cada tipo de geração tem PESO por custo real (image=1, video=3, premium-video=10, short=5).
 */
class UsageService
{
    /** Buckets de quota (1 unidade = 1 operação). Separados por tipo porque o CUSTO difere
     *  muito (ver docs/custos-e-planos.md): image ~US$0,01 · video ~US$0,30 · short ~US$2,20 ·
     *  premium-video ~US$1,20. O limite de cada bucket vem de Tenant::PLAN_LIMITS.
     * @var list<string> */
    public const KINDS = ['image', 'video', 'short', 'premium-video'];

    /**
     * Peso por operação = 1 (cada geração consome 1 do seu bucket). O custo já é refletido
     * pela SEPARAÇÃO em buckets + os limites por plano, não por peso dentro de um bucket comum.
     *
     * @var array<string,int>
     */
    public const WEIGHTS = [
        'image' => 1,
        'video' => 1,
        'short' => 1,
        'premium-video' => 1,
    ];

    public function period(): string
    {
        return date('Y-m');
    }

    /** Peso (custo) de um tipo de geração; default 1 para tipos não mapeados. */
    public function weightFor(string $kind): int
    {
        return self::WEIGHTS[$kind] ?? 1;
    }

    /**
     * Resumo de consumo vs teto do plano.
     *
     * @return array{period:string,plan:string,premium:bool,kinds:array<string,array{used:int,limit:int,remaining:int,pct:int}>}
     */
    public function summary(Tenant $tenant): array
    {
        $period = $this->period();
        $limits = $tenant->limits();
        $used = $tenant->usages()->where('period', $period)->pluck('count', 'kind');

        $kinds = [];
        foreach (self::KINDS as $k) {
            $limit = (int) ($limits[$k] ?? 0);
            $u = (int) ($used[$k] ?? 0);
            $kinds[$k] = [
                'used' => $u,
                'limit' => $limit,
                'remaining' => max(0, $limit - $u),
                'pct' => $limit > 0 ? (int) min(100, round($u / $limit * 100)) : 0,
            ];
        }

        return [
            'period' => $period,
            'plan' => $tenant->plan,
            'premium' => (bool) ($limits['premium'] ?? false),
            'kinds' => $kinds,
        ];
    }

    /**
     * AUD-002 — RESERVA atômica de cota.
     *
     * Faz um único UPDATE que só credita o peso se a linha ainda comporta o consumo
     * (count + weight <= limit). Como o predicado da quota vive na cláusula WHERE,
     * dois requests concorrentes nunca ultrapassam o teto (o banco serializa o UPDATE).
     *
     * @param  string  $kind    tipo da QUOTA (image|video|premium-video) — a "bucket" debitada
     * @param  int     $weight  quanto debitar (use weightFor()/N de uma vez)
     * @return bool    true = reservado (pode gerar); false = estourou o plano (402)
     */
    public function tryConsume(Tenant $tenant, string $kind, int $weight = 1): bool
    {
        $weight = max(1, $weight);
        $limit = (int) ($tenant->limits()[$kind] ?? 0);
        if ($limit <= 0) {
            return false; // tipo não incluso no plano
        }
        if ($weight > $limit) {
            return false; // pedido maior que o teto total — nunca caberia
        }

        $period = $this->period();

        // Garante a linha (idempotente pelo unique tenant+period+kind). insertOrIgnore
        // evita corrida na criação; se já existir, simplesmente não faz nada.
        DB::table('usages')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'period' => $period,
            'kind' => $kind,
            'count' => 0,
        ]);

        // UPDATE condicional atômico: só credita se ainda couber dentro do limite.
        $affected = DB::table('usages')
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->where('kind', $kind)
            ->whereRaw('count + ? <= ?', [$weight, $limit])
            ->update(['count' => DB::raw('count + '.(int) $weight)]);

        return $affected === 1;
    }

    /**
     * Estorna (compensa) uma reserva quando a geração falhou. Nunca deixa negativo.
     *
     * @param  int  $weight  quanto devolver (o mesmo que foi reservado, ou a parte não gerada)
     */
    public function refund(Tenant $tenant, string $kind, int $weight = 1): void
    {
        $weight = max(1, $weight);

        DB::table('usages')
            ->where('tenant_id', $tenant->id)
            ->where('period', $this->period())
            ->where('kind', $kind)
            ->update([
                // GREATEST evita saldo negativo se houver concorrência/dupla-compensação.
                'count' => DB::raw('CASE WHEN count >= '.(int) $weight.' THEN count - '.(int) $weight.' ELSE 0 END'),
            ]);
    }

    /**
     * @deprecated AUD-002: use tryConsume() (reserva atômica). Mantido por compatibilidade.
     * True quando o tenant já bateu o teto do tipo no mês (base do 402).
     */
    public function exceeds(Tenant $tenant, string $kind): bool
    {
        $limit = (int) ($tenant->limits()[$kind] ?? 0);
        if ($limit <= 0) {
            return true; // tipo não incluso no plano
        }
        $used = (int) $tenant->usages()
            ->where('period', $this->period())->where('kind', $kind)->value('count');

        return $used >= $limit;
    }

    /**
     * @deprecated AUD-002: use tryConsume() (que já credita atomicamente). Mantido por compatibilidade.
     * Incrementa o contador do tipo no período (idempotente por linha única tenant+period+kind).
     */
    public function increment(Tenant $tenant, string $kind, int $by = 1): Usage
    {
        $usage = Usage::firstOrCreate(
            ['tenant_id' => $tenant->id, 'period' => $this->period(), 'kind' => $kind],
            ['count' => 0],
        );
        $usage->increment('count', $by);

        return $usage;
    }
}
