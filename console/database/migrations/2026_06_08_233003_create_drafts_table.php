<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('keyword')->default('');
            $table->json('research')->nullable();
            $table->json('texts')->nullable();
            $table->json('media')->nullable();
            $table->text('image_url')->nullable();
            $table->text('video_url')->nullable();
            $table->text('image_prompt')->nullable();
            $table->string('status')->default('rascunho');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
