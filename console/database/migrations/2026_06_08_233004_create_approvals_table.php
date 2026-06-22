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
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('job_id')->nullable();
            $table->string('keyword')->default('');
            $table->text('preview_text')->nullable();
            $table->text('image_url')->nullable();
            $table->text('video_url')->nullable();
            $table->text('resume_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->json('meta')->nullable();
            $table->string('status')->default('pendente'); // pendente | aprovado | rejeitado
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
