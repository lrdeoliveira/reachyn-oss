<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Remove o prefixo "RedFox AI · " dos display_name dos modelos de geração (branding — o Luciano
// pediu pra tirar o nome "RedFox AI"). Fica só o nome curto: "Imagem", "Pro", "Rápido", etc.
// Idempotente: só toca nos que ainda têm o prefixo. Os seeders já nascem sem ele. Cosmético,
// sem impacto em roteamento/cobrança (o slug/provider não muda).
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gen_models')
            ->where('display_name', 'like', 'RedFox AI · %')
            ->update(['display_name' => DB::raw("trim(replace(display_name, 'RedFox AI · ', ''))")]);
    }

    public function down(): void
    {
        // Sem rollback automático — não re-adiciona o prefixo.
    }
};
