<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class InvoiceAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'event_type',
        'old_values',
        'new_values',
        'metadata',
        'user_id',
        'ip_address',
        'user_agent',
        'is_valid_change',
        'validation_notes'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'is_valid_change' => 'boolean'
    ];

    // ==================== RELATIONSHIPS ====================

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ==================== SCOPES ====================

    public function scopeEventType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    public function scopeValidChanges(Builder $query): Builder
    {
        return $query->where('is_valid_change', true);
    }

    public function scopeInvalidChanges(Builder $query): Builder
    {
        return $query->where('is_valid_change', false);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ==================== STATIC METHODS ====================

    public static function logEvent(
        int $invoiceId,
        string $eventType,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'invoice_id' => $invoiceId,
            'event_type' => $eventType,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'is_valid_change' => true
        ]);
    }

    public static function logInvalidChange(
        int $invoiceId,
        string $eventType,
        string $reason,
        ?array $attemptedChanges = null
    ): self {
        return self::create([
            'invoice_id' => $invoiceId,
            'event_type' => $eventType,
            'new_values' => $attemptedChanges,
            'validation_notes' => $reason,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'is_valid_change' => false
        ]);
    }
}