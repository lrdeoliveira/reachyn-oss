<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CATÁLOGO DE ELEMENTOS — o carro, a cadeira, a mala, o cachorro de rua.
 *
 * O BURACO QUE FECHA: personagem e cenário tinham biblioteca com imagem-âncora, e por isso
 * atravessavam o filme sem mudar de cara. Objeto não tinha casa nenhuma — o `📦 Molde: Objeto`
 * (2026-07-26) descrevia o carro como se fosse um personagem, e nada garantia que o carro da cena
 * 2 fosse o carro da cena 7. Aqui o objeto ganha a mesma mecânica: ficha + imagem que viaja como
 * referência para toda cena que o usa. Ideia lida no Catalog do Celtx, onde todo ativo de produção
 * é indexado por departamento e reaproveitado pelas cenas.
 *
 * `scenes.element_ids` é json, igual a `character_ids`: a cena aponta quem entra nela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            // Categoria = o departamento do Celtx, enxugado pro que um filme gerado por IA usa.
            $t->string('categoria', 24)->default('prop');
            $t->text('description')->nullable();
            $t->string('image_url')->nullable();
            $t->string('image_model', 60)->nullable();
            $t->string('status', 16)->default('');   // '' | 'base' | 'error' (mesma régua do cenário)
            $t->timestamps();
            $t->index(['tenant_id', 'categoria']);
        });

        Schema::table('scenes', function (Blueprint $t) {
            $t->json('element_ids')->nullable()->after('character_ids');
        });
    }

    public function down(): void
    {
        Schema::table('scenes', function (Blueprint $t) {
            $t->dropColumn('element_ids');
        });
        Schema::dropIfExists('elements');
    }
};
