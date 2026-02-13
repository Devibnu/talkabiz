<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Incident Communication Model
 * 
 * Tracks internal and external communications during an incident.
 */
class IncidentCommunication extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'incident_id',
        'comm_type',
        'audience',
        'subject',
        'message',
        'author_id',
        'author_name',
        'recipients',
        'is_sent',
        'sent_at',
        'status_page_state',
    ];

    protected $casts = [
        'recipients' => 'array',
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
    ];

    // Communication Types
    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_STATUS_PAGE = 'status_page';
    public const TYPE_EMAIL = 'email';
    public const TYPE_SLACK = 'slack';

    // Audience
    public const AUDIENCE_RESPONDERS = 'responders';
    public const AUDIENCE_STAKEHOLDERS = 'stakeholders';
    public const AUDIENCE_CUSTOMERS = 'customers';
    public const AUDIENCE_PUBLIC = 'public';

    // Status Page States
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_IDENTIFIED = 'identified';
    public const STATUS_MONITORING = 'monitoring';
    public const STATUS_RESOLVED = 'resolved';

    protected static function boot()
    {
        parent::boot();

        static::creating(function (IncidentCommunication $comm) {
            if (empty($comm->uuid)) {
                $comm->uuid = Str::uuid()->toString();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // ==================== ACTIONS ====================

    public function markAsSent(): bool
    {
        $this->is_sent = true;
        $this->sent_at = now();
        return $this->save();
    }

    // ==================== SCOPES ====================

    public function scopeSent($query)
    {
        return $query->where('is_sent', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_sent', false);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('comm_type', $type);
    }

    public function scopeForAudience($query, string $audience)
    {
        return $query->where('audience', $audience);
    }

    public function scopeStatusPageUpdates($query)
    {
        return $query->where('comm_type', self::TYPE_STATUS_PAGE);
    }

    // ==================== HELPERS ====================

    public function isExternal(): bool
    {
        return in_array($this->audience, [self::AUDIENCE_CUSTOMERS, self::AUDIENCE_PUBLIC]);
    }

    public function isStatusPageUpdate(): bool
    {
        return $this->comm_type === self::TYPE_STATUS_PAGE;
    }
}
