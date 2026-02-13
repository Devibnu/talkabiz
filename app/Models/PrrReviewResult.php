<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRR Review Result
 * 
 * Individual verification result per checklist item.
 * 
 * Status:
 * - pending: Not checked yet
 * - passed: Verified OK
 * - failed: Verification failed
 * - skipped: Not applicable
 * - waived: Exception granted
 */
class PrrReviewResult extends Model
{
    use HasFactory;

    protected $table = 'prr_review_results';

    protected $fillable = [
        'review_id',
        'item_id',
        'status',
        'notes',
        'evidence',
        'automated_result',
        'waiver_reason',
        'waived_by',
        'waived_at',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'evidence' => 'array',
        'automated_result' => 'array',
        'waived_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function review(): BelongsTo
    {
        return $this->belongsTo(PrrReview::class, 'review_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PrrChecklistItem::class, 'item_id');
    }

    public function verifiedByUser()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function waivedByUser()
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePassed($query)
    {
        return $query->where('status', 'passed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeWaived($query)
    {
        return $query->where('status', 'waived');
    }

    public function scopeBlockers($query)
    {
        return $query->whereHas('item', fn($q) => $q->where('severity', 'blocker'));
    }

    public function scopeByCategory($query, string $categorySlug)
    {
        return $query->whereHas('item.category', fn($q) => $q->where('slug', $categorySlug));
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsPassedAttribute(): bool
    {
        return $this->status === 'passed';
    }

    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    public function getIsBlockerAttribute(): bool
    {
        return $this->item?->severity === 'blocker';
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'passed' => '✅',
            'failed' => '❌',
            'pending' => '⏳',
            'skipped' => '⏭️',
            'waived' => '🔓',
            default => '❓',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'passed' => 'green',
            'failed' => 'red',
            'pending' => 'yellow',
            'skipped' => 'gray',
            'waived' => 'blue',
            default => 'gray',
        };
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Mark as passed
     */
    public function markPassed(?int $verifiedBy = null, ?string $notes = null, ?array $evidence = null): void
    {
        $this->update([
            'status' => 'passed',
            'notes' => $notes,
            'evidence' => $evidence,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
        ]);

        $this->review->updateStatistics();
    }

    /**
     * Mark as failed
     */
    public function markFailed(?int $verifiedBy = null, ?string $notes = null, ?array $evidence = null): void
    {
        $this->update([
            'status' => 'failed',
            'notes' => $notes,
            'evidence' => $evidence,
            'verified_by' => $verifiedBy,
            'verified_at' => now(),
        ]);

        $this->review->updateStatistics();
    }

    /**
     * Mark as skipped (not applicable)
     */
    public function markSkipped(?string $reason = null): void
    {
        $this->update([
            'status' => 'skipped',
            'notes' => $reason,
        ]);

        $this->review->updateStatistics();
    }

    /**
     * Grant waiver (exception)
     */
    public function grantWaiver(string $reason, ?int $waivedBy = null): void
    {
        $this->update([
            'status' => 'waived',
            'waiver_reason' => $reason,
            'waived_by' => $waivedBy,
            'waived_at' => now(),
        ]);

        $this->review->updateStatistics();
    }

    /**
     * Run automated verification for this item
     */
    public function runAutomatedCheck(?int $verifiedBy = null): array
    {
        if (!$this->item?->can_auto_verify) {
            return [
                'success' => false,
                'message' => 'Item cannot be auto-verified',
            ];
        }

        $result = $this->item->runAutomatedCheck();

        if ($result === null) {
            return [
                'success' => false,
                'message' => 'Automated check returned no result',
            ];
        }

        $this->update([
            'automated_result' => $result,
        ]);

        if ($result['passed'] ?? false) {
            $this->markPassed(
                $verifiedBy, 
                $result['message'] ?? 'Automated check passed',
                ['automated' => true, 'result' => $result]
            );
        } else {
            $this->markFailed(
                $verifiedBy,
                $result['message'] ?? 'Automated check failed',
                ['automated' => true, 'result' => $result]
            );
        }

        return [
            'success' => true,
            'passed' => $result['passed'] ?? false,
            'message' => $result['message'] ?? null,
            'result' => $result,
        ];
    }

    /**
     * Reset to pending
     */
    public function reset(): void
    {
        $this->update([
            'status' => 'pending',
            'notes' => null,
            'evidence' => null,
            'automated_result' => null,
            'waiver_reason' => null,
            'waived_by' => null,
            'waived_at' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);

        $this->review->updateStatistics();
    }
}
