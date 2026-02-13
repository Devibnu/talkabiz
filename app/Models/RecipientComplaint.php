<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RecipientComplaint Model
 * 
 * Tracks spam/abuse complaints from message recipients.
 * Integrates with AbuseScoringService for automated risk escalation.
 */
class RecipientComplaint extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'recipient_phone',
        'recipient_name',
        'complaint_type',
        'complaint_source',
        'provider_name',
        'message_id',
        'message_content_sample',
        'complaint_reason',
        'complaint_metadata',
        'severity',
        'is_processed',
        'processed_at',
        'processed_by',
        'abuse_score_impact',
        'abuse_event_id',
        'action_taken',
        'action_notes',
        'complaint_received_at',
    ];

    protected $casts = [
        'complaint_metadata' => 'array',
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'complaint_received_at' => 'datetime',
        'abuse_score_impact' => 'decimal:2',
    ];

    // Constants for complaint types
    const TYPE_SPAM = 'spam';
    const TYPE_ABUSE = 'abuse';
    const TYPE_PHISHING = 'phishing';
    const TYPE_INAPPROPRIATE = 'inappropriate';
    const TYPE_FREQUENCY = 'frequency';
    const TYPE_OTHER = 'other';

    // Constants for complaint sources
    const SOURCE_PROVIDER_WEBHOOK = 'provider_webhook';
    const SOURCE_MANUAL_REPORT = 'manual_report';
    const SOURCE_INTERNAL_FLAG = 'internal_flag';
    const SOURCE_THIRD_PARTY = 'third_party';

    // Constants for severity
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';

    /**
     * Get the klien that owns this complaint
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Get the abuse event associated with this complaint
     */
    public function abuseEvent(): BelongsTo
    {
        return $this->belongsTo(AbuseEvent::class, 'abuse_event_id');
    }

    /**
     * Get the user who processed this complaint
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Scope: Unprocessed complaints
     */
    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    /**
     * Scope: Processed complaints
     */
    public function scopeProcessed($query)
    {
        return $query->where('is_processed', true);
    }

    /**
     * Scope: By complaint type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('complaint_type', $type);
    }

    /**
     * Scope: By severity
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope: Critical complaints
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Scope: High severity
     */
    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    /**
     * Scope: Recent complaints (within days)
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('complaint_received_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: By provider
     */
    public function scopeFromProvider($query, string $provider)
    {
        return $query->where('provider_name', $provider);
    }

    /**
     * Scope: For specific klien
     */
    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope: By recipient phone
     */
    public function scopeForRecipient($query, string $phone)
    {
        return $query->where('recipient_phone', $phone);
    }

    /**
     * Mark complaint as processed
     */
    public function markAsProcessed(?int $processedBy = null, ?string $action = null, ?string $notes = null): void
    {
        $this->update([
            'is_processed' => true,
            'processed_at' => now(),
            'processed_by' => $processedBy ?? auth()->id(),
            'action_taken' => $action,
            'action_notes' => $notes,
        ]);
    }

    /**
     * Calculate severity based on complaint type and history
     */
    public static function calculateSeverity(
        int $klienId, 
        string $complaintType, 
        ?string $recipientPhone = null
    ): string {
        // Count recent complaints for this klien
        $recentComplaints = self::forKlien($klienId)
            ->recent(30)
            ->count();

        // Count complaints from same recipient
        $recipientComplaints = $recipientPhone 
            ? self::forKlien($klienId)
                ->forRecipient($recipientPhone)
                ->recent(90)
                ->count()
            : 0;

        // Critical complaint types
        $criticalTypes = [self::TYPE_PHISHING, self::TYPE_ABUSE];
        
        if (in_array($complaintType, $criticalTypes)) {
            return self::SEVERITY_CRITICAL;
        }

        // Based on frequency
        if ($recentComplaints >= 10 || $recipientComplaints >= 3) {
            return self::SEVERITY_HIGH;
        } elseif ($recentComplaints >= 5 || $recipientComplaints >= 2) {
            return self::SEVERITY_MEDIUM;
        }

        return self::SEVERITY_LOW;
    }

    /**
     * Get display name for complaint type
     */
    public function getTypeDisplayName(): string
    {
        return match($this->complaint_type) {
            self::TYPE_SPAM => 'Spam/Unsolicited',
            self::TYPE_ABUSE => 'Abuse/Harassment',
            self::TYPE_PHISHING => 'Phishing/Scam',
            self::TYPE_INAPPROPRIATE => 'Inappropriate Content',
            self::TYPE_FREQUENCY => 'Too Frequent',
            self::TYPE_OTHER => 'Other',
            default => ucfirst($this->complaint_type),
        };
    }

    /**
     * Get severity badge class
     */
    public function getSeverityBadgeClass(): string
    {
        return match($this->severity) {
            self::SEVERITY_CRITICAL => 'badge-danger',
            self::SEVERITY_HIGH => 'badge-warning',
            self::SEVERITY_MEDIUM => 'badge-info',
            self::SEVERITY_LOW => 'badge-secondary',
            default => 'badge-light',
        };
    }

    /**
     * Check if complaint requires immediate action
     */
    public function requiresImmediateAction(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL || 
               $this->complaint_type === self::TYPE_PHISHING;
    }

    /**
     * Get complaint summary for audit
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'klien_id' => $this->klien_id,
            'recipient' => $this->recipient_phone,
            'type' => $this->complaint_type,
            'severity' => $this->severity,
            'source' => $this->complaint_source,
            'provider' => $this->provider_name,
            'received_at' => $this->complaint_received_at->toDateTimeString(),
            'processed' => $this->is_processed,
            'score_impact' => $this->abuse_score_impact,
        ];
    }
}
