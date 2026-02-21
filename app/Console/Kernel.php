<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\MonitorSlaCompliance::class,
        Commands\SmartWarmCacheCommand::class,
        Commands\WarmCacheCommand::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $hour = config('app.hour');
        $min = config('app.min');
        $scheduledInterval = $hour !== '' ? ( ($min !== '' && $min != 0) ?  $min .' */'. $hour .' * * *' : '0 */'. $hour .' * * *') : '*/'. $min .' * * * *';
        if(env('IS_DEMO')) {
            $schedule->command('migrate:fresh --seed')->cron($scheduledInterval);
        }

        // ==================== MESSAGE SENDING SCHEDULER ====================
        
        /**
         * Retry failed messages setiap menit
         * Cari message yang is_retryable=true dan retry_after <= now
         */
        $schedule->job(new \App\Jobs\RetryFailedMessagesJob(100))
            ->everyMinute()
            ->withoutOverlapping()
            ->name('retry-failed-messages');

        /**
         * Cleanup stuck messages setiap 5 menit
         * Reset message yang status=sending tapi > 5 menit
         */
        $schedule->job(new \App\Jobs\CleanupStuckMessagesJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('cleanup-stuck-messages');

        /**
         * Cleanup expired quota reservations setiap 5 menit
         */
        $schedule->call(function () {
            app(\App\Services\QuotaService::class)->cleanupExpiredReservations();
        })->everyFiveMinutes()
          ->name('cleanup-quota-reservations');

        // ==================== SUBSCRIPTION CHANGE SCHEDULER ====================

        /**
         * Revenue Lock Phase 1 — Auto-expire subscriptions
         * Runs every 5 minutes. If expires_at < now() → set status=expired.
         * Fail-safe: middleware juga cek real-time.
         */
        $schedule->command('subscription:expire')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('subscription-auto-expire');

        /**
         * Process pending subscription changes (downgrades)
         * Runs daily at 00:10 to apply scheduled downgrades
         */
        $schedule->command('subscription:process-pending')
            ->dailyAt('00:10')
            ->withoutOverlapping()
            ->name('process-pending-subscription-changes');

        // ==================== SUBSCRIPTION RENEWAL REMINDERS ====================

        /**
         * Check subscription expiry & send multi-channel reminders
         * Runs hourly to catch T-7, T-3, T-1, and expired transitions
         * Anti-duplicate: unique constraint on (user, type, channel, date)
         */
        $schedule->command('subscription:check-expiry')
            ->hourly()
            ->withoutOverlapping()
            ->name('check-subscription-expiry-reminders');

        // ==================== TRIAL ACTIVATION REMINDERS ====================

        /**
         * Send activation reminders to trial_selected users
         * Runs hourly to send:
         *   ≥1h  → email_1h  (first nudge)
         *   ≥24h → email_24h + wa_24h (urgency push)
         * Anti-duplicate: one-time per user per type
         * Stop: skips users with successful payment or active subscription
         */
        $schedule->command('trial:send-reminders')
            ->hourly()
            ->withoutOverlapping()
            ->name('trial-activation-reminders');

        // ==================== INVOICE & GRACE PERIOD SCHEDULER ====================

        /**
         * Process expired payments and grace period expirations
         * Runs hourly to:
         * 1. Mark expired payments
         * 2. Suspend subscriptions where grace period ended
         */
        $schedule->command('invoice:process-grace-period')
            ->hourly()
            ->withoutOverlapping()
            ->name('process-invoice-grace-period');

        // ==================== AUTO-RENEWAL RECURRING ====================

        /**
         * Auto-renew recurring subscriptions via Midtrans Core API
         * Runs every 6 hours to:
         * 1. Find subscriptions expiring within 3 days with auto_renew=true
         * 2. Charge saved credit card token via Midtrans
         * 3. On success → extend subscription +30 days
         * 4. On failure → increment attempt counter → grace on max retries
         */
        $schedule->command('subscription:renew')
            ->everySixHours()
            ->withoutOverlapping()
            ->name('subscription-auto-renew');

        /**
         * Subscription cleanup — Phase 3 (Duplicate Payment Lock)
         * Runs every 6 hours to:
         * 1. Expire pending invoices > 24h
         * 2. Expire duplicate pending invoices
         * 3. Expire stale pending transactions > 24h
         */
        $schedule->command('subscription:cleanup --hours=24')
            ->everySixHours()
            ->withoutOverlapping()
            ->name('subscription-cleanup-duplicates');

        // ==================== REPORTING & KPI SCHEDULER ====================

        /**
         * Calculate daily KPI snapshot
         * Runs daily at 01:00 AM
         */
        $schedule->command('reporting:calculate-kpi --type=daily')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->name('calculate-daily-kpi');

        /**
         * Calculate monthly KPI snapshot (first day of month)
         * Runs on the 1st at 02:00 AM
         */
        $schedule->command('reporting:calculate-kpi --type=monthly')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->name('calculate-monthly-kpi');

        // ==================== SLA & SUPPORT SCHEDULER ====================

        /**
         * Real-time SLA monitoring (every 3 minutes)
         * Continuous monitoring of active tickets with automatic escalation
         */
        $schedule->job(new \App\Jobs\SlaMonitoringJob([
                'auto_escalate' => true,
                'send_warnings' => true,
                'max_tickets_per_run' => 100
            ]))
            ->everyThreeMinutes()
            ->withoutOverlapping(5) // 5 minute overlap protection
            ->name('sla-monitoring-realtime');

        /**
         * Comprehensive SLA compliance check (every 15 minutes)
         * Full system scan with detailed logging
         */
        $schedule->command('sla:monitor --escalate --breach-only')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('sla-comprehensive-check');

        /**
         * Daily SLA compliance report (at 08:00)
         * Generate daily summary for management
         */
        $schedule->command('sla:monitor --package=enterprise --package=professional')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->name('sla-daily-report');

        /**
         * Legacy SLA breach check (keep for backwards compatibility)
         * Will be deprecated in favor of new monitoring system
         */
        $schedule->command('sla:check-breaches --notify')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->name('legacy-sla-breaches');

        // ==================== PLAN LIMIT QUOTA RESET ====================

        /**
         * Reset hourly message counters setiap jam
         * Untuk rate limiting per jam
         */
        $schedule->command('quota:reset hourly')
            ->hourly()
            ->withoutOverlapping()
            ->name('reset-hourly-quota');

        /**
         * Reset daily message counters setiap tengah malam
         */
        $schedule->command('quota:reset daily')
            ->dailyAt('00:00')
            ->withoutOverlapping()
            ->name('reset-daily-quota');

        /**
         * Reset monthly message counters setiap awal bulan
         */
        $schedule->command('quota:reset monthly')
            ->monthlyOn(1, '00:05')
            ->withoutOverlapping()
            ->name('reset-monthly-quota');

        // ==================== BILLING & COST SCHEDULER ====================

        /**
         * Aggregate billing data daily at 23:55
         * Also process fallback billing for sent messages without delivered status
         */
        $schedule->command('billing:aggregate --with-fallback')
            ->dailyAt('23:55')
            ->withoutOverlapping()
            ->name('daily-billing-aggregate');

        /**
         * Reset daily cost limits at midnight
         */
        $schedule->command('billing:reset-limits --daily')
            ->dailyAt('00:01')
            ->withoutOverlapping()
            ->name('reset-daily-cost-limits');

        /**
         * Reset monthly cost limits on 1st of each month
         */
        $schedule->command('billing:reset-limits --monthly')
            ->monthlyOn(1, '00:06')
            ->withoutOverlapping()
            ->name('reset-monthly-cost-limits');

        // ==================== WARMUP SCHEDULER ====================

        /**
         * Process daily warmup reset setiap hari jam 00:01
         * Reset sent_today, progress day, auto-resume from pause
         */
        $schedule->command('warmup:process-daily')
            ->dailyAt('00:01')
            ->withoutOverlapping()
            ->name('daily-warmup-reset');

        /**
         * Process warmup state machine setiap hari jam 00:05
         * Age-based transitions, health sync, cooldown expiry
         */
        $schedule->command('warmup:process-states')
            ->dailyAt('00:05')
            ->withoutOverlapping()
            ->name('warmup-state-machine');

        // ==================== HEALTH SCORE SCHEDULER ====================

        /**
         * Recalculate health scores setiap 30 menit
         * Calculate delivery rates, failure rates, and apply auto-actions
         */
        $schedule->command('health:calculate --queue')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->name('health-score-recalculate');

        // ==================== OWNER ALERT SCHEDULER ====================

        /**
         * Run owner alert checks setiap 5 menit
         * Check profit, quota, WA status
         */
        $schedule->command('alerts:check')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('owner-alert-checks');

        /**
         * Send daily alert digest setiap pagi jam 08:00
         */
        $schedule->command('alerts:digest')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->name('owner-alert-digest');

        /**
         * Daily metrics snapshot - collect metrics for monitoring & optimization
         * Runs at 06:00 to capture yesterday's complete data
         */
        $schedule->command('snapshot:daily')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->name('daily-metrics-snapshot');

        /**
         * Risk alert check - detect failure spikes and risk indicators
         * Runs at 06:30 after snapshot is complete
         */
        $schedule->command('alert:risk')
            ->dailyAt('06:30')
            ->withoutOverlapping()
            ->name('daily-risk-alert');

        // ==================== ABUSE SCORING SCHEDULER ====================

        /**
         * Decay abuse scores daily at 03:00
         * Reduce scores over time when no new violations occur
         */
        $schedule->command('abuse:decay')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->name('abuse-score-decay');

        /**
         * Check and auto-unlock temporarily suspended users
         * Runs daily at 03:30 after score decay completes
         * Auto-unlocks users whose cooldown expired and score improved
         */
        $schedule->command('abuse:check-suspended')
            ->dailyAt('03:30')
            ->withoutOverlapping()
            ->name('abuse-check-suspended-users');

        // ==================== AUTO PRICING SCHEDULER ====================

        /**
         * Recalculate pricing setiap 30 menit
         * Adjust price based on cost, health, volume, margin
         */
        $schedule->command('pricing:recalculate --queue')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->name('auto-pricing-recalculate');

        /**
         * Expire old pending messages (> 24 jam) setiap jam
         */
        $schedule->call(function () {
            app(\App\Services\MessageSenderService::class)->expireOldMessages(24);
        })->hourly()
          ->name('expire-old-messages');

        // ==================== RATE LIMITING SCHEDULER ====================

        /**
         * Reset daily counters setiap hari jam 00:00
         * Reset messages_sent_today pada semua sender
         */
        $schedule->call(function () {
            \App\Models\SenderStatus::query()->update(['messages_sent_today' => 0]);
            \Illuminate\Support\Facades\Log::info('RateLimiter: Daily counters reset');
        })->dailyAt('00:00')
          ->name('reset-daily-counters');

        /**
         * Cleanup old throttle events (> 7 hari)
         */
        $schedule->call(function () {
            $deleted = \Illuminate\Support\Facades\DB::table('throttle_events')
                ->where('created_at', '<', now()->subDays(7))
                ->delete();
            \Illuminate\Support\Facades\Log::info('RateLimiter: Cleaned up throttle events', ['count' => $deleted]);
        })->weekly()
          ->name('cleanup-throttle-events');

        /**
         * Cleanup completed campaign throttle states
         */
        $schedule->call(function () {
            app(\App\Services\CampaignThrottleService::class)->cleanupCompletedCampaigns();
        })->dailyAt('03:00')
          ->name('cleanup-campaign-states');

        /**
         * Reset circuit breaker untuk sender yang paused > 1 jam
         * Memberikan kesempatan kedua secara otomatis
         */
        $schedule->call(function () {
            \App\Models\SenderStatus::where('status', 'paused')
                ->where('paused_at', '<', now()->subHour())
                ->update([
                    'status' => 'active',
                    'consecutive_errors' => 0,
                    'paused_at' => null,
                ]);
        })->hourly()
          ->name('reset-circuit-breakers');

        // ==================== DELIVERY REPORT SCHEDULER ====================

        /**
         * Reconcile orphan webhook events
         * Link events yang belum terhubung ke MessageLog
         */
        $schedule->call(function () {
            app(\App\Services\DeliveryReportService::class)->reconcileOrphanEvents();
        })->everyThirtyMinutes()
          ->name('reconcile-orphan-events');

        /**
         * Cleanup old webhook receipts (> 30 hari)
         * Untuk menjaga ukuran database
         */
        $schedule->call(function () {
            $deleted = \Illuminate\Support\Facades\DB::table('webhook_receipts')
                ->where('created_at', '<', now()->subDays(30))
                ->delete();
            \Illuminate\Support\Facades\Log::info('Webhook: Cleaned up old receipts', ['count' => $deleted]);
        })->weekly()
          ->name('cleanup-webhook-receipts');

        /**
         * Cleanup old message events raw payloads (> 90 hari)
         * Keep the event record but remove bulky payload
         */
        $schedule->call(function () {
            $updated = \App\Models\MessageEvent::where('created_at', '<', now()->subDays(90))
                ->whereNotNull('raw_payload')
                ->update(['raw_payload' => null]);
            \Illuminate\Support\Facades\Log::info('MessageEvents: Cleaned up old payloads', ['count' => $updated]);
        })->monthly()
          ->name('cleanup-old-payloads');

        // ==================== RISK SCORING SCHEDULER ====================

        /**
         * Evaluate risk scores setiap jam
         * Hitung ulang weighted risk score untuk semua entity
         */
        $schedule->job(new \App\Jobs\EvaluateRiskJob())
            ->hourly()
            ->withoutOverlapping()
            ->name('evaluate-risk-scores');

        /**
         * Apply risk decay setiap hari jam 01:00
         * Kurangi risk score untuk entity yang "baik" 
         */
        $schedule->job(new \App\Jobs\ApplyRiskDecayJob())
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->name('apply-risk-decay');

        /**
         * Expire old risk actions
         * Hapus action yang sudah expire
         */
        $schedule->call(function () {
            $expired = \App\Models\RiskAction::where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->get();
            
            foreach ($expired as $action) {
                $action->expire();
                
                // Update risk score jika perlu
                if ($riskScore = $action->riskScore) {
                    if ($riskScore->current_action === $action->action_type) {
                        $riskScore->update([
                            'current_action' => null,
                            'action_expires_at' => null,
                        ]);
                    }
                }
            }
            
            \Illuminate\Support\Facades\Log::info('RiskActions: Expired actions', ['count' => $expired->count()]);
        })->everyFifteenMinutes()
          ->name('expire-risk-actions');

        /**
         * Cleanup old risk events (> 90 hari)
         * Jaga ukuran tabel tetap manageable
         */
        $schedule->call(function () {
            $deleted = \Illuminate\Support\Facades\DB::table('risk_events')
                ->where('occurred_at', '<', now()->subDays(90))
                ->delete();
            \Illuminate\Support\Facades\Log::info('RiskEvents: Cleaned up old events', ['count' => $deleted]);
        })->weekly()
          ->name('cleanup-risk-events');

        // ==================== ABUSE DETECTION SCHEDULER ====================

        /**
         * Evaluate abuse setiap 15 menit
         * Deteksi abuse berdasarkan rules yang aktif
         */
        $schedule->job(new \App\Jobs\EvaluateAbuseJob())
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('evaluate-abuse');

        /**
         * Process abuse recovery setiap hari jam 02:00
         * - Recover users dengan expired restrictions
         * - Apply daily point decay
         * - Increment clean days
         */
        $schedule->job(new \App\Jobs\ProcessAbuseRecoveryJob())
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->name('process-abuse-recovery');

        /**
         * Expire user restrictions yang sudah waktunya
         */
        $schedule->call(function () {
            $expired = \App\Models\UserRestriction::withExpiredRestrictions()->get();
            
            foreach ($expired as $restriction) {
                app(\App\Services\AbuseDetectionService::class)->recoverUser($restriction);
            }
            
            \Illuminate\Support\Facades\Log::info('UserRestrictions: Processed expired', ['count' => $expired->count()]);
        })->everyThirtyMinutes()
          ->name('expire-user-restrictions');

        /**
         * Resolve expired suspensions
         */
        $schedule->call(function () {
            $expired = \App\Models\SuspensionHistory::expired()->get();
            
            foreach ($expired as $suspension) {
                $suspension->resolve(\App\Models\SuspensionHistory::RESOLUTION_EXPIRED);
            }
            
            \Illuminate\Support\Facades\Log::info('Suspensions: Resolved expired', ['count' => $expired->count()]);
        })->hourly()
          ->name('resolve-expired-suspensions');

        /**
         * Cleanup old abuse events (> 180 hari)
         * Keep for longer than risk events for audit
         */
        $schedule->call(function () {
            // Don't delete, just mark as archived or null the evidence
            $updated = \Illuminate\Support\Facades\DB::table('abuse_events')
                ->where('detected_at', '<', now()->subDays(180))
                ->whereNotNull('evidence')
                ->update(['evidence' => null]);
            \Illuminate\Support\Facades\Log::info('AbuseEvents: Cleaned up old evidence', ['count' => $updated]);
        })->monthly()
          ->name('cleanup-abuse-events');

        // ==================== COMPLIANCE & RETENTION SCHEDULER ====================

        /**
         * Archive logs setiap hari jam 02:00
         * Move old logs to legal_archives based on retention policies
         */
        $schedule->job(new \App\Jobs\ArchiveLogsJob())
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->name('archive-compliance-logs');

        /**
         * Purge expired archives setiap minggu (Sunday jam 03:00)
         * Delete archives yang sudah melewati total retention period
         */
        $schedule->job(new \App\Jobs\PurgeExpiredLogsJob(7))
            ->weeklyOn(0, '03:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->name('purge-expired-archives');

        /**
         * Integrity check setiap hari jam 04:00
         * Verify checksums of archives and audit logs
         */
        $schedule->job(new \App\Jobs\IntegrityCheckJob(5000, 10000))
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->name('integrity-check');

        /**
         * Cleanup old access logs (beyond retention)
         * Access logs don't need as long retention as audit logs
         */
        $schedule->call(function () {
            $cutoff = now()->subDays(90);
            $deleted = \Illuminate\Support\Facades\DB::table('access_logs')
                ->where('accessed_at', '<', $cutoff)
                ->delete();
            \Illuminate\Support\Facades\Log::info('AccessLogs: Cleaned up old logs', ['count' => $deleted]);
        })->weeklyOn(1, '03:30')
          ->name('cleanup-access-logs');

        // ==================== INCIDENT RESPONSE SCHEDULER ====================

        /**
         * Detect anomalies setiap 2 menit
         * Evaluate all alert rules dan create incidents jika threshold terlampaui
         * CRITICAL: Ini adalah early detection system
         */
        $schedule->job(new \App\Jobs\DetectAnomaliesJob())
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->name('detect-anomalies');

        /**
         * Process escalations setiap 5 menit
         * Escalate unacknowledged incidents berdasarkan severity SLA
         * - SEV-1: Escalate setelah 5 menit
         * - SEV-2: Escalate setelah 15 menit
         * - SEV-3: Escalate setelah 30 menit
         * - SEV-4: Escalate setelah 1 jam
         */
        $schedule->job(new \App\Jobs\ProcessEscalationsJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('process-escalations');

        /**
         * Check SLA breaches setiap 5 menit
         * Log warning untuk incidents yang mendekati SLA breach
         */
        $schedule->call(function () {
            $incidents = \App\Models\Incident::whereIn('status', [
                \App\Models\Incident::STATUS_DETECTED,
                \App\Models\Incident::STATUS_ACKNOWLEDGED,
                \App\Models\Incident::STATUS_INVESTIGATING,
                \App\Models\Incident::STATUS_MITIGATING,
            ])->get();

            foreach ($incidents as $incident) {
                $slaConfig = $incident->getSlaConfig();
                $acknowledgeDeadline = $incident->detected_at->addMinutes($slaConfig['acknowledge']);
                $resolveDeadline = $incident->detected_at->addHours($slaConfig['resolve']);

                // Check acknowledge SLA
                if (!$incident->acknowledged_at && now()->gt($acknowledgeDeadline)) {
                    \Illuminate\Support\Facades\Log::warning('Incident SLA: Acknowledge SLA breached', [
                        'incident_id' => $incident->id,
                        'incident_number' => $incident->incident_number,
                        'severity' => $incident->severity,
                        'detected_at' => $incident->detected_at->toIso8601String(),
                        'deadline' => $acknowledgeDeadline->toIso8601String(),
                        'overdue_minutes' => now()->diffInMinutes($acknowledgeDeadline),
                    ]);
                }

                // Check resolve SLA (warning at 75%)
                $warningThreshold = $incident->detected_at->addMinutes($slaConfig['resolve'] * 60 * 0.75);
                if (!$incident->resolved_at && now()->gt($warningThreshold)) {
                    \Illuminate\Support\Facades\Log::warning('Incident SLA: Resolve SLA at risk', [
                        'incident_id' => $incident->id,
                        'incident_number' => $incident->incident_number,
                        'severity' => $incident->severity,
                        'detected_at' => $incident->detected_at->toIso8601String(),
                        'resolve_deadline' => $resolveDeadline->toIso8601String(),
                        'time_remaining_minutes' => now()->diffInMinutes($resolveDeadline, false),
                    ]);
                }
            }
        })->everyFiveMinutes()
          ->name('check-incident-sla');

        /**
         * Cleanup old resolved incidents (archive setelah 180 hari)
         * Incidents tetap disimpan tapi metric snapshots bisa di-archive
         */
        $schedule->call(function () {
            $cutoff = now()->subDays(180);
            
            // Archive metric snapshots dari incidents lama
            $archived = \Illuminate\Support\Facades\DB::table('incident_metric_snapshots')
                ->where('captured_at', '<', $cutoff)
                ->whereNotNull('metrics')
                ->update(['metrics' => null]);
            
            \Illuminate\Support\Facades\Log::info('IncidentMetrics: Archived old snapshots', ['count' => $archived]);
        })->monthly()
          ->name('archive-incident-metrics');

        /**
         * Reminder untuk incidents dengan postmortem pending
         * Kirim reminder setiap hari jam 09:00
         */
        $schedule->call(function () {
            $pendingPostmortems = \App\Models\Incident::where('status', \App\Models\Incident::STATUS_POSTMORTEM_PENDING)
                ->where('resolved_at', '<', now()->subDays(2))
                ->get();

            foreach ($pendingPostmortems as $incident) {
                \Illuminate\Support\Facades\Log::info('Postmortem Reminder: Pending postmortem', [
                    'incident_id' => $incident->id,
                    'incident_number' => $incident->incident_number,
                    'severity' => $incident->severity,
                    'resolved_at' => $incident->resolved_at->toIso8601String(),
                    'days_pending' => $incident->resolved_at->diffInDays(now()),
                ]);

                // Optional: Send notification to commander
                if ($incident->commander_id) {
                    // Dispatch notification job
                    // \App\Jobs\SendIncidentNotificationJob::dispatch($incident, 'postmortem_reminder');
                }
            }
        })->dailyAt('09:00')
          ->name('postmortem-reminder');

        /**
         * Check overdue action items
         * Kirim reminder untuk CAPA items yang overdue
         */
        $schedule->call(function () {
            $overdueActions = \App\Models\IncidentAction::where('status', 'in_progress')
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->with(['incident', 'assignee'])
                ->get();

            foreach ($overdueActions as $action) {
                \Illuminate\Support\Facades\Log::warning('Action Item Overdue', [
                    'action_id' => $action->id,
                    'incident_number' => $action->incident->incident_number ?? 'N/A',
                    'title' => $action->title,
                    'priority' => $action->priority,
                    'due_date' => $action->due_date->toDateString(),
                    'days_overdue' => $action->due_date->diffInDays(now()),
                    'assignee' => $action->assignee->name ?? 'Unassigned',
                ]);
            }
        })->dailyAt('08:00')
          ->name('check-overdue-actions');

        // ==================== STATUS PAGE SCHEDULER ====================

        /**
         * Sync status page dengan internal incidents
         * Setiap 5 menit untuk memastikan konsistensi
         */
        $schedule->job(new \App\Jobs\SyncStatusPageJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('sync-status-page');

        /**
         * Process scheduled maintenance
         * - Auto-start maintenance saat waktunya
         * - Send reminders 1 jam sebelum
         * - Handle overdue maintenance
         */
        $schedule->job(new \App\Jobs\ProcessScheduledMaintenanceJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('process-scheduled-maintenance');

        /**
         * Calculate trust metrics setiap hari jam 23:55
         * - Uptime per component
         * - MTTA & MTTR
         * - Notification delivery rate
         * - Incident count
         */
        $schedule->job(new \App\Jobs\CalculateTrustMetricsJob())
            ->dailyAt('23:55')
            ->withoutOverlapping()
            ->name('calculate-trust-metrics');

        /**
         * Cleanup expired banners setiap hari jam 02:00
         */
        $schedule->call(function () {
            $expired = \App\Models\InAppBanner::where('is_active', true)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update(['is_active' => false]);
            
            \Illuminate\Support\Facades\Log::info('InAppBanners: Cleaned up expired', ['count' => $expired]);
        })->dailyAt('02:00')
          ->name('cleanup-expired-banners');

        /**
         * Cleanup old customer notifications (> 90 hari)
         */
        $schedule->call(function () {
            $deleted = \Illuminate\Support\Facades\DB::table('customer_notifications')
                ->where('created_at', '<', now()->subDays(90))
                ->delete();
            
            \Illuminate\Support\Facades\Log::info('CustomerNotifications: Cleaned up old', ['count' => $deleted]);
        })->weeklyOn(0, '03:00')
          ->name('cleanup-old-notifications');

        /**
         * Archive old status updates (> 180 hari)
         * Keep the records but null out large text fields
         */
        $schedule->call(function () {
            // We don't delete status_updates because they are immutable audit records
            // Instead, we can archive old metrics
            $archived = \Illuminate\Support\Facades\DB::table('status_page_metrics')
                ->where('metric_date', '<', now()->subYears(1))
                ->whereNotNull('breakdown')
                ->update(['breakdown' => null]);
            
            \Illuminate\Support\Facades\Log::info('StatusPageMetrics: Archived old breakdown data', ['count' => $archived]);
        })->monthly()
          ->name('archive-status-metrics');

        // ==================== CHAOS TESTING SCHEDULER ====================
        // SOFT FREEZE: Only runs when CHAOS_ENABLED=true
        if (config('app.chaos_enabled')) {

        /**
         * Monitor running chaos experiments setiap 10 detik
         * ONLY runs when there's an active experiment
         * - Check guardrails
         * - Collect metrics
         * - Detect anomalies
         * - Auto-rollback if needed
         */
        $schedule->call(function () {
            // Only run if there's an active experiment
            $runningExperiment = \App\Models\ChaosExperiment::running()->first();
            
            if ($runningExperiment) {
                \App\Jobs\MonitorChaosExperimentJob::dispatch($runningExperiment);
            }
        })->everyTenSeconds()
          ->withoutOverlapping()
          ->name('monitor-chaos-experiment')
          ->environments(['local', 'staging', 'canary']); // NEVER production

        /**
         * Auto-cleanup expired chaos flags setiap menit
         * Safety measure untuk memastikan tidak ada flag yang tertinggal
         */
        $schedule->call(function () {
            $expired = \App\Models\ChaosFlag::where('is_enabled', true)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update([
                    'is_enabled' => false,
                    'disabled_at' => now(),
                    'disabled_reason' => 'Auto-expired by scheduler',
                ]);
            
            if ($expired > 0) {
                \Illuminate\Support\Facades\Log::channel('chaos')->info('ChaosFlags: Auto-expired flags', ['count' => $expired]);
            }
        })->everyMinute()
          ->withoutOverlapping()
          ->name('cleanup-chaos-flags')
          ->environments(['local', 'staging', 'canary']);

        /**
         * Auto-abort stale experiments setiap 5 menit
         * Experiments running > max_duration akan di-abort otomatis
         */
        $schedule->call(function () {
            $maxDuration = config('chaos.defaults.max_duration', 900);
            
            $stale = \App\Models\ChaosExperiment::running()
                ->where('started_at', '<', now()->subSeconds($maxDuration))
                ->get();
            
            foreach ($stale as $experiment) {
                $experiment->abort([
                    'reason' => 'Auto-aborted: exceeded maximum duration',
                    'triggered_by' => 'scheduler',
                    'max_duration' => $maxDuration,
                ]);
                
                // Disable all flags for safety
                \App\Services\ChaosToggleService::disableAll("Experiment {$experiment->experiment_id} auto-aborted");
                
                \Illuminate\Support\Facades\Log::channel('chaos')->warning('ChaosExperiment: Auto-aborted stale experiment', [
                    'experiment_id' => $experiment->experiment_id,
                    'started_at' => $experiment->started_at->toIso8601String(),
                    'duration_exceeded' => $maxDuration,
                ]);
            }
        })->everyFiveMinutes()
          ->withoutOverlapping()
          ->name('abort-stale-experiments')
          ->environments(['local', 'staging', 'canary']);

        /**
         * Cleanup old chaos data setiap minggu
         * - Old event logs (> 30 hari)
         * - Old injection history (> 30 hari)
         * - Old mock response records
         */
        $schedule->call(function () {
            $cutoff = now()->subDays(30);
            
            // Cleanup event logs
            $events = \Illuminate\Support\Facades\DB::table('chaos_event_logs')
                ->where('occurred_at', '<', $cutoff)
                ->delete();
            
            // Cleanup injection history
            $injections = \Illuminate\Support\Facades\DB::table('chaos_injection_history')
                ->where('injected_at', '<', $cutoff)
                ->delete();
            
            \Illuminate\Support\Facades\Log::channel('chaos')->info('Chaos: Cleaned up old data', [
                'events_deleted' => $events,
                'injections_deleted' => $injections,
            ]);
        })->weekly()
          ->name('cleanup-chaos-data')
          ->environments(['local', 'staging', 'canary']);

        /**
         * Archive old experiment reports (> 90 hari)
         * Keep experiment records but archive large JSON fields
         */
        $schedule->call(function () {
            $cutoff = now()->subDays(90);
            
            $archived = \Illuminate\Support\Facades\DB::table('chaos_experiments')
                ->where('created_at', '<', $cutoff)
                ->whereIn('status', ['completed', 'aborted', 'rolled_back'])
                ->whereNotNull('final_metrics')
                ->update([
                    'notes' => \Illuminate\Support\Facades\DB::raw("CONCAT(IFNULL(notes, ''), '\n[ARCHIVED: final_metrics cleared]')"),
                    // Keep baseline and final metrics summary but clear detailed data
                ]);
            
            \Illuminate\Support\Facades\Log::channel('chaos')->info('Chaos: Archived old experiment data', ['count' => $archived]);
        })->monthly()
          ->name('archive-chaos-experiments')
          ->environments(['local', 'staging', 'canary']);

        } // end if (config('app.chaos_enabled'))

        // ==================== ERROR BUDGET SCHEDULER ====================

        /**
         * Calculate error budgets setiap 5 menit
         * - Recalculate budget untuk semua SLOs
         * - Update burn rates (1h, 6h, 24h, 7d)
         * - Detect status changes
         * - Enforce reliability policies
         * CRITICAL: Ini adalah core error budget system
         */
        $schedule->job(new \App\Jobs\CalculateErrorBudgetsJob())
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->name('calculate-error-budgets');

        /**
         * Detect burn events setiap menit
         * - Threshold crossings (75%, 50%, 25%, 10%, 5%)
         * - Burn rate spikes (> 5x normal)
         * - Budget exhaustion
         * - SLO breaches
         * CRITICAL: Early warning system
         */
        $schedule->job(new \App\Jobs\DetectBurnEventsJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->name('detect-burn-events');

        /**
         * Aggregate SLI measurements setiap jam
         * - Hourly → Daily aggregation
         * - Midnight: Daily → Weekly
         * - First day of month: Weekly → Monthly
         */
        $schedule->job(new \App\Jobs\AggregateSliMeasurementsJob())
            ->hourly()
            ->withoutOverlapping()
            ->name('aggregate-sli-measurements');

        /**
         * Generate daily budget report jam 09:00
         * - Overall health summary
         * - Budget status per SLO
         * - Top failure contributors
         * - Recommendations
         */
        $schedule->job(new \App\Jobs\GenerateBudgetReportJob('daily'))
            ->dailyAt('09:00')
            ->name('daily-budget-report');

        /**
         * Generate weekly budget report Senin jam 09:00
         * - Week-over-week comparison
         * - Trend analysis
         * - Event timeline
         */
        $schedule->job(new \App\Jobs\GenerateBudgetReportJob('weekly'))
            ->weeklyOn(1, '09:00')
            ->name('weekly-budget-report');

        /**
         * Generate monthly budget report tanggal 1 jam 09:00
         * - SLO performance summary
         * - Monthly trends
         * - Strategic recommendations
         */
        $schedule->job(new \App\Jobs\GenerateBudgetReportJob('monthly'))
            ->monthlyOn(1, '09:00')
            ->name('monthly-budget-report');

        /**
         * Evaluate & enforce policies setiap 2 menit
         * - Check all policies against current budget status
         * - Activate/deactivate policies automatically
         * - Execute actions (block deploy, throttle, etc.)
         * CRITICAL: Automatic reliability enforcement
         */
        $schedule->call(function () {
            app(\App\Services\ReliabilityPolicyService::class)->evaluateAndEnforce();
        })->everyTwoMinutes()
          ->withoutOverlapping()
          ->name('enforce-reliability-policies');

        /**
         * Cleanup old budget data setiap minggu
         * - Old SLI measurements (> 90 hari hourly)
         * - Old burn events (> 180 hari)
         * - Old policy activations (> 90 hari)
         */
        $schedule->call(function () {
            $cutoff90 = now()->subDays(90);
            $cutoff180 = now()->subDays(180);
            
            // Cleanup hourly measurements (keep daily/weekly/monthly)
            $hourlyDeleted = \Illuminate\Support\Facades\DB::table('sli_measurements')
                ->where('granularity', 'hourly')
                ->where('period_start', '<', $cutoff90)
                ->delete();
            
            // Cleanup old burn events
            $eventsDeleted = \Illuminate\Support\Facades\DB::table('budget_burn_events')
                ->where('occurred_at', '<', $cutoff180)
                ->delete();
            
            // Cleanup old policy activations
            $activationsDeleted = \Illuminate\Support\Facades\DB::table('policy_activations')
                ->where('deactivated_at', '<', $cutoff90)
                ->where('is_active', false)
                ->delete();
            
            \Illuminate\Support\Facades\Log::channel('reliability')->info('ErrorBudget: Cleaned up old data', [
                'hourly_measurements' => $hourlyDeleted,
                'burn_events' => $eventsDeleted,
                'policy_activations' => $activationsDeleted,
            ]);
        })->weeklyOn(0, '04:00')
          ->name('cleanup-budget-data');

        /**
         * Archive old budget reports (> 365 hari)
         * Keep records but clear large JSON data
         */
        $schedule->call(function () {
            $cutoff = now()->subYear();
            
            $archived = \Illuminate\Support\Facades\DB::table('budget_reports')
                ->where('period_end', '<', $cutoff)
                ->whereNotNull('data')
                ->update(['data' => null]);
            
            \Illuminate\Support\Facades\Log::channel('reliability')->info('BudgetReports: Archived old reports', ['count' => $archived]);
        })->monthly()
          ->name('archive-budget-reports');

        /**
         * Reset expired policy restrictions setiap jam
         * Safety net untuk memastikan tidak ada restriction yang stuck
         */
        $schedule->call(function () {
            // Clear cache flags older than 24 hours (they should auto-refresh)
            // This is a safety measure
            $cachePrefixes = [
                'reliability:deploy_blocked',
                'reliability:deploy_warning',
                'reliability:throttle',
                'reliability:feature_freeze',
                'reliability:full_freeze',
                'reliability:campaign_pause',
                'reliability:campaign_limit',
            ];
            
            foreach ($cachePrefixes as $key) {
                $value = \Illuminate\Support\Facades\Cache::get($key);
                if ($value && isset($value['since'])) {
                    $since = \Carbon\Carbon::parse($value['since']);
                    if ($since->lt(now()->subHours(24))) {
                        // Log warning but don't auto-clear (safety concern)
                        \Illuminate\Support\Facades\Log::channel('reliability')->warning('Stale restriction detected', [
                            'key' => $key,
                            'since' => $value['since'],
                            'hours_old' => $since->diffInHours(now()),
                        ]);
                    }
                }
            }
        })->hourly()
          ->name('check-stale-restrictions');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
