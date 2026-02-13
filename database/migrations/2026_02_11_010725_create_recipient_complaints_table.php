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
        Schema::create('recipient_complaints', function (Blueprint $table) {
            $table->id();
            
            // Foreign Keys
            $table->unsignedBigInteger('klien_id');
            $table->foreign('klien_id')->references('id')->on('klien')->onDelete('cascade');
            
            // Recipient Information
            $table->string('recipient_phone', 20)->index();
            $table->string('recipient_name', 255)->nullable();
            
            // Complaint Details
            $table->enum('complaint_type', [
                'spam',           // Unsolicited messages
                'abuse',          // Harassment or threatening
                'phishing',       // Fraudulent/scam messages
                'inappropriate',  // Offensive content
                'frequency',      // Too many messages
                'other'
            ])->index();
            
            $table->enum('complaint_source', [
                'provider_webhook',  // From WhatsApp/SMS provider
                'manual_report',     // Manually reported by recipient
                'internal_flag',     // Flagged by internal system
                'third_party'        // From third-party monitoring
            ])->default('provider_webhook');
            
            $table->string('provider_name', 50)->nullable()->index(); // gupshup, twilio, vonage, etc
            
            // Message Reference (optional)
            $table->string('message_id', 100)->nullable()->index();
            $table->text('message_content_sample')->nullable(); // First 500 chars of message
            
            // Complaint Reason & Metadata
            $table->text('complaint_reason')->nullable();
            $table->json('complaint_metadata')->nullable(); // Provider-specific data
            
            // Severity Assessment
            $table->enum('severity', [
                'low',      // Minor issue, isolated incident
                'medium',   // Multiple complaints or moderate issue
                'high',     // Serious violation or pattern detected
                'critical'  // Severe abuse, immediate action required
            ])->default('low')->index();
            
            // Processing Status
            $table->boolean('is_processed')->default(false)->index();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable(); // User ID who processed
            
            // Abuse Score Impact
            $table->decimal('abuse_score_impact', 8, 2)->default(0); // Score added to abuse_scores
            $table->unsignedBigInteger('abuse_event_id')->nullable(); // Link to abuse_events
            $table->foreign('abuse_event_id')->references('id')->on('abuse_events')->onDelete('set null');
            
            // Action Taken
            $table->string('action_taken', 100)->nullable(); // warn, suspend, escalate, etc
            $table->text('action_notes')->nullable();
            
            // Timestamps
            $table->timestamp('complaint_received_at')->useCurrent()->index();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['klien_id', 'complaint_type']);
            $table->index(['klien_id', 'is_processed']);
            $table->index(['klien_id', 'severity']);
            $table->index(['provider_name', 'complaint_received_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipient_complaints');
    }
};
