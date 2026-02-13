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
        if (!Schema::hasTable('monthly_closing_details')) {
            Schema::create('monthly_closing_details', function (Blueprint $table) {
            $table->id();
            
            // ==================== RELATIONSHIPS ====================
            $table->unsignedBigInteger('monthly_closing_id');
            $table->unsignedBigInteger('user_id');
            
            // ==================== BALANCE DETAILS ====================
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('total_topup', 15, 2)->default(0);
            $table->decimal('total_debit', 15, 2)->default(0);
            $table->decimal('total_refund', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->decimal('calculated_closing_balance', 15, 2)->default(0);
            $table->decimal('balance_variance', 15, 2)->default(0);
            $table->boolean('is_balanced')->default(true);
            
            // ==================== TRANSACTION COUNTS ====================
            $table->integer('transaction_count')->default(0);
            $table->integer('credit_transaction_count')->default(0);
            $table->integer('debit_transaction_count')->default(0);
            $table->integer('refund_transaction_count')->default(0);
            
            // ==================== TRANSACTION METRICS ====================
            $table->timestamp('first_transaction_at')->nullable();
            $table->timestamp('last_transaction_at')->nullable();
            $table->decimal('largest_topup_amount', 15, 2)->default(0);
            $table->decimal('largest_debit_amount', 15, 2)->default(0);
            $table->decimal('average_transaction_amount', 15, 2)->default(0);
            
            // ==================== USER ACTIVITY ====================
            $table->integer('activity_days_count')->default(0);
            $table->boolean('is_active_user')->default(false);
            $table->string('user_tier', 50)->nullable();
            
            // ==================== VALIDATION ====================
            $table->text('notes')->nullable();
            $table->string('validation_status', 50)->default('pending');
            $table->unsignedBigInteger('last_ledger_entry_id')->nullable();
            $table->timestamp('balance_check_timestamp')->nullable();
            $table->json('data_snapshot')->nullable();
            
            $table->timestamps();
            
            // ==================== INDEXES & CONSTRAINTS ====================
            $table->index(['monthly_closing_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('monthly_closing_id')->references('id')->on('monthly_closings')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_closing_details');
    }
};
