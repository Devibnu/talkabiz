<?php

/**
 * Tax Configuration — Talkabiz SaaS
 *
 * ATURAN:
 * - Pajak TIDAK mempengaruhi saldo wallet
 * - Pajak hanya untuk invoice & laporan keuangan
 * - Semua tarif dari config, TIDAK hardcode di service
 * - Invoice adalah dokumen hukum — SSOT keuangan & pajak
 *
 * @see App\Services\TaxService
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Tax
    |--------------------------------------------------------------------------
    |
    | Master switch untuk mengaktifkan perhitungan pajak.
    | Jika false, semua invoice akan tax_type = 'none'.
    |
    */
    'enable_tax' => env('TAX_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Tax Rate (%)
    |--------------------------------------------------------------------------
    |
    | Tarif PPN Indonesia = 11%.
    | Untuk tarif custom per klien, override di level service.
    |
    */
    'default_tax_rate' => env('TAX_DEFAULT_RATE', 11),

    /*
    |--------------------------------------------------------------------------
    | Default Tax Type
    |--------------------------------------------------------------------------
    |
    | Tipe pajak default: 'PPN' (Pajak Pertambahan Nilai).
    | Supported: 'PPN', 'none'
    |
    */
    'default_tax_type' => env('TAX_DEFAULT_TYPE', 'PPN'),

    /*
    |--------------------------------------------------------------------------
    | Tax Included in Price
    |--------------------------------------------------------------------------
    |
    | false = pajak DITAMBAHKAN di atas harga (exclusive / DPP + PPN)
    | true  = pajak SUDAH TERMASUK dalam harga (inclusive)
    |
    | Untuk topup saldo: selalu false
    | → saldo wallet = amount, invoice total = amount + PPN
    |
    */
    'tax_included' => env('TAX_INCLUDED', false),

    /*
    |--------------------------------------------------------------------------
    | Company Information (Seller / Penjual)
    |--------------------------------------------------------------------------
    |
    | Data perusahaan yang tampil di invoice dan faktur pajak.
    |
    */
    'company_name'    => env('TAX_COMPANY_NAME', 'PT Talkabiz Teknologi Indonesia'),
    'company_npwp'    => env('TAX_COMPANY_NPWP', '00.000.000.0-000.000'),
    'company_address' => env('TAX_COMPANY_ADDRESS', 'Indonesia'),
    'company_phone'   => env('TAX_COMPANY_PHONE', '-'),
    'company_email'   => env('TAX_COMPANY_EMAIL', 'billing@talkabiz.com'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Number Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix untuk nomor invoice resmi.
    | Format final: {prefix}/{YYYY}/{MM}/{SEQUENCE}
    | Contoh: TBZ/INV/2026/02/000123
    |
    */
    'invoice_prefix' => env('TAX_INVOICE_PREFIX', 'TBZ/INV'),

    /*
    |--------------------------------------------------------------------------
    | Sequence Digits
    |--------------------------------------------------------------------------
    |
    | Jumlah digit untuk sequence number.
    | 6 digits = 000001 s/d 999999 per bulan.
    |
    */
    'sequence_digits' => env('TAX_SEQUENCE_DIGITS', 6),

    /*
    |--------------------------------------------------------------------------
    | Calculation Precision
    |--------------------------------------------------------------------------
    |
    | Presisi desimal untuk perhitungan pajak.
    | Standar Indonesia: 0 (pembulatan ke Rupiah penuh).
    |
    */
    'calculation_precision' => env('TAX_PRECISION', 0),

    /*
    |--------------------------------------------------------------------------
    | Rounding Mode
    |--------------------------------------------------------------------------
    |
    | Metode pembulatan pajak:
    | 'round' = pembulatan normal (default, sesuai aturan DJP)
    | 'floor' = pembulatan ke bawah
    | 'ceil'  = pembulatan ke atas
    |
    */
    'rounding_mode' => env('TAX_ROUNDING', 'round'),

];
