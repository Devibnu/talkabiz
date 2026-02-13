<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PercakapanInbox extends Model
{
    use HasFactory;

    protected $table = 'percakapan_inbox';

    protected $fillable = [
        'klien_id',
        'no_whatsapp',
        'nama_customer',
        'foto_profil',
        'ditangani_oleh',
        'waktu_diambil',
        'terkunci',
        'status',
        'pesan_terakhir',
        'pengirim_terakhir',
        'waktu_pesan_terakhir',
        'total_pesan',
        'pesan_belum_dibaca',
        'label',
        'prioritas',
        'catatan',
    ];

    protected $casts = [
        'waktu_diambil' => 'datetime',
        'waktu_pesan_terakhir' => 'datetime',
        'terkunci' => 'boolean',
        'total_pesan' => 'integer',
        'pesan_belum_dibaca' => 'integer',
        'label' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function ditanganiOleh(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'ditangani_oleh');
    }

    /**
     * Alias untuk relasi ditanganiOleh untuk digunakan di controller
     */
    public function penanggungjawab(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'ditangani_oleh');
    }

    public function pesanInbox(): HasMany
    {
        return $this->hasMany(PesanInbox::class, 'percakapan_id');
    }

    /**
     * Relasi ke pesan terakhir untuk eager loading
     */
    public function pesanTerakhirRelasi(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PesanInbox::class, 'percakapan_id')
            ->orderByDesc('waktu_pesan')
            ->limit(1);
    }

    // ==================== ACCESSORS ====================

    /**
     * Nama tampilan customer
     */
    public function getNamaTampilanAttribute(): string
    {
        return $this->nama_customer ?: $this->no_whatsapp;
    }

    /**
     * Label status dengan warna
     */
    public function getLabelStatusAttribute(): array
    {
        return match ($this->status) {
            'baru' => ['label' => 'Baru', 'warna' => 'blue', 'ikon' => '🆕'],
            'belum_dibaca' => ['label' => 'Belum Dibaca', 'warna' => 'red', 'ikon' => '🔴'],
            'aktif' => ['label' => 'Aktif', 'warna' => 'green', 'ikon' => '💬'],
            'menunggu' => ['label' => 'Menunggu Balasan', 'warna' => 'yellow', 'ikon' => '⏳'],
            'selesai' => ['label' => 'Selesai', 'warna' => 'gray', 'ikon' => '✅'],
            default => ['label' => ucfirst($this->status), 'warna' => 'gray', 'ikon' => '⚪'],
        };
    }

    /**
     * Label prioritas
     */
    public function getLabelPrioritasAttribute(): array
    {
        return match ($this->prioritas) {
            'rendah' => ['label' => 'Rendah', 'warna' => 'gray'],
            'normal' => ['label' => 'Normal', 'warna' => 'blue'],
            'tinggi' => ['label' => 'Tinggi', 'warna' => 'orange'],
            'urgent' => ['label' => 'Urgent', 'warna' => 'red'],
            default => ['label' => 'Normal', 'warna' => 'blue'],
        };
    }

    /**
     * Cek apakah ada pesan belum dibaca
     */
    public function getAdaBelumDibacaAttribute(): bool
    {
        return $this->pesan_belum_dibaca > 0;
    }

    /**
     * Cek apakah sedang ditangani
     */
    public function getSedangDitanganiAttribute(): bool
    {
        return $this->terkunci && $this->ditangani_oleh !== null;
    }

    /**
     * Preview pesan terakhir
     */
    public function getPreviewPesanTerakhirAttribute(): string
    {
        $pesan = $this->pesan_terakhir ?? '';
        return strlen($pesan) > 50 ? substr($pesan, 0, 50) . '...' : $pesan;
    }

    // ==================== METHODS ====================

    /**
     * Ambil percakapan oleh sales
     */
    public function ambilOleh(int $penggunaId): bool
    {
        if ($this->terkunci && $this->ditangani_oleh !== $penggunaId) {
            return false; // Sudah diambil orang lain
        }

        $this->update([
            'ditangani_oleh' => $penggunaId,
            'waktu_diambil' => now(),
            'terkunci' => true,
            'status' => 'aktif',
        ]);

        return true;
    }

    /**
     * Lepas percakapan
     */
    public function lepas(): void
    {
        $this->update([
            'ditangani_oleh' => null,
            'waktu_diambil' => null,
            'terkunci' => false,
        ]);
    }

    /**
     * Selesaikan percakapan
     */
    public function selesaikan(): void
    {
        $this->update([
            'status' => 'selesai',
            'terkunci' => false,
        ]);
    }

    /**
     * Update pesan terakhir
     */
    public function updatePesanTerakhir(string $pesan, string $pengirim): void
    {
        $this->update([
            'pesan_terakhir' => $pesan,
            'pengirim_terakhir' => $pengirim,
            'waktu_pesan_terakhir' => now(),
            'total_pesan' => $this->total_pesan + 1,
        ]);

        // Jika dari customer, tambah belum dibaca
        if ($pengirim === 'customer') {
            $this->increment('pesan_belum_dibaca');
            
            if ($this->status === 'menunggu' || $this->status === 'selesai') {
                $this->update(['status' => 'belum_dibaca']);
            }
        }
    }

    /**
     * Tandai semua dibaca
     */
    public function tandaiSudahDibaca(): void
    {
        $this->update(['pesan_belum_dibaca' => 0]);
        
        $this->pesanInbox()
             ->where('arah', 'masuk')
             ->where('dibaca_sales', false)
             ->update([
                 'dibaca_sales' => true,
                 'waktu_dibaca_sales' => now(),
             ]);
    }

    // ==================== SCOPES ====================

    public function scopeBelumDitangani($query)
    {
        return $query->where('terkunci', false)->orWhereNull('ditangani_oleh');
    }

    public function scopeDitanganiOleh($query, int $penggunaId)
    {
        return $query->where('ditangani_oleh', $penggunaId);
    }

    public function scopeAdaPesanBaru($query)
    {
        return $query->where('pesan_belum_dibaca', '>', 0);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPrioritas($query, string $prioritas)
    {
        return $query->where('prioritas', $prioritas);
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeTerbaru($query)
    {
        return $query->orderByDesc('waktu_pesan_terakhir');
    }
}
