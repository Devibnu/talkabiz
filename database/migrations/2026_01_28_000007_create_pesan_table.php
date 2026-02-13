<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Pesan (Log Semua Pesan)
     * 
     * Menyimpan log SEMUA pesan yang dikirim/diterima.
     * Ini adalah master log untuk audit & reporting.
     * Berbeda dengan target_kampanye yang spesifik untuk 1 campaign.
     */
    public function up(): void
    {
        Schema::create('pesan', function (Blueprint $table) {
            $table->id();
            
            // Relasi
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('kampanye_id')->nullable()->constrained('kampanye')->onDelete('set null');
            
            // Identifikasi WhatsApp
            $table->string('wa_message_id')->nullable()->unique(); // ID dari WhatsApp API
            $table->string('no_pengirim', 20); // nomor WA pengirim
            $table->string('no_penerima', 20); // nomor WA penerima
            
            // Arah Pesan
            $table->enum('arah', ['keluar', 'masuk'])->default('keluar');
            
            // Konten
            $table->enum('tipe', ['teks', 'gambar', 'dokumen', 'audio', 'video', 'lokasi', 'kontak', 'template'])->default('teks');
            $table->text('isi_pesan')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_mime_type')->nullable();
            $table->string('nama_file')->nullable();
            
            // Status
            $table->enum('status', [
                'pending',
                'terkirim',
                'delivered',
                'dibaca',
                'gagal'
            ])->default('pending');
            
            // Waktu
            $table->timestamp('waktu_kirim')->nullable();
            $table->timestamp('waktu_delivered')->nullable();
            $table->timestamp('waktu_dibaca')->nullable();
            
            // Error
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            
            // Biaya (untuk tracking)
            $table->bigInteger('biaya')->default(0);
            $table->boolean('sudah_ditagih')->default(false);
            
            $table->timestamps();
            
            // Index
            $table->index('klien_id');
            $table->index('kampanye_id');
            $table->index('no_pengirim');
            $table->index('no_penerima');
            $table->index('arah');
            $table->index('status');
            $table->index('created_at');
            $table->index(['klien_id', 'arah', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesan');
    }
};
