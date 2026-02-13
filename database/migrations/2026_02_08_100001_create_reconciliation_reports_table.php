<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * RECONCILIATION REPORTS
     * =====================
     * 
     * Menyimpan hasil rekonsiliasi per periode.
     * Setiap periode (harian/mingguan/bulanan) harus ada 1 record.
     * 
     * APPEND ONLY - tidak boleh edit record lama.
     */
    public function up(): void
    {
        if (!Schema::hasTable('reconciliation_reports')) {
            Schema::create('reconciliation_reports', function (Blueprint $table) {
            $table->id();
            
            // Period identification
            $table->string('period_type', 20); // 'daily', 'weekly', 'monthly'
            $table->date('report_date'); // 2026-02-08 for daily
            $table->string('period_key', 50); // '2026-02-08', '2026-W06', '2026-02'
            
            // Reconciliation status
            $table->enum('status', ['in_progress', 'completed', 'failed', 'anomaly_detected'])
                  ->default('in_progress');
            
            // Summary counts
            $table->integer('total_invoices_checked')->default(0);
            $table->integer('total_messages_checked')->default(0); 
            $table->integer('total_ledger_entries_checked')->default(0);
            
            // Anomaly counts
            $table->integer('invoice_anomalies')->default(0);
            $table->integer('message_anomalies')->default(0);
            $table->integer('balance_anomalies')->default(0);
            
            // Financial summary (in smallest currency unit - cents/rupiah)
            $table->bigInteger('total_invoice_amount')->default(0);
            $table->bigInteger('total_ledger_credits')->default(0);
            $table->bigInteger('total_ledger_debits')->default(0);
            $table->bigInteger('total_refunds')->default(0);
            $table->bigInteger('closing_balance')->default(0);
            
            // Execution metadata
            $table->timestamp('reconciliation_started_at');
            $table->timestamp('reconciliation_completed_at')->nullable();
            $table->string('executed_by', 100)->nullable(); // job name or user
            $table->integer('execution_duration_seconds')->nullable();
            
            // Error tracking
            $table->text('error_summary')->nullable();
            $table->json('detailed_errors')->nullable();
            
            // Audit trail
            $table->json('reconciliation_rules_used')->nullable();
            $table->json('period_statistics')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->unique(['period_type', 'period_key']); // One report per period
            $table->index(['report_date', 'status']);
            $table->index(['status', 'created_at']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reports');
    }
};