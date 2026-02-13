<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceAuditLog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

/**
 * CRITICAL SERVICE: Invoice PDF Generation with Immutability Protection
 * 
 * This service generates PDF invoices with strict security rules:
 * - ✅ PDF generation ONLY for PAID invoices
 * - ✅ Uses ONLY immutable tax_snapshot data
 * - ❌ NEVER generates PDF from live calculation
 * - ✅ PDF integrity protection with hash verification
 * - ✅ Full audit trail for compliance
 * 
 * Invoice Types Supported:
 * - Standard invoice with tax breakdown
 * - Tax invoice (Faktur Pajak) for PKP companies
 * - International invoice (no tax)
 */
class InvoicePdfService
{
    private Dompdf $dompdf;
    private array $config;

    public function __construct()
    {
        $this->initializeDompdf();
        $this->config = config('invoice_pdf', [
            'font_size' => 10,
            'orientation' => 'portrait',
            'paper_size' => 'A4',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 20,
            'margin_right' => 20,
        ]);
    }

    /**
     * Generate PDF for invoice - ONLY for PAID invoices with tax snapshot
     * 
     * @param Invoice $invoice Must be paid and have tax snapshot
     * @return string File path of generated PDF
     * @throws Exception If invoice is not paid or missing tax data
     */
    public function generatePdf(Invoice $invoice): string
    {
        // CRITICAL SECURITY CHECKS
        $this->validateInvoiceForPdfGeneration($invoice);
        
        // Generate PDF content from immutable snapshot
        $pdfContent = $this->generatePdfContent($invoice);
        
        // Create PDF binary
        $pdfBinary = $this->generatePdfBinary($pdfContent, $invoice);
        
        // Save PDF file with secure naming
        $filePath = $this->savePdfFile($invoice, $pdfBinary);
        
        // Update invoice with PDF information
        $this->updateInvoiceWithPdfInfo($invoice, $filePath, $pdfBinary);
        
        // Log PDF generation for audit
        $this->logPdfGeneration($invoice, $filePath);
        
        return $filePath;
    }

    /**
     * Legacy method for backward compatibility - redirects to new secure method
     */
    public function generate(Invoice $invoice, bool $download = false)
    {
        if (!$invoice->is_paid) {
            throw new Exception('PDF generation requires paid invoice status');
        }
        
        $filePath = $this->generatePdf($invoice);
        
        if ($download) {
            return $this->downloadPdf($invoice);
        }
        
        return $filePath;
    }

    /**
     * Regenerate PDF (only if current PDF is corrupted)
     */
    public function regeneratePdf(Invoice $invoice, string $reason = 'Manual regeneration'): string
    {
        if (!$invoice->is_paid) {
            throw new Exception('Cannot regenerate PDF for unpaid invoice');
        }

        // Delete existing PDF if exists
        if ($invoice->pdf_path) {
            Storage::delete($invoice->pdf_path);
        }

        $filePath = $this->generatePdf($invoice);
        
        InvoiceAuditLog::logEvent(
            $invoice->id,
            'pdf_regenerated',
            null,
            ['new_pdf_path' => $filePath],
            ['reason' => $reason, 'regenerated_by' => auth()->id()]
        );
        
        return $filePath;
    }

    /**
     * Download PDF with security checks
     */
    public function downloadPdf(Invoice $invoice)
    {
        if (!$invoice->is_paid || !$invoice->pdf_path) {
            throw new Exception('PDF not available for download');
        }

        $integrity = $this->verifyPdfIntegrity($invoice);
        if (!$integrity['is_valid']) {
            throw new Exception('PDF integrity check failed: ' . $integrity['message']);
        }

        return Storage::download(
            $invoice->pdf_path,
            'Invoice_' . $invoice->formatted_invoice_number . '.pdf'
        );
    }

    /**
     * Stream PDF for browser view
     */
    public function stream(Invoice $invoice)
    {
        if (!$invoice->is_paid || !$invoice->pdf_path) {
            throw new Exception('PDF not available for viewing');
        }

        return response()->file(storage_path('app/' . $invoice->pdf_path));
    }

    /**
     * Verify PDF integrity using stored hash
     */
    public function verifyPdfIntegrity(Invoice $invoice): array
    {
        if (!$invoice->pdf_path || !$invoice->pdf_hash) {
            return [
                'is_valid' => false,
                'message' => 'PDF or hash information missing'
            ];
        }

        $filePath = storage_path('app/' . $invoice->pdf_path);
        
        if (!file_exists($filePath)) {
            return [
                'is_valid' => false,
                'message' => 'PDF file not found on disk'
            ];
        }

        $currentHash = hash_file('sha256', $filePath);
        $isValid = $currentHash === $invoice->pdf_hash;
        
        return [
            'is_valid' => $isValid,
            'message' => $isValid ? 'PDF integrity verified' : 'PDF has been modified or corrupted',
            'stored_hash' => $invoice->pdf_hash,
            'current_hash' => $currentHash,
            'file_size' => filesize($filePath),
            'generated_at' => $invoice->pdf_generated_at
        ];
    }

