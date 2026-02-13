<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Pesan Inbox
     * 
     * Menyimpan semua pesan dalam percakapan inbox.
     * Ini terpisah dari tabel 'pesan' karena fokusnya berbeda:
     * - Tabel 'pesan' = log semua pesan untuk audit & billing
     * - Tabel 'pesan_inbox' = untuk tampilan chat UI
     */
    public function up(): void
    {
        Schema::create('pesan_inbox', function (Blueprint $table) {
            $table->id();
            
            // Relasi
            $table->foreignId('percakapan_id')->constrained('percakapan_inbox')->onDelete('cascade');
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('pengguna_id')->nullable()->constrained('pengguna')->onDelete('set null'); // sales yang kirim (jika keluar)
            
            // Referensi ke tabel pesan utama
            $table->foreignId('pesan_id')->nullable()->constrained('pesan')->onDelete('set null');
            
            // Identifikasi
            $table->string('wa_message_id')->nullable();
            
            // Arah & Pengirim
            $table->enum('arah', ['masuk', 'keluar'])->default('masuk');
            $table->string('no_pengirim', 20);
            
            // Konten
            $table->enum('tipe', ['teks', 'gambar', 'dokumen', 'audio', 'video', 'lokasi', 'kontak', 'sticker'])->default('teks');
            $table->text('isi_pesan')->nullable();
            $table->string('media_url')->nullable();
            $table->string('media_mime_type')->nullable();
            $table->string('nama_file')->nullable();
            $table->unsignedInteger('ukuran_file')->nullable(); // dalam bytes
            
            // Caption untuk media
            $table->text('caption')->nullable();
            
            // Reply (jika ini adalah balasan ke pesan tertentu)
            $table->foreignId('reply_to')->nullable()->constrained('pesan_inbox')->onDelete('set null');
            
            // Status
            $table->enum('status', [
                'pending',
                'terkirim',
                'delivered',
                'dibaca',
                'gagal'
            ])->default('pending');
            
            // Status dibaca oleh sales (untuk pesan masuk)
            $table->boolean('dibaca_sales')->default(false);
            $table->timestamp('waktu_dibaca_sales')->nullable();
            
            // Waktu
            $table->timestamp('waktu_pesan'); // waktu asli pesan (dari WA)
            $table->timestamp('waktu_delivered')->nullable();
            $table->timestamp('waktu_dibaca')->nullable();
            
            // Error
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            // Index
            $table->index('percakapan_id');
            $table->index('klien_id');
            $table->index('arah');
            $table->index('status');
            $table->index('waktu_pesan');
            $table->index('dibaca_sales');
            $table->index(['percakapan_id', 'waktu_pesan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesan_inbox');
    }
};
