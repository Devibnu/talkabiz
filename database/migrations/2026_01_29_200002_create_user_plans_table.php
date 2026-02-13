<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User Plans Table (Paket Aktif User)
 * 
 * Menyimpan paket yang dimiliki/aktif oleh user (klien).
 * 
 * ATURAN BISNIS:
 * - 1 user hanya boleh punya 1 paket AKTIF (status = active)
 * - Paket inactive/expired tetap disimpan untuk history
 * - Kuota berkurang setiap kirim pesan
 * - Jika kuota habis / expired → campaign diblok
 * 
 * SUMBER AKTIVASI:
 * - payment: Via payment gateway (UMKM)
 * - admin: Manual assign oleh admin (Corporate)
 * - promo: Dari promo/voucher
 * - upgrade: Upgrade dari paket lain
 * 
 * @author Senior SA
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_plans', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('plans')->onDelete('restrict');
            $table->foreignId('assigned_by')->nullable()->constrained('pengguna')->onDelete('set null')
                  ->comment('Admin yang assign (untuk corporate)');
            
            // Status
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled', 'upgraded'])
                  ->default('pending')
                  ->comment('Status paket user');
            
            // Periode Aktif
            $table->timestamp('activated_at')->nullable()->comment('Waktu aktivasi');
            $table->timestamp('expires_at')->nullable()->comment('Waktu berakhir (null = unlimited)');
            
            // Kuota (Snapshot dari plan + tracking)
            $table->unsignedInteger('quota_messages_initial')->default(0)->comment('Kuota awal (snapshot dari plan)');
            $table->unsignedInteger('quota_messages_used')->default(0)->comment('Kuota terpakai');
            $table->unsignedInteger('quota_messages_remaining')->default(0)->comment('Kuota tersisa (calculated)');
            
            $table->unsignedInteger('quota_contacts_initial')->default(0);
            $table->unsignedInteger('quota_contacts_used')->default(0);
            
            $table->unsignedInteger('quota_campaigns_initial')->default(0);
            $table->unsignedInteger('quota_campaigns_active')->default(0);
            
            // Sumber Aktivasi
            $table->enum('activation_source', ['payment', 'admin', 'promo', 'upgrade', 'trial'])
                  ->default('payment')
                  ->comment('Sumber aktivasi paket');
            
            // Harga yang dibayar (untuk riwayat)
            $table->decimal('price_paid', 15, 2)->default(0)->comment('Harga yang dibayar saat beli');
            $table->string('currency', 3)->default('IDR');
            
            // Idempotency Key (untuk mencegah double activation dari payment callback)
            $table->string('idempotency_key', 100)->nullable()->unique()
                  ->comment('Key untuk mencegah double activation');
            
            // Referensi Transaksi
            $table->foreignId('transaction_id')->nullable()->comment('Referensi ke plan_transactions');
            
            // Notes
            $table->text('notes')->nullable()->comment('Catatan admin');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['klien_id', 'status']);
            $table->index('status');
            $table->index('expires_at');
            $table->index('activation_source');
            
            // Ensure only 1 active plan per user
            // Note: Enforced in application layer + partial unique index
        });
        
        // Add constraint: Only 1 active plan per klien (MySQL 8.0+)
        // For older MySQL, enforce in application layer
        // DB::statement('CREATE UNIQUE INDEX user_plans_active_unique ON user_plans (klien_id) WHERE status = "active" AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plans');
    }
};
