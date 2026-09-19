<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfis de publicação (profiles do Zernio) por tenant. Cada perfil = 1 conjunto de contas
 * sociais conectadas ("Marca", "Pessoal", "Cliente X"). Permite que UMA conta tenha VÁRIOS
 * perfis e publique em 1..N de uma vez (a empresa com 3 Instagrams = 3 perfis).
 *
 * O `tenants.zernio_profile_id` segue existindo como o perfil PADRÃO (retrocompat: Histórias,
 * Aprovações e o publish single-profile continuam usando-o). O backfill cria a linha default
 * a partir dele.
 *
 * Isolamento igual às demais tabelas tenant-scoped: trait BelongsToTenant (app) + RLS (Postgres)
 * com a MESMA política fail-open por `app.current_tenant`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('zernio_profile_id')->nullable(); // null = perfil novo ainda sem profile Zernio criado
            $table->boolean('is_default')->default(false);    // o perfil padrão (espelha tenants.zernio_profile_id)
            $table->timestamps();
            $table->index('tenant_id');
        });

        // Backfill: cada tenant com zernio_profile_id ganha seu perfil PADRÃO.
        foreach (DB::table('tenants')->whereNotNull('zernio_profile_id')->where('zernio_profile_id', '!=', '')->get() as $t) {
            DB::table('profiles')->insert([
                'tenant_id' => $t->id,
                'name' => $t->name ?: 'Principal',
                'zernio_profile_id' => $t->zernio_profile_id,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // RLS (defense-in-depth) — mesma política fail-open das outras tabelas tenant-scoped.
        if (DB::getDriverName() === 'pgsql') {
            $cond = "current_setting('app.current_tenant', true) IS NULL "
                  ."OR current_setting('app.current_tenant', true) = '' "
                  ."OR tenant_id = current_setting('app.current_tenant', true)::bigint";
            DB::statement('ALTER TABLE profiles ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE profiles FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON profiles');
            DB::statement("CREATE POLICY tenant_isolation ON profiles USING ({$cond}) WITH CHECK ({$cond})");
            // Acesso do role de aplicação (não-privilegiado) à nova tabela + sua sequence.
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON profiles TO reachyn_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON SEQUENCE profiles_id_seq TO reachyn_app');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON profiles');
        }
        Schema::dropIfExists('profiles');
    }
};
