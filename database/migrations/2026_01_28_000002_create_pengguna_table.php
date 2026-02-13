<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Pengguna
     * 
     * Menyimpan data pengguna sistem dengan role-based access.
     * Roles: super_admin (system), owner, admin, sales
     * 
     * super_admin = Pengelola sistem (tidak terikat klien)
     * owner = Pemilik perusahaan klien
     * admin = Operator klien (bisa kirim campaign, lihat saldo)
     * sales = Hanya bisa akses inbox
     */
    public function up(): void
    {
        Schema::create('pengguna', function (Blueprint $table) {
            $table->id();
            
            // Relasi ke Klien (null untuk super_admin)
            $table->foreignId('klien_id')->nullable()->constrained('klien')->onDelete('cascade');
            
            // Identitas
            $table->string('nama_lengkap');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('no_telepon', 20)->nullable();
            $table->string('foto_profil')->nullable();
            
            // Role & Akses
            $table->enum('role', ['super_admin', 'owner', 'admin', 'sales'])->default('sales');
            $table->boolean('aktif')->default(true);
            
            // Keamanan
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('terakhir_login')->nullable();
            $table->string('remember_token', 100)->nullable();
            
            // Pengaturan User
            $table->json('preferensi')->nullable(); // notifikasi, bahasa, dll
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index
            $table->index('klien_id');
            $table->index('role');
            $table->index('aktif');
            $table->index(['klien_id', 'role']); // untuk filter user per klien per role
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengguna');
    }
};
