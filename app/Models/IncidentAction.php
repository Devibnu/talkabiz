<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Incident Action Model
 * 
 * Tracks Corrective and Preventive Actions (CAPA) for incidents.
 * Ensures incidents are not closed without proper follow-up.
 */
class IncidentAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'incident_id',
        'action_type',
        'title',
        'description',
        'priority',
        'owner_id',
        'owner_team',
        'status',
        'due_date',
        'completed_date',
        'jira_ticket',
        'completion_notes',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_date' => 'date',
        'verified_at' => 'datetime',
    ];

    // Action Types
    public const TYPE_IMMEDIATE = 'immediate';
    public const TYPE_CORRECTIVE = 'corrective';
    public const TYPE_PREVENTIVE = 'preventive';
    public const TYPE_DETECTIVE = 'detective';
    public const TYPE_MONITORING = 'monitoring';

    // Priority
    public const PRIORITY_P0 = 'P0';
    public const PRIORITY_P1 = 'P1';
    public const PRIORITY_P2 = 'P2';
    public const PRIORITY_P3 = 'P3';

    // Status
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_CANCELLED = 'cancelled';

    protected static function boot()
    {
        parent::boot();

        static::creating(function (IncidentAction $action) {
            if (empty($action->uuid)) {
                $action->uuid = Str::uuid()->toString();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // ==================== ACTIONS ====================

    public function start(): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }

        $this->status = self::STATUS_IN_PROGRESS;
        return $this->save();
    }

    public function complete(?string $notes = null): bool
    {
        if (!in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS])) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completed_date = now();
        if ($notes) {
            $this->completion_notes = $notes;
        }
        return $this->save();
    }

    public function verify(int $verifierId, ?string $notes = null): bool
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            return false;
        }

        $this->status = self::STATUS_VERIFIED;
        $this->verified_by = $verifierId;
        $this->verified_at = now();
        if ($notes) {
            $this->verification_notes = $notes;
        }
        return $this->save();
    }

    public function cancel(?string $reason = null): bool
    {
        if ($this->status === self::STATUS_VERIFIED) {
            return false;  // Cannot cancel verified actions
        }

        $this->status = self::STATUS_CANCELLED;
        if ($reason) {
            $this->completion_notes = "Cancelled: {$reason}";
        }
        return $this->save();
    }

    public function assign(int $ownerId, ?string $team = null): bool
    {
        $this->owner_id = $ownerId;
        if ($team) {
            $this->owner_team = $team;
        }
        return $this->save();
    }

    // ==================== SCOPES ====================

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeVerified($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeOverdue($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS])
            ->where('due_date', '<', now()->toDateString());
    }

    public function scopePriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeCritical($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_P0, self::PRIORITY_P1]);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('action_type', $type);
    }

    public function scopeForOwner($query, int $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeForTeam($query, string $team)
    {
        return $query->where('owner_team', $team);
    }

    // ==================== HELPERS ====================

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_VERIFIED]);
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isOverdue(): bool
    {
        if (!$this->due_date || !$this->isOpen()) {
            return false;
        }
        return $this->due_date->isPast();
    }

    public function getDaysOverdue(): ?int
    {
        if (!$this->isOverdue()) {
            return null;
        }
        return $this->due_date->diffInDays(now());
    }

    public function getDaysUntilDue(): ?int
    {
        if (!$this->due_date || !$this->isOpen()) {
            return null;
        }
        if ($this->due_date->isPast()) {
            return null;
        }
        return now()->diffInDays($this->due_date);
    }

    public function getTypeLabel(): string
    {
        return match($this->action_type) {
            self::TYPE_IMMEDIATE => 'Immediate Fix',
            self::TYPE_CORRECTIVE => 'Corrective Action',
            self::TYPE_PREVENTIVE => 'Preventive Action',
            self::TYPE_DETECTIVE => 'Detection Improvement',
            self::TYPE_MONITORING => 'Monitoring Enhancement',
            default => ucfirst($this->action_type),
        };
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            self::STATUS_OPEN => 'Open',
            self::STATUS_IN_PROGRESS => 'In Progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_VERIFIED => 'Verified',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status),
        };
    }

    public function getPriorityColor(): string
    {
        return match($this->priority) {
            self::PRIORITY_P0 => 'red',
            self::PRIORITY_P1 => 'orange',
            self::PRIORITY_P2 => 'yellow',
            self::PRIORITY_P3 => 'blue',
            default => 'gray',
        };
    }
}
