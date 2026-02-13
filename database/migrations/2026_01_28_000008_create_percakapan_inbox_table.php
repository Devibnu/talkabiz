<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Percakapan Inbox (Thread)
     * 
     * Setiap nomor customer = 1 thread percakapan.
     * Prinsip: 1 customer hanya bisa di-handle 1 sales pada satu waktu.
     */
    public function up(): void
    {
        Schema::create('percakapan_inbox', function (Blueprint $table) {
            $table->id();
            
            // Relasi
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            
            // Data Customer
            $table->string('no_whatsapp', 20); // nomor WA customer
            $table->string('nama_customer')->nullable(); // nama (dari kontak atau manual)
            $table->string('foto_profil')->nullable(); // foto profil WA
            
            // Assignment (siapa yang handle)
            $table->foreignId('ditangani_oleh')->nullable()->constrained('pengguna')->onDelete('set null');
            $table->timestamp('waktu_diambil')->nullable(); // kapan sales ambil chat
            $table->boolean('terkunci')->default(false); // true = sedang di-handle, tidak bisa diambil sales lain
            
            // Status Percakapan
            $table->enum('status', [
                'baru',         // belum pernah dibuka
                'belum_dibaca', // ada pesan masuk belum dibaca
                'aktif',        // sedang ditangani
                'menunggu',     // menunggu balasan customer
                'selesai'       // percakapan selesai
            ])->default('baru');
            
            // Pesan Terakhir (untuk preview di list)
            $table->text('pesan_terakhir')->nullable();
            $table->enum('pengirim_terakhir', ['customer', 'sales'])->nullable();
            $table->timestamp('waktu_pesan_terakhir')->nullable();
            
            // Statistik
            $table->unsignedInteger('total_pesan')->default(0);
            $table->unsignedInteger('pesan_belum_dibaca')->default(0);
            
            // Label & Prioritas
            $table->json('label')->nullable(); // ['urgent', 'vip', 'komplain']
            $table->enum('prioritas', ['rendah', 'normal', 'tinggi', 'urgent'])->default('normal');
            
            // Catatan Internal
            $table->text('catatan')->nullable();
            
            $table->timestamps();
            
            // Unique: 1 nomor = 1 thread per klien
            $table->unique(['klien_id', 'no_whatsapp']);
            
            // Index
            $table->index('klien_id');
            $table->index('ditangani_oleh');
            $table->index('status');
            $table->index('terkunci');
            $table->index('waktu_pesan_terakhir');
            $table->index(['klien_id', 'status']);
            $table->index(['klien_id', 'ditangani_oleh']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('percakapan_inbox');
    }
};
