<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * FINAL TARGET (non-destructive phase 1):
     * - Keep legacy Gupshup fields for backward compatibility
     * - Add Meta Cloud per-tenant fields to whatsapp_connections
     * - Backfill data from klien WA columns so whatsapp_connections becomes
     *   the operational source of truth in the next refactor phase
     */
    public function up(): void
    {
        if (!Schema::hasTable('whatsapp_connections')) {
            return;
        }

        $this->expandStatusEnum();

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_connections', 'provider')) {
                $table->string('provider', 50)->nullable()->after('klien_id');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'connection_name')) {
                $table->string('connection_name')->nullable()->after('provider');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'phone_number_id')) {
                $table->string('phone_number_id')->nullable()->after('phone_number');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'waba_id')) {
                $table->string('waba_id')->nullable()->after('phone_number_id');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'meta_app_id')) {
                $table->string('meta_app_id')->nullable()->after('waba_id');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'meta_business_id')) {
                $table->string('meta_business_id')->nullable()->after('meta_app_id');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'access_token')) {
                $table->text('access_token')->nullable()->after('api_secret');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'token_type')) {
                $table->string('token_type', 50)->nullable()->after('access_token');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('token_type');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'token_last_refreshed_at')) {
                $table->timestamp('token_last_refreshed_at')->nullable()->after('token_expires_at');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'verification_status')) {
                $table->string('verification_status', 50)->nullable()->after('quality_rating');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'connected_by_user_id')) {
                $table->foreignId('connected_by_user_id')->nullable()->after('verification_status')
                    ->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('whatsapp_connections', 'webhook_verify_token')) {
                $table->string('webhook_verify_token')->nullable()->after('webhook_last_update');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'last_error_code')) {
                $table->string('last_error_code', 100)->nullable()->after('error_reason');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'last_error_message')) {
                $table->text('last_error_message')->nullable()->after('last_error_code');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'settings')) {
                $table->json('settings')->nullable()->after('last_error_message');
            }

            if (!Schema::hasColumn('whatsapp_connections', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        $this->addIndexes();
        $this->backfillFromKlien();
        $this->normalizeDefaults();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('whatsapp_connections')) {
            return;
        }

        $this->dropIndexes();

        Schema::table('whatsapp_connections', function (Blueprint $table) {
            if (Schema::hasColumn('whatsapp_connections', 'connected_by_user_id')) {
                $table->dropConstrainedForeignId('connected_by_user_id');
            }

            $dropColumns = [
                'provider',
                'connection_name',
                'phone_number_id',
                'waba_id',
                'meta_app_id',
                'meta_business_id',
                'access_token',
                'token_type',
                'token_expires_at',
                'token_last_refreshed_at',
                'verification_status',
                'webhook_verify_token',
                'last_error_code',
                'last_error_message',
                'settings',
                'deleted_at',
            ];

            $existing = array_filter($dropColumns, fn ($column) => Schema::hasColumn('whatsapp_connections', $column));

            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });

        $this->restoreLegacyStatusEnum();
    }

    private function expandStatusEnum(): void
    {
        DB::statement(
            "ALTER TABLE whatsapp_connections MODIFY status ENUM('disconnected','pending','connected','restricted','failed','suspended','expired','token_expired','permission_revoked') NOT NULL DEFAULT 'disconnected'"
        );
    }

    private function restoreLegacyStatusEnum(): void
    {
        DB::statement(
            "ALTER TABLE whatsapp_connections MODIFY status ENUM('disconnected','pending','connected','restricted') NOT NULL DEFAULT 'disconnected'"
        );
    }

    private function addIndexes(): void
    {
        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('provider');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('phone_number_id');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->unique('phone_number_id');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('waba_id');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('token_expires_at');
            });
        } catch (\Throwable $e) {
        }

        try {
            Schema::table('whatsapp_connections', function (Blueprint $table) {
                $table->index('connected_by_user_id');
            });
        } catch (\Throwable $e) {
        }
    }

    private function dropIndexes(): void
    {
        $indexNames = [
            'whatsapp_connections_provider_index',
            'whatsapp_connections_phone_number_id_index',
            'whatsapp_connections_phone_number_id_unique',
            'whatsapp_connections_waba_id_index',
            'whatsapp_connections_token_expires_at_index',
            'whatsapp_connections_connected_by_user_id_index',
        ];

        foreach ($indexNames as $indexName) {
            try {
                DB::statement("ALTER TABLE whatsapp_connections DROP INDEX {$indexName}");
            } catch (\Throwable $e) {
            }
        }
    }

    private function backfillFromKlien(): void
    {
        if (!Schema::hasTable('klien')) {
            return;
        }

        DB::table('whatsapp_connections as wc')
            ->join('klien as k', 'k.id', '=', 'wc.klien_id')
            ->whereNull('wc.phone_number_id')
            ->whereNotNull('k.wa_phone_number_id')
            ->update([
                'wc.phone_number_id' => DB::raw('k.wa_phone_number_id'),
            ]);

        DB::table('whatsapp_connections as wc')
            ->join('klien as k', 'k.id', '=', 'wc.klien_id')
            ->whereNull('wc.waba_id')
            ->whereNotNull('k.wa_business_account_id')
            ->update([
                'wc.waba_id' => DB::raw('k.wa_business_account_id'),
            ]);

        DB::table('whatsapp_connections as wc')
            ->join('klien as k', 'k.id', '=', 'wc.klien_id')
            ->whereNull('wc.access_token')
            ->whereNotNull('k.wa_access_token')
            ->update([
                'wc.access_token' => DB::raw('k.wa_access_token'),
                'wc.token_type' => DB::raw("COALESCE(wc.token_type, 'legacy_klien')"),
            ]);

        DB::table('whatsapp_connections as wc')
            ->join('klien as k', 'k.id', '=', 'wc.klien_id')
            ->whereNull('wc.webhook_last_update')
            ->whereNotNull('k.wa_terakhir_sync')
            ->update([
                'wc.webhook_last_update' => DB::raw('k.wa_terakhir_sync'),
            ]);

        DB::table('whatsapp_connections as wc')
            ->join('klien as k', 'k.id', '=', 'wc.klien_id')
            ->where('k.wa_terhubung', true)
            ->whereIn('wc.status', ['disconnected', 'pending'])
            ->update([
                'wc.status' => 'connected',
                'wc.connected_at' => DB::raw('COALESCE(wc.connected_at, k.wa_terakhir_sync, wc.updated_at)'),
            ]);
    }

    private function normalizeDefaults(): void
    {
        DB::table('whatsapp_connections')
            ->whereNull('provider')
            ->update(['provider' => 'meta_cloud']);

        DB::table('whatsapp_connections')
            ->whereNull('connection_name')
            ->update(['connection_name' => 'WhatsApp Utama']);

        DB::table('whatsapp_connections')
            ->whereNull('last_error_message')
            ->whereNotNull('error_reason')
            ->update([
                'last_error_message' => DB::raw('error_reason'),
            ]);

        DB::table('whatsapp_connections')
            ->whereNull('verification_status')
            ->where('status', 'connected')
            ->update(['verification_status' => 'verified']);

        DB::table('whatsapp_connections')
            ->whereNull('verification_status')
            ->whereIn('status', ['pending', 'disconnected', 'failed'])
            ->update(['verification_status' => 'unknown']);
    }
};