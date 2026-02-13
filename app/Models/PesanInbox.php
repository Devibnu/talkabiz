<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PesanInbox extends Model
{
    use HasFactory;

    protected $table = 'pesan_inbox';

    protected $fillable = [
        'percakapan_id',
        'klien_id',
        'pengguna_id',
        'pesan_id',
        'wa_message_id',
        'arah',
        'no_pengirim',
        'tipe',
        'isi_pesan',
        'media_url',
        'media_mime_type',
        'nama_file',
        'ukuran_file',
        'caption',
        'reply_to',
        'status',
        'dibaca_sales',
        'waktu_dibaca_sales',
        'waktu_pesan',
        'waktu_delivered',
        'waktu_dibaca',
        'error_message',
    ];

    protected $casts = [
        'dibaca_sales' => 'boolean',
        'waktu_dibaca_sales' => 'datetime',
        'waktu_pesan' => 'datetime',
        'waktu_delivered' => 'datetime',
        'waktu_dibaca' => 'datetime',
        'ukuran_file' => 'integer',
    ];

    // ==================== RELATIONSHIPS ====================

    public function percakapan(): BelongsTo
    {
        return $this->belongsTo(PercakapanInbox::class, 'percakapan_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id');
    }

    public function pesanUtama(): BelongsTo
    {
        return $this->belongsTo(Pesan::class, 'pesan_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(PesanInbox::class, 'reply_to');
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah pesan masuk (dari customer)
     */
    public function getIsMasukAttribute(): bool
    {
        return $this->arah === 'masuk';
    }

    /**
     * Cek apakah pesan keluar (dari sales)
     */
    public function getIsKeluarAttribute(): bool
    {
        return $this->arah === 'keluar';
    }

    /**
     * Cek apakah pesan sudah dibaca oleh sales
     */
    public function getSudahDibacaSalesAttribute(): bool
    {
        return $this->dibaca_sales === true;
    }

    /**
     * Label status
     */
    public function getLabelStatusAttribute(): array
    {
        return match ($this->status) {
            'pending' => ['label' => 'Pending', 'ikon' => '⏳'],
            'terkirim' => ['label' => 'Terkirim', 'ikon' => '✓'],
            'delivered' => ['label' => 'Sampai', 'ikon' => '✓✓'],
            'dibaca' => ['label' => 'Dibaca', 'ikon' => '👁️'],
            'gagal' => ['label' => 'Gagal', 'ikon' => '❌'],
            default => ['label' => ucfirst($this->status), 'ikon' => '⚪'],
        };
    }

    /**
     * Preview pesan
     */
    public function getPreviewAttribute(): string
    {
        if ($this->tipe !== 'teks') {
            $label = match ($this->tipe) {
                'gambar' => '📷 Gambar',
                'dokumen' => '📄 Dokumen',
                'audio' => '🎵 Audio',
                'video' => '🎬 Video',
                'lokasi' => '📍 Lokasi',
                'kontak' => '👤 Kontak',
                'sticker' => '🎨 Sticker',
                default => ucfirst($this->tipe),
            };

            return $this->caption ? "{$label}: {$this->caption}" : $label;
        }

        return $this->isi_pesan ?? '';
    }

    /**
     * Format ukuran file
     */
    public function getUkuranFileFormattedAttribute(): string
    {
        if (!$this->ukuran_file) {
            return '';
        }

        $bytes = $this->ukuran_file;
        
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        
        return $bytes . ' B';
    }

    /**
     * Waktu dalam format relatif
     */
    public function getWaktuRelatifAttribute(): string
    {
        return $this->waktu_pesan?->diffForHumans() ?? '';
    }

    // ==================== SCOPES ====================

    public function scopeMasuk($query)
    {
        return $query->where('arah', 'masuk');
    }

    public function scopeKeluar($query)
    {
        return $query->where('arah', 'keluar');
    }

    public function scopeBelumDibacaSales($query)
    {
        return $query->where('arah', 'masuk')->where('dibaca_sales', false);
    }

    public function scopeByPercakapan($query, int $percakapanId)
    {
        return $query->where('percakapan_id', $percakapanId);
    }

    public function scopeTerbaru($query)
    {
        return $query->orderByDesc('waktu_pesan');
    }

    public function scopeTerlama($query)
    {
        return $query->orderBy('waktu_pesan');
    }
}
