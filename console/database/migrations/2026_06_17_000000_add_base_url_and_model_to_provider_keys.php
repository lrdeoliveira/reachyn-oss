<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Providers de TEXTO (primário e alternativo) ganham base_url + model (endpoint + modelo do LLM).
// NÃO são secretos (api_key continua encrypted; base_url/model são texto puro em claro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_keys', function (Blueprint $table) {
            $table->string('base_url')->nullable()->after('api_key'); // endpoint do provider (texto puro)
            $table->string('model')->nullable()->after('base_url');    // modelo do LLM (texto puro)
        });
    }

    public function down(): void
    {
        Schema::table('provider_keys', function (Blueprint $table) {
            $table->dropColumn(['base_url', 'model']);
        });
    }
};
