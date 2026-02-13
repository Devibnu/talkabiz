<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * IN-APP BANNER MODEL
 * 
 * Real-time in-app notifications/banners.
 * Displayed prominently in user dashboard.
 */
class InAppBanner extends Model
{
    protected $table = 'in_app_banners';

    // ==================== BANNER TYPES ====================
    public const TYPE_INCIDENT = 'incident';
    public const TYPE_MAINTENANCE = 'maintenance';
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_WARNING = 'warning';
    public const TYPE_INFO = 'info';

    // ==================== SEVERITY ====================
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_DANGER = 'danger';
    public const SEVERITY_SUCCESS = 'success';

    public const SEVERITY_COLORS = [
        self::SEVERITY_INFO => 'blue',
        self::SEVERITY_WARNING => 'yellow',
        self::SEVERITY_DANGER => 'red',
        self::SEVERITY_SUCCESS => 'green',
    ];

    protected $fillable = [
        'banner_type',
        'severity',
        'title',
        'message',
        'link_text',
        'link_url',
        'is_dismissible',
        'is_active',
        'target_users',
        'target_components',
        'source_type',
        'source_id',
        'starts_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'is_dismissible' => 'boolean',
        'is_active' => 'boolean',
        'target_users' => 'array',
        'target_components' => 'array',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(BannerDismissal::class, 'banner_id');
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->whereNull('target_users')
              ->orWhereJsonContains('target_users', $userId);
        });
    }

    public function scopeNotDismissedBy($query, int $userId)
    {
        return $query->whereDoesntHave('dismissals', function ($q) use ($userId) {
            $q->where('user_id', $userId);
        });
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('banner_type', $type);
    }

    // ==================== METHODS ====================

    /**
     * Check if banner is visible to user
     */
    public function isVisibleTo(int $userId): bool
    {
        // Check if active and within time range
        if (!$this->is_active) {
            return false;
        }

        if ($this->starts_at > now()) {
            return false;
        }

        if ($this->expires_at && $this->expires_at < now()) {
            return false;
        }

        // Check target users
        if (!empty($this->target_users) && !in_array($userId, $this->target_users)) {
            return false;
        }

        // Check if dismissed
        if ($this->is_dismissible && $this->dismissals()->where('user_id', $userId)->exists()) {
            return false;
        }

        return true;
    }

    /**
     * Dismiss banner for user
     */
    public function dismissForUser(int $userId): bool
    {
        if (!$this->is_dismissible) {
            return false;
        }

        BannerDismissal::firstOrCreate([
            'banner_id' => $this->id,
            'user_id' => $userId,
        ], [
            'dismissed_at' => now(),
        ]);

        return true;
    }

    /**
     * Deactivate banner
     */
    public function deactivate(): bool
    {
        return $this->update(['is_active' => false]);
    }

    // ==================== ACCESSORS ====================

    public function getSeverityColorAttribute(): string
    {
        return self::SEVERITY_COLORS[$this->severity] ?? 'gray';
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at < now();
    }

    // ==================== FACTORY METHODS ====================

    /**
     * Create banner from status incident
     */
    public static function createFromIncident(StatusIncident $incident): self
    {
        $severity = match ($incident->impact) {
            StatusIncident::IMPACT_CRITICAL => self::SEVERITY_DANGER,
            StatusIncident::IMPACT_MAJOR => self::SEVERITY_WARNING,
            default => self::SEVERITY_INFO,
        };

        return self::create([
            'banner_type' => self::TYPE_INCIDENT,
            'severity' => $severity,
            'title' => $incident->title,
            'message' => $incident->summary ?? 'Kami sedang menangani kendala pada layanan.',
            'link_text' => 'Lihat Status',
            'link_url' => '/status',
            'is_dismissible' => false, // Incident banners should stay visible
            'is_active' => true,
            'target_components' => $incident->affected_components,
            'source_type' => StatusIncident::class,
            'source_id' => $incident->id,
            'starts_at' => now(),
            'expires_at' => null, // Will be deactivated when incident resolves
        ]);
    }

    /**
     * Create banner from maintenance
     */
    public static function createFromMaintenance(ScheduledMaintenance $maintenance): self
    {
        return self::create([
            'banner_type' => self::TYPE_MAINTENANCE,
            'severity' => self::SEVERITY_INFO,
            'title' => 'Pemeliharaan Terjadwal',
            'message' => "{$maintenance->title} - {$maintenance->scheduled_start->format('d M H:i')} s/d {$maintenance->scheduled_end->format('H:i')} WIB",
            'link_text' => 'Lihat Detail',
            'link_url' => '/status/maintenance/' . $maintenance->public_id,
            'is_dismissible' => true,
            'is_active' => true,
            'target_components' => $maintenance->affected_components,
            'source_type' => ScheduledMaintenance::class,
            'source_id' => $maintenance->id,
            'starts_at' => $maintenance->scheduled_start->subHours(24), // Show 24h before
            'expires_at' => $maintenance->scheduled_end,
        ]);
    }

    /**
     * Get active banners for user
     */
    public static function getActiveForUser(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        return self::active()
            ->forUser($userId)
            ->notDismissedBy($userId)
            ->orderBy('severity', 'desc')
            ->orderBy('starts_at', 'desc')
            ->get();
    }

    // ==================== PUBLIC API ====================

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->banner_type,
            'severity' => $this->severity,
            'severity_color' => $this->severity_color,
            'title' => $this->title,
            'message' => $this->message,
            'link_text' => $this->link_text,
            'link_url' => $this->link_url,
            'is_dismissible' => $this->is_dismissible,
            'starts_at' => $this->starts_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
