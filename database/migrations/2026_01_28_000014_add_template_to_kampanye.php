<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Update tabel kampanye dan target_kampanye untuk integrasi Template
     * 
     * ANTI-BONCOS RULES:
     * - template_snapshot menyimpan isi template saat campaign dibuat
     * - payload_kirim menyimpan payload yang dikirim ke provider
     * - message_id untuk tracking status delivery
     */
    public function up(): void
    {
        // Update tabel kampanye
        if (Schema::hasTable('kampanye') && !Schema::hasColumn('kampanye', 'template_pesan_id')) {
        Schema::table('kampanye', function (Blueprint $table) {
            // Foreign key ke template_pesan (nullable karena bisa campaign tanpa template)
            $table->foreignId('template_pesan_id')
                ->nullable()
                ->after('template_id')
                ->constrained('template_pesan')
                ->nullOnDelete();
            
            // Snapshot template saat campaign dibuat (untuk anti-boncos)
            // Jika template diubah setelah campaign dibuat, snapshot tetap dipakai
            $table->json('template_snapshot')->nullable()->after('template_pesan_id');
            
            // Index
            $table->index('template_pesan_id');
        });
        }

        // Update tabel target_kampanye
        if (Schema::hasTable('target_kampanye') && !Schema::hasColumn('target_kampanye', 'payload_kirim')) {
        Schema::table('target_kampanye', function (Blueprint $table) {
            // Payload yang dikirim ke provider (untuk debugging & audit)
            $table->json('payload_kirim')->nullable()->after('data_variabel');
        });
        }
    }

    public function down(): void
    {
        Schema::table('kampanye', function (Blueprint $table) {
            $table->dropForeign(['template_pesan_id']);
            $table->dropColumn(['template_pesan_id', 'template_snapshot']);
        });

        Schema::table('target_kampanye', function (Blueprint $table) {
            $table->dropColumn('payload_kirim');
        });
    }
};
