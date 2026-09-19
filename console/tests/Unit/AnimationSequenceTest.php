<?php

namespace Tests\Unit;

use App\Models\AnimationProject;
use App\Models\GenModel;
use App\Services\AnimationFlow;
use App\Services\UsageService;
use Tests\TestCase;

/**
 * 🔗 Sequência do Desenho animado — a lógica de ramificação (mode × sequence_mode) que decide
 * se os keyframes encadeiam (chainsFrames) e se os clipes viram plano-sequência (chainsClips),
 * mais a checagem de tail-capability (endImageUrl só em modelo Kling image_urls[0..1]). Puro,
 * sem DB nem engine.
 */
class AnimationSequenceTest extends TestCase
{
    private function project(string $mode, string $seq): AnimationProject
    {
        $p = new AnimationProject;
        $p->mode = $mode;
        $p->sequence_mode = $seq;

        return $p;
    }

    public function test_solto_nao_encadeia_nada(): void
    {
        $p = $this->project('animacao', 'solto');
        $this->assertFalse($p->chainsFrames());
        $this->assertFalse($p->chainsClips());
    }

    public function test_encadeado_encadeia_so_os_keyframes(): void
    {
        $p = $this->project('animacao', 'encadeado');
        $this->assertTrue($p->chainsFrames(), 'encadeado deve encadear os keyframes');
        $this->assertFalse($p->chainsClips(), 'encadeado NÃO faz plano-sequência (mantém o lip-sync)');
    }

    public function test_plano_encadeia_keyframes_e_clipes(): void
    {
        $p = $this->project('animacao', 'plano');
        $this->assertTrue($p->chainsFrames());
        $this->assertTrue($p->chainsClips(), 'plano faz plano-sequência (endImageUrl nos clipes)');
    }

    public function test_historia_encadeia_keyframes_e_clipes(): void
    {
        // A História narrada também encadeia (recomendação estendida): keyframes fluem, e o plano
        // roda SEM custo de lip-sync (voz é única). Só o modo importa pra clipes é quadrinhos.
        $this->assertTrue($this->project('historia', 'encadeado')->chainsFrames());
        $this->assertFalse($this->project('historia', 'encadeado')->chainsClips());
        $this->assertTrue($this->project('historia', 'plano')->chainsFrames());
        $this->assertTrue($this->project('historia', 'plano')->chainsClips());
    }

    public function test_quadrinhos_encadeia_keyframes_mas_nunca_clipes(): void
    {
        // Quadrinhos é slides-only (sem i2v) → keyframes podem encadear (slides fluem), clipes nunca.
        $this->assertTrue($this->project('quadrinhos', 'encadeado')->chainsFrames());
        $this->assertFalse($this->project('quadrinhos', 'encadeado')->chainsClips());
        // Mesmo que 'plano' escape a validação, não há clipe pra encadear.
        $this->assertFalse($this->project('quadrinhos', 'plano')->chainsClips());
    }

    public function test_solto_nunca_encadeia_em_nenhum_modo(): void
    {
        foreach (AnimationProject::MODES as $mode) {
            $p = $this->project($mode, 'solto');
            $this->assertFalse($p->chainsFrames(), "$mode/solto não deve encadear keyframes");
            $this->assertFalse($p->chainsClips(), "$mode/solto não deve encadear clipes");
        }
    }

    // ── tail-capability (habilita o endImageUrl do plano-sequência) ──────────

    private function flow(): AnimationFlow
    {
        return new AnimationFlow($this->createMock(UsageService::class));
    }

    private function model(string $provider, array $caps): GenModel
    {
        $m = new GenModel;
        $m->provider = $provider;
        $m->capabilities = $caps;

        return $m;
    }

    public function test_tail_capable_exige_flag_explicita_no_catalogo(): void
    {
        $flow = $this->flow();

        // Motor de nuvem com refs em ARRAY e tail DECLARADO → aceita primeiro+último frame.
        $this->assertTrue($flow->isTailCapable(
            $this->model('magnific', ['magnific' => [
                'refs_field' => 'image', 'refs_single' => false, 'tail' => true,
            ]])
        ));

        // Aceita várias refs mas NÃO declara tail → não. A flag é explícita de propósito:
        // um modelo multi-ref pode tratar a 2ª imagem como referência solta, e o fim do trecho
        // sai espelhado (caso real 2026-07-16). Inferir "multi-ref logo tem tail" produzia
        // exatamente esse defeito, e ele só aparecia no vídeo pronto.
        $this->assertFalse($flow->isTailCapable(
            $this->model('magnific', ['magnific' => ['refs_field' => 'image', 'refs_single' => false]])
        ));

        // Refs SINGLE (só 1 imagem) → não faz plano-sequência, mesmo com tail declarado.
        $this->assertFalse($flow->isTailCapable(
            $this->model('magnific', ['magnific' => [
                'refs_field' => 'image', 'refs_single' => true, 'tail' => true,
            ]])
        ));

        // Sem refs_field → não.
        $this->assertFalse($flow->isTailCapable($this->model('magnific', ['magnific' => []])));

        // Outro provider → não (o modo keyframe só foi validado no motor de nuvem).
        $this->assertFalse($flow->isTailCapable(
            $this->model('minimax', ['magnific' => [
                'refs_field' => 'image', 'refs_single' => false, 'tail' => true,
            ]])
        ));

        // Sem modelo → não (degrada com segurança).
        $this->assertFalse($flow->isTailCapable(null));
    }
}
