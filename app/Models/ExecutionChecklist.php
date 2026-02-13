<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EXECUTION CHECKLIST MODEL
 * 
 * Checklist items for each execution period.
 */
class ExecutionChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_period_id',
        'item_code',
        'item_title',
        'item_description',
        'category',
        'is_required',
        'is_completed',
        'completed_at',
        'completed_by',
        'notes',
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(ExecutionPeriod::class, 'execution_period_id');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_completed', false);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order');
    }

    // ==========================================
    // COMPUTED ATTRIBUTES
    // ==========================================

    public function getStatusIconAttribute(): string
    {
        return $this->is_completed ? '✅' : ($this->is_required ? '🔴' : '⚪');
    }

    public function getCategoryIconAttribute(): string
    {
        return match ($this->category) {
            'setup' => '⚙️',
            'monitoring' => '📊',
            'review' => '👀',
            'action' => '⚡',
            'gate' => '🚦',
            default => '📋',
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'setup' => 'Setup',
            'monitoring' => 'Monitoring',
            'review' => 'Review',
            'action' => 'Action',
            'gate' => 'Gate Decision',
            default => 'Other',
        };
    }

    // ==========================================
    // ACTIONS
    // ==========================================

    /**
     * Mark checklist item as completed
     */
    public function complete(?string $completedBy = null, ?string $notes = null): bool
    {
        return $this->update([
            'is_completed' => true,
            'completed_at' => now(),
            'completed_by' => $completedBy,
            'notes' => $notes,
        ]);
    }

    /**
     * Mark checklist item as incomplete
     */
    public function uncomplete(): bool
    {
        return $this->update([
            'is_completed' => false,
            'completed_at' => null,
            'completed_by' => null,
        ]);
    }
}
