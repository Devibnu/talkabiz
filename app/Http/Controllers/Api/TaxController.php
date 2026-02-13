<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientTaxProfile;
use App\Models\Invoice;
use App\Models\Klien;
use App\Models\TaxSettings;
use App\Services\TaxService;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * TaxController
 * 
 * API endpoints untuk manajemen pajak:
 * - Client tax profile CRUD
 * - Tax settings management (owner only)
 * - Tax calculation preview
 * - Invoice PDF generation
 * - Tax summary reporting
 * 
 * @author Senior Tax Compliance Architect
 */
class TaxController extends Controller
{
    protected TaxService $taxService;
    protected InvoicePdfService $pdfService;

    public function __construct(TaxService $taxService, InvoicePdfService $pdfService)
    {
        $this->taxService = $taxService;
        $this->pdfService = $pdfService;
    }

    // ==================== CLIENT TAX PROFILE ====================

    /**
     * Get client's tax profile
     * 
     * GET /api/tax/profile
     */
    public function getProfile(Request $request): JsonResponse
    {
        $klien = $this->getKlienFromRequest($request);
        
        if (!$klien) {
            return response()->json(['error' => 'Client not found'], 404);
        }

        $profile = $klien->taxProfile;
        
        if (!$profile) {
            return response()->json([
                'exists' => false,
                'message' => 'Tax profile belum diisi',
            ]);
        }

        return response()->json([
            'exists' => true,
            'profile' => $profile,
            'validation' => $this->taxService->validateTaxProfile($klien),
        ]);
    }

