<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add email tracking columns to subscriptions table.
 * 
 * These nullable datetime fields ensure each notification type
 * is sent exactly once per subscription lifecycle.
 * 
 * - reminder_sent_at      → 3-day pre-expiry reminder
 * - grace_email_sent_at   → Grace period warning
 * - expired_email_sent_at → Final expiration notice
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('renewal_attempts');
            $table->timestamp('grace_email_sent_at')->nullable()->after('reminder_sent_at');
            $table->timestamp('expired_email_sent_at')->nullable()->after('grace_email_sent_at');

            // Index for efficient query: find subscriptions needing emails
            $table->index(['status', 'reminder_sent_at', 'expires_at'], 'subscriptions_email_reminder_idx');
            $table->index(['status', 'grace_email_sent_at'], 'subscriptions_email_grace_idx');
            $table->index(['status', 'expired_email_sent_at'], 'subscriptions_email_expired_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_email_reminder_idx');
            $table->dropIndex('subscriptions_email_grace_idx');
            $table->dropIndex('subscriptions_email_expired_idx');
            $table->dropColumn([
                'reminder_sent_at',
                'grace_email_sent_at',
                'expired_email_sent_at',
            ]);
        });
    }
};
