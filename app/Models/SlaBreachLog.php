<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * SlaBreachLog Model
 * 
 * Log pelanggaran SLA untuk audit dan reporting.
 */
class SlaBreachLog extends Model
{
    protected $table = 'sla_breach_logs';

    const BREACH_TYPE_RESPONSE = 'response';
    const BREACH_TYPE_RESOLUTION = 'resolution';

    const CHANNEL_EMAIL = 'email';
    const CHANNEL_SLACK = 'slack';
    const CHANNEL_WEBHOOK = 'webhook';

    protected $fillable = [
        'ticket_id',
        'klien_id',
        'breach_type',
        'due_at',
        'breached_at',
        'overdue_minutes',
        'owner_notified',
        'notification_channel',
        'notification_sent_at',
        'metadata',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'breached_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'owner_notified' => 'boolean',
        'metadata' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    // ==================== SCOPES ====================

    public function scopeResponse(Builder $query): Builder
    {
        return $query->where('breach_type', self::BREACH_TYPE_RESPONSE);
    }

    public function scopeResolution(Builder $query): Builder
    {
        return $query->where('breach_type', self::BREACH_TYPE_RESOLUTION);
    }

    public function scopeNotified(Builder $query): Builder
    {
        return $query->where('owner_notified', true);
    }

    public function scopePendingNotification(Builder $query): Builder
    {
        return $query->where('owner_notified', false);
    }

    public function scopeForKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeForPeriod(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('breached_at', [$startDate, $endDate]);
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create a breach log entry
     */
    public static function recordBreach(
        SupportTicket $ticket,
        string $breachType,
        \DateTimeInterface $dueAt
    ): self {
        $now = now();
        $overdueMinutes = $dueAt->diffInMinutes($now);

        return self::create([
            'ticket_id' => $ticket->id,
            'klien_id' => $ticket->klien_id,
            'breach_type' => $breachType,
            'due_at' => $dueAt,
            'breached_at' => $now,
            'overdue_minutes' => $overdueMinutes,
            'metadata' => [
                'ticket_number' => $ticket->ticket_number,
                'priority' => $ticket->priority,
                'status_at_breach' => $ticket->status,
            ],
        ]);
    }

    // ==================== HELPERS ====================

    /**
     * Mark as notified
     */
    public function markNotified(string $channel = self::CHANNEL_EMAIL): self
    {
        $this->update([
            'owner_notified' => true,
            'notification_channel' => $channel,
            'notification_sent_at' => now(),
        ]);

        return $this;
    }

    /**
     * Get breach type label
     */
    public function getBreachTypeLabelAttribute(): string
    {
        return match($this->breach_type) {
            self::BREACH_TYPE_RESPONSE => 'Response SLA',
            self::BREACH_TYPE_RESOLUTION => 'Resolution SLA',
            default => $this->breach_type,
        };
    }

    /**
     * Get overdue duration formatted
     */
    public function getOverdueDurationAttribute(): string
    {
        $minutes = $this->overdue_minutes;
        
        if ($minutes < 60) {
            return "{$minutes} menit";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return "{$hours} jam {$remainingMinutes} menit";
        }

        $days = floor($hours / 24);
        $remainingHours = $hours % 24;

        return "{$days} hari {$remainingHours} jam";
    }

    /**
     * Get statistics for a period
     */
    public static function getStatistics(
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $klienId = null
    ): array {
        $query = self::query();

        if ($startDate && $endDate) {
            $query->forPeriod($startDate, $endDate);
        }

        if ($klienId) {
            $query->forKlien($klienId);
        }

        $total = $query->count();
        $response = (clone $query)->response()->count();
        $resolution = (clone $query)->resolution()->count();
        $avgOverdue = (clone $query)->avg('overdue_minutes') ?? 0;

        return [
            'total_breaches' => $total,
            'response_breaches' => $response,
            'resolution_breaches' => $resolution,
            'avg_overdue_minutes' => round($avgOverdue, 2),
        ];
    }
}
