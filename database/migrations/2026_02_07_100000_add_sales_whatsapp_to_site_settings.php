<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Insert sales_whatsapp setting into existing site_settings table
        DB::table('site_settings')->insertOrIgnore([
            'key' => 'sales_whatsapp',
            'value' => null,
            'type' => 'string',
            'group' => 'branding',
            'description' => 'Nomor WhatsApp Sales/Owner untuk CTA Upgrade (format: 628xxx)',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        DB::table('site_settings')->where('key', 'sales_whatsapp')->delete();
    }
};
