<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TaxSettings Model
 * 
 * Konfigurasi pajak global untuk company/seller.
 * Include: info perusahaan, NPWP seller, rate default, e-Faktur settings.
 * 
 * @property string $company_name
 * @property string $company_npwp
 * @property float $default_ppn_rate
 * @property bool $auto_apply_tax
 * @property bool $efaktur_enabled
 */
class TaxSettings extends Model
{
    protected $table = 'tax_settings';

    protected $fillable = [
        'company_name',
        'company_npwp',
        'company_pkp_number',
        'company_address',
        'default_ppn_rate',
        'auto_apply_tax',
        'efaktur_enabled',
        'efaktur_api_url',
        'efaktur_api_key',
        'efaktur_prefix',
        'efaktur_last_number',
        'is_active',
    ];

    protected $casts = [
        'default_ppn_rate' => 'decimal:2',
        'auto_apply_tax' => 'boolean',
        'efaktur_enabled' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'efaktur_api_key',
    ];

    /**
     * Get active tax settings
     */
    public static function getActive(): ?self
    {
        return self::where('is_active', true)->first();
    }

    /**
     * Get seller info for invoice
     */
    public function getSellerInfo(): array
    {
        return [
            'npwp' => $this->company_npwp,
            'name' => $this->company_name,
            'address' => $this->company_address,
            'pkp_number' => $this->company_pkp_number,
        ];
    }

    /**
     * Generate next e-Faktur number
     */
    public function generateNextEfakturNumber(): string
    {
        $this->efaktur_last_number++;
        $this->save();

        $prefix = $this->efaktur_prefix ?? '010';
        $year = now()->format('y');
        $sequence = str_pad($this->efaktur_last_number, 8, '0', STR_PAD_LEFT);

        return "{$prefix}.000-{$year}.{$sequence}";
    }
}
