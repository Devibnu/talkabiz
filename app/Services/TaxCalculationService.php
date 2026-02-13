<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\TaxRule;
use App\Models\TaxSetting;
use App\Models\CompanyProfile;
use App\Models\ClientTaxConfiguration;
use App\Models\InvoiceAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Exception;

/**
 * CRITICAL SERVICE: Tax Calculation & Immutability Enforcement
 * 
 * This service manages tax calculations with strict immutability rules:
 * - ❌ NO tax recalculation after invoice status = PAID
 * - ✅ Tax snapshot required before payment
 * - ✅ All tax data stored as immutable JSON snapshot
 * - ✅ Full audit trail for compliance
 * 
 * Indonesian Tax Compliance:
 * - PPN (VAT) 11% default rate
 * - PPh 21/23 withholding tax support
 * - PKP (Pengusaha Kena Pajak) status handling
 * - NPWP validation and formatting
 */
class TaxCalculationService
{
    /**
     * Calculate tax for invoice and create immutable snapshot
     * 
     * @param Invoice $invoice The invoice to calculate tax for
     * @param bool $forceRecalculation Force recalculation (only for non-paid invoices)
     * @return array Tax snapshot with calculation details
     * @throws Exception If invoice is immutable or calculation fails
     */
    public function calculateAndSnapshot(Invoice $invoice, bool $forceRecalculation = false): array
    {
        // IMMUTABILITY CHECK - CRITICAL
        if ($invoice->is_immutable && !$forceRecalculation) {
            throw new Exception(
                "IMMUTABILITY VIOLATION: Cannot recalculate tax for {$invoice->status} invoice ID {$invoice->id}. " .
                "Invoice is locked and immutable. Use existing tax_snapshot data only."
            );
        }

        if ($invoice->is_paid) {
            throw new Exception(
                "PAYMENT INTEGRITY ERROR: Cannot modify tax calculation for paid invoice ID {$invoice->id}. " .
                "Tax calculations must be finalized before payment processing."
            );
        }

        return DB::transaction(function () use ($invoice, $forceRecalculation) {
            // Get or create company profile
            $companyProfile = $this->getCompanyProfile($invoice->user_id);
            
            // Get tax settings for user
            $taxSettings = $this->getTaxSettings($invoice->user_id, $invoice->klien_id);
            
            // Get applicable tax rules
            $taxRules = $this->getApplicableTaxRules($invoice, $taxSettings);
            
            // Calculate base amounts
            $baseCalculation = $this->calculateBaseAmounts($invoice);
            
            // Apply tax rules
            $taxCalculation = $this->applyTaxRules($baseCalculation, $taxRules, $taxSettings);
            
            // Create immutable snapshot
            $snapshot = $this->createTaxSnapshot(
                $invoice,
                $baseCalculation,
                $taxCalculation,
                $taxRules,
                $taxSettings,
                $companyProfile
            );
            
            // Update invoice with calculated values
            $this->updateInvoiceWithTaxData($invoice, $snapshot, $companyProfile);
            
            // Log the calculation for audit trail
            $this->logTaxCalculation($invoice, $snapshot, $forceRecalculation);
            
            return $snapshot;
        });
    }

    /**
     * Validate tax calculation integrity
     * 
     * @param Invoice $invoice
     * @return array Validation results
     */
    public function validateTaxIntegrity(Invoice $invoice): array
    {
        $issues = [];
        
        // Check if paid invoice has required tax data
        if ($invoice->is_paid) {
            if (!$invoice->tax_snapshot) {
                $issues[] = 'CRITICAL: Paid invoice missing required tax snapshot';
            }
            
            if (!$invoice->is_locked) {
                $issues[] = 'CRITICAL: Paid invoice should be locked for immutability';
            }
        }
        
        // Validate snapshot integrity
        if ($invoice->tax_snapshot) {
            $snapshotIssues = $this->validateSnapshotStructure($invoice->tax_snapshot);
            $issues = array_merge($issues, $snapshotIssues);
        }
        
        // Check calculation consistency
        if ($invoice->tax_amount && $invoice->tax_snapshot) {
            $snapshotTaxAmount = $invoice->tax_snapshot['amounts']['tax_amount'] ?? 0;
            if (abs($invoice->tax_amount - $snapshotTaxAmount) > 0.01) {
                $issues[] = 'WARNING: Tax amount mismatch between invoice field and snapshot';
            }
        }
        
        return [
            'is_valid' => empty($issues),
            'issues' => $issues,
            'checked_at' => now()->toISOString(),
            'invoice_status' => $invoice->status,
            'is_locked' => $invoice->is_locked
        ];
    }

