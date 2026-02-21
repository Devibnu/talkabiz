<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan Change Logs — Audit trail for upgrade/downgrade prorate operations.
 * 
 * Records every plan switch with full prorate calculation details.
 * Immutable log — no updates or deletes allowed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_change_logs', function (Blueprint $table) {
            $table->id();

            // Who changed
            $table->unsignedBigInteger('klien_id')->index();
            $table->unsignedBigInteger('user_id')->index();

            // What changed
            $table->unsignedBigInteger('from_plan_id');
            $table->unsignedBigInteger('to_plan_id');
            $table->enum('direction', ['upgrade', 'downgrade']);

            // Prorate calculation snapshot
            $table->unsignedInteger('total_days')->default(30);
            $table->unsignedInteger('remaining_days');
            $table->decimal('from_plan_price', 15, 2)->comment('price_monthly of old plan');
            $table->decimal('to_plan_price', 15, 2)->comment('price_monthly of new plan');
            $table->decimal('current_daily_rate', 15, 2);
            $table->decimal('new_daily_rate', 15, 2);
            $table->decimal('current_remaining_value', 15, 2);
            $table->decimal('new_remaining_cost', 15, 2);
            $table->decimal('price_difference', 15, 2)->comment('positive = charge, negative = refund');

            // Tax (PPN) on the difference
            $table->decimal('tax_rate', 5, 2)->default(0)->comment('e.g. 11.00');
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_with_tax', 15, 2)->default(0)->comment('price_difference + tax_amount');

            // Resolution
            $table->enum('resolution', ['payment', 'wallet_credit', 'immediate'])->comment('payment=midtrans, wallet_credit=refund, immediate=zero-diff');
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');

            // References
            $table->unsignedBigInteger('old_user_plan_id')->nullable()->comment('UserPlan that was deactivated');
            $table->unsignedBigInteger('new_user_plan_id')->nullable()->comment('UserPlan that was activated');
            $table->unsignedBigInteger('old_subscription_id')->nullable();
            $table->unsignedBigInteger('new_subscription_id')->nullable();
            $table->unsignedBigInteger('plan_transaction_id')->nullable()->comment('PlanTransaction for upgrade payment');
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->comment('WalletTransaction for refund');
            $table->unsignedBigInteger('invoice_id')->nullable()->comment('Invoice for the prorate charge');

            // Metadata
            $table->json('calculation_snapshot')->nullable()->comment('Full prorate calculation data');
            $table->string('idempotency_key', 100)->unique()->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Foreign keys
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            $table->foreign('from_plan_id')->references('id')->on('plans')->onDelete('restrict');
            $table->foreign('to_plan_id')->references('id')->on('plans')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_change_logs');
    }
};
