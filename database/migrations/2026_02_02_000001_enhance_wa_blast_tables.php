<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement untuk WA Blast Flow
 * 
 * Menambahkan kolom yang diperlukan untuk:
 * 1. Campaign flow dengan quota tracking
 * 2. Reason tracking untuk failed campaigns
 * 3. Daily/monthly quota di user level
 * 4. Batch processing support
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== ENHANCE WHATSAPP CAMPAIGNS ====================
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            // Reason for failure/pause
            if (!Schema::hasColumn('whatsapp_campaigns', 'fail_reason')) {
                $table->string('fail_reason')->nullable()->after('rate_limit_per_second');
            }
            
            // Quota at campaign creation (snapshot)
            if (!Schema::hasColumn('whatsapp_campaigns', 'quota_allocated')) {
                $table->unsignedInteger('quota_allocated')->default(0)->after('fail_reason');
            }
            if (!Schema::hasColumn('whatsapp_campaigns', 'quota_used')) {
                $table->unsignedInteger('quota_used')->default(0)->after('quota_allocated');
            }
            
            // Processing batch info
            if (!Schema::hasColumn('whatsapp_campaigns', 'current_batch')) {
                $table->unsignedInteger('current_batch')->default(0)->after('quota_used');
            }
            if (!Schema::hasColumn('whatsapp_campaigns', 'batch_size')) {
                $table->unsignedInteger('batch_size')->default(100)->after('current_batch');
            }
            
            // Connection used for sending
            $table->foreignId('connection_id')->nullable()->after('template_id')
                ->constrained('whatsapp_connections')->nullOnDelete();
            
            // Owner action tracking
            if (!Schema::hasColumn('whatsapp_campaigns', 'stopped_by_owner')) {
                $table->boolean('stopped_by_owner')->default(false)->after('batch_size');
            }
            if (!Schema::hasColumn('whatsapp_campaigns', 'stopped_by_user_id')) {
                $table->unsignedBigInteger('stopped_by_user_id')->nullable()->after('stopped_by_owner');
            }
            if (!Schema::hasColumn('whatsapp_campaigns', 'stopped_at')) {
                $table->timestamp('stopped_at')->nullable()->after('stopped_by_user_id');
            }
            
            // Progress tracking
            if (!Schema::hasColumn('whatsapp_campaigns', 'skipped_count')) {
                $table->unsignedInteger('skipped_count')->default(0)->after('failed_count');
            }
            
            // Index for fail_reason queries
            $table->index('fail_reason');
        });

        // ==================== ENHANCE WHATSAPP TEMPLATES ====================
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            // Connection reference
            $table->foreignId('connection_id')->nullable()->after('klien_id')
                ->constrained('whatsapp_connections')->nullOnDelete();
            
            // Template tracking from Gupshup
            if (!Schema::hasColumn('whatsapp_templates', 'synced_at')) {
                $table->timestamp('synced_at')->nullable()->after('rejection_reason');
            }
            
            // Quality tracking
            if (!Schema::hasColumn('whatsapp_templates', 'quality_score')) {
                $table->string('quality_score')->nullable()->after('synced_at');
            }
        });

        // ==================== ENHANCE WHATSAPP CAMPAIGN RECIPIENTS ====================
        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            // Skip reason for non opt-in
            if (!Schema::hasColumn('whatsapp_campaign_recipients', 'skip_reason')) {
                $table->string('skip_reason')->nullable()->after('error_message');
            }
            
            // Batch number for this recipient
            if (!Schema::hasColumn('whatsapp_campaign_recipients', 'batch_number')) {
                $table->unsignedInteger('batch_number')->default(0)->after('skip_reason');
            }
            
            // Index for batch queries
            $table->index(['campaign_id', 'batch_number']);
        });

        // ==================== CREATE USER QUOTA TABLE ====================
        // Daily & Monthly quota tracking per user
        if (!Schema::hasTable('user_quotas')) {
            Schema::create('user_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            
            // Plan-based limits
            $table->unsignedInteger('daily_limit')->default(1000);
            $table->unsignedInteger('monthly_limit')->default(10000);
            
            // Current usage
            $table->unsignedInteger('daily_used')->default(0);
            $table->unsignedInteger('monthly_used')->default(0);
            
            // Reset tracking
            $table->date('daily_reset_date')->nullable();
            $table->date('monthly_reset_date')->nullable();
            
            // Last exceeded timestamps
            $table->timestamp('daily_exceeded_at')->nullable();
            $table->timestamp('monthly_exceeded_at')->nullable();
            
            $table->timestamps();
            
            $table->unique(['user_id', 'klien_id']);
            $table->index('daily_reset_date');
            $table->index('monthly_reset_date');
        });
}

        // ==================== ENHANCE WHATSAPP MESSAGE LOGS ====================
        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            // Add idempotency key for preventing duplicate sends
            if (!Schema::hasColumn('whatsapp_message_logs', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable()->after('metadata');
            }
            
            // Add skip reason
            if (!Schema::hasColumn('whatsapp_message_logs', 'skip_reason')) {
                $table->string('skip_reason')->nullable()->after('error_message');
            }
            
            // Index for idempotency
            $table->unique('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_campaigns', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropColumn([
                'fail_reason', 'quota_allocated', 'quota_used',
                'current_batch', 'batch_size', 'connection_id',
                'stopped_by_owner', 'stopped_by_user_id', 'stopped_at',
                'skipped_count'
            ]);
            $table->dropIndex(['fail_reason']);
        });

        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
            $table->dropColumn(['connection_id', 'synced_at', 'quality_score']);
        });

        Schema::table('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['skip_reason', 'batch_number']);
            $table->dropIndex(['campaign_id', 'batch_number']);
        });

        Schema::dropIfExists('user_quotas');

        Schema::table('whatsapp_message_logs', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'skip_reason']);
        });
    }
};
