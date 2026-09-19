<?php

namespace App\Jobs;

use App\Models\AssetVersion;
use App\Models\Tenant;
use App\Services\UsageService;
use App\Support\EngineClient;
use App\Support\GaleriaMalha;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * MALHA 3D assíncrona (POST /v1/mesh/generate no Estúdio Local).
 *
 * POR QUE ASSÍNCRONO: até 2026-08-02 o controller chamava o engine DENTRO do request e segurava a
 * conexão HTTP durante toda a geração. Medido em produção: ~285s numa geração e ~106s de sobra
 * numa outra — o clique das 14:38 virou HTTP 499 no nginx (o navegador desistiu e fechou a
 * conexão) enquanto o engine só terminou às 14:40. Nenhum navegador, proxy ou balanceador espera
 * 4-5 minutos: síncrono aqui é falha garantida, e a GPU ficava produzindo pra ninguém recolher.
 * Agora o worker faz a chamada longa, grava `mesh_url` e o front acompanha por `mesh_status` —
 * mesmo desenho do GenerateShotFrameJob / GenerateElementJob.
 *
 * COBRANÇA (reserve-then-consume): a cota é reservada no controller ANTES de enfileirar e
 * estornada aqui quando a geração não entrega — o cliente nunca paga por malha que não recebeu.
 * Hoje o gerador roda no Estúdio Local (GPU própria, custo de provedor zero), então o custo
 * reservado é 0; a plumbing existe para o dia em que a malha for roteada por API paga.
 */
class GenerateMeshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** > 660s do HTTP no Estúdio Local (que por dentro espera os 600s do ComfyUI): quem está por
     *  fora sempre espera mais que quem está por dentro, senão cortamos o trabalho já pago. */
    public int $timeout = 720;

    /** Sem retry: cada tentativa é mais um ciclo inteiro de GPU e o resultado de uma malha que
     *  falhou raramente muda no automático — quem decide regerar é o usuário. */
    public int $tries = 1;

    public function __construct(
        public string $tipo,        // 'character' | 'element'
        public int $donoId,
        public int $tenantId,
        public array $payload,      // body do /v1/mesh/generate (imageUrl + lock/style)
        public int $weight = 1,
        public ?int $costCredits = null,
    ) {}

    public function handle(UsageService $usage): void
    {
        $dono = $this->dono();
        if (! $dono) {
            $this->refund($usage);

            return;
        }

        $res = EngineClient::make(EngineClient::TIMEOUT_ESTUDIO_LOCAL)->post('/v1/mesh/generate', $this->payload);
        $url = $res->successful() ? (string) $res->json('url') : '';

        if ($url === '') {
            // O MOTIVO vem junto: o engine classifica o erro (gerr) e, quando ele é acionável pelo
            // usuário — 4xx, tipicamente "a descrição do personagem e a imagem-base não combinam" —
            // manda a frase que resolve. Guardá-la é o que separa "falhou, tente de novo" (que faz
            // o usuário reclicar num problema que não está na geração) de "isto aqui não fecha,
            // ajuste X". Em 5xx a mensagem é genérica de propósito, então não vale a pena mostrar.
            $motivo = $res->status() < 500 ? trim((string) $res->json('error')) : '';
            Log::warning('GenerateMeshJob: geração sem URL', [
                'tipo' => $this->tipo, 'id' => $this->donoId, 'status' => $res->status(), 'motivo' => $motivo,
            ]);
            $this->refund($usage);
            $dono->update(['mesh_status' => 'erro', 'mesh_msg' => $motivo ?: null]);

            return;
        }

        // `aviso` = veredito do juiz de visão do engine (malha entregue mas com ressalva: pedestal,
        // parte faltando). Não é falha — a malha vale, e a decisão de regerar é do usuário. Vai como
        // status próprio pra tela poder avisar sem confundir com erro.
        $aviso = trim((string) $res->json('aviso'));
        // A ressalva vai pro banco junto com o status: sem o texto, "aviso" na tela é um alerta
        // sem conteúdo — o usuário sabe que tem ALGO errado com a malha e não sabe o quê.
        $dono->update([
            'mesh_url' => $url,
            'mesh_status' => $aviso !== '' ? 'aviso' : null,
            'mesh_msg' => $aviso ?: null,
        ]);

        if ($aviso !== '') {
            Log::info('[mesh] malha entregue com ressalva do juiz', ['tipo' => $this->tipo, 'id' => $this->donoId, 'aviso' => $aviso]);
        }

        GaleriaMalha::publica($this->tenantId, $this->tipo, $this->donoId, $dono->name ?? null, $url);
    }

    /** Falha DEFINITIVA (exceção, timeout do worker): estorna e deixa o estado visível na tela. */
    public function failed(\Throwable $e): void
    {
        Log::warning('GenerateMeshJob falhou', ['tipo' => $this->tipo, 'id' => $this->donoId, 'error' => $e->getMessage()]);
        $this->refund(app(UsageService::class));
        // Aqui a exceção é infraestrutural (timeout do worker, conexão caída) e a mensagem técnica
        // não ajudaria ninguém na tela — limpa o motivo anterior pra não exibir o de outra rodada.
        $this->dono()?->update(['mesh_status' => 'erro', 'mesh_msg' => null]);
    }

    /** O dono da malha (personagem ou elemento) — a mesma tabela de tipos do AssetVersion. */
    private function dono(): ?object
    {
        $classe = AssetVersion::DONOS[$this->tipo] ?? null;

        return $classe ? $classe::find($this->donoId) : null;
    }

    private function refund(UsageService $usage): void
    {
        if ($t = Tenant::find($this->tenantId)) {
            $usage->refund($t, 'image', $this->weight, $this->costCredits);
        }
    }
}
