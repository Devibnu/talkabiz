<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Klien (Perusahaan/Tenant)
     * 
     * Menyimpan data klien yang menggunakan sistem.
     * Setiap klien bisa punya banyak pengguna (owner, admin, sales).
     */
    public function up(): void
    {
        Schema::create('klien', function (Blueprint $table) {
            $table->id();
            
            // Identitas Klien
            $table->string('nama_perusahaan');
            $table->string('slug')->unique(); // untuk subdomain/URL
            $table->enum('tipe_bisnis', ['perorangan', 'cv', 'pt', 'ud', 'lainnya'])->default('perorangan');
            $table->string('alamat')->nullable();
            $table->string('kota')->nullable();
            $table->string('provinsi')->nullable();
            $table->string('kode_pos', 10)->nullable();
            
            // Kontak
            $table->string('email')->unique();
            $table->string('no_telepon', 20)->nullable();
            $table->string('no_whatsapp', 20)->nullable();
            
            // Konfigurasi WhatsApp API
            $table->string('wa_phone_number_id')->nullable(); // dari Meta
            $table->string('wa_business_account_id')->nullable();
            $table->text('wa_access_token')->nullable(); // encrypted
            $table->boolean('wa_terhubung')->default(false);
            $table->timestamp('wa_terakhir_sync')->nullable();
            
            // Status & Paket
            $table->enum('status', ['aktif', 'nonaktif', 'suspend', 'trial'])->default('trial');
            $table->enum('tipe_paket', ['umkm', 'enterprise'])->default('umkm');
            $table->date('tanggal_bergabung');
            $table->date('tanggal_berakhir')->nullable(); // untuk trial/subscription
            
            // Pengaturan
            $table->json('pengaturan')->nullable(); // settings tambahan dalam JSON
            
            $table->timestamps();
            $table->softDeletes();
            
            // Index untuk pencarian
            $table->index('status');
            $table->index('tipe_paket');
            $table->index('wa_terhubung');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klien');
    }
};
