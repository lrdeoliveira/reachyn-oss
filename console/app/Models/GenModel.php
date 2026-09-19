<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de geração selecionável (catálogo do operador). Global, igual ProviderKey.
 * Organizado por `kind` (video/image/audio/text).
 * provider / provider_model_id / provider_endpoint / cost_basis_micro = INTERNOS
 * (white-label: NUNCA expostos pro cliente — ver GenModelResource).
 *
 * @property array<string,mixed> $capabilities
 */
class GenModel extends Model
{
    use HasFactory;

    public const KINDS = ['video', 'image', 'audio', 'text'];

    // Ordem dos planos (do menor pro maior tier). `min_plan` é o tier MÍNIMO: um plano libera todo
    // modelo cujo min_plan esteja no seu tier OU abaixo (threshold, NÃO igualdade). Espelha as chaves
    // de Organization::PLAN_LIMITS. Manter em sincronia se um tier novo entrar.
    public const PLAN_ORDER = ['starter', 'pro', 'studio', 'enterprise', 'unlimited'];

    // Modelo t2i PADRÃO — o que responde quando o cliente não escolheu (ou escolheu algo que não
    // resolve pro plano dele). Fonte ÚNICA: StudioController, FilmController e AnimationFlow
    // apontam pra cá, e não pro slug cru.
    //
    // Desde 2026-07-20 é o img-cli-mmx (CLI de assinatura, custo marginal ZERO) e não mais o
    // img-padrao (API image-01, US$0,0035/img) — são o MESMO modelo por baixo, mudou só o
    // transporte. O img-padrao continua no catálogo, inativo, como contingência.
    //
    // ⚠️ Isso põe o sidecar do bridge no caminho crítico do t2i: bridge fora do ar = t2i fora do
    // ar (não há fallback cross-provider, é decisão explícita — ver content.go). Se o bridge cair,
    // a saída é reativar o img-padrao no Filament e apontar esta constante de volta pra ele.
    public const DEFAULT_T2I = 'img-cli-mmx';

