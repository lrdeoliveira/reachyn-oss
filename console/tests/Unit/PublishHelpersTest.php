<?php

namespace Tests\Unit;

use App\Services\PublishService;
use PHPUnit\Framework\TestCase;

/** Helpers de publicação: título de foto-post do TikTok (teto 90 chars — erro 400 acima disso). */
class PublishHelpersTest extends TestCase
{
    public function test_tiktok_photo_title_usa_primeira_linha_quando_cabe(): void
    {
        $texto = "Gancho curto e forte\n\nCorpo da legenda com mais detalhes\n#tag1 #tag2";
        $this->assertSame('Gancho curto e forte', PublishService::tiktokPhotoTitle($texto));
    }

    public function test_tiktok_photo_title_nunca_estoura_90(): void
    {
        $linhaLonga = str_repeat('palavra ', 30); // ~240 chars numa linha só
        $out = PublishService::tiktokPhotoTitle($linhaLonga."\nsegunda linha");
        $this->assertLessThanOrEqual(90, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function test_tiktok_photo_title_corta_em_fronteira_de_palavra(): void
    {
        $out = PublishService::tiktokPhotoTitle(str_repeat('abcde ', 40));
        $this->assertLessThanOrEqual(90, mb_strlen($out));
        // O conteúdo antes do "…" deve ser só palavras COMPLETAS (nunca "abc…" cortado no meio).
        $this->assertMatchesRegularExpression('/^(abcde )*abcde…$/u', $out);
    }

    public function test_tiktok_photo_title_multibyte_conta_certo(): void
    {
        $emoji = str_repeat('🚀', 100); // 100 chars multibyte
        $out = PublishService::tiktokPhotoTitle($emoji);
        $this->assertLessThanOrEqual(90, mb_strlen($out));
    }

    public function test_tiktok_photo_title_texto_vazio_devolve_vazio(): void
    {
        $this->assertSame('', PublishService::tiktokPhotoTitle("   \n  "));
    }
}
