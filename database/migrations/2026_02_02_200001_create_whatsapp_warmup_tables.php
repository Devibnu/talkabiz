<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * AUTO WARM-UP NUMBER System
     * ==========================
     * 
     * Sistem untuk menghangatkan nomor WhatsApp baru secara bertahap
     * untuk menghindari risiko BAN dari Meta.
     * 
     * Strategi Warmup (Default):
     * - Day 1: 50 messages
     * - Day 2: 100 messages  
     * - Day 3: 250 messages
     * - Day 4: 500 messages
     * - Day 5: 1000 messages
     * - Day 6+: Unlimited (warmup complete)
     */
    public function up(): void
    {
        // ==================== WHATSAPP WARMUPS TABLE ====================
        // Menyimpan konfigurasi dan status warmup per connection
        if (!Schema::hasTable('whatsapp_warmups')) {
            Schema::create('whatsapp_warmups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')
                ->constrained('whatsapp_connections')
                ->onDelete('cascade');
            
            // Warmup Configuration
            $table->boolean('enabled')->default(true);
            $table->unsignedTinyInteger('current_day')->default(1);
            $table->unsignedTinyInteger('total_days')->default(5);
            $table->json('daily_limits')->comment('Array of daily limits per day');
            
            // Current Day Stats
            $table->unsignedInteger('sent_today')->default(0);
            $table->unsignedInteger('delivered_today')->default(0);
            $table->unsignedInteger('failed_today')->default(0);
            $table->date('current_date')->nullable();
            
            // Overall Stats
            $table->unsignedInteger('total_sent')->default(0);
            $table->unsignedInteger('total_delivered')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            
            // Safety Thresholds
            $table->unsignedTinyInteger('min_delivery_rate')->default(70)
                ->comment('Minimum delivery rate % to continue warmup');
            $table->unsignedTinyInteger('max_fail_rate')->default(15)
                ->comment('Maximum fail rate % before auto-pause');
            $table->unsignedTinyInteger('cooldown_hours')->default(24)
                ->comment('Hours to wait after pause before auto-resume');
            
            // Status
            $table->enum('status', ['active', 'paused', 'completed', 'failed'])
                ->default('active');
            $table->string('pause_reason')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            // Completion
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['connection_id', 'status']);
            $table->index(['current_date', 'status']);
        });
}

        // ==================== WARMUP LOGS TABLE ====================
        // Log semua aktivitas warmup
        if (!Schema::hasTable('whatsapp_warmup_logs')) {
            Schema::create('whatsapp_warmup_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warmup_id')
                ->constrained('whatsapp_warmups')
                ->onDelete('cascade');
            $table->foreignId('connection_id')
                ->constrained('whatsapp_connections')
                ->onDelete('cascade');
            
            // Event Type
            $table->enum('event', [
                'started',           // Warmup dimulai
                'day_completed',     // Hari selesai
                'day_progressed',    // Naik ke hari berikutnya
                'limit_reached',     // Limit harian tercapai
                'paused_auto',       // Auto pause (delivery rate rendah)
                'paused_manual',     // Manual pause oleh owner
                'resumed',           // Resume setelah pause
                'completed',         // Warmup selesai
                'failed',            // Warmup gagal
                'stats_snapshot',    // Snapshot statistik
            ]);
            
            // Event Data
            $table->unsignedTinyInteger('day_number')->nullable();
            $table->unsignedInteger('daily_limit')->nullable();
            $table->unsignedInteger('sent_count')->nullable();
            $table->unsignedInteger('delivered_count')->nullable();
            $table->unsignedInteger('failed_count')->nullable();
            $table->decimal('delivery_rate', 5, 2)->nullable();
            $table->decimal('fail_rate', 5, 2)->nullable();
            
            // Actor (for manual actions)
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            // Additional context
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes
            $table->index(['warmup_id', 'event']);
            $table->index('created_at');
        });
}

        // ==================== ADD WARMUP FIELDS TO CONNECTIONS ====================
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            // Quick warmup status flags (denormalized for performance)
            if (!Schema::hasColumn('whatsapp_connections', 'warmup_enabled')) {
                $table->boolean('warmup_enabled')->default(false)->after('metadata');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'warmup_active')) {
                $table->boolean('warmup_active')->default(false)->after('warmup_enabled');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'warmup_daily_limit')) {
                $table->unsignedInteger('warmup_daily_limit')->default(0)->after('warmup_active');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'warmup_sent_today')) {
                $table->unsignedInteger('warmup_sent_today')->default(0)->after('warmup_daily_limit');
            }
            if (!Schema::hasColumn('whatsapp_connections', 'warmup_current_date')) {
                $table->date('warmup_current_date')->nullable()->after('warmup_sent_today');
            }
            
            // Index for quick lookup
            $table->index(['warmup_enabled', 'warmup_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropIndex(['warmup_enabled', 'warmup_active']);
            $table->dropColumn([
                'warmup_enabled',
                'warmup_active',
                'warmup_daily_limit',
                'warmup_sent_today',
                'warmup_current_date',
            ]);
        });
        
        Schema::dropIfExists('whatsapp_warmup_logs');
        Schema::dropIfExists('whatsapp_warmups');
    }
};
