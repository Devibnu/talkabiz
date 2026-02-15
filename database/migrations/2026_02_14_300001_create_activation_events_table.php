<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GROWTH ENGINE — Activation Events KPI Table
 * 
 * Tracks funnel: registered → viewed_subscription → clicked_pay → payment_success
 * Used for conversion analytics and growth optimization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event_type', 50)->index();
            // event_type values:
            // - registered
            // - onboarding_complete
            // - viewed_subscription
            // - clicked_pay
            // - payment_success
            // - first_campaign_sent
            // - activation_modal_shown
            // - activation_modal_cta_clicked
            // - scarcity_timer_shown
            $table->json('metadata')->nullable(); // extra context (plan_id, source, etc.)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'event_type']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_events');
    }
};
