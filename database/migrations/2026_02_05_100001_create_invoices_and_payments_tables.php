<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create invoices and payments tables
 * 
 * INVOICE & PAYMENT SYSTEM:
 * =========================
 * 
 * Invoice adalah SUMBER KEBENARAN keuangan.
 * 
 * FLOW:
 * 1. Create invoice untuk subscription/topup
 * 2. Generate payment link via Midtrans
 * 3. Midtrans webhook → update payment → update invoice
 * 4. Invoice paid → activate subscription
 * 5. Invoice expired → suspend subscription (after grace period)
 * 
 * STATUS MAPPING:
 * - Midtrans settlement → payment.success → invoice.paid → subscription.active
 * - Midtrans expire/cancel → payment.failed → invoice.expired → subscription.suspended
 * 
 * GRACE PERIOD:
 * - Jika invoice expired, klien punya X hari grace period
 * - Setelah grace period, subscription di-suspend
 * 
 * @see SA Document: Invoice & Payment System
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== INVOICES TABLE ====================
        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            
            // Reference
            $table->string('invoice_number', 50)->unique()
                  ->comment('Format: INV-YYYYMMDD-XXXXX');
            
            // Relationships
            $table->foreignId('klien_id')
                  ->constrained('klien')
                  ->onDelete('cascade');
            
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null')
                  ->comment('User yang membuat invoice');
            
            // Invoice type
            $table->enum('type', [
                'subscription',     // Pembayaran subscription/paket
                'subscription_upgrade', // Upgrade paket
                'subscription_renewal', // Perpanjangan
                'topup',            // Top up saldo
                'addon',            // Addon/tambahan
                'other'             // Lainnya
            ])->default('subscription');
            
            // Related entities (polymorphic-like)
            $table->unsignedBigInteger('invoiceable_id')->nullable()
                  ->comment('ID of related entity (subscription, etc)');
            $table->string('invoiceable_type', 100)->nullable()
                  ->comment('Model class of related entity');
            
            // Financial
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0)
                  ->comment('Final amount to pay');
            $table->string('currency', 3)->default('IDR');
            
            // Status
            $table->enum('status', [
                'draft',            // Belum dikirim
                'pending',          // Menunggu pembayaran
                'paid',             // Sudah dibayar
                'partial',          // Sebagian dibayar
                'expired',          // Kedaluwarsa
                'cancelled',        // Dibatalkan
                'refunded',         // Dikembalikan
            ])->default('draft');
            
            // Dates
            $table->timestamp('issued_at')->nullable()
                  ->comment('Tanggal invoice dikirim');
            $table->timestamp('due_at')->nullable()
                  ->comment('Batas waktu pembayaran');
            $table->timestamp('paid_at')->nullable()
                  ->comment('Tanggal pembayaran berhasil');
            $table->timestamp('expired_at')->nullable()
                  ->comment('Tanggal invoice expired');
            
            // Grace period
            $table->timestamp('grace_period_ends_at')->nullable()
                  ->comment('Batas grace period sebelum suspend');
            $table->boolean('grace_period_notified')->default(false)
                  ->comment('Sudah kirim notifikasi grace period');
            
            // Payment info (from Midtrans)
            $table->string('payment_method')->nullable()
                  ->comment('bank_transfer, qris, gopay, etc');
            $table->string('payment_channel')->nullable()
                  ->comment('bca, bni, mandiri, etc');
            
            // Line items (JSON)
            $table->json('line_items')->nullable()
                  ->comment('Array of {name, qty, price, total}');
            
            // Metadata
            $table->json('metadata')->nullable()
                  ->comment('Additional data');
            $table->text('notes')->nullable()
                  ->comment('Catatan internal');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('klien_id');
            $table->index('status');
            $table->index('type');
            $table->index('due_at');
            $table->index(['invoiceable_id', 'invoiceable_type']);
            $table->index(['status', 'due_at']);
            $table->index(['status', 'grace_period_ends_at']);
        });
}

        // ==================== PAYMENTS TABLE ====================
        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
            $table->id();
            
            // Reference
            $table->string('payment_id', 100)->unique()
                  ->comment('Our internal payment ID');
            
            // Relationships
            $table->foreignId('invoice_id')
                  ->constrained('invoices')
                  ->onDelete('cascade');
            
            $table->foreignId('klien_id')
                  ->constrained('klien')
                  ->onDelete('cascade');
            
            // Gateway info
            $table->string('gateway', 50)->default('midtrans')
                  ->comment('midtrans, xendit, manual');
            
            $table->string('gateway_order_id', 100)->nullable()
                  ->comment('Order ID di gateway (untuk Midtrans)');
            
            $table->string('gateway_transaction_id', 100)->nullable()
                  ->comment('Transaction ID dari gateway');
            
            // Financial
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0)
                  ->comment('Gateway fee');
            $table->decimal('net_amount', 15, 2)->default(0)
                  ->comment('Amount - fee');
            $table->string('currency', 3)->default('IDR');
            
            // Status
            $table->enum('status', [
                'pending',          // Menunggu pembayaran
                'processing',       // Sedang diproses gateway
                'success',          // Berhasil
                'failed',           // Gagal
                'expired',          // Kedaluwarsa
                'cancelled',        // Dibatalkan user
                'refunded',         // Dikembalikan
                'challenge',        // Fraud challenge (credit card)
            ])->default('pending');
            
            // Payment method
            $table->string('payment_method')->nullable();
            $table->string('payment_channel')->nullable();
            
            // Snap/redirect info
            $table->string('snap_token')->nullable()
                  ->comment('Midtrans Snap token');
            $table->string('redirect_url')->nullable()
                  ->comment('Payment redirect URL');
            
            // Dates
            $table->timestamp('expires_at')->nullable()
                  ->comment('Payment link expiry');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            // Gateway response
            $table->json('gateway_response')->nullable()
                  ->comment('Full response from gateway');
            $table->text('failure_reason')->nullable();
            
            // Idempotency
            $table->string('idempotency_key', 100)->nullable()->unique()
                  ->comment('Prevent duplicate processing');
            $table->boolean('is_processed')->default(false)
                  ->comment('Webhook sudah diproses');
            $table->timestamp('processed_at')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('invoice_id');
            $table->index('klien_id');
            $table->index('gateway');
            $table->index('status');
            $table->index('gateway_order_id');
            $table->index('gateway_transaction_id');
            $table->index(['status', 'expires_at']);
        });
}

        // ==================== INVOICE EVENTS TABLE ====================
        // Audit log untuk perubahan invoice
        if (!Schema::hasTable('invoice_events')) {
            Schema::create('invoice_events', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('invoice_id')
                  ->constrained('invoices')
                  ->onDelete('cascade');
            
            $table->string('event', 50)
                  ->comment('created, sent, paid, expired, cancelled, etc');
            
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            
            $table->foreignId('user_id')->nullable()
                  ->constrained('users')
                  ->onDelete('set null')
                  ->comment('User yang trigger event (null jika system)');
            
            $table->string('source', 50)->default('system')
                  ->comment('system, webhook, admin, cron');
            
            $table->json('data')->nullable()
                  ->comment('Additional event data');
            
            $table->text('notes')->nullable();
            
            $table->timestamp('created_at');
            
            // Indexes
            $table->index('invoice_id');
            $table->index('event');
            $table->index('created_at');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoices');
    }
};
