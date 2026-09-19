<?php

namespace App\Services;

use App\Jobs\GenerateCharacterJob;
use App\Jobs\GenerateElementJob;
use App\Jobs\GenerateScenarioJob;
use App\Models\Character;
use App\Models\Element;
use App\Models\GenModel;
use App\Models\Project;
use App\Models\Scenario;
use App\Models\Tenant;
use App\Support\AssetPrompt;
use App\Support\GenPayload;

/**
 * BOTÃO ÚNICO do Roteiro — gera de uma vez a imagem-âncora de TODO personagem, cenário e elemento
 * que as cenas da história citam e que ainda não tem imagem.
 *
 * Por que existe (pedido do Luciano, 2026-08-30): o `plan` já criava as FICHAS (texto), mas dar
 * rosto a elas era abrir três abas e clicar item por item — dez cliques antes de conseguir montar
 * qualquer coisa, e cada aba com o seu modelo e o seu estilo, o que fazia a mesma história sair
 * com três estéticas. Aqui o modelo e a técnica são os da HISTÓRIA: um valor, herdado por todos.
 *
 * Não regera o que já tem imagem (refazer é decisão do autor, na aba do asset) e não toca no que
 * está gerando agora. Quando a cota acaba no meio, PARA e devolve o que conseguiu — melhor meia
 * biblioteca pronta e um aviso claro do que estourar 402 sem ter feito nada.
 */
class ElencoDaHistoria
{
    /** Mesmo default t2i das três abas: âncora de identidade gera 1× por asset — qualidade > custo. */
    private const MODEL_T2I = 'img-ultra';

    public function __construct(private UsageService $usage) {}

    /**
     * @return array{personagens:int,cenarios:int,elementos:int,prontos:int,cota:bool,modelo:?string,estilo:string}
     *                                                                                                              `prontos` = os que já tinham imagem (pulados); `cota` = true se parou por limite do plano.
     */
    public function gerarFaltantes(Project $p): array
    {
        $tenant = $p->tenant;
        $style = AssetPrompt::normStyle($p->image_style);
        $gm = ($p->image_model ? GenModel::resolveSelectable($p->image_model, 'image', $tenant->plan) : null)
            ?? GenModel::resolveSelectable(self::MODEL_T2I, 'image', $tenant->plan)
            // O default preferido pode estar desativado (foi o que aconteceu com img-ultra): o
            // fallback final é o TOPO real do catálogo, não um slug fixo que pode morrer.
            ?? GenModel::topoDe('image', 'text_to_image', $tenant->plan);

        // O elenco da HISTÓRIA, não a biblioteca inteira do tenant: só o que as cenas deste projeto
        // citam. Sem este recorte o botão gastaria crédito com asset de outro filme.
        $chars = $cens = $elems = [];
        foreach ($p->scenes as $sc) {
            foreach ((array) $sc->character_ids as $id) {
                $chars[(int) $id] = true;
            }
            foreach ((array) $sc->element_ids as $id) {
                $elems[(int) $id] = true;
            }
            if ($sc->scenario_id) {
                $cens[(int) $sc->scenario_id] = true;
            }
        }

        $out = ['personagens' => 0, 'cenarios' => 0, 'elementos' => 0, 'prontos' => 0, 'cota' => false,
            'modelo' => $gm?->slug, 'estilo' => $style];

        foreach (Character::where('tenant_id', $tenant->id)->whereIn('id', array_keys($chars))->get() as $c) {
            if ($c->base_url) {
                $out['prontos']++;

                continue;
            }
            // status '' = ocioso; qualquer outro é geração em curso — reenfileirar duplicaria o gasto.
            if ($c->status !== '' && $c->status !== 'error') {
                continue;
            }
            $desc = trim((string) $c->description);
            if ($desc === '') {
                continue;   // ficha sem descrição não tem o que gerar: o prompt sairia vazio
            }
            if (! $res = $this->reservar($tenant, $gm)) {
                $out['cota'] = true;
                break;
            }
            [$weight, $custo] = $res;
            $payload = $this->payload(AssetPrompt::personagem($desc, $style), '3:4', $style, $gm);
            $c->update(['style' => $style, 'status' => 'base', 'image_model' => $gm?->slug]);
            GenerateCharacterJob::dispatch($c->id, $tenant->id, 'base', $payload, $weight, $custo);
            $out['personagens']++;
        }

        foreach (Scenario::where('tenant_id', $tenant->id)->whereIn('id', array_keys($cens))->get() as $s) {
            if ($s->image_url) {
                $out['prontos']++;

                continue;
            }
            if ($s->isBusy()) {
                continue;
            }
            $desc = trim((string) $s->description);
            if ($desc === '') {
                continue;
            }
            if (! $res = $this->reservar($tenant, $gm)) {
                $out['cota'] = true;
                break;
            }
            [$weight, $custo] = $res;
            $payload = $this->payload(AssetPrompt::cenario($desc), '16:9', $style, $gm);
            $s->update(['style' => $style, 'status' => 'base', 'image_model' => $gm?->slug]);
            GenerateScenarioJob::dispatch($s->id, $tenant->id, $payload, $weight, $custo);
            $out['cenarios']++;
        }

        foreach (Element::where('tenant_id', $tenant->id)->whereIn('id', array_keys($elems))->get() as $e) {
            if ($e->image_url) {
                $out['prontos']++;

                continue;
            }
            if ($e->status === 'base') {
                continue;
            }
            $desc = trim((string) $e->description);
            if ($desc === '') {
                continue;
            }
            if (! $res = $this->reservar($tenant, $gm)) {
                $out['cota'] = true;
                break;
            }
            [$weight, $custo] = $res;
            $payload = $this->payload(AssetPrompt::elemento($desc), '1:1', $style, $gm);
            $e->update(['style' => $style, 'status' => 'base', 'image_model' => $gm?->slug]);
            GenerateElementJob::dispatch($e->id, $tenant->id, $payload, $weight, $custo);
            $out['elementos']++;
        }

        return $out;
    }

    /** Reserva uma unidade da cota de imagem. null = plano estourou (quem chama para o laço). */
    private function reservar(Tenant $tenant, ?GenModel $gm): ?array
    {
        $weight = $this->usage->weightFor('image');

        return $this->usage->tryConsume($tenant, 'image', $weight, $gm?->cost_credits)
            ? [$weight, $gm?->cost_credits]
            : null;
    }

    /** Body do /v1/image. O `style` viaja separado: quem cola prefixo e sufixo da técnica é o engine. */
    private function payload(string $prompt, string $aspect, string $style, ?GenModel $gm): array
    {
        $payload = ['prompt' => $prompt, 'aspect' => $aspect, 'style' => $style];

        return $gm ? array_merge($payload, GenPayload::imagePayloadBase($gm, GenPayload::quality($gm, null))) : $payload;
    }
}
