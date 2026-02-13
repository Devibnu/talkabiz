<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * BALANCE REPORTS
     * ===============
     * 
     * Laporan saldo terstruktur per periode dan user.
     * Data diambil LANGSUNG dari ledger (tidak boleh manual calculation).
     * 
     * APPEND ONLY - setiap periode buat record baru.
     */
    public function up(): void
    {
        if (!Schema::hasTable('balance_reports')) {
            Schema::create('balance_reports', function (Blueprint $table) {
            $table->id();
            
            // Report identification
            $table->string('report_type', 30); // 'daily', 'weekly', 'monthly'
            $table->date('report_date');
            $table->string('period_key', 50); // '2026-02-08', '2026-W06', '2026-02'
            
            // User scope (dapat null untuk system-wide report)
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('klien_id')->nullable();
            
            // Opening balance (dari periode sebelumnya)
            $table->bigInteger('opening_balance')->default(0); // in smallest unit
            
            // Credit movements (topup, refund, bonus)
            $table->bigInteger('total_topup_credits')->default(0);
            $table->bigInteger('total_refund_credits')->default(0);
            $table->bigInteger('total_bonus_credits')->default(0);
            $table->bigInteger('total_other_credits')->default(0);
            $table->bigInteger('total_credits')->default(0);
            
            // Debit movements (message sending, fees)
            $table->bigInteger('total_message_debits')->default(0);
            $table->bigInteger('total_fee_debits')->default(0);
            $table->bigInteger('total_penalty_debits')->default(0);
            $table->bigInteger('total_other_debits')->default(0);
            $table->bigInteger('total_debits')->default(0);
            
            // Closing balance
            $table->bigInteger('closing_balance')->default(0);
            $table->bigInteger('calculated_balance')->default(0); // opening + credits - debits
            $table->bigInteger('balance_difference')->default(0); // closing - calculated (should be 0)
            
            // Transaction counts
            $table->integer('credit_transaction_count')->default(0);
            $table->integer('debit_transaction_count')->default(0);
            $table->integer('total_transaction_count')->default(0);
            
            // Message statistics
            $table->integer('messages_sent_count')->default(0);
            $table->integer('messages_failed_count')->default(0);
            $table->integer('messages_refunded_count')->default(0);
            
            // Validation status
            $table->boolean('balance_validated')->default(false);
            $table->text('validation_notes')->nullable();
            $table->timestamp('validated_at')->nullable();
            
            // Generation metadata
            $table->timestamp('generated_at');
            $table->string('generated_by', 100); // job name or admin user
            $table->integer('generation_duration_ms')->nullable();
            
            // Ledger source tracking
            $table->integer('ledger_entries_processed')->default(0);
            $table->bigInteger('first_ledger_id')->nullable();
            $table->bigInteger('last_ledger_id')->nullable();
            
            $table->timestamps();
            
            // Unique constraint - one report per period per user
            $table->unique(['report_type', 'period_key', 'user_id'], 'unique_period_user_report');
            
            // Indexes for querying
            $table->index(['user_id', 'report_date']);
            $table->index(['klien_id', 'report_date']);
            $table->index(['period_key', 'balance_validated']);
            $table->index(['closing_balance']); // For balance ranking
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_reports');
    }
};