<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RateLimitRule;
use Illuminate\Support\Facades\DB;

class RateLimitRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('rate_limit_rules')->delete();

        $rules = [
            // ============ CRITICAL ENDPOINTS - BLOCK HIGH RISK ============
            [
                'name' => 'Critical Risk - API Messaging Block',
                'description' => 'Block high/critical risk users from sending messages',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => 'critical',
                'saldo_status' => null,
                'max_requests' => 0,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 100,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
                'block_message' => 'Your account has been flagged for suspicious activity. Please contact support.',
            ],
            
            // ============ HIGH RISK - AGGRESSIVE LIMITS ============
            [
                'name' => 'High Risk - API Messaging Limit',
                'description' => 'Strict rate limit for high-risk users',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => 'high',
                'saldo_status' => null,
                'max_requests' => 15,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 90,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            [
                'name' => 'High Risk - Campaign Creation',
                'description' => 'Limit campaign creation for high-risk users',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/campaigns',
                'risk_level' => 'high',
                'saldo_status' => null,
                'max_requests' => 5,
                'window_seconds' => 3600,
                'algorithm' => 'token_bucket',
                'action' => 'block',
                'priority' => 90,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            // ============ MEDIUM RISK - MODERATE LIMITS ============
            [
                'name' => 'Medium Risk - API Messaging Limit',
                'description' => 'Moderate rate limit for medium-risk users',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => 'medium',
                'saldo_status' => null,
                'max_requests' => 30,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'throttle',
                'throttle_delay_ms' => 1000,
                'priority' => 70,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            [
                'name' => 'Medium Risk - Campaign Creation',
                'description' => 'Moderate campaign creation limit',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/campaigns',
                'risk_level' => 'medium',
                'saldo_status' => null,
                'max_requests' => 10,
                'window_seconds' => 3600,
                'algorithm' => 'token_bucket',
                'action' => 'throttle',
                'throttle_delay_ms' => 2000,
                'priority' => 70,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            // ============ LOW SALDO - PROTECTIVE LIMITS ============
            [
                'name' => 'Zero Saldo - API Messaging Block',
                'description' => 'Block messaging when saldo is zero',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => null,
                'saldo_status' => 'zero',
                'max_requests' => 0,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 95,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
                'block_message' => 'Insufficient balance. Please top up your account to continue.',
            ],
            
            [
                'name' => 'Critical Saldo - API Messaging Limit',
                'description' => 'Strict limit when saldo is critically low',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => null,
                'saldo_status' => 'critical',
                'max_requests' => 20,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'throttle',
                'throttle_delay_ms' => 1500,
                'priority' => 80,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            [
                'name' => 'Low Saldo - API Messaging Limit',
                'description' => 'Moderate limit when saldo is low',
                'context_type' => 'user',
                'endpoint_pattern' => '/api/messages/*',
                'risk_level' => null,
                'saldo_status' => 'low',
                'max_requests' => 50,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'warn',
                'priority' => 60,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            // ============ ENDPOINT-SPECIFIC LIMITS ============
            [
                'name' => 'API Messages - Default Limit',
                'description' => 'Default rate limit for message sending',
                'context_type' => 'endpoint',
                'endpoint_pattern' => '/api/messages/send',
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 10,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 50,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
                'block_message' => 'Message rate limit exceeded. Please wait before sending more messages.',
            ],
            
            [
                'name' => 'API Broadcasts - Default Limit',
                'description' => 'Default rate limit for broadcast messages',
                'context_type' => 'endpoint',
                'endpoint_pattern' => '/api/broadcasts',
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 5,
                'window_seconds' => 300,
                'algorithm' => 'token_bucket',
                'action' => 'block',
                'priority' => 50,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
                'block_message' => 'Broadcast rate limit exceeded. Please wait before creating another broadcast.',
            ],
            
            [
                'name' => 'Campaign Creation - Default Limit',
                'description' => 'Default rate limit for campaign creation',
                'context_type' => 'endpoint',
                'endpoint_pattern' => '/api/campaigns',
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 20,
                'window_seconds' => 3600,
                'algorithm' => 'token_bucket',
                'action' => 'throttle',
                'throttle_delay_ms' => 500,
                'priority' => 50,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
            
            [
                'name' => 'Contact Import - Default Limit',
                'description' => 'Rate limit for contact imports',
                'context_type' => 'endpoint',
                'endpoint_pattern' => '/api/contacts/import',
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 10,
                'window_seconds' => 3600,
                'algorithm' => 'token_bucket',
                'action' => 'block',
                'priority' => 50,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
                'block_message' => 'Contact import rate limit exceeded. Please wait before importing again.',
            ],
            
            // ============ GUEST PROTECTION (UNAUTHENTICATED) ============
            [
                'name' => 'Guest - API Rate Limit',
                'description' => 'Strict rate limit for unauthenticated requests',
                'context_type' => 'ip',
                'endpoint_pattern' => '/api/*',
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 30,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'block',
                'priority' => 40,
                'is_active' => true,
                'applies_to_authenticated' => false,
                'applies_to_guest' => true,
                'block_message' => 'Rate limit exceeded. Please authenticate to increase your limit.',
            ],
            
            // ============ GLOBAL FALLBACK ============
            [
                'name' => 'Global - Authenticated Users',
                'description' => 'Global rate limit for all authenticated users',
                'context_type' => 'user',
                'endpoint_pattern' => null,
                'risk_level' => null,
                'saldo_status' => null,
                'max_requests' => 120,
                'window_seconds' => 60,
                'algorithm' => 'sliding_window',
                'action' => 'warn',
                'priority' => 10,
                'is_active' => true,
                'applies_to_authenticated' => true,
                'applies_to_guest' => false,
            ],
        ];

        foreach ($rules as $rule) {
            RateLimitRule::create($rule);
        }

        $this->command->info('✅ Created ' . count($rules) . ' rate limit rules');
    }
}
