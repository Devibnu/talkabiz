<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PILOT USER MODEL
 * 
 * User yang berpartisipasi dalam program pilot
 * Tracking dari pending → approved → active → graduated
 */
class PilotUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'pilot_id',
        'user_id',
        'launch_phase_id',
        'company_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'business_type',
        'industry',
        'status',
        'applied_at',
        'approved_at',
        'activated_at',
        'graduated_at',
        'churned_at',
        'pilot_tier_id',
        'custom_daily_limit',
        'custom_rate_limit',
        'total_messages_sent',
        'avg_delivery_rate',
        'abuse_score',
        'abuse_incidents',
        'support_tickets',
        'total_revenue',
        'monthly_revenue',
        'nps_score',
        'feedback_notes',
        'willing_to_testimonial',
        'willing_to_case_study',
        'internal_notes',
        'assigned_to',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'graduated_at' => 'datetime',
        'churned_at' => 'datetime',
        'avg_delivery_rate' => 'decimal:2',
        'abuse_score' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'monthly_revenue' => 'decimal:2',
        'willing_to_testimonial' => 'boolean',
        'willing_to_case_study' => 'boolean',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function phase(): BelongsTo
    {
        return $this->belongsTo(LaunchPhase::class, 'launch_phase_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(PilotTier::class, 'pilot_tier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(PilotUserMetric::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeGraduated($query)
    {
        return $query->where('status', 'graduated');
    }

    public function scopeChurned($query)
    {
        return $query->where('status', 'churned');
    }

    public function scopeBanned($query)
    {
        return $query->where('status', 'banned');
    }

    public function scopeUmkm($query)
    {
        return $query->where('business_type', 'umkm');
    }

    public function scopeCorporate($query)
    {
        return $query->whereIn('business_type', ['corporate', 'enterprise']);
    }

    public function scopeForPhase($query, $phaseId)
    {
        return $query->where('launch_phase_id', $phaseId);
    }

    public function scopeHighPerformers($query)
    {
        return $query->where('avg_delivery_rate', '>=', 95)
            ->where('abuse_score', '<=', 2);
    }

    public function scopeAtRisk($query)
    {
        return $query->where(function ($q) {
            $q->where('abuse_score', '>', 5)
                ->orWhere('avg_delivery_rate', '<', 85);
        });
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusIconAttribute(): string
    {
        $icons = [
            'pending_approval' => '⏳',
            'approved' => '✅',
            'active' => '🟢',
            'paused' => '⏸️',
            'churned' => '💀',
            'graduated' => '🎓',
            'rejected' => '❌',
            'banned' => '🚫',
        ];
        
        return $icons[$this->status] ?? '❓';
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'pending_approval' => 'Pending Approval',
            'approved' => 'Approved',
            'active' => 'Active',
            'paused' => 'Paused',
            'churned' => 'Churned',
            'graduated' => 'Graduated',
            'rejected' => 'Rejected',
            'banned' => 'Banned',
        ];
        
        return $labels[$this->status] ?? $this->status;
    }

    public function getHealthScoreAttribute(): int
    {
        $score = 100;
        
        // Delivery rate impact (-30 max)
        if ($this->avg_delivery_rate < 95) {
            $score -= (95 - $this->avg_delivery_rate) * 0.6;
        }
        
        // Abuse score impact (-30 max)
        $score -= min(30, $this->abuse_score * 6);
        
        // Support tickets impact (-20 max)
        $score -= min(20, $this->support_tickets * 2);
        
        return max(0, min(100, round($score)));
    }

    public function getHealthStatusAttribute(): string
    {
        $score = $this->health_score;
        
        if ($score >= 80) return '🟢 Healthy';
        if ($score >= 60) return '🟡 Watch';
        if ($score >= 40) return '🟠 At Risk';
        return '🔴 Critical';
    }

    public function getDaysActiveAttribute(): int
    {
        if (!$this->activated_at) {
            return 0;
        }
        
        $endDate = $this->churned_at ?? $this->graduated_at ?? now();
        
        return $this->activated_at->diffInDays($endDate);
    }

    public function getLtvAttribute(): float
    {
        return (float) $this->total_revenue;
    }

    public function getMonthlyArpuAttribute(): float
    {
        $months = max(1, ceil($this->days_active / 30));
        
        return round($this->total_revenue / $months, 2);
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function approve(?string $approvedBy = null): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }
        
        $this->update([
            'status' => 'approved',
            'approved_at' => now(),
            'internal_notes' => $this->internal_notes . "\n[" . now() . "] Approved by: " . ($approvedBy ?? 'System'),
        ]);
        
        return true;
    }

    public function reject(?string $reason = null): bool
    {
        if ($this->status !== 'pending_approval') {
            return false;
        }
        
        $this->update([
            'status' => 'rejected',
            'internal_notes' => $this->internal_notes . "\n[" . now() . "] Rejected: " . ($reason ?? 'No reason provided'),
        ]);
        
        return true;
    }

    public function activate(): bool
    {
        if (!in_array($this->status, ['approved', 'paused'])) {
            return false;
        }
        
        $this->update([
            'status' => 'active',
            'activated_at' => $this->activated_at ?? now(),
        ]);
        
        // Update phase user count
        $this->phase->increment('current_user_count');
        
        return true;
    }

    public function pause(?string $reason = null): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $this->update([
            'status' => 'paused',
            'internal_notes' => $this->internal_notes . "\n[" . now() . "] Paused: " . ($reason ?? 'No reason'),
        ]);
        
        // Update phase user count
        $this->phase->decrement('current_user_count');
        
        return true;
    }

    public function graduate(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }
        
        $this->update([
            'status' => 'graduated',
            'graduated_at' => now(),
        ]);
        
        // Update phase user count
        $this->phase->decrement('current_user_count');
        
        return true;
    }

    public function churn(?string $reason = null): bool
    {
        if (!in_array($this->status, ['active', 'approved', 'paused'])) {
            return false;
        }
        
        if ($this->status === 'active') {
            $this->phase->decrement('current_user_count');
        }
        
        $this->update([
            'status' => 'churned',
            'churned_at' => now(),
            'internal_notes' => $this->internal_notes . "\n[" . now() . "] Churned: " . ($reason ?? 'No reason'),
        ]);
        
        return true;
    }

    public function ban(string $reason): bool
    {
        if ($this->status === 'active') {
            $this->phase->decrement('current_user_count');
        }
        
        $this->update([
            'status' => 'banned',
            'internal_notes' => $this->internal_notes . "\n[" . now() . "] Banned: " . $reason,
        ]);
        
        return true;
    }

    public function recordMetric(array $data): PilotUserMetric
    {
        return $this->metrics()->create(array_merge([
            'metric_date' => now()->toDateString(),
        ], $data));
    }

    public function updateAggregates(): void
    {
        $totals = $this->metrics()->selectRaw('
            SUM(messages_sent) as total_sent,
            SUM(messages_delivered) as total_delivered,
            SUM(abuse_flags) as total_abuse,
            SUM(support_tickets) as total_tickets,
            SUM(revenue) as total_revenue
        ')->first();
        
        $this->update([
            'total_messages_sent' => $totals->total_sent ?? 0,
            'avg_delivery_rate' => $totals->total_sent > 0 
                ? round(($totals->total_delivered / $totals->total_sent) * 100, 2) 
                : 0,
            'abuse_incidents' => $totals->total_abuse ?? 0,
            'support_tickets' => $totals->total_tickets ?? 0,
            'total_revenue' => $totals->total_revenue ?? 0,
        ]);
    }

    public static function createPilot(array $data): self
    {
        return static::create(array_merge([
            'pilot_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'pending_approval',
            'applied_at' => now(),
        ], $data));
    }
}
