<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription Invoices — Bukti tagihan langganan
 * 
 * Setiap kali user checkout paket, SubscriptionInvoice dibuat (status: pending).
 * Setelah payment berhasil (webhook), status menjadi 'paid'.
 * 
 * PENTING: Ini BUKAN wallet topup. Ini invoiced biaya akses sistem (paket).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();

            // Invoice number: INV-SUB-2026020001 (auto-generated, unique)
            $table->string('invoice_number', 50)->unique();

            // Relasi utama
            $table->foreignId('klien_id')
                  ->constrained('klien')
                  ->onDelete('cascade');

            $table->foreignId('user_id')
                  ->comment('User yang melakukan checkout')
                  ->constrained('users')
                  ->onDelete('cascade');

            $table->foreignId('plan_id')
                  ->constrained('plans')
                  ->onDelete('restrict');

            $table->foreignId('plan_transaction_id')
                  ->nullable()
                  ->comment('PlanTransaction yang terkait')
                  ->constrained('plan_transactions')
                  ->onDelete('set null');

            $table->foreignId('subscription_id')
                  ->nullable()
                  ->comment('Subscription record, di-set setelah aktivasi')
                  ->constrained('subscriptions')
                  ->onDelete('set null');

            // Amount (dari Plan::price_monthly, FIXED)
            $table->decimal('amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('final_amount', 15, 2);
            $table->string('currency', 3)->default('IDR');

            // Plan snapshot (immutable copy saat invoice dibuat)
            $table->json('plan_snapshot')->nullable();

            // Status
            $table->enum('status', ['pending', 'paid', 'cancelled', 'expired', 'refunded'])
                  ->default('pending');

            // Payment info (di-fill setelah payment berhasil)
            $table->string('payment_method', 50)->nullable();
            $table->string('payment_channel', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Description
            $table->string('description')->nullable();
            $table->text('notes')->nullable();

            // Idempotency 
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
