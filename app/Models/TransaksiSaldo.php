<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TransaksiSaldo extends Model
{
    protected $table = 'transaksi_saldo';

    protected $fillable = [
        'kode_transaksi',
        'dompet_id',
        'klien_id',
        'kampanye_id',
        'pengguna_id',
        'jenis',
        'nominal',
        'saldo_sebelum',
        'saldo_sesudah',
        'keterangan',
        'referensi',
        'midtrans_snap_token',
        'midtrans_response',
        'status_topup',
        'metode_bayar',
        'bank_tujuan',
        'bukti_transfer',
        'batas_bayar',
        'diproses_oleh',
        'waktu_diproses',
        'catatan_admin',
    ];

    protected $casts = [
        'nominal' => 'integer',
        'saldo_sebelum' => 'integer',
        'saldo_sesudah' => 'integer',
        'batas_bayar' => 'datetime',
        'waktu_diproses' => 'datetime',
        'midtrans_response' => 'array',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($transaksi) {
            if (empty($transaksi->kode_transaksi)) {
                $transaksi->kode_transaksi = self::generateKode();
            }
        });
    }

    /**
     * Generate kode transaksi unik
     */
    public static function generateKode(): string
    {
        $tanggal = now()->format('Ymd');
        $random = strtoupper(Str::random(5));
        return "TRX-{$tanggal}-{$random}";
    }

    // ==================== RELATIONSHIPS ====================

    public function dompet(): BelongsTo
    {
        return $this->belongsTo(DompetSaldo::class, 'dompet_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    public function kampanye(): BelongsTo
    {
        return $this->belongsTo(Kampanye::class, 'kampanye_id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id');
    }

    public function diprosesOleh(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'diproses_oleh');
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah transaksi masuk (positif)
     */
    public function getIsMasukAttribute(): bool
    {
        return $this->nominal > 0;
    }

    /**
     * Cek apakah transaksi keluar (negatif)
     */
    public function getIsKeluarAttribute(): bool
    {
        return $this->nominal < 0;
    }

    /**
     * Nominal absolut (tanpa minus)
     */
    public function getNominalAbsolutAttribute(): int
    {
        return abs($this->nominal);
    }

    /**
     * Format nominal dengan Rupiah
     */
    public function getNominalFormattedAttribute(): string
    {
        $prefix = $this->is_masuk ? '+' : '';
        return $prefix . 'Rp ' . number_format($this->nominal, 0, ',', '.');
    }

    /**
     * Label jenis transaksi
     */
    public function getLabelJenisAttribute(): string
    {
        return match ($this->jenis) {
            'topup' => 'Top Up',
            'potong' => 'Pemotongan Campaign',
            'hold' => 'Ditahan (Hold)',
            'release' => 'Dilepas (Release)',
            'refund' => 'Pengembalian',
            'koreksi' => 'Koreksi Admin',
            default => ucfirst($this->jenis),
        };
    }

    /**
     * Ikon jenis transaksi
     */
    public function getIkonJenisAttribute(): string
    {
        return match ($this->jenis) {
            'topup' => '🟢',
            'potong' => '🔻',
            'hold' => '🔒',
            'release' => '🔓',
            'refund' => '🔵',
            'koreksi' => '🟡',
            default => '⚪',
        };
    }

    /**
     * Cek apakah top up pending
     */
    public function getIsTopupPendingAttribute(): bool
    {
        return $this->jenis === 'topup' && $this->status_topup === 'pending';
    }

    /**
     * Cek apakah top up sudah kadaluarsa
     */
    public function getIsTopupKadaluarsaAttribute(): bool
    {
        if ($this->jenis !== 'topup' || empty($this->batas_bayar)) {
            return false;
        }
        return now()->isAfter($this->batas_bayar) && $this->status_topup === 'pending';
    }

    // ==================== SCOPES ====================

    public function scopeTopup($query)
    {
        return $query->where('jenis', 'topup');
    }

    public function scopePotong($query)
    {
        return $query->where('jenis', 'potong');
    }

    public function scopeHold($query)
    {
        return $query->where('jenis', 'hold');
    }

    public function scopeRefund($query)
    {
        return $query->where('jenis', 'refund');
    }

    public function scopePending($query)
    {
        return $query->where('status_topup', 'pending');
    }

    public function scopeDisetujui($query)
    {
        return $query->where('status_topup', 'disetujui');
    }

    public function scopeDitolak($query)
    {
        return $query->where('status_topup', 'ditolak');
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopePeriode($query, $dari, $sampai)
    {
        return $query->whereBetween('created_at', [$dari, $sampai]);
    }

    // ==================== STATIC METHODS ====================

    /**
     * Buat transaksi top up baru
     */
    public static function buatTopup(
        int $dompetId,
        int $klienId,
        int $nominal,
        string $metodeBayar,
        ?string $bankTujuan = null,
        ?int $penggunaId = null
    ): self {
        $dompet = DompetSaldo::findOrFail($dompetId);

        return self::create([
            'dompet_id' => $dompetId,
            'klien_id' => $klienId,
            'pengguna_id' => $penggunaId,
            'jenis' => 'topup',
            'nominal' => $nominal,
            'saldo_sebelum' => $dompet->saldo_tersedia,
            'saldo_sesudah' => $dompet->saldo_tersedia, // belum berubah sampai disetujui
            'keterangan' => "Request top up Rp " . number_format($nominal, 0, ',', '.'),
            'status_topup' => 'pending',
            'metode_bayar' => $metodeBayar,
            'bank_tujuan' => $bankTujuan,
            'batas_bayar' => now()->addHours(24),
        ]);
    }

    /**
     * Setujui top up
     */
    public function setujuiTopup(int $adminId, ?string $catatan = null): bool
    {
        if ($this->jenis !== 'topup' || $this->status_topup !== 'pending') {
            return false;
        }

        $dompet = $this->dompet;
        $saldoSebelum = $dompet->saldo_tersedia;
        
        // Tambah saldo
        $dompet->tambahSaldo($this->nominal);

        // Update transaksi
        $this->update([
            'status_topup' => 'disetujui',
            'saldo_sebelum' => $saldoSebelum,
            'saldo_sesudah' => $dompet->saldo_tersedia,
            'diproses_oleh' => $adminId,
            'waktu_diproses' => now(),
            'catatan_admin' => $catatan,
        ]);

        return true;
    }

    /**
     * Tolak top up
     */
    public function tolakTopup(int $adminId, string $alasan): bool
    {
        if ($this->jenis !== 'topup' || $this->status_topup !== 'pending') {
            return false;
        }

        $this->update([
            'status_topup' => 'ditolak',
            'diproses_oleh' => $adminId,
            'waktu_diproses' => now(),
            'catatan_admin' => $alasan,
        ]);

        return true;
    }
}
