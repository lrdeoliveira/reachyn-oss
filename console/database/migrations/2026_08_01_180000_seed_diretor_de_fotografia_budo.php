<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Personas de imagem/vídeo destiladas do kit BUDO/Human Images (/Volumes/M5SSD/Assets) — a
 * disciplina de direção de fotografia (física de câmera/lente/luz, zero buzzword, grão visível)
 * que o Estúdio (FoxAssets fundido em 2026-08-01) NÃO carregava: o pipeline só tinha o prefixo/
 * sufixo genérico por estilo (web/lib/imageStyleParts.server.ts), sem a inteligência do BUDO
 * — que só entrava quando alguém chamava o Claude manualmente numa conversa, nunca no runtime.
 *
 * Aqui elas viram PERSONAS DE VERDADE (aba Prompts, kind=image|video) — o mesmo mecanismo que
 * Imagem/Vídeo avulsos já usam (personaId → Prompt::content → engine CompilePrompt →
 * personaDirective, ver engine/internal/content/spec.go). GenerateController::roteiroImagem/
 * roteiroRender (Estúdio → Roteiro) passam a aplicar esta persona POR PADRÃO quando o estilo é
 * realista/cinematográfico e o cliente não escolheu outra — é o que fecha o "não está passando
 * pela skill do BUDO" (pedido do Luciano, 2026-08-01).
 *
 * Teto de 1200 runas em personaDirective — texto denso, o que importa mais primeiro.
 * Idempotente por título. Semeada no tenant 1 (mesmo padrão das outras seed migrations do
 * Estúdio, ex. 2026_07_23_181000_seed_arquiteto_de_personagem).
 */
return new class extends Migration
{
    private function tenantId(): ?int
    {
        return DB::table('tenants')->orderBy('id')->value('id');
    }

    public function up(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }

        $imagem = <<<'TXT'
You are a professional cinematographer directing this shot. Describe PHYSICS, not adjectives: camera body (ARRI Alexa 35 for dynamic/urban/night/narrative scenes, IMAX 65mm for contemplative/grand/portrait scenes), lens and focal length, T-stop, camera height and angle — avoid neutral eye-level, prefer low-angle, hip-level, floor-level or oblique framing. A single motivated light source with Kelvin temperature, direction and shadow behavior. Real skin/material texture: visible pores, natural asymmetry, imperfect but intentional composition, off-center subject. Post: visible organic film grain (never subtle or fine), restrained natural color grading, no plastic sheen or AI-artifact look. Never use: cinematic, epic, beautiful, dramatic, stunning, moody, ethereal, perfect composition, gorgeous, breathtaking, masterpiece, award-winning, best quality, 4k, 8k, hyperrealistic, ultra detailed. No text, logos or watermarks in the image.
TXT;

        $video = <<<'TXT'
You are a professional cinematographer directing this shot for a video clip. Physical camera behavior over adjectives: camera movement (handheld with subtle breathing, slow dolly-in, static locked-off, or low-angle tracking), lens focal length and depth of field, a single motivated light source with color temperature and direction, natural shadow falloff. Skin and material textures stay realistic — visible pores, natural fabric behavior under motion, no plastic or CGI sheen. Post: visible organic film grain, restrained color grading. Never use: cinematic, epic, stunning, dramatic, breathtaking, masterpiece, award-winning, best quality, 4k, 8k, hyperrealistic, ultra detailed. Framing must feel intentional and slightly imperfect — never a perfectly centered stock-footage composition. No on-screen text, logos or watermarks.
TXT;

        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '🎬 Diretor de Fotografia (BUDO)', 'kind' => 'image'],
            ['content' => $imagem, 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('prompts')->updateOrInsert(
            ['tenant_id' => $tenantId, 'title' => '🎬 Diretor de Fotografia (BUDO — Vídeo)', 'kind' => 'video'],
            ['content' => $video, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)
            ->whereIn('title', ['🎬 Diretor de Fotografia (BUDO)', '🎬 Diretor de Fotografia (BUDO — Vídeo)'])
            ->delete();
    }
};
