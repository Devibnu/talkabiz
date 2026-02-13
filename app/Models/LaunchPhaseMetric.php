<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH PHASE METRIC MODEL
 * 
 * Go/No-Go criteria per fase
 * Metrik yang harus dipenuhi sebelum lanjut ke fase berikutnya
 */
class LaunchPhaseMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'launch_phase_id',
        'metric_code',
        'metric_name',
        'description',
        'unit',
        'comparison',
        'threshold_value',
        'threshold_value_max',
        'is_go_criteria',
        'is_blocking',
        'weight',
        'current_value',
        'current_status',
        'last_evaluated_at',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:4',
        'threshold_value_max' => 'decimal:4',
        'current_value' => 'decimal:4',
        'is_go_criteria' => 'boolean',
        'is_blocking' => 'boolean',
        'last_evaluated_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'launch_phase_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeGoCriteria($query)
    {
        return $query->where('is_go_criteria', true);
    }

    public function scopeBlocking($query)
    {
        return $query->where('is_blocking', true);
    }

    public function scopePassing($query)
    {
        return $query->where('current_status', 'passing');
    }

    public function scopeFailing($query)
    {
        return $query->where('current_status', 'failing');
    }

    public function scopeWarning($query)
    {
        return $query->where('current_status', 'warning');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusIconAttribute(): string
    {
        $icons = [
            'passing' => '🟢',
            'warning' => '🟡',
            'failing' => '🔴',
            'unknown' => '⚪',
        ];
        
        return $icons[$this->current_status] ?? '⚪';
    }

    public function getStatusLabelAttribute(): string
    {
        return ucfirst($this->current_status);
    }

    public function getComparisonSymbolAttribute(): string
    {
        $symbols = [
            'gte' => '≥',
            'lte' => '≤',
            'eq' => '=',
            'between' => '↔',
        ];
        
        return $symbols[$this->comparison] ?? $this->comparison;
    }

    public function getThresholdDisplayAttribute(): string
    {
        if ($this->comparison === 'between') {
            return "{$this->threshold_value} - {$this->threshold_value_max} {$this->unit}";
        }
        
        return "{$this->comparison_symbol} {$this->threshold_value}{$this->unit}";
    }

    public function getCurrentDisplayAttribute(): string
    {
        if ($this->current_value === null) {
            return 'N/A';
        }
        
        return "{$this->current_value}{$this->unit}";
    }

    public function getBlockingLabelAttribute(): string
    {
        return $this->is_blocking ? '🚫 BLOCKING' : '';
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function evaluate($value): string
    {
        $this->current_value = $value;
        $this->last_evaluated_at = now();
        
        $status = $this->calculateStatus($value);
        $this->current_status = $status;
        $this->save();
        
        return $status;
    }

    public function calculateStatus($value): string
    {
        if ($value === null) {
            return 'unknown';
        }
        
        $isPassing = $this->meetsThreshold($value);
        
        if ($isPassing) {
            return 'passing';
        }
        
        // Check if close to passing (within 10% for warning)
        $isWarning = $this->isCloseToThreshold($value);
        
        return $isWarning ? 'warning' : 'failing';
    }

    public function meetsThreshold($value): bool
    {
        switch ($this->comparison) {
            case 'gte':
                return $value >= $this->threshold_value;
            case 'lte':
                return $value <= $this->threshold_value;
            case 'eq':
                return abs($value - $this->threshold_value) < 0.0001;
            case 'between':
                return $value >= $this->threshold_value && $value <= $this->threshold_value_max;
            default:
                return false;
        }
    }

    public function isCloseToThreshold($value): bool
    {
        $tolerance = $this->threshold_value * 0.1; // 10% tolerance
        
        switch ($this->comparison) {
            case 'gte':
                return $value >= ($this->threshold_value - $tolerance) && $value < $this->threshold_value;
            case 'lte':
                return $value <= ($this->threshold_value + $tolerance) && $value > $this->threshold_value;
            case 'between':
                $lowerWarning = $value >= ($this->threshold_value - $tolerance) && $value < $this->threshold_value;
                $upperWarning = $value <= ($this->threshold_value_max + $tolerance) && $value > $this->threshold_value_max;
                return $lowerWarning || $upperWarning;
            default:
                return false;
        }
    }

    public function getGap(): ?float
    {
        if ($this->current_value === null) {
            return null;
        }
        
        switch ($this->comparison) {
            case 'gte':
                return $this->current_value - $this->threshold_value;
            case 'lte':
                return $this->threshold_value - $this->current_value;
            case 'between':
                if ($this->current_value < $this->threshold_value) {
                    return $this->current_value - $this->threshold_value;
                }
                if ($this->current_value > $this->threshold_value_max) {
                    return $this->threshold_value_max - $this->current_value;
                }
                return 0;
            default:
                return null;
        }
    }

    public function getRecommendation(): string
    {
        if ($this->current_status === 'passing') {
            return "✅ {$this->metric_name} sudah memenuhi target";
        }
        
        $gap = $this->getGap();
        $gapAbs = abs($gap ?? 0);
        
        switch ($this->metric_code) {
            case 'delivery_rate':
                return "📈 Perlu meningkatkan delivery rate sebesar {$gapAbs}% untuk mencapai target";
            case 'abuse_rate':
                return "🛡️ Perlu menurunkan abuse rate sebesar {$gapAbs}% - tinjau kebijakan anti-spam";
            case 'error_budget':
                return "⚠️ Error budget kurang {$gapAbs}% - kurangi incident dan downtime";
            case 'weekly_incidents':
                return "🔧 Terlalu banyak incident - perbaiki monitoring dan alerting";
            case 'user_count':
                return "👥 Perlu menambah {$gapAbs} user aktif untuk mencapai target";
            default:
                return "📊 {$this->metric_name} perlu diperbaiki untuk mencapai target {$this->threshold_display}";
        }
    }
}
