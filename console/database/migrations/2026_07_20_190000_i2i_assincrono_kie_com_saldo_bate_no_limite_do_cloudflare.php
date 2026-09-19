<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Marca o img-referencia (i2i, ancoragem de personagem) como ASSÍNCRONO.
//
// CONTEXTO: o Luciano recolocou saldo no KIE em 2026-07-20. Medido logo depois, o i2i leva **98s**
// — colado no corte de ~100s do Cloudflare. Síncrono, isso falharia de forma INTERMITENTE com
// HTTP 524: às vezes passa, às vezes não. É o pior tipo de bug, porque some quando se vai
// investigar e a imagem ainda por cima aparece na galeria depois (o backend conclui).
//
// POR QUE NÃO APARECEU ANTES: enquanto o KIE esteve sem saldo, o i2i falhava RÁPIDO (erro de
// cota), nunca chegando perto do limite de tempo. Repor o crédito não criou o problema — só
// tirou o disfarce.
//
// O t2i do KIE (img-pro) mediu 60s: passa, mas com folga de só ~40s. Fica em observação; se
// aparecer 524 nele, é só marcar async aqui também — o gatilho é a capability, não o slug.
//
// Reversível: o down() tira a flag e o modelo volta a ser síncrono.
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
        $row = DB::table('gen_models')->where('slug', 'img-referencia')->first();
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
        DB::table('gen_models')->where('slug', 'img-referencia')
            ->update(['capabilities' => json_encode($caps), 'updated_at' => now()]);
    }
};
