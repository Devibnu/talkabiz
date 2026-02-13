<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * TicketAttachment Model
 * 
 * Lampiran file untuk tiket support.
 */
class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';

    const UPLOADED_BY_CLIENT = 'client';
    const UPLOADED_BY_AGENT = 'agent';

    protected $fillable = [
        'ticket_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'uploaded_by',
        'uploader_id',
    ];

    protected $appends = [
        'file_url',
        'file_size_formatted',
    ];

    // ==================== RELATIONSHIPS ====================

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function uploader(): BelongsTo
    {
        if ($this->uploaded_by === self::UPLOADED_BY_CLIENT) {
            return $this->belongsTo(Klien::class, 'uploader_id');
        }
        return $this->belongsTo(User::class, 'uploader_id');
    }

    // ==================== SCOPES ====================

    public function scopeForTicket(Builder $query, int $ticketId): Builder
    {
        return $query->where('ticket_id', $ticketId);
    }

    public function scopeByClient(Builder $query): Builder
    {
        return $query->where('uploaded_by', self::UPLOADED_BY_CLIENT);
    }

    public function scopeByAgent(Builder $query): Builder
    {
        return $query->where('uploaded_by', self::UPLOADED_BY_AGENT);
    }

    // ==================== ACCESSORS ====================

    public function getFileUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    public function getFileSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;
        
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        
        if ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        }
        
        return round($bytes / 1048576, 2) . ' MB';
    }

    // ==================== HELPERS ====================

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Check if file is a document
     */
    public function isDocument(): bool
    {
        $documentTypes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];
        
        return in_array($this->mime_type, $documentTypes);
    }

    /**
     * Delete the file from storage
     */
    public function deleteFile(): bool
    {
        return Storage::disk('public')->delete($this->file_path);
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($attachment) {
            $attachment->deleteFile();
        });
    }
}
