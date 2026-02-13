<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * MESSAGE USAGE REPORTS
     * =====================
     * 
     * Laporan pemakaian pesan per hari/kategori/campaign.
     * Data diambil dari kombinasi ledger + message logs.
     * 
     * APPEND ONLY - tidak boleh edit data historis.
     */
    public function up(): void
    {
        if (!Schema::hasTable('message_usage_reports')) {
            Schema::create('message_usage_reports', function (Blueprint $table) {
            $table->id();
            
            // Report identification
            $table->string('report_type', 30); // 'daily', 'weekly', 'monthly'
            $table->date('report_date');
            $table->string('period_key', 50);
            
            // Scope identification
            $table->bigInteger('user_id')->nullable(); // null = system-wide
            $table->bigInteger('klien_id')->nullable();
            $table->string('category', 50)->nullable(); // 'campaign', 'broadcast', 'api', 'manual'
            $table->bigInteger('campaign_id')->nullable(); // specific campaign stats
            
            // Message counts
            $table->integer('messages_attempted')->default(0); // Total messages tried
            $table->integer('messages_sent_successfully')->default(0);
            $table->integer('messages_failed')->default(0);
            $table->integer('messages_pending')->default(0); // Still in queue
            
            // Success rate calculation
            $table->decimal('success_rate_percentage', 5, 2)->default(0.00);
            $table->decimal('failure_rate_percentage', 5, 2)->default(0.00);
            
            // Financial impact
            $table->bigInteger('total_cost_attempted')->default(0); // Cost for all attempted
            $table->bigInteger('total_cost_charged')->default(0); // Cost actually charged
            $table->bigInteger('total_refunds_given')->default(0); // Refunds for failures
            $table->bigInteger('net_cost')->default(0); // charged - refunds
            
            // Message types breakdown
            $table->integer('campaign_messages')->default(0);
            $table->integer('broadcast_messages')->default(0);
            $table->integer('api_messages')->default(0);
            $table->integer('manual_messages')->default(0);
            
            // Timing analysis
            $table->time('peak_usage_hour')->nullable(); // Hour with most messages
            $table->integer('peak_hour_count')->default(0);
            $table->decimal('average_messages_per_hour', 8, 2)->default(0.00);
            
            // Quality metrics
            $table->integer('unique_recipients')->default(0); // Distinct phone numbers
            $table->decimal('average_cost_per_message', 10, 2)->default(0.00);
            $table->decimal('average_cost_per_recipient', 10, 2)->default(0.00);
            
            // Source tracking
            $table->integer('ledger_debits_processed')->default(0);
            $table->integer('message_logs_processed')->default(0);
            $table->bigInteger('first_message_id')->nullable();
            $table->bigInteger('last_message_id')->nullable();
            
            // Validation & metadata
            $table->boolean('calculation_validated')->default(false);
            $table->text('validation_notes')->nullable();
            $table->timestamp('generated_at');
            $table->string('generated_by', 100);
            $table->integer('generation_duration_ms')->nullable();
            
            $table->timestamps();
            
            // Unique constraint per report scope
            $table->unique([
                'report_type', 'period_key', 'user_id', 'category', 'campaign_id'
            ], 'unique_usage_report_scope');
            
            // Indexes for analysis
            $table->index(['user_id', 'report_date', 'category']);
            $table->index(['klien_id', 'report_date']);
            $table->index(['campaign_id', 'report_date']);
            $table->index(['success_rate_percentage']); // Performance ranking
            $table->index(['net_cost']); // Cost analysis
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('message_usage_reports');
    }
};