<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Pengguna extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $table = 'pengguna';

    protected $fillable = [
        'klien_id',
        'nama_lengkap',
        'email',
        'password',
        'no_telepon',
        'foto_profil',
        'role',
        'aktif',
        'email_verified_at',
        'terakhir_login',
        'preferensi',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'terakhir_login' => 'datetime',
        'aktif' => 'boolean',
        'preferensi' => 'array',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * Klien yang dimiliki pengguna
     */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class, 'klien_id');
    }

    /**
     * Kampanye yang dibuat oleh pengguna
     */
    public function kampanye(): HasMany
    {
        return $this->hasMany(Kampanye::class, 'dibuat_oleh');
    }

    /**
     * Percakapan yang ditangani
     */
    public function percakapanDitangani(): HasMany
    {
        return $this->hasMany(PercakapanInbox::class, 'ditangani_oleh');
    }

    /**
     * Transaksi yang diproses (untuk admin)
     */
    public function transaksiDiproses(): HasMany
    {
        return $this->hasMany(TransaksiSaldo::class, 'diproses_oleh');
    }

    /**
     * Log aktivitas pengguna
     */
    public function logAktivitas(): HasMany
    {
        return $this->hasMany(LogAktivitas::class, 'pengguna_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah super admin
     */
    public function getIsSuperAdminAttribute(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Cek apakah owner
     */
    public function getIsOwnerAttribute(): bool
    {
        return $this->role === 'owner';
    }

    /**
     * Cek apakah admin
     */
    public function getIsAdminAttribute(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Cek apakah sales
     */
    public function getIsSalesAttribute(): bool
    {
        return $this->role === 'sales';
    }

    /**
     * Cek apakah bisa akses saldo (owner & admin)
     */
    public function getBisaAksesSaldoAttribute(): bool
    {
        return in_array($this->role, ['super_admin', 'owner', 'admin']);
    }

    /**
     * Cek apakah bisa kirim campaign
     */
    public function getBisaKirimCampaignAttribute(): bool
    {
        return in_array($this->role, ['super_admin', 'owner', 'admin']);
    }

    /**
     * Cek apakah bisa approve top up
     */
    public function getBisaApproveTopupAttribute(): bool
    {
        return $this->role === 'super_admin';
    }

    // ==================== SCOPES ====================

    public function scopeAktif($query)
    {
        return $query->where('aktif', true);
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function scopeByKlien($query, int $klienId)
    {
        return $query->where('klien_id', $klienId);
    }

    // ==================== METHODS ====================

    /**
     * Update waktu terakhir login
     */
    public function updateTerakhirLogin(): void
    {
        $this->update(['terakhir_login' => now()]);
    }
}