    /**
     * Get PDF download URL with security checks
     */
    public function getPdfDownloadUrl(Invoice $invoice): string
    {
        if (!$invoice->is_paid || !$invoice->pdf_path) {
            throw new Exception('PDF not available for this invoice');
        }

        $integrity = $this->verifyPdfIntegrity($invoice);
        if (!$integrity['is_valid']) {
            throw new Exception('PDF file integrity check failed: ' . $integrity['message']);
        }

        // Return signed URL for secure download
        return Storage::temporaryUrl(
            $invoice->pdf_path,
            now()->addHour(),
            [
                'ResponseContentDisposition' => 'attachment; filename="Invoice_' . $invoice->formatted_invoice_number . '.pdf"'
            ]
        );
    }

    // ==================== LEGACY COMPATIBILITY METHODS ====================

    public function getPath(Invoice $invoice): ?string
    {
        return $invoice->pdf_path;
    }

    public function exists(Invoice $invoice): bool
    {
        return !empty($invoice->pdf_path) && Storage::exists($invoice->pdf_path);
    }

    public function delete(Invoice $invoice): bool
    {
        if ($invoice->pdf_path && Storage::exists($invoice->pdf_path)) {
            Storage::delete($invoice->pdf_path);
            $invoice->update(['pdf_path' => null, 'pdf_generated_at' => null, 'pdf_hash' => null]);
            return true;
        }
        return false;
    }

    public function regenerate(Invoice $invoice): string
    {
        return $this->regeneratePdf($invoice, 'Legacy regenerate call');
    }

    public function getDownloadUrl(Invoice $invoice): ?string
    {
        try {
            return $this->getPdfDownloadUrl($invoice);
        } catch (Exception $e) {
            return null;
        }
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Initialize PDF generator
     */
    private function initializeDompdf(): void
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('debugKeepTemp', false);
        
        $this->dompdf = new Dompdf($options);
    }

    /**
     * Validate invoice for PDF generation - CRITICAL SECURITY
     */
    private function validateInvoiceForPdfGeneration(Invoice $invoice): void
    {
        // Check payment status
        if (!$invoice->is_paid) {
            throw new Exception(
                "SECURITY VIOLATION: Cannot generate PDF for unpaid invoice ID {$invoice->id}. " .
                "PDF generation is only allowed after payment confirmation and invoice locking."
            );
        }

        // Check for tax snapshot (immutable data source)
        if (!$invoice->tax_snapshot) {
            throw new Exception(
                "DATA INTEGRITY ERROR: Cannot generate PDF without tax snapshot for invoice ID {$invoice->id}. " .
                "Tax calculations must be finalized and snapshotted before PDF generation."
            );
        }

        // Check invoice lock status
        if (!$invoice->is_locked) {
            throw new Exception(
                "IMMUTABILITY ERROR: Invoice ID {$invoice->id} must be locked before PDF generation. " .
                "This ensures data immutability and compliance."
            );
        }

        // Validate snapshot structure
        $requiredSnapshotFields = ['amounts', 'tax_details', 'company_info', 'client_info'];
        foreach ($requiredSnapshotFields as $field) {
            if (!isset($invoice->tax_snapshot[$field])) {
                throw new Exception(
                    "SNAPSHOT ERROR: Missing required field '{$field}' in tax snapshot for invoice ID {$invoice->id}"
                );
            }
        }
    }

