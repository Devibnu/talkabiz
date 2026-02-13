<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApprovalLog Model - Audit Trail untuk Risk Approval Flow
 * 
 * PURPOSE:
 * - Record semua approval actions (approve, reject, suspend)
 * - Audit trail untuk compliance
 * - Track who, when, why untuk setiap perubahan status
 * 
 * @property int $id
 * @property int $klien_id
 * @property string $action approve|reject|suspend|reactivate|request_review
 * @property string|null $status_from
 * @property string $status_to
 * @property int $actor_id Admin/Owner user ID
 * @property string $actor_type admin|owner|system
 * @property string|null $reason
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
class ApprovalLog extends Model
{
    use HasFactory;

    protected $table = 'approval_logs';

    // No updated_at (append-only log)
    const UPDATED_AT = null;

    protected $fillable = [
        'klien_id',
        'action',
        'status_from',
        'status_to',
        'actor_id',
        'actor_type',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'klien_id' => 'integer',
        'actor_id' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Action constants
    const ACTION_APPROVE = 'approve';
    const ACTION_REJECT = 'reject';
    const ACTION_SUSPEND = 'suspend';
    const ACTION_REACTIVATE = 'reactivate';
    const ACTION_REQUEST_REVIEW = 'request_review';

    // Actor type constants
    const ACTOR_ADMIN = 'admin';
    const ACTOR_OWNER = 'owner';
    const ACTOR_SYSTEM = 'system';

    /**
     * Get the klien that was approved/rejected
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Get the admin/owner who performed the action
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Create a new approval log entry.
     * 
     * @param Klien $klien
     * @param string $action
     * @param string $statusTo
     * @param int $actorId
     * @param string $actorType
     * @param string|null $reason
     * @param array|null $metadata
     * @return self
     */
    public static function createLog(
        Klien $klien,
        string $action,
        string $statusTo,
        int $actorId,
        string $actorType = self::ACTOR_ADMIN,
        ?string $reason = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'klien_id' => $klien->id,
            'action' => $action,
            'status_from' => $klien->getOriginal('approval_status'),
            'status_to' => $statusTo,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'reason' => $reason,
            'metadata' => array_merge($metadata ?? [], [
                'klien_name' => $klien->nama_perusahaan,
                'business_type' => $klien->tipe_bisnis,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]),
        ]);
    }

    /**
     * Get approval history for a klien
     * 
     * @param int $klienId
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getHistory(int $klienId, int $limit = 50)
    {
        return self::where('klien_id', $klienId)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent approval actions (admin dashboard)
     * 
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getRecentActions(int $limit = 100)
    {
        return self::with(['klien:id,nama_perusahaan,tipe_bisnis', 'actor:id,name,email'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get approval statistics
     * 
     * @param string|null $period '7d', '30d', '90d'
     * @return array
     */
    public static function getStatistics(?string $period = '30d'): array
    {
        $query = self::query();

        // Apply period filter
        if ($period) {
            $days = (int) filter_var($period, FILTER_SANITIZE_NUMBER_INT);
            $query->where('created_at', '>=', now()->subDays($days));
        }

        $total = $query->count();

        return [
            'total_actions' => $total,
            'by_action' => $query->groupBy('action')
                ->selectRaw('action, count(*) as count')
                ->pluck('count', 'action')
                ->toArray(),
            'by_actor_type' => $query->groupBy('actor_type')
                ->selectRaw('actor_type, count(*) as count')
                ->pluck('count', 'actor_type')
                ->toArray(),
            'period' => $period,
        ];
    }

    /**
     * Scope to filter by action
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to filter by actor
     */
    public function scopeByActor($query, int $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    /**
     * Scope to filter by klien
     */
    public function scopeForKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Get formatted action label
     */
    public function getActionLabelAttribute(): string
    {
        return match($this->action) {
            self::ACTION_APPROVE => 'Approved',
            self::ACTION_REJECT => 'Rejected',
            self::ACTION_SUSPEND => 'Suspended',
            self::ACTION_REACTIVATE => 'Reactivated',
            self::ACTION_REQUEST_REVIEW => 'Review Requested',
            default => ucfirst($this->action),
        };
    }

    /**
     * Get formatted actor label
     */
    public function getActorLabelAttribute(): string
    {
        return $this->actor 
            ? "{$this->actor->name} ({$this->actor_type})"
            : ucfirst($this->actor_type);
    }
}

