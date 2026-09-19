<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Abre o "Studio B" (img-cli-mmx) para TODOS os planos — era min_plan='unlimited', ou seja,
// só a org redfox o via no select de modelo de imagem.
//
// Por quê: é geração de custo marginal ZERO (roda na conta de ASSINATURA via CLI no host, não
// na API paga por chamada). Com o KIE sem saldo, é o caminho que mantém a geração de imagem
// viva — e mesmo com saldo, é a opção barata ao lado dos modelos KIE, que passam a ser escolha
// deliberada de qualidade em vez de único caminho.
//
// ⚠️ CAPACIDADE: a assinatura é UMA conta e o bridge roda BRIDGE_CONCURRENCY jobs em paralelo
// (default 2). Sob concorrência alta o bridge devolve 429 ("bridge ocupado") em vez de acumular
// processo de CLI no host — de propósito. Se virar gargalo, subir BRIDGE_CONCURRENCY (teto 8)
// no /opt/reachyn-cli-bridge/.env antes de mexer em código.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gen_models')->where('slug', 'img-cli-mmx')->update(['min_plan' => null]);
    }

    public function down(): void
    {
        DB::table('gen_models')->where('slug', 'img-cli-mmx')->update(['min_plan' => 'unlimited']);
    }
};
