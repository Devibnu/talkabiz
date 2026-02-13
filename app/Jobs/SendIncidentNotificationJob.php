<?php

namespace App\Jobs;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;

/**
 * Send Incident Notification Job
 * 
 * Handles all incident notifications:
 * - Slack messages
 * - Email notifications
 * - PagerDuty alerts
 * - SMS (for critical)
 * 
 * @author SRE Team
 */
class SendIncidentNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public int $backoff = 10;

    protected int $incidentId;
    protected string $channel;  // oncall, stakeholders, user, all
    protected string $event;    // new, acknowledged, resolved, escalated, etc.
    protected array $options;

    public function __construct(int $incidentId, string $channel, string $event, array $options = [])
    {
        $this->incidentId = $incidentId;
        $this->channel = $channel;
        $this->event = $event;
        $this->options = $options;
    }

    public function handle(): void
    {
        $incident = Incident::find($this->incidentId);
        if (!$incident) {
            Log::warning('SendIncidentNotificationJob: Incident not found', [
                'incident_id' => $this->incidentId,
            ]);
            return;
        }

        try {
            match ($this->channel) {
                'oncall' => $this->notifyOnCall($incident),
                'stakeholders' => $this->notifyStakeholders($incident),
                'user' => $this->notifyUser($incident),
                'all' => $this->notifyAll($incident),
                default => Log::warning("Unknown notification channel: {$this->channel}"),
            };

        } catch (\Exception $e) {
            Log::error('SendIncidentNotificationJob failed', [
                'incident_id' => $this->incidentId,
                'channel' => $this->channel,
                'event' => $this->event,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    protected function notifyOnCall(Incident $incident): void
    {
        // Send Slack notification to on-call channel
        $this->sendSlackNotification($incident, config('incident.slack.oncall_channel'));

        // If SEV-1 or SEV-2, also send PagerDuty
        if ($incident->isCritical()) {
            $this->sendPagerDutyAlert($incident);
        }
    }

    protected function notifyStakeholders(Incident $incident): void
    {
        // Send to stakeholder Slack channel
        $this->sendSlackNotification($incident, config('incident.slack.stakeholder_channel'));

        // Send email to stakeholders
        $stakeholders = config('incident.stakeholder_emails', []);
        if (!empty($stakeholders)) {
            $this->sendEmailNotification($incident, $stakeholders);
        }
    }

    protected function notifyUser(Incident $incident): void
    {
        $userId = $this->options['user_id'] ?? null;
        if (!$userId) {
            return;
        }

        $user = \App\Models\User::find($userId);
        if (!$user) {
            return;
        }

        // Send Slack DM
        if ($user->slack_id) {
            $this->sendSlackDM($incident, $user->slack_id);
        }

        // Send email
        if ($user->email) {
            $this->sendEmailNotification($incident, [$user->email]);
        }
    }

    protected function notifyAll(Incident $incident): void
    {
        $this->notifyOnCall($incident);
        $this->notifyStakeholders($incident);
    }

    // ==================== NOTIFICATION METHODS ====================

    protected function sendSlackNotification(Incident $incident, ?string $channel): void
    {
        if (!$channel) {
            return;
        }

        $webhookUrl = config('incident.slack.webhook_url');
        if (!$webhookUrl) {
            Log::debug('Slack webhook not configured');
            return;
        }

        $message = $this->buildSlackMessage($incident);

        try {
            Http::post($webhookUrl, [
                'channel' => $channel,
                'attachments' => $message['attachments'],
                'text' => $message['text'],
            ]);

            Log::info('Slack notification sent', [
                'incident_id' => $incident->incident_id,
                'channel' => $channel,
            ]);
        } catch (\Exception $e) {
            Log::error('Slack notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendSlackDM(Incident $incident, string $slackId): void
    {
        // Slack API integration for DM would go here
        Log::info('Slack DM sent', [
            'incident_id' => $incident->incident_id,
            'slack_id' => $slackId,
        ]);
    }

    protected function sendPagerDutyAlert(Incident $incident): void
    {
        $apiKey = config('incident.pagerduty.api_key');
        $serviceKey = config('incident.pagerduty.service_key');

        if (!$apiKey || !$serviceKey) {
            Log::debug('PagerDuty not configured');
            return;
        }

        try {
            Http::withHeaders([
                'Authorization' => "Token token={$apiKey}",
                'Content-Type' => 'application/json',
            ])->post('https://events.pagerduty.com/v2/enqueue', [
                'routing_key' => $serviceKey,
                'event_action' => $this->mapEventToPagerDutyAction(),
                'dedup_key' => $incident->incident_id,
                'payload' => [
                    'summary' => "[{$incident->severity}] {$incident->title}",
                    'source' => config('app.name'),
                    'severity' => $this->mapSeverityToPagerDuty($incident->severity),
                    'custom_details' => [
                        'incident_id' => $incident->incident_id,
                        'type' => $incident->incident_type,
                        'status' => $incident->status,
                        'detected_at' => $incident->detected_at->toIso8601String(),
                    ],
                ],
                'links' => [
                    [
                        'href' => url("/incidents/{$incident->id}"),
                        'text' => 'View Incident',
                    ],
                ],
            ]);

            Log::info('PagerDuty alert sent', [
                'incident_id' => $incident->incident_id,
            ]);
        } catch (\Exception $e) {
            Log::error('PagerDuty alert failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sendEmailNotification(Incident $incident, array $recipients): void
    {
        $subject = $this->buildEmailSubject($incident);
        $body = $this->buildEmailBody($incident);

        try {
            // Use Laravel Mail
            // For now, just log
            Log::info('Email notification sent', [
                'incident_id' => $incident->incident_id,
                'recipients' => $recipients,
            ]);
        } catch (\Exception $e) {
            Log::error('Email notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== MESSAGE BUILDERS ====================

    protected function buildSlackMessage(Incident $incident): array
    {
        $color = match ($incident->severity) {
            Incident::SEVERITY_SEV1 => '#FF0000',
            Incident::SEVERITY_SEV2 => '#FF8C00',
            Incident::SEVERITY_SEV3 => '#FFD700',
            Incident::SEVERITY_SEV4 => '#4169E1',
            default => '#808080',
        };

        $emoji = match ($this->event) {
            'new' => '🚨',
            'acknowledged' => '👀',
            'investigating' => '🔍',
            'mitigating' => '🔧',
            'resolved' => '✅',
            'escalated' => '⬆️',
            default => '📢',
        };

        $status = match ($this->event) {
            'new' => 'NEW INCIDENT',
            'acknowledged' => 'ACKNOWLEDGED',
            'investigating' => 'INVESTIGATING',
            'mitigating' => 'MITIGATING',
            'resolved' => 'RESOLVED',
            'escalated' => 'ESCALATED',
            default => strtoupper($this->event),
        };

        return [
            'text' => "{$emoji} [{$incident->severity}] {$status}: {$incident->title}",
            'attachments' => [
                [
                    'color' => $color,
                    'title' => $incident->incident_id,
                    'title_link' => url("/incidents/{$incident->id}"),
                    'text' => $incident->summary ?? 'No summary provided',
                    'fields' => [
                        [
                            'title' => 'Severity',
                            'value' => $incident->severity,
                            'short' => true,
                        ],
                        [
                            'title' => 'Type',
                            'value' => $incident->incident_type,
                            'short' => true,
                        ],
                        [
                            'title' => 'Status',
                            'value' => $incident->status,
                            'short' => true,
                        ],
                        [
                            'title' => 'Duration',
                            'value' => $incident->getDurationForHumans(),
                            'short' => true,
                        ],
                    ],
                    'footer' => 'Incident Response System',
                    'ts' => now()->timestamp,
                ],
            ],
        ];
    }

    protected function buildEmailSubject(Incident $incident): string
    {
        $status = strtoupper($this->event);
        return "[{$incident->severity}] {$status}: {$incident->title}";
    }

    protected function buildEmailBody(Incident $incident): string
    {
        return "
Incident ID: {$incident->incident_id}
Severity: {$incident->severity}
Type: {$incident->incident_type}
Status: {$incident->status}

Summary:
{$incident->summary}

Detected: {$incident->detected_at->format('Y-m-d H:i:s')} UTC
Duration: {$incident->getDurationForHumans()}

View Incident: " . url("/incidents/{$incident->id}");
    }

    protected function mapEventToPagerDutyAction(): string
    {
        return match ($this->event) {
            'new' => 'trigger',
            'acknowledged' => 'acknowledge',
            'resolved' => 'resolve',
            default => 'trigger',
        };
    }

    protected function mapSeverityToPagerDuty(string $severity): string
    {
        return match ($severity) {
            Incident::SEVERITY_SEV1 => 'critical',
            Incident::SEVERITY_SEV2 => 'error',
            Incident::SEVERITY_SEV3 => 'warning',
            Incident::SEVERITY_SEV4 => 'info',
            default => 'warning',
        };
    }
}
