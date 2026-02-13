<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Kampanye (Campaign)
     * 
     * Menyimpan data campaign blast WhatsApp.
     * Prinsip ANTI-BONCOS:
     * - saldo_dibutuhkan dihitung sebelum campaign dimulai
     * - saldo di-hold saat campaign mulai
     * - saldo dipotong sesuai pesan terkirim
     */
    public function up(): void
    {
        Schema::create('kampanye', function (Blueprint $table) {
            $table->id();
            
            // Kode unik
            $table->string('kode_kampanye')->unique(); // CMP-YYYYMMDD-XXXXX
            
            // Relasi
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('dibuat_oleh')->constrained('pengguna')->onDelete('cascade');
            
            // Identitas Campaign
            $table->string('nama_kampanye');
            $table->text('deskripsi')->nullable();
            
            // Konten Pesan
            $table->enum('tipe_pesan', ['teks', 'gambar', 'dokumen', 'template'])->default('teks');
            $table->text('template_pesan'); // template pesan utama (renamed from isi_pesan)
            $table->string('media_url')->nullable(); // URL gambar/dokumen
            $table->string('template_id')->nullable(); // ID template WhatsApp jika pakai template
            $table->json('variabel_pesan')->nullable(); // variabel personalisasi {{nama}}, {{produk}}, dll
            
            // Catatan campaign (untuk jeda/berhenti)
            $table->text('catatan')->nullable();
            
            // Target Penerima
            $table->unsignedInteger('total_target')->default(0); // jumlah penerima
            $table->enum('sumber_target', ['manual', 'import_csv', 'grup_kontak', 'filter'])->default('manual');
            
            // Jadwal
            $table->enum('tipe_jadwal', ['langsung', 'terjadwal'])->default('langsung');
            $table->timestamp('jadwal_kirim')->nullable(); // kapan mulai kirim
            $table->timestamp('waktu_mulai')->nullable(); // kapan actual mulai
            $table->timestamp('waktu_selesai')->nullable(); // kapan selesai
            
            // Status Campaign (synced with CampaignService constants)
            $table->enum('status', [
                'draft',        // masih diedit
                'siap',         // target sudah ada, siap dijalankan
                'berjalan',     // sedang kirim
                'jeda',         // di-pause oleh user atau auto-stop
                'selesai',      // semua terkirim
                'gagal',        // gagal total
                'dibatalkan'    // dibatalkan user
            ])->default('draft');
            
            // Progress Pengiriman
            $table->unsignedInteger('terkirim')->default(0);
            $table->unsignedInteger('gagal')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->unsignedInteger('dibaca')->default(0); // read receipt
            
            // Keuangan (ANTI-BONCOS)
            $table->bigInteger('harga_per_pesan')->default(50); // Rp per pesan
            $table->bigInteger('estimasi_biaya')->default(0); // total_target × harga
            $table->bigInteger('saldo_dihold')->default(0); // saldo yang ditahan
            $table->bigInteger('biaya_aktual')->default(0); // biaya sebenarnya (terkirim × harga)
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('klien_id');
            $table->index('status');
            $table->index('jadwal_kirim');
            $table->index(['klien_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kampanye');
    }
};
