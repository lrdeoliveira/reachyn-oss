<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1 do fluxo image→cena (PLANO-PERSONAGENS §3.1): a camada de FICHA metodológica antes da
 * geração de imagem. O `bible` (JSON) e o `lock` já existem — aqui só entram as duas colunas
 * LEVES que a listagem/filtro usa (o resto é forma canônica dentro do bible):
 *
 *   archetype: papel do curso (heroi|mentor|guardiao_limiar|arauto|camaleao|sombra|picaro) — filtro.
 *   logline:   1 frase "quem quer o quê, contra o quê" — mostrada na lista sem abrir a ficha.
 *
 * Forma canônica do bible (Tema 2 do curso — desejo/McKee + conflito + arco/Vogler):
 *   { desejo:{objetivo,subjetivo}, conflito:{tipo,natureza,descricao}, antagonista,
 *     fisico:{nome,apelido,idade,sexo,aparencia,voz:{timbre,ritmo,tique,vocabulario}},
 *     psicologico:{personalidade,passado,valores,reflexo_fisico},
 *     arco:{quer_longo,quer_agora,obstaculo,como_supera,nova_situacao,muda_objetivo,
 *           afasta_aproxima,enfrenta_depois} }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('archetype', 24)->nullable()->after('style');
            $table->string('logline', 240)->nullable()->after('archetype');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn(['archetype', 'logline']);
        });
    }
};
