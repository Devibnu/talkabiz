<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Template Pesan WhatsApp
     * 
     * Menyimpan template pesan yang sudah disetujui Meta/WhatsApp.
     * Template wajib untuk mengirim pesan ke customer yang belum pernah chat (>24 jam).
     * 
     * Kategori:
     * - marketing: promo, newsletter (butuh opt-in)
     * - utility: update order, notifikasi (lebih fleksibel)
     * - authentication: OTP, verifikasi
     */
    public function up(): void
    {
        Schema::create('template_pesan', function (Blueprint $table) {
            $table->id();
            
            // Relasi
            $table->foreignId('klien_id')
                ->constrained('klien')
                ->onDelete('cascade');
            $table->foreignId('dibuat_oleh')
                ->nullable()
                ->constrained('pengguna')
                ->onDelete('set null');
            
            // Identifikasi
            $table->string('nama_template', 100); // snake_case, lowercase
            $table->string('nama_tampilan')->nullable(); // User-friendly name
            
            // Kategori & Bahasa
            $table->enum('kategori', ['marketing', 'utility', 'authentication'])
                ->default('utility');
            $table->string('bahasa', 10)->default('id'); // id, en, etc
            
            // Konten Template
            $table->text('header')->nullable(); // teks/image/video/document
            $table->enum('header_type', ['none', 'text', 'image', 'video', 'document'])
                ->default('none');
            $table->string('header_media_url')->nullable();
            $table->text('body'); // isi utama dengan placeholder {{1}}, {{2}}
            $table->text('footer')->nullable();
            
            // Buttons (JSON array)
            // [{type: 'quick_reply', text: 'Ya'}, {type: 'url', text: 'Lihat', url: 'https://...'}]
            $table->json('buttons')->nullable();
            
            // Contoh variabel untuk approval
            // {"1": "John", "2": "Rp 500.000"}
            $table->json('contoh_variabel')->nullable();
            
            // Status Approval (Indonesia)
            // draft = baru dibuat
            // diajukan = sedang review provider
            // disetujui = approved oleh Meta
            // ditolak = rejected oleh Meta
            // arsip = non-aktif
            $table->enum('status', ['draft', 'diajukan', 'disetujui', 'ditolak', 'arsip'])
                ->default('draft');
            $table->string('provider_template_id')->nullable(); // ID dari Gupshup/Meta
            $table->text('catatan_reject')->nullable();
            $table->text('alasan_penolakan')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            // Statistik
            $table->unsignedInteger('total_terkirim')->default(0);
            $table->unsignedInteger('total_dibaca')->default(0);
            $table->unsignedInteger('dipakai_count')->default(0);
            
            // Metadata
            $table->boolean('aktif')->default(true);
            $table->timestamps();
            
            // Index
            $table->index('klien_id');
            $table->index('status');
            $table->index('kategori');
            $table->index(['klien_id', 'status']);
            $table->unique(['klien_id', 'nama_template']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_pesan');
    }
};
