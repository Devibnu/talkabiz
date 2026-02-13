<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STATUS UPDATE MODEL
 * 
 * IMMUTABLE - Append-only updates for status incidents.
 * Each update represents a point-in-time communication to customers.
 * 
 * Prinsip:
 * - Tidak bisa di-edit setelah publish
 * - Bahasa profesional & menenangkan
 * - Tidak menyebut detail teknis sensitif
 */
class StatusUpdate extends Model
{
    public $timestamps = false;

    protected $table = 'status_updates';

    // Disable updates - append only
    protected static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            // Only allow publishing unpublished updates
            if ($model->isDirty(['message', 'status'])) {
                throw new \RuntimeException('StatusUpdate records are immutable once created');
            }
        });

        static::deleting(function ($model) {
            throw new \RuntimeException('StatusUpdate records cannot be deleted');
        });
    }

    protected $fillable = [
        'status_incident_id',
        'status',
        'message',
        'is_published',
        'published_at',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'status_incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    // ==================== METHODS ====================

    /**
     * Publish update
     */
    public function publish(): bool
    {
        if ($this->is_published) {
            return true;
        }

        $this->is_published = true;
        $this->published_at = now();
        return $this->save();
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return StatusIncident::STATUS_LABELS[$this->status] ?? 'Unknown';
    }

    // ==================== PUBLIC API ====================

    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'status_label' => $this->status_label,
            'message' => $this->message,
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }

    // ==================== MESSAGE TEMPLATES ====================

    /**
     * Generate investigating message template
     */
    public static function investigatingTemplate(string $component, string $impact): string
    {
        return "Kami sedang memeriksa kendala pada layanan {$component}. " .
               "Beberapa pengguna mungkin mengalami {$impact}. " .
               "Tim kami sedang bekerja untuk mengidentifikasi penyebabnya. " .
               "Kami akan memberikan update dalam 30 menit ke depan.";
    }

    /**
     * Generate identified message template
     */
    public static function identifiedTemplate(string $cause, string $action): string
    {
        return "Penyebab kendala telah teridentifikasi: {$cause}. " .
               "Tim kami sedang {$action}. " .
               "Kami memperkirakan layanan akan kembali normal dalam waktu dekat.";
    }

    /**
     * Generate monitoring message template
     */
    public static function monitoringTemplate(): string
    {
        return "Perbaikan telah diterapkan dan layanan mulai pulih. " .
               "Kami sedang memantau untuk memastikan semua berjalan normal. " .
               "Pengguna seharusnya sudah dapat mengakses layanan seperti biasa.";
    }

    /**
     * Generate resolved message template
     */
    public static function resolvedTemplate(string $summary): string
    {
        return "Kendala telah teratasi. {$summary} " .
               "Layanan kembali berjalan normal. " .
               "Kami mohon maaf atas ketidaknyamanan yang terjadi. " .
               "Terima kasih atas kesabaran Anda.";
    }
}
