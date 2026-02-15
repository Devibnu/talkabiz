<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * CORPORATE MODULE FREEZE
 * 
 * Drop semua tabel corporate module.
 * Fokus Talkabiz sekarang: UMKM SaaS.
 * Corporate akan dibangun ulang nanti jika diperlukan.
 * 
 * Tables dropped:
 * - corporate_clients
 * - corporate_invites
 * - corporate_metric_snapshots
 * - corporate_activity_logs
 * - corporate_prospects
 */
return new class extends Migration
{
    // Drop order: children first, then parents (FK-safe)
    private array $tables = [
        'corporate_activity_logs',
        'corporate_metric_snapshots',
        'corporate_invites',
        'corporate_prospects',
        'corporate_clients',  // parent — last
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }

    public function down(): void
    {
        // Corporate module frozen — no rollback.
        // Rebuild from scratch if needed.
    }
};
