<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Log Aktivitas
     * 
     * Mencatat semua aktivitas penting untuk audit trail.
     * Penting untuk keamanan dan tracking.
     */
    public function up(): void
    {
        Schema::create('log_aktivitas', function (Blueprint $table) {
            $table->id();
            
            // Siapa
            $table->foreignId('pengguna_id')->nullable()->constrained('pengguna')->onDelete('set null');
            $table->foreignId('klien_id')->nullable()->constrained('klien')->onDelete('set null');
            
            // Apa yang dilakukan
            $table->string('aksi'); // create, update, delete, login, logout, approve, reject, dll
            $table->string('modul'); // klien, kampanye, saldo, inbox, dll
            $table->string('tabel_terkait')->nullable();
            $table->unsignedBigInteger('id_terkait')->nullable(); // ID record yang diakses
            
            // Detail
            $table->text('deskripsi')->nullable(); // deskripsi aktivitas
            $table->json('data_lama')->nullable(); // data sebelum diubah
            $table->json('data_baru')->nullable(); // data setelah diubah
            
            // Context
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            
            // Waktu
            $table->timestamp('waktu')->useCurrent();
            
            // Index
            $table->index('pengguna_id');
            $table->index('klien_id');
            $table->index('aksi');
            $table->index('modul');
            $table->index('waktu');
            $table->index(['klien_id', 'modul']);
            $table->index(['klien_id', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_aktivitas');
    }
};
