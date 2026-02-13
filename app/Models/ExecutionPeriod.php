<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * EXECUTION PERIOD MODEL
 * 
 * Represents a period in the 30-day soft-launch execution.
 * 
 * Periods:
 * - day_1_3: Stabilitas Awal (Observe)
 * - day_4_7: Validasi Perilaku UMKM
 * - day_8_14: Kontrol & Optimasi
 * - day_15_21: Scale Terkontrol
 * - day_22_30: Readiness Gate
 */
class ExecutionPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'period_code',
        'period_name',
        'description',
        'target',
        'day_start',
        'day_end',
        'actual_start_date',
        'actual_end_date',
        'status',
        'min_delivery_rate',
        'max_failure_rate',
        'max_abuse_rate',
        'max_risk_score',
        'max_incidents',
        'min_error_budget',
        'max_queue_latency_p95',
        'max_campaign_recipients',
        'throttling_active',
        'template_manual_approval',
        'auto_pause_enabled',
        'auto_suspend_enabled',
        'self_service_enabled',
        'corporate_flag_off',
        'promo_blocked',
        'phase_locked',
        'gate_result',
        'gate_notes',
        'gate_decided_at',
        'display_order',
    ];

    protected $casts = [
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
        'gate_decided_at' => 'datetime',
        'min_delivery_rate' => 'decimal:2',
        'max_failure_rate' => 'decimal:2',
        'max_abuse_rate' => 'decimal:2',
        'max_risk_score' => 'decimal:2',
        'min_error_budget' => 'decimal:2',
        'throttling_active' => 'boolean',
        'template_manual_approval' => 'boolean',
        'auto_pause_enabled' => 'boolean',
        'auto_suspend_enabled' => 'boolean',
        'self_service_enabled' => 'boolean',
        'corporate_flag_off' => 'boolean',
        'promo_blocked' => 'boolean',
        'phase_locked' => 'boolean',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function checklists(): HasMany
    {
        return $this->hasMany(ExecutionChecklist::class)->orderBy('display_order');
    }

    public function dailyRituals(): HasMany
    {
        return $this->hasMany(DailyRitual::class);
    }

    public function gateDecision(): HasOne
    {
        return $this->hasOne(GateDecision::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(ExecutionViolation::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'upcoming');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // ==========================================
    // STATIC HELPERS
    // ==========================================

    /**
     * Get current active period
     */
    public static function getCurrentPeriod(): ?self
    {
        return static::active()->first();
    }

    /**
     * Get period by day number
     */
    public static function getPeriodByDay(int $day): ?self
    {
        return static::where('day_start', '<=', $day)
            ->where('day_end', '>=', $day)
            ->first();
    }

    /**
     * Get period by code
     */
    public static function getByCode(string $code): ?self
    {
        return static::where('period_code', $code)->first();
    }

    // ==========================================
    // COMPUTED ATTRIBUTES
    // ==========================================

    public function getDurationDaysAttribute(): int
    {
        return $this->day_end - $this->day_start + 1;
    }

    public function getStatusIconAttribute(): string
    {
        return match ($this->status) {
            'upcoming' => '📋',
            'active' => '🟢',
            'completed' => '✅',
            'failed' => '🔴',
            default => '⚪',
        };
    }

    public function getGateIconAttribute(): string
    {
        return match ($this->gate_result) {
            'go' => '✅',
            'no_go' => '🔴',
            'conditional' => '🟡',
            default => '⏳',
        };
    }

    public function getChecklistProgressAttribute(): array
    {
        $total = $this->checklists()->count();
        $completed = $this->checklists()->where('is_completed', true)->count();
        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

        return [
            'completed' => $completed,
            'total' => $total,
            'percentage' => $percentage,
        ];
    }

    // ==========================================
    // THRESHOLD CHECKING
    // ==========================================

    /**
     * Check if metrics meet all thresholds
     */
    public function checkThresholds(array $metrics): array
    {
        $results = [];

        // Delivery Rate (must be >= threshold)
        $results['delivery_rate'] = [
            'threshold' => $this->min_delivery_rate,
            'actual' => $metrics['delivery_rate'] ?? 0,
            'comparison' => 'gte',
            'passed' => ($metrics['delivery_rate'] ?? 0) >= $this->min_delivery_rate,
        ];

        // Failure Rate (must be <= threshold)
        $results['failure_rate'] = [
            'threshold' => $this->max_failure_rate,
            'actual' => $metrics['failure_rate'] ?? 0,
            'comparison' => 'lte',
            'passed' => ($metrics['failure_rate'] ?? 0) <= $this->max_failure_rate,
        ];

        // Abuse Rate (must be <= threshold)
        $results['abuse_rate'] = [
            'threshold' => $this->max_abuse_rate,
            'actual' => $metrics['abuse_rate'] ?? 0,
            'comparison' => 'lte',
            'passed' => ($metrics['abuse_rate'] ?? 0) <= $this->max_abuse_rate,
        ];

        // Risk Score (must be <= threshold)
        $results['risk_score'] = [
            'threshold' => $this->max_risk_score,
            'actual' => $metrics['risk_score'] ?? 0,
            'comparison' => 'lte',
            'passed' => ($metrics['risk_score'] ?? 0) <= $this->max_risk_score,
        ];

        // Error Budget (must be >= threshold)
        $results['error_budget'] = [
            'threshold' => $this->min_error_budget,
            'actual' => $metrics['error_budget'] ?? 0,
            'comparison' => 'gte',
            'passed' => ($metrics['error_budget'] ?? 0) >= $this->min_error_budget,
        ];

        // Incidents (must be <= threshold)
        $results['incidents'] = [
            'threshold' => $this->max_incidents,
            'actual' => $metrics['incidents'] ?? 0,
            'comparison' => 'lte',
            'passed' => ($metrics['incidents'] ?? 0) <= $this->max_incidents,
        ];

        return $results;
    }

    /**
     * Check if all thresholds are met
     */
    public function allThresholdsMet(array $metrics): bool
    {
        $results = $this->checkThresholds($metrics);
        return collect($results)->every(fn($r) => $r['passed']);
    }

    // ==========================================
    // PERIOD LIFECYCLE
    // ==========================================

    /**
     * Activate this period
     */
    public function activate(): bool
    {
        if ($this->status !== 'upcoming') {
            return false;
        }

        $this->update([
            'status' => 'active',
            'actual_start_date' => now(),
        ]);

        return true;
    }

    /**
     * Complete this period with gate decision
     */
    public function complete(string $gateResult, ?string $notes = null): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->update([
            'status' => $gateResult === 'no_go' ? 'failed' : 'completed',
            'actual_end_date' => now(),
            'gate_result' => $gateResult,
            'gate_notes' => $notes,
            'gate_decided_at' => now(),
        ]);

        return true;
    }

    /**
     * Get restrictions/larangan for this period
     */
    public function getRestrictions(): array
    {
        $restrictions = [];

        if ($this->corporate_flag_off) {
            $restrictions[] = [
                'type' => 'corporate_access',
                'title' => 'Corporate Access OFF',
                'description' => 'Tidak boleh membuka akses corporate',
            ];
        }

        if ($this->promo_blocked) {
            $restrictions[] = [
                'type' => 'promo_attempt',
                'title' => 'Promo Besar Dilarang',
                'description' => 'Tidak boleh menjalankan promo besar',
            ];
        }

        if ($this->template_manual_approval) {
            $restrictions[] = [
                'type' => 'template_override',
                'title' => 'Manual Template Approval',
                'description' => 'Tidak boleh override manual approval',
            ];
        }

        if ($this->auto_suspend_enabled) {
            $restrictions[] = [
                'type' => 'auto_suspend_override',
                'title' => 'Auto-Suspend Aktif',
                'description' => 'Tidak boleh override auto-suspend',
            ];
        }

        return $restrictions;
    }
}
