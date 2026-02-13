<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Auto Pricing System
 * 
 * Sistem dynamic pricing berdasarkan:
 * - Cost per message (dari Gupshup)
 * - Health score nomor WhatsApp
 * - Volume pengiriman
 * - Target margin owner
 * 
 * PRICING FORMULA:
 * ================
 * base_price = cost × (1 + target_margin)
 * health_adjustment = berdasarkan health status
 * volume_adjustment = berdasarkan daily volume spike
 * final_price = base_price × health_adjustment × volume_adjustment
 * 
 * GUARDRAILS:
 * ===========
 * - Margin minimum 20%
 * - Max daily price change 10%
 * - Smooth adjustment (tidak lompat drastis)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pricing settings (owner-configurable)
        if (!Schema::hasTable('pricing_settings')) {
            Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();
            
            // Base pricing
            $table->decimal('base_cost_per_message', 10, 2)->default(350); // IDR
            $table->decimal('current_price_per_message', 10, 2)->default(500); // IDR
            
            // Target margins
            $table->decimal('target_margin_percent', 5, 2)->default(30); // 30%
            $table->decimal('min_margin_percent', 5, 2)->default(20); // 20% minimum
            $table->decimal('max_margin_percent', 5, 2)->default(50); // 50% maximum
            
            // Health-based adjustments
            $table->decimal('health_warning_markup', 5, 2)->default(7.5); // +7.5% for WARNING
            $table->decimal('health_critical_markup', 5, 2)->default(15); // +15% for CRITICAL
            $table->boolean('block_on_critical')->default(false); // Block sending on CRITICAL?
            
            // Volume-based adjustments
            $table->unsignedInteger('volume_spike_threshold')->default(10000); // messages/day
            $table->decimal('volume_spike_markup', 5, 2)->default(5); // +5% above threshold
            $table->decimal('volume_spike_per_10k', 5, 2)->default(2); // +2% per additional 10k
            
            // Guardrails
            $table->decimal('max_daily_price_change', 5, 2)->default(10); // Max 10% change/day
            $table->decimal('price_smoothing_factor', 5, 2)->default(0.3); // 30% toward new price
            
            // Auto-adjustment settings
            $table->boolean('auto_adjust_enabled')->default(true);
            $table->unsignedInteger('recalculate_interval_minutes')->default(30);
            $table->boolean('adjust_on_cost_change')->default(true);
            $table->boolean('adjust_on_health_drop')->default(true);
            
            // Notification settings
            $table->decimal('alert_margin_threshold', 5, 2)->default(15); // Alert if margin < 15%
            $table->decimal('alert_price_change_threshold', 5, 2)->default(5); // Alert if price change > 5%
            
            $table->timestamps();
        });
}

        // Pricing logs (audit trail)
        if (!Schema::hasTable('pricing_logs')) {
            Schema::create('pricing_logs', function (Blueprint $table) {
            $table->id();
            
            // What triggered the calculation
            $table->string('trigger_type', 50); // scheduled, cost_change, health_drop, manual
            $table->string('trigger_reason')->nullable();
            
            // Input values at calculation time
            $table->decimal('input_cost', 10, 2);
            $table->decimal('input_health_score', 5, 2)->nullable();
            $table->string('input_health_status', 20)->nullable();
            $table->decimal('input_delivery_rate', 5, 2)->nullable();
            $table->unsignedInteger('input_daily_volume')->default(0);
            $table->decimal('input_target_margin', 5, 2);
            
            // Calculation breakdown
            $table->decimal('base_price', 10, 2); // cost × (1 + margin)
            $table->decimal('health_adjustment_percent', 5, 2)->default(0);
            $table->decimal('volume_adjustment_percent', 5, 2)->default(0);
            $table->decimal('cost_adjustment_percent', 5, 2)->default(0);
            
            // Guardrail applications
            $table->decimal('raw_calculated_price', 10, 2);
            $table->decimal('smoothed_price', 10, 2);
            $table->decimal('guardrail_capped_price', 10, 2);
            $table->boolean('guardrail_applied')->default(false);
            $table->string('guardrail_reason')->nullable();
            
            // Final values
            $table->decimal('previous_price', 10, 2);
            $table->decimal('new_price', 10, 2);
            $table->decimal('price_change_percent', 5, 2);
            $table->decimal('actual_margin_percent', 5, 2);
            
            // Status
            $table->boolean('was_applied')->default(true);
            $table->string('rejection_reason')->nullable();
            
            // Alert sent?
            $table->boolean('alert_sent')->default(false);
            $table->string('alert_type')->nullable();
            
            // Full breakdown JSON for debugging
            $table->json('calculation_details')->nullable();
            
            $table->timestamps();
            
            $table->index('trigger_type');
            $table->index('created_at');
            $table->index(['was_applied', 'created_at']);
        });
}

        // Cost history (track Gupshup cost changes)
        if (!Schema::hasTable('cost_history')) {
            Schema::create('cost_history', function (Blueprint $table) {
            $table->id();
            
            $table->decimal('cost_per_message', 10, 2);
            $table->string('source', 50)->default('gupshup'); // gupshup, manual, api
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            
            $table->timestamps();
            
            $table->index('effective_from');
        });
}

        // Insert default pricing settings
        DB::table('pricing_settings')->insertOrIgnore([
            'base_cost_per_message' => 350,
            'current_price_per_message' => 500,
            'target_margin_percent' => 30,
            'min_margin_percent' => 20,
            'max_margin_percent' => 50,
            'health_warning_markup' => 7.5,
            'health_critical_markup' => 15,
            'block_on_critical' => false,
            'volume_spike_threshold' => 10000,
            'volume_spike_markup' => 5,
            'volume_spike_per_10k' => 2,
            'max_daily_price_change' => 10,
            'price_smoothing_factor' => 0.3,
            'auto_adjust_enabled' => true,
            'recalculate_interval_minutes' => 30,
            'adjust_on_cost_change' => true,
            'adjust_on_health_drop' => true,
            'alert_margin_threshold' => 15,
            'alert_price_change_threshold' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert initial cost history
        DB::table('cost_history')->insertOrIgnore([
            'cost_per_message' => 350,
            'source' => 'initial',
            'reason' => 'Initial cost setup',
            'effective_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_history');
        Schema::dropIfExists('pricing_logs');
        Schema::dropIfExists('pricing_settings');
    }
};
