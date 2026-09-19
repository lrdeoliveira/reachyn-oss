<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Marca o img-cli-cursor como ASSÍNCRONO (capabilities.async = true).
//
// Descoberto no smoke pós-deploy de 2026-07-20: o cursor gera certo (110-145s, PNG real no S3),
// mas o app.reachyn.agency está atrás do CLOUDFLARE, que corta em ~100s com HTTP 524. Pelo
// container direto dava 200; só pela URL pública aparecia o erro. Resultado prático: o usuário
// esperava 100s, via falha, e a imagem depois "aparecia sozinha" na galeria — porque o backend
// concluía normalmente.
//
// A cadeia interna de timeouts (console 360 > engine 300 > bridge 280) está correta; quem corta
// é a borda, que não controlamos. Com a flag, o StudioController::media() enfileira o
// GenerateImageJob e devolve o draftId na hora — o front faz polling do rascunho, igual a
// vídeo/GIF/música.
//
// O gatilho é a CAPABILITY, não o slug: motor lento novo passa a ser assíncrono só marcando isso
// no Filament, sem deploy. O mmx (~5-20s) segue síncrono, que é a UX melhor quando dá.
return new class extends Migration
{
    public function up(): void
    {
        $this->setAsync(true);
    }

    public function down(): void
    {
        $this->setAsync(false);
    }

    private function setAsync(bool $async): void
    {
        $row = DB::table('gen_models')->where('slug', 'img-cli-cursor')->first();
        if (! $row) {
            return;
        }
        $caps = json_decode((string) $row->capabilities, true);
        if (! is_array($caps)) {
            $caps = [];
        }
        if ($async) {
            $caps['async'] = true;
        } else {
            unset($caps['async']);
        }
        DB::table('gen_models')->where('slug', 'img-cli-cursor')
            ->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
    }
};
