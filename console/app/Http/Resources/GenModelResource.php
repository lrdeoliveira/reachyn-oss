<?php

namespace App\Http\Resources;

use App\Models\GenModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tudo que o front PODE ver de um modelo. White-label:
 * provider / provider_model_id / provider_endpoint / cost_basis_micro NUNCA entram aqui
 * (o cliente não sabe qual provedor/endpoint roda por baixo — guideline #6).
 *
 * @mixin GenModel
 */
class GenModelResource extends JsonResource
{
    public function toArray($request): array
    {
        $out = [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'kind' => $this->kind,          // video | image | audio | text
            'subtype' => $this->subtype,
            'cost_credits' => $this->cost_credits,
            'capabilities' => $this->publicCapabilities(),
            'is_unstable' => $this->is_unstable,   // alimenta o banner "Instabilidade"
            // O MOTIVO é a metade acionável do aviso: "instável" sozinho não diz se dá pra usar
            // assim mesmo. Texto white-label (escrito no admin) — nunca nomeia provedor.
            'unstable_reason' => $this->unstable_reason,
            'min_plan' => $this->min_plan,      // null = todos os planos; senão badge "requer X" + filtro premium na UI
        ];
        // Qualidades (resolução) com preço por duração — pro seletor de qualidade + preço ao vivo (v2).
        // SÓ o público (key/label/p5/p10); o `extra` (resolução/mode reais do motor) fica interno (white-label).
        $qs = $this->capabilities[$this->provider]['qualities'] ?? $this->capabilities['kie']['qualities'] ?? null;
        if (is_array($qs) && $qs) {
            $out['qualities'] = array_map(fn ($q) => [
                'key' => $q['key'] ?? '', 'label' => $q['label'] ?? '',
                'p5' => $q['p5'] ?? null, 'p10' => $q['p10'] ?? null, // vídeo (por duração)
                'p' => $q['p'] ?? null,                               // imagem (preço único)
            ], $qs);
            $out['default_quality'] = $this->capabilities[$this->provider]['default_quality'] ?? $this->capabilities['kie']['default_quality'] ?? ($qs[0]['key'] ?? null);
        }
        // FILME (plano-sequência): o modelo aceita PRIMEIRO+ÚLTIMO frame (refs em array)? Flag
        // booleana derivada — pública e white-label (não revela provedor nem campos internos).
        // true → modo keyframe (sem drift, paralelo); false → modo corrente (mais barato, serial).
        if ($this->kind === 'video') {
            $kie = $this->capabilities[$this->provider] ?? $this->capabilities['kie'] ?? [];
            $out['film_tail'] = $this->resource->isTailCapable(); // regra única em GenModel::isTailCapable
            // ⚡ Filme rápido (Sprint D): o modelo suporta multi_shots (vários cortes numa ÚNICA
            // geração — mais barato, sem keyframes intermediários). Flag pública derivada.
            // multi_shots (vários cortes numa geração só) saiu com o agregador: nenhum motor
            // atual expõe o modo. Fica FALSO em vez de sumir do contrato — o front lê a chave, e
            // ausência viraria `undefined`, que em JS é falsy por acidente e não por decisão.
            $out['multi_shots'] = false;
        }
        // own_account: roda numa conta PRÓPRIA pré-paga, não no agregador principal — não some
        // quando o saldo do agregador zera (caso real 2026-07-17). White-label: não citar o
        // provedor, só sinalizar a independência pro operador escolher em contingência.
        if (in_array($this->kind, ['video', 'image'], true)) {
            $out['own_account'] = $this->provider === 'minimax';
        }
        // ONDE a peça é gerada. É um LUGAR, não um provedor — por isso é público e white-label
        // seguro (o cliente lê "☁️ Nuvem" / "💻 Este Mac", nunca o nome de uma empresa de IA).
        // O front (lib/motor.ts) já esperava este campo desde 2026-07-30, mas ele nunca foi
        // emitido: sem ele `lugarDe()` caía no default "nuvem" e TODOS os motores apareciam como
        // ☁️, inclusive os que rodam por bridge nesta máquina e não cobram crédito por peça.
        $out['runs_on'] = self::lugarDe(
            (string) ($this->provider ?? ''),
            (string) $this->slug,
            (int) ($this->cost_credits ?? 0),
        );

        // OPERADOR (RedFoxCode) vê o modelo REAL por baixo — só pra facilitar a escolha interna.
        // O CLIENTE NUNCA recebe isto (white-label #6). Só o NOME BASE do modelo, sem o provedor
        // (o Luciano pediu tirar o "kie ·"/"minimax ·"). Ex.: "image-01", "nano-banana-2", "kling-3.0/video".
        $u = $request->user();
        if ($u && method_exists($u, 'isOperator') && $u->isOperator()) {
            $out['real_name'] = trim((string) ($this->provider_model_id ?? ''));
            // `origem` NOMEIA a conta de onde a peça sai (KIE, Higgsfield, Mac…) — e é a conta que
            // acaba (2026-07-20: o saldo do KIE zerou e o sintoma chegou como "geração falhando").
            // Nomeia empresa ⇒ mesmo tratamento do real_name: só operador (white-label #6).
            if ($o = self::origemDe((string) ($this->provider ?? ''), (string) $this->slug)) {
                $out['origem'] = $o;
            }
        }

        return $out;
    }

    /** LUGAR onde o motor roda — espelha MotorLugar em web/lib/motor.ts.
     *
     *  'nuvem'   serviço de geração: sempre disponível, cobra crédito por peça.
     *  'estudio' o ComfyUI do operador (nesta máquina ou num Colab): GPU em vez de crédito, mas
     *            só funciona com o servidor de pé.
     *  'mac'     o que roda por bridge nesta máquina (CLI de imagem): usa assinatura, não crédito.
     *
     *  Default 'nuvem' é o erro SEGURO: no máximo o rótulo fica genérico — nunca promete que algo
     *  é grátis quando cobra.
     *
     *  ⚠️ O RÓTULO SEGUE O PREÇO, NÃO O PROVEDOR. O texto de 'mac' na tela diz "usa sua assinatura
     *  e o hardware daqui, NÃO crédito" — então só pode valer para modelo que de fato não cobra.
     *  Mandar todo `cli-bridge` para 'mac' quebrava exatamente isso: os 15 modelos de assinatura
     *  ativados em 2026-08-03 custam de 1 a 90 créditos e apareciam anunciados como gratuitos
     *  (bug achado na auditoria do mesmo dia). Cobra crédito ⇒ 'nuvem', venha de onde vier. */
    private static function lugarDe(string $provider, string $slug, int $custo): string
    {
        if ($provider === 'comfy' || $provider === 'comfyui' || str_contains($slug, '-local-')) {
            return 'estudio';
        }
        if ($provider === 'cli-bridge' && $custo <= 0) {
            return 'mac'; // bridge SEM cobrança por peça: aí sim é assinatura/hardware, não crédito
        }

        return 'nuvem';
    }

    /** Nome da CONTA de onde a peça sai (só operador). As chaves batem com ICONE_ORIGEM em
     *  web/lib/motor.ts — origem sem ícone lá degrada pro ícone do lugar, então acrescentar
     *  provider novo aqui não quebra a tela. */
    private static function origemDe(string $provider, string $slug): ?string
    {
        if ($provider === 'comfy' || $provider === 'comfyui' || str_contains($slug, '-local-')) {
            return 'ComfyUI';
        }
        if ($provider === 'cli-bridge') {
            // O bridge atende contas diferentes: as de assinatura própria (Higgsfield) e as CLIs
            // da máquina (mmx/cursor). Saldos separados ⇒ nomes separados.
            return str_contains($slug, 'higgsfield') ? 'Higgsfield' : 'Mac';
        }

        return match ($provider) {
            'magnific' => 'Magnific',
            'minimax' => 'MiniMax',
            'google' => 'Google',
            'elevenlabs' => 'ElevenLabs',
            default => null,
        };
    }

    /** Remove do cliente o que é INTERNO: pista de provedor ('upstream') e os specs de
     *  roteamento (campos de input/model de cada motor). Só o público (task_types etc) sai.
     *
     *  A lista é EXPLÍCITA e inclui a chave do agregador aposentado: linhas antigas do catálogo
     *  ainda a carregam, e vazar `kie.refs_field` pro cliente entregaria de graça o mapa interno
     *  de roteamento — o oposto do white-label (#6). Motor novo entra AQUI junto com o provider. */
    private function publicCapabilities(): array
    {
        $caps = $this->capabilities ?? [];
        unset($caps['upstream'], $caps['kie'], $caps['magnific']);

        return $caps;
    }
}
