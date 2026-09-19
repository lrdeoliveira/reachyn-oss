<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Marca o img-cli-mmx como ASSÍNCRONO.
//
// Antes ele era o caso rápido (~20s) e o síncrono era a UX melhor. Depois de subir a resolução
// (1280x720 → 2048x1152, commit 2e9fd41), o 16:9 medido em prod foi a ~75s — a só ~25s do corte
// de ~100s do Cloudflare. Não vale apostar nessa margem: 524 INTERMITENTE é pior que polling,
// porque some quando se vai investigar e o usuário não entende por que às vezes funciona.
//
// Com isso, TODOS os motores de imagem passam a ser assíncronos (mmx, cursor, img-referencia).
// O caminho síncrono continua no código, e é o certo para qualquer modelo que caiba com folga
// abaixo de ~90s — o gatilho segue sendo a capability, não o slug.
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
        $row = DB::table('gen_models')->where('slug', 'img-cli-mmx')->first();
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
        DB::table('gen_models')->where('slug', 'img-cli-mmx')
            ->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
    }
};
