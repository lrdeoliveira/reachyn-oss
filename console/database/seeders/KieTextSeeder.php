<?php

namespace Database\Seeders;

use App\Models\GenModel;
use Illuminate\Database\Seeder;

/**
 * Modelos de TEXTO (kind='text') — seletor de qualidade do fluxo Pesquisa→Resumo→Conteúdo,
 * roteiro de História/Filme e bíblia de Personagem (pedido Luciano 2026-07-10, cobrado por modelo).
 *
 * provider = 'kie'; provider_model_id = o model da Claude Messages API do agregador (validados
 * com chamada real em 2026-07-10: claude-* / gemini-3-flash|pro / gpt-5-2 — 2026-07-10). O engine roteia por FAMÍLIA: claude-* via Messages API, demais via chat completions OpenAI-compatible. O console
 * resolve slug→pmid e manda ao engine em gen_lines.text.model (o cliente NUNCA vê o provedor).
 *
 * Custos (cost_credits) = custo real estimado ÷ US$0,005 por operação típica de resumo/roteiro
 * (~16k in + 1,6k out). Idempotente e NÃO-DESTRUTIVO (calibração do operador só no 1º insert).
 */
class KieTextSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            ['slug' => 'txt-rapido', 'name' => 'Rápido', 'pmid' => 'claude-haiku-4-5',
                'cost' => 1, 'basis' => 5000, 'sort' => 0],
            ['slug' => 'txt-versatil', 'name' => 'Versátil', 'pmid' => 'gemini-3-flash',
                'cost' => 1, 'basis' => 5000, 'sort' => 1],
            ['slug' => 'txt-criativo', 'name' => 'Criativo', 'pmid' => 'gpt-5-2',
                'cost' => 3, 'basis' => 15000, 'sort' => 2],
            ['slug' => 'txt-profundo', 'name' => 'Profundo', 'pmid' => 'gemini-3-pro',
                'cost' => 3, 'basis' => 15000, 'sort' => 3],
            ['slug' => 'txt-equilibrado', 'name' => 'Equilibrado', 'pmid' => 'claude-sonnet-5',
                'cost' => 5, 'basis' => 25000, 'sort' => 4],
            ['slug' => 'txt-avancado', 'name' => 'Avançado', 'pmid' => 'claude-opus-4-8',
                'cost' => 10, 'basis' => 50000, 'sort' => 5],
            ['slug' => 'txt-topo', 'name' => 'Topo', 'pmid' => 'claude-fable-5',
                'cost' => 20, 'basis' => 100000, 'sort' => 6],
        ];

        foreach ($models as $m) {
            $gm = GenModel::firstOrNew(['slug' => $m['slug']]);
            $gm->fill([
                'display_name' => $m['name'],
                'kind' => 'text',
                'subtype' => 'chat',
                'provider' => 'kie',
                'provider_model_id' => $m['pmid'],
                'capabilities' => ['task_types' => ['Text']],
            ]);
            if (! $gm->exists) {
                $gm->cost_credits = $m['cost'];
                $gm->cost_basis_micro = $m['basis'];
                $gm->is_active = true; // strings já validadas com chamada real
                $gm->sort_order = $m['sort'];
            }
            $gm->save();
        }
    }
}
