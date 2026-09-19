<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Biblioteca de PERSONAGENS reutilizáveis do tenant (base + model sheet híbrido). Um personagem
// guarda: descrição livre, estilo, a imagem-BASE (retrato frontal = âncora i2i), o MODEL SHEET
// (turnaround) e a BÍBLIA destilada (lock canônico em EN + paleta/traços/acessórios/expressões).
// É a base reutilizável em Histórias e Mídia — mantém a integridade visual do personagem.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('');
            $table->text('description')->nullable();   // descrição livre (vira o lock via bíblia)
            $table->string('style')->default('realista'); // 1 dos estilos de imagem (styles.go)
            $table->string('base_url')->nullable();    // imagem-base (retrato frontal) = âncora i2i
            $table->string('sheet_url')->nullable();   // model sheet (turnaround) gerado por i2i
            $table->text('lock')->nullable();          // CHARACTER LOCK canônico (bíblia, EN verbatim)
            $table->json('bible')->nullable();         // {palette,traits,accessories,expressions}
            $table->string('status')->default('');     // ''=pronto | base|sheet|edit = geração em curso (job)
            $table->timestamps();
            $table->index(['tenant_id', 'updated_at']);
        });

        // Defesa em profundidade (AUD-020): RLS por tenant_id (fail-open p/ operador/jobs), espelhando
        // enable_rls_tenant_tables. + GRANT explícito ao role de aplicação reachyn_app (o GRANT ON ALL
        // TABLES anterior não cobre tabelas criadas depois). Só Postgres (em dev/sqlite não se aplica).
        if (DB::getDriverName() === 'pgsql') {
            $cond = "current_setting('app.current_tenant', true) IS NULL "
                  ."OR current_setting('app.current_tenant', true) = '' "
                  ."OR tenant_id = current_setting('app.current_tenant', true)::bigint";
            DB::statement('ALTER TABLE characters ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE characters FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON characters');
            DB::statement("CREATE POLICY tenant_isolation ON characters USING ({$cond}) WITH CHECK ({$cond})");
            // Re-aplica os GRANTs em ALL TABLES/SEQUENCES (idempotente) — cobre a tabela/sequence
            // recém-criadas (o GRANT ON ALL anterior só pegou as que existiam então). Mesma forma
            // do enable_rls_tenant_tables; robusto a id serial OU identity.
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO reachyn_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO reachyn_app');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON characters');
        }
        Schema::dropIfExists('characters');
    }
};
