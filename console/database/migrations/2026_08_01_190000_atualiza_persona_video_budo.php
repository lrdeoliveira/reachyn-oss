<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Atualiza a persona "🎬 Diretor de Fotografia (BUDO — Vídeo)" (semeada em
 * 2026_08_01_180000) pelo BUDO DE VERDADE — a versão anterior tinha sido inferida à mão
 * (filosofia do Human Images, que BANE buzzword). O BUDO de vídeo (budo-foundation +
 * budo-seedance-cinematic, /Volumes/M5SSD/Assets/Budobonus/BUDOSKILL_Pack_v2.1) tem
 * filosofia OPOSTA nesse ponto: usa "8K, ultra-HD, shot on ARRI Alexa, anamorphic,
 * teal-and-orange, 35mm grain" como VOCABULÁRIO DE QUALIDADE legítimo (Base §3), não como
 * buzzword proibido — a régua ali é ESPECIFICIDADE (nome + número + técnica), não a
 * ausência de certas palavras. Trocado pelo melhor que o BUDO tem: hook de 2 segundos
 * (framework universal §2), técnica de câmera nomeada com velocidade física (§3 do
 * cinematic), setup de luz nomeado com Kelvin, grade nomeada, vocabulário empilhado de
 * camadas diferentes (não da mesma), marcadores de tempo. Pedido do Luciano (2026-08-01).
 *
 * Teto de 1200 runas em personaDirective. Idempotente (update by title).
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

        $video = <<<'TXT'
You are directing a cinematic Seedance-style shot. Open with IMPACT in the first ~2 seconds — never a fade-in, logo, or static establishing shot: pick a hook (light burst from black, extreme macro pulling to wide, reverse motion, rack-focus snap, eyes opening). Translate feelings into visible cues (posture, light, color) — never state emotions directly. Name ONE camera technique with physical speed (dolly forward at 1.5 ft/s, 180-degree orbit, crane rising 30ft over 4s) instead of vague words like fast or dynamic. Name ONE lighting setup (chiaroscuro, Rembrandt, golden hour, hard noir, motivated practical) with exact Kelvin and direction. Name ONE color grade (teal-and-orange, bleach bypass, Kodak Vision3, day-for-night). Stack 3-5 quality-vocabulary terms from different layers — resolution (8K, ultra-HD), camera body (shot on ARRI Alexa, RED Komodo), lens (anamorphic 2.39:1, f/1.4 shallow depth), grade (DaVinci Resolve grade), texture (35mm film grain, halation) — never contradicting registers (no iPhone plus IMAX together). Use timing markers (at 4.2s, camera pushes in). Reference one director's visual register when useful (Deakins, Lubezki).
TXT;

        DB::table('prompts')->where('tenant_id', $tenantId)
            ->where('title', '🎬 Diretor de Fotografia (BUDO — Vídeo)')
            ->where('kind', 'video')
            ->update(['content' => $video, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Sem rollback de conteúdo: a versão anterior (inferida) não vale a pena recuperar.
    }
};
