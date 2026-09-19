<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Usage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Uso x quota do tenant no período corrente.
 * Fonte única para o widget do /app, o endpoint /api/usage e o enforcement 402.
 *
 * AUD-002: enforcement com incremento ATÔMICO condicional (sem race condition) —
 *   tryConsume() reserva a cota num único UPDATE que só credita se ainda houver saldo.
 * AUD-012: cada tipo de geração tem PESO por custo real (image=1, video=3, veo=10, short=5).
 */
class UsageService
{
    /** Buckets de quota (1 unidade = 1 operação). Separados por tipo porque o CUSTO difere
     *  muito (ver docs/custos-e-planos.md): image ~US$0,01 · video ~US$0,30 · short ~US$2,20 ·
     *  veo ~US$1,20. O limite de cada bucket vem de Tenant::PLAN_LIMITS.
     *  story = história stickman (3..20 cenas) — bucket próprio, teto mensal baixo (só Studio).
     *
     * @var list<string> */
    public const KINDS = ['image', 'video', 'short', 'veo', 'story', 'audio', 'text', 'effect'];

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
        'veo' => 1,
        'story' => 1,
        'audio' => 1,
        'text' => 1,
        'effect' => 1,
    ];

    /** CUSTO EM CRÉDITOS por operação = custo do provedor ÷ US$0,005/crédito (rate de custo da KIE).
     *  A MARGEM (40%) vive no PREÇO de venda do crédito ($0,00833), não aqui: o cliente paga
     *  `créditos × $0,00833` e o custo real é `créditos × $0,005`. Substitui a quota mensal — o que
     *  limita agora é o SALDO, não um teto por bucket. story = 0 (cada cena debita image/video/short).
     *  Custos atuais: imagem MiniMax $0,01 · vídeo padrão $0,30 · short $2,20 · Veo $1,20.
     *  (Recalibrar por modelo se rotear pela KIE — ver docs/custos-e-planos.md §9.)
     *
     * @var array<string,int> */
    public const CREDIT_COST = [
        'image' => 2,    // $0,01  ÷ $0,005
        'video' => 60,   // $0,30  ÷ $0,005
        'short' => 440,  // $2,20  ÷ $0,005
        'veo' => 240,    // $1,20  ÷ $0,005
        'story' => 0,
        'audio' => 1,    // música MiniMax (music-2.6-free) = grátis · TTS ~grátis → 1 crédito simbólico ($0,005)
        'text' => 5,     // fallback do texto premium (resumo/roteiro/bíblia/post) — o custo REAL vem do modelo escolhido (gen_models kind=text)
        'effect' => 1,   // Estúdio de Efeitos (F1+): 1 créd por transição aplicada; filtros/VFX/SFX têm multiplicador próprio no controller
    ];

    public function __construct(private CreditWallet $wallet) {}

    /** Custo em créditos de um tipo de operação (0 = não cobra). */
    public function creditCostFor(string $kind): int
    {
        return self::CREDIT_COST[$kind] ?? 0;
    }

    /** Cota DIÁRIA de pesquisa para conta em TRIAL. Pesquisa fica liberada sem plano e custa
     *  ~US$0,01–0,05/busca → teto diário é o anti denial-of-wallet (baseline secure). Assinantes
     *  e orgs exempt NÃO passam por esta cota (pesquisa faz parte do plano pago). */
    public const RESEARCH_TRIAL_DAILY = 10;

    public function period(): string
    {
        return date('Y-m');
    }

    /** Período DIÁRIO (prefixo 'd:' p/ não colidir com o mensal no unique tenant+period+kind). */
    public function dayPeriod(): string
    {
        return 'd:'.date('Y-m-d');
    }

    /**
     * Reserva atômica de cota DIÁRIA (mesma garantia anti-race do tryConsume, mas com teto
     * por dia e limite explícito — usada na cota de pesquisa do trial). true = reservado.
     */
    public function tryConsumeDaily(Tenant $tenant, string $kind, int $limit, int $weight = 1): bool
    {
        $weight = max(1, $weight);
        $limit = (int) $limit;
        if ($limit <= 0 || $weight > $limit) {
            return false;
        }

        $period = $this->dayPeriod();

        DB::table('usages')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'period' => $period,
            'kind' => $kind,
            'count' => 0,
        ]);

        $affected = DB::table('usages')
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->where('kind', $kind)
            ->whereRaw('count + ? <= ?', [$weight, $limit])
            ->update(['count' => DB::raw('count + '.(int) $weight)]);

        return $affected === 1;
    }

    /** Estorna a reserva diária quando a operação falhou (não cobra por falha nossa). */
    public function refundDaily(Tenant $tenant, string $kind, int $weight = 1): void
    {
        $weight = max(1, $weight);

        DB::table('usages')
            ->where('tenant_id', $tenant->id)
            ->where('period', $this->dayPeriod())
            ->where('kind', $kind)
            ->update([
                'count' => DB::raw('CASE WHEN count >= '.(int) $weight.' THEN count - '.(int) $weight.' ELSE 0 END'),
            ]);
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
     * @param  string  $kind  tipo da QUOTA (image|video|veo) — a "bucket" debitada
     * @param  int  $weight  quanto debitar (use weightFor()/N de uma vez)
     * @param  ?int  $costCredits  custo unitário em créditos do MODELO escolhido (gen_models.cost_credits);
     *                             null = usa o custo fixo por tipo (CREDIT_COST). Cobrança por modelo.
     * @return bool true = reservado (pode gerar); false = estourou o plano (402)
     */
    public function tryConsume(Tenant $tenant, string $kind, int $weight = 1, ?int $costCredits = null): bool
    {
        $weight = max(1, $weight);

        // (1) FEATURE-GATE por plano: bucket com teto 0 = tipo NÃO incluso no plano (ex: veo/story
        // só no Studio). Os números do PLAN_LIMITS viram só "disponível (>0) vs não (0)" no modelo
        // de créditos. exempt tem limites altíssimos → sempre passa.
        if ((int) ($tenant->limits()[$kind] ?? 0) <= 0) {
            return false; // 402 "não incluso no plano"
        }

        // (2) CONSUMO = CRÉDITOS (reserve-then-consume). Débito ANTES de gerar; estornado se falhar.
        // O custo é POR MODELO (gen_models.cost_credits) quando informado; senão cai no custo fixo
        // por tipo (CREDIT_COST). exempt → debit() é no-op (não cobra). Saldo insuficiente →
        // InsufficientCreditsException PROPAGA (render 402 'insufficient_credits' com saldo/required).
        $cost = ($costCredits ?? $this->creditCostFor($kind)) * $weight;
        if ($cost > 0 && ($org = $tenant->organization)) {
            // Saldo é da ORG (compartilhado entre marcas); tenant_id marca qual gastou no extrato.
            $this->wallet->debit($org, $cost, 'debit_generation', [
                'reference_type' => 'generation',
                'reference_id' => $kind,
                'tenant_id' => $tenant->id,
            ]);
        }

        // (3) ANALYTICS: registra a operação no bucket do período (sem gate — o gate agora é o saldo).
        $this->recordUsage($tenant, $kind, $weight);

        return true;
    }

    /** Registra a operação no bucket do mês (somente contagem para analytics/painel; não limita). */
    private function recordUsage(Tenant $tenant, string $kind, int $weight): void
    {
        $period = $this->period();
        DB::table('usages')->insertOrIgnore([
            'tenant_id' => $tenant->id, 'period' => $period, 'kind' => $kind, 'count' => 0,
        ]);
        DB::table('usages')
            ->where('tenant_id', $tenant->id)->where('period', $period)->where('kind', $kind)
            ->update(['count' => DB::raw('count + '.(int) $weight)]);
    }

    /**
     * Estorna (compensa) uma reserva quando a geração falhou. Nunca deixa negativo.
     *
     * @param  int  $weight  quanto devolver (o mesmo que foi reservado, ou a parte não gerada)
     * @param  ?int  $costCredits  MESMO custo unitário usado na reserva (gen_models.cost_credits);
     *                             null = custo fixo por tipo. Tem de bater com o tryConsume.
     */
    public function refund(Tenant $tenant, string $kind, int $weight = 1, ?int $costCredits = null): void
    {
        $weight = max(1, $weight);

        // Estorna os CRÉDITOS debitados (geração falhou — não cobra por falha nossa). exempt é
        // tratado NO WALLET (registra delta 0 pra netar o consumo monitorado, sem creditar).
        $cost = ($costCredits ?? $this->creditCostFor($kind)) * $weight;
        if ($cost > 0 && ($org = $tenant->organization)) {
            $this->wallet->refund($org, $cost, [
                'reference_type' => 'generation_refund',
                'reference_id' => $kind,
                'tenant_id' => $tenant->id,
            ]);
        }

        // Desfaz o registro de analytics do bucket.
        DB::table('usages')
            ->where('tenant_id', $tenant->id)
            ->where('period', $this->period())
            ->where('kind', $kind)
            ->update([
                'count' => DB::raw('CASE WHEN count >= '.(int) $weight.' THEN count - '.(int) $weight.' ELSE 0 END'),
            ]);
    }

    /**
     * Preço justo de uma entrega feita pela LINHA DE RESERVA de texto, em créditos por peça.
     * Bate com os níveis mais baratos do catálogo (txt-rapido / txt-versatil = 1), que é a régua
     * honesta: a reserva entrega qualidade de nível básico, então não pode custar mais que ele.
     */
    public const PRECO_RESERVA_TEXTO = 1;

    /**
     * Devolve a DIFERENÇA quando a geração de texto foi entregue pela reserva, e não pelo modelo
     * pedido. O engine sinaliza isso no cabeçalho X-Reachyn-Reserva (ver engine/internal/api/reserva.go).
     *
     * POR QUE NÃO É UM refund() COMUM: a geração ACONTECEU e foi entregue — só que num nível
     * abaixo do contratado. Então o crédito volta parcialmente (a diferença) e o contador de
     * analytics NÃO é desfeito: a peça existe e conta como uso. Estornar tudo pagaria o cliente
     * por uma entrega que ele recebeu; não estornar nada é o defeito que isto corrige (2026-08-03:
     * "Topo" a 20 créditos servido pela reserva, cujo nível equivalente custa 1).
     *
     * @param  int  $weight  o MESMO peso usado na reserva
     * @param  ?int  $costCredits  o MESMO custo unitário usado na reserva
     * @return int créditos devolvidos (0 quando o pedido já era do nível da reserva)
     */
    public function ajustaParaReserva(Tenant $tenant, string $kind, int $weight = 1, ?int $costCredits = null): int
    {
        $weight = max(1, $weight);
        $cobrado = ($costCredits ?? $this->creditCostFor($kind)) * $weight;
        $justo = self::PRECO_RESERVA_TEXTO * $weight;

        $diferenca = $cobrado - $justo;
        if ($diferenca <= 0) {
            return 0; // pediu o nível básico e recebeu o básico — nada a devolver
        }

        if ($org = $tenant->organization) {
            $this->wallet->refund($org, $diferenca, [
                // Motivo PRÓPRIO no extrato: no relatório isto não pode se confundir com falha de
                // geração — aqui a peça foi entregue, só que num nível abaixo.
                'reference_type' => 'generation_downgrade',
                'reference_id' => $kind,
                'tenant_id' => $tenant->id,
            ]);
        }

        Log::info('texto entregue pela reserva: devolvida a diferença', [
            'tenant_id' => $tenant->id, 'kind' => $kind,
            'cobrado' => $cobrado, 'justo' => $justo, 'devolvido' => $diferenca,
        ]);

        return $diferenca;
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
