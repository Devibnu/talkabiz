<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. WhatsApp Connections (Cloud API via Gupshup)
        if (!Schema::hasTable('whatsapp_connections')) {
            Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->string('gupshup_app_id')->nullable();
            $table->string('business_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->enum('status', ['disconnected', 'pending', 'connected', 'restricted'])->default('disconnected');
            $table->text('api_key')->nullable(); // Encrypted
            $table->text('api_secret')->nullable(); // Encrypted
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable(); // Additional Gupshup data
            $table->timestamps();
            
            $table->unique('klien_id');
            $table->index('status');
            $table->index('gupshup_app_id');
        });
}

        // 2. WhatsApp Templates (approved templates from Gupshup)
        if (!Schema::hasTable('whatsapp_templates')) {
            Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->string('template_id'); // Gupshup template ID
            $table->string('name');
            $table->string('category')->nullable(); // MARKETING, UTILITY, AUTHENTICATION
            $table->string('language')->default('id');
            $table->json('components')->nullable(); // Header, body, footer, buttons
            $table->text('sample_text')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'paused'])->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            
            $table->unique(['klien_id', 'template_id']);
            $table->index('status');
        });
}

        // 3. WhatsApp Contacts (opt-in contacts for blast)
        if (!Schema::hasTable('whatsapp_contacts')) {
            Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->string('phone_number');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->json('tags')->nullable(); // For segmentation
            $table->json('custom_fields')->nullable();
            $table->boolean('opted_in')->default(false);
            $table->timestamp('opted_in_at')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->string('opt_in_source')->nullable(); // website, import, manual
            $table->timestamps();
            
            $table->unique(['klien_id', 'phone_number']);
            $table->index('opted_in');
            $table->index('phone_number');
        });
}

        // 4. WhatsApp Campaigns (WA Blast)
        if (!Schema::hasTable('whatsapp_campaigns')) {
            Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->foreignId('template_id')->constrained('whatsapp_templates')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'running', 'paused', 'completed', 'cancelled'])->default('draft');
            $table->json('audience_filter')->nullable(); // Filter criteria for contacts
            $table->json('template_variables')->nullable(); // Variable mappings
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->decimal('estimated_cost', 10, 2)->default(0);
            $table->decimal('actual_cost', 10, 2)->default(0);
            $table->unsignedInteger('rate_limit_per_second')->default(10); // Safe default
            $table->timestamps();
            
            $table->index('status');
            $table->index('scheduled_at');
        });
}

        // 5. Campaign Recipients (individual message tracking)
        if (!Schema::hasTable('whatsapp_campaign_recipients')) {
            Schema::create('whatsapp_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('whatsapp_campaigns')->onDelete('cascade');
            $table->foreignId('contact_id')->constrained('whatsapp_contacts')->onDelete('cascade');
            $table->string('message_id')->nullable(); // Gupshup message ID
            $table->string('phone_number');
            $table->enum('status', ['pending', 'queued', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('cost', 8, 4)->default(0);
            $table->timestamps();
            
            $table->unique(['campaign_id', 'contact_id']);
            $table->index('message_id');
            $table->index('status');
        });
}

        // 6. WhatsApp Message Logs (all messages in/out)
        if (!Schema::hasTable('whatsapp_message_logs')) {
            Schema::create('whatsapp_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('klien_id')->constrained('klien')->onDelete('cascade');
            $table->string('message_id')->nullable(); // Gupshup message ID
            $table->enum('direction', ['inbound', 'outbound'])->default('outbound');
            $table->string('phone_number');
            $table->string('template_id')->nullable();
            $table->text('content')->nullable();
            $table->json('media')->nullable(); // Media attachments
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('cost', 8, 4)->default(0);
            $table->foreignId('campaign_id')->nullable()->constrained('whatsapp_campaigns')->onDelete('set null');
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index('message_id');
            $table->index(['klien_id', 'direction']);
            $table->index('phone_number');
            $table->index('status');
        });
}

        // 7. Webhook Logs (for debugging)
        if (!Schema::hasTable('whatsapp_webhook_logs')) {
            Schema::create('whatsapp_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->json('payload');
            $table->enum('processing_status', ['received', 'processed', 'failed'])->default('received');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('event_type');
            $table->index('created_at');
        });
}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_logs');
        Schema::dropIfExists('whatsapp_message_logs');
        Schema::dropIfExists('whatsapp_campaign_recipients');
        Schema::dropIfExists('whatsapp_campaigns');
        Schema::dropIfExists('whatsapp_contacts');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_connections');
    }
};
