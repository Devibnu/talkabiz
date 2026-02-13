<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EInvoice Model (e-Faktur)
 * 
 * Struktur untuk e-Faktur pajak Indonesia.
 * Menyimpan data faktur yang siap upload ke DJP.
 * 
 * Status: draft -> uploaded -> approved/rejected
 * 
 * @property int $invoice_id
 * @property string $efaktur_number
 * @property string $status
 * @property string $transaction_code
 */
class EInvoice extends Model
{
    use SoftDeletes;

    protected $table = 'e_invoices';

    // Transaction codes for e-Faktur
    const TRANS_INTERNAL = '01'; // Kepada pihak yang bukan pemungut PPN
    const TRANS_GOVERNMENT = '02'; // Kepada pemungut bendaharawan
    const TRANS_COLLECTOR = '03'; // Kepada pemungut selain bendaharawan
    const TRANS_SPECIAL = '04'; // DPP nilai lain
    const TRANS_ASSET = '05'; // Penyerahan aktiva pasal 16D
    const TRANS_DELEGATE = '06'; // Penyerahan kepada turis asing
    const TRANS_NON_TAXABLE = '07'; // Penyerahan tidak dipungut
    const TRANS_EXEMPT = '08'; // Penyerahan dibebaskan
    const TRANS_EXPORT = '09'; // Penyerahan aktiva pasal 16D kepada pemungut

    // Status flow
    const STATUS_DRAFT = 'draft';
    const STATUS_UPLOADED = 'uploaded';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REPLACED = 'replaced';
    const STATUS_PENDING = 'pending'; // Alias for draft

    protected $fillable = [
        'invoice_id',
        'efaktur_number',
        'transaction_code',
        'status_code',
        'seller_npwp',
        'seller_name',
        'seller_address',
        'buyer_npwp',
        'buyer_name',
        'buyer_address',
        'buyer_is_pkp',
        'dpp',
        'ppn',
        'ppn_bm',
        'total',
        'line_items',
        'faktur_date',
        'approval_date',
        'status',
        'djp_response_code',
        'djp_response_message',
        'pdf_path',
        'xml_path',
        'qr_code',
        'reference_efaktur',
        'notes',
        'created_by',
        'approved_by',
        // Aliases for service compatibility
        'dpp_amount',
        'ppn_amount',
        'ppnbm_amount',
        'total_amount',
        'items_data',
    ];

    protected $casts = [
        'faktur_date' => 'date',
        'approval_date' => 'date',
        'dpp' => 'decimal:2',
        'ppn' => 'decimal:2',
        'ppn_bm' => 'decimal:2',
        'total' => 'decimal:2',
        'line_items' => 'array',
        'buyer_is_pkp' => 'boolean',
    ];

    /**
     * Invoice relationship
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Creator relationship
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Approver relationship
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Mutators for aliases (service compatibility)
     */
    public function setDppAmountAttribute($value)
    {
        $this->attributes['dpp'] = $value;
    }

    public function setPpnAmountAttribute($value)
    {
        $this->attributes['ppn'] = $value;
    }

    public function setPpnbmAmountAttribute($value)
    {
        $this->attributes['ppn_bm'] = $value;
    }

    public function setTotalAmountAttribute($value)
    {
        $this->attributes['total'] = $value;
    }

    public function setItemsDataAttribute($value)
    {
        $this->attributes['line_items'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Check if can be retried after rejection
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if approved
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Mark as uploaded
     */
    public function markAsUploaded(): void
    {
        $this->update([
            'status' => self::STATUS_UPLOADED,
        ]);
    }

    /**
     * Mark as approved
     */
    public function markAsApproved(string $approvalNumber, ?string $responseCode = null): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'djp_response_code' => $approvalNumber,
            'approval_date' => now(),
        ]);
    }

    /**
     * Mark as rejected
     */
    public function markAsRejected(string $errorMessage, ?string $responseCode = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'djp_response_code' => $responseCode,
            'djp_response_message' => $errorMessage,
        ]);
    }

    /**
     * Get formatted e-Faktur data for DJP
     */
    public function toDjpFormat(): array
    {
        return [
            'NPWP' => $this->buyer_npwp,
            'NAMA' => $this->buyer_name,
            'ALAMAT_LENGKAP' => $this->buyer_address,
            'NOMOR_FAKTUR' => $this->efaktur_number,
            'TANGGAL_FAKTUR' => $this->faktur_date->format('d/m/Y'),
            'KODE_TRANSAKSI' => $this->transaction_code,
            'JUMLAH_DPP' => number_format($this->dpp, 0, '', ''),
            'JUMLAH_PPN' => number_format($this->ppn, 0, '', ''),
            'JUMLAH_PPNBM' => number_format($this->ppn_bm ?? 0, 0, '', ''),
            'REFERENSI' => $this->invoice?->invoice_number ?? '',
        ];
    }

    /**
     * Scope: pending/draft
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope: need upload
     */
    public function scopeNeedUpload($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    /**
     * Scope: approved
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope: rejected
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }
}
