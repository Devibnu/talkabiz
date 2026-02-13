<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Kontak extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kontak';

    // Source constants
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_API = 'api';
    public const SOURCE_FORM = 'form';

    protected $fillable = [
        'klien_id',
        'dibuat_oleh',
        'nama',
        'no_telepon',
        'email',
        'tags',
        'source',
        'catatan',
        'data_tambahan',
    ];

    protected $casts = [
        'tags' => 'array',
        'data_tambahan' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function pembuatUser(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'dibuat_oleh');
    }

    // ==================== SCOPES ====================

    public function scopeByKlien($query, $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    public function scopeWithTag($query, $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    // ==================== ACCESSORS ====================

    public function getFormattedPhoneAttribute(): string
    {
        $phone = preg_replace('/[^0-9]/', '', $this->no_telepon);
        
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        
        return $phone;
    }

    public function getInisialAttribute(): string
    {
        $words = explode(' ', $this->nama);
        $initials = '';
        
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
        
        return $initials ?: 'K';
    }

    // ==================== METHODS ====================

    public function addTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        if (!in_array($tag, $tags)) {
            $tags[] = $tag;
            $this->tags = $tags;
            $this->save();
        }
    }

    public function removeTag(string $tag): void
    {
        $tags = $this->tags ?? [];
        $this->tags = array_values(array_filter($tags, fn($t) => $t !== $tag));
        $this->save();
    }
}
