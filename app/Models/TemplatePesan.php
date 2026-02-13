<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model TemplatePesan
 * 
 * Template pesan WhatsApp yang sudah diapprove Meta.
 * Digunakan untuk campaign blast dan pesan utility/auth.
 */
class TemplatePesan extends Model
{
    use HasFactory;

    protected $table = 'template_pesan';

    // ==================== STATUS CONSTANTS (INDONESIA) ====================
    public const STATUS_DRAFT = 'draft';
    public const STATUS_DIAJUKAN = 'diajukan';
    public const STATUS_DISETUJUI = 'disetujui';
    public const STATUS_DITOLAK = 'ditolak';
    public const STATUS_ARSIP = 'arsip';

    // Legacy aliases untuk backward compatibility
    public const STATUS_PENDING = 'diajukan';
    public const STATUS_APPROVED = 'disetujui';
    public const STATUS_REJECTED = 'ditolak';
    public const STATUS_DISABLED = 'arsip';

    // ==================== KATEGORI CONSTANTS ====================
    public const KATEGORI_MARKETING = 'marketing';
    public const KATEGORI_UTILITY = 'utility';
    public const KATEGORI_AUTHENTICATION = 'authentication';

    // ==================== HEADER TYPE CONSTANTS ====================
    public const HEADER_NONE = 'none';
    public const HEADER_TEXT = 'text';
    public const HEADER_IMAGE = 'image';
    public const HEADER_VIDEO = 'video';
    public const HEADER_DOCUMENT = 'document';

    protected $fillable = [
        'klien_id',
        'dibuat_oleh',
        'nama_template',
        'nama_tampilan',
        'kategori',
        'bahasa',
        'header',
        'header_type',
        'header_media_url',
        'body',
        'footer',
        'buttons',
        'contoh_variabel',
        'status',
        'provider_template_id',
        'provider_payload',
        'provider_response',
        'catatan_reject',
        'alasan_penolakan',
        'submitted_at',
        'approved_at',
        'total_terkirim',
        'total_dibaca',
        'dipakai_count',
        'aktif',
    ];

    protected $casts = [
        'buttons' => 'array',
        'contoh_variabel' => 'array',
        'provider_payload' => 'array',
        'provider_response' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'aktif' => 'boolean',
        'total_terkirim' => 'integer',
        'total_dibaca' => 'integer',
        'dipakai_count' => 'integer',
    ];

    // ==================== RELATIONSHIPS ====================

