<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ESTADO DA GERAÇÃO DE MALHA — `mesh_status` ('gerando' | 'erro' | null).
 *
 * POR QUE EXISTE: a geração de malha era SÍNCRONA (o POST /api/mesh-generate segurava a
 * requisição HTTP durante toda a geração). Medido em produção: ~4 a 5 minutos por malha —
 * o navegador desistia antes e o nginx registrava HTTP 499 (cliente fechou a conexão),
 * enquanto a GPU seguia trabalhando pra ninguém. Agora a geração vai pra fila e o front
 * descobre o fim por POLLING, exatamente como já acontece com imagem lenta, vídeo e música.
 *
 * POR QUE UM CAMPO NOVO (e não o `status` que já existe): `status` é a máquina de estados da
 * geração de IMAGEM do asset ('base' | 'sheet' | 'edit'). Reaproveitá-lo faria o personagem
 * parecer "gerando a imagem-base" enquanto na verdade gera a malha, travando os botões errados.
 * Estados independentes = colunas independentes. Mesma convenção do `shots.quadro_status`.
 *
 * Idempotente: só cria a coluna se ela ainda não existir (a migration pode reencontrar um banco
 * onde ela já foi aplicada à mão).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabelas = ['characters', 'elements'];

    public function up(): void
    {
        foreach ($this->tabelas as $tabela) {
            if (! Schema::hasTable($tabela) || Schema::hasColumn($tabela, 'mesh_status')) {
                continue;
            }
            Schema::table($tabela, function (Blueprint $t) {
                $t->string('mesh_status', 20)->nullable()->after('mesh_url');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabelas as $tabela) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'mesh_status')) {
                Schema::table($tabela, function (Blueprint $t) {
                    $t->dropColumn('mesh_status');
                });
            }
        }
    }
};
