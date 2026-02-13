<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Klien extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'klien';

    protected $fillable = [
        'nama_perusahaan',
        'slug',
        'tipe_bisnis',
        'alamat',
        'kota',
        'provinsi',
        'kode_pos',
        'email',
        'no_telepon',
        'no_whatsapp',
        'wa_phone_number_id',
        'wa_business_account_id',
        'wa_access_token',
        'wa_terhubung',
        'wa_terakhir_sync',
        'status',
        'tipe_paket',
        'tanggal_bergabung',
        'tanggal_berakhir',
        'pengaturan',
        'approval_status',
        'approved_by',
        'approved_at',
        'approval_notes',
    ];

    protected $casts = [
        'wa_terhubung' => 'boolean',
        'wa_terakhir_sync' => 'datetime',
        'tanggal_bergabung' => 'date',
        'tanggal_berakhir' => 'date',
        'pengaturan' => 'array',
        'approved_at' => 'datetime',
    ];

    protected $hidden = [
        'wa_access_token',
    ];

    // ==================== RELATIONSHIPS ====================

    /**
     * User utama klien (1:1)
     * Foreign key klien_id ada di tabel users
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'klien_id');
    }

    /**
     * Dompet saldo klien (1:1)
     */
    public function dompet(): HasOne
    {
        return $this->hasOne(DompetSaldo::class, 'klien_id');
    }

    /**
     * Semua pengguna klien
     */
    public function pengguna(): HasMany
    {
        return $this->hasMany(Pengguna::class, 'klien_id');
    }

    /**
     * Owner klien
     */
    public function owner(): HasOne
    {
        return $this->hasOne(Pengguna::class, 'klien_id')->where('role', 'owner');
    }

    /**
     * Semua admin klien
     */
    public function admin(): HasMany
    {
        return $this->hasMany(Pengguna::class, 'klien_id')->where('role', 'admin');
    }

    /**
     * Semua sales klien
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Pengguna::class, 'klien_id')->where('role', 'sales');
    }

    /**
     * Semua kampanye klien
     */
    public function kampanye(): HasMany
    {
        return $this->hasMany(Kampanye::class, 'klien_id');
    }

    /**
     * Subscription plan klien
     */
    public function subscriptionPlan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Semua transaksi saldo
     */
    public function transaksiSaldo(): HasMany
    {
        return $this->hasMany(TransaksiSaldo::class, 'klien_id');
    }

    /**
     * Semua percakapan inbox
     */
    public function percakapan(): HasMany
    {
        return $this->hasMany(PercakapanInbox::class, 'klien_id');
    }

    /**
     * WhatsApp Connections (Cloud API)
     * Relasi ke tabel whatsapp_connections
     */
    public function whatsappConnections(): HasMany
    {
        return $this->hasMany(WhatsappConnection::class, 'klien_id');
    }

    /**
     * WhatsApp Connection aktif (pertama)
     */
    public function whatsappConnection(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WhatsappConnection::class, 'klien_id')
            ->where('status', 'connected')
            ->latest();
    }

    /**
     * Tax Profile klien (untuk PPN dan e-Faktur)
     */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(ClientTaxProfile::class, 'klien_id');
    }

    /**
     * Business Type dari master data
     * Relasi: klien.tipe_bisnis (string) -> business_types.code (string)
     */
    public function businessType(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'tipe_bisnis', 'code');
    }

    /**
     * Semua invoice klien
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'klien_id');
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah klien aktif
     */
    public function getIsAktifAttribute(): bool
    {
        return $this->status === 'aktif';
    }

    /**
     * Cek apakah WA sudah terhubung
     */
    public function getIsWaTerhubungAttribute(): bool
    {
        return $this->wa_terhubung && !empty($this->wa_access_token);
    }

    // ==================== SCOPES ====================

    public function scopeAktif($query)
    {
        return $query->where('status', 'aktif');
    }

    public function scopeEnterprise($query)
    {
        return $query->where('tipe_paket', 'enterprise');
    }

    public function scopeUmkm($query)
    {
        return $query->where('tipe_paket', 'umkm');
    }

    public function scopeWaTerhubung($query)
    {
        return $query->where('wa_terhubung', true);
    }

    // ==================== BUSINESS SNAPSHOT ====================

    /**
     * Generate immutable business snapshot untuk invoice
     * 
     * IMMUTABILITY:
     * - Snapshot diambil pada saat invoice created
     * - TIDAK berubah walaupun profil bisnis diupdate
     * - Untuk audit trail, PPN, dan compliance
     * 
     * @return array
     */
    public function generateBusinessSnapshot(): array
    {
        // Load business type dari master data
        $businessType = $this->businessType;
        
        $snapshot = [
            'business_name' => $this->nama_perusahaan,
            'business_type_code' => $this->tipe_bisnis,
            'business_type_name' => $businessType?->name ?? ucfirst($this->tipe_bisnis),
            'address' => $this->alamat,
            'city' => $this->kota,
            'province' => $this->provinsi,
            'postal_code' => $this->kode_pos,
            'email' => $this->email,
            'phone' => $this->no_telepon,
            'whatsapp' => $this->no_whatsapp,
            'snapshot_at' => now()->toIso8601String(),
        ];
        
        // Tax Profile (NPWP) if exists
        if ($this->relationLoaded('taxProfile') && $this->taxProfile) {
            $snapshot['npwp'] = $this->taxProfile->npwp;
            $snapshot['npwp_name'] = $this->taxProfile->npwp_name;
            $snapshot['npwp_address'] = $this->taxProfile->npwp_address;
            $snapshot['is_pkp'] = $this->taxProfile->is_pkp;
        }
        
        return $snapshot;
    }

    // ==================== APPROVAL MANAGEMENT ====================

    /**
     * Get approval logs for this klien
     */
    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ApprovalLog::class, 'klien_id');
    }

    /**
     * Get admin who approved/rejected this klien
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // ==================== ABUSE SCORING ====================

    /**
     * Get abuse score for this klien
     */
    public function abuseScore(): HasOne
    {
        return $this->hasOne(AbuseScore::class, 'klien_id');
    }

    /**
     * Get abuse events for this klien
     */
    public function abuseEvents(): HasMany
    {
        return $this->hasMany(AbuseEvent::class, 'klien_id');
    }

    /**
     * Check if klien is approved for message sending.
     */
    public function isApproved(): bool
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if klien is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->approval_status === 'pending';
    }

    /**
     * Check if klien is rejected.
     */
    public function isRejected(): bool
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Check if klien is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->approval_status === 'suspended';
    }

    /**
     * Check if klien can send messages.
     * Requires both approval AND active status.
     */
    public function canSendMessages(): bool
    {
        return $this->isApproved() && $this->status === 'aktif';
    }

    /**
     * Get approval status badge color for UI.
     */
    public function getApprovalBadgeColor(): string
    {
        return match($this->approval_status) {
            'approved' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            'suspended' => 'dark',
            default => 'secondary',
        };
    }

    /**
     * Get approval status label for UI.
     */
    public function getApprovalStatusLabel(): string
    {
        return match($this->approval_status) {
            'approved' => 'Approved',
            'pending' => 'Pending Approval',
            'rejected' => 'Rejected',
            'suspended' => 'Suspended',
            default => ucfirst($this->approval_status ?? 'Unknown'),
        };
    }

    /**
     * Scope to get only approved kliens
     */
    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    /**
     * Scope to get only pending kliens
     */
    public function scopePending($query)
    {
        return $query->where('approval_status', 'pending');
    }

    /**
     * Scope to get only rejected kliens
     */
    public function scopeRejected($query)
    {
        return $query->where('approval_status', 'rejected');
    }

    /**
     * Scope to get only suspended kliens
     */
    public function scopeSuspended($query)
    {
        return $query->where('approval_status', 'suspended');
    }
}

