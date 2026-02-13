<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LAUNCH PHASE MODEL
 * 
 * Fase peluncuran: UMKM Pilot → UMKM Scale → Corporate
 * Setiap fase memiliki target, batas, dan kriteria go/no-go
 */
class LaunchPhase extends Model
{
    use HasFactory;

    protected $fillable = [
        'phase_code',
        'phase_name',
        'description',
        'target_users_min',
        'target_users_max',
        'estimated_duration_days',
        'planned_start_date',
        'planned_end_date',
        'status',
        'actual_start_date',
        'actual_end_date',
        'current_user_count',
        'max_daily_messages_per_user',
        'max_campaign_size',
        'max_messages_per_minute',
        'require_manual_approval',
        'self_service_enabled',
        'min_delivery_rate',
        'max_abuse_rate',
        'min_error_budget',
        'max_incidents_per_week',
        'max_support_tickets_per_user_week',
        'target_revenue_min',
        'target_revenue_max',
        'actual_revenue',
        'phase_order',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'require_manual_approval' => 'boolean',
        'self_service_enabled' => 'boolean',
        'min_delivery_rate' => 'decimal:2',
        'max_abuse_rate' => 'decimal:2',
        'min_error_budget' => 'decimal:2',
        'target_revenue_min' => 'decimal:2',
        'target_revenue_max' => 'decimal:2',
        'actual_revenue' => 'decimal:2',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function metrics(): HasMany
    {
        return $this->hasMany(LaunchPhaseMetric::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(LaunchMetricSnapshot::class);
    }

    public function pilotUsers(): HasMany
    {
        return $this->hasMany(PilotUser::class);
    }

    public function pilotTiers(): HasMany
    {
        return $this->hasMany(PilotTier::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(LaunchChecklist::class);
    }

    public function communications(): HasMany
    {
        return $this->hasMany(LaunchCommunication::class);
    }

    public function transitionsTo(): HasMany
    {
        return $this->hasMany(PhaseTransitionLog::class, 'to_phase_id');
    }

    public function transitionsFrom(): HasMany
    {
        return $this->hasMany(PhaseTransitionLog::class, 'from_phase_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePlanned($query)
    {
        return $query->where('status', 'planned');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('phase_order');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'planned' => '📋 Planned',
            'active' => '🟢 Active',
            'completed' => '✅ Completed',
            'paused' => '⏸️ Paused',
            'skipped' => '⏭️ Skipped',
        ];
        
        return $labels[$this->status] ?? $this->status;
    }

    public function getProgressPercentAttribute(): float
    {
        if ($this->target_users_max <= 0) {
            return 0;
        }
        
        return min(100, round(($this->current_user_count / $this->target_users_max) * 100, 1));
    }

    public function getRevenueProgressPercentAttribute(): float
    {
        if ($this->target_revenue_min <= 0) {
            return 0;
        }
        
        return min(100, round(($this->actual_revenue / $this->target_revenue_min) * 100, 1));
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (!$this->actual_start_date) {
            return null;
        }
        
        $endDate = $this->planned_end_date ?? $this->actual_start_date->addDays($this->estimated_duration_days);
        
        return max(0, now()->diffInDays($endDate, false));
    }

    public function getDaysActiveAttribute(): int
    {
        if (!$this->actual_start_date) {
            return 0;
        }
        
        $endDate = $this->actual_end_date ?? now();
        
        return $this->actual_start_date->diffInDays($endDate);
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function isReadyForNextPhase(): bool
    {
        $metrics = $this->metrics()->where('is_go_criteria', true)->get();
        
        foreach ($metrics as $metric) {
            if ($metric->is_blocking && $metric->current_status === 'failing') {
                return false;
            }
        }
        
        // Check checklists
        $requiredChecklists = $this->checklists()
            ->where('is_required', true)
            ->where('when_required', 'before_next_phase')
            ->where('is_completed', false)
            ->count();
        
        return $requiredChecklists === 0;
    }

    public function getBlockers(): array
    {
        $blockers = [];
        
        // Blocking metrics
        $failingMetrics = $this->metrics()
            ->where('is_blocking', true)
            ->where('current_status', 'failing')
            ->get();
        
        foreach ($failingMetrics as $metric) {
            $blockers[] = [
                'type' => 'metric',
                'code' => $metric->metric_code,
                'name' => $metric->metric_name,
                'current' => $metric->current_value,
                'required' => $metric->threshold_value,
                'comparison' => $metric->comparison,
            ];
        }
        
        // Incomplete required checklists
        $incompleteChecklists = $this->checklists()
            ->where('is_required', true)
            ->where('is_completed', false)
            ->get();
        
        foreach ($incompleteChecklists as $item) {
            $blockers[] = [
                'type' => 'checklist',
                'code' => $item->item_code,
                'name' => $item->item_title,
                'category' => $item->category,
            ];
        }
        
        return $blockers;
    }

    public function getGoNoGoSummary(): array
    {
        $metrics = $this->metrics()->where('is_go_criteria', true)->get();
        
        $passing = $metrics->where('current_status', 'passing')->count();
        $warning = $metrics->where('current_status', 'warning')->count();
        $failing = $metrics->where('current_status', 'failing')->count();
        $unknown = $metrics->where('current_status', 'unknown')->count();
        
        $total = $metrics->count();
        $blockingFailing = $metrics->where('is_blocking', true)->where('current_status', 'failing')->count();
        
        return [
            'total' => $total,
            'passing' => $passing,
            'warning' => $warning,
            'failing' => $failing,
            'unknown' => $unknown,
            'blocking_failing' => $blockingFailing,
            'pass_rate' => $total > 0 ? round(($passing / $total) * 100, 1) : 0,
            'ready' => $blockingFailing === 0 && $failing === 0,
        ];
    }

    public function activate(): bool
    {
        if ($this->status !== 'planned') {
            return false;
        }
        
        // Check pre-start checklists
        $incompletePreStart = $this->checklists()
            ->where('is_required', true)
            ->where('when_required', 'before_start')
            ->where('is_completed', false)
            ->count();
        
        if ($incompletePreStart > 0) {
            return false;
        }
        
        $this->update([
            'status' => 'active',
            'actual_start_date' => now(),
        ]);
        
        return true;
    }

    public function complete(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $this->update([
            'status' => 'completed',
            'actual_end_date' => now(),
        ]);
        
        return true;
    }

    public function pause(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $this->update(['status' => 'paused']);
        
        return true;
    }

    public function resume(): bool
    {
        if ($this->status !== 'paused') {
            return false;
        }
        
        $this->update(['status' => 'active']);
        
        return true;
    }

    public function getNextPhase(): ?self
    {
        return static::where('phase_order', '>', $this->phase_order)
            ->orderBy('phase_order')
            ->first();
    }

    public function getPreviousPhase(): ?self
    {
        return static::where('phase_order', '<', $this->phase_order)
            ->orderBy('phase_order', 'desc')
            ->first();
    }

    public static function getCurrentPhase(): ?self
    {
        return static::active()->first();
    }

    public static function getPhaseByCode(string $code): ?self
    {
        return static::where('phase_code', $code)->first();
    }
}
