<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * PRODUCTION READINESS REVIEW (PRR) TABLES
 * =============================================================================
 * 
 * Sistem untuk tracking Go-Live checklist dan Production Readiness Review.
 * 
 * TUJUAN:
 * 1. Mencegah kegagalan fatal saat launch
 * 2. Memastikan semua sistem kritikal siap produksi
 * 3. Memberi dasar GO / NO-GO decision
 * 4. Menyelaraskan tech, ops, & bisnis
 * 
 * =============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. PRR CATEGORIES - Kategori checklist
        // =====================================================================
        Schema::create('prr_categories', function (Blueprint $table) {
            $table->id();
            
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            
            $table->unsignedInteger('display_order')->default(0);
            $table->string('icon', 50)->nullable();
            $table->string('owner_role', 100)->nullable(); // devops, backend, security, etc.
            
            $table->boolean('is_critical')->default(false); // Must pass for GO
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });

        // =====================================================================
        // 2. PRR CHECKLIST ITEMS - Item checklist
        // =====================================================================
        Schema::create('prr_checklist_items', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('category_id')->constrained('prr_categories')->onDelete('cascade');
            
            $table->string('slug', 150)->unique();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->text('how_to_verify')->nullable(); // Instructions to verify
            $table->text('remediation')->nullable(); // How to fix if failed
            
            // Verification
            $table->enum('verification_type', [
                'manual',           // Requires human verification
                'automated',        // System can auto-check
                'semi_automated',   // System checks, human confirms
            ])->default('manual');
            
            $table->string('automated_check', 200)->nullable(); // Class::method to run
            
            // Priority
            $table->enum('severity', [
                'blocker',    // Must pass - blocks go-live
                'critical',   // Should pass - risks if ignored
                'major',      // Important but can defer
                'minor',      // Nice to have
            ])->default('major');
            
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
            
            $table->index(['category_id', 'severity']);
        });

        // =====================================================================
        // 3. PRR REVIEWS - Review sessions
        // =====================================================================
        Schema::create('prr_reviews', function (Blueprint $table) {
            $table->id();
            
            $table->string('review_id', 50)->unique(); // PRR-2026-001
            $table->string('name', 200); // "Go-Live Review v1.0"
            $table->text('description')->nullable();
            
            // Target
            $table->string('target_environment', 50)->default('production');
            $table->date('target_launch_date')->nullable();
            
            // Status
            $table->enum('status', [
                'draft',        // Being prepared
                'in_progress',  // Review ongoing
                'pending',      // Awaiting decision
                'approved',     // GO decision
                'rejected',     // NO-GO decision
                'deferred',     // Postponed
            ])->default('draft');
            
            // Decision
            $table->enum('decision', [
                'go',           // Full launch approved
                'go_limited',   // Soft launch / limited rollout
                'no_go',        // Launch blocked
                'pending',      // Not decided yet
            ])->default('pending');
            
            $table->text('decision_rationale')->nullable();
            $table->json('blockers')->nullable(); // List of blocking issues
            $table->json('risks_accepted')->nullable(); // Accepted risks
            
            // Statistics
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('passed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->unsignedInteger('pending_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->decimal('pass_rate', 5, 2)->default(0);
            
            // Sign-off
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'decision']);
        });

        // =====================================================================
        // 4. PRR REVIEW RESULTS - Hasil per item
        // =====================================================================
        Schema::create('prr_review_results', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('review_id')->constrained('prr_reviews')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('prr_checklist_items')->onDelete('cascade');
            
            // Result
            $table->enum('status', [
                'pending',      // Not checked yet
                'passed',       // Verified OK
                'failed',       // Verification failed
                'skipped',      // Not applicable
                'waived',       // Exception granted
            ])->default('pending');
            
            // Details
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable(); // Screenshots, logs, etc.
            $table->json('automated_result')->nullable(); // Result from auto-check
            
            // Waiver (if skipped or waived)
            $table->text('waiver_reason')->nullable();
            $table->unsignedBigInteger('waived_by')->nullable();
            $table->timestamp('waived_at')->nullable();
            
            // Verification
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            
            $table->timestamps();
            
            $table->unique(['review_id', 'item_id']);
            $table->index(['status', 'review_id']);
        });

        // =====================================================================
        // 5. PRR SIGN-OFFS - Approval sign-offs
        // =====================================================================
        Schema::create('prr_sign_offs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('review_id')->constrained('prr_reviews')->onDelete('cascade');
            
            $table->string('role', 100); // tech_lead, ops_lead, security, business
            $table->string('signer_name', 200);
            $table->string('signer_email', 200)->nullable();
            $table->unsignedBigInteger('signer_user_id')->nullable();
            
            $table->enum('decision', ['approve', 'reject', 'abstain'])->default('approve');
            $table->text('comments')->nullable();
            $table->json('conditions')->nullable(); // Conditions for approval
            
            $table->timestamp('signed_at');
            $table->string('signature_hash', 64)->nullable(); // SHA256 hash
            
            $table->timestamps();
            
            $table->unique(['review_id', 'role']);
        });

        // =====================================================================
        // SEED DATA - Categories & Checklist Items
        // =====================================================================
        $this->seedCategories();
        $this->seedChecklistItems();
    }

    private function seedCategories(): void
    {
        $categories = [
            [
                'slug' => 'environment-config',
                'name' => 'Environment & Configuration',
                'description' => 'Production environment settings, secrets, and configuration verification',
                'display_order' => 1,
                'icon' => '⚙️',
                'owner_role' => 'devops',
                'is_critical' => true,
            ],
            [
                'slug' => 'payment-billing',
                'name' => 'Payment & Billing',
                'description' => 'Payment gateway, subscription, and billing system verification',
                'display_order' => 2,
                'icon' => '💳',
                'owner_role' => 'backend',
                'is_critical' => true,
            ],
            [
                'slug' => 'messaging-delivery',
                'name' => 'Messaging & Delivery',
                'description' => 'WhatsApp Business API, templates, and message delivery verification',
                'display_order' => 3,
                'icon' => '📨',
                'owner_role' => 'backend',
                'is_critical' => true,
            ],
            [
                'slug' => 'data-safety',
                'name' => 'Data & Safety',
                'description' => 'Backup, data integrity, PII protection, and retention policies',
                'display_order' => 4,
                'icon' => '🔒',
                'owner_role' => 'devops',
                'is_critical' => true,
            ],
            [
                'slug' => 'scalability-performance',
                'name' => 'Scalability & Performance',
                'description' => 'Load testing, autoscaling, and performance verification',
                'display_order' => 5,
                'icon' => '📈',
                'owner_role' => 'devops',
                'is_critical' => false,
            ],
            [
                'slug' => 'observability-alerting',
                'name' => 'Observability & Alerting',
                'description' => 'Monitoring, metrics, alerts, and incident detection',
                'display_order' => 6,
                'icon' => '📊',
                'owner_role' => 'sre',
                'is_critical' => true,
            ],
            [
                'slug' => 'security-compliance',
                'name' => 'Security & Compliance',
                'description' => 'Authentication, authorization, audit, and security controls',
                'display_order' => 7,
                'icon' => '🛡️',
                'owner_role' => 'security',
                'is_critical' => true,
            ],
            [
                'slug' => 'operational-readiness',
                'name' => 'Operational Readiness',
                'description' => 'Runbooks, incident response, and operational procedures',
                'display_order' => 8,
                'icon' => '🔧',
                'owner_role' => 'sre',
                'is_critical' => true,
            ],
            [
                'slug' => 'business-customer',
                'name' => 'Business & Customer',
                'description' => 'Pricing, terms, support, and customer-facing readiness',
                'display_order' => 9,
                'icon' => '🏢',
                'owner_role' => 'business',
                'is_critical' => false,
            ],
        ];

        foreach ($categories as $cat) {
            DB::table('prr_categories')->insert(array_merge($cat, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    private function seedChecklistItems(): void
    {
        $items = [
            // ==================== ENVIRONMENT & CONFIG ====================
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-app-env-production',
                'title' => 'APP_ENV is set to production',
                'description' => 'Environment must be set to production mode',
                'how_to_verify' => 'Check .env file: APP_ENV=production',
                'remediation' => 'Set APP_ENV=production in .env file',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkAppEnv',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-app-debug-false',
                'title' => 'APP_DEBUG is set to false',
                'description' => 'Debug mode must be disabled in production',
                'how_to_verify' => 'Check .env file: APP_DEBUG=false',
                'remediation' => 'Set APP_DEBUG=false in .env file',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkAppDebug',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-app-key-set',
                'title' => 'APP_KEY is set and secure',
                'description' => 'Application encryption key must be set',
                'how_to_verify' => 'APP_KEY should start with base64: and be 32 bytes',
                'remediation' => 'Run: php artisan key:generate',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkAppKey',
                'severity' => 'blocker',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-secrets-production',
                'title' => 'All API keys are production keys (not sandbox)',
                'description' => 'Payment, WhatsApp, and third-party API keys must be production',
                'how_to_verify' => 'Verify WABA token, Midtrans/Xendit keys are production',
                'remediation' => 'Replace sandbox keys with production keys',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-timezone-correct',
                'title' => 'Timezone is correctly configured',
                'description' => 'Server and application timezone must match business timezone',
                'how_to_verify' => 'Check APP_TIMEZONE in config and compare with server timezone',
                'remediation' => 'Set correct timezone in config/app.php',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkTimezone',
                'severity' => 'critical',
                'display_order' => 5,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-cache-config',
                'title' => 'Config and routes are cached',
                'description' => 'Production must have cached config and routes for performance',
                'how_to_verify' => 'Check bootstrap/cache for config.php and routes.php',
                'remediation' => 'Run: php artisan config:cache && php artisan route:cache',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkConfigCache',
                'severity' => 'major',
                'display_order' => 6,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-queue-connection',
                'title' => 'Queue connection is production-ready',
                'description' => 'Queue must use Redis/SQS, not sync or database',
                'how_to_verify' => 'Check QUEUE_CONNECTION in .env',
                'remediation' => 'Set QUEUE_CONNECTION=redis or sqs',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkQueueConnection',
                'severity' => 'blocker',
                'display_order' => 7,
            ],
            [
                'category_slug' => 'environment-config',
                'slug' => 'env-log-channel',
                'title' => 'Log channel is production-appropriate',
                'description' => 'Logs should go to stack/daily/external service, not single file',
                'how_to_verify' => 'Check LOG_CHANNEL and LOG_LEVEL in .env',
                'remediation' => 'Set LOG_CHANNEL=stack and LOG_LEVEL=warning or error',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkLogChannel',
                'severity' => 'major',
                'display_order' => 8,
            ],

            // ==================== PAYMENT & BILLING ====================
            [
                'category_slug' => 'payment-billing',
                'slug' => 'pay-gateway-production',
                'title' => 'Payment gateway is in production mode',
                'description' => 'Midtrans/Xendit must be configured with production credentials',
                'how_to_verify' => 'Check MIDTRANS_IS_PRODUCTION=true or equivalent',
                'remediation' => 'Switch to production credentials in payment config',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkPaymentGateway',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'payment-billing',
                'slug' => 'pay-webhook-verified',
                'title' => 'Payment webhooks are tested and verified',
                'description' => 'Webhook endpoints must respond correctly to payment callbacks',
                'how_to_verify' => 'Send test payment and verify webhook processing',
                'remediation' => 'Fix webhook endpoint to handle callbacks correctly',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'payment-billing',
                'slug' => 'pay-idempotency-active',
                'title' => 'Payment idempotency protection is active',
                'description' => 'Prevent double-charge with idempotency keys',
                'how_to_verify' => 'Check IdempotencyGuard is active on payment routes',
                'remediation' => 'Enable IdempotencyGuard middleware on payment endpoints',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkPaymentIdempotency',
                'severity' => 'blocker',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'payment-billing',
                'slug' => 'pay-refund-path',
                'title' => 'Refund and cancellation flow is documented',
                'description' => 'Clear process for refunds and subscription cancellations',
                'how_to_verify' => 'Review refund runbook and test refund flow',
                'remediation' => 'Document and implement refund workflow',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'payment-billing',
                'slug' => 'pay-invoice-audit',
                'title' => 'Invoice generation and audit log active',
                'description' => 'All payments must generate invoices with audit trail',
                'how_to_verify' => 'Verify invoices table has data and audit_logs captures payment events',
                'remediation' => 'Implement invoice generation on successful payment',
                'verification_type' => 'semi_automated',
                'severity' => 'critical',
                'display_order' => 5,
            ],

            // ==================== MESSAGING & DELIVERY ====================
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-waba-production',
                'title' => 'WABA is production approved',
                'description' => 'WhatsApp Business Account must be approved for production use',
                'how_to_verify' => 'Verify in Meta Business Suite that account is approved',
                'remediation' => 'Complete WABA approval process with Meta',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-templates-approved',
                'title' => 'Message templates are approved',
                'description' => 'All templates must be approved by WhatsApp',
                'how_to_verify' => 'Check template status in Meta Business Suite',
                'remediation' => 'Submit templates for approval and wait for approval',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-rate-limit-active',
                'title' => 'Rate limiting is active and configured',
                'description' => 'Message rate limiting must be active per WhatsApp policies',
                'how_to_verify' => 'Verify RateLimitGuard middleware is active',
                'remediation' => 'Enable rate limiting middleware on messaging endpoints',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkRateLimiting',
                'severity' => 'blocker',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-throttling-active',
                'title' => 'Campaign throttling is active',
                'description' => 'Campaign sending must be throttled to prevent bans',
                'how_to_verify' => 'Verify CampaignThrottleService is configured',
                'remediation' => 'Configure throttling parameters in config/throttle.php',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkThrottling',
                'severity' => 'blocker',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-quota-guard-active',
                'title' => 'Quota guard is active',
                'description' => 'User quota must be enforced before sending',
                'how_to_verify' => 'Verify QuotaService is called before message dispatch',
                'remediation' => 'Enable quota checking in message dispatch flow',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkQuotaGuard',
                'severity' => 'blocker',
                'display_order' => 5,
            ],
            [
                'category_slug' => 'messaging-delivery',
                'slug' => 'msg-delivery-webhook',
                'title' => 'Delivery report webhooks are configured',
                'description' => 'WABA webhooks must be configured to receive delivery reports',
                'how_to_verify' => 'Verify webhook URL is registered in Meta Business Suite',
                'remediation' => 'Configure webhook URL in Meta Business Suite',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 6,
            ],

            // ==================== DATA & SAFETY ====================
            [
                'category_slug' => 'data-safety',
                'slug' => 'data-backup-configured',
                'title' => 'Database backup is configured and tested',
                'description' => 'Regular automated backups must be running',
                'how_to_verify' => 'Verify backup schedule and test restore',
                'remediation' => 'Configure automated backup solution',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'data-safety',
                'slug' => 'data-restore-tested',
                'title' => 'Database restore has been tested',
                'description' => 'Restore from backup must be tested and documented',
                'how_to_verify' => 'Perform test restore to staging environment',
                'remediation' => 'Test restore procedure and document results',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'data-safety',
                'slug' => 'data-migrations-applied',
                'title' => 'All migrations are applied',
                'description' => 'No pending migrations in production',
                'how_to_verify' => 'Run php artisan migrate:status',
                'remediation' => 'Run php artisan migrate',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkMigrations',
                'severity' => 'blocker',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'data-safety',
                'slug' => 'data-retention-active',
                'title' => 'Legal log retention is active',
                'description' => 'Retention policies must be configured and jobs scheduled',
                'how_to_verify' => 'Verify retention policies exist and archive jobs are scheduled',
                'remediation' => 'Configure retention policies in database',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkRetentionPolicies',
                'severity' => 'critical',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'data-safety',
                'slug' => 'data-pii-protection',
                'title' => 'PII data is protected',
                'description' => 'Personal data must be encrypted or masked appropriately',
                'how_to_verify' => 'Review data models for PII and verify encryption',
                'remediation' => 'Implement encryption for sensitive fields',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 5,
            ],

            // ==================== SCALABILITY & PERFORMANCE ====================
            [
                'category_slug' => 'scalability-performance',
                'slug' => 'scale-queue-workers',
                'title' => 'Queue workers are configured for scale',
                'description' => 'Worker count and memory limits are appropriate',
                'how_to_verify' => 'Review queue worker configuration and supervisor setup',
                'remediation' => 'Configure appropriate worker count and limits',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'scalability-performance',
                'slug' => 'scale-db-connections',
                'title' => 'Database connection pool is configured',
                'description' => 'Connection limits and timeouts are appropriate',
                'how_to_verify' => 'Check database connection pool settings',
                'remediation' => 'Configure connection pool in database.php',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkDbConnections',
                'severity' => 'critical',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'scalability-performance',
                'slug' => 'scale-load-test',
                'title' => 'Load testing has been performed',
                'description' => 'System has been tested under expected load',
                'how_to_verify' => 'Review load test results and verify acceptable performance',
                'remediation' => 'Perform load testing with k6 or artillery',
                'verification_type' => 'manual',
                'severity' => 'major',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'scalability-performance',
                'slug' => 'scale-cache-configured',
                'title' => 'Caching is properly configured',
                'description' => 'Redis or Memcached is configured for caching',
                'how_to_verify' => 'Check CACHE_DRIVER and verify Redis connection',
                'remediation' => 'Configure Redis as cache driver',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkCacheDriver',
                'severity' => 'major',
                'display_order' => 4,
            ],

            // ==================== OBSERVABILITY & ALERTING ====================
            [
                'category_slug' => 'observability-alerting',
                'slug' => 'obs-metrics-available',
                'title' => 'Key metrics are being collected',
                'description' => 'Delivery rate, latency, error rate metrics are available',
                'how_to_verify' => 'Verify SLI measurements are being recorded',
                'remediation' => 'Implement SLI recording in critical paths',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkMetrics',
                'severity' => 'critical',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'observability-alerting',
                'slug' => 'obs-alerts-configured',
                'title' => 'Alert rules are configured',
                'description' => 'Alert thresholds are set for critical metrics',
                'how_to_verify' => 'Verify alert_rules table has active rules',
                'remediation' => 'Configure alert rules for critical metrics',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkAlertRules',
                'severity' => 'critical',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'observability-alerting',
                'slug' => 'obs-error-budget-healthy',
                'title' => 'Error budget is healthy',
                'description' => 'No SLOs should be in critical or exhausted state',
                'how_to_verify' => 'Run php artisan budget:status',
                'remediation' => 'Resolve issues causing budget consumption',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkErrorBudget',
                'severity' => 'blocker',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'observability-alerting',
                'slug' => 'obs-status-page-live',
                'title' => 'Status page is live and accessible',
                'description' => 'Public status page must be available',
                'how_to_verify' => 'Access status page URL and verify it loads',
                'remediation' => 'Deploy and configure status page',
                'verification_type' => 'manual',
                'severity' => 'major',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'observability-alerting',
                'slug' => 'obs-oncall-owner',
                'title' => 'On-call owner is assigned',
                'description' => 'Clear on-call schedule and escalation path',
                'how_to_verify' => 'Verify on-call schedule exists and is current',
                'remediation' => 'Set up on-call schedule and escalation policy',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 5,
            ],

            // ==================== SECURITY & COMPLIANCE ====================
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-auth-tested',
                'title' => 'Authentication is tested and secure',
                'description' => 'Login, logout, password reset flows are tested',
                'how_to_verify' => 'Test authentication flows and verify security',
                'remediation' => 'Fix authentication issues found',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-rbac-verified',
                'title' => 'Role-based access control is verified',
                'description' => 'User roles and permissions are correctly enforced',
                'how_to_verify' => 'Test access to resources with different roles',
                'remediation' => 'Fix permission issues',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-audit-log-active',
                'title' => 'Admin action audit logging is active',
                'description' => 'All admin actions must be logged for audit',
                'how_to_verify' => 'Verify audit_logs table captures admin actions',
                'remediation' => 'Implement audit logging for admin actions',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkAuditLogging',
                'severity' => 'critical',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-api-rate-limit',
                'title' => 'API rate limiting is active',
                'description' => 'API endpoints are protected by rate limiting',
                'how_to_verify' => 'Verify throttle middleware on API routes',
                'remediation' => 'Add throttle middleware to API routes',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkApiRateLimit',
                'severity' => 'critical',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-webhook-signature',
                'title' => 'Webhook signature validation is active',
                'description' => 'Incoming webhooks must validate signatures',
                'how_to_verify' => 'Review webhook handlers for signature validation',
                'remediation' => 'Implement signature validation in webhook handlers',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 5,
            ],
            [
                'category_slug' => 'security-compliance',
                'slug' => 'sec-no-debug-endpoints',
                'title' => 'No debug endpoints exposed',
                'description' => 'Telescope, debugbar, and debug routes must be disabled',
                'how_to_verify' => 'Try accessing debug endpoints and verify they fail',
                'remediation' => 'Disable debug packages in production',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkDebugEndpoints',
                'severity' => 'blocker',
                'display_order' => 6,
            ],

            // ==================== OPERATIONAL READINESS ====================
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-runbook-available',
                'title' => 'Runbooks are available',
                'description' => 'Operational runbooks for common tasks exist',
                'how_to_verify' => 'Review runbook documentation',
                'remediation' => 'Create runbooks for common operations',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-incident-drill',
                'title' => 'Incident response drill completed',
                'description' => 'Team has practiced incident response',
                'how_to_verify' => 'Review drill documentation and results',
                'remediation' => 'Conduct incident response drill',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-chaos-test-passed',
                'title' => 'Chaos testing has been performed',
                'description' => 'System has been tested for resilience',
                'how_to_verify' => 'Review chaos experiment results',
                'remediation' => 'Run chaos experiments in staging',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkChaosTests',
                'severity' => 'major',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-rollback-plan',
                'title' => 'Rollback plan is documented',
                'description' => 'Clear rollback procedure exists',
                'how_to_verify' => 'Review rollback documentation',
                'remediation' => 'Document rollback procedure',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-killswitch-ready',
                'title' => 'Kill switch is ready',
                'description' => 'Emergency stop mechanism exists and is tested',
                'how_to_verify' => 'Verify kill switch command or feature flag exists',
                'remediation' => 'Implement emergency kill switch',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkKillSwitch',
                'severity' => 'blocker',
                'display_order' => 5,
            ],
            [
                'category_slug' => 'operational-readiness',
                'slug' => 'ops-escalation-path',
                'title' => 'Support escalation path is defined',
                'description' => 'Clear escalation path from support to engineering',
                'how_to_verify' => 'Review escalation documentation',
                'remediation' => 'Document escalation procedure',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 6,
            ],

            // ==================== BUSINESS & CUSTOMER ====================
            [
                'category_slug' => 'business-customer',
                'slug' => 'biz-pricing-final',
                'title' => 'Pricing and packages are finalized',
                'description' => 'All pricing tiers and features are confirmed',
                'how_to_verify' => 'Review pricing configuration',
                'remediation' => 'Finalize pricing with business team',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 1,
            ],
            [
                'category_slug' => 'business-customer',
                'slug' => 'biz-terms-ready',
                'title' => 'Terms of Service are ready',
                'description' => 'Legal terms, privacy policy, acceptable use policy ready',
                'how_to_verify' => 'Review legal documents',
                'remediation' => 'Finalize legal documents with legal team',
                'verification_type' => 'manual',
                'severity' => 'blocker',
                'display_order' => 2,
            ],
            [
                'category_slug' => 'business-customer',
                'slug' => 'biz-status-templates',
                'title' => 'Status communication templates are ready',
                'description' => 'Templates for incident/maintenance communication',
                'how_to_verify' => 'Review status_update_templates table',
                'remediation' => 'Create communication templates',
                'verification_type' => 'automated',
                'automated_check' => 'App\\Services\\PRRCheckService::checkStatusTemplates',
                'severity' => 'major',
                'display_order' => 3,
            ],
            [
                'category_slug' => 'business-customer',
                'slug' => 'biz-refund-policy',
                'title' => 'Refund and dispute policy is documented',
                'description' => 'Clear refund policy for customers',
                'how_to_verify' => 'Review refund policy documentation',
                'remediation' => 'Document refund policy',
                'verification_type' => 'manual',
                'severity' => 'critical',
                'display_order' => 4,
            ],
            [
                'category_slug' => 'business-customer',
                'slug' => 'biz-owner-dashboard',
                'title' => 'Owner monitoring dashboard is ready',
                'description' => 'Business owners can monitor key metrics',
                'how_to_verify' => 'Access owner dashboard and verify data',
                'remediation' => 'Implement owner dashboard',
                'verification_type' => 'manual',
                'severity' => 'major',
                'display_order' => 5,
            ],
        ];

        // Get category IDs
        $categories = DB::table('prr_categories')->pluck('id', 'slug');

        foreach ($items as $item) {
            $categorySlug = $item['category_slug'];
            unset($item['category_slug']);

            DB::table('prr_checklist_items')->insert(array_merge($item, [
                'category_id' => $categories[$categorySlug],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prr_sign_offs');
        Schema::dropIfExists('prr_review_results');
        Schema::dropIfExists('prr_reviews');
        Schema::dropIfExists('prr_checklist_items');
        Schema::dropIfExists('prr_categories');
    }
};
