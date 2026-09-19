<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBX COMO SEGUNDO FORMATO DA MALHA.
 *
 * O GLB é o formato do viewer /3d (three.js) e do plugin Unity com glTFast. Mas o FBX é o formato
 * nativo que TODA engine importa sem pacote extra (Unity/Unreal/Godot) — e o Blender exporta os
 * dois no mesmo passe. `fbx_url` é opcional: quem sobe só o GLB continua funcionando igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $t) {
            $t->string('fbx_url')->nullable()->after('mesh_url');
        });
        Schema::table('elements', function (Blueprint $t) {
            $t->string('fbx_url')->nullable()->after('mesh_url');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $t) {
            $t->dropColumn('fbx_url');
        });
        Schema::table('elements', function (Blueprint $t) {
            $t->dropColumn('fbx_url');
        });
    }
};
