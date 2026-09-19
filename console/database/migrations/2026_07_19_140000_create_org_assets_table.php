<?php

use App\Support\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * org_assets — Hub "My Assets" (reuso de mídia da marca, benchmark Nordy My Asset). Diferente das
 * mídias soltas do rascunho: aqui o usuário GUARDA o que gostou (imagem/vídeo/áudio/keyframe/logo)
 * pra reusar em outro projeto (como `imageUrl`/ref) — favoritável, filtrável.
 *
 * Por-marca clássica → BelongsToTenant no model + RLS (12ª→13ª tabela do verify-db-isolation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // quem guardou
            $table->string('kind', 16);    // image|video|audio|keyframe|logo|other
            $table->string('source', 16);  // upload|generation|easyapp|import
            $table->string('url', 500);    // só domínio próprio (S3)
            $table->string('thumb_url', 500)->nullable();
            $table->json('meta')->nullable(); // {draft_id?, project_id?, character_id?, prompt?, easyapp?}
            $table->boolean('favorite')->default(false);
            $table->timestamps();
            $table->index(['tenant_id', 'kind']);
            $table->index(['tenant_id', 'favorite']);
            $table->index(['tenant_id', 'created_at']);
        });

        // RLS (defense-in-depth): o banco barra vazamento entre marcas mesmo se o TenantScope falhar.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (! DB::selectOne('SELECT to_regclass(?) AS t', ['public.org_assets'])->t) {
            return;
        }
        TenantRls::protect('org_assets');
        if (DB::selectOne("SELECT 1 FROM pg_roles WHERE rolname = 'reachyn_app'")) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON org_assets TO reachyn_app');
            DB::statement('GRANT USAGE, SELECT, UPDATE ON SEQUENCE org_assets_id_seq TO reachyn_app');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON org_assets');
        }
        Schema::dropIfExists('org_assets');
    }
};
