<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GenModelResource;
use App\Models\GenModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Catálogo de modelos disponível pro cliente (web/Next.js).
 * Lista SÓ os modelos ativos, filtrados pelo plano do tenant, agrupáveis por `kind`
 * (video/image/audio/text). White-label garantido pelo GenModelResource.
 */
class GenModelController extends Controller
{
    /**
     * GET /api/gen-models?kind=video&uso=geracao — modelos ativos do plano, ordenados.
     *
     * `uso` separa GERAR de TRATAR e é 'geracao' por omissão: quem não pede nada continua
     * recebendo só o que cria peça do zero. Sem esse default, ligar a pós-produção no catálogo
     * (upscale, remover fundo, expandir, deflicker) despejaria as ferramentas dentro dos
     * seletores de geração — o cliente escolheria "Upscale" esperando uma imagem nova.
     * A galeria pede `uso=postproducao` para montar o menu de tratamento.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $plan = $request->user()->tenant->plan ?? null;

        $models = GenModel::active()
            ->when($request->filled('kind'), fn ($q) => $q->kind($request->string('kind')))
            ->uso((string) $request->string('uso', 'geracao'))
            ->forPlan($plan)
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->get();

        return GenModelResource::collection($models);
    }
}
