<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOTIVO da malha — o texto que acompanha `mesh_status` ('aviso' | 'erro').
 *
 * POR QUE EXISTE: o engine já produzia a mensagem CERTA e o console a jogava fora. Flagrado no
 * teste de 2026-08-02 (personagem "Drone de Segurança Alpha"): o juiz de identidade reprovou as
 * três fichas com o motivo exato — "includes human-like legs which are not part of the described
 * octocopter drone", porque o lock dizia "octocopter drone" e a imagem-base mostrava esse drone
 * sobre pernas humanas. O `GenerateMeshJob` gravava só `mesh_status = 'erro'` e mandava o texto
 * pro log; na tela sobrava "A última geração de malha falhou", que não diz o que fazer. O usuário
 * clicaria "gerar de novo" pra sempre, porque o problema não estava na geração, estava no dado.
 *
 * O mesmo buraco existia no caminho FELIZ: quando o juiz entrega a malha COM RESSALVA
 * (`mesh_status = 'aviso'` — pedestal, parte faltando), a ressalva também só ia pro log. Uma
 * coluna serve aos dois: é sempre "o que o juiz disse sobre esta malha".
 *
 * `text` e não `string(n)`: a mensagem vem do juiz de visão, em linguagem natural, e um teto
 * arbitrário só truncaria a parte útil — que costuma estar no fim da frase.
 *
 * Idempotente, como a irmã que criou `mesh_status`.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tabelas = ['characters', 'elements'];

    public function up(): void
    {
        foreach ($this->tabelas as $tabela) {
            if (! Schema::hasTable($tabela) || Schema::hasColumn($tabela, 'mesh_msg')) {
                continue;
            }
            Schema::table($tabela, function (Blueprint $t) {
                $t->text('mesh_msg')->nullable()->after('mesh_status');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tabelas as $tabela) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'mesh_msg')) {
                Schema::table($tabela, function (Blueprint $t) {
                    $t->dropColumn('mesh_msg');
                });
            }
        }
    }
};
