<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CORPORATE PROSPECT MODEL
 * 
 * Pipeline untuk corporate (sebelum jadi pilot)
 * Track dari lead → qualified → won
 */
class CorporateProspect extends Model
{
    use HasFactory;

    protected $fillable = [
        'prospect_id',
        'company_name',
        'industry',
        'company_size',
        'website',
        'contact_name',
        'contact_title',
        'contact_email',
        'contact_phone',
        'source',
        'referral_from',
        'how_found_us',
        'estimated_monthly_volume',
        'use_case',
        'requirements',
        'current_solution',
        'status',
        'deal_value',
        'deal_term',
        'probability_percent',
        'expected_close_date',
        'objections',
        'notes',
        'assigned_to',
        'last_contacted_at',
        'next_followup_at',
        'converted_to_pilot_id',
        'converted_at',
    ];

    protected $casts = [
        'requirements' => 'array',
        'deal_value' => 'decimal:2',
        'expected_close_date' => 'date',
        'last_contacted_at' => 'datetime',
        'next_followup_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function convertedPilot(): BelongsTo
    {
        return $this->belongsTo(PilotUser::class, 'converted_to_pilot_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeLeads($query)
    {
        return $query->where('status', 'lead');
    }

    public function scopeQualified($query)
    {
        return $query->where('status', 'qualified');
    }

    public function scopeInNegotiation($query)
    {
        return $query->whereIn('status', ['proposal_sent', 'negotiation']);
    }

    public function scopeWon($query)
    {
        return $query->where('status', 'won');
    }

    public function scopeLost($query)
    {
        return $query->where('status', 'lost');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['won', 'lost', 'on_hold']);
    }

    public function scopeNeedFollowup($query)
    {
        return $query->active()
            ->whereNotNull('next_followup_at')
            ->where('next_followup_at', '<=', now());
    }

    public function scopeAssignedTo($query, $assignee)
    {
        return $query->where('assigned_to', $assignee);
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusIconAttribute(): string
    {
        $icons = [
            'lead' => '🔵',
            'qualified' => '🟢',
            'demo_scheduled' => '📅',
            'demo_completed' => '✅',
            'proposal_sent' => '📨',
            'negotiation' => '🤝',
            'won' => '🏆',
            'lost' => '❌',
            'on_hold' => '⏸️',
        ];
        
        return $icons[$this->status] ?? '❓';
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'lead' => 'Lead',
            'qualified' => 'Qualified',
            'demo_scheduled' => 'Demo Scheduled',
            'demo_completed' => 'Demo Completed',
            'proposal_sent' => 'Proposal Sent',
            'negotiation' => 'In Negotiation',
            'won' => 'Won',
            'lost' => 'Lost',
            'on_hold' => 'On Hold',
        ];
        
        return $labels[$this->status] ?? $this->status;
    }

    public function getDealValueFormattedAttribute(): string
    {
        if (!$this->deal_value) {
            return '-';
        }
        
        return 'Rp ' . number_format($this->deal_value, 0, ',', '.');
    }

    public function getWeightedValueAttribute(): float
    {
        if (!$this->deal_value || !$this->probability_percent) {
            return 0;
        }
        
        return $this->deal_value * ($this->probability_percent / 100);
    }

    public function getDaysSinceContactAttribute(): ?int
    {
        if (!$this->last_contacted_at) {
            return null;
        }
        
        return $this->last_contacted_at->diffInDays(now());
    }

    public function getIsOverdueAttribute(): bool
    {
        if (!$this->next_followup_at) {
            return false;
        }
        
        return $this->next_followup_at->isPast();
    }

    public function getPipelineStageAttribute(): int
    {
        $stages = [
            'lead' => 1,
            'qualified' => 2,
            'demo_scheduled' => 3,
            'demo_completed' => 4,
            'proposal_sent' => 5,
            'negotiation' => 6,
            'won' => 7,
        ];
        
        return $stages[$this->status] ?? 0;
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function qualify(): bool
    {
        if ($this->status !== 'lead') {
            return false;
        }
        
        $this->update(['status' => 'qualified']);
        
        return true;
    }

    public function scheduleDemo(\DateTime $date): bool
    {
        if (!in_array($this->status, ['lead', 'qualified'])) {
            return false;
        }
        
        $this->update([
            'status' => 'demo_scheduled',
            'next_followup_at' => $date,
        ]);
        
        return true;
    }

    public function completeDemo(): bool
    {
        if ($this->status !== 'demo_scheduled') {
            return false;
        }
        
        $this->update(['status' => 'demo_completed']);
        
        return true;
    }

    public function sendProposal(float $value, string $term = 'monthly'): bool
    {
        if (!in_array($this->status, ['demo_completed', 'qualified'])) {
            return false;
        }
        
        $this->update([
            'status' => 'proposal_sent',
            'deal_value' => $value,
            'deal_term' => $term,
            'last_contacted_at' => now(),
        ]);
        
        return true;
    }

    public function startNegotiation(): bool
    {
        if ($this->status !== 'proposal_sent') {
            return false;
        }
        
        $this->update(['status' => 'negotiation']);
        
        return true;
    }

    public function win(): bool
    {
        $this->update([
            'status' => 'won',
            'probability_percent' => 100,
        ]);
        
        return true;
    }

    public function lose(string $reason = null): bool
    {
        $notes = $this->notes ?? '';
        if ($reason) {
            $notes .= "\n[Lost] " . now() . ": " . $reason;
        }
        
        $this->update([
            'status' => 'lost',
            'probability_percent' => 0,
            'notes' => $notes,
        ]);
        
        return true;
    }

    public function hold(string $reason = null): bool
    {
        $notes = $this->notes ?? '';
        if ($reason) {
            $notes .= "\n[Hold] " . now() . ": " . $reason;
        }
        
        $this->update([
            'status' => 'on_hold',
            'notes' => $notes,
        ]);
        
        return true;
    }

    public function reactivate(): bool
    {
        if ($this->status !== 'on_hold') {
            return false;
        }
        
        // Go back to last active stage
        $this->update([
            'status' => 'qualified',
            'notes' => ($this->notes ?? '') . "\n[Reactivated] " . now(),
        ]);
        
        return true;
    }

    public function convertToPilot(int $phaseId, int $tierId): ?PilotUser
    {
        if ($this->status !== 'won') {
            return null;
        }
        
        $pilot = PilotUser::createPilot([
            'launch_phase_id' => $phaseId,
            'pilot_tier_id' => $tierId,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'business_type' => 'corporate',
            'industry' => $this->industry,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
        
        $this->update([
            'converted_to_pilot_id' => $pilot->id,
            'converted_at' => now(),
        ]);
        
        return $pilot;
    }

    public function recordContact(string $note = null): void
    {
        $notes = $this->notes ?? '';
        if ($note) {
            $notes .= "\n[Contact] " . now() . ": " . $note;
        }
        
        $this->update([
            'last_contacted_at' => now(),
            'notes' => $notes,
        ]);
    }

    public static function createProspect(array $data): self
    {
        return static::create(array_merge([
            'prospect_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'lead',
        ], $data));
    }

    public static function getPipelineStats(): array
    {
        $prospects = static::active()->get();
        
        $byStatus = $prospects->groupBy('status');
        
        return [
            'total_active' => $prospects->count(),
            'total_value' => $prospects->sum('deal_value'),
            'weighted_value' => $prospects->sum('weighted_value'),
            'by_status' => $byStatus->map->count(),
            'overdue_followups' => static::needFollowup()->count(),
        ];
    }
}