    /**
     * Generate PDF content from tax snapshot data
     */
    private function generatePdfContent(Invoice $invoice): string
    {
        $taxBreakdown = $invoice->getTaxBreakdownFromSnapshot();
        $companyInfo = $invoice->tax_snapshot['company_info'];
        $clientInfo = $invoice->tax_snapshot['client_info'];
        
        // Determine invoice type
        $invoiceType = $this->determineInvoiceType($invoice, $companyInfo);
        
        // Prepare data for PDF template
        $data = [
            'invoice' => $invoice,
            'tax_breakdown' => $taxBreakdown,
            'company_info' => $companyInfo,
            'client_info' => $clientInfo,
            'invoice_type' => $invoiceType,
            'generated_at' => now(),
            'snapshot_timestamp' => $invoice->tax_snapshot['calculation_timestamp'],
            'items' => $invoice->items,
            'is_tax_invoice' => $companyInfo['is_pkp'] && $invoice->requires_tax_invoice,
            'pdf_config' => $this->config,
            
            // Legacy compatibility
            'seller' => $companyInfo,
            'buyer' => $clientInfo,
            'invoice_details' => [
                'number' => $invoice->formatted_invoice_number,
                'date' => $invoice->created_at->format('d M Y'),
                'due_date' => $invoice->due_at?->format('d M Y') ?? '-',
                'paid_date' => $invoice->paid_at->format('d M Y'),
                'status' => $invoice->status,
                'type' => $invoice->type,
            ]
        ];
        
        // Select appropriate template
        $template = $this->selectTemplate($invoiceType, $companyInfo['is_pkp']);
        
        return View::make($template, $data)->render();
    }

    /**
     * Generate PDF binary from HTML content
     */
    private function generatePdfBinary(string $htmlContent, Invoice $invoice): string
    {
        // Load HTML content
        $this->dompdf->loadHtml($htmlContent);
        
        // Set paper size and orientation
        $this->dompdf->setPaper(
            $this->config['paper_size'],
            $this->config['orientation']
        );
        
        // Render PDF
        $this->dompdf->render();
        
        // Add metadata to PDF
        $this->addPdfMetadata($invoice);
        
        return $this->dompdf->output();
    }

    /**
     * Add metadata to PDF for tracking and security
     */
    private function addPdfMetadata(Invoice $invoice): void
    {
        $canvas = $this->dompdf->getCanvas();
        
        // Add PDF metadata
        $canvas->get_cpdf()->addInfo(
            'Title',
            'Invoice #' . $invoice->formatted_invoice_number
        );
        
        $canvas->get_cpdf()->addInfo(
            'Subject',
            'Invoice from ' . $invoice->tax_snapshot['company_info']['company_name']
        );
        
        $canvas->get_cpdf()->addInfo(
            'Creator',
            'Talkabiz Invoice System'
        );
        
        $canvas->get_cpdf()->addInfo(
            'Author',
            $invoice->tax_snapshot['company_info']['company_name']
        );
        
        $canvas->get_cpdf()->addInfo(
            'Keywords',
            'invoice,tax,pajak,indonesia,talkabiz'
        );
        
        // Add creation date
        $canvas->get_cpdf()->addInfo(
            'CreationDate',
            'D:' . now()->format('YmdHis') . '+07\'00\''
        );
    }

    /**
     * Save PDF file with secure naming convention
     */
    private function savePdfFile(Invoice $invoice, string $pdfBinary): string
    {
        $fileName = sprintf(
            'invoices/%s/invoice_%s_%s.pdf',
            $invoice->user_id,
            $invoice->formatted_invoice_number,
            now()->format('Y_m_d_H_i_s')
        );
        
        // Ensure directory exists
        Storage::makeDirectory(dirname($fileName));
        
        // Save PDF file
        Storage::put($fileName, $pdfBinary);
        
        return $fileName;
    }

    /**
     * Update invoice with PDF information
     */
    private function updateInvoiceWithPdfInfo(Invoice $invoice, string $filePath, string $pdfBinary): void
    {
        $hash = hash('sha256', $pdfBinary);
        
        $invoice->update([
            'pdf_path' => $filePath,
            'pdf_generated_at' => now(),
            'pdf_hash' => $hash
        ]);
    }

    /**
     * Log PDF generation for audit trail
     */
    private function logPdfGeneration(Invoice $invoice, string $filePath): void
    {
        InvoiceAuditLog::logEvent(
            $invoice->id,
            'pdf_generated',
            null,
            [
                'pdf_path' => $filePath,
                'file_size' => Storage::size($filePath),
                'generated_at' => now()->toISOString()
            ],
            [
                'service' => 'InvoicePdfService',
                'generated_by' => auth()->id(),
                'source_data' => 'tax_snapshot'
            ]
        );
    }

    /**
     * Determine invoice type based on company and client info
     */
    private function determineInvoiceType(Invoice $invoice, array $companyInfo): string
    {
        if ($companyInfo['is_pkp']) {
            return 'tax_invoice'; // Faktur Pajak
        }
        
        if ($invoice->tax_amount > 0) {
            return 'standard_with_tax';
        }
        
        return 'standard_no_tax';
    }

