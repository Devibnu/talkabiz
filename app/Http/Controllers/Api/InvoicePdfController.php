<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceAuditLog;
use App\Services\InvoicePdfService;
use App\Services\TaxCalculationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

/**
 * Invoice PDF & Tax API Controller
 * 
 * Handles invoice tax calculations and PDF generation with strict immutability:
 * - ✅ Tax calculation only for unpaid invoices
 * - ✅ PDF generation only for paid invoices
 * - ❌ NO modifications after payment
 * - ✅ Complete audit trail
 * 
 * Endpoints:
 * - Invoice tax calculation
 * - PDF generation & download
 * - Tax validation & integrity checks
 * - Invoice status management
 */
class InvoicePdfController extends Controller
{
    private InvoicePdfService $pdfService;
    private TaxCalculationService $taxService;

    public function __construct(InvoicePdfService $pdfService, TaxCalculationService $taxService)
    {
        $this->middleware('auth:sanctum');
        $this->pdfService = $pdfService;
        $this->taxService = $taxService;
    }

    // ==================== TAX CALCULATION ====================

    /**
     * Calculate tax for invoice (only for unpaid invoices)
     */
    public function calculateTax(Invoice $invoice, Request $request): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        try {
            // IMMUTABILITY CHECK
            if ($invoice->is_immutable) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot recalculate tax for immutable invoice',
                    'error_type' => 'immutability_violation',
                    'invoice_status' => $invoice->status,
                    'is_locked' => $invoice->is_locked
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'force_recalculation' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $forceRecalculation = $request->boolean('force_recalculation', false);

            // Calculate tax and create snapshot
            $taxSnapshot = $this->taxService->calculateAndSnapshot($invoice, $forceRecalculation);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice_id' => $invoice->id,
                    'tax_snapshot' => $taxSnapshot,
                    'tax_breakdown' => $invoice->fresh()->getTaxBreakdownFromSnapshot(),
                    'calculation_timestamp' => $taxSnapshot['calculation_timestamp'],
                    'immutable_after_payment' => true
                ],
                'message' => 'Tax calculated and snapshot created successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'tax_calculation_error'
            ], 422);
        }
    }

    /**
     * Mark invoice as paid and lock it
     */
    public function markPaidAndLock(Invoice $invoice, Request $request): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        try {
            $validator = Validator::make($request->all(), [
                'payment_method' => 'nullable|string|max:100',
                'payment_channel' => 'nullable|string|max:100',
                'payment_reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $metadata = [
                'payment_reference' => $request->payment_reference,
                'notes' => $request->notes,
                'marked_by' => auth()->id(),
                'marked_at' => now()->toISOString()
            ];

            // Mark as paid and lock
            $invoice = $invoice->markPaidAndLock(
                $request->payment_method,
                $request->payment_channel,
                $metadata
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice_id' => $invoice->id,
                    'status' => $invoice->status,
                    'is_locked' => $invoice->is_locked,
                    'locked_at' => $invoice->locked_at,
                    'paid_at' => $invoice->paid_at,
                    'tax_snapshot_preserved' => !empty($invoice->tax_snapshot),
                    'ready_for_pdf' => true
                ],
                'message' => 'Invoice marked as paid and locked successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'payment_processing_error'
            ], 422);
        }
    }

    // ==================== PDF GENERATION ====================

    /**
     * Generate PDF for paid invoice
     */
    public function generatePdf(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        try {
            // Generate PDF (will validate payment status and tax snapshot)
            $pdfPath = $this->pdfService->generatePdf($invoice);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice_id' => $invoice->id,
                    'pdf_path' => $pdfPath,
                    'pdf_generated_at' => $invoice->fresh()->pdf_generated_at,
                    'download_url' => $this->pdfService->getPdfDownloadUrl($invoice->fresh()),
                    'file_size' => \Storage::size($pdfPath),
                    'integrity_hash' => $invoice->fresh()->pdf_hash
                ],
                'message' => 'PDF generated successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'pdf_generation_error'
            ], 422);
        }
    }

    /**
     * Download invoice PDF
     */
    public function downloadPdf(Invoice $invoice)
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            abort(403, 'Access denied');
        }

        try {
            return $this->pdfService->downloadPdf($invoice);
        } catch (Exception $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Stream/view invoice PDF
     */
    public function viewPdf(Invoice $invoice)
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            abort(403, 'Access denied');
        }

        try {
            return $this->pdfService->stream($invoice);
        } catch (Exception $e) {
            abort(422, $e->getMessage());
        }
    }

    /**
     * Get PDF download URL
     */
    public function getPdfUrl(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        try {
            $url = $this->pdfService->getPdfDownloadUrl($invoice);

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $url,
                    'expires_at' => now()->addHour()->toISOString(),
                    'invoice_number' => $invoice->formatted_invoice_number
                ],
                'message' => 'PDF download URL generated successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'url_generation_error'
            ], 422);
        }
    }

    /**
     * Regenerate PDF (for corrupted files)
     */
    public function regeneratePdf(Invoice $invoice, Request $request): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $pdfPath = $this->pdfService->regeneratePdf($invoice, $request->reason);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice_id' => $invoice->id,
                    'new_pdf_path' => $pdfPath,
                    'regenerated_at' => now()->toISOString(),
                    'reason' => $request->reason,
                    'download_url' => $this->pdfService->getPdfDownloadUrl($invoice->fresh())
                ],
                'message' => 'PDF regenerated successfully'
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => 'pdf_regeneration_error'
            ], 422);
        }
    }

    // ==================== VALIDATION & INTEGRITY ====================

    /**
     * Validate invoice tax integrity
     */
    public function validateTaxIntegrity(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        $validation = $this->taxService->validateTaxIntegrity($invoice);

        return response()->json([
            'success' => true,
            'data' => $validation,
            'message' => $validation['is_valid'] ? 'Tax integrity validated' : 'Tax integrity issues found'
        ]);
    }

    /**
     * Verify PDF integrity
     */
    public function verifyPdfIntegrity(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        $verification = $this->pdfService->verifyPdfIntegrity($invoice);

        return response()->json([
            'success' => true,
            'data' => $verification,
            'message' => $verification['is_valid'] ? 'PDF integrity verified' : 'PDF integrity issues detected'
        ]);
    }

    /**
     * Get invoice compliance status
     */
    public function getComplianceStatus(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        $taxValidation = $this->taxService->validateTaxIntegrity($invoice);
        $pdfValidation = $this->pdfService->verifyPdfIntegrity($invoice);
        $integrityIssues = $invoice->validateIntegrity();

        $complianceStatus = [
            'is_compliant' => $taxValidation['is_valid'] && 
                            $pdfValidation['is_valid'] && 
                            empty($integrityIssues),
            'tax_integrity' => $taxValidation,
            'pdf_integrity' => $pdfValidation,
            'general_integrity' => [
                'is_valid' => empty($integrityIssues),
                'issues' => $integrityIssues
            ],
            'status_summary' => [
                'status' => $invoice->status,
                'is_paid' => $invoice->is_paid,
                'is_locked' => $invoice->is_locked,
                'has_tax_snapshot' => !empty($invoice->tax_snapshot),
                'has_pdf' => !empty($invoice->pdf_path),
                'locked_at' => $invoice->locked_at,
                'paid_at' => $invoice->paid_at
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $complianceStatus,
            'message' => 'Compliance status retrieved successfully'
        ]);
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * Bulk generate PDFs for multiple invoices
     */
    public function bulkGeneratePdf(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invoice_ids' => 'required|array|min:1|max:50',
            'invoice_ids.*' => 'integer|exists:invoices,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $invoices = Invoice::whereIn('id', $request->invoice_ids)
            ->where('user_id', auth()->id())
            ->get();

        $results = [
            'successful' => [],
            'failed' => []
        ];

        foreach ($invoices as $invoice) {
            try {
                if (!$invoice->is_paid) {
                    throw new Exception('Invoice must be paid');
                }

                $pdfPath = $this->pdfService->generatePdf($invoice);
                
                $results['successful'][] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->formatted_invoice_number,
                    'pdf_path' => $pdfPath
                ];

            } catch (Exception $e) {
                $results['failed'][] = [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->formatted_invoice_number ?? $invoice->id,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results,
            'summary' => [
                'total_requested' => count($request->invoice_ids),
                'successful_count' => count($results['successful']),
                'failed_count' => count($results['failed'])
            ],
            'message' => 'Bulk PDF generation completed'
        ]);
    }

    /**
     * Get invoice audit trail
     */
    public function getAuditTrail(Invoice $invoice): JsonResponse
    {
        // Authorization check
        if ($invoice->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied'
            ], 403);
        }

        $auditLogs = InvoiceAuditLog::where('invoice_id', $invoice->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_id' => $invoice->id,
                'audit_logs' => $auditLogs,
                'total_events' => $auditLogs->count(),
                'first_event' => $auditLogs->last()?->created_at,
                'last_event' => $auditLogs->first()?->created_at
            ],
            'message' => 'Audit trail retrieved successfully'
        ]);
    }

    /**
     * Emergency unlock invoice (admin only, with strong justification)
     */
    public function emergencyUnlock(Invoice $invoice, Request $request): JsonResponse
    {
        // This should be heavily restricted and logged
        // Only for extreme cases with proper authorization
        
        return response()->json([
            'success' => false,
            'message' => 'Emergency unlock is not implemented. Contact system administrator.',
            'note' => 'Invoice immutability is a core compliance requirement.'
        ], 501);
    }
}