    /**
     * Create or update client's tax profile
     * 
     * POST /api/tax/profile
     */
    public function saveProfile(Request $request): JsonResponse
    {
        $klien = $this->getKlienFromRequest($request);
        
        if (!$klien) {
            return response()->json(['error' => 'Client not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'entity_name' => 'nullable|string|max:255',
            'entity_address' => 'nullable|string|max:500',
            'npwp' => 'nullable|string|max:20',
            'npwp_name' => 'nullable|string|max:255',
            'npwp_address' => 'nullable|string|max:500',
            'is_pkp' => 'nullable|boolean',
            'pkp_number' => 'nullable|string|max:50',
            'pkp_registered_at' => 'nullable|date',
            'pkp_expired_at' => 'nullable|date',
            'tax_exempt' => 'nullable|boolean',
            'tax_exempt_reason' => 'nullable|string|max:255',
            'custom_tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_contact_name' => 'nullable|string|max:100',
            'tax_contact_email' => 'nullable|email|max:100',
            'tax_contact_phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile = ClientTaxProfile::updateOrCreate(
            ['klien_id' => $klien->id],
            $validator->validated()
        );

        // Validate after save
        $validation = $this->taxService->validateTaxProfile($klien->fresh());

        return response()->json([
            'success' => true,
            'profile' => $profile,
            'validation' => $validation,
        ]);
    }

    /**
     * Get all client tax profiles (owner only)
     * 
     * GET /api/tax/profiles
     */
    public function listProfiles(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);
        $status = $request->get('status'); // pending, verified, rejected
        $pkpOnly = $request->boolean('pkp_only');

        $query = ClientTaxProfile::with('klien');

        if ($status) {
            $query->where('verification_status', $status);
        }

        if ($pkpOnly) {
            $query->where('is_pkp', true);
        }

        $profiles = $query->paginate($perPage);

        return response()->json($profiles);
    }

    /**
     * Verify client tax profile (owner only)
     * 
     * POST /api/tax/profiles/{id}/verify
     */
    public function verifyProfile(Request $request, int $id): JsonResponse
    {
        $profile = ClientTaxProfile::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:verified,rejected',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profile->update([
            'verification_status' => $request->status,
            'verification_notes' => $request->notes,
            'verified_by' => Auth::id(),
            'verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'profile' => $profile,
        ]);
    }

    // ==================== TAX SETTINGS (OWNER ONLY) ====================

    /**
     * Get tax settings
     * 
     * GET /api/tax/settings
     */
    public function getSettings(): JsonResponse
    {
        $settings = $this->taxService->getSettings();

        if (!$settings) {
            return response()->json([
                'exists' => false,
                'default_ppn_rate' => 11.00,
            ]);
        }

        return response()->json([
            'exists' => true,
            'settings' => $settings,
        ]);
    }

    /**
     * Update tax settings
     * 
     * POST /api/tax/settings
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'company_npwp' => 'required|string|max:20',
            'company_pkp_number' => 'nullable|string|max:50',
            'company_address' => 'required|string|max:500',
            'default_ppn_rate' => 'required|numeric|min:0|max:100',
            'auto_apply_tax' => 'boolean',
            'efaktur_enabled' => 'boolean',
            'efaktur_api_url' => 'nullable|url|max:255',
            'efaktur_api_key' => 'nullable|string',
            'efaktur_prefix' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Deactivate existing settings
        TaxSettings::where('is_active', true)->update(['is_active' => false]);

        // Create new settings
        $settings = TaxSettings::create(array_merge(
            $validator->validated(),
            ['is_active' => true]
        ));

        return response()->json([
            'success' => true,
            'settings' => $settings,
        ]);
    }

    // ==================== TAX CALCULATION ====================

    /**
     * Preview tax calculation
     * 
     * POST /api/tax/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subtotal' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'calculation' => 'nullable|in:exclusive,inclusive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $preview = $this->taxService->preview(
            $request->subtotal,
            $request->discount ?? 0,
            $request->tax_rate,
            $request->calculation ?? 'exclusive'
        );

        return response()->json($preview);
    }

    /**
     * Apply tax to invoice
     * 
     * POST /api/tax/apply/{invoiceId}
     */
    public function applyToInvoice(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoiceId);

        // Verify ownership
        $klien = $this->getKlienFromRequest($request);
        if ($klien && $invoice->klien_id !== $klien->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'nullable|in:ppn,ppn_bm,pph,exempt',
            'calculation' => 'nullable|in:exclusive,inclusive',
            'lock' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $invoice = $this->taxService->calculateAndApplyTax(
                $invoice,
                $request->tax_rate,
                $request->tax_type ?? 'ppn',
                $request->calculation ?? 'exclusive',
                $request->boolean('lock')
            );

            return response()->json([
                'success' => true,
                'invoice' => $invoice,
                'tax_breakdown' => $invoice->getTaxBreakdown(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    // ==================== INVOICE PDF ====================

    /**
     * Get invoice PDF
     * 
     * GET /api/tax/invoice/{invoiceId}/pdf
     */
    public function getInvoicePdf(Request $request, int $invoiceId)
    {
        $invoice = Invoice::findOrFail($invoiceId);

        // Verify ownership
        $klien = $this->getKlienFromRequest($request);
        if ($klien && $invoice->klien_id !== $klien->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $download = $request->boolean('download');

        if ($download) {
            return $this->pdfService->generate($invoice, true);
        }

        return $this->pdfService->stream($invoice);
    }

    /**
     * Regenerate invoice PDF
     * 
     * POST /api/tax/invoice/{invoiceId}/pdf/regenerate
     */
    public function regenerateInvoicePdf(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = Invoice::findOrFail($invoiceId);

        $path = $this->pdfService->regenerate($invoice);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => $this->pdfService->getDownloadUrl($invoice),
        ]);
    }

    // ==================== TAX SUMMARY ====================

    /**
     * Get tax summary for period
     * 
     * GET /api/tax/summary
     */
    public function getSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'klien_id' => 'nullable|integer|exists:klien,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $summary = $this->taxService->getTaxSummary(
            $request->start_date,
            $request->end_date,
            $request->klien_id
        );

        return response()->json($summary);
    }

    /**
     * Get e-Faktur queue status
     * 
     * GET /api/tax/efaktur/queue
     */
    public function getEfakturQueue(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 20);
        $status = $request->get('status');

        $query = \App\Models\EInvoice::with('invoice');

        if ($status) {
            $query->where('status', $status);
        }

        $queue = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($queue);
    }

    // ==================== HELPERS ====================

    /**
     * Get klien from authenticated user
     */
    private function getKlienFromRequest(Request $request): ?Klien
    {
        $user = Auth::user();
        
        // Check if user has klien relationship
        if (method_exists($user, 'klien') && $user->klien) {
            return $user->klien;
        }

        // Check if klien_id is passed
        if ($request->has('klien_id')) {
            return Klien::find($request->klien_id);
        }

        return null;
    }
}
