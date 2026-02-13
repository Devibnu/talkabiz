<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Escalation Log
 * 
 * Track eskalasi insiden ke level lebih tinggi.
 */
class EscalationLog extends Model
{
    use HasFactory;

    protected $table = 'escalation_logs';

    protected $fillable = [
        'escalation_id',
        'incident_id',
        'execution_id',
        'from_role_id',
        'to_role_id',
        'escalated_by',
        'escalator_name',
        'severity',
        'reason',
        'context',
        'attachments',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'response_notes',
        'sla_minutes',
        'sla_breached',
    ];

    protected $casts = [
        'attachments' => 'array',
        'acknowledged_at' => 'datetime',
        'sla_breached' => 'boolean',
        'sla_minutes' => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function fromRole(): BelongsTo
    {
        return $this->belongsTo(RunbookRole::class, 'from_role_id');
    }

    public function toRole(): BelongsTo
    {
        return $this->belongsTo(RunbookRole::class, 'to_role_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAcknowledged($query)
    {
        return $query->where('status', 'acknowledged');
    }

    public function scopeResolved($query)
    {
        return $query->where('status', 'resolved');
    }

    public function scopeBreached($query)
    {
        return $query->where('sla_breach', true);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'pending' => '⏳',
            'acknowledged' => '👀',
            'actioned' => '🔄',
            'resolved' => '✅',
            'expired' => '⏰',
            default => '❓',
        };
    }

    public function getSeverityIconAttribute(): string
    {
        return match ($this->severity) {
            'sev1' => '🔴',
            'sev2' => '🟠',
            'sev3' => '🟡',
            'sev4' => '🟢',
            default => '⚪',
        };
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getSlaStatusAttribute(): string
    {
        if ($this->sla_breached) {
            return 'breached';
        }

        if ($this->acknowledged_at) {
            return 'met';
        }

        // Check if still within SLA
        $slaMinutes = $this->sla_minutes ?? 15;
        $deadline = $this->created_at->addMinutes($slaMinutes);

        return now()->isAfter($deadline) ? 'breaching' : 'within';
    }

    public function getSlaRemainingAttribute(): ?int
    {
        if ($this->acknowledged_at) {
            return null;
        }

        $slaMinutes = $this->sla_minutes ?? 15;
        $deadline = $this->created_at->addMinutes($slaMinutes);
        
        return max(0, now()->diffInMinutes($deadline, false));
    }

    public function getToContactAttribute(): string
    {
        $contact = OncallContact::getCurrentOnCall($this->to_role_id);
        return $contact?->name ?? $this->toRole?->name ?? 'Unknown';
    }

    public function getFromContactAttribute(): string
    {
        if ($this->from_role_id) {
            return $this->fromRole?->name ?? 'Unknown';
        }
        return $this->escalator_name ?? 'Unknown';
    }

    public function getResponseTimeMinutesAttribute(): ?int
    {
        if (!$this->acknowledged_at) {
            return null;
        }
        return (int) $this->created_at->diffInMinutes($this->acknowledged_at);
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    public static function generateEscalationId(): string
    {
        return 'ESC-' . now()->format('Ymd-His') . '-' . strtoupper(substr(uniqid(), -4));
    }

    public static function createEscalation(
        string $severity,
        string $reason,
        RunbookRole $fromRole,
        RunbookRole $toRole,
        ?string $incidentId = null,
        ?array $context = null
    ): self {
        return static::create([
            'escalation_id' => static::generateEscalationId(),
            'incident_id' => $incidentId,
            'from_role_id' => $fromRole->id,
            'to_role_id' => $toRole->id,
            'escalator_name' => $fromRole->name,
            'severity' => strtolower($severity),
            'reason' => $reason,
            'context' => $context,
            'sla_minutes' => $toRole->response_sla_minutes ?? 15,
            'status' => 'pending',
        ]);
    }

    public function acknowledge(?string $acknowledgedBy = null): void
    {
        $responseTime = (int) $this->created_at->diffInMinutes(now());
        $slaMinutes = $this->sla_minutes ?? 15;
        
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(),
            'sla_breached' => $responseTime > $slaMinutes,
        ]);
    }

    public function markActioned(): void
    {
        if (!$this->acknowledged_at) {
            $this->acknowledge();
        }
        
        $this->update(['status' => 'actioned']);
    }

    public function resolve(string $resolution): void
    {
        $this->update([
            'status' => 'resolved',
            'response_notes' => $resolution,
        ]);
    }

    public function expire(): void
    {
        $this->update([
            'status' => 'expired',
            'sla_breached' => true,
        ]);
    }

    public function escalateFurther(string $reason): self
    {
        $nextRole = $this->toRole->getNextEscalation();
        
        if (!$nextRole) {
            throw new \Exception('No further escalation path available');
        }

        return static::createEscalation(
            $this->severity,
            $reason,
            $this->toRole,
            $nextRole,
            $this->incident_id,
            ['previous_escalation' => $this->escalation_id]
        );
    }

    // =========================================================================
    // NOTIFICATIONS
    // =========================================================================

    public function getNotificationChannels(): array
    {
        $contact = OncallContact::getCurrentOnCall($this->to_role_id);
        
        if (!$contact) {
            return [];
        }

        $channels = [];

        if ($contact->phone) {
            $channels[] = ['type' => 'sms', 'target' => $contact->phone];
            $channels[] = ['type' => 'whatsapp', 'target' => $contact->phone];
        }

        if ($contact->email) {
            $channels[] = ['type' => 'email', 'target' => $contact->email];
        }

        if ($contact->slack_handle) {
            $channels[] = ['type' => 'slack', 'target' => $contact->slack_handle];
        }

        return $channels;
    }

    public function buildNotificationMessage(): string
    {
        return sprintf(
            "🚨 ESCALATION [%s] %s\n\n" .
            "📌 ID: %s\n" .
            "⚡ Severity: %s\n" .
            "📝 Reason: %s\n" .
            "👤 From: %s\n" .
            "⏰ Time: %s\n\n" .
            "Please acknowledge within %d minutes.",
            strtoupper($this->severity),
            $this->incident_id ?? 'New Incident',
            $this->escalation_id,
            strtoupper($this->severity),
            $this->reason,
            $this->from_contact,
            $this->created_at->format('Y-m-d H:i:s'),
            $this->sla_minutes ?? 15
        );
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getPendingEscalations()
    {
        return static::pending()
            ->with(['fromRole', 'toRole'])
            ->orderBy('created_at')
            ->get();
    }

    public static function getTodayStats(): array
    {
        $today = static::today()->get();

        return [
            'total' => $today->count(),
            'pending' => $today->where('status', 'pending')->count(),
            'acknowledged' => $today->where('status', 'acknowledged')->count(),
            'resolved' => $today->where('status', 'resolved')->count(),
            'breached' => $today->where('sla_breached', true)->count(),
            'avg_response_time' => $today->filter(fn($e) => $e->response_time_minutes !== null)
                ->avg(fn($e) => $e->response_time_minutes) ?? 0,
        ];
    }
}
