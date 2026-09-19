<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// FILME CONTÍNUO (plano-sequência) — estado do filme no rascunho, paralelo a `story`:
// {status, brief, style, aspect, clip_duration, model, quality, master_ref, beats:[{title,
//  frame_prompt, move_prompt, clip_url?}], final_frame_prompt, keyframes:[url0..urlN], final_url}.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->jsonb('film')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropColumn('film');
        });
    }
};
