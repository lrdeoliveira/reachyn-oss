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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();              // antigo tenant_id text ('redfox','default')
            $table->string('name');
            $table->string('plan')->default('starter');    // starter | pro | studio
            $table->string('zernio_profile_id')->nullable();
            $table->string('billing_status')->default('none');
            $table->string('voice_id')->nullable();
            $table->timestamps();
            // Colunas Stripe (stripe_id, pm_*, trial_ends_at) entram via Cashier customer_columns.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
