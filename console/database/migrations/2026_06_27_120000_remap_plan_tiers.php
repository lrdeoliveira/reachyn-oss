<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reestrutura da escada de planos (2026-06-27): novo Starter barato (R$30) + nomes deslizam pra cima.
//   Antes: starter($39/4500) · pro($99/12000) · studio($269/30000)
//   Agora: starter(R$30/600) · pro(R$100/2000) · studio(R$250/5000) · enterprise(R$600/12000)
// As ORGS existentes deslizam um degrau pra PRESERVAR a capacidade (vídeo/veo) que já tinham —
// senão um 'studio' antigo (tinha Veo) viraria o novo 'studio' (sem Veo). Saldo é rollover (já
// preservado, independe do plano). Ordem dos updates evita cascata (renomear de cima pra baixo).
return new class extends Migration
{
    public function up(): void
    {
        // 1) Interno/exempt (redfox) → 'unlimited' (todas as features; não debita). Tira do shift abaixo.
        DB::table('organizations')->where('billing_status', 'exempt')->update(['plan' => 'unlimited']);
        // 2) studio antigo (top, tinha Veo) → enterprise (novo top, tem Veo).
        DB::table('organizations')->where('plan', 'studio')->update(['plan' => 'enterprise']);
        // 3) pro antigo → studio.
        DB::table('organizations')->where('plan', 'pro')->update(['plan' => 'studio']);
        // 4) starter antigo (tinha vídeo) → pro (mantém vídeo; o novo starter é sem vídeo).
        DB::table('organizations')->where('plan', 'starter')->update(['plan' => 'pro']);
    }

    public function down(): void
    {
        // Inverso (ordem inversa p/ não cascatear). 'unlimited' não tem origem única → vira 'studio'
        // (o estado mais comum dos exempt antes); ajuste manual se precisar de exatidão no rollback.
        DB::table('organizations')->where('plan', 'pro')->update(['plan' => 'starter']);
        DB::table('organizations')->where('plan', 'studio')->update(['plan' => 'pro']);
        DB::table('organizations')->where('plan', 'enterprise')->update(['plan' => 'studio']);
        DB::table('organizations')->where('plan', 'unlimited')->update(['plan' => 'studio']);
    }
};
