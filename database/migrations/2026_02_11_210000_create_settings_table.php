<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();

                // Company Info
                $table->string('company_name')->nullable();
                $table->text('company_address')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();

                // Sales & Marketing
                $table->string('sales_whatsapp')->nullable();
                $table->string('maps_embed_url', 500)->nullable();
                $table->string('maps_link', 500)->nullable();

                // Financial Defaults
                $table->string('default_currency', 10)->default('IDR');
                $table->decimal('default_tax_percent', 5, 2)->default(11.00);

                // Operating Hours
                $table->string('operating_hours')->nullable();

                $table->timestamps();
            });
        }

        // Seed row 1 — pull existing values from site_settings if available
        $contactEmail   = DB::table('site_settings')->where('key', 'contact_email')->value('value');
        $contactPhone   = DB::table('site_settings')->where('key', 'contact_phone')->value('value');
        $companyAddress  = DB::table('site_settings')->where('key', 'company_address')->value('value');
        $mapsEmbedUrl   = DB::table('site_settings')->where('key', 'maps_embed_url')->value('value');
        $mapsLink       = DB::table('site_settings')->where('key', 'maps_link')->value('value');
        $operatingHours = DB::table('site_settings')->where('key', 'operating_hours')->value('value');
        $salesWhatsapp  = DB::table('site_settings')->where('key', 'sales_whatsapp')->value('value');
        $siteName       = DB::table('site_settings')->where('key', 'site_name')->value('value');

        DB::table('settings')->insertOrIgnore([
            'id'                  => 1,
            'company_name'        => $siteName ?: 'Talkabiz',
            'company_address'     => $companyAddress ?: 'Jakarta, Indonesia',
            'contact_email'       => $contactEmail ?: 'support@talkabiz.id',
            'contact_phone'       => $contactPhone ?: '+62 812-3456-7890',
            'sales_whatsapp'      => $salesWhatsapp,
            'maps_embed_url'      => $mapsEmbedUrl ?: 'https://www.google.com/maps?q=Jakarta&output=embed',
            'maps_link'           => $mapsLink ?: 'https://www.google.com/maps?q=Jakarta',
            'default_currency'    => 'IDR',
            'default_tax_percent' => 11.00,
            'operating_hours'     => $operatingHours ?: 'Senin – Jumat, 09.00 – 17.00 WIB',
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
