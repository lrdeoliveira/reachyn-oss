<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\StudioController;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Testes das funções PURAS/estáticas do StudioController (sem DB, sem engine):
 * textPersona (Voz da Marca + roteirista), subtitleStyleFrom (allowlists/clamps da legenda)
 * e isOwnMediaUrl (anti-SSRF). São os guard-rails que todo endpoint de geração atravessa.
 */
class StudioStaticHelpersTest extends TestCase
{
    private function req(array $input): Request
    {
        return Request::create('/test', 'POST', $input);
    }

    private function tenant(?string $brandVoice): object
    {
        return (object) ['brand_voice' => $brandVoice];
    }

    // ── textPersona (Sprint A Hollywood) ────────────────────────────────────

    public function test_text_persona_vazia_sem_voz_nem_roteirista(): void
    {
        $this->assertSame('', StudioController::textPersona($this->req([]), $this->tenant(null)));
        $this->assertSame('', StudioController::textPersona($this->req(['persona' => '   ']), $this->tenant('  ')));
    }

    public function test_text_persona_so_roteirista(): void
    {
        $out = StudioController::textPersona($this->req(['persona' => 'Você escreve como repórter.']), $this->tenant(null));
        $this->assertSame('Você escreve como repórter.', $out);
    }

    public function test_text_persona_voz_da_marca_prefixa_o_roteirista(): void
    {
        $out = StudioController::textPersona(
            $this->req(['persona' => 'Craft do roteirista.']),
            $this->tenant('Tom acolhedor, sem gírias.'),
        );
        $this->assertStringStartsWith('VOZ DA MARCA', $out);
        $this->assertStringContainsString('Tom acolhedor, sem gírias.', $out);
        // roteirista vem DEPOIS da voz (a voz é a regra permanente; o roteirista é o craft do post)
        $this->assertGreaterThan(
            mb_strpos($out, 'Tom acolhedor'),
            mb_strpos($out, 'Craft do roteirista.'),
        );
    }

    public function test_text_persona_caps_de_tamanho(): void
    {
        $out = StudioController::textPersona(
            $this->req(['persona' => str_repeat('x', 5000)]),
            $this->tenant(str_repeat('w', 5000)),
        );
        // caps: voz ≤1200 + rótulo + roteirista ≤2000 — nunca estoura o clip(4000) do engine.
        // ('x' e 'w' não aparecem no rótulo "VOZ DA MARCA (...)" — contagem limpa)
        $this->assertLessThanOrEqual(4000, mb_strlen($out));
        $this->assertSame(2000, mb_substr_count($out, 'x'));
        $this->assertSame(1200, mb_substr_count($out, 'w'));
    }

    // ── subtitleStyleFrom (estilo da legenda: allowlists + clamps) ───────────

    public function test_subtitle_style_defaults(): void
    {
        $s = StudioController::subtitleStyleFrom($this->req([]));
        $this->assertSame('bottom', $s['subtitlePos']);
        $this->assertSame(0, $s['subtitleSize']);
        $this->assertSame('', $s['subtitleColor']);
        $this->assertSame('', $s['subtitleFont']);
    }

    public function test_subtitle_style_allowlists_e_clamps(): void
    {
        $s = StudioController::subtitleStyleFrom($this->req([
            'subtitlePos' => 'diagonal',            // fora da allowlist → bottom
            'subtitleSize' => 999,                   // clamp → 72
            'subtitleBorder' => -3,                  // clamp → 0
            'subtitleColor' => 'red; DROP TABLE',    // não é #RRGGBB → ''
            'subtitleBorderColor' => '#00FF00',      // válida
            'subtitleFont' => 'comic-sans',          // fora da allowlist → ''
        ]));
        $this->assertSame('bottom', $s['subtitlePos']);
        $this->assertSame(72, $s['subtitleSize']);
        $this->assertSame(0, $s['subtitleBorder']);
        $this->assertSame('', $s['subtitleColor']);
        $this->assertSame('#00FF00', $s['subtitleBorderColor']);
        $this->assertSame('', $s['subtitleFont']);
    }

    // ── isOwnMediaUrl (anti-SSRF: só o NOSSO storage entra em i2i/i2v/refs) ──

    public function test_own_media_url_aceita_so_nosso_storage(): void
    {
        $this->assertTrue(StudioController::isOwnMediaUrl('https://s3.example.com/media/x.jpg'));
        $this->assertFalse(StudioController::isOwnMediaUrl('https://evil.com/x.jpg'));
        $this->assertFalse(StudioController::isOwnMediaUrl('http://169.254.169.254/latest/meta-data'));
        $this->assertFalse(StudioController::isOwnMediaUrl('file:///etc/passwd'));
        $this->assertFalse(StudioController::isOwnMediaUrl(''));
        $this->assertFalse(StudioController::isOwnMediaUrl(null));
        // sufixo forjado (s3.example.com.evil.com) NÃO pode passar
        $this->assertFalse(StudioController::isOwnMediaUrl('https://s3.example.com.evil.com/x.jpg'));
    }
}
