<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRITICAL: SLA Definitions per Subscription Package
 * 
 * This table defines Service Level Agreements for each subscription package:
 * - Response time commitments
 * - Resolution time commitments  
 * - Available support channels
 * - Priority levels per package
 * 
 * BUSINESS RULES:
 * - ❌ NO bypassing SLA commitments
 * - ✅ SLA MUST be tied to subscription package
 * - ✅ Clear expectations per package tier
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sla_definitions')) {
            Schema::create('sla_definitions', function (Blueprint $table) {
            $table->id();
            
            // Package Identification
            $table->string('package_name', 50); // starter, professional, enterprise
            $table->string('package_code', 20)->unique(); // STR, PRO, ENT
            $table->json('package_features')->nullable(); // Additional package info
            
            // Response Time SLAs (in minutes)
            $table->unsignedInteger('response_time_critical')->default(60); // Critical issues
            $table->unsignedInteger('response_time_high')->default(240); // High priority 
            $table->unsignedInteger('response_time_medium')->default(720); // Medium priority
            $table->unsignedInteger('response_time_low')->default(1440); // Low priority
            
            // Resolution Time SLAs (in hours)
            $table->unsignedInteger('resolution_time_critical')->default(4); // Critical issues
            $table->unsignedInteger('resolution_time_high')->default(24); // High priority
            $table->unsignedInteger('resolution_time_medium')->default(72); // Medium priority
            $table->unsignedInteger('resolution_time_low')->default(168); // Low priority (1 week)
            
            // Support Channel Access
            $table->json('available_channels'); // ['email', 'chat', 'phone', 'priority_support']
            $table->boolean('has_dedicated_support')->default(false);
            $table->boolean('has_phone_support')->default(false);
            $table->boolean('has_priority_queue')->default(false);
            
            // Business Hours & Coverage
            $table->json('business_hours'); // Operating hours per timezone
            $table->json('coverage_days'); // Days of week coverage
            $table->string('timezone', 50)->default('Asia/Jakarta');
            
            // Priority & Escalation Rules
            $table->json('auto_escalation_rules'); // When to escalate
            $table->unsignedInteger('max_escalation_level')->default(3);
            $table->json('escalation_contacts')->nullable(); // Who gets escalated tickets
            
            // SLA Tracking & Metrics
            $table->decimal('target_first_response_rate', 5, 2)->default(95.00); // 95% target
            $table->decimal('target_resolution_rate', 5, 2)->default(90.00); // 90% target
            $table->boolean('is_active')->default(true);
            
            // Metadata
            $table->text('description')->nullable();
            $table->json('terms_conditions')->nullable(); // SLA terms
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['package_code', 'is_active']);
            $table->index('effective_from');
        });
}
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_definitions');
    }
};