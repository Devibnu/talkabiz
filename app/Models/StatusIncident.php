<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STATUS INCIDENT MODEL
 * 
 * Public-facing incident for status page.
 * Sanitized version of internal incidents - no sensitive details.
 * 
 * Bahasa yang digunakan:
 * - Non-teknis, mudah dipahami
 * - Tidak menyalahkan pihak tertentu
 * - Profesional dan menenangkan
 */
class StatusIncident extends Model
{
    use HasFactory;

    protected $table = 'status_incidents';

    // ==================== STATUS CONSTANTS ====================
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_IDENTIFIED = 'identified';
    public const STATUS_MONITORING = 'monitoring';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        self::STATUS_INVESTIGATING,
        self::STATUS_IDENTIFIED,
        self::STATUS_MONITORING,
        self::STATUS_RESOLVED,
    ];

    // Public-friendly status labels
    public const STATUS_LABELS = [
        self::STATUS_INVESTIGATING => 'Sedang Diperiksa',
        self::STATUS_IDENTIFIED => 'Penyebab Teridentifikasi',
        self::STATUS_MONITORING => 'Dalam Pemantauan',
        self::STATUS_RESOLVED => 'Sudah Teratasi',
    ];

    // ==================== IMPACT CONSTANTS ====================
    public const IMPACT_NONE = 'none';
    public const IMPACT_MINOR = 'minor';
    public const IMPACT_MAJOR = 'major';
    public const IMPACT_CRITICAL = 'critical';

    public const IMPACTS = [
        self::IMPACT_NONE,
        self::IMPACT_MINOR,
        self::IMPACT_MAJOR,
        self::IMPACT_CRITICAL,
    ];

    // Public-friendly impact labels
    public const IMPACT_LABELS = [
        self::IMPACT_NONE => 'Tidak Ada Dampak',
        self::IMPACT_MINOR => 'Dampak Minimal',
        self::IMPACT_MAJOR => 'Dampak Sebagian',
        self::IMPACT_CRITICAL => 'Dampak Signifikan',
    ];

    // Impact colors
    public const IMPACT_COLORS = [
        self::IMPACT_NONE => 'gray',
        self::IMPACT_MINOR => 'yellow',
        self::IMPACT_MAJOR => 'orange',
        self::IMPACT_CRITICAL => 'red',
    ];

    protected $fillable = [
        'public_id',
        'internal_incident_id',
        'title',
        'status',
        'impact',
        'summary',
        'affected_components',
        'is_published',
        'published_at',
        'started_at',
        'identified_at',
        'resolved_at',
        'created_by',
    ];

    protected $casts = [
        'affected_components' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'started_at' => 'datetime',
        'identified_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = self::generatePublicId();
            }
            if (empty($model->started_at)) {
                $model->started_at = now();
            }
        });
    }

    /**
     * Generate unique public ID
     */
    public static function generatePublicId(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;
        return sprintf('INC-%s-%03d', $date, $count);
    }

    // ==================== RELATIONSHIPS ====================

    public function updates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class, 'status_incident_id')
            ->orderByDesc('created_at');
    }

    public function publishedUpdates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class, 'status_incident_id')
            ->where('is_published', true)
            ->orderByDesc('created_at');
    }

    public function internalIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'internal_incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notifications(): HasMany
    {
        return $this->morphMany(CustomerNotification::class, 'notifiable');
    }

    // ==================== SCOPES ====================

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_INVESTIGATING,
            self::STATUS_IDENTIFIED,
            self::STATUS_MONITORING,
        ]);
    }

    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    // ==================== STATUS METHODS ====================

    /**
     * Publish incident to status page
     */
    public function publish(): bool
    {
        if ($this->is_published) {
            return true;
        }

        $this->update([
            'is_published' => true,
            'published_at' => now(),
        ]);

        return true;
    }

    /**
     * Update status with automatic update creation
     */
    public function updateStatus(string $newStatus, string $message, ?int $userId = null): StatusUpdate
    {
        $this->update(['status' => $newStatus]);

        if ($newStatus === self::STATUS_IDENTIFIED && !$this->identified_at) {
            $this->update(['identified_at' => now()]);
        }

        if ($newStatus === self::STATUS_RESOLVED && !$this->resolved_at) {
            $this->update(['resolved_at' => now()]);
        }

        return StatusUpdate::create([
            'status_incident_id' => $this->id,
            'status' => $newStatus,
            'message' => $message,
            'is_published' => $this->is_published,
            'published_at' => $this->is_published ? now() : null,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Resolve incident
     */
    public function resolve(string $message, ?int $userId = null): StatusUpdate
    {
        return $this->updateStatus(self::STATUS_RESOLVED, $message, $userId);
    }

    /**
     * Check if incident is active (not resolved)
     */
    public function isActive(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }

    /**
     * Check if incident is resolved
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Get duration in minutes
     */
    public function getDurationMinutes(): int
    {
        $endTime = $this->resolved_at ?? now();
        return $this->started_at->diffInMinutes($endTime);
    }

    /**
     * Get affected component models
     */
    public function getAffectedComponentModels(): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($this->affected_components)) {
            return collect();
        }

        return SystemComponent::whereIn('id', $this->affected_components)->get();
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Unknown';
    }

    public function getImpactLabelAttribute(): string
    {
        return self::IMPACT_LABELS[$this->impact] ?? 'Unknown';
    }

    public function getImpactColorAttribute(): string
    {
        return self::IMPACT_COLORS[$this->impact] ?? 'gray';
    }

    public function getDurationAttribute(): string
    {
        $minutes = $this->getDurationMinutes();
        
        if ($minutes < 60) {
            return "{$minutes} menit";
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        if ($remainingMinutes === 0) {
            return "{$hours} jam";
        }
        
        return "{$hours} jam {$remainingMinutes} menit";
    }

    // ==================== PUBLIC API ====================

    /**
     * Convert to public API format (sanitized)
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'impact' => $this->impact,
            'impact_label' => $this->impact_label,
            'impact_color' => $this->impact_color,
            'summary' => $this->summary,
            'affected_components' => $this->getAffectedComponentModels()
                ->map(fn($c) => ['slug' => $c->slug, 'name' => $c->name])
                ->toArray(),
            'started_at' => $this->started_at->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'duration' => $this->duration,
            'updates' => $this->publishedUpdates->map(fn($u) => $u->toPublicArray()),
        ];
    }

    /**
     * Create public incident from internal incident
     */
    public static function createFromInternalIncident(Incident $incident, array $overrides = []): self
    {
        // Map internal severity to public impact
        $impactMap = [
            Incident::SEVERITY_SEV1 => self::IMPACT_CRITICAL,
            Incident::SEVERITY_SEV2 => self::IMPACT_MAJOR,
            Incident::SEVERITY_SEV3 => self::IMPACT_MINOR,
            Incident::SEVERITY_SEV4 => self::IMPACT_NONE,
        ];

        // Sanitize title - remove technical jargon
        $title = self::sanitizeTitle($incident->title);

        return self::create(array_merge([
            'internal_incident_id' => $incident->id,
            'title' => $title,
            'status' => self::STATUS_INVESTIGATING,
            'impact' => $impactMap[$incident->severity] ?? self::IMPACT_MINOR,
            'started_at' => $incident->detected_at,
        ], $overrides));
    }

    /**
     * Sanitize title for public consumption
     * Remove technical terms, make it customer-friendly
     */
    public static function sanitizeTitle(string $title): string
    {
        // Replace technical terms with customer-friendly versions
        $replacements = [
            '/ban(ned)?/i' => 'pembatasan layanan',
            '/outage/i' => 'gangguan',
            '/failure/i' => 'kendala',
            '/error/i' => 'kendala',
            '/rate.?limit/i' => 'pembatasan kecepatan',
            '/queue/i' => 'antrian',
            '/timeout/i' => 'waktu tunggu',
            '/api/i' => 'koneksi',
            '/webhook/i' => 'notifikasi',
            '/database/i' => 'penyimpanan data',
            '/server/i' => 'sistem',
            '/crash/i' => 'gangguan sistem',
            '/exception/i' => 'kendala',
            '/bug/i' => 'kendala',
            '/502|503|504|500/i' => 'kendala akses',
        ];

        $sanitized = $title;
        foreach ($replacements as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, $sanitized);
        }

        return ucfirst(trim($sanitized));
    }
}
