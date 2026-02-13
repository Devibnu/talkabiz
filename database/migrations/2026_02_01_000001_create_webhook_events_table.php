<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table untuk idempotency check dan audit trail webhook events
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            
            // Event identification (untuk idempotency)
            $table->string('event_id')->unique()->comment('Unique event ID dari provider atau generated hash');
            $table->string('provider')->default('gupshup')->index();
            
            // Event details
            $table->string('event_type')->nullable();
            $table->string('phone_number')->nullable()->index();
            $table->string('app_id')->nullable();
            
            // Status tracking
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->boolean('status_changed')->default(false);
            
            // Processing result
            $table->enum('result', ['processed', 'ignored', 'rejected', 'error'])->default('processed');
            $table->string('result_reason')->nullable();
            
            // Security audit
            $table->string('source_ip')->nullable();
            $table->string('payload_hash')->nullable()->comment('SHA256 hash of raw payload');
            $table->boolean('signature_valid')->default(false);
            $table->boolean('ip_valid')->default(false);
            
            // Raw data (encrypted in production)
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            
            // Reference to connection if matched
            $table->foreignId('whatsapp_connection_id')->nullable()->constrained('whatsapp_connections')->nullOnDelete();
            
            $table->timestamps();
            
            // Indexes for querying
            $table->index(['provider', 'created_at']);
            $table->index(['phone_number', 'app_id']);
            $table->index(['result', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