    public function klien(): BelongsTo
    {
        return $this->belongsTo(Klien::class);
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'dibuat_oleh');
    }

    public function kampanye(): HasMany
    {
        return $this->hasMany(Kampanye::class, 'template_id');
    }

    // ==================== SCOPES ====================

    /**
     * Scope untuk template klien tertentu
     */
    public function scopeUntukKlien(Builder $query, int $klienId): Builder
    {
        return $query->where('klien_id', $klienId);
    }

    /**
     * Scope untuk template yang sudah disetujui
     */
    public function scopeDisetujui(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISETUJUI);
    }

    /**
     * Scope approved (alias untuk backward compatibility)
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $this->scopeDisetujui($query);
    }

    /**
     * Scope untuk template aktif
     */
    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true);
    }

    /**
     * Scope berdasarkan kategori
     */
    public function scopeKategori(Builder $query, string $kategori): Builder
    {
        return $query->where('kategori', $kategori);
    }

    /**
     * Scope template yang bisa dipakai untuk campaign (marketing disetujui)
     */
    public function scopeUntukCampaign(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISETUJUI)
            ->where('aktif', true);
    }

    /**
     * Scope template yang bisa dipakai di inbox (utility/auth disetujui)
     */
    public function scopeUntukInbox(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DISETUJUI)
            ->where('aktif', true)
            ->whereIn('kategori', [self::KATEGORI_UTILITY, self::KATEGORI_AUTHENTICATION]);
    }

    // ==================== ACCESSORS ====================

    /**
     * Cek apakah template bisa digunakan
     */
    public function getBisaDigunakanAttribute(): bool
    {
        return $this->status === self::STATUS_DISETUJUI && $this->aktif;
    }

    /**
     * Hitung jumlah variabel dalam body
     */
    public function getJumlahVariabelAttribute(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->body, $matches);
        return count(array_unique($matches[1] ?? []));
    }

    /**
     * Preview template dengan contoh variabel
     */
    public function getPreviewAttribute(): string
    {
        $preview = $this->body;
        $contoh = $this->contoh_variabel ?? [];

        foreach ($contoh as $key => $value) {
            $preview = str_replace("{{{$key}}}", $value, $preview);
        }

        return $preview;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Validasi nama template (WhatsApp rules)
     */
    public static function validasiNamaTemplate(string $nama): array
    {
        $errors = [];

        // Harus lowercase
        if ($nama !== strtolower($nama)) {
            $errors[] = 'Nama template harus lowercase';
        }

        // Tidak boleh ada spasi, gunakan underscore
        if (str_contains($nama, ' ')) {
            $errors[] = 'Nama template tidak boleh mengandung spasi, gunakan underscore';
        }

        // Maksimal 512 karakter
        if (strlen($nama) > 512) {
            $errors[] = 'Nama template maksimal 512 karakter';
        }

        // Hanya alfanumerik dan underscore
        if (!preg_match('/^[a-z0-9_]+$/', $nama)) {
            $errors[] = 'Nama template hanya boleh mengandung huruf kecil, angka, dan underscore';
        }

        return $errors;
    }

    /**
     * Format body untuk dikirim ke provider
     */
    public function formatBodyUntukProvider(): array
    {
        $components = [];

        // Header
        if ($this->header_type !== self::HEADER_NONE && $this->header) {
            $headerComponent = ['type' => 'header'];
            
            if ($this->header_type === self::HEADER_TEXT) {
                $headerComponent['parameters'] = [
                    ['type' => 'text', 'text' => $this->header]
                ];
            } else {
                $headerComponent['parameters'] = [
                    ['type' => $this->header_type, 'url' => $this->header_media_url]
                ];
            }
            
            $components[] = $headerComponent;
        }

        // Body dengan variabel
        if ($this->contoh_variabel) {
            $bodyParams = [];
            foreach ($this->contoh_variabel as $value) {
                $bodyParams[] = ['type' => 'text', 'text' => (string) $value];
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $bodyParams,
            ];
        }

        // Buttons
        if ($this->buttons) {
            foreach ($this->buttons as $index => $button) {
                if ($button['type'] === 'url' && isset($button['url_suffix'])) {
                    $components[] = [
                        'type' => 'button',
                        'sub_type' => 'url',
                        'index' => $index,
                        'parameters' => [
                            ['type' => 'text', 'text' => $button['url_suffix']]
                        ],
                    ];
                }
            }
        }

        return $components;
    }

    /**
     * Increment statistik terkirim
     */
    public function incrementTerkirim(int $jumlah = 1): void
    {
        $this->increment('total_terkirim', $jumlah);
    }

    /**
     * Increment statistik dibaca
     */
    public function incrementDibaca(int $jumlah = 1): void
    {
        $this->increment('total_dibaca', $jumlah);
    }

    /**
     * Cek apakah bisa di-submit ke provider
     */
    public function bisaSubmit(): bool
    {
        return $this->status === self::STATUS_DRAFT 
            || $this->status === self::STATUS_DITOLAK;
    }

    /**
     * Cek apakah sedang diajukan (pending review)
     */
    public function sedangDiajukan(): bool
    {
        return $this->status === self::STATUS_DIAJUKAN;
    }

    /**
     * Cek apakah bisa diedit
     */
    public function bisaDiedit(): bool
    {
        return $this->status === self::STATUS_DRAFT 
            || $this->status === self::STATUS_DITOLAK;
    }

    /**
     * Cek apakah bisa dihapus
     */
    public function bisaDihapus(): bool
    {
        // Tidak bisa hapus jika sudah dipakai campaign
        return $this->dipakai_count === 0 
            && ($this->status === self::STATUS_DRAFT || $this->status === self::STATUS_DITOLAK);
    }

    /**
     * Increment dipakai count
     */
    public function incrementDipakaiCount(): void
    {
        $this->increment('dipakai_count');
    }

    /**
     * Decrement dipakai count
     */
    public function decrementDipakaiCount(): void
    {
        if ($this->dipakai_count > 0) {
            $this->decrement('dipakai_count');
        }
    }
}
