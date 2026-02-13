<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('site_settings')->insertOrIgnore([
            [
                'key' => 'contact_email',
                'value' => 'support@talkabiz.id',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'Email kontak yang ditampilkan di halaman Contact',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'contact_phone',
                'value' => '+62 812-3456-7890',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'Nomor telepon/WA yang ditampilkan di halaman Contact (format tampil)',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'company_address',
                'value' => 'Jakarta, Indonesia',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'Alamat perusahaan yang ditampilkan di halaman Contact',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'maps_embed_url',
                'value' => 'https://www.google.com/maps?q=Jakarta&output=embed',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'URL iframe embed Google Maps',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'maps_link',
                'value' => 'https://www.google.com/maps?q=Jakarta',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'URL Google Maps untuk tombol "Buka di Google Maps"',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'operating_hours',
                'value' => 'Senin – Jumat, 09.00 – 17.00 WIB',
                'type' => 'string',
                'group' => 'contact',
                'description' => 'Jam operasional yang ditampilkan di halaman Contact',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('site_settings')->whereIn('key', [
            'contact_email',
            'contact_phone',
            'company_address',
            'maps_embed_url',
            'maps_link',
            'operating_hours',
        ])->delete();
    }
};
