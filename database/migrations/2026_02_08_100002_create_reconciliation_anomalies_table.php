<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * RECONCILIATION ANOMALIES
     * ========================
     * 
     * Log setiap anomali yang ditemukan selama rekonsiliasi.
     * Setiap mismatch = 1 record anomali untuk audit trail.
     * 
     * APPEND ONLY - sekali tulis tidak boleh edit.
     */
    public function up(): void
    {
        if (!Schema::hasTable('reconciliation_anomalies')) {
            Schema::create('reconciliation_anomalies', function (Blueprint $table) {
            $table->id();
            
            // Kaitkan dengan reconciliation report
            $table->foreignId('reconciliation_report_id')
                  ->constrained('reconciliation_reports')
                  ->onDelete('cascade');
            
            // Anomaly classification
            $table->enum('anomaly_type', [
                'invoice_ledger_mismatch',    // Invoice PAID tidak ada credit di ledger
                'message_debit_mismatch',     // Message SUCCESS tidak ada debit di ledger
                'refund_missing',             // Message FAILED tidak ada refund di ledger
                'negative_balance',           // Balance menjadi negatif
                'duplicate_transaction',      // Transaction code duplikat
                'orphaned_ledger_entry',      // Ledger entry tanpa reference
                'amount_mismatch',            // Amount tidak sesuai antara systems
                'timing_anomaly'              // Transaction timing tidak masuk akal
            ]);
            
            $table->enum('severity', ['critical', 'high', 'medium', 'low'])
                  ->default('medium');
            
            // Entity identification
            $table->string('entity_type', 50); // 'invoice', 'message', 'ledger', 'balance'
            $table->string('entity_id', 100)->nullable(); // ID dari entity terkait
            $table->bigInteger('user_id')->nullable();
            
            // Anomaly details
            $table->string('description', 500);
            $table->decimal('expected_amount', 15, 2)->nullable();
            $table->decimal('actual_amount', 15, 2)->nullable();
            $table->decimal('difference_amount', 15, 2)->nullable();
            
            // Context data
            $table->json('entity_data')->nullable(); // Snapshot data entity
            $table->json('related_records')->nullable(); // IDs record terkait
            $table->json('system_state')->nullable(); // State sistem saat anomali
            
            // Resolution tracking
            $table->enum('resolution_status', [
                'pending', 'investigating', 'resolved', 'false_positive', 'accepted_risk'
            ])->default('pending');
            
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->bigInteger('resolved_by_user_id')->nullable();
            
            // Auto-resolution attempts
            $table->boolean('auto_resolution_attempted')->default(false);
            $table->text('auto_resolution_result')->nullable();
            
            $table->timestamps();
            
            // Indexes for investigation
            $table->index(['anomaly_type', 'severity', 'resolution_status'], 'recon_anom_type_sev_status_idx');
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['resolution_status', 'created_at']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_anomalies');
    }
};