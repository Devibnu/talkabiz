<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DompetSaldo extends Model
{
    use HasFactory;

    protected $table = 'dompet_saldo';

    protected $fillable = [
        'klien_id',
        'saldo_tersedia',
        'saldo_tertahan',
        'batas_warning',
        'batas_minimum',
        'total_topup',
        'total_terpakai',
        'terakhir_topup',
        'terakhir_transaksi',
    ];

    protected $casts = [
        'saldo_tersedia' => 'integer',
        'saldo_tertahan' => 'integer',
        'batas_warning' => 'integer',
        'batas_minimum' => 'integer',
        'total_topup' => 'integer',
        'total_terpakai' => 'integer',
        'terakhir_topup' => 'datetime',
        'terakhir_transaksi' => 'datetime',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(TransaksiSaldo::class, 'dompet_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Total saldo (tersedia + tertahan)
     */
    public function getSaldoTotalAttribute(): int
    {
        return $this->saldo_tersedia + $this->saldo_tertahan;
    }

    /**
     * Status saldo: aman, menipis, habis
     */
    public function getStatusSaldoAttribute(): string
    {
        if ($this->saldo_tersedia <= 0) {
            return 'habis';
        }
        
        if ($this->saldo_tersedia < $this->batas_minimum) {
            return 'kritis';
        }
        
        if ($this->saldo_tersedia < $this->batas_warning) {
            return 'menipis';
        }
        
        return 'aman';
    }

    /**
     * Cek apakah saldo aman
     */
    public function getIsAmanAttribute(): bool
    {
        return $this->status_saldo === 'aman';
    }

    /**
     * Cek apakah saldo menipis
     */
    public function getIsMenipisAttribute(): bool
    {
        return $this->status_saldo === 'menipis';
    }

    /**
     * Cek apakah saldo kritis/habis
     */
    public function getIsKritisAttribute(): bool
    {
        return in_array($this->status_saldo, ['kritis', 'habis']);
    }

    // ==================== ANTI-BONCOS METHODS ====================

    /**
     * Cek apakah saldo cukup untuk nominal tertentu
     */
    public function cukupUntuk(int $nominal): bool
    {
        return $this->saldo_tersedia >= $nominal;
    }

    /**
     * Cek apakah bisa kirim campaign dengan jumlah target tertentu
     */
    public function bisaKirimCampaign(int $jumlahTarget, int $hargaPerPesan = 50): bool
    {
        $estimasiBiaya = $jumlahTarget * $hargaPerPesan;
        return $this->cukupUntuk($estimasiBiaya);
    }

    /**
     * Hitung maksimal pesan yang bisa dikirim dengan saldo saat ini
     */
    public function maksimalPesan(int $hargaPerPesan = 50): int
    {
        if ($hargaPerPesan <= 0) {
            return 0;
        }
        return (int) floor($this->saldo_tersedia / $hargaPerPesan);
    }

    /**
     * Estimasi hari saldo habis berdasarkan rata-rata pemakaian
     */
    public function estimasiHariHabis(int $rataRataHarian = 0): ?int
    {
        if ($rataRataHarian <= 0 || $this->saldo_tersedia <= 0) {
            return null;
        }
        return (int) ceil($this->saldo_tersedia / $rataRataHarian);
    }

    /**
     * Hold saldo untuk campaign
     * @throws \Exception jika saldo tidak cukup
     */
    public function holdSaldo(int $nominal): bool
    {
        if (!$this->cukupUntuk($nominal)) {
            throw new \Exception('Saldo tidak mencukupi untuk di-hold');
        }

        $this->saldo_tersedia -= $nominal;
        $this->saldo_tertahan += $nominal;
        $this->terakhir_transaksi = now();
        
        return $this->save();
    }

    /**
     * Release saldo yang di-hold (campaign batal/gagal)
     */
    public function releaseSaldo(int $nominal): bool
    {
        $releaseAmount = min($nominal, $this->saldo_tertahan);
        
        $this->saldo_tertahan -= $releaseAmount;
        $this->saldo_tersedia += $releaseAmount;
        $this->terakhir_transaksi = now();
        
        return $this->save();
    }

    /**
     * Potong saldo dari hold (campaign sukses)
     */
    public function potongDariHold(int $nominal): bool
    {
        $potongAmount = min($nominal, $this->saldo_tertahan);
        
        $this->saldo_tertahan -= $potongAmount;
        $this->total_terpakai += $potongAmount;
        $this->terakhir_transaksi = now();
        
        return $this->save();
    }

    /**
     * Tambah saldo (top up disetujui)
     */
    public function tambahSaldo(int $nominal): bool
    {
        $this->saldo_tersedia += $nominal;
        $this->total_topup += $nominal;
        $this->terakhir_topup = now();
        $this->terakhir_transaksi = now();
        
        return $this->save();
    }

    // ==================== SCOPES ====================

    public function scopeSaldoAman($query)
    {
        return $query->whereColumn('saldo_tersedia', '>=', 'batas_warning');
    }

    public function scopeSaldoMenipis($query)
    {
        return $query->whereColumn('saldo_tersedia', '<', 'batas_warning')
                     ->whereColumn('saldo_tersedia', '>=', 'batas_minimum');
    }

    public function scopeSaldoKritis($query)
    {
        return $query->whereColumn('saldo_tersedia', '<', 'batas_minimum');
    }
}
