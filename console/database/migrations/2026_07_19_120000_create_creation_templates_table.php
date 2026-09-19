<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * creation_templates — catálogo de TEMPLATES de criação (Quick Start / aba Rápido, benchmark
 * Nordy+RunningHub). Aplicar um template pré-preenche um AnimationProject ou um Draft (o `payload`
 * carrega mode/style/aspect/scenes/etc.), tirando o usuário do "por onde começo?".
 *
 * Tabela MISTA: `tenant_id` NULL = template GLOBAL Reachyn (seed, visível a todas as marcas);
 * `tenant_id` preenchido = "receita" da própria org (P2.3 — receitas da equipe).
 *
 * RLS — caso NOVO no padrão (única tabela mista do banco). O TenantRls::protect() padrão usa a
 * MESMA condição em USING e WITH CHECK, o que ESCONDERIA as linhas globais (tenant_id NULL) do
 * cliente. Aqui a policy é escrita à mão com condições diferentes:
 *   - USING (leitura):    fail-open + "tenant_id IS NULL" + própria marca  → cliente vê globais + as suas
 *   - WITH CHECK (escrita): fail-open + própria marca (SEM o IS NULL)      → cliente só grava na sua marca;
 *     o seed de globais roda no CLI (app.current_tenant vazio → fail-open) e por isso consegue inserir NULL.
 * Ambas as condições contêm NULLIF, então passam na FALHA 2 do reachyn:verify-db-isolation (que
 * derruba deploy se a `qual` da policy 'tenant_isolation' não tiver NULLIF); e como a policy se
 * chama 'tenant_isolation', a tabela também não cai no WARNING de "tenant_id sem policy". Nenhuma
 * mudança no comando é necessária.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creation_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = global Reachyn
            $table->string('slug', 64);            // allowlist ^[a-z0-9-]{1,64}$ (validado na app)
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('category', 32);        // ugc_produto|historia|reels|comic|faceless|outro
            $table->string('preview_url', 500)->nullable();
            $table->json('payload');               // shape: CreationTemplate — target/mode/style/aspect/scenes/...
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']); // slug único por marca (globais: tenant_id NULL)
            $table->index(['tenant_id', 'category', 'active']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return; // sqlite (suíte de testes) não tem RLS — o TenantScope/scopeVisibleTo isola na app
        }

        // Condições da policy — mesma base do TenantRls::condition() (fail-open p/ operador/CLI/job),
        // mas USING ≠ WITH CHECK por ser tabela mista. NULLIF é OBRIGATÓRIO (verify-db-isolation).
        $open = "current_setting('app.current_tenant', true) IS NULL "
              ."OR current_setting('app.current_tenant', true) = ''";
        $own = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::bigint";
        $using = "{$open} OR tenant_id IS NULL OR {$own}";  // leitura: globais + próprios (+ fail-open)
        $check = "{$open} OR {$own}";                       // escrita: só própria marca (globais = seed/CLI)

        DB::statement('ALTER TABLE creation_templates ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE creation_templates FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON creation_templates');
        DB::statement("CREATE POLICY tenant_isolation ON creation_templates USING ({$using}) WITH CHECK ({$check})");

        // GRANT condicional: em banco novo o role reachyn_app ainda não existe (ensure_reachyn_app_role
        // roda depois e refaz os grants + DEFAULT PRIVILEGES); pular aqui não deixa buraco.
        if (DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'reachyn_app'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON creation_templates TO reachyn_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON SEQUENCE creation_templates_id_seq TO reachyn_app');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON creation_templates');
        }
        Schema::dropIfExists('creation_templates');
    }
};
