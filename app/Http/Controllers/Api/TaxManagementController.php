<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxSetting;
use App\Models\CompanyProfile;
use App\Models\TaxRule;
use App\Models\ClientTaxConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

/**
 * Tax Management API Controller
 * 
 * Manages tax settings, company profiles, and client-specific configurations
 * for Indonesian tax compliance (PPN, PPh, PKP status)
 * 
 * Endpoints:
 * - Tax settings (global & user-specific)
 * - Company profiles with PKP/NPWP
 * - Client tax configurations
 * - Tax rules management
 */
class TaxManagementController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    // ==================== TAX SETTINGS ====================

    /**
     * Get user's tax settings
     */
    public function getTaxSettings(): JsonResponse
    {
        $settings = TaxSetting::getTaxCalculationSettings(auth()->id());
        
        return response()->json([
            'success' => true,
            'data' => $settings,
            'message' => 'Tax settings retrieved successfully'
        ]);
    }

    /**
     * Update user's tax settings
     */
    public function updateTaxSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'default_tax_calculation' => 'required|in:inclusive,exclusive',
            'enable_auto_tax' => 'boolean',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
            'enable_pph_calculation' => 'boolean',
            'default_pph_rate' => 'nullable|numeric|min:0|max:100',
            'enable_tax_rounding' => 'boolean',
            'tax_rounding_precision' => 'integer|min:0|max:4'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $setting = TaxSetting::where('user_id', auth()->id())
            ->where('setting_type', 'tax_calculation')
            ->first();

        if (!$setting) {
            $setting = TaxSetting::create([
                'user_id' => auth()->id(),
                'setting_type' => 'tax_calculation',
                'setting_value' => $request->all()
            ]);
        } else {
            $setting->update([
                'setting_value' => array_merge(
                    $setting->setting_value,
                    $request->all()
                )
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $setting->fresh(),
            'message' => 'Tax settings updated successfully'
        ]);
    }

    // ==================== COMPANY PROFILE ====================

    /**
     * Get user's company profile
     */
    public function getCompanyProfile(): JsonResponse
    {
        $profile = CompanyProfile::where('user_id', auth()->id())->first();
        
        if (!$profile) {
            $profile = CompanyProfile::getOrCreateForUser(auth()->id());
        }
        
        return response()->json([
            'success' => true,
            'data' => $profile,
            'message' => 'Company profile retrieved successfully'
        ]);
    }

    /**
     * Update company profile
     */
    public function updateCompanyProfile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'postal_code' => 'required|string|max:10',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'npwp' => 'nullable|string|size:15|regex:/^[0-9./-]+$/',
            'is_pkp' => 'boolean',
            'pkp_number' => 'nullable|required_if:is_pkp,true|string|max:50',
            'business_field' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate NPWP format if provided
        if ($request->npwp && !$this->isValidNpwp($request->npwp)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid NPWP format. Use format: XX.XXX.XXX.X-XXX.XXX',
                'errors' => ['npwp' => ['Invalid NPWP format']]
            ], 422);
        }

        $profile = CompanyProfile::updateOrCreate(
            ['user_id' => auth()->id()],
            $request->all()
        );

        return response()->json([
            'success' => true,
            'data' => $profile->fresh(),
            'message' => 'Company profile updated successfully'
        ]);
    }

    // ==================== CLIENT TAX CONFIGURATION ====================

    /**
     * Get client tax configurations
     */
    public function getClientTaxConfigurations(): JsonResponse
    {
        $configurations = ClientTaxConfiguration::where('user_id', auth()->id())
            ->with('client:id,name,email')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $configurations,
            'message' => 'Client tax configurations retrieved successfully'
        ]);
    }

    /**
     * Update client tax configuration
     */
    public function updateClientTaxConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|integer|exists:klien,id',
            'override_global_settings' => 'boolean',
            'tax_exempt' => 'boolean',
            'custom_tax_rate' => 'nullable|numeric|min:0|max:100',
            'withholding_tax_rate' => 'nullable|numeric|min:0|max:100',
            'notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verify client belongs to user
        $client = \App\Models\Klien::where('id', $request->client_id)
            ->where('user_id', auth()->id())
            ->first();

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client not found or access denied'
            ], 404);
        }

        $configuration = ClientTaxConfiguration::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'client_id' => $request->client_id
            ],
            $request->all()
        );

        return response()->json([
            'success' => true,
            'data' => $configuration->fresh(['client']),
            'message' => 'Client tax configuration updated successfully'
        ]);
    }

    /**
     * Delete client tax configuration
     */
    public function deleteClientTaxConfiguration(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_id' => 'required|integer|exists:klien,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $deleted = ClientTaxConfiguration::where('user_id', auth()->id())
            ->where('client_id', $request->client_id)
            ->delete();

        if ($deleted) {
            return response()->json([
                'success' => true,
                'message' => 'Client tax configuration deleted successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Configuration not found'
        ], 404);
    }

    // ==================== TAX RULES ====================

    /**
     * Get available tax rules
     */
    public function getTaxRules(): JsonResponse
    {
        $rules = TaxRule::where('is_active', true)
            ->orderBy('tax_type')
            ->orderBy('rate')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $rules,
            'message' => 'Tax rules retrieved successfully'
        ]);
    }

    /**
     * Get default tax breakdown preview
     */
    public function getDefaultTaxBreakdown(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'client_id' => 'nullable|integer|exists:klien,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $amount = $request->amount;
        $clientId = $request->client_id;

        // Get tax settings
        $taxSettings = TaxSetting::getTaxCalculationSettings(auth()->id());
        
        // Get client-specific settings if available
        $clientConfig = null;
        if ($clientId) {
            $clientConfig = ClientTaxConfiguration::where('user_id', auth()->id())
                ->where('client_id', $clientId)
                ->first();
        }

        // Calculate tax
        $breakdown = $this->calculateTaxBreakdown($amount, $taxSettings, $clientConfig);
        
        return response()->json([
            'success' => true,
            'data' => $breakdown,
            'message' => 'Tax breakdown calculated successfully'
        ]);
    }

    // ==================== UTILITY ENDPOINTS ====================

    /**
     * Validate NPWP format
     */
    public function validateNpwp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'npwp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $isValid = $this->isValidNpwp($request->npwp);
        $formatted = $isValid ? $this->formatNpwp($request->npwp) : null;
        
        return response()->json([
            'success' => true,
            'data' => [
                'is_valid' => $isValid,
                'formatted' => $formatted,
                'input' => $request->npwp
            ],
            'message' => $isValid ? 'NPWP is valid' : 'NPWP format is invalid'
        ]);
    }

    /**
     * Get tax compliance summary
     */
    public function getTaxComplianceSummary(): JsonResponse
    {
        $companyProfile = CompanyProfile::where('user_id', auth()->id())->first();
        $taxSettings = TaxSetting::getTaxCalculationSettings(auth()->id());
        
        // Count invoices by tax status
        $invoiceStats = DB::table('invoices')
            ->where('user_id', auth()->id())
            ->selectRaw('
                COUNT(*) as total_invoices,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN tax_snapshot IS NOT NULL THEN 1 ELSE 0 END) as invoices_with_tax_snapshot,
                SUM(CASE WHEN pdf_path IS NOT NULL THEN 1 ELSE 0 END) as invoices_with_pdf,
                SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked_invoices
            ')
            ->first();
        
        $complianceScore = $this->calculateComplianceScore($companyProfile, $taxSettings, $invoiceStats);
        
        return response()->json([
            'success' => true,
            'data' => [
                'company_profile' => [
                    'has_npwp' => !empty($companyProfile?->npwp),
                    'is_pkp' => $companyProfile?->is_pkp ?? false,
                    'profile_complete' => $companyProfile ? $companyProfile->isProfileComplete() : false
                ],
                'tax_settings' => [
                    'configured' => !empty($taxSettings),
                    'auto_tax_enabled' => $taxSettings['enable_auto_tax'] ?? false
                ],
                'invoice_statistics' => [
                    'total_invoices' => $invoiceStats->total_invoices,
                    'paid_invoices' => $invoiceStats->paid_invoices,
                    'invoices_with_tax_snapshot' => $invoiceStats->invoices_with_tax_snapshot,
                    'invoices_with_pdf' => $invoiceStats->invoices_with_pdf,
                    'locked_invoices' => $invoiceStats->locked_invoices,
                    'compliance_percentage' => $invoiceStats->total_invoices > 0 
                        ? round(($invoiceStats->invoices_with_tax_snapshot / $invoiceStats->total_invoices) * 100, 2)
                        : 100
                ],
                'compliance_score' => $complianceScore,
                'recommendations' => $this->getComplianceRecommendations($companyProfile, $taxSettings, $invoiceStats)
            ],
            'message' => 'Tax compliance summary retrieved successfully'
        ]);
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Validate NPWP format
     */
    private function isValidNpwp(string $npwp): bool
    {
        // Remove all non-numeric characters for validation
        $numeric = preg_replace('/[^0-9]/', '', $npwp);
        
        // NPWP should be 15 digits
        if (strlen($numeric) !== 15) {
            return false;
        }
        
        // Basic format validation (can be enhanced with checksum)
        return true;
    }

    /**
     * Format NPWP to standard format
     */
    private function formatNpwp(string $npwp): string
    {
        $numeric = preg_replace('/[^0-9]/', '', $npwp);
        
        if (strlen($numeric) === 15) {
            return substr($numeric, 0, 2) . '.' . 
                   substr($numeric, 2, 3) . '.' . 
                   substr($numeric, 5, 3) . '.' . 
                   substr($numeric, 8, 1) . '-' . 
                   substr($numeric, 9, 3) . '.' . 
                   substr($numeric, 12, 3);
        }
        
        return $npwp;
    }

    /**
     * Calculate tax breakdown preview
     */
    private function calculateTaxBreakdown(float $amount, array $taxSettings, ?ClientTaxConfiguration $clientConfig): array
    {
        // Check for tax exemption
        if ($clientConfig && $clientConfig->tax_exempt) {
            return [
                'subtotal' => $amount,
                'tax_amount' => 0,
                'total' => $amount,
                'tax_rate' => 0,
                'exempt_reason' => 'Client is tax exempt'
            ];
        }

        // Get tax rate
        $taxRate = $clientConfig?->custom_tax_rate ?? 
                  $taxSettings['default_tax_rate'] ?? 
                  11; // Default PPN rate

        $calculation = $taxSettings['default_tax_calculation'] ?? 'exclusive';
        
        if ($calculation === 'inclusive') {
            $taxAmount = $amount * $taxRate / (100 + $taxRate);
            $subtotal = $amount - $taxAmount;
        } else {
            $subtotal = $amount;
            $taxAmount = $amount * $taxRate / 100;
        }
        
        return [
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total' => round($subtotal + $taxAmount, 2),
            'tax_rate' => $taxRate,
            'calculation_method' => $calculation
        ];
    }

    /**
     * Calculate compliance score (0-100)
     */
    private function calculateComplianceScore(?CompanyProfile $profile, array $settings, object $invoiceStats): int
    {
        $score = 0;
        
        // Company profile completeness (30 points)
        if ($profile) {
            $score += $profile->isProfileComplete() ? 30 : ($profile->company_name ? 15 : 0);
        }
        
        // Tax settings configured (20 points)
        $score += !empty($settings) ? 20 : 0;
        
        // Invoice compliance (50 points)
        if ($invoiceStats->total_invoices > 0) {
            $complianceRate = $invoiceStats->invoices_with_tax_snapshot / $invoiceStats->total_invoices;
            $score += round($complianceRate * 50);
        } else {
            $score += 50; // No invoices yet, full compliance
        }
        
        return min(100, $score);
    }

    /**
     * Get compliance recommendations
     */
    private function getComplianceRecommendations(?CompanyProfile $profile, array $settings, object $invoiceStats): array
    {
        $recommendations = [];
        
        if (!$profile || !$profile->isProfileComplete()) {
            $recommendations[] = [
                'type' => 'company_profile',
                'message' => 'Complete your company profile with NPWP and PKP information',
                'action' => 'update_company_profile',
                'priority' => 'high'
            ];
        }
        
        if (empty($settings)) {
            $recommendations[] = [
                'type' => 'tax_settings',
                'message' => 'Configure your tax calculation settings',
                'action' => 'setup_tax_settings',
                'priority' => 'medium'
            ];
        }
        
        if ($invoiceStats->paid_invoices > $invoiceStats->invoices_with_tax_snapshot) {
            $recommendations[] = [
                'type' => 'invoice_compliance',
                'message' => 'Some paid invoices are missing tax snapshots',
                'action' => 'review_invoices',
                'priority' => 'high'
            ];
        }
        
        return $recommendations;
    }
}