<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Add trial activation reminder types to subscription_notifications table.
 * 
 * New types: email_1h, email_24h, wa_24h
 * Required for Growth Engine trial→active conversion reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Alter ENUM to include new trial activation types
        DB::statement("ALTER TABLE subscription_notifications MODIFY COLUMN `type` ENUM('t7','t3','t1','expired','email_1h','email_24h','wa_24h') NOT NULL");
    }

    public function down(): void
    {
        // Revert to original ENUM (will fail if rows exist with new types)
        DB::statement("ALTER TABLE subscription_notifications MODIFY COLUMN `type` ENUM('t7','t3','t1','expired') NOT NULL");
    }
};
