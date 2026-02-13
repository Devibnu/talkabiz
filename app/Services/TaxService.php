<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Klien;
use App\Models\ClientTaxProfile;
use App\Models\TaxSettings;
use App\Models\EInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TaxService
 * 
 * Menangani perhitungan dan penerapan pajak pada invoice.
 * 
 * ATURAN BISNIS:
 * ==============
 * 1. PPN Default 11% (dapat dikonfigurasi)
 * 2. Pajak dihitung saat invoice dibuat (snapshot)
 * 3. Invoice tidak boleh diubah setelah status PAID
 * 4. PKP wajib faktur pajak, non-PKP optional
 * 5. Siap integrasi e-Faktur DJP
 * 
 * FLOW:
 * =====
 * 1. calculateAndApplyTax(Invoice) → Hitung & apply tax
 * 2. snapshotTaxInfo(Invoice) → Snapshot buyer/seller info
 * 3. lockInvoiceTax(Invoice) → Lock agar tidak bisa diubah
 * 4. generateEfaktur(Invoice) → Create e-Faktur record
 */
class TaxService
{
    private ?TaxSettings $taxSettings = null;

    public function __construct()
    {
        // Only load if table exists (handles pre-migration state)
        try {
            $this->taxSettings = TaxSettings::getActive();
        } catch (\Exception $e) {
            // Table doesn't exist yet - ignore
            $this->taxSettings = null;
        }
    }

    /**
     * Get active tax settings
     */
    public function getSettings(): ?TaxSettings
    {
        return $this->taxSettings;
    }

    /**
     * Get default PPN rate
     */
    public function getDefaultPpnRate(): float
    {
        return $this->taxSettings?->default_ppn_rate ?? 11.00;
    }

    /**
     * Calculate and apply tax to invoice
     * 
     * @param Invoice $invoice The invoice to apply tax to
     * @param float|null $customRate Custom tax rate (null = use default)
     * @param string $taxType Tax type: ppn, ppn_bm, pph, exempt
     * @param string $calculation exclusive or inclusive
     * @param bool $lockAfter Lock tax after applying
     * @return Invoice
     * 
     * @throws \Exception if invoice cannot be modified
     */
    public function calculateAndApplyTax(
        Invoice $invoice,
        ?float $customRate = null,
        string $taxType = 'ppn',
        string $calculation = 'exclusive',
        bool $lockAfter = false
    ): Invoice {
        // Validate can modify
        if (!$invoice->canModifyTax()) {
            throw new \Exception('Invoice tax sudah terkunci dan tidak dapat diubah');
        }

        // Check if client is tax exempt
        $taxProfile = $invoice->getTaxProfile();
        if ($taxProfile && $taxProfile->tax_exempt) {
            return $this->applyExempt($invoice, $taxProfile->tax_exempt_reason ?? 'Tax exempt');
        }

        // Determine tax rate
        $rate = $customRate ?? $taxProfile?->custom_tax_rate ?? $this->getDefaultPpnRate();

        // Apply tax
        $invoice->applyTax($rate, $taxType, $calculation);

        // Snapshot tax info
        $this->snapshotTaxInfo($invoice, $taxProfile);

        // Lock if requested
        if ($lockAfter) {
            $invoice->lockTax();
        }

        $invoice->save();

        Log::info('TaxService: Applied tax to invoice', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'tax_rate' => $rate,
            'tax_type' => $taxType,
            'tax_amount' => $invoice->tax,
            'total' => $invoice->total,
        ]);

