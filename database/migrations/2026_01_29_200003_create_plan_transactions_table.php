<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan Transactions Table (Transaksi Paket)
 * 
 * Menyimpan riwayat transaksi pembelian paket.
 * 
 * FLOW TRANSAKSI:
 * 1. User pilih paket → Create transaction (pending)
 * 2. Redirect ke payment gateway
 * 3. User bayar → Callback update status (paid)
 * 4. Sistem aktivasi paket → Create user_plan
 * 
 * ATURAN BISNIS:
 * - Setiap pembelian paket = 1 transaksi
 * - Corporate: Dibuat manual oleh admin
 * - UMKM: Dibuat otomatis saat checkout
 * - Idempotency key mencegah double processing
 * 
 * @author Senior SA
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_transactions', function (Blueprint $table) {
            $table->id();
            
            // Identitas Transaksi
            $table->string('transaction_code', 50)->unique()->comment('Kode transaksi: TRX-YYYYMMDD-XXXXX');
            $table->string('idempotency_key', 100)->unique()->comment('Key untuk mencegah double processing');
            
            // Foreign Keys
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('plans')->onDelete('restrict');
            $table->foreignId('user_plan_id')->nullable()->comment('Referensi ke user_plan yang diaktifkan');
            $table->foreignId('created_by')->nullable()->constrained('pengguna')->onDelete('set null')
                  ->comment('User yang membuat transaksi');
            $table->foreignId('processed_by')->nullable()->constrained('pengguna')->onDelete('set null')
                  ->comment('Admin yang memproses (untuk corporate)');
            
            // Tipe Transaksi
            $table->enum('type', ['purchase', 'renewal', 'upgrade', 'promo', 'admin_assign'])
                  ->default('purchase')
                  ->comment('Tipe transaksi');
            
            // Pricing
            $table->decimal('original_price', 15, 2)->default(0)->comment('Harga asli paket');
            $table->decimal('discount_amount', 15, 2)->default(0)->comment('Potongan diskon');
            $table->decimal('final_price', 15, 2)->default(0)->comment('Harga final yang dibayar');
            $table->string('currency', 3)->default('IDR');
            
            // Promo/Voucher
            $table->string('promo_code', 50)->nullable();
            $table->decimal('promo_discount', 15, 2)->default(0);
            
            // Status
            $table->enum('status', ['pending', 'waiting_payment', 'paid', 'failed', 'expired', 'cancelled', 'refunded'])
                  ->default('pending')
                  ->comment('Status transaksi');
            
            // Payment Gateway
            $table->string('payment_gateway', 50)->nullable()->comment('midtrans, xendit, manual');
            $table->string('payment_method', 50)->nullable()->comment('bank_transfer, credit_card, ewallet, manual');
            $table->string('payment_channel', 50)->nullable()->comment('bca, bni, gopay, shopeepay');
            
            // Payment Gateway Response
            $table->string('pg_transaction_id', 100)->nullable()->comment('Transaction ID dari payment gateway');
            $table->string('pg_order_id', 100)->nullable()->comment('Order ID yang dikirim ke PG');
            $table->json('pg_request_payload')->nullable()->comment('Request ke PG');
            $table->json('pg_response_payload')->nullable()->comment('Response dari PG');
            $table->string('pg_redirect_url', 500)->nullable()->comment('URL redirect untuk pembayaran');
            
            // Timestamps Payment
            $table->timestamp('payment_expires_at')->nullable()->comment('Batas waktu pembayaran');
            $table->timestamp('paid_at')->nullable()->comment('Waktu pembayaran berhasil');
            $table->timestamp('processed_at')->nullable()->comment('Waktu transaksi diproses');
            
            // Notes
            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable()->comment('Alasan gagal (jika ada)');
            
            // Audit
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('transaction_code');
            $table->index(['klien_id', 'status']);
            $table->index('status');
            $table->index('payment_gateway');
            $table->index('pg_transaction_id');
            $table->index('pg_order_id');
            $table->index('created_at');
        });
        
        // Add foreign key to user_plans after both tables exist
        Schema::table('user_plans', function (Blueprint $table) {
            $table->foreign('transaction_id')
                  ->references('id')
                  ->on('plan_transactions')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        // Remove foreign key first
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropForeign(['transaction_id']);
        });
        
        Schema::dropIfExists('plan_transactions');
    }
};
