<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HARDENING MIGRATION
 * 
 * Menambahkan:
 * 1. Index pada transaksi_saldo untuk query cepat
 * 2. Unique constraint pada referensi/order_id
 * 3. Tabel webhook_logs untuk menyimpan raw payload
 * 4. Index pada dompet_saldo
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Harden transaksi_saldo table
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            // Add unique index on referensi (order_id) for idempotency
            // Using whereNotNull to allow multiple nulls
            $table->unique('referensi', 'transaksi_saldo_referensi_unique');
            
            // Add payment_gateway column if not exists
            if (!Schema::hasColumn('transaksi_saldo', 'payment_gateway')) {
                $table->string('payment_gateway', 20)->nullable()->after('metode_bayar')
                    ->comment('midtrans, xendit, manual');
            }
            
            // Add index on status_topup + created_at for filtering
            $table->index(['status_topup', 'created_at'], 'transaksi_status_created_idx');
            
            // Add index on dompet_id for wallet lookups
            if (!Schema::hasIndex('transaksi_saldo', 'transaksi_saldo_dompet_id_index')) {
                $table->index('dompet_id', 'transaksi_saldo_dompet_id_index');
            }
        });

        // 2. Add index on dompet_saldo for faster lookups
        Schema::table('dompet_saldo', function (Blueprint $table) {
            // Add created_at index
            $table->index('created_at', 'dompet_saldo_created_at_index');
        });

        // 3. Create webhook_logs table for raw payload storage
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 20); // midtrans, xendit
            $table->string('event_type', 50)->nullable(); // settlement, capture, expired, etc
            $table->string('order_id')->nullable()->index();
            $table->string('external_id')->nullable()->index(); // for xendit
            $table->json('payload'); // raw JSON payload
            $table->json('headers')->nullable(); // request headers
            $table->string('signature')->nullable(); // signature from webhook
            $table->boolean('signature_valid')->default(false);
            $table->boolean('processed')->default(false);
            $table->text('process_result')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['gateway', 'processed'], 'webhook_logs_gateway_processed_idx');
            $table->index('created_at', 'webhook_logs_created_at_idx');
        });
    }

    public function down(): void
    {
        // Remove webhook_logs table
        Schema::dropIfExists('webhook_logs');
        
        // Remove added indexes from dompet_saldo
        Schema::table('dompet_saldo', function (Blueprint $table) {
            $table->dropIndex('dompet_saldo_created_at_index');
        });
        
        // Remove added indexes and columns from transaksi_saldo
        Schema::table('transaksi_saldo', function (Blueprint $table) {
            $table->dropUnique('transaksi_saldo_referensi_unique');
            $table->dropIndex('transaksi_status_created_idx');
            
            if (Schema::hasIndex('transaksi_saldo', 'transaksi_saldo_dompet_id_index')) {
                $table->dropIndex('transaksi_saldo_dompet_id_index');
            }
            
            if (Schema::hasColumn('transaksi_saldo', 'payment_gateway')) {
                $table->dropColumn('payment_gateway');
            }
        });
    }
};
