<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans Table (Paket WA Blast)
 * 
 * Menyimpan definisi paket WA Blast untuk UMKM & Corporate.
 * 
 * SEGMENT:
 * - umkm: Self-service, beli via payment gateway
 * - corporate: Enterprise, assign manual oleh admin
 * 
 * ATURAN BISNIS:
 * - Harga dalam IDR
 * - Kuota pesan = jumlah pesan yang bisa dikirim
 * - Masa aktif dalam hari (0 = tidak expired)
 * - Corporate plan tidak bisa dibeli via payment
 * 
 * @author Senior SA
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            
            // Identitas Paket
            $table->string('code', 50)->unique()->comment('Kode unik paket: umkm-starter, corp-enterprise');
            $table->string('name', 100)->comment('Nama tampilan paket');
            $table->text('description')->nullable();
            
            // Segment
            $table->enum('segment', ['umkm', 'corporate'])->default('umkm')
                  ->comment('umkm = self-service, corporate = manual assign');
            
            // Pricing
            $table->decimal('price', 15, 2)->default(0)->comment('Harga dalam IDR');
            $table->string('currency', 3)->default('IDR');
            $table->decimal('discount_price', 15, 2)->nullable()->comment('Harga promo (jika ada)');
            
            // Durasi & Kuota
            $table->unsignedInteger('duration_days')->default(30)->comment('Masa aktif dalam hari (0 = unlimited)');
            $table->unsignedInteger('quota_messages')->default(1000)->comment('Kuota pesan');
            $table->unsignedInteger('quota_contacts')->default(500)->comment('Maksimal kontak');
            $table->unsignedInteger('quota_campaigns')->default(5)->comment('Maksimal campaign aktif');
            
            // Fitur (JSON untuk fleksibilitas)
            $table->json('features')->nullable()->comment('Fitur: inbox, campaign, flow, api, template, dll');
            
            // Settings
            $table->boolean('is_purchasable')->default(true)->comment('Bisa dibeli via payment gateway');
            $table->boolean('is_visible')->default(true)->comment('Tampil di halaman pricing');
            $table->boolean('is_active')->default(true)->comment('Paket aktif');
            $table->boolean('is_recommended')->default(false)->comment('Tag "Recommended"');
            $table->unsignedTinyInteger('sort_order')->default(0)->comment('Urutan tampil');
            
            // Metadata
            $table->string('badge_text', 50)->nullable()->comment('Badge: "Best Value", "Popular"');
            $table->string('badge_color', 20)->nullable()->comment('Warna badge');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('segment');
            $table->index(['is_active', 'is_visible']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