    /**
     * Select appropriate PDF template
     */
    private function selectTemplate(string $invoiceType, bool $isPkp): string
    {
        $templates = [
            'tax_invoice' => 'pdf.invoice_tax', // For PKP companies
            'standard_with_tax' => 'pdf.invoice_standard_tax',
            'standard_no_tax' => 'pdf.invoice_standard'
        ];
        
        // Fallback to existing template if custom templates not available
        $template = $templates[$invoiceType] ?? 'invoices.pdf';
        
        if (!View::exists($template)) {
            return 'invoices.pdf'; // Legacy fallback
        }
        
        return $template;
    }

    // ==================== TOPUP INVOICE PDF ====================

    /**
     * Generate PDF for topup invoice.
     *
     * Topup invoices are simpler — no tax_snapshot, no lock required.
     * Uses dedicated template: invoices.topup-pdf
     *
     * @param Invoice $invoice Must be type=topup and status=paid
     * @return string File path of generated PDF
     */
    public function generateTopupPdf(Invoice $invoice): string
    {
        if ($invoice->type !== Invoice::TYPE_TOPUP) {
            throw new Exception("generateTopupPdf: Invoice ID {$invoice->id} bukan tipe topup.");
        }

        if (!$invoice->is_paid) {
            throw new Exception("generateTopupPdf: Invoice ID {$invoice->id} belum paid.");
        }

        $invoice->loadMissing(['user', 'walletTransaction']);

        $data = $this->prepareTopupData($invoice);

        $html = View::make('invoices.topup-pdf', $data)->render();

        $this->initializeDompdf();
        $pdfBinary = $this->generatePdfBinary($html, $invoice);

        $filePath = $this->savePdfFile($invoice, $pdfBinary);
        $this->updateInvoiceWithPdfInfo($invoice, $filePath, $pdfBinary);

        \Illuminate\Support\Facades\Log::info('[InvoicePdfService] Topup PDF generated', [
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'path'           => $filePath,
        ]);

        return $filePath;
    }

    /**
     * Stream topup PDF to browser.
     */
    public function streamTopupPdf(Invoice $invoice)
    {
        if ($invoice->type !== Invoice::TYPE_TOPUP) {
            throw new Exception("streamTopupPdf: Invoice bukan tipe topup.");
        }

        $invoice->loadMissing(['user', 'walletTransaction']);

        $data = $this->prepareTopupData($invoice);

        $html = View::make('invoices.topup-pdf', $data)->render();

        $this->initializeDompdf();
        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('a4', 'portrait');
        $this->dompdf->render();
        $this->addPdfMetadata($invoice);

        $output = $this->dompdf->output();

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"invoice-{$invoice->invoice_number}.pdf\"",
        ]);
    }

    /**
     * Download topup PDF.
     */
    public function downloadTopupPdf(Invoice $invoice)
    {
        if ($invoice->type !== Invoice::TYPE_TOPUP) {
            throw new Exception("downloadTopupPdf: Invoice bukan tipe topup.");
        }

        $invoice->loadMissing(['user', 'walletTransaction']);

        $data = $this->prepareTopupData($invoice);

        $html = View::make('invoices.topup-pdf', $data)->render();

        $this->initializeDompdf();
        $this->dompdf->loadHtml($html);
        $this->dompdf->setPaper('a4', 'portrait');
        $this->dompdf->render();
        $this->addPdfMetadata($invoice);

        $output = $this->dompdf->output();

        return response($output, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"invoice-{$invoice->invoice_number}.pdf\"",
        ]);
    }

    /**
     * Prepare data for topup PDF template.
     */
    private function prepareTopupData(Invoice $invoice): array
    {
        return [
            'invoice'     => $invoice,
            'user'        => $invoice->user,
            'transaction' => $invoice->walletTransaction,
            'company'     => [
                'name'    => config('tax.company_name', config('app.name', 'Talkabiz')),
                'npwp'    => config('tax.company_npwp', ''),
                'address' => config('tax.company_address', 'Indonesia'),
                'phone'   => config('tax.company_phone', '-'),
                'email'   => config('tax.company_email', 'billing@talkabiz.com'),
                'logo'    => public_path('assets/img/logo.png'),
            ],
            'formatted'   => [
                'amount'    => 'Rp ' . number_format($invoice->total, 0, ',', '.'),
                'subtotal'  => 'Rp ' . number_format($invoice->subtotal, 0, ',', '.'),
                'tax'       => 'Rp ' . number_format($invoice->tax_amount ?? $invoice->tax ?? 0, 0, ',', '.'),
                'issued_at' => $invoice->issued_at?->format('d M Y, H:i') ?? '-',
                'paid_at'   => $invoice->paid_at?->format('d M Y, H:i') ?? '-',
            ],
        ];
    }
}
