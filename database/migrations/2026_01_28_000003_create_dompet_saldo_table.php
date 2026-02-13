<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Dompet Saldo
     * 
     * Setiap klien punya 1 dompet untuk menyimpan saldo.
     * Prinsip ANTI-BONCOS:
     * - saldo_tersedia = saldo yang bisa dipakai
     * - saldo_tertahan = saldo yang di-hold untuk campaign aktif
     * - saldo_tersedia tidak boleh < 0
     */
    public function up(): void
    {
        Schema::create('dompet_saldo', function (Blueprint $table) {
            $table->id();
            
            // Relasi ke Klien (1 klien = 1 dompet)
            $table->foreignId('klien_id')->unique()->constrained('klien')->onDelete('cascade');
            
            // Saldo (dalam Rupiah, menggunakan bigint untuk angka besar)
            $table->bigInteger('saldo_tersedia')->default(0); // saldo yang bisa dipakai
            $table->bigInteger('saldo_tertahan')->default(0); // saldo yang di-hold untuk campaign
            
            // Threshold untuk warning
            $table->bigInteger('batas_warning')->default(500000); // warning jika saldo < ini
            $table->bigInteger('batas_minimum')->default(50000); // tidak bisa kirim jika < ini
            
            // Statistik
            $table->bigInteger('total_topup')->default(0); // total semua top up
            $table->bigInteger('total_terpakai')->default(0); // total semua pemakaian
            
            // Audit
            $table->timestamp('terakhir_topup')->nullable();
            $table->timestamp('terakhir_transaksi')->nullable();
            
            $table->timestamps();
            
            // Index
            $table->index('saldo_tersedia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dompet_saldo');
    }
};
