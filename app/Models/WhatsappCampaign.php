<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappCampaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'template_id',
        'name',
        'description',
        'status',
        'audience_filter',
        'template_variables',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'delivered_count',
        'read_count',
        'failed_count',
        'estimated_cost',
        'actual_cost',
        'rate_limit_per_second',
    ];

    protected $casts = [
        'audience_filter' => 'array',
        'template_variables' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
    ];

    // Status constants
    const STATUS_DRAFT = 'draft';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_RUNNING = 'running';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    // Default rate limit (messages per second)
    const DEFAULT_RATE_LIMIT = 10;

    // Cost per message (IDR) - adjust based on Gupshup pricing
    const COST_PER_MESSAGE = 350; // ~0.02 USD

    /**
     * Get the klien that owns this campaign
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    /**
     * Get the template used by this campaign
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsappTemplate::class, 'template_id');
    }

    /**
     * Get campaign recipients
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsappCampaignRecipient::class, 'campaign_id');
    }

    /**
     * Get message logs for this campaign
     */
    public function messageLogs(): HasMany
    {
        return $this->hasMany(WhatsappMessageLog::class, 'campaign_id');
    }

    /**
     * Scope for draft campaigns
     */
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope for scheduled campaigns
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /**
     * Scope for running campaigns
     */
    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    /**
     * Check if campaign can be started
     */
    public function canStart(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED]);
    }

    /**
     * Check if campaign can be paused
     */
    public function canPause(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if campaign can be cancelled
     */
    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_RUNNING, self::STATUS_PAUSED]);
    }

    /**
     * Start the campaign
     */
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * Pause the campaign
     */
    public function pause(): void
    {
        $this->update([
            'status' => self::STATUS_PAUSED,
        ]);
    }

    /**
     * Resume the campaign
     */
    public function resume(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
        ]);
    }

    /**
     * Complete the campaign
     */
    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Cancel the campaign
     */
    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
        ]);
    }

    /**
     * Calculate estimated cost
     */
    public function calculateEstimatedCost(): float
    {
        return $this->total_recipients * self::COST_PER_MESSAGE;
    }

    /**
     * Update actual cost
     */
    public function updateActualCost(): void
    {
        $this->actual_cost = $this->recipients()->sum('cost');
        $this->save();
    }

    /**
     * Get delivery rate percentage
     */
    public function getDeliveryRateAttribute(): float
    {
        if ($this->sent_count === 0) {
            return 0;
        }
        return round(($this->delivered_count / $this->sent_count) * 100, 2);
    }

    /**
     * Get read rate percentage
     */
    public function getReadRateAttribute(): float
    {
        if ($this->delivered_count === 0) {
            return 0;
        }
        return round(($this->read_count / $this->delivered_count) * 100, 2);
    }

    /**
     * Get failure rate percentage
     */
    public function getFailureRateAttribute(): float
    {
        if ($this->total_recipients === 0) {
            return 0;
        }
        return round(($this->failed_count / $this->total_recipients) * 100, 2);
    }
}