    protected $fillable = [
        'slug', 'display_name', 'kind', 'subtype',
        'provider', 'provider_model_id', 'provider_endpoint',
        'cost_credits', 'cost_basis_micro', 'capabilities',
        'is_active', 'is_unstable', 'unstable_reason', 'health_checked_at',
        'min_plan', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'is_unstable' => 'boolean',
            'health_checked_at' => 'datetime',
        ];
    }

    /** Modelo da geração i2i (com imagem de referência). Fonte ÚNICA — o par
     *  `resolveSelectable('img-referencia') ?: resolveSelectable(DEFAULT_T2I)` estava copiado em 14
     *  lugares entre FilmController, AnimationController e StudioController.
     *
     *  ⚠️ `$fallbackT2I` existe porque os chamadores NÃO concordam entre si hoje, e a divergência é
     *  substantiva — não cosmética. Cair no DEFAULT_T2I numa operação i2i significa usar um modelo
     *  *text-to-image* num pedido que depende das referências: ele pode ignorá-las em silêncio e
     *  devolver uma imagem nova, em vez da edição pedida. `FilmController::keyframeEdit` (edição
     *  pura) NÃO faz esse fallback; os outros 5 sites do Filme fazem. Sem o parâmetro, unificar
     *  significaria escolher um dos dois comportamentos às cegas para todo mundo.
     *
     *  O default `true` preserva o comportamento da maioria. Qual dos dois está certo é decisão de
     *  produto pendente — quando for tomada, o parâmetro some. */
    public static function referenceImageModel(?string $plan, bool $fallbackT2I = true): ?self
    {
        $gm = self::resolveSelectable('img-referencia', 'image', $plan);
        if ($gm || ! $fallbackT2I) {
            return $gm;
        }

        return self::resolveSelectable(self::DEFAULT_T2I, 'image', $plan);
    }

    /**
     * Gera CLIPE a partir de um keyframe (i2v ancorado)? É o contrato de /v1/filmclip — o Filme e
     * cada cena do Estúdio de Animação partem de uma imagem que já existe.
     *
     * Fonte ÚNICA da regra (FilmController e AnimationFlow chamam aqui — não duplicar). Espelha o
     * que o engine sabe rotear em clipModelOrdered: `cli-bridge`, `magnific` e `minimax`. Fora disso:
     *   - `google` (Veo) é t2v de clipe curto — não parte de keyframe. Vale no Estúdio (/v1/veo),
     *     nunca aqui.
     *   - o caminho nativo legado saiu do engine em 22/07 (conta encerrada em 13/07).
     *
     * ⚠️ Sem este filtro a Animação aceitava vid-premium (google) e a chamada morria com 403 de
     * saldo no roteamento legado — projeto 19, 6 cenas perdidas. O Filme já filtrava; a Animação não.
     */
    public function isClipCapable(): bool
    {
        return in_array($this->provider, ['cli-bridge', 'magnific', 'minimax'], true);
    }

    /** Aceita PRIMEIRO+ÚLTIMO frame no i2v (refs em array — Kling image_urls[0..1])? Habilita o
     *  modo keyframe do Filme e o plano-sequência do Estúdio de Animação (endImageUrl). Fonte ÚNICA
     *  da regra — FilmController, AnimationFlow e GenModelResource chamam aqui (não duplicar). */
    public function isTailCapable(): bool
    {
        $spec = (array) ($this->capabilities[$this->provider] ?? $this->capabilities['kie'] ?? []);

        // Último frame REAL só onde a semântica foi validada com geração real. "Aceita várias
        // refs" NÃO implica tail — um modelo multi-ref pode tratar a 2ª imagem como REFERÊNCIA
        // solta, e o fim do trecho sai espelhado/derivado (caso real 2026-07-16, vid-economico em
        // modo keyframe). Por isso a flag `tail` é EXPLÍCITA no catálogo, e não inferida.
        //
        // Desde a saída do agregador (2026-08-03) o modo keyframe roda no provider `magnific`.
        return $this->provider === 'magnific'
            && (($spec['refs_single'] ?? true) === false)
            && (bool) ($spec['tail'] ?? false);
    }

    /**
     * DURAÇÕES de clipe que este modelo oferece, em segundos e em ordem.
     *
     * Sai das `qualities` do catálogo (cada faixa traz o preço p5/p10, então a chave diz qual
     * duração existe) e cai em [5, 10] pro modelo sem faixa declarada. Existe porque "5 ou 10"
     * estava escrito à mão em dez lugares do console: um motor que oferecesse 8s era impossível
     * de suportar sem caçar todos eles. Quem precisa da lista pergunta ao catálogo.
     */
    public function duracoes(): array
    {
        $q = (array) ($this->capabilities[$this->provider]['qualities'] ?? $this->capabilities['kie']['qualities'] ?? []);
        $segs = [];
        foreach ($q as $faixa) {
            foreach (array_keys((array) $faixa) as $k) {
                if (preg_match('/^p(\d+)$/', (string) $k, $m)) {
                    $segs[(int) $m[1]] = true;
                }
            }
        }
        $segs = array_keys($segs);
        sort($segs);

        return $segs !== [] ? $segs : [5, 10];
    }

    /** Quantas imagens-âncora este modelo aceita por clipe.
     *
     * 0 = só texto (sem campo de referência no spec) · 1 = uma imagem-base (refs_single) ·
     * >1 = várias referências no array. O teto de 5 é o limite prático do agregador: acima
     * disso o payload é rejeitado, e cada referência ainda é cobrada.
     *
     * Fonte ÚNICA da regra (o console valida e o front desenha os slots a partir daqui) —
     * irmão do isTailCapable(), que responde outra pergunta: se a SEGUNDA imagem é o quadro
     * final ou só mais uma referência de identidade.
     */
    public function refsMax(): int
    {
        // Estúdio Local (ComfyUI): não há spec de motor — i2i recebe UMA imagem base (o workflow
        // carrega a ref via upload direto), t2i nenhuma. Sem isto, img-local-* i2i chamado
        // pela rota genérica /generate/image caía no 0 do refs_field vazio.
        // `image_to_video` (prévia de movimento, Wan 2.2) entra na MESMA regra: o nó
        // Wan22ImageToVideoLatent parte de UM quadro inicial. Sem incluí-lo aqui, o vid-local-previa
        // caía no 0 e a rota recusava a imagem com "este modelo gera só a partir de texto" — logo
        // ele, que sem imagem não tem o que animar.
        if ($this->provider === 'comfy') {
            return in_array($this->subtype, ['image_to_image', 'image_to_video'], true) ? 1 : 0;
        }
        $kie = (array) ($this->capabilities[$this->provider] ?? $this->capabilities['kie'] ?? []);
        // Veo tem caminho próprio (/v1/veo) e parte de uma imagem só.
        if ((bool) ($this->capabilities['veo'] ?? false)) {
            return 1;
        }
        // 🔑 CATÁLOGO NOVO (Higgsfield, 2026-08-02): a capacidade de receber referência é um
        // BOOL no topo (`refs`), não um `refs_field` dentro do spec do motor — o adapter do
        // bridge sabe onde pôr a imagem, então não há campo pra nomear. Sem este ramo, todo
        // modelo hf-* caía no `refs_field` vazio → refsMax()=0 → `array_slice($refs, 0, 0)`
        // DESCARTAVA as âncoras em SILÊNCIO: i2i e ancoragem de personagem viravam t2i puro,
        // sem erro, sem log, cobrando igual. Teto segue o mesmo 5 do legado; modelo que aceita
        // menos declara `refs_max` (é o que evita queimar geração, ver o caso do vid-economico).
        if (array_key_exists('refs', (array) $this->capabilities)) {
            return ((bool) $this->capabilities['refs']) ? max(1, (int) ($this->capabilities['refs_max'] ?? 5)) : 0;
        }
        if (($kie['refs_field'] ?? '') === '') {
            return 0;
        }
        if (($kie['refs_single'] ?? true)) {
            return 1;
        }

        // TETO MEDIDO do modelo, quando o provedor aceita menos que o teto do agregador. Sem
        // isto o default 5 valia pra todo multi-ref e o excedente só aparecia como falha lá na
        // ponta: o vid-economico (seedance-1.5-pro) rejeita a 3ª imagem com "expected at most
        // one last frame image content but got 2 instead" — 3 tentativas queimadas por clipe e
        // a cena voltando sem vídeo (caso real 2026-07-29, cenas 3 e 4 do piloto do farol).
        return max(1, (int) ($kie['refs_max'] ?? 5));
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeKind($q, string $kind)
    {
        return $q->where('kind', $kind);
    }

    /** Subtype que marca o que PARTE de uma peça pronta em vez de gerar do zero. */
    public const USO_POSTPRODUCAO = 'postproducao';

    /**
     * Separa GERAR de TRATAR. Upscale, remover fundo, expandir enquadramento e deflicker têm
     * `kind` image/video como qualquer modelo, mas exigem uma peça de entrada: num seletor de
     * geração eles devolvem a peça errada para quem pediu uma nova.
     *
     * `$uso = 'geracao'` (o default de quem não pede nada) devolve só o que cria do zero — é o
     * que mantém os seletores existentes intactos quando a pós-produção entra no catálogo.
     * `$uso = 'postproducao'` devolve só as ferramentas de tratamento.
     */
    public function scopeUso($q, string $uso)
    {
        return $uso === self::USO_POSTPRODUCAO
            ? $q->where('subtype', self::USO_POSTPRODUCAO)
            : $q->where(fn ($w) => $w->whereNull('subtype')->orWhere('subtype', '!=', self::USO_POSTPRODUCAO));
    }

    /** Planos cujo tier o `$plan` satisfaz: o próprio + todos ABAIXO. Plano desconhecido/null → []. */
    public static function plansAtOrBelow(?string $plan): array
    {
        $idx = $plan === null ? false : array_search($plan, self::PLAN_ORDER, true);

        return $idx === false ? [] : array_slice(self::PLAN_ORDER, 0, $idx + 1);
    }

    /**
     * Modelos liberados para um plano: min_plan NULL (todos) OU min_plan no tier do plano OU abaixo
     * (threshold de tier — um plano superior também acessa o que é liberado a tiers inferiores).
     */
    public function scopeForPlan($q, ?string $plan)
    {
        $allowed = self::plansAtOrBelow($plan);

        return $q->where(fn ($w) => $w->whereNull('min_plan')->orWhereIn('min_plan', $allowed));
    }

    /**
     * Resolve um slug de modelo VINDO DO CLIENTE num GenModel utilizável — server-side, validado.
     * O cliente só manda o `slug` (público); provider/provider_model_id/custo são resolvidos AQUI,
     * nunca confiados na request (secure-by-default). Aceita `mixed` de propósito: o input cru da
     * request pode vir como array (`model[]=…`) — nesse caso retorna null em vez de estourar TypeError.
     *
     * Usa o MESMO filtro do GenModelController (active + kind + forPlan) pra que todo modelo listado
     * pro plano seja resolvível, e nada fora dele.
     *
     * @return self|null null quando: slug vazio/não-string (cliente não escolheu → usa o default do
     *                   fluxo) OU slug inexistente/inativo/fora do plano (o controller deve recusar — 422).
     */
    /**
     * O TOPO real do catálogo de um subtype — o 1º modelo ATIVO liberado pro plano, na ordem que
     * o cliente vê no seletor.
     *
     * Existe porque os fallbacks "cai no topo do catálogo" das abas eram um SLUG FIXO no código
     * (`img-ultra`), e esse modelo foi desativado em prod ("Motor descontinuado", 03/08). Slug de
     * modelo inativo resolve pra null em silêncio: quem não escolhia modelo deixava de ter o topo
     * e caía no default do engine, sem ninguém perceber. Slug morre; a ordem do catálogo, não.
     */
    public static function topoDe(string $kind, string $subtype, ?string $plan): ?self
    {
        return static::active()->kind($kind)->where('subtype', $subtype)->forPlan($plan)
            ->orderBy('sort_order')->orderBy('id')->first();
    }

    public static function resolveSelectable(mixed $slug, string $kind, ?string $plan): ?self
    {
        if (! is_string($slug) || ($slug = trim($slug)) === '') {
            return null;
        }

        return static::active()
            ->kind($kind)
            ->where('slug', $slug)
            ->forPlan($plan)
            ->first();
    }
}
