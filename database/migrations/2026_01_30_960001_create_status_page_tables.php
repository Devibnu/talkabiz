<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * STATUS PAGE & CUSTOMER COMMUNICATION TABLES
 * 
 * Sistem untuk:
 * - Publish status transparan ke customer
 * - Single source of truth
 * - Integrasi dengan incident response
 * - Multi-channel communication
 * 
 * Prinsip:
 * - Append-only untuk audit trail
 * - Bahasa non-teknis, profesional
 * - Tidak panik, tidak menyalahkan
 */
return new class extends Migration
{
    public function up(): void
    {
        // ==================== SYSTEM COMPONENTS ====================
        // Daftar komponen yang di-monitor di status page
        Schema::create('system_components', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique(); // campaign_sending, inbox, etc
            $table->string('name', 100);          // Display name
            $table->string('description')->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_critical')->default(false); // Affect global status
            $table->boolean('is_visible')->default(true);   // Show on public page
            $table->string('current_status', 30)->default('operational');
            $table->timestamp('status_changed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['is_visible', 'display_order']);
            $table->index('current_status');
        });

        // ==================== COMPONENT STATUS HISTORY ====================
        // Append-only history of component status changes
        Schema::create('component_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('system_components')->cascadeOnDelete();
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->string('source', 30)->default('system'); // system, admin, incident
            $table->unsignedBigInteger('source_id')->nullable(); // incident_id atau admin_id
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable(); // user_id jika admin
            $table->timestamp('changed_at');
            $table->json('metrics_snapshot')->nullable(); // Metrics saat change
            
            $table->index(['component_id', 'changed_at']);
            $table->index(['source', 'source_id']);
        });

        // ==================== GLOBAL SYSTEM STATUS ====================
        // Snapshot global system status (append-only)
        Schema::create('system_status_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('status', 30); // operational, degraded, partial_outage, major_outage, maintenance
            $table->string('title', 200)->nullable(); // Short status message
            $table->text('description')->nullable();  // Longer explanation
            $table->string('source', 30)->default('system');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable(); // Untuk maintenance
            $table->json('component_statuses')->nullable(); // Snapshot semua component
            $table->timestamps();
            
            $table->index('effective_at');
            $table->index(['status', 'effective_at']);
        });

        // ==================== STATUS INCIDENTS (PUBLIC) ====================
        // Public-facing incidents (sanitized version of internal incidents)
        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 20)->unique(); // INC-20260130-001 (public facing)
            $table->unsignedBigInteger('internal_incident_id')->nullable(); // Link to incidents table
            $table->string('title', 200);
            $table->string('status', 30)->default('investigating');
            // investigating, identified, monitoring, resolved
            $table->string('impact', 30)->default('minor');
            // none, minor, major, critical
            $table->text('summary')->nullable(); // High-level summary
            $table->json('affected_components')->nullable(); // [component_ids]
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('identified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index(['is_published', 'started_at']);
            $table->index('status');
            $table->index('internal_incident_id');
        });

        // ==================== STATUS UPDATES (APPEND-ONLY) ====================
        // Updates for status incidents - IMMUTABLE
        Schema::create('status_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_incident_id')->constrained('status_incidents')->cascadeOnDelete();
            $table->string('status', 30); // Status at time of update
            $table->text('message'); // Public message
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at');
            
            // IMMUTABLE - no updated_at, no soft deletes
            $table->index(['status_incident_id', 'created_at']);
        });

        // ==================== SCHEDULED MAINTENANCES ====================
        Schema::create('scheduled_maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 20)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->json('affected_components')->nullable();
            $table->string('impact', 30)->default('minor');
            $table->timestamp('scheduled_start');
            $table->timestamp('scheduled_end');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->string('status', 30)->default('scheduled');
            // scheduled, in_progress, completed, cancelled
            $table->text('completion_message')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index(['status', 'scheduled_start']);
            $table->index(['is_published', 'scheduled_start']);
        });

        // ==================== CUSTOMER NOTIFICATIONS ====================
        // Log of notifications sent to customers
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('notification_type', 50);
            // incident_notice, incident_update, maintenance_scheduled, maintenance_started, 
            // maintenance_completed, status_change, general_announcement
            $table->string('channel', 30); // email, in_app, whatsapp, sms, webhook
            $table->unsignedBigInteger('user_id')->nullable(); // Null = broadcast
            $table->morphs('notifiable'); // status_incident, scheduled_maintenance, etc
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('pending');
            // pending, sent, delivered, failed, bounced
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
            // morphs() already creates notifiable_type_notifiable_id index
            $table->index(['channel', 'status']);
        });

        // ==================== NOTIFICATION SUBSCRIPTIONS ====================
        // User preferences for notifications
        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 30); // email, in_app, whatsapp, sms
            $table->boolean('incidents')->default(true);
            $table->boolean('maintenances')->default(true);
            $table->boolean('status_changes')->default(false);
            $table->boolean('announcements')->default(true);
            $table->json('component_filters')->nullable(); // Only specific components
            $table->string('email')->nullable(); // Override email
            $table->string('phone')->nullable();  // For SMS/WA
            $table->string('webhook_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->unique(['user_id', 'channel']);
            $table->index(['channel', 'is_active']);
        });

        // ==================== STATUS PAGE METRICS ====================
        // Trust metrics tracking
        Schema::create('status_page_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date');
            $table->string('metric_type', 50);
            // response_time, update_latency, uptime, incident_count, 
            // support_tickets, complaint_rate, notification_delivery
            $table->string('component_slug', 50)->nullable();
            $table->decimal('value', 12, 4);
            $table->string('unit', 20)->nullable();
            $table->json('breakdown')->nullable();
            $table->timestamps();
            
            $table->unique(['metric_date', 'metric_type', 'component_slug'], 'spm_date_type_component_unique');
            $table->index(['metric_type', 'metric_date'], 'spm_type_date_idx');
        });

        // ==================== IN-APP BANNERS ====================
        // Real-time in-app notifications/banners
        Schema::create('in_app_banners', function (Blueprint $table) {
            $table->id();
            $table->string('banner_type', 30);
            // incident, maintenance, announcement, warning, info
            $table->string('severity', 20)->default('info');
            // info, warning, danger, success
            $table->string('title', 200);
            $table->text('message');
            $table->string('link_text', 100)->nullable();
            $table->string('link_url', 500)->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('target_users')->nullable(); // Null = all, or [user_ids]
            $table->json('target_components')->nullable(); // Affected components
            $table->morphs('source'); // status_incident, scheduled_maintenance
            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            
            $table->index(['is_active', 'starts_at', 'expires_at']);
        });

        // ==================== BANNER DISMISSALS ====================
        Schema::create('banner_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained('in_app_banners')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('dismissed_at');
            
            $table->unique(['banner_id', 'user_id']);
        });

        // ==================== SEED DEFAULT COMPONENTS ====================
        $this->seedDefaultComponents();
    }

    /**
     * Seed default system components
     */
    private function seedDefaultComponents(): void
    {
        $components = [
            [
                'slug' => 'campaign_sending',
                'name' => 'Campaign Sending',
                'description' => 'Pengiriman pesan campaign WhatsApp',
                'display_order' => 1,
                'is_critical' => true,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'inbox',
                'name' => 'Inbox & Chat',
                'description' => 'Menerima dan membalas pesan masuk',
                'display_order' => 2,
                'is_critical' => true,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'billing',
                'name' => 'Billing & Pembayaran',
                'description' => 'Proses pembayaran dan top-up saldo',
                'display_order' => 3,
                'is_critical' => false,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'whatsapp_api',
                'name' => 'WhatsApp API',
                'description' => 'Koneksi ke WhatsApp Business Platform',
                'display_order' => 4,
                'is_critical' => true,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'webhook_processing',
                'name' => 'Webhook & Notifikasi',
                'description' => 'Pemrosesan status pengiriman dan notifikasi',
                'display_order' => 5,
                'is_critical' => false,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'dashboard',
                'name' => 'Dashboard & Reporting',
                'description' => 'Akses dashboard dan laporan',
                'display_order' => 6,
                'is_critical' => false,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'template_management',
                'name' => 'Template Management',
                'description' => 'Pembuatan dan persetujuan template pesan',
                'display_order' => 7,
                'is_critical' => false,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
            [
                'slug' => 'contact_management',
                'name' => 'Contact & Audience',
                'description' => 'Manajemen kontak dan segmentasi audience',
                'display_order' => 8,
                'is_critical' => false,
                'is_visible' => true,
                'current_status' => 'operational',
            ],
        ];

        $now = now();
        foreach ($components as $component) {
            $component['status_changed_at'] = $now;
            $component['created_at'] = $now;
            $component['updated_at'] = $now;
            DB::table('system_components')->insert($component);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_dismissals');
        Schema::dropIfExists('in_app_banners');
        Schema::dropIfExists('status_page_metrics');
        Schema::dropIfExists('notification_subscriptions');
        Schema::dropIfExists('customer_notifications');
        Schema::dropIfExists('scheduled_maintenances');
        Schema::dropIfExists('status_updates');
        Schema::dropIfExists('status_incidents');
        Schema::dropIfExists('system_status_snapshots');
        Schema::dropIfExists('component_status_history');
        Schema::dropIfExists('system_components');
    }
};
