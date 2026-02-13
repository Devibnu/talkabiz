<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * WA Pricing Table
 * 
 * PAY AS YOU GO - Biaya per pesan WhatsApp
 * Harga dinamis berdasarkan kategori pesan.
 * 
 * KATEGORI (sesuai Meta pricing):
 * - marketing: Promotional messages
 * - utility: Transactional messages (OTP, notif order)
 * - authentication: OTP/verification
 * - service: Reply dalam 24 jam (gratis di Meta, tapi kita charge)
 * 
 * ATURAN BISNIS:
 * - Harga bisa diubah via admin panel
 * - Tidak perlu deploy untuk ubah harga
 * - History harga untuk audit
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50); // marketing, utility, authentication, service
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            
            // Pricing
            $table->decimal('price_per_message', 10, 2); // Harga per pesan
            $table->string('currency', 3)->default('IDR');
            
            // Status
            $table->boolean('is_active')->default(true);
            
            // Audit
            $table->foreignId('updated_by')->nullable()->constrained('pengguna')->nullOnDelete();
            $table->timestamp('effective_from')->nullable(); // Berlaku mulai kapan
            
            $table->timestamps();
            
            // Index
            $table->index(['category', 'is_active']);
        });
        
        // Insert default pricing
        DB::table('wa_pricing')->insert([
            [
                'category' => 'marketing',
                'display_name' => 'Marketing Message',
                'description' => 'Pesan promosi, broadcast, campaign',
                'price_per_message' => 150, // Rp 150 per pesan
                'currency' => 'IDR',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'utility',
                'display_name' => 'Utility Message',
                'description' => 'Notifikasi order, pengiriman, update status',
                'price_per_message' => 100, // Rp 100 per pesan
                'currency' => 'IDR',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'authentication',
                'display_name' => 'Authentication Message',
                'description' => 'OTP, verifikasi, login',
                'price_per_message' => 120, // Rp 120 per pesan
                'currency' => 'IDR',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'category' => 'service',
                'display_name' => 'Service Message',
                'description' => 'Reply inbox dalam 24 jam',
                'price_per_message' => 50, // Rp 50 per pesan (lebih murah)
                'currency' => 'IDR',
                'is_active' => true,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_pricing');
    }
};
