<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUD-001: idempotência de webhooks do Stripe.
 * Cada evento (event_id único do Stripe) é registrado aqui antes de ser aplicado;
 * uma 2ª entrega do mesmo evento colide no índice unique e é ignorada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique(); // ex: evt_... (id do evento do Stripe)
            $table->string('type')->nullable();    // ex: customer.subscription.updated
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
