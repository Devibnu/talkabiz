<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TargetKampanye extends Model
{
    use HasFactory;

    protected $table = 'target_kampanye';

    protected $fillable = [
        'kampanye_id',
        'klien_id',
        'no_whatsapp',
        'nama',
        'data_variabel',
        'payload_kirim', // payload yang dikirim ke provider
        'status',
        'message_id', // renamed from wa_message_id
        'waktu_kirim',
        'waktu_delivered',
        'waktu_dibaca',
        'catatan', // renamed from error_message
        'urutan',
    ];

    protected $casts = [
        'data_variabel' => 'array',
        'payload_kirim' => 'array',
        'waktu_kirim' => 'datetime',
        'waktu_delivered' => 'datetime',
        'waktu_dibaca' => 'datetime',
        'urutan' => 'integer',
    ];

    // ==================== RELATIONSHIPS ====================

    public function kampanye(): BelongsTo
    {
        return $this->belongsTo(Kampanye::class, 'kampanye_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Alias nomor_telepon untuk backward compatibility
     */
    public function getNomorTeleponAttribute(): ?string
    {
        return $this->no_whatsapp;
    }

    /**
     * Format nomor WA (tampilan)
     */
    public function getNoWhatsappFormattedAttribute(): string
    {
        $no = $this->no_whatsapp;
        
        // Jika dimulai dengan 62, format sebagai Indonesia
        if (str_starts_with($no, '62')) {
            $no = substr($no, 2);
            return '+62 ' . chunk_split($no, 4, '-');
        }
        
        return $no;
    }

    /**
     * Nama tampilan (nama atau nomor)
     */
    public function getNamaTampilanAttribute(): string
    {
        return $this->nama ?: $this->no_whatsapp_formatted;
    }

    /**
     * Label status
     */
    public function getLabelStatusAttribute(): array
    {
        return match ($this->status) {
            'pending' => ['label' => 'Pending', 'warna' => 'gray', 'ikon' => '⏳'],
            'antrian' => ['label' => 'Dalam Antrian', 'warna' => 'blue', 'ikon' => '📤'],
            'terkirim' => ['label' => 'Terkirim', 'warna' => 'green', 'ikon' => '✅'],
            'delivered' => ['label' => 'Sampai', 'warna' => 'green', 'ikon' => '✅✅'],
            'dibaca' => ['label' => 'Dibaca', 'warna' => 'blue', 'ikon' => '👁️'],
            'gagal' => ['label' => 'Gagal', 'warna' => 'red', 'ikon' => '❌'],
            'invalid' => ['label' => 'Nomor Invalid', 'warna' => 'red', 'ikon' => '🚫'],
            default => ['label' => ucfirst($this->status), 'warna' => 'gray', 'ikon' => '⚪'],
        };
    }

    /**
     * Cek apakah sukses (terkirim/delivered/dibaca)
     */
    public function getIsSuksesAttribute(): bool
    {
        return in_array($this->status, ['terkirim', 'delivered', 'dibaca']);
    }

    /**
     * Cek apakah gagal
     */
    public function getIsGagalAttribute(): bool
    {
        return in_array($this->status, ['gagal', 'invalid']);
    }

    // ==================== METHODS ====================

    /**
     * Ganti variabel dalam pesan dengan data target
     */
    public function prosesVariabel(string $template): string
    {
        $pesan = $template;
        $data = $this->data_variabel ?? [];
        
        // Default variabel
        $data['nama'] = $data['nama'] ?? $this->nama ?? '';
        
        foreach ($data as $key => $value) {
            $pesan = str_replace("{{{$key}}}", $value, $pesan);
        }
        
        return $pesan;
    }

    /**
     * Tandai sebagai terkirim
     */
    public function tandaiTerkirim(?string $waMessageId = null): void
    {
        $this->update([
            'status' => 'terkirim',
            'wa_message_id' => $waMessageId,
            'waktu_kirim' => now(),
        ]);
    }

    /**
     * Tandai sebagai delivered
     */
    public function tandaiDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'waktu_delivered' => now(),
        ]);
    }

    /**
     * Tandai sebagai dibaca
     */
    public function tandaiDibaca(): void
    {
        $this->update([
            'status' => 'dibaca',
            'waktu_dibaca' => now(),
        ]);
    }

    /**
     * Tandai sebagai gagal
     */
    public function tandaiGagal(string $errorMessage): void
    {
        $this->update([
            'status' => 'gagal',
            'error_message' => $errorMessage,
        ]);
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeTerkirim($query)
    {
        return $query->whereIn('status', ['terkirim', 'delivered', 'dibaca']);
    }

    public function scopeGagal($query)
    {
        return $query->whereIn('status', ['gagal', 'invalid']);
    }

    public function scopeByKampanye($query, int $kampanyeId)
    {
        return $query->where('kampanye_id', $kampanyeId);
    }

    public function scopeUrutanKirim($query)
    {
        return $query->orderBy('urutan');
    }
}
