<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAUNCH CHECKLIST MODEL
 * 
 * Checklist yang harus dipenuhi per fase
 */
class LaunchChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'launch_phase_id',
        'item_code',
        'item_title',
        'item_description',
        'category',
        'is_required',
        'when_required',
        'is_completed',
        'completed_at',
        'completed_by',
        'completion_notes',
        'evidence_url',
        'display_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
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

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeOptional($query)
    {
        return $query->where('is_required', false);
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_completed', false);
    }

    public function scopeForCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBeforeStart($query)
    {
        return $query->where('when_required', 'before_start');
    }

    public function scopeDuring($query)
    {
        return $query->where('when_required', 'during');
    }

    public function scopeBeforeNextPhase($query)
    {
        return $query->where('when_required', 'before_next_phase');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // ==========================================
    // ACCESSORS
    // ==========================================

    public function getStatusIconAttribute(): string
    {
        if ($this->is_completed) {
            return '✅';
        }
        
        return $this->is_required ? '🔴' : '⚪';
    }

    public function getCategoryIconAttribute(): string
    {
        $icons = [
            'technical' => '⚙️',
            'operational' => '📋',
            'commercial' => '💰',
            'legal' => '⚖️',
            'marketing' => '📢',
            'support' => '🎧',
        ];
        
        return $icons[$this->category] ?? '📌';
    }

    public function getCategoryLabelAttribute(): string
    {
        return ucfirst($this->category);
    }

    public function getWhenRequiredLabelAttribute(): string
    {
        $labels = [
            'before_start' => 'Before Start',
            'during' => 'During Phase',
            'before_next_phase' => 'Before Next Phase',
        ];
        
        return $labels[$this->when_required] ?? $this->when_required;
    }

    public function getPriorityLabelAttribute(): string
    {
        if (!$this->is_required) {
            return '🟢 Optional';
        }
        
        if ($this->when_required === 'before_start') {
            return '🔴 Critical';
        }
        
        return '🟡 Required';
    }

    // ==========================================
    // BUSINESS LOGIC
    // ==========================================

    public function complete(string $completedBy, string $notes = null, string $evidenceUrl = null): bool
    {
        if ($this->is_completed) {
            return false;
        }
        
        $this->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $completedBy,
            'completion_notes' => $notes,
            'evidence_url' => $evidenceUrl,
        ]);
        
        return true;
    }

    public function uncomplete(): bool
    {
        if (!$this->is_completed) {
            return false;
        }
        
        $this->update([
            'is_completed' => false,
            'completed_at' => null,
            'completed_by' => null,
            'completion_notes' => null,
            'evidence_url' => null,
        ]);
        
        return true;
    }

    public static function getProgressForPhase(LaunchPhase $phase): array
    {
        $all = static::where('launch_phase_id', $phase->id)->get();
        $required = $all->where('is_required', true);
        $completed = $all->where('is_completed', true);
        $requiredCompleted = $required->where('is_completed', true);
        
        return [
            'total' => $all->count(),
            'completed' => $completed->count(),
            'pending' => $all->count() - $completed->count(),
            'required_total' => $required->count(),
            'required_completed' => $requiredCompleted->count(),
            'required_pending' => $required->count() - $requiredCompleted->count(),
            'progress_percent' => $all->count() > 0 
                ? round(($completed->count() / $all->count()) * 100, 1) 
                : 0,
            'required_progress_percent' => $required->count() > 0 
                ? round(($requiredCompleted->count() / $required->count()) * 100, 1) 
                : 0,
        ];
    }

    public static function getBlockingItems(LaunchPhase $phase, string $when): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('launch_phase_id', $phase->id)
            ->required()
            ->pending()
            ->where('when_required', $when)
            ->ordered()
            ->get();
    }
}
