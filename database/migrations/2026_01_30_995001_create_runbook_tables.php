<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * SOC / NOC RUNBOOK TABLES
 * =============================================================================
 * 
 * Sistem runbook untuk operasi 24/7 SOC/NOC shift operator.
 * 
 * TUJUAN:
 * 1. Respon cepat & konsisten untuk insiden
 * 2. Minimalkan MTTR (Mean Time To Recovery)
 * 3. Lindungi reputasi & revenue
 * 4. Pastikan eskalasi tepat & terdokumentasi
 * 5. Siap audit & postmortem
 * 
 * =============================================================================
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // 1. RUNBOOK ROLES - Peran dalam operasi
        // =====================================================================
        Schema::create('runbook_roles', function (Blueprint $table) {
            $table->id();
            
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('level'); // L1, L2, L3, IC
            
            // Responsibilities
            $table->json('responsibilities')->nullable();
            $table->json('permissions')->nullable(); // Can escalate, can deploy, etc.
            
            // Escalation
            $table->unsignedInteger('escalation_order')->default(0);
            $table->unsignedInteger('response_sla_minutes')->default(15);
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // =====================================================================
        // 2. ON-CALL CONTACTS - Kontak darurat
        // =====================================================================
        Schema::create('oncall_contacts', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('role_id')->constrained('runbook_roles')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable();
            
            $table->string('name', 200);
            $table->string('email', 200)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('slack_handle', 100)->nullable();
            $table->string('telegram_id', 100)->nullable();
            
            // Schedule
            $table->enum('schedule_type', ['primary', 'secondary', 'backup'])->default('primary');
            $table->date('rotation_start')->nullable();
            $table->date('rotation_end')->nullable();
            $table->json('schedule_days')->nullable(); // [1,2,3,4,5] = Mon-Fri
            $table->time('shift_start')->nullable();
            $table->time('shift_end')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['role_id', 'is_active']);
        });

        // =====================================================================
        // 3. SHIFT CHECKLISTS - Checklist per shift
        // =====================================================================
        Schema::create('shift_checklists', function (Blueprint $table) {
            $table->id();
            
            $table->string('slug', 100)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            
            $table->enum('shift_type', ['start', 'hourly', 'end'])->default('start');
            $table->unsignedInteger('display_order')->default(0);
            
            // What to check
            $table->string('check_type', 50); // dashboard, metric, alert, external
            $table->string('check_target', 200)->nullable(); // URL, command, metric name
            $table->json('expected_values')->nullable(); // Thresholds
            
            $table->boolean('is_critical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // =====================================================================
        // 4. SHIFT LOGS - Log shift operator
        // =====================================================================
        Schema::create('shift_logs', function (Blueprint $table) {
            $table->id();
            
            $table->string('shift_id', 50)->unique(); // SHIFT-2026-01-30-A
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 200);
            
            $table->enum('shift_type', ['morning', 'afternoon', 'night'])->default('morning');
            $table->timestamp('shift_start');
            $table->timestamp('shift_end')->nullable();
            
            // Status
            $table->enum('status', ['active', 'completed', 'handover'])->default('active');
            
            // Checklist completion
            $table->json('checklist_completed')->nullable();
            $table->text('handover_notes')->nullable();
            
            // Summary
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedInteger('alerts_acknowledged')->default(0);
            $table->unsignedInteger('escalations_made')->default(0);
            
            $table->timestamps();
            
            $table->index(['shift_start', 'status']);
        });

        // =====================================================================
        // 5. SHIFT CHECKLIST RESULTS - Hasil checklist
        // =====================================================================
        Schema::create('shift_checklist_results', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('shift_log_id')->constrained('shift_logs')->onDelete('cascade');
            $table->foreignId('checklist_id')->constrained('shift_checklists')->onDelete('cascade');
            
            $table->enum('status', ['pending', 'ok', 'warning', 'critical', 'skipped'])->default('pending');
            $table->text('notes')->nullable();
            $table->json('observed_values')->nullable();
            
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            
            $table->unique(['shift_log_id', 'checklist_id']);
        });

        // =====================================================================
        // 6. INCIDENT PLAYBOOKS - Playbook untuk insiden
        // =====================================================================
        Schema::create('incident_playbooks', function (Blueprint $table) {
            $table->id();
            
            $table->string('slug', 100)->unique();
            $table->string('title', 200);
            $table->text('description')->nullable();
            
            // Trigger conditions
            $table->string('trigger_type', 50); // ban, delivery_drop, queue_backlog, payment_fail, abuse
            $table->json('trigger_conditions')->nullable(); // Metric thresholds
            
            // Severity
            $table->enum('default_severity', ['sev1', 'sev2', 'sev3', 'sev4'])->default('sev3');
            
            // Response
            $table->json('immediate_actions')->nullable(); // Steps to take immediately
            $table->json('diagnostic_steps')->nullable(); // How to diagnose
            $table->json('mitigation_steps')->nullable(); // How to mitigate
            $table->json('escalation_path')->nullable(); // Who to escalate to
            $table->json('communication_template')->nullable(); // Status page template
            
            // Recovery
            $table->json('recovery_verification')->nullable(); // How to verify recovery
            $table->json('post_incident_tasks')->nullable(); // What to do after
            
            $table->unsignedInteger('estimated_mttr_minutes')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('trigger_type');
        });

        // =====================================================================
        // 7. PLAYBOOK EXECUTIONS - Eksekusi playbook
        // =====================================================================
        Schema::create('playbook_executions', function (Blueprint $table) {
            $table->id();
            
            $table->string('execution_id', 50)->unique(); // PB-2026-01-30-001
            $table->foreignId('playbook_id')->constrained('incident_playbooks')->onDelete('cascade');
            $table->string('incident_id', 50)->nullable(); // Link to incident
            $table->foreignId('shift_log_id')->nullable()->constrained('shift_logs')->onDelete('set null');
            
            $table->unsignedBigInteger('executed_by')->nullable();
            $table->string('executor_name', 200);
            
            // Execution
            $table->enum('status', ['started', 'in_progress', 'completed', 'aborted', 'escalated'])->default('started');
            $table->json('steps_completed')->nullable();
            $table->json('steps_skipped')->nullable();
            $table->text('execution_notes')->nullable();
            
            // Timing
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            
            // Outcome
            $table->enum('outcome', ['resolved', 'mitigated', 'escalated', 'failed', 'pending'])->default('pending');
            $table->text('outcome_notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'started_at']);
        });

        // =====================================================================
        // 8. ESCALATION LOGS - Log eskalasi
        // =====================================================================
        Schema::create('escalation_logs', function (Blueprint $table) {
            $table->id();
            
            $table->string('escalation_id', 50)->unique();
            $table->string('incident_id', 50)->nullable();
            $table->foreignId('execution_id')->nullable()->constrained('playbook_executions')->onDelete('set null');
            
            // From -> To
            $table->foreignId('from_role_id')->nullable()->constrained('runbook_roles')->onDelete('set null');
            $table->foreignId('to_role_id')->constrained('runbook_roles')->onDelete('cascade');
            $table->unsignedBigInteger('escalated_by')->nullable();
            $table->string('escalator_name', 200);
            
            // Details
            $table->enum('severity', ['sev1', 'sev2', 'sev3', 'sev4'])->default('sev3');
            $table->string('reason', 500);
            $table->text('context')->nullable();
            $table->json('attachments')->nullable(); // Links, screenshots
            
            // Response
            $table->enum('status', ['pending', 'acknowledged', 'actioned', 'resolved', 'expired'])->default('pending');
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('response_notes')->nullable();
            
            // SLA
            $table->unsignedInteger('sla_minutes')->default(15);
            $table->boolean('sla_breached')->default(false);
            
            $table->timestamps();
            
            $table->index(['status', 'severity']);
        });

        // =====================================================================
        // 9. OPERATOR ACTIONS LOG - Audit log aksi operator
        // =====================================================================
        Schema::create('operator_action_logs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('shift_log_id')->nullable()->constrained('shift_logs')->onDelete('set null');
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_name', 200);
            
            $table->string('action_type', 100); // acknowledge_alert, execute_playbook, escalate, etc.
            $table->string('action_target', 200)->nullable(); // What was actioned
            $table->json('action_details')->nullable();
            
            $table->string('incident_id', 50)->nullable();
            $table->string('alert_id', 50)->nullable();
            
            $table->text('notes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            
            $table->timestamps();
            
            $table->index(['action_type', 'created_at']);
            $table->index('incident_id');
        });

        // =====================================================================
        // 10. COMMUNICATION LOGS - Log komunikasi
        // =====================================================================
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            
            $table->string('incident_id', 50)->nullable();
            $table->foreignId('shift_log_id')->nullable()->constrained('shift_logs')->onDelete('set null');
            
            $table->enum('channel', ['slack', 'email', 'status_page', 'sms', 'phone', 'other']);
            $table->enum('direction', ['internal', 'external']);
            $table->enum('type', ['update', 'escalation', 'resolution', 'info']);
            
            $table->string('subject', 300)->nullable();
            $table->text('message');
            $table->json('recipients')->nullable();
            
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('sender_name', 200);
            $table->timestamp('sent_at');
            
            $table->boolean('approved_by_ic')->default(false);
            
            $table->timestamps();
            
            $table->index(['incident_id', 'sent_at']);
        });

        // =====================================================================
        // SEED DATA
        // =====================================================================
        $this->seedRoles();
        $this->seedShiftChecklists();
        $this->seedPlaybooks();
    }

    private function seedRoles(): void
    {
        $roles = [
            [
                'slug' => 'noc-l1',
                'name' => 'NOC Operator (L1)',
                'description' => 'First-line monitoring, acknowledge alerts, basic mitigation',
                'level' => 1,
                'responsibilities' => json_encode([
                    'Monitor dashboards 24/7',
                    'Acknowledge alerts within 5 minutes',
                    'Execute standard playbooks',
                    'Escalate to L2 if needed',
                    'Document all actions',
                    'Update shift logs',
                ]),
                'permissions' => json_encode([
                    'acknowledge_alert',
                    'execute_playbook',
                    'pause_campaign',
                    'escalate_l2',
                    'update_shift_log',
                ]),
                'escalation_order' => 1,
                'response_sla_minutes' => 5,
            ],
            [
                'slug' => 'soc-l2',
                'name' => 'SOC Analyst (L2)',
                'description' => 'Risk analysis, abuse detection, security response',
                'level' => 2,
                'responsibilities' => json_encode([
                    'Analyze risk and abuse signals',
                    'Investigate security incidents',
                    'Suspend malicious users',
                    'Coordinate with BSP/providers',
                    'Escalate to L3/IC if needed',
                ]),
                'permissions' => json_encode([
                    'acknowledge_alert',
                    'execute_playbook',
                    'suspend_user',
                    'throttle_global',
                    'escalate_l3',
                    'access_audit_logs',
                ]),
                'escalation_order' => 2,
                'response_sla_minutes' => 15,
            ],
            [
                'slug' => 'sre-l3',
                'name' => 'SRE On-Call (L3)',
                'description' => 'Technical fixes, rollback, infrastructure changes',
                'level' => 3,
                'responsibilities' => json_encode([
                    'Diagnose and fix technical issues',
                    'Execute rollback if needed',
                    'Scale infrastructure',
                    'Deploy hotfixes',
                    'Coordinate with IC',
                ]),
                'permissions' => json_encode([
                    'execute_playbook',
                    'deploy_hotfix',
                    'rollback_deploy',
                    'scale_infrastructure',
                    'access_production',
                    'modify_config',
                ]),
                'escalation_order' => 3,
                'response_sla_minutes' => 15,
            ],
            [
                'slug' => 'incident-commander',
                'name' => 'Incident Commander (IC)',
                'description' => 'Coordinate incident response, make decisions',
                'level' => 4,
                'responsibilities' => json_encode([
                    'Coordinate all responders',
                    'Make go/no-go decisions',
                    'Approve external communications',
                    'Declare incident start/end',
                    'Own postmortem scheduling',
                ]),
                'permissions' => json_encode([
                    'declare_incident',
                    'approve_communication',
                    'freeze_deploy',
                    'activate_killswitch',
                    'all_playbooks',
                ]),
                'escalation_order' => 4,
                'response_sla_minutes' => 10,
            ],
            [
                'slug' => 'business-owner',
                'name' => 'Business Owner',
                'description' => 'Business decisions, customer communication',
                'level' => 5,
                'responsibilities' => json_encode([
                    'Make business impact decisions',
                    'Approve customer communications',
                    'Handle VIP escalations',
                    'Coordinate with stakeholders',
                ]),
                'permissions' => json_encode([
                    'approve_customer_comms',
                    'waive_sla',
                    'approve_credits',
                ]),
                'escalation_order' => 5,
                'response_sla_minutes' => 30,
            ],
        ];

        foreach ($roles as $role) {
            DB::table('runbook_roles')->insert(array_merge($role, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    private function seedShiftChecklists(): void
    {
        $checklists = [
            // SHIFT START
            [
                'slug' => 'check-dashboard-metrics',
                'title' => 'Check Dashboard Metrics',
                'description' => 'Review main dashboard for anomalies',
                'shift_type' => 'start',
                'display_order' => 1,
                'check_type' => 'dashboard',
                'check_target' => '/dashboard/metrics',
                'is_critical' => true,
            ],
            [
                'slug' => 'check-error-budget',
                'title' => 'Check Error Budget & Burn Rate',
                'description' => 'Verify error budget status for all SLOs',
                'shift_type' => 'start',
                'display_order' => 2,
                'check_type' => 'command',
                'check_target' => 'php artisan budget:status',
                'expected_values' => json_encode(['remaining_percent' => '>= 25']),
                'is_critical' => true,
            ],
            [
                'slug' => 'check-queue-depth',
                'title' => 'Check Queue Depth & Latency',
                'description' => 'Verify queue is not backing up',
                'shift_type' => 'start',
                'display_order' => 3,
                'check_type' => 'metric',
                'check_target' => 'queue.jobs.pending',
                'expected_values' => json_encode(['max' => 10000]),
                'is_critical' => true,
            ],
            [
                'slug' => 'check-delivery-rate',
                'title' => 'Check Delivery & Failure Rate',
                'description' => 'Verify delivery rate is healthy',
                'shift_type' => 'start',
                'display_order' => 4,
                'check_type' => 'metric',
                'check_target' => 'messaging.delivery_rate',
                'expected_values' => json_encode(['min' => 95]),
                'is_critical' => true,
            ],
            [
                'slug' => 'check-waba-status',
                'title' => 'Check WABA/BSP Status',
                'description' => 'Verify WhatsApp Business API connection',
                'shift_type' => 'start',
                'display_order' => 5,
                'check_type' => 'external',
                'check_target' => 'waba_health_check',
                'is_critical' => true,
            ],
            [
                'slug' => 'check-status-page',
                'title' => 'Check Status Page & Active Alerts',
                'description' => 'Review status page and any active alerts',
                'shift_type' => 'start',
                'display_order' => 6,
                'check_type' => 'dashboard',
                'check_target' => '/status',
                'is_critical' => false,
            ],
            [
                'slug' => 'check-pending-incidents',
                'title' => 'Check Pending Incidents',
                'description' => 'Review any ongoing or unresolved incidents',
                'shift_type' => 'start',
                'display_order' => 7,
                'check_type' => 'command',
                'check_target' => 'php artisan incident:list --status=open',
                'is_critical' => true,
            ],
            [
                'slug' => 'review-handover-notes',
                'title' => 'Review Handover Notes',
                'description' => 'Read notes from previous shift',
                'shift_type' => 'start',
                'display_order' => 8,
                'check_type' => 'manual',
                'is_critical' => true,
            ],

            // HOURLY
            [
                'slug' => 'hourly-queue-check',
                'title' => 'Hourly Queue Health Check',
                'description' => 'Quick check on queue depth and processing rate',
                'shift_type' => 'hourly',
                'display_order' => 1,
                'check_type' => 'metric',
                'check_target' => 'queue.jobs.pending',
                'is_critical' => false,
            ],
            [
                'slug' => 'hourly-alert-review',
                'title' => 'Hourly Alert Review',
                'description' => 'Review and acknowledge new alerts',
                'shift_type' => 'hourly',
                'display_order' => 2,
                'check_type' => 'dashboard',
                'check_target' => '/alerts',
                'is_critical' => false,
            ],

            // SHIFT END
            [
                'slug' => 'end-update-shift-log',
                'title' => 'Update Shift Log',
                'description' => 'Complete shift log with summary',
                'shift_type' => 'end',
                'display_order' => 1,
                'check_type' => 'manual',
                'is_critical' => true,
            ],
            [
                'slug' => 'end-prepare-handover',
                'title' => 'Prepare Handover Notes',
                'description' => 'Document anything important for next shift',
                'shift_type' => 'end',
                'display_order' => 2,
                'check_type' => 'manual',
                'is_critical' => true,
            ],
            [
                'slug' => 'end-verify-no-pending',
                'title' => 'Verify No Pending Actions',
                'description' => 'Ensure all alerts acknowledged, actions documented',
                'shift_type' => 'end',
                'display_order' => 3,
                'check_type' => 'manual',
                'is_critical' => true,
            ],
        ];

        foreach ($checklists as $checklist) {
            if (!isset($checklist['expected_values'])) {
                $checklist['expected_values'] = null;
            }
            DB::table('shift_checklists')->insert(array_merge($checklist, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    private function seedPlaybooks(): void
    {
        $playbooks = [
            // BAN / QUALITY DROP
            [
                'slug' => 'waba-ban-quality-drop',
                'title' => '🔴 WABA Ban / Quality Rating Drop',
                'description' => 'Handle WhatsApp Business account ban or quality rating degradation',
                'trigger_type' => 'ban',
                'trigger_conditions' => json_encode([
                    'quality_rating' => 'Yellow OR Red',
                    'messaging_limit' => 'Decreased',
                    'account_status' => 'Flagged OR Banned',
                ]),
                'default_severity' => 'sev1',
                'immediate_actions' => json_encode([
                    '1. PAUSE all active campaigns immediately',
                    '2. Enable GLOBAL THROTTLE to minimum rate',
                    '3. Check WhatsApp Business Manager for status',
                    '4. Notify SOC L2 and IC immediately',
                    '5. Update status page to DEGRADED',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Check quality rating in WA Business Manager',
                    '2. Review recent template rejection rates',
                    '3. Check user complaint signals',
                    '4. Review abuse detection logs',
                    '5. Identify problematic campaigns/users',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Suspend high-risk users immediately',
                    '2. If backup sender available, switch traffic',
                    '3. Reduce sending rate by 80%',
                    '4. Enable stricter content filtering',
                    '5. Contact BSP support if ban',
                ]),
                'escalation_path' => json_encode([
                    'L1 → L2 (SOC)' => 'Immediately on detection',
                    'L2 → IC' => 'If ban confirmed',
                    'IC → Business' => 'If revenue impact > $1000/hour',
                ]),
                'communication_template' => json_encode([
                    'status' => 'degraded',
                    'title' => 'Message Delivery Degraded',
                    'message' => 'We are experiencing reduced message delivery capacity. Our team is actively working to restore full service. Campaign sending may be delayed.',
                ]),
                'recovery_verification' => json_encode([
                    '1. Quality rating returned to Green',
                    '2. Messaging limit restored',
                    '3. Delivery rate > 95%',
                    '4. No new complaints in 1 hour',
                ]),
                'post_incident_tasks' => json_encode([
                    '1. Document root cause',
                    '2. Identify and permanently ban abusive users',
                    '3. Update abuse detection rules',
                    '4. Schedule postmortem within 48 hours',
                ]),
                'estimated_mttr_minutes' => 120,
            ],

            // DELIVERY DROP
            [
                'slug' => 'delivery-rate-drop',
                'title' => '🟠 Delivery Rate Drop',
                'description' => 'Handle sudden drop in message delivery rate',
                'trigger_type' => 'delivery_drop',
                'trigger_conditions' => json_encode([
                    'delivery_rate' => '< 90%',
                    'failure_rate' => '> 10%',
                    'trend' => 'Decreasing for 15+ minutes',
                ]),
                'default_severity' => 'sev2',
                'immediate_actions' => json_encode([
                    '1. Check BSP/WABA status page',
                    '2. Reduce sending rate by 50%',
                    '3. Monitor failure codes',
                    '4. Notify SOC L2',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Check error codes in delivery reports',
                    '2. Verify BSP connectivity',
                    '3. Check if specific templates failing',
                    '4. Review rate limit status',
                    '5. Check Meta status page',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Reduce global rate limit',
                    '2. Pause non-critical campaigns',
                    '3. Retry failed messages with backoff',
                    '4. Enable alternate routing if available',
                ]),
                'escalation_path' => json_encode([
                    'L1 → L2' => 'If not recovered in 15 min',
                    'L2 → L3' => 'If infrastructure issue suspected',
                    'L2 → IC' => 'If SLA impact confirmed',
                ]),
                'recovery_verification' => json_encode([
                    '1. Delivery rate > 95%',
                    '2. Failure rate < 2%',
                    '3. Stable for 30 minutes',
                ]),
                'estimated_mttr_minutes' => 45,
            ],

            // QUEUE BACKLOG
            [
                'slug' => 'queue-backlog',
                'title' => '🔵 Queue Backlog',
                'description' => 'Handle queue depth exceeding threshold',
                'trigger_type' => 'queue_backlog',
                'trigger_conditions' => json_encode([
                    'queue_depth' => '> 10000 jobs',
                    'queue_latency' => '> 5 minutes',
                    'worker_count' => 'Below expected',
                ]),
                'default_severity' => 'sev2',
                'immediate_actions' => json_encode([
                    '1. Check worker health and count',
                    '2. Pause new campaign scheduling',
                    '3. Notify SRE L3',
                    '4. Monitor queue drain rate',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Check worker processes: php artisan queue:monitor',
                    '2. Review worker memory/CPU usage',
                    '3. Check for failed jobs spike',
                    '4. Verify Redis/database connectivity',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Scale up queue workers',
                    '2. Clear stuck/dead jobs if safe',
                    '3. Temporarily increase worker memory',
                    '4. Enable priority queue processing',
                ]),
                'escalation_path' => json_encode([
                    'L1 → L3' => 'Immediately if workers down',
                    'L3 → IC' => 'If scaling not possible',
                ]),
                'recovery_verification' => json_encode([
                    '1. Queue depth < 1000',
                    '2. Queue latency < 1 minute',
                    '3. All workers healthy',
                ]),
                'estimated_mttr_minutes' => 30,
            ],

            // PAYMENT FAILURE
            [
                'slug' => 'payment-webhook-failure',
                'title' => '🟣 Payment / Webhook Failure',
                'description' => 'Handle payment gateway or webhook issues',
                'trigger_type' => 'payment_fail',
                'trigger_conditions' => json_encode([
                    'webhook_success_rate' => '< 95%',
                    'payment_error_rate' => '> 5%',
                    'callback_timeout' => '> 30 seconds',
                ]),
                'default_severity' => 'sev2',
                'immediate_actions' => json_encode([
                    '1. Check payment gateway status page',
                    '2. Verify webhook endpoint health',
                    '3. Notify IC immediately',
                    '4. DO NOT process duplicate payments',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Check webhook logs for errors',
                    '2. Verify payment gateway credentials',
                    '3. Test webhook endpoint manually',
                    '4. Review recent deployment changes',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Enable payment retry queue',
                    '2. FREEZE billing if double-charge risk',
                    '3. Queue failed webhooks for replay',
                    '4. Enable maintenance mode for checkout if severe',
                ]),
                'escalation_path' => json_encode([
                    'L1 → IC' => 'Immediately on payment issues',
                    'IC → Business' => 'If customer impact confirmed',
                    'L3' => 'If code fix needed',
                ]),
                'recovery_verification' => json_encode([
                    '1. Webhook success rate > 99%',
                    '2. No duplicate transactions',
                    '3. Failed webhooks replayed',
                    '4. Billing unfrozen',
                ]),
                'post_incident_tasks' => json_encode([
                    '1. Reconcile all transactions',
                    '2. Identify failed payments for retry',
                    '3. Notify affected customers',
                ]),
                'estimated_mttr_minutes' => 60,
            ],

            // ABUSE / SECURITY
            [
                'slug' => 'abuse-security-incident',
                'title' => '🔒 Abuse / Security Incident',
                'description' => 'Handle abuse detection or security incident',
                'trigger_type' => 'abuse',
                'trigger_conditions' => json_encode([
                    'abuse_score' => '> 80',
                    'spam_reports' => '> 10 in 1 hour',
                    'suspicious_activity' => 'Detected',
                ]),
                'default_severity' => 'sev2',
                'immediate_actions' => json_encode([
                    '1. Identify suspected user/account',
                    '2. SUSPEND account immediately',
                    '3. Preserve all evidence (DO NOT DELETE)',
                    '4. Notify SOC L2 and IC',
                    '5. Log event in security audit',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Cross-check risk score factors',
                    '2. Review user activity logs',
                    '3. Check content for policy violations',
                    '4. Verify identity/registration data',
                    '5. Check for related accounts',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Apply global throttle if widespread',
                    '2. Block IP ranges if needed',
                    '3. Enable enhanced verification',
                    '4. Notify BSP if required by policy',
                ]),
                'escalation_path' => json_encode([
                    'L1 → L2' => 'On any abuse detection',
                    'L2 → IC' => 'If platform-wide impact',
                    'IC → Legal' => 'If law enforcement needed',
                ]),
                'recovery_verification' => json_encode([
                    '1. Abusive accounts suspended',
                    '2. No ongoing abuse detected',
                    '3. Evidence preserved',
                    '4. Audit log complete',
                ]),
                'post_incident_tasks' => json_encode([
                    '1. Update abuse detection rules',
                    '2. Document case for compliance',
                    '3. Review related accounts',
                    '4. Report to BSP if required',
                ]),
                'estimated_mttr_minutes' => 45,
            ],

            // DATABASE / INFRASTRUCTURE
            [
                'slug' => 'infrastructure-failure',
                'title' => '⚫ Infrastructure / Database Failure',
                'description' => 'Handle critical infrastructure failure',
                'trigger_type' => 'infrastructure',
                'trigger_conditions' => json_encode([
                    'database_down' => true,
                    'redis_down' => true,
                    'api_response_time' => '> 5 seconds',
                    'error_rate' => '> 50%',
                ]),
                'default_severity' => 'sev1',
                'immediate_actions' => json_encode([
                    '1. Confirm scope of outage',
                    '2. Notify IC and SRE L3 immediately',
                    '3. Enable maintenance mode',
                    '4. Update status page to MAJOR OUTAGE',
                ]),
                'diagnostic_steps' => json_encode([
                    '1. Check server/container health',
                    '2. Review infrastructure monitoring',
                    '3. Check cloud provider status',
                    '4. Review recent deployments',
                ]),
                'mitigation_steps' => json_encode([
                    '1. Restart affected services',
                    '2. Failover to backup if available',
                    '3. Rollback recent deployment if suspected',
                    '4. Scale resources if capacity issue',
                ]),
                'escalation_path' => json_encode([
                    'L1 → L3 + IC' => 'Immediately',
                    'L3 → Cloud Provider' => 'If provider issue',
                ]),
                'recovery_verification' => json_encode([
                    '1. All services responding',
                    '2. Error rate < 1%',
                    '3. Response time normal',
                    '4. Queue processing resumed',
                ]),
                'estimated_mttr_minutes' => 60,
            ],
        ];

        foreach ($playbooks as $playbook) {
            if (!isset($playbook['post_incident_tasks'])) {
                $playbook['post_incident_tasks'] = null;
            }
            if (!isset($playbook['communication_template'])) {
                $playbook['communication_template'] = null;
            }
            DB::table('incident_playbooks')->insert(array_merge($playbook, [
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('operator_action_logs');
        Schema::dropIfExists('escalation_logs');
        Schema::dropIfExists('playbook_executions');
        Schema::dropIfExists('incident_playbooks');
        Schema::dropIfExists('shift_checklist_results');
        Schema::dropIfExists('shift_logs');
        Schema::dropIfExists('shift_checklists');
        Schema::dropIfExists('oncall_contacts');
        Schema::dropIfExists('runbook_roles');
    }
};