        return $invoice;
    }

    /**
     * Apply tax exemption
     */
    public function applyExempt(Invoice $invoice, string $reason = 'Tax exempt'): Invoice
    {
        if (!$invoice->canModifyTax()) {
            throw new \Exception('Invoice tax sudah terkunci dan tidak dapat diubah');
        }

        $invoice->tax_rate = 0;
        $invoice->tax_type = 'exempt';
        $invoice->tax = 0;
        $invoice->total = $invoice->subtotal - $invoice->discount;
        
        // Store exemption reason in metadata
        $metadata = $invoice->metadata ?? [];
        $metadata['tax_exempt_reason'] = $reason;
        $invoice->metadata = $metadata;

        $invoice->save();

        Log::info('TaxService: Applied tax exemption', [
            'invoice_id' => $invoice->id,
            'reason' => $reason,
        ]);

        return $invoice;
    }

    /**
     * Snapshot buyer and seller tax info
     */
    public function snapshotTaxInfo(Invoice $invoice, ?ClientTaxProfile $taxProfile = null): Invoice
    {
        // Snapshot buyer info
        $taxProfile = $taxProfile ?? $invoice->getTaxProfile();
        $invoice->snapshotBuyerTaxInfo($taxProfile);

        // Snapshot seller info
        if ($this->taxSettings) {
            $invoice->snapshotSellerTaxInfo($this->taxSettings);
        }

        return $invoice;
    }

    /**
     * Lock invoice tax (called after payment success)
     */
    public function lockInvoiceTax(Invoice $invoice): Invoice
    {
        $invoice->lockTax();
        
        Log::info('TaxService: Locked invoice tax', [
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);

        return $invoice;
    }

    /**
     * Create e-Faktur record for invoice
     * 
     * Only for PKP clients and when:
     * - Invoice is paid
     * - Buyer is PKP
     * - Or explicitly requested as tax invoice
     */
    public function generateEfaktur(Invoice $invoice): ?EInvoice
    {
        // Validate
        if ($invoice->status !== Invoice::STATUS_PAID) {
            Log::warning('TaxService: Cannot generate e-Faktur for unpaid invoice', [
                'invoice_id' => $invoice->id,
            ]);
            return null;
        }

        if ($invoice->eInvoice) {
            Log::info('TaxService: e-Faktur already exists', [
                'invoice_id' => $invoice->id,
                'efaktur_number' => $invoice->eInvoice->efaktur_number,
            ]);
            return $invoice->eInvoice;
        }

        // Check if should generate e-Faktur
        $shouldGenerate = $invoice->is_tax_invoice || $invoice->buyer_is_pkp;
        if (!$shouldGenerate) {
            Log::info('TaxService: e-Faktur not required', [
                'invoice_id' => $invoice->id,
                'buyer_is_pkp' => $invoice->buyer_is_pkp,
            ]);
            return null;
        }

        // Generate e-Faktur number
        $efakturNumber = $this->taxSettings?->generateNextEfakturNumber() 
            ?? $this->generateFallbackEfakturNumber();

        // Create e-Invoice record
        $eInvoice = EInvoice::create([
            'invoice_id' => $invoice->id,
            'efaktur_number' => $efakturNumber,
            'transaction_code' => EInvoice::TRANS_INTERNAL,
            'status' => EInvoice::STATUS_PENDING,
            'faktur_date' => $invoice->paid_at ?? now(),
            'buyer_npwp' => $invoice->buyer_npwp ?? '',
            'buyer_name' => $invoice->buyer_npwp_name ?? $invoice->klien->name ?? '',
            'buyer_address' => $invoice->buyer_npwp_address ?? '',
            'dpp_amount' => $invoice->subtotal - $invoice->discount,
            'ppn_amount' => $invoice->tax,
            'ppnbm_amount' => 0,
            'total_amount' => $invoice->total,
            'items_data' => $invoice->line_items,
        ]);

        // Update invoice with e-Faktur reference
        $invoice->update([
            'efaktur_number' => $efakturNumber,
            'efaktur_generated_at' => now(),
            'is_tax_invoice' => true,
        ]);

        Log::info('TaxService: Generated e-Faktur', [
            'invoice_id' => $invoice->id,
            'efaktur_number' => $efakturNumber,
            'e_invoice_id' => $eInvoice->id,
        ]);

        return $eInvoice;
    }

    /**
     * Generate fallback e-Faktur number if no TaxSettings
     */
    private function generateFallbackEfakturNumber(): string
    {
        $year = now()->format('y');
        $sequence = str_pad(EInvoice::count() + 1, 8, '0', STR_PAD_LEFT);
        return "010.000-{$year}.{$sequence}";
    }

    /**
     * Validate client tax profile
     */
    public function validateTaxProfile(Klien $klien): array
    {
        $errors = [];
        $warnings = [];
        
        $taxProfile = $klien->taxProfile;

        if (!$taxProfile) {
            $warnings[] = 'Tax profile belum diisi';
            return ['valid' => true, 'errors' => $errors, 'warnings' => $warnings];
        }

        // NPWP validation (15 digits)
        if ($taxProfile->npwp) {
            $npwpDigits = preg_replace('/[^0-9]/', '', $taxProfile->npwp);
            if (strlen($npwpDigits) !== 15) {
                $errors[] = 'NPWP harus 15 digit';
            }
        }

        // PKP validation
        if ($taxProfile->is_pkp) {
            if (empty($taxProfile->npwp)) {
                $errors[] = 'PKP wajib memiliki NPWP';
            }
            if (empty($taxProfile->pkp_number)) {
                $warnings[] = 'Nomor pengukuhan PKP belum diisi';
            }
            if ($taxProfile->pkp_expired_at && $taxProfile->pkp_expired_at->isPast()) {
                $warnings[] = 'PKP sudah expired';
            }
        }

        // Address validation
        if (empty($taxProfile->entity_address) && empty($taxProfile->npwp_address)) {
            $warnings[] = 'Alamat perpajakan belum diisi';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get tax summary for a period
     */
    public function getTaxSummary(string $startDate, string $endDate, ?int $klienId = null): array
    {
        $query = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereBetween('paid_at', [$startDate, $endDate]);

        if ($klienId) {
            $query->where('klien_id', $klienId);
        }

        $invoices = $query->get();

        $totalDpp = $invoices->sum(function ($inv) {
            return $inv->subtotal - $inv->discount;
        });
        $totalPpn = $invoices->sum('tax');
        $totalInvoiced = $invoices->sum('total');

        // Group by tax type
        $byTaxType = $invoices->groupBy('tax_type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'dpp' => $group->sum(function ($inv) {
                    return $inv->subtotal - $inv->discount;
                }),
                'tax' => $group->sum('tax'),
                'total' => $group->sum('total'),
            ];
        });

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => [
                'invoice_count' => $invoices->count(),
                'total_dpp' => round($totalDpp, 2),
                'total_ppn' => round($totalPpn, 2),
                'total_invoiced' => round($totalInvoiced, 2),
            ],
            'by_tax_type' => $byTaxType,
            'efaktur' => [
                'generated' => $invoices->whereNotNull('efaktur_number')->count(),
                'pending' => EInvoice::where('status', EInvoice::STATUS_PENDING)->count(),
                'approved' => EInvoice::where('status', EInvoice::STATUS_APPROVED)->count(),
            ],
        ];
    }

    /**
     * Calculate tax for preview (without saving)
     */
    public function preview(
        float $subtotal,
        float $discount = 0,
        ?float $taxRate = null,
        string $calculation = 'exclusive'
    ): array {
        $rate = $taxRate ?? $this->getDefaultPpnRate();
        $dpp = $subtotal - $discount;

        if ($calculation === 'exclusive') {
            $tax = round($dpp * ($rate / 100), 2);
            $total = $dpp + $tax;
        } else {
            // Inclusive - extract tax
            $tax = round($dpp - ($dpp / (1 + ($rate / 100))), 2);
            $total = $dpp;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discount, 2),
            'dpp' => round($dpp, 2),
            'tax_rate' => $rate,
            'tax_calculation' => $calculation,
            'tax_amount' => $tax,
            'total' => round($total, 2),
        ];
    }

    // ==================== TOPUP PPN (WALLET-SAFE) ====================

    /**
     * Hitung PPN untuk topup saldo.
     *
     * ATURAN KERAS:
     * ─────────────
     * ❌ Pajak TIDAK mempengaruhi saldo wallet
     * ✅ Saldo wallet = amount (DPP, tanpa pajak)
     * ✅ Invoice total = amount + PPN (untuk dokumen keuangan)
     * ✅ Semua tarif dari config('tax.*'), TIDAK hardcode
     *
     * @param  int|float $amount  Nominal topup (masuk wallet = DPP)
     * @return array{subtotal: float, tax_rate: float, tax_amount: float, total_amount: float, tax_type: string, tax_included: bool}
     *
     * @throws \InvalidArgumentException Jika amount <= 0
     */
    public function calculatePPN(int|float $amount): array
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount harus lebih besar dari 0.');
        }

        $enabled  = (bool) config('tax.enable_tax', true);
        $taxRate  = (float) config('tax.default_tax_rate', $this->getDefaultPpnRate());
        $taxType  = (string) config('tax.default_tax_type', 'PPN');
        $included = (bool) config('tax.tax_included', false);
        $precision = (int) config('tax.calculation_precision', 0);

        // Pajak nonaktif → tanpa pajak
        if (!$enabled) {
            return [
                'subtotal'     => $this->roundTax($amount, $precision),
                'tax_rate'     => 0,
                'tax_amount'   => 0,
                'total_amount' => $this->roundTax($amount, $precision),
                'tax_type'     => 'none',
                'tax_included' => false,
            ];
        }

        // EXCLUSIVE (default topup): DPP = amount, PPN = DPP × rate%
        if (!$included) {
            $subtotal  = $this->roundTax($amount, $precision);
            $taxAmount = $this->roundTax($amount * $taxRate / 100, $precision);
            $total     = $this->roundTax($subtotal + $taxAmount, $precision);

            return [
                'subtotal'     => $subtotal,
                'tax_rate'     => $taxRate,
                'tax_amount'   => $taxAmount,
                'total_amount' => $total,
                'tax_type'     => $taxType,
                'tax_included' => false,
            ];
        }

        // INCLUSIVE: Total = amount, DPP = Total / (1 + rate%)
        $total     = $this->roundTax($amount, $precision);
        $subtotal  = $this->roundTax($amount / (1 + $taxRate / 100), $precision);
        $taxAmount = $this->roundTax($total - $subtotal, $precision);

        return [
            'subtotal'     => $subtotal,
            'tax_rate'     => $taxRate,
            'tax_amount'   => $taxAmount,
            'total_amount' => $total,
            'tax_type'     => $taxType,
            'tax_included' => true,
        ];
    }

    /**
     * Cek apakah pajak sekarang aktif (via config).
     */
    public function isTaxEnabled(): bool
    {
        return (bool) config('tax.enable_tax', true);
    }

    /**
     * Ambil info perusahaan (seller) dari config.
     *
     * @return array{name: string, npwp: string, address: string, phone: string, email: string}
     */
    public function getCompanyInfo(): array
    {
        return [
            'name'    => config('tax.company_name', 'PT Talkabiz Teknologi Indonesia'),
            'npwp'    => config('tax.company_npwp', '00.000.000.0-000.000'),
            'address' => config('tax.company_address', 'Indonesia'),
            'phone'   => config('tax.company_phone', '-'),
            'email'   => config('tax.company_email', 'billing@talkabiz.com'),
        ];
    }

    /**
     * Round sesuai presisi & mode konfigurasi.
     */
    protected function roundTax(float $value, int $precision): float
    {
        $mode = config('tax.rounding_mode', 'round');

        return match ($mode) {
            'floor' => floor($value * pow(10, $precision)) / pow(10, $precision),
            'ceil'  => ceil($value * pow(10, $precision)) / pow(10, $precision),
            default => round($value, $precision),
        };
    }
}
