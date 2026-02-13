<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'klien_id',
        'phone_number',
        'name',
        'email',
        'tags',
        'custom_fields',
        'opted_in',
        'opted_in_at',
        'opted_out_at',
        'opt_in_source',
    ];

    protected $casts = [
        'tags' => 'array',
        'custom_fields' => 'array',
        'opted_in' => 'boolean',
        'opted_in_at' => 'datetime',
        'opted_out_at' => 'datetime',
    ];

    // Opt-in sources
    const SOURCE_WEBSITE = 'website';
    const SOURCE_IMPORT = 'import';
    const SOURCE_MANUAL = 'manual';
    const SOURCE_API = 'api';

    /**
     * Get the klien that owns this contact
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    /**
     * Get campaign recipients for this contact
     */
    public function campaignRecipients(): HasMany
    {
        return $this->hasMany(WhatsappCampaignRecipient::class, 'contact_id');
    }

    /**
     * Scope for opted-in contacts
     */
    public function scopeOptedIn($query)
    {
        return $query->where('opted_in', true);
    }

    /**
     * Scope by tag
     */
    public function scopeWithTag($query, string $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Opt-in this contact
     */
    public function optIn(string $source = self::SOURCE_MANUAL): void
    {
        $this->update([
            'opted_in' => true,
            'opted_in_at' => now(),
            'opted_out_at' => null,
            'opt_in_source' => $source,
        ]);
    }

    /**
     * Opt-out this contact
     */
    public function optOut(): void
    {
        $this->update([
            'opted_in' => false,
            'opted_out_at' => now(),
        ]);
    }

    /**
     * Normalize phone number (ensure +62 format)
     */
    public static function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }
        
        if (!str_starts_with($phone, '62')) {
            $phone = '62' . $phone;
        }
        
        return $phone;
    }

    /**
     * Set phone number with normalization
     */
    public function setPhoneNumberAttribute($value): void
    {
        $this->attributes['phone_number'] = self::normalizePhoneNumber($value);
    }

    /**
     * Get formatted phone number
     */
    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->phone_number;
        return '+' . substr($phone, 0, 2) . ' ' . substr($phone, 2, 3) . '-' . substr($phone, 5, 4) . '-' . substr($phone, 9);
    }
}
