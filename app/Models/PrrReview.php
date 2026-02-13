<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * PRR Review
 * 
 * Production Readiness Review session.
 * 
 * Status Flow:
 * draft -> in_progress -> pending -> approved/rejected/deferred
 * 
 * Decision Types:
 * - go: Full launch approved
 * - go_limited: Soft launch / limited rollout
 * - no_go: Launch blocked
 * - pending: Not decided yet
 */
class PrrReview extends Model
{
    use HasFactory;

    protected $table = 'prr_reviews';

    protected $fillable = [
        'review_id',
        'name',
        'description',
        'target_environment',
        'target_launch_date',
        'status',
        'decision',
        'decision_rationale',
        'blockers',
        'risks_accepted',
        'total_items',
        'passed_items',
        'failed_items',
        'pending_items',
        'skipped_items',
        'pass_rate',
        'created_by',
        'reviewed_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'target_launch_date' => 'date',
        'blockers' => 'array',
        'risks_accepted' => 'array',
        'total_items' => 'integer',
        'passed_items' => 'integer',
        'failed_items' => 'integer',
        'pending_items' => 'integer',
        'skipped_items' => 'integer',
        'pass_rate' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function results(): HasMany
    {
        return $this->hasMany(PrrReviewResult::class, 'review_id');
    }

    public function signOffs(): HasMany
    {
        return $this->hasMany(PrrSignOff::class, 'review_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedByUser()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopePending($query)
    {
        return $query->where('decision', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->whereIn('decision', ['go', 'go_limited']);
    }

    public function scopeRejected($query)
    {
        return $query->where('decision', 'no_go');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getIsGoAttribute(): bool
    {
        return in_array($this->decision, ['go', 'go_limited']);
    }

    public function getIsNoGoAttribute(): bool
    {
        return $this->decision === 'no_go';
    }

    public function getIsPendingAttribute(): bool
    {
        return $this->decision === 'pending';
    }

    public function getPassRatePercentAttribute(): string
    {
        return number_format($this->pass_rate, 1) . '%';
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'draft' => '📝',
            'in_progress' => '⏳',
            'pending' => '⏸️',
            'approved' => '✅',
            'rejected' => '❌',
            'deferred' => '⏭️',
            default => '❓',
        };
    }

    public function getDecisionIconAttribute(): string
    {
        return match ($this->decision) {
            'go' => '🚀',
            'go_limited' => '🎯',
            'no_go' => '🛑',
            'pending' => '⏳',
            default => '❓',
        };
    }

    public function getDecisionLabelAttribute(): string
    {
        return match ($this->decision) {
            'go' => 'GO LIVE ✅',
            'go_limited' => 'GO LIVE (LIMITED / SOFT LAUNCH)',
            'no_go' => 'NO-GO (BLOCKER FOUND)',
            'pending' => 'PENDING DECISION',
            default => 'UNKNOWN',
        };
    }

    public function getBlockerCountAttribute(): int
    {
        return is_array($this->blockers) ? count($this->blockers) : 0;
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Generate unique review ID
     */
    public static function generateReviewId(): string
    {
        $year = date('Y');
        $count = static::whereYear('created_at', $year)->count() + 1;
        return sprintf('PRR-%s-%03d', $year, $count);
    }

    /**
     * Create new review with all checklist items
     */
    public static function startNewReview(
        string $name,
        ?string $description = null,
        ?string $targetEnvironment = 'production',
        ?\DateTimeInterface $targetLaunchDate = null,
        ?int $createdBy = null
    ): self {
        $review = static::create([
            'review_id' => static::generateReviewId(),
            'name' => $name,
            'description' => $description,
            'target_environment' => $targetEnvironment,
            'target_launch_date' => $targetLaunchDate,
            'status' => 'draft',
            'decision' => 'pending',
            'created_by' => $createdBy,
        ]);

        // Create results for all active checklist items
        $items = PrrChecklistItem::active()->get();
        foreach ($items as $item) {
            PrrReviewResult::create([
                'review_id' => $review->id,
                'item_id' => $item->id,
                'status' => 'pending',
            ]);
        }

        $review->updateStatistics();

        return $review;
    }

    /**
     * Update statistics based on results
     */
    public function updateStatistics(): void
    {
        $stats = $this->results()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status IN ('skipped', 'waived') THEN 1 ELSE 0 END) as skipped
            ")
            ->first();

        $this->update([
            'total_items' => $stats->total ?? 0,
            'passed_items' => $stats->passed ?? 0,
            'failed_items' => $stats->failed ?? 0,
            'pending_items' => $stats->pending ?? 0,
            'skipped_items' => $stats->skipped ?? 0,
            'pass_rate' => $stats->total > 0 
                ? (($stats->passed + $stats->skipped) / $stats->total) * 100 
                : 0,
        ]);
    }

    /**
     * Get blockers (failed blocker-severity items)
     */
    public function getBlockerIssues(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->results()
            ->where('status', 'failed')
            ->whereHas('item', fn($q) => $q->where('severity', 'blocker'))
            ->with('item.category')
            ->get();
    }

    /**
     * Make GO/NO-GO decision
     */
    public function makeDecision(?int $decidedBy = null, ?string $rationale = null): array
    {
        $blockerIssues = $this->getBlockerIssues();
        $blockerCount = $blockerIssues->count();

        // Get failed critical items (non-blocker)
        $criticalIssues = $this->results()
            ->where('status', 'failed')
            ->whereHas('item', fn($q) => $q->where('severity', 'critical'))
            ->with('item.category')
            ->get();

        // Update blockers list
        $blockers = $blockerIssues->map(fn($r) => [
            'item_slug' => $r->item->slug,
            'title' => $r->item->title,
            'category' => $r->item->category->name,
            'notes' => $r->notes,
        ])->toArray();

        $this->update(['blockers' => $blockers]);

        // Determine decision
        if ($blockerCount > 0) {
            $decision = 'no_go';
            $status = 'rejected';
            $label = "NO-GO: {$blockerCount} blocker(s) found";
        } elseif ($criticalIssues->count() > 0) {
            $decision = 'go_limited';
            $status = 'approved';
            $label = "GO LIMITED: {$criticalIssues->count()} critical issue(s) - soft launch recommended";
        } else {
            $decision = 'go';
            $status = 'approved';
            $label = 'GO LIVE: All checks passed';
        }

        $this->update([
            'decision' => $decision,
            'status' => $status,
            'decision_rationale' => $rationale ?? $label,
            'approved_by' => $decision !== 'no_go' ? $decidedBy : null,
            'approved_at' => $decision !== 'no_go' ? now() : null,
        ]);

        return [
            'decision' => $decision,
            'label' => $label,
            'blockers' => $blockers,
            'critical_issues' => $criticalIssues->map(fn($r) => [
                'item_slug' => $r->item->slug,
                'title' => $r->item->title,
                'category' => $r->item->category->name,
            ])->toArray(),
            'pass_rate' => $this->pass_rate,
        ];
    }

    /**
     * Start the review process
     */
    public function startReview(?int $reviewerId = null): void
    {
        $this->update([
            'status' => 'in_progress',
            'reviewed_by' => $reviewerId,
        ]);
    }

    /**
     * Submit for decision
     */
    public function submitForDecision(): void
    {
        $this->updateStatistics();
        $this->update(['status' => 'pending']);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    public static function getCurrent(): ?self
    {
        return static::whereIn('status', ['draft', 'in_progress', 'pending'])
            ->latest()
            ->first();
    }

    public static function getLatestApproved(): ?self
    {
        return static::approved()->latest()->first();
    }
}
