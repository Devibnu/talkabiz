<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel Target Kampanye
     * 
     * Menyimpan daftar penerima untuk setiap campaign.
     * Setiap record = 1 penerima dengan status pengiriman.
     */
    public function up(): void
    {
        Schema::create('target_kampanye', function (Blueprint $table) {
            $table->id();
            
            // Relasi
            $table->foreignId('kampanye_id')->constrained('kampanye')->onDelete('cascade');
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            
            // Data Penerima
            $table->string('no_whatsapp', 20); // nomor WA penerima
            $table->string('nama')->nullable(); // nama penerima
            $table->json('data_variabel')->nullable(); // data untuk personalisasi {{nama}}, {{produk}}
            
            // Status Pengiriman (synced with CampaignService constants)
            $table->enum('status', [
                'pending',      // belum dikirim
                'antrian',      // dalam antrian kirim
                'terkirim',     // sudah terkirim ke WA
                'delivered',    // sudah sampai ke HP penerima
                'dibaca',       // sudah dibaca (read receipt)
                'gagal',        // gagal kirim
                'dilewati',     // dilewati (skip)
                'invalid'       // nomor tidak valid
            ])->default('pending');
            
            // Detail Pengiriman (synced with CampaignService)
            $table->string('message_id')->nullable(); // ID pesan dari WhatsApp API (renamed from wa_message_id)
            $table->timestamp('waktu_kirim')->nullable();
            $table->timestamp('waktu_delivered')->nullable();
            $table->timestamp('waktu_dibaca')->nullable();
            $table->text('catatan')->nullable(); // catatan/error message (renamed from error_message)
            
            // Urutan pengiriman
            $table->unsignedInteger('urutan')->default(0);
            
            $table->timestamps();
            
            // Index untuk query cepat
            $table->index('kampanye_id');
            $table->index('status');
            $table->index('no_whatsapp');
            $table->index(['kampanye_id', 'status']);
            $table->index(['kampanye_id', 'urutan']);
            
            // Composite unique: 1 nomor hanya 1x per campaign
            $table->unique(['kampanye_id', 'no_whatsapp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_kampanye');
    }
};
