<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DAILY RITUAL MODEL
 * 
 * Tracks daily check-ins by Owner/SA during 30-day execution.
 * 
 * Daily Ritual:
 * 1. Buka Executive Dashboard
 * 2. Baca Action Recommendation
 * 3. Ambil 1 keputusan (scale/hold/investigate)
 */
class DailyRitual extends Model
{
    use HasFactory;

    protected $fillable = [
        'ritual_date',
        'execution_period_id',
        'day_number',
        'dashboard_opened',
        'dashboard_opened_at',
        'recommendation_read',
        'recommendation_read_at',
        'decision_made',
        'decision_made_at',
        'decision',
        'decision_notes',
        'decided_by',
        'delivery_rate',
        'failure_rate',
        'abuse_rate',
        'risk_score',
        'error_budget',
        'incidents_count',
        'queue_latency_p95',
        'all_thresholds_met',
        'threshold_results',
        'action_recommendation',
        'urgency',
    ];

    protected $casts = [
        'ritual_date' => 'date',
        'dashboard_opened' => 'boolean',
        'dashboard_opened_at' => 'datetime',
        'recommendation_read' => 'boolean',
        'recommendation_read_at' => 'datetime',
        'decision_made' => 'boolean',
        'decision_made_at' => 'datetime',
        'delivery_rate' => 'decimal:2',
        'failure_rate' => 'decimal:2',
        'abuse_rate' => 'decimal:2',
        'risk_score' => 'decimal:2',
        'error_budget' => 'decimal:2',
        'all_thresholds_met' => 'boolean',
        'threshold_results' => 'array',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function period(): BelongsTo
    {
        return $this->belongsTo(ExecutionPeriod::class, 'execution_period_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeForDate($query, $date)
    {
        return $query->where('ritual_date', $date);
    }

    public function scopeCompleted($query)
    {
        return $query->where('decision_made', true);
    }

    public function scopePending($query)
    {
        return $query->where('decision_made', false);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('ritual_date', '>=', now()->subDays($days));
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Get or create today's ritual
     */
    public static function getOrCreateToday(int $dayNumber, ?int $periodId = null): self
    {
        return static::firstOrCreate(
            ['ritual_date' => now()->toDateString()],
            [
                'day_number' => $dayNumber,
                'execution_period_id' => $periodId,
            ]
        );
    }

    /**
     * Get today's ritual
     */
    public static function getToday(): ?self
    {
        return static::forDate(now()->toDateString())->first();
    }

    // ==========================================
    // COMPUTED ATTRIBUTES
    // ==========================================

    public function getIsCompleteAttribute(): bool
    {
        return $this->dashboard_opened && 
               $this->recommendation_read && 
               $this->decision_made;
    }

    public function getCompletionStepsAttribute(): array
    {
        return [
            [
                'step' => 1,
                'name' => 'Buka Dashboard',
                'completed' => $this->dashboard_opened,
                'completed_at' => $this->dashboard_opened_at,
            ],
            [
                'step' => 2,
                'name' => 'Baca Recommendation',
                'completed' => $this->recommendation_read,
                'completed_at' => $this->recommendation_read_at,
            ],
            [
                'step' => 3,
                'name' => 'Ambil Keputusan',
                'completed' => $this->decision_made,
                'completed_at' => $this->decision_made_at,
            ],
        ];
    }

    public function getDecisionIconAttribute(): string
    {
        return match ($this->decision) {
            'scale' => '📈',
            'hold' => '⏸️',
            'rollback' => '⏪',
            'investigate' => '🔍',
            default => '❓',
        };
    }

    public function getDecisionLabelAttribute(): string
    {
        return match ($this->decision) {
            'scale' => 'Scale (Lanjut)',
            'hold' => 'Hold (Tahan)',
            'rollback' => 'Rollback (Mundur)',
            'investigate' => 'Investigate (Selidiki)',
            default => 'Belum Diputuskan',
        };
    }

    public function getUrgencyIconAttribute(): string
    {
        return match ($this->urgency) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    public function getHealthSummaryAttribute(): string
    {
        $passing = collect($this->threshold_results ?? [])->filter(fn($r) => $r['passed'] ?? false)->count();
        $total = count($this->threshold_results ?? []);
        
        return "✅{$passing} / {$total}";
    }

    // ==========================================
    // RITUAL ACTIONS
    // ==========================================

    /**
     * Mark dashboard as opened
     */
    public function markDashboardOpened(): void
    {
        if (!$this->dashboard_opened) {
            $this->update([
                'dashboard_opened' => true,
                'dashboard_opened_at' => now(),
            ]);
        }
    }

    /**
     * Mark recommendation as read
     */
    public function markRecommendationRead(): void
    {
        if (!$this->recommendation_read) {
            $this->update([
                'recommendation_read' => true,
                'recommendation_read_at' => now(),
            ]);
        }
    }

    /**
     * Record decision
     */
    public function recordDecision(
        string $decision, 
        ?string $notes = null, 
        ?string $decidedBy = null
    ): void {
        $this->update([
            'decision_made' => true,
            'decision_made_at' => now(),
            'decision' => $decision,
            'decision_notes' => $notes,
            'decided_by' => $decidedBy,
        ]);
    }

    /**
     * Record metrics snapshot
     */
    public function recordMetrics(array $metrics, array $thresholdResults, string $recommendation, string $urgency): void
    {
        $this->update([
            'delivery_rate' => $metrics['delivery_rate'] ?? null,
            'failure_rate' => $metrics['failure_rate'] ?? null,
            'abuse_rate' => $metrics['abuse_rate'] ?? null,
            'risk_score' => $metrics['risk_score'] ?? null,
            'error_budget' => $metrics['error_budget'] ?? null,
            'incidents_count' => $metrics['incidents'] ?? 0,
            'queue_latency_p95' => $metrics['queue_latency_p95'] ?? null,
            'all_thresholds_met' => collect($thresholdResults)->every(fn($r) => $r['passed']),
            'threshold_results' => $thresholdResults,
            'action_recommendation' => $recommendation,
            'urgency' => $urgency,
        ]);
    }
}
