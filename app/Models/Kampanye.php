<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Kampanye extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'kampanye';

    protected $fillable = [
        'kode_kampanye',
        'klien_id',
        'dibuat_oleh',
        'nama_kampanye',
        'deskripsi',
        'tipe_pesan',
        'template_pesan', // renamed from isi_pesan
        'media_url',
        'template_id',
        'template_pesan_id', // FK ke template_pesan (approved template)
        'template_snapshot', // snapshot template saat campaign dibuat
        'variabel_pesan',
        'catatan', // untuk jeda/berhenti
        'total_target',
        'sumber_target',
        'tipe_jadwal',
        'jadwal_kirim',
        'waktu_mulai',
        'waktu_selesai',
        'status',
        'terkirim',
        'gagal',
        'pending',
        'dibaca',
        'harga_per_pesan',
        'estimasi_biaya',
        'saldo_dihold',
        'biaya_aktual',
    ];

    protected $casts = [
        'variabel_pesan' => 'array',
        'template_snapshot' => 'array',
        'jadwal_kirim' => 'datetime',
        'waktu_mulai' => 'datetime',
        'waktu_selesai' => 'datetime',
        'total_target' => 'integer',
        'terkirim' => 'integer',
        'gagal' => 'integer',
        'pending' => 'integer',
        'dibaca' => 'integer',
        'harga_per_pesan' => 'integer',
        'estimasi_biaya' => 'integer',
        'saldo_dihold' => 'integer',
        'biaya_aktual' => 'integer',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($kampanye) {
            if (empty($kampanye->kode_kampanye)) {
                $kampanye->kode_kampanye = self::generateKode();
            }
            
            // Hitung estimasi biaya
            $kampanye->estimasi_biaya = $kampanye->total_target * $kampanye->harga_per_pesan;
        });
    }

    public static function generateKode(): string
    {
        $tanggal = now()->format('Ymd');
        $random = strtoupper(Str::random(5));
        return "CMP-{$tanggal}-{$random}";
    }

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'dibuat_oleh');
    }

    public function target(): HasMany
    {
        return $this->hasMany(TargetKampanye::class, 'kampanye_id');
    }

    public function templatePesan(): BelongsTo
    {
        return $this->belongsTo(TemplatePesan::class, 'template_pesan_id');
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(Pesan::class, 'kampanye_id');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(TransaksiSaldo::class, 'kampanye_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Persentase progress
     */
    public function getProgressPersenAttribute(): float
    {
        if ($this->total_target <= 0) {
            return 0;
        }
        return round(($this->terkirim / $this->total_target) * 100, 1);
    }

    /**
     * Sisa target yang belum terkirim
     */
    public function getSisaTargetAttribute(): int
    {
        return max(0, $this->total_target - $this->terkirim - $this->gagal);
    }

    /**
     * Biaya untuk sisa target
     */
    public function getBiayaSisaAttribute(): int
    {
        return $this->sisa_target * $this->harga_per_pesan;
    }

    /**
     * Label status dengan warna
     */
    public function getLabelStatusAttribute(): array
    {
        return match ($this->status) {
            'draft' => ['label' => 'Draft', 'warna' => 'gray'],
            'menunggu' => ['label' => 'Menunggu Jadwal', 'warna' => 'blue'],
            'validasi' => ['label' => 'Validasi', 'warna' => 'yellow'],
            'berjalan' => ['label' => 'Sedang Berjalan', 'warna' => 'green'],
            'pause' => ['label' => 'Dihentikan', 'warna' => 'orange'],
            'selesai' => ['label' => 'Selesai', 'warna' => 'green'],
            'gagal' => ['label' => 'Gagal', 'warna' => 'red'],
            'dibatalkan' => ['label' => 'Dibatalkan', 'warna' => 'red'],
            default => ['label' => ucfirst($this->status), 'warna' => 'gray'],
        };
    }

    /**
     * Cek apakah campaign bisa diedit
     */
    public function getBisaDieditAttribute(): bool
    {
        return in_array($this->status, ['draft']);
    }

    /**
     * Cek apakah campaign bisa dijalankan
     */
    public function getBisaDijalankanAttribute(): bool
    {
        return in_array($this->status, ['draft', 'menunggu', 'pause']);
    }

    /**
     * Cek apakah campaign sedang aktif
     */
    public function getIsAktifAttribute(): bool
    {
        return in_array($this->status, ['validasi', 'berjalan']);
    }

    /**
     * Cek apakah campaign sudah selesai
     */
    public function getIsSelesaiAttribute(): bool
    {
        return in_array($this->status, ['selesai', 'gagal', 'dibatalkan']);
    }

    // ==================== ANTI-BONCOS METHODS ====================

    /**
     * Validasi saldo sebelum mulai campaign
     */
    public function validasiSaldo(): array
    {
        $dompet = $this->klien->dompet;
        
        if (!$dompet) {
            return [
                'valid' => false,
                'pesan' => 'Dompet tidak ditemukan',
            ];
        }

        if (!$dompet->cukupUntuk($this->estimasi_biaya)) {
            $kekurangan = $this->estimasi_biaya - $dompet->saldo_tersedia;
            $maksimalPesan = $dompet->maksimalPesan($this->harga_per_pesan);
            
            return [
                'valid' => false,
                'pesan' => "Saldo tidak cukup. Kekurangan: Rp " . number_format($kekurangan, 0, ',', '.'),
                'saldo_tersedia' => $dompet->saldo_tersedia,
                'estimasi_biaya' => $this->estimasi_biaya,
                'kekurangan' => $kekurangan,
                'maksimal_target' => $maksimalPesan,
            ];
        }

        return [
            'valid' => true,
            'pesan' => 'Saldo mencukupi',
            'saldo_tersedia' => $dompet->saldo_tersedia,
            'estimasi_biaya' => $this->estimasi_biaya,
            'sisa_setelah_kirim' => $dompet->saldo_tersedia - $this->estimasi_biaya,
        ];
    }

    /**
     * Hold saldo untuk campaign ini
     */
    public function holdSaldo(): bool
    {
        $validasi = $this->validasiSaldo();
        
        if (!$validasi['valid']) {
            throw new \Exception($validasi['pesan']);
        }

        $dompet = $this->klien->dompet;
        $dompet->holdSaldo($this->estimasi_biaya);
        
        $this->update([
            'saldo_dihold' => $this->estimasi_biaya,
            'status' => 'berjalan',
            'waktu_mulai' => now(),
        ]);

        return true;
    }

    /**
     * Update progress dan potong saldo
     */
    public function updateProgress(int $terkirimBaru, int $gagalBaru): void
    {
        $this->terkirim += $terkirimBaru;
        $this->gagal += $gagalBaru;
        $this->pending = $this->total_target - $this->terkirim - $this->gagal;
        $this->biaya_aktual = $this->terkirim * $this->harga_per_pesan;
        
        // Cek apakah selesai
        if ($this->pending <= 0) {
            $this->selesaikanCampaign();
        }
        
        $this->save();
    }

    /**
     * Selesaikan campaign dan proses saldo
     */
    public function selesaikanCampaign(): void
    {
        $dompet = $this->klien->dompet;
        
        // Potong saldo sesuai yang terkirim
        $biayaAktual = $this->terkirim * $this->harga_per_pesan;
        $dompet->potongDariHold($biayaAktual);
        
        // Release sisa hold (untuk yang gagal)
        $sisaHold = $this->saldo_dihold - $biayaAktual;
        if ($sisaHold > 0) {
            $dompet->releaseSaldo($sisaHold);
        }
        
        $this->update([
            'status' => 'selesai',
            'waktu_selesai' => now(),
            'biaya_aktual' => $biayaAktual,
            'saldo_dihold' => 0,
        ]);
    }

    /**
     * Pause campaign karena saldo habis
     */
    public function pauseKarenaSaldoHabis(): void
    {
        $this->update([
            'status' => 'pause',
            'alasan_berhenti' => 'Saldo habis. Silakan top up untuk melanjutkan.',
        ]);
    }

    /**
     * Batalkan campaign
     */
    public function batalkan(string $alasan = 'Dibatalkan oleh user'): void
    {
        $dompet = $this->klien->dompet;
        
        // Release semua hold
        if ($this->saldo_dihold > 0) {
            // Potong yang sudah terkirim
            $biayaTerkirim = $this->terkirim * $this->harga_per_pesan;
            $dompet->potongDariHold($biayaTerkirim);
            
            // Release sisanya
            $sisaHold = $this->saldo_dihold - $biayaTerkirim;
            if ($sisaHold > 0) {
                $dompet->releaseSaldo($sisaHold);
            }
        }
        
        $this->update([
            'status' => 'dibatalkan',
            'waktu_selesai' => now(),
            'biaya_aktual' => $this->terkirim * $this->harga_per_pesan,
            'saldo_dihold' => 0,
            'alasan_berhenti' => $alasan,
        ]);
    }

    // ==================== SCOPES ====================

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeBerjalan($query)
    {
        return $query->where('status', 'berjalan');
    }

    public function scopeSelesai($query)
    {
        return $query->where('status', 'selesai');
    }

    public function scopeAktif($query)
    {
        return $query->whereIn('status', ['validasi', 'berjalan']);
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }
}
