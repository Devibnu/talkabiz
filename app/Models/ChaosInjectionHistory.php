<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * =============================================================================
 * CHAOS INJECTION HISTORY MODEL
 * =============================================================================
 * 
 * Audit log of all chaos injections (enable/disable/modify)
 * 
 * =============================================================================
 */
class ChaosInjectionHistory extends Model
{
    protected $table = 'chaos_injection_history';

    protected $fillable = [
        'experiment_id',
        'flag_key',
        'injection_type',
        'target',
        'config',
        'action',
        'performed_by',
        'performed_at'
    ];

    protected $casts = [
        'config' => 'array',
        'performed_at' => 'datetime'
    ];

    // ==================== CONSTANTS ====================

    const ACTION_ENABLED = 'enabled';
    const ACTION_DISABLED = 'disabled';
    const ACTION_MODIFIED = 'modified';

    // ==================== RELATIONSHIPS ====================

    public function experiment(): BelongsTo
    {
        return $this->belongsTo(ChaosExperiment::class, 'experiment_id');
    }

    // ==================== SCOPES ====================

    public function scopeByExperiment($query, int $experimentId)
    {
        return $query->where('experiment_id', $experimentId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('performed_at', '>=', now()->subHours($hours));
    }
}
