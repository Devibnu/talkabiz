<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('site_settings')) {
            Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string, file, boolean, json
            $table->string('group', 50)->default('general'); // general, branding, etc.
            $table->string('description')->nullable();
            $table->timestamps();
        });
}

        // Seed default branding settings
        DB::table('site_settings')->insertOrIgnore([
            [
                'key' => 'site_name',
                'value' => 'Talkabiz',
                'type' => 'string',
                'group' => 'branding',
                'description' => 'Nama brand/situs yang ditampilkan di semua halaman',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'site_logo',
                'value' => null,
                'type' => 'file',
                'group' => 'branding',
                'description' => 'Logo utama (ditampilkan di navbar, landing, dll). Rekomendasi: PNG/SVG transparan, maks 2MB',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'site_favicon',
                'value' => null,
                'type' => 'file',
                'group' => 'branding',
                'description' => 'Favicon browser. Rekomendasi: PNG 32x32 atau 64x64',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'site_tagline',
                'value' => 'Platform WhatsApp Marketing untuk Bisnis Indonesia',
                'type' => 'string',
                'group' => 'branding',
                'description' => 'Tagline singkat di bawah logo atau footer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
