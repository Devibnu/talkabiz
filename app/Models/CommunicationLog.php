<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Communication Log
 * 
 * Log komunikasi internal & eksternal saat insiden.
 */
class CommunicationLog extends Model
{
    use HasFactory;

    protected $table = 'communication_logs';

    protected $fillable = [
        'shift_log_id',
        'incident_id',
        'type',
        'direction',
        'channel',
        'sender',
        'recipients',
        'subject',
        'message',
        'template_used',
        'sent_at',
        'delivered_at',
        'status',
        'response',
    ];

    protected $casts = [
        'recipients' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    public const TYPES = [
        'incident_notification' => 'Incident Notification',
        'status_update' => 'Status Update',
        'escalation_notice' => 'Escalation Notice',
        'resolution_notice' => 'Resolution Notice',
        'maintenance_start' => 'Maintenance Start',
        'maintenance_end' => 'Maintenance End',
        'handover' => 'Shift Handover',
        'customer_update' => 'Customer Update',
        'internal_update' => 'Internal Update',
        'postmortem' => 'Postmortem Notification',
    ];

    public const CHANNELS = [
        'slack' => 'Slack',
        'email' => 'Email',
        'sms' => 'SMS',
        'whatsapp' => 'WhatsApp',
        'phone' => 'Phone Call',
        'status_page' => 'Status Page',
        'ticket' => 'Support Ticket',
    ];

    public const DIRECTIONS = [
        'internal' => 'Internal',
        'external' => 'External (Customer)',
        'vendor' => 'External (Vendor)',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function shiftLog(): BelongsTo
    {
        return $this->belongsTo(ShiftLog::class, 'shift_log_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeForIncident($query, string $incidentId)
    {
        return $query->where('incident_id', $incidentId);
    }

    public function scopeInternal($query)
    {
        return $query->where('direction', 'internal');
    }

    public function scopeExternal($query)
    {
        return $query->whereIn('direction', ['external', 'vendor']);
    }

    public function scopeChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('sent_at', today());
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getChannelLabelAttribute(): string
    {
        return self::CHANNELS[$this->channel] ?? $this->channel;
    }

    public function getDirectionLabelAttribute(): string
    {
        return self::DIRECTIONS[$this->direction] ?? $this->direction;
    }

    public function getTypeIconAttribute(): string
    {
        return match ($this->type) {
            'incident_notification' => '🚨',
            'status_update' => '📊',
            'escalation_notice' => '📈',
            'resolution_notice' => '✅',
            'maintenance_start' => '🔧',
            'maintenance_end' => '✨',
            'handover' => '🔄',
            'customer_update' => '👥',
            'internal_update' => '📢',
            'postmortem' => '📋',
            default => '📌',
        };
    }

    public function getChannelIconAttribute(): string
    {
        return match ($this->channel) {
            'slack' => '💬',
            'email' => '📧',
            'sms' => '📱',
            'whatsapp' => '📲',
            'phone' => '📞',
            'status_page' => '🌐',
            'ticket' => '🎫',
            default => '📌',
        };
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'pending' => '⏳',
            'sent' => '📤',
            'delivered' => '✅',
            'failed' => '❌',
            'read' => '👀',
            default => '❓',
        };
    }

    public function getRecipientsCountAttribute(): int
    {
        return count($this->recipients ?? []);
    }

    public function getSummaryAttribute(): string
    {
        return sprintf(
            "%s %s via %s to %d recipient(s)",
            $this->type_icon,
            $this->type_label,
            $this->channel_label,
            $this->recipients_count
        );
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public static function log(
        string $type,
        string $channel,
        string $direction,
        string $message,
        array $recipients,
        ?string $incidentId = null,
        ?int $shiftLogId = null,
        ?string $subject = null,
        ?string $templateUsed = null
    ): self {
        // Auto-detect current shift if not provided
        if (!$shiftLogId) {
            $currentShift = ShiftLog::getCurrentShift();
            $shiftLogId = $currentShift?->id;
        }

        return static::create([
            'shift_log_id' => $shiftLogId,
            'incident_id' => $incidentId,
            'type' => $type,
            'direction' => $direction,
            'channel' => $channel,
            'sender' => auth()->user()?->name ?? 'system',
            'recipients' => $recipients,
            'subject' => $subject,
            'message' => $message,
            'template_used' => $templateUsed,
            'sent_at' => now(),
            'status' => 'pending',
        ]);
    }

    public function markSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => $this->sent_at ?? now(),
        ]);
    }

    public function markDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function markFailed(?string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'response' => $reason,
        ]);
    }

    public function recordResponse(string $response): void
    {
        $this->update(['response' => $response]);
    }

    // =========================================================================
    // COMMUNICATION TEMPLATES
    // =========================================================================

    public static function buildIncidentNotification(
        string $severity,
        string $title,
        string $description,
        ?string $impact = null
    ): string {
        return <<<MSG
🚨 INCIDENT NOTIFICATION [{$severity}]

📌 Title: {$title}
📝 Description: {$description}
💥 Impact: {$impact}
⏰ Time: {now()->format('Y-m-d H:i:s')}

Please acknowledge and begin investigation.
MSG;
    }

    public static function buildStatusUpdate(
        string $incidentId,
        string $status,
        string $update,
        ?string $eta = null
    ): string {
        $etaLine = $eta ? "⏱️ ETA: {$eta}\n" : "";
        
        return <<<MSG
📊 STATUS UPDATE

📌 Incident: {$incidentId}
🔄 Status: {$status}
📝 Update: {$update}
{$etaLine}
⏰ Time: {now()->format('Y-m-d H:i:s')}
MSG;
    }

    public static function buildResolutionNotice(
        string $incidentId,
        string $resolution,
        int $durationMinutes
    ): string {
        $hours = floor($durationMinutes / 60);
        $mins = $durationMinutes % 60;
        $duration = $hours > 0 ? "{$hours}h {$mins}m" : "{$mins}m";
        
        return <<<MSG
✅ INCIDENT RESOLVED

📌 Incident: {$incidentId}
📝 Resolution: {$resolution}
⏱️ Duration: {$duration}
⏰ Resolved at: {now()->format('Y-m-d H:i:s')}

Postmortem will be shared within 48 hours.
MSG;
    }

    public static function buildHandoverMessage(
        string $fromOperator,
        string $toOperator,
        array $activeIssues,
        ?string $notes = null
    ): string {
        $issuesList = empty($activeIssues) 
            ? "None" 
            : implode("\n", array_map(fn($i) => "  • {$i}", $activeIssues));
        
        $notesSection = $notes ? "\n📝 Notes: {$notes}" : "";
        
        return <<<MSG
🔄 SHIFT HANDOVER

👤 From: {$fromOperator}
👤 To: {$toOperator}
⏰ Time: {now()->format('Y-m-d H:i:s')}

📋 Active Issues:
{$issuesList}
{$notesSection}
MSG;
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getIncidentTimeline(string $incidentId): \Illuminate\Support\Collection
    {
        return static::forIncident($incidentId)
            ->orderBy('sent_at')
            ->get();
    }

    public static function getTodayStats(): array
    {
        $today = static::today()->get();

        return [
            'total' => $today->count(),
            'by_channel' => $today->groupBy('channel')
                ->map->count()
                ->toArray(),
            'by_type' => $today->groupBy('type')
                ->map->count()
                ->toArray(),
            'by_direction' => $today->groupBy('direction')
                ->map->count()
                ->toArray(),
        ];
    }
}
