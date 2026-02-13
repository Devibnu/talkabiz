<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';

    public $timestamps = false; // Pakai kolom 'waktu' saja

    protected $fillable = [
        'pengguna_id',
        'klien_id',
        'aksi',
        'modul',
        'tabel_terkait',
        'id_terkait',
        'deskripsi',
        'data_lama',
        'data_baru',
        'ip_address',
        'user_agent',
        'waktu',
    ];

    protected $casts = [
        'data_lama' => 'array',
        'data_baru' => 'array',
        'waktu' => 'datetime',
    ];

    // ==================== BOOT ====================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (empty($log->waktu)) {
                $log->waktu = now();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id');
    }

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Label aksi dengan warna
     */
    public function getLabelAksiAttribute(): array
    {
        return match ($this->aksi) {
            'create' => ['label' => 'Buat', 'warna' => 'green'],
            'update' => ['label' => 'Ubah', 'warna' => 'yellow'],
            'delete' => ['label' => 'Hapus', 'warna' => 'red'],
            'login' => ['label' => 'Login', 'warna' => 'blue'],
            'logout' => ['label' => 'Logout', 'warna' => 'gray'],
            'approve' => ['label' => 'Setujui', 'warna' => 'green'],
            'reject' => ['label' => 'Tolak', 'warna' => 'red'],
            'kirim' => ['label' => 'Kirim', 'warna' => 'blue'],
            'topup' => ['label' => 'Top Up', 'warna' => 'green'],
            default => ['label' => ucfirst($this->aksi), 'warna' => 'gray'],
        };
    }

    // ==================== STATIC METHODS ====================

    /**
     * Catat aktivitas
     */
    public static function catat(
        string $aksi,
        string $modul,
        ?string $deskripsi = null,
        ?int $penggunaId = null,
        ?int $klienId = null,
        ?string $tabelTerkait = null,
        ?int $idTerkait = null,
        ?array $dataLama = null,
        ?array $dataBaru = null
    ): self {
        return self::create([
            'pengguna_id' => $penggunaId ?? auth()->id(),
            'klien_id' => $klienId,
            'aksi' => $aksi,
            'modul' => $modul,
            'tabel_terkait' => $tabelTerkait,
            'id_terkait' => $idTerkait,
            'deskripsi' => $deskripsi,
            'data_lama' => $dataLama,
            'data_baru' => $dataBaru,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Shortcut: Log login
     */
    public static function logLogin(int $penggunaId, ?int $klienId = null): self
    {
        return self::catat('login', 'auth', 'User login', $penggunaId, $klienId);
    }

    /**
     * Shortcut: Log logout
     */
    public static function logLogout(int $penggunaId, ?int $klienId = null): self
    {
        return self::catat('logout', 'auth', 'User logout', $penggunaId, $klienId);
    }

    /**
     * Shortcut: Log approve top up
     */
    public static function logApproveTopup(
        int $transaksiId,
        int $klienId,
        int $adminId,
        int $nominal
    ): self {
        return self::catat(
            'approve',
            'saldo',
            "Menyetujui top up Rp " . number_format($nominal, 0, ',', '.'),
            $adminId,
            $klienId,
            'transaksi_saldo',
            $transaksiId
        );
    }

    /**
     * Shortcut: Log kirim campaign
     */
    public static function logKirimCampaign(
        int $kampanyeId,
        int $klienId,
        int $penggunaId,
        string $namaCampaign
    ): self {
        return self::catat(
            'kirim',
            'kampanye',
            "Mengirim campaign: {$namaCampaign}",
            $penggunaId,
            $klienId,
            'kampanye',
            $kampanyeId
        );
    }

    // ==================== SCOPES ====================

    public function scopeByPengguna($query, int $penggunaId)
    {
        return $query->where('pengguna_id', $penggunaId);
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    public function scopeByModul($query, string $modul)
    {
        return $query->where('modul', $modul);
    }

    public function scopeByAksi($query, string $aksi)
    {
        return $query->where('aksi', $aksi);
    }

    public function scopePeriode($query, $dari, $sampai)
    {
        return $query->whereBetween('waktu', [$dari, $sampai]);
    }

    public function scopeTerbaru($query)
    {
        return $query->orderByDesc('waktu');
    }
}
