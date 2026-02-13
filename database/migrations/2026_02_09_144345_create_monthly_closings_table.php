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
        if (!Schema::hasTable('monthly_closings')) {
            Schema::create('monthly_closings', function (Blueprint $table) {
            $table->id();
            
            // ==================== PERIOD INFO ====================
            $table->integer('year');
            $table->integer('month');
            $table->string('period_key', 20)->unique(); // YYYY-MM
            $table->date('period_start');
            $table->date('period_end');
            
            // ==================== STATUS & CONTROL ====================
            $table->string('status', 50)->default('draft'); // draft, in_progress, completed, failed
            $table->timestamp('closing_started_at')->nullable();
            $table->timestamp('closing_completed_at')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->text('closing_notes')->nullable();
            
            // ==================== WALLET BALANCE TRACKING ====================
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('total_topup', 15, 2)->default(0);
            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->decimal('calculated_closing_balance', 15, 2)->default(0);
            $table->decimal('balance_variance', 15, 2)->default(0);
            $table->boolean('is_balanced')->default(true);
            
            // ==================== TRANSACTION METRICS ====================
            $table->integer('total_transactions')->default(0);
            $table->integer('credit_transactions_count')->default(0);
            $table->integer('debit_transactions_count')->default(0);
            $table->integer('refund_transactions_count')->default(0);
            
            // ==================== USER METRICS ====================
            $table->integer('active_users_count')->default(0);
            $table->integer('topup_users_count')->default(0);
            $table->decimal('average_balance_per_user', 15, 2)->default(0);
            $table->decimal('average_topup_per_user', 15, 2)->default(0);
            $table->decimal('average_usage_per_user', 15, 2)->default(0);
            
            // ==================== EXPORT INFO ====================
            $table->json('export_files')->nullable();
            $table->timestamp('last_exported_at')->nullable();
            $table->json('export_summary')->nullable();
            
            // ==================== DATA SOURCE ====================
            $table->timestamp('data_source_from')->nullable();
            $table->timestamp('data_source_to')->nullable();
            $table->string('data_source_version', 50)->nullable();
            
            // ==================== ERROR & PROCESSING ====================
            $table->json('error_details')->nullable();
            $table->json('validation_results')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('processing_time_seconds')->nullable();
            $table->decimal('memory_usage_mb', 10, 2)->nullable();
            
            // ==================== OWNERSHIP ====================
            $table->string('processed_by', 100)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            
            // ==================== FINANCE CLOSING (Invoice SSOT) ====================
            $table->integer('invoice_count')->default(0);
            $table->decimal('invoice_subscription_revenue', 15, 2)->default(0);
            $table->decimal('invoice_topup_revenue', 15, 2)->default(0);
            $table->decimal('invoice_other_revenue', 15, 2)->default(0);
            $table->decimal('invoice_total_ppn', 15, 2)->default(0);
            $table->decimal('invoice_gross_revenue', 15, 2)->default(0);
            $table->decimal('invoice_net_revenue', 15, 2)->default(0);
            
            // ==================== RECONCILIATION ====================
            $table->decimal('recon_wallet_topup', 15, 2)->default(0);
            $table->decimal('recon_topup_discrepancy', 15, 2)->default(0);
            $table->decimal('recon_wallet_usage', 15, 2)->default(0);
            $table->boolean('recon_has_negative_balance')->default(false);
            $table->string('recon_status', 50)->default('UNCHECKED'); // MATCH, MISMATCH, UNCHECKED
            
            // ==================== FINANCE STATUS ====================
            $table->json('finance_revenue_snapshot')->nullable();
            $table->json('finance_recon_details')->nullable();
            $table->text('finance_discrepancy_notes')->nullable();
            $table->string('finance_status', 50)->default('DRAFT'); // DRAFT, CLOSED, FAILED
            $table->unsignedBigInteger('finance_closed_by')->nullable();
            $table->timestamp('finance_closed_at')->nullable();
            $table->string('finance_closing_hash')->nullable();
            
            $table->timestamps();
            
            // ==================== INDEXES & CONSTRAINTS ====================
            $table->unique(['year', 'month'], 'unique_year_month');
            $table->index(['year', 'month', 'status']);
            $table->index(['finance_status']);
            $table->index(['period_key']);
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('finance_closed_by')->references('id')->on('users')->onDelete('set null');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_closings');
    }
};
