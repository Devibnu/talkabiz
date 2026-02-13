<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * INVOICE REPORTS
     * ===============
     * 
     * Laporan invoice (status & nilai) per periode.
     * Data LANGSUNG dari table invoices + ledger validation.
     * 
     * APPEND ONLY - tidak boleh edit historical data.
     */
    public function up(): void
    {
        if (!Schema::hasTable('invoice_reports')) {
            Schema::create('invoice_reports', function (Blueprint $table) {
            $table->id();
            
            // Report identification
            $table->string('report_type', 30); // 'daily', 'weekly', 'monthly'
            $table->date('report_date');
            $table->string('period_key', 50);
            
            // Scope
            $table->bigInteger('user_id')->nullable(); // null = system-wide
            $table->bigInteger('klien_id')->nullable();
            $table->string('payment_gateway', 50)->nullable(); // specific gateway analysis
            
            // Invoice counts by status
            $table->integer('invoices_pending')->default(0);
            $table->integer('invoices_paid')->default(0);
            $table->integer('invoices_failed')->default(0);
            $table->integer('invoices_expired')->default(0);
            $table->integer('invoices_refunded')->default(0);
            $table->integer('total_invoices')->default(0);
            
            // Financial summary (in smallest currency unit)
            $table->bigInteger('amount_pending')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->bigInteger('amount_failed')->default(0);
            $table->bigInteger('amount_expired')->default(0);
            $table->bigInteger('amount_refunded')->default(0);
            $table->bigInteger('total_amount_invoiced')->default(0);
            
            // Admin fees
            $table->bigInteger('total_admin_fees_pending')->default(0);
            $table->bigInteger('total_admin_fees_collected')->default(0);
            $table->bigInteger('total_admin_fees_lost')->default(0); // failed + expired
            
            // Payment gateway breakdown
            $table->bigInteger('midtrans_amount')->default(0);
            $table->bigInteger('xendit_amount')->default(0);
            $table->bigInteger('manual_amount')->default(0);
            $table->bigInteger('other_gateway_amount')->default(0);
            
            // Performance metrics
            $table->decimal('payment_success_rate', 5, 2)->default(0.00);
            $table->decimal('payment_failure_rate', 5, 2)->default(0.00);
            $table->decimal('expiry_rate', 5, 2)->default(0.00);
            $table->decimal('refund_rate', 5, 2)->default(0.00);
            
            // Timing analysis
            $table->decimal('average_payment_time_hours', 8, 2)->nullable();
            $table->time('peak_invoice_hour')->nullable();
            $table->integer('peak_hour_invoice_count')->default(0);
            
            // Reconciliation validation
            $table->boolean('ledger_reconciled')->default(false);
            $table->integer('invoices_missing_ledger_credit')->default(0);
            $table->integer('ledger_credits_missing_invoice')->default(0);
            $table->bigInteger('reconciliation_difference')->default(0);
            
            // Invoice size analysis
            $table->bigInteger('min_invoice_amount')->nullable();
            $table->bigInteger('max_invoice_amount')->nullable();
            $table->bigInteger('average_invoice_amount')->default(0);
            $table->bigInteger('median_invoice_amount')->default(0);
            
            // Source tracking
            $table->integer('invoices_processed')->default(0);
            $table->bigInteger('first_invoice_id')->nullable();
            $table->bigInteger('last_invoice_id')->nullable();
            
            // Generation metadata
            $table->boolean('calculation_validated')->default(false);
            $table->text('validation_notes')->nullable();
            $table->timestamp('generated_at');
            $table->string('generated_by', 100);
            $table->integer('generation_duration_ms')->nullable();
            
            $table->timestamps();
            
            // Unique constraint
            $table->unique([
                'report_type', 'period_key', 'user_id', 'payment_gateway'
            ], 'unique_invoice_report_scope');
            
            // Indexes
            $table->index(['user_id', 'report_date']);
            $table->index(['klien_id', 'report_date']);
            $table->index(['payment_gateway', 'report_date']);
            $table->index(['payment_success_rate']); // Performance analysis
            $table->index(['ledger_reconciled', 'report_date']);
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reports');
    }
};