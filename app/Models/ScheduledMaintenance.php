<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCHEDULED MAINTENANCE MODEL
 * 
 * Represents planned maintenance windows.
 * Proactive communication to prevent support tickets.
 */
class ScheduledMaintenance extends Model
{
    use HasFactory;

    protected $table = 'scheduled_maintenances';

    // ==================== STATUS CONSTANTS ====================
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public const STATUS_LABELS = [
        self::STATUS_SCHEDULED => 'Dijadwalkan',
        self::STATUS_IN_PROGRESS => 'Sedang Berlangsung',
        self::STATUS_COMPLETED => 'Selesai',
        self::STATUS_CANCELLED => 'Dibatalkan',
    ];

    // ==================== IMPACT CONSTANTS ====================
    public const IMPACT_NONE = 'none';
    public const IMPACT_MINOR = 'minor';
    public const IMPACT_MAJOR = 'major';

    public const IMPACT_LABELS = [
        self::IMPACT_NONE => 'Tidak Ada Dampak',
        self::IMPACT_MINOR => 'Dampak Minimal',
        self::IMPACT_MAJOR => 'Layanan Tidak Tersedia',
    ];

    protected $fillable = [
        'public_id',
        'title',
        'description',
        'affected_components',
        'impact',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'status',
        'completion_message',
        'is_published',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'affected_components' => 'array',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = self::generatePublicId();
            }
        });
    }

    public static function generatePublicId(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;
        return sprintf('MNT-%s-%03d', $date, $count);
    }

    // ==================== RELATIONSHIPS ====================

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

    public function scopeUpcoming($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_start', '>', now());
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeActiveOrUpcoming($query)
    {
        return $query->whereIn('status', [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS])
            ->where(function ($q) {
                $q->where('scheduled_start', '>', now())
                  ->orWhere('status', self::STATUS_IN_PROGRESS);
            });
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('scheduled_start', '>=', now()->subDays($days));
    }

    // ==================== STATUS METHODS ====================

    /**
     * Start maintenance
     */
    public function start(): bool
    {
        if ($this->status !== self::STATUS_SCHEDULED) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'actual_start' => now(),
        ]);

        // Update affected components
        $this->updateComponentStatuses(SystemComponent::STATUS_MAINTENANCE);

        return true;
    }

    /**
     * Complete maintenance
     */
    public function complete(?string $message = null): bool
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'actual_end' => now(),
            'completion_message' => $message ?? 'Pemeliharaan selesai. Semua layanan kembali normal.',
        ]);

        // Restore affected components
        $this->updateComponentStatuses(SystemComponent::STATUS_OPERATIONAL);

        return true;
    }

    /**
     * Cancel maintenance
     */
    public function cancel(?string $reason = null): bool
    {
        if (!in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS])) {
            return false;
        }

        $wasInProgress = $this->status === self::STATUS_IN_PROGRESS;

        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completion_message' => $reason ?? 'Pemeliharaan dibatalkan.',
        ]);

        // Restore components if was in progress
        if ($wasInProgress) {
            $this->updateComponentStatuses(SystemComponent::STATUS_OPERATIONAL);
        }

        return true;
    }

    /**
     * Update affected component statuses
     */
    private function updateComponentStatuses(string $status): void
    {
        if (empty($this->affected_components)) {
            return;
        }

        $components = SystemComponent::whereIn('id', $this->affected_components)->get();
        foreach ($components as $component) {
            $component->updateStatus(
                $status,
                'maintenance',
                $this->id,
                $status === SystemComponent::STATUS_MAINTENANCE
                    ? "Maintenance: {$this->title}"
                    : "Maintenance completed: {$this->title}"
            );
        }
    }

    /**
     * Publish maintenance notice
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

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? 'Unknown';
    }

    public function getImpactLabelAttribute(): string
    {
        return self::IMPACT_LABELS[$this->impact] ?? 'Unknown';
    }

    public function getScheduledDurationAttribute(): string
    {
        $minutes = $this->scheduled_start->diffInMinutes($this->scheduled_end);
        
        if ($minutes < 60) {
            return "{$minutes} menit";
        }
        
        $hours = floor($minutes / 60);
        return "{$hours} jam";
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function getIsUpcomingAttribute(): bool
    {
        return $this->status === self::STATUS_SCHEDULED && $this->scheduled_start > now();
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

    // ==================== PUBLIC API ====================

    public function toPublicArray(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'impact' => $this->impact,
            'impact_label' => $this->impact_label,
            'affected_components' => $this->getAffectedComponentModels()
                ->map(fn($c) => ['slug' => $c->slug, 'name' => $c->name])
                ->toArray(),
            'scheduled_start' => $this->scheduled_start->toIso8601String(),
            'scheduled_end' => $this->scheduled_end->toIso8601String(),
            'scheduled_duration' => $this->scheduled_duration,
            'actual_start' => $this->actual_start?->toIso8601String(),
            'actual_end' => $this->actual_end?->toIso8601String(),
            'completion_message' => $this->completion_message,
        ];
    }

    // ==================== NOTIFICATION TEMPLATES ====================

    /**
     * Generate scheduled notice
     */
    public function getScheduledNotice(): string
    {
        $components = $this->getAffectedComponentModels()->pluck('name')->join(', ');
        $date = $this->scheduled_start->translatedFormat('l, d F Y');
        $startTime = $this->scheduled_start->format('H:i');
        $endTime = $this->scheduled_end->format('H:i');

        return "📅 Pemeliharaan Terjadwal\n\n" .
               "{$this->title}\n\n" .
               "📆 Tanggal: {$date}\n" .
               "🕐 Waktu: {$startTime} - {$endTime} WIB\n" .
               "⏱️ Durasi: {$this->scheduled_duration}\n" .
               "📍 Layanan terdampak: {$components}\n\n" .
               "ℹ️ {$this->description}\n\n" .
               "Kami akan memberikan update saat pemeliharaan dimulai dan selesai.";
    }

    /**
     * Generate started notice
     */
    public function getStartedNotice(): string
    {
        return "🔧 Pemeliharaan Dimulai\n\n" .
               "{$this->title} sedang berlangsung.\n\n" .
               "Perkiraan selesai: {$this->scheduled_end->format('H:i')} WIB\n\n" .
               "Kami akan memberitahu Anda segera setelah pemeliharaan selesai.";
    }

    /**
     * Generate completed notice
     */
    public function getCompletedNotice(): string
    {
        return "✅ Pemeliharaan Selesai\n\n" .
               "{$this->title} telah selesai.\n\n" .
               ($this->completion_message ?? 'Semua layanan kembali berjalan normal.') . "\n\n" .
               "Terima kasih atas kesabaran Anda.";
    }
}
