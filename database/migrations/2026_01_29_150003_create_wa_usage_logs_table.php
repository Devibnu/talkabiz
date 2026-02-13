<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WA Usage Logs Table
 * 
 * AUDIT TRAIL untuk setiap pesan yang dikirim.
 * Log ini WAJIB untuk:
 * 1. Menghitung usage harian/bulanan
 * 2. Audit biaya per pesan
 * 3. Investigasi jika ada dispute
 * 
 * ATURAN BISNIS:
 * - Setiap pesan yang BERHASIL dikirim = 1 record
 * - Simpan saldo sebelum & sesudah
 * - Simpan alasan jika ditolak (limit/saldo)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->cascadeOnDelete();
            $table->foreignId('pengguna_id')->nullable()->constrained('pengguna')->nullOnDelete();
            
            // Reference
            $table->foreignId('kampanye_id')->nullable()->constrained('kampanye')->nullOnDelete();
            $table->foreignId('target_kampanye_id')->nullable()->constrained('target_kampanye')->nullOnDelete();
            $table->foreignId('percakapan_inbox_id')->nullable()->constrained('percakapan_inbox')->nullOnDelete();
            
            // Message Info
            $table->string('nomor_tujuan', 20);
            $table->string('message_type', 50)->default('text'); // text, template, media
            $table->string('message_category', 50)->default('marketing'); // marketing, utility, auth, service
            
            // Pricing
            $table->decimal('price_per_message', 10, 2);
            $table->decimal('total_cost', 12, 2);
            $table->string('currency', 3)->default('IDR');
            
            // Saldo Tracking (ANTI-BONCOS)
            $table->decimal('saldo_before', 14, 2);
            $table->decimal('saldo_after', 14, 2);
            
            // Status
            $table->enum('status', ['success', 'failed', 'rejected', 'pending'])->default('pending');
            $table->string('rejection_reason', 100)->nullable(); // limit_daily, limit_monthly, insufficient_balance, etc
            
            // Provider Response
            $table->string('provider_message_id', 100)->nullable();
            $table->string('provider_status', 50)->nullable();
            
            $table->timestamps();
            
            // Indexes for fast queries
            $table->index(['klien_id', 'created_at']);
            $table->index(['klien_id', 'status', 'created_at']);
            $table->index(['kampanye_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_usage_logs');
    }
};
