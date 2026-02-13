<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WaRiskEvent Model
 * Individual risk events that impact health score
 */
class WaRiskEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'wa_connection_id',
        'user_id',
        'campaign_id',
        'event_type',
        'severity',
        'score_impact',
        'message_id',
        'template_name',
        'recipient_phone',
        'description',
        'meta_data',
        'source',
        'resolved',
        'resolution_note',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'resolved' => 'boolean',
        'resolved_at' => 'datetime',
    ];

    /**
     * Event type labels
     */
    public const EVENT_LABELS = [
        'message_failed' => 'Pesan Gagal Terkirim',
        'message_blocked' => 'Pesan Diblokir',
        'message_reported' => 'Dilaporkan Spam',
        'template_rejected' => 'Template Ditolak',
        'burst_violation' => 'Pengiriman Terlalu Cepat',
        'cooldown_violation' => 'Pelanggaran Cooldown',
        'spam_detected' => 'Konten Spam Terdeteksi',
        'optin_violation' => 'Pelanggaran Opt-in',
        'rate_limit_hit' => 'Rate Limit Tercapai',
        'quality_warning' => 'Peringatan Kualitas Meta',
        'account_flagged' => 'Akun Ditandai Meta',
    ];

    /**
     * Severity colors
     */
    public const SEVERITY_COLORS = [
        'low' => 'info',
        'medium' => 'warning',
        'high' => 'orange',
        'critical' => 'danger',
    ];

    /**
     * Default score impacts per event type
     */
    public const DEFAULT_IMPACTS = [
        'message_failed' => -1,
        'message_blocked' => -5,
        'message_reported' => -10,
        'template_rejected' => -8,
        'burst_violation' => -3,
        'cooldown_violation' => -5,
        'spam_detected' => -7,
        'optin_violation' => -5,
        'rate_limit_hit' => -2,
        'quality_warning' => -15,
        'account_flagged' => -25,
    ];

    // ===== Relationships =====

    public function waConnection(): BelongsTo
    {
        return $this->belongsTo(WaConnection::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ===== Accessors =====

    public function getEventLabelAttribute(): string
    {
        return self::EVENT_LABELS[$this->event_type] ?? $this->event_type;
    }

    public function getSeverityColorAttribute(): string
    {
        return self::SEVERITY_COLORS[$this->severity] ?? 'secondary';
    }

    // ===== Scopes =====

    public function scopeUnresolved($query)
    {
        return $query->where('resolved', false);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeForConnection($query, int $waConnectionId)
    {
        return $query->where('wa_connection_id', $waConnectionId);
    }

    // ===== Methods =====

    /**
     * Mark event as resolved
     */
    public function resolve(string $note = null, int $userId = null): void
    {
        $this->update([
            'resolved' => true,
            'resolution_note' => $note,
            'resolved_at' => now(),
            'resolved_by' => $userId ?? auth()->id(),
        ]);
    }

    /**
     * Create risk event with default impact
     */
    public static function createEvent(
        int $waConnectionId,
        int $userId,
        string $eventType,
        string $severity = 'medium',
        array $data = [],
        string $source = 'system'
    ): self {
        return self::create([
            'wa_connection_id' => $waConnectionId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'severity' => $severity,
            'score_impact' => self::DEFAULT_IMPACTS[$eventType] ?? -1,
            'source' => $source,
            ...$data,
        ]);
    }
}
