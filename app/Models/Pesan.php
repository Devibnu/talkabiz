<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pesan extends Model
{
    protected $table = 'pesan';

    protected $fillable = [
        'klien_id',
        'kampanye_id',
        'wa_message_id',
        'no_pengirim',
        'no_penerima',
        'arah',
        'tipe',
        'isi_pesan',
        'media_url',
        'media_mime_type',
        'nama_file',
        'status',
        'waktu_kirim',
        'waktu_delivered',
        'waktu_dibaca',
        'error_message',
        'error_code',
        'biaya',
        'sudah_ditagih',
    ];

    protected $casts = [
        'waktu_kirim' => 'datetime',
        'waktu_delivered' => 'datetime',
        'waktu_dibaca' => 'datetime',
        'biaya' => 'integer',
        'sudah_ditagih' => 'boolean',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function kampanye(): BelongsTo
    {
        return $this->belongsTo(Kampanye::class, 'kampanye_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah pesan keluar
     */
    public function getIsKeluarAttribute(): bool
    {
        return $this->arah === 'keluar';
    }

    /**
     * Cek apakah pesan masuk
     */
    public function getIsMasukAttribute(): bool
    {
        return $this->arah === 'masuk';
    }

    /**
     * Label status
     */
    public function getLabelStatusAttribute(): array
    {
        return match ($this->status) {
            'pending' => ['label' => 'Pending', 'ikon' => '⏳'],
            'terkirim' => ['label' => 'Terkirim', 'ikon' => '✅'],
            'delivered' => ['label' => 'Sampai', 'ikon' => '✅✅'],
            'dibaca' => ['label' => 'Dibaca', 'ikon' => '👁️'],
            'gagal' => ['label' => 'Gagal', 'ikon' => '❌'],
            default => ['label' => ucfirst($this->status), 'ikon' => '⚪'],
        };
    }

    /**
     * Preview isi pesan (dipotong)
     */
    public function getPreviewAttribute(): string
    {
        if ($this->tipe !== 'teks') {
            return match ($this->tipe) {
                'gambar' => '📷 Gambar',
                'dokumen' => '📄 Dokumen',
                'audio' => '🎵 Audio',
                'video' => '🎬 Video',
                'lokasi' => '📍 Lokasi',
                'kontak' => '👤 Kontak',
                default => ucfirst($this->tipe),
            };
        }

        $pesan = $this->isi_pesan ?? '';
        return strlen($pesan) > 100 ? substr($pesan, 0, 100) . '...' : $pesan;
    }

    // ==================== SCOPES ====================

    public function scopeKeluar($query)
    {
        return $query->where('arah', 'keluar');
    }

    public function scopeMasuk($query)
    {
        return $query->where('arah', 'masuk');
    }

    public function scopeTerkirim($query)
    {
        return $query->whereIn('status', ['terkirim', 'delivered', 'dibaca']);
    }

    public function scopeGagal($query)
    {
        return $query->where('status', 'gagal');
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeByKampanye($query, int $kampanyeId)
    {
        return $query->where('kampanye_id', $kampanyeId);
    }

    public function scopeBelumDitagih($query)
    {
        return $query->where('sudah_ditagih', false);
    }
}
