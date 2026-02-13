<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: WhatsApp Deliverability Health Score System
 * 
 * Sistem untuk mengukur & menjaga deliverability nomor WhatsApp.
 * 
 * SCORING BREAKDOWN:
 * ==================
 * - Delivery Rate: 40% (delivered/sent)
 * - Failure Rate: 25% (failed/sent, inverse)
 * - User Signals: 20% (blocks, reports)
 * - Pattern Score: 10% (send spikes, timing)
 * - Template Mix: 5% (variasi template)
 * 
 * STATUS THRESHOLDS:
 * ==================
 * - EXCELLENT: 85-100
 * - GOOD: 70-84
 * - WARNING: 50-69
 * - CRITICAL: 0-49
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Main health scores table
        if (!Schema::hasTable('whatsapp_health_scores')) {
            Schema::create('whatsapp_health_scores', function (Blueprint $table) {
            $table->id();
            
            // Connection reference
            $table->unsignedBigInteger('connection_id')->index();
            $table->unsignedBigInteger('klien_id')->index();
            
            // Overall score & status
            $table->decimal('score', 5, 2)->default(100); // 0-100
            $table->string('status', 20)->default('excellent'); // excellent, good, warning, critical
            $table->string('previous_status', 20)->nullable();
            
            // Score breakdown (0-100 each)
            $table->decimal('delivery_score', 5, 2)->default(100);
            $table->decimal('failure_score', 5, 2)->default(100);
            $table->decimal('user_signal_score', 5, 2)->default(100);
            $table->decimal('pattern_score', 5, 2)->default(100);
            $table->decimal('template_mix_score', 5, 2)->default(100);
            
            // Raw metrics (for calculation)
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_blocked')->default(0);
            $table->unsignedInteger('total_reported')->default(0);
            
            // Rates (calculated)
            $table->decimal('delivery_rate', 5, 2)->default(100); // %
            $table->decimal('failure_rate', 5, 2)->default(0); // %
            $table->decimal('block_rate', 5, 2)->default(0); // %
            
            // Pattern metrics
            $table->decimal('send_spike_factor', 5, 2)->default(1); // 1 = normal
            $table->unsignedInteger('unique_templates_used')->default(0);
            $table->unsignedInteger('peak_hourly_sends')->default(0);
            $table->unsignedInteger('avg_hourly_sends')->default(0);
            
            // Time window for calculation
            $table->string('calculation_window', 20)->default('24h'); // 24h, 7d, 30d
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            
            // Auto actions taken
            $table->boolean('batch_size_reduced')->default(false);
            $table->boolean('delay_added')->default(false);
            $table->boolean('campaign_paused')->default(false);
            $table->boolean('warmup_paused')->default(false);
            $table->boolean('reconnect_blocked')->default(false);
            
            // Metadata
            $table->json('breakdown_details')->nullable(); // Full breakdown for debugging
            $table->json('recommendations')->nullable();
            $table->timestamp('calculated_at')->useCurrent();
            
            $table->timestamps();
            
            $table->foreign('connection_id')
                ->references('id')
                ->on('whatsapp_connections')
                ->onDelete('cascade');
                
            $table->index(['connection_id', 'calculated_at']);
            $table->index(['status', 'score']);
        });
}

        // Health score history for trending
        if (!Schema::hasTable('whatsapp_health_score_history')) {
            Schema::create('whatsapp_health_score_history', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('connection_id')->index();
            $table->date('date')->index();
            
            // Daily snapshot
            $table->decimal('score', 5, 2);
            $table->string('status', 20);
            $table->decimal('delivery_rate', 5, 2);
            $table->decimal('failure_rate', 5, 2);
            
            // Daily metrics
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            
            $table->timestamps();
            
            $table->unique(['connection_id', 'date']);
            
            $table->foreign('connection_id')
                ->references('id')
                ->on('whatsapp_connections')
                ->onDelete('cascade');
        });
}

        // Add health columns to whatsapp_connections (mirror/summary)
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_connections', 'health_score')) {

                $table->decimal('health_score', 5, 2)->default(100)->after('status');

            }
            if (!Schema::hasColumn('whatsapp_connections', 'health_status')) {
                $table->string('health_status', 20)->default('excellent')->after('health_score');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'health_updated_at')) {
                $table->timestamp('health_updated_at')->nullable()->after('health_status');
            }
            
            // Auto-action flags
            if (!Schema::hasColumn('whatsapp_connections', 'reduced_batch_size')) {
                $table->unsignedInteger('reduced_batch_size')->nullable()->after('health_updated_at');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'added_delay_ms')) {
                $table->unsignedInteger('added_delay_ms')->nullable()->after('reduced_batch_size');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'is_paused_by_health')) {
                $table->boolean('is_paused_by_health')->default(false)->after('added_delay_ms');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn([
                'health_score',
                'health_status',
                'health_updated_at',
                'reduced_batch_size',
                'added_delay_ms',
                'is_paused_by_health',
            ]);
        });

        Schema::dropIfExists('whatsapp_health_score_history');
        Schema::dropIfExists('whatsapp_health_scores');
    }
};
