<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL: Default SLA & Support Channel Data
 * 
 * Seeds the system with standard SLA definitions and support channels
 * for common subscription packages. This provides working defaults
 * that can be customized per business requirements.
 * 
 * PACKAGE TIERS:
 * - Starter: Basic email support with 24h response
 * - Professional: Email + chat with 4h response  
 * - Enterprise: Full support with 1h response + phone
 */
return new class extends Migration
{
    public function up(): void
    {
        // Default SLA Definitions — only seed if table is empty
        if (Schema::hasTable('sla_definitions') && DB::table('sla_definitions')->count() === 0) {
        DB::table('sla_definitions')->insertOrIgnore([
            [
                'package_name' => 'Starter',
                'package_code' => 'STARTER',
                'package_features' => json_encode(['basic_support', 'email_only']),
                
                // Response Times (minutes)
                'response_time_critical' => 720,  // 12 hours
                'response_time_high' => 1440,     // 24 hours
                'response_time_medium' => 2880,   // 48 hours
                'response_time_low' => 4320,      // 72 hours
                
                // Resolution Times (hours)
                'resolution_time_critical' => 72,  // 3 days
                'resolution_time_high' => 120,     // 5 days
                'resolution_time_medium' => 168,   // 1 week
                'resolution_time_low' => 336,      // 2 weeks
                
                // Channel Access
                'available_channels' => json_encode(['email']),
                'has_dedicated_support' => false,
                'has_phone_support' => false,
                'has_priority_queue' => false,
                
                // Business Hours
                'business_hours' => json_encode([
                    'monday' => ['09:00', '17:00'],
                    'tuesday' => ['09:00', '17:00'],
                    'wednesday' => ['09:00', '17:00'],
                    'thursday' => ['09:00', '17:00'],
                    'friday' => ['09:00', '17:00'],
                    'weekend' => false
                ]),
                'coverage_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']),
                
                // Escalation
                'auto_escalation_rules' => json_encode([
                    'response_breach_hours' => 48,
                    'resolution_breach_hours' => 168
                ]),
                'max_escalation_level' => 2,
                'escalation_contacts' => json_encode([]),
                
                // Targets
                'target_first_response_rate' => 90.00,
                'target_resolution_rate' => 85.00,
                'description' => 'Basic email support for Starter package',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'package_name' => 'Professional',
                'package_code' => 'PROFESSIONAL',
                'package_features' => json_encode(['priority_support', 'chat_support', 'email_support']),
                
                // Response Times (minutes)
                'response_time_critical' => 240,  // 4 hours
                'response_time_high' => 480,      // 8 hours
                'response_time_medium' => 720,    // 12 hours
                'response_time_low' => 1440,      // 24 hours
                
                // Resolution Times (hours)
                'resolution_time_critical' => 24,  // 1 day
                'resolution_time_high' => 48,      // 2 days
                'resolution_time_medium' => 72,    // 3 days
                'resolution_time_low' => 120,      // 5 days
                
                // Channel Access
                'available_channels' => json_encode(['email', 'chat']),
                'has_dedicated_support' => false,
                'has_phone_support' => false,
                'has_priority_queue' => true,
                
                // Business Hours
                'business_hours' => json_encode([
                    'monday' => ['08:00', '18:00'],
                    'tuesday' => ['08:00', '18:00'],
                    'wednesday' => ['08:00', '18:00'],
                    'thursday' => ['08:00', '18:00'],
                    'friday' => ['08:00', '18:00'],
                    'weekend' => ['10:00', '16:00'] // Limited weekend support
                ]),
                'coverage_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']),
                
                // Escalation
                'auto_escalation_rules' => json_encode([
                    'response_breach_hours' => 12,
                    'resolution_breach_hours' => 48
                ]),
                'max_escalation_level' => 3,
                'escalation_contacts' => json_encode(['support-manager@talkabiz.com']),
                
                // Targets
                'target_first_response_rate' => 95.00,
                'target_resolution_rate' => 90.00,
                'description' => 'Priority support with chat for Professional package',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'package_name' => 'Enterprise',
                'package_code' => 'ENTERPRISE',
                'package_features' => json_encode(['dedicated_support', 'phone_support', 'priority_queue', '24x7']),
                
                // Response Times (minutes)
                'response_time_critical' => 60,   // 1 hour
                'response_time_high' => 120,      // 2 hours
                'response_time_medium' => 240,    // 4 hours
                'response_time_low' => 480,       // 8 hours
                
                // Resolution Times (hours)
                'resolution_time_critical' => 4,   // 4 hours
                'resolution_time_high' => 12,      // 12 hours
                'resolution_time_medium' => 24,    // 1 day
                'resolution_time_low' => 48,       // 2 days
                
                // Channel Access
                'available_channels' => json_encode(['email', 'chat', 'phone', 'priority_support']),
                'has_dedicated_support' => true,
                'has_phone_support' => true,
                'has_priority_queue' => true,
                
                // Business Hours (24/7)
                'business_hours' => json_encode([
                    'monday' => ['00:00', '23:59'],
                    'tuesday' => ['00:00', '23:59'],
                    'wednesday' => ['00:00', '23:59'],
                    'thursday' => ['00:00', '23:59'],
                    'friday' => ['00:00', '23:59'],
                    'saturday' => ['00:00', '23:59'],
                    'sunday' => ['00:00', '23:59']
                ]),
                'coverage_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
                
                // Escalation
                'auto_escalation_rules' => json_encode([
                    'response_breach_hours' => 2,
                    'resolution_breach_hours' => 8,
                    'critical_immediate_escalation' => true
                ]),
                'max_escalation_level' => 4,
                'escalation_contacts' => json_encode(['support-manager@talkabiz.com', 'cto@talkabiz.com']),
                
                // Targets
                'target_first_response_rate' => 99.00,
                'target_resolution_rate' => 95.00,
                'description' => 'Premium 24/7 support with dedicated agents for Enterprise',
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
        } // end sla_definitions check

        // Default Support Channels — only seed if table is empty
        if (Schema::hasTable('support_channels') && DB::table('support_channels')->count() === 0) {
            // Insert each channel separately (different column sets)
            $channels = [
            [
                'channel_name' => 'Email Support',
                'channel_code' => 'EMAIL',
                'channel_description' => 'Traditional email-based support for all ticket types',
                'channel_type' => 'async',
                
                // Package Access
                'available_for_packages' => json_encode(['starter', 'professional', 'enterprise']),
                'requires_package_verification' => true,
                
                // Configuration
                'is_active' => true,
                'is_available_24_7' => false,
                'operating_hours' => json_encode([
                    'start' => '09:00',
                    'end' => '17:00',
                    'timezone' => 'Asia/Jakarta'
                ]),
                'operating_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']),
                
                // Capacity
                'max_daily_tickets' => 500,
                'current_load' => 0,
                
                // SLA
                'expected_response_time_minutes' => 480, // 8 hours default
                'max_response_time_minutes' => 1440,     // 24 hours max
                'supports_instant_response' => false,
                'supports_file_attachments' => true,
                'max_attachment_size_mb' => 25,
                
                // Assignment
                'assigned_agent_teams' => json_encode(['L1', 'L2']),
                'escalation_rules' => json_encode(['auto_escalate_after_24h' => true]),
                'min_agent_experience_months' => 0,
                
                // Quality Targets
                'target_customer_satisfaction' => 4.20,
                'target_first_contact_resolution' => 75.00,
                'max_handoff_count' => 2,
                
                // Settings
                'channel_settings' => json_encode([
                    'auto_acknowledgment' => true,
                    'auto_categorization' => true,
                    'spam_filtering' => true
                ]),
                
                'includes_in_sla_reports' => true,
                'requires_customer_authentication' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'channel_name' => 'Live Chat Support',
                'channel_code' => 'CHAT',
                'channel_description' => 'Real-time chat support for Professional and Enterprise customers',
                'channel_type' => 'sync',
                
                // Package Access (Professional+)
                'available_for_packages' => json_encode(['professional', 'enterprise']),
                'requires_package_verification' => true,
                
                // Configuration
                'is_active' => true,
                'is_available_24_7' => false,
                'operating_hours' => json_encode([
                    'weekday_start' => '08:00',
                    'weekday_end' => '20:00',
                    'weekend_start' => '10:00',
                    'weekend_end' => '16:00'
                ]),
                'operating_days' => json_encode(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']),
                
                // Capacity
                'max_concurrent_sessions' => 50,
                'max_daily_tickets' => 200,
                'current_load' => 0,
                
                // SLA
                'expected_response_time_minutes' => 5,  // 5 minutes for chat
                'max_response_time_minutes' => 15,      // 15 minutes max
                'supports_instant_response' => true,
                'supports_file_attachments' => true,
                'max_attachment_size_mb' => 10,
                
                // Assignment
                'assigned_agent_teams' => json_encode(['L1', 'L2', 'specialist']),
                'escalation_rules' => json_encode(['auto_escalate_after_30_min' => true]),
                'requires_specialized_agents' => true,
                'min_agent_experience_months' => 3,
                
                // Quality Targets
                'target_customer_satisfaction' => 4.50,
                'target_first_contact_resolution' => 85.00,
                'max_handoff_count' => 1,
                
                // Settings
                'channel_settings' => json_encode([
                    'queue_management' => true,
                    'typing_indicators' => true,
                    'file_sharing' => true,
                    'screen_sharing' => false
                ]),
                'supports_chatbot' => true,
                'supports_auto_routing' => true,
                'supports_sentiment_analysis' => true,
                
                'includes_in_sla_reports' => true,
                'requires_chat_logging' => true,
                'requires_customer_authentication' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'channel_name' => 'Phone Support',
                'channel_code' => 'PHONE',
                'channel_description' => 'Direct phone support for Enterprise customers only',
                'channel_type' => 'sync',
                
                // Package Access (Enterprise only)
                'available_for_packages' => json_encode(['enterprise']),
                'requires_package_verification' => true,
                
                // Configuration
                'is_active' => true,
                'is_available_24_7' => true, // For Enterprise
                'operating_hours' => json_encode(['24x7' => true]),
                'operating_days' => json_encode(['all_days' => true]),
                
                // Capacity
                'max_concurrent_sessions' => 20,
                'max_daily_tickets' => 100,
                'current_load' => 0,
                
                // SLA
                'expected_response_time_minutes' => 2,  // Answer within 2 minutes
                'max_response_time_minutes' => 5,       // 5 minutes max hold
                'supports_instant_response' => true,
                'supports_file_attachments' => false,
                
                // Assignment
                'assigned_agent_teams' => json_encode(['L2', 'L3', 'specialist']),
                'escalation_rules' => json_encode(['immediate_escalation_critical' => true]),
                'requires_specialized_agents' => true,
                'min_agent_experience_months' => 12,
                
                // Quality Targets
                'target_customer_satisfaction' => 4.70,
                'target_first_contact_resolution' => 90.00,
                'max_handoff_count' => 1,
                
                // Settings
                'channel_settings' => json_encode([
                    'call_recording' => true,
                    'call_queuing' => true,
                    'ivr_system' => true,
                    'callback_option' => true
                ]),
                'cost_per_interaction' => 15.50,
                'agent_cost_per_hour' => 75.00,
                
                'includes_in_sla_reports' => true,
                'requires_call_recording' => true,
                'requires_customer_authentication' => true,
                'available_during_emergencies' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'channel_name' => 'Priority Support Queue',
                'channel_code' => 'PRIORITY',
                'channel_description' => 'High-priority dedicated queue for critical issues',
                'channel_type' => 'hybrid',
                
                // Package Access (Professional+)
                'available_for_packages' => json_encode(['professional', 'enterprise']),
                'requires_package_verification' => true,
                
                // Configuration
                'is_active' => true,
                'is_available_24_7' => false,
                'operating_hours' => json_encode([
                    'extended_hours' => '06:00-22:00',
                    'timezone' => 'Asia/Jakarta'
                ]),
                
                // Capacity
                'max_daily_tickets' => 50,
                'current_load' => 0,
                
                // SLA (Aggressive)
                'expected_response_time_minutes' => 60,  // 1 hour
                'max_response_time_minutes' => 120,      // 2 hours max
                'supports_instant_response' => false,
                'supports_file_attachments' => true,
                'max_attachment_size_mb' => 50,
                
                // Assignment
                'assigned_agent_teams' => json_encode(['L2', 'L3', 'management']),
                'escalation_rules' => json_encode(['immediate_management_visibility' => true]),
                'requires_specialized_agents' => true,
                'min_agent_experience_months' => 6,
                
                // Quality Targets
                'target_customer_satisfaction' => 4.80,
                'target_first_contact_resolution' => 95.00,
                'max_handoff_count' => 0,
                
                // Settings
                'channel_settings' => json_encode([
                    'priority_routing' => true,
                    'management_notifications' => true,
                    'executive_visibility' => true
                ]),
                'requires_manager_approval' => false, // Auto-approved for eligible packages
                
                'includes_in_sla_reports' => true,
                'requires_customer_authentication' => true,
                'available_during_emergencies' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]
            ];
            foreach ($channels as $channel) {
                DB::table('support_channels')->insertOrIgnore($channel);
            }
        } // end support_channels check
    }

    public function down(): void
    {
        DB::table('support_channels')->delete();
        DB::table('sla_definitions')->delete();
    }
};