    /**
     * Get tax breakdown from snapshot (read-only)
     */
    public function getTaxBreakdownFromSnapshot(Invoice $invoice): array
    {
        if (!$invoice->tax_snapshot) {
            throw new Exception("No tax snapshot available for invoice ID {$invoice->id}");
        }
        
        $snapshot = $invoice->tax_snapshot;
        
        return [
            'subtotal' => $snapshot['amounts']['subtotal'],
            'discount_amount' => $snapshot['amounts']['discount_amount'] ?? 0,
            'taxable_amount' => $snapshot['amounts']['subtotal'] - ($snapshot['amounts']['discount_amount'] ?? 0),
            'tax_calculations' => $snapshot['tax_details']['calculations'] ?? [],
            'total_tax_amount' => $snapshot['amounts']['tax_amount'],
            'admin_fee' => $snapshot['amounts']['admin_fee'] ?? 0,
            'grand_total' => $snapshot['amounts']['total_calculated'],
            'tax_rules_applied' => $snapshot['tax_rule'],
            'calculation_timestamp' => $snapshot['calculation_timestamp'],
            'company_info' => $snapshot['company_info'],
            'client_info' => $snapshot['client_info'],
            'is_snapshot' => true
        ];
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Get or create company profile for user
     */
    private function getCompanyProfile(int $userId): CompanyProfile
    {
        return CompanyProfile::getOrCreateForUser($userId);
    }

    /**
     * Get tax settings for user and client
     */
    private function getTaxSettings(int $userId, ?int $klienId = null): array
    {
        $globalSettings = TaxSetting::getTaxCalculationSettings($userId);
        
        $clientSpecific = [];
        if ($klienId) {
            $clientConfig = ClientTaxConfiguration::where('user_id', $userId)
                ->where('client_id', $klienId)
                ->first();
                
            if ($clientConfig) {
                $clientSpecific = [
                    'override_global' => $clientConfig->override_global_settings,
                    'tax_exempt' => $clientConfig->tax_exempt,
                    'custom_tax_rate' => $clientConfig->custom_tax_rate,
                    'withholding_tax_rate' => $clientConfig->withholding_tax_rate,
                    'notes' => $clientConfig->notes
                ];
            }
        }
        
        return [
            'global' => $globalSettings,
            'client_specific' => $clientSpecific,
            'effective_settings' => array_merge($globalSettings, $clientSpecific)
        ];
    }

    /**
     * Get applicable tax rules based on invoice and settings
     */
    private function getApplicableTaxRules(Invoice $invoice, array $taxSettings): array
    {
        $rules = [];
        
        // Check for client-specific tax exemption
        if ($taxSettings['client_specific']['tax_exempt'] ?? false) {
            return []; // No tax rules if client is exempt
        }
        
        // Get default PPN rule
        $ppnRule = TaxRule::getDefaultPpn();
        if ($ppnRule) {
            $rules['ppn'] = $ppnRule;
        }
        
        // Add withholding tax if applicable
        $withholdingRate = $taxSettings['client_specific']['withholding_tax_rate'] ?? null;
        if ($withholdingRate) {
            $withholdingRule = TaxRule::where('tax_type', 'pph')
                ->where('rate', $withholdingRate)
                ->first();
            
            if ($withholdingRule) {
                $rules['pph'] = $withholdingRule;
            }
        }
        
        return $rules;
    }

    /**
     * Calculate base amounts before tax
     */
    private function calculateBaseAmounts(Invoice $invoice): array
    {
        $subtotal = $invoice->subtotal ?: 0;
        $discountAmount = $invoice->discount_amount ?: 0;
        $adminFee = $invoice->admin_fee ?: 0;
        
        return [
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'taxable_amount' => $subtotal - $discountAmount,
            'admin_fee' => $adminFee
        ];
    }

    /**
     * Apply tax rules to base calculation
     */
    private function applyTaxRules(array $baseCalculation, array $taxRules, array $taxSettings): array
    {
        $calculations = [];
        $totalTaxAmount = 0;
        
        foreach ($taxRules as $taxType => $rule) {
            $taxCalculation = $rule->calculateTax($baseCalculation['taxable_amount']);
            $calculations[$taxType] = $taxCalculation;
            $totalTaxAmount += $taxCalculation['tax_amount'];
        }
        
        $grandTotal = $baseCalculation['taxable_amount'] + $totalTaxAmount + $baseCalculation['admin_fee'];
        
        return [
            'calculations' => $calculations,
            'total_tax_amount' => $totalTaxAmount,
            'grand_total' => $grandTotal
        ];
    }

    /**
     * Create immutable tax snapshot
     */
    private function createTaxSnapshot(
        Invoice $invoice,
        array $baseCalculation,
        array $taxCalculation,
        array $taxRules,
        array $taxSettings,
        CompanyProfile $companyProfile
    ): array {
        return [
            'calculation_timestamp' => now()->toISOString(),
            'snapshot_version' => '1.0',
            'invoice_id' => $invoice->id,
            'immutable_after_payment' => true,
            
            // Base amounts
            'amounts' => [
                'subtotal' => $baseCalculation['subtotal'],
                'discount_amount' => $baseCalculation['discount_amount'],
                'taxable_amount' => $baseCalculation['taxable_amount'],
                'tax_amount' => $taxCalculation['total_tax_amount'],
                'admin_fee' => $baseCalculation['admin_fee'],
                'total_calculated' => $taxCalculation['grand_total']
            ],
            
            // Tax calculation details
            'tax_details' => $taxCalculation,
            
            // Applied tax rules
            'tax_rules_applied' => array_map(function($rule) {
                return [
                    'rule_code' => $rule->rule_code,
                    'rule_name' => $rule->rule_name,
                    'tax_type' => $rule->tax_type,
                    'rate' => $rule->rate,
                    'is_inclusive' => $rule->is_inclusive
                ];
            }, $taxRules),
            
            // Company information at time of calculation
            'company_info' => [
                'company_name' => $companyProfile->company_name,
                'npwp' => $companyProfile->npwp,
                'is_pkp' => $companyProfile->is_pkp,
                'address' => $companyProfile->formatted_address,
                'phone' => $companyProfile->phone,
                'email' => $companyProfile->email
            ],
            
            // Client information
            'client_info' => [
                'client_name' => $invoice->klien->nama_perusahaan ?? $invoice->klien->name,
                'client_email' => $invoice->klien->email,
                'npwp' => $invoice->klien->npwp ?? null,
                'address' => $invoice->klien->alamat ?? null
            ],
            
            // Settings used for calculation
            'settings_snapshot' => $taxSettings,
            
            // Metadata
            'calculated_by' => auth()->id(),
            'calculation_source' => 'TaxCalculationService',
            'compliance_notes' => [
                'indonesian_tax_rules_applied' => true,
                'snapshot_immutable_after_payment' => true,
                'audit_trail_required' => true
            ]
        ];
    }

    /**
     * Update invoice with calculated tax data
     */
    private function updateInvoiceWithTaxData(Invoice $invoice, array $snapshot, CompanyProfile $companyProfile): void
    {
        $primaryTaxRule = collect($snapshot['tax_rules_applied'])->firstWhere('tax_type', 'ppn');
        
        $updateData = [
            'tax_amount' => $snapshot['amounts']['tax_amount'],
            'total_calculated' => $snapshot['amounts']['total_calculated'],
            'tax_snapshot' => $snapshot,
            'company_profile_id' => $companyProfile->id,
            'requires_tax_invoice' => $companyProfile->is_pkp,
            'tax_status' => 'calculated'
        ];
        
        if ($primaryTaxRule) {
            $updateData['tax_rate'] = $primaryTaxRule['rate'];
        }
        
        // Generate formatted invoice number if company is PKP
        if ($companyProfile->is_pkp) {
            $updateData['formatted_invoice_number'] = $companyProfile->generateInvoiceNumber();
        }
        
        $invoice->update($updateData);
    }

    /**
     * Log tax calculation for audit trail
     */
    private function logTaxCalculation(Invoice $invoice, array $snapshot, bool $wasRecalculation): void
    {
        InvoiceAuditLog::logEvent(
            $invoice->id,
            $wasRecalculation ? 'tax_recalculated' : 'tax_calculated',
            null,
            [
                'tax_amount' => $snapshot['amounts']['tax_amount'],
                'total_calculated' => $snapshot['amounts']['total_calculated'],
                'calculation_timestamp' => $snapshot['calculation_timestamp']
            ],
            [
                'was_recalculation' => $wasRecalculation,
                'tax_rules_count' => count($snapshot['tax_rules_applied']),
                'service' => 'TaxCalculationService'
            ]
        );
    }

    /**
     * Validate snapshot structure
     */
    private function validateSnapshotStructure(array $snapshot): array
    {
        $issues = [];
        
        $requiredFields = [
            'calculation_timestamp',
            'amounts',
            'tax_details',
            'tax_rules_applied',
            'company_info',
            'client_info'
        ];
        
        foreach ($requiredFields as $field) {
            if (!isset($snapshot[$field])) {
                $issues[] = "Missing required snapshot field: {$field}";
            }
        }
        
        // Validate amounts structure
        if (isset($snapshot['amounts'])) {
            $requiredAmounts = ['subtotal', 'tax_amount', 'total_calculated'];
            foreach ($requiredAmounts as $amount) {
                if (!isset($snapshot['amounts'][$amount])) {
                    $issues[] = "Missing required amount field: {$amount}";
                }
            }
        }
        
        return $issues;
    }
}