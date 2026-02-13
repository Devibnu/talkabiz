<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Menambahkan field untuk tracking webhook status updates:
     * - last_webhook_payload: payload terakhir dari Gupshup
     * - webhook_last_update: kapan terakhir update via webhook
     * - failed_at: kapan status menjadi FAILED
     * - error_reason: alasan kegagalan dari Gupshup
     * - display_name: nama bisnis dari WhatsApp
     * - quality_rating: rating kualitas (GREEN/YELLOW/RED)
     */
    public function up(): void
    {
        if (!Schema::hasTable('whatsapp_connections')) {
            return;
        }

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_connections', 'last_webhook_payload')) {
                $table->json('last_webhook_payload')->nullable()->after('metadata')
                    ->comment('Last webhook payload from Gupshup');
            }
            
            if (!Schema::hasColumn('whatsapp_connections', 'webhook_last_update')) {
                $table->timestamp('webhook_last_update')->nullable()->after('last_webhook_payload')
                    ->comment('Timestamp of last webhook update');
            }
            
            if (!Schema::hasColumn('whatsapp_connections', 'failed_at')) {
                $table->timestamp('failed_at')->nullable()->after('disconnected_at')
                    ->comment('Timestamp when status became FAILED');
            }
            
            if (!Schema::hasColumn('whatsapp_connections', 'error_reason')) {
                $table->text('error_reason')->nullable()->after('failed_at')
                    ->comment('Error reason from Gupshup webhook');
            }
            
            if (!Schema::hasColumn('whatsapp_connections', 'display_name')) {
                $table->string('display_name')->nullable()->after('business_name')
                    ->comment('Display name from WhatsApp Business');
            }
            
            if (!Schema::hasColumn('whatsapp_connections', 'quality_rating')) {
                $table->string('quality_rating', 20)->nullable()->after('display_name')
                    ->comment('Quality rating: GREEN, YELLOW, RED');
            }
        });

        // Add indexes safely
        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('webhook_last_update');
                $table->index('failed_at');
            });
        } catch (\Exception $e) {
            // Indexes already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn([
                'last_webhook_payload',
                'webhook_last_update',
                'failed_at',
                'error_reason',
                'display_name',
                'quality_rating',
            ]);
        });
    }
};
