<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Transaksi Saldo
     * 
     * Mencatat SEMUA perubahan saldo (masuk & keluar).
     * Prinsip ANTI-BONCOS: Setiap transaksi tercatat, tidak ada yang hilang.
     * 
     * Jenis transaksi:
     * - topup: Penambahan saldo dari pembayaran
     * - potong: Pemotongan saldo setelah campaign selesai
     * - hold: Penahanan saldo saat campaign dimulai
     * - release: Pelepasan hold jika campaign batal/gagal
     * - refund: Pengembalian saldo untuk pesan gagal
     * - koreksi: Penyesuaian manual oleh admin
     */
    public function up(): void
    {
        Schema::create('transaksi_saldo', function (Blueprint $table) {
            $table->id();
            
            // Kode unik transaksi
            $table->string('kode_transaksi')->unique(); // TRX-YYYYMMDD-XXXXX
            
            // Relasi
            $table->foreignId('dompet_id')->constrained('dompet_saldo')->onDelete('cascade');
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->unsignedBigInteger('kampanye_id')->nullable(); // akan di-constrain nanti
            $table->foreignId('pengguna_id')->nullable()->constrained('pengguna')->onDelete('set null'); // siapa yang melakukan
            
            // Jenis & Nominal
            $table->enum('jenis', [
                'topup',      // penambahan dari pembayaran
                'potong',     // pengurangan setelah campaign selesai
                'hold',       // penahanan saldo saat campaign mulai
                'release',    // pelepasan hold
                'refund',     // pengembalian untuk pesan gagal
                'koreksi'     // penyesuaian manual
            ]);
            
            $table->bigInteger('nominal'); // bisa positif (masuk) atau negatif (keluar)
            $table->bigInteger('saldo_sebelum'); // saldo sebelum transaksi
            $table->bigInteger('saldo_sesudah'); // saldo setelah transaksi
            
            // Detail Transaksi
            $table->text('keterangan')->nullable(); // deskripsi transaksi
            $table->string('referensi')->nullable(); // invoice/campaign ID/dll
            
            // Untuk Top Up
            $table->enum('status_topup', ['pending', 'disetujui', 'ditolak', 'kadaluarsa'])->nullable();
            $table->enum('metode_bayar', ['transfer_manual', 'virtual_account', 'ewallet'])->nullable();
            $table->string('bank_tujuan')->nullable();
            $table->string('bukti_transfer')->nullable(); // path file
            $table->timestamp('batas_bayar')->nullable();
            $table->foreignId('diproses_oleh')->nullable()->constrained('pengguna')->onDelete('set null');
            $table->timestamp('waktu_diproses')->nullable();
            $table->text('catatan_admin')->nullable();
            
            $table->timestamps();
            
            // Index untuk query cepat
            $table->index('klien_id');
            $table->index('jenis');
            $table->index('status_topup');
            $table->index('created_at');
            $table->index(['klien_id', 'jenis']);
            $table->index(['klien_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_saldo');
    }
};
