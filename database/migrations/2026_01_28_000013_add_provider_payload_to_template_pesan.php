<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menambahkan kolom untuk menyimpan payload dan response dari provider.
     * Ini bagian dari Anti-Boncos Rule untuk logging.
     */
    public function up(): void
    {
        if (Schema::hasTable('template_pesan') && !Schema::hasColumn('template_pesan', 'provider_payload')) {
        Schema::table('template_pesan', function (Blueprint $table) {
            // Simpan payload yang dikirim ke provider
            $table->json('provider_payload')->nullable()->after('provider_template_id');
            
            // Simpan response dari provider
            $table->json('provider_response')->nullable()->after('provider_payload');
        });
        }
    }

    public function down(): void
    {
        Schema::table('template_pesan', function (Blueprint $table) {
            $table->dropColumn(['provider_payload', 'provider_response']);
        });
    }
};
