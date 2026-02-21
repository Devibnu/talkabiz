<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceWebController
 * 
 * Unified web controller for viewing all invoice types.
 * Supports subscription, topup, recurring, upgrade invoices.
 * 
 * Routes:
 *   GET /invoices             → index    (list all invoices)
 *   GET /invoices/{id}        → show     (invoice detail)
 *   GET /invoices/{id}/pdf    → pdf      (stream PDF in browser)
 *   GET /invoices/{id}/download → download (download PDF file)
 */
class InvoiceWebController extends Controller
{
    protected InvoicePdfService $pdfService;

    public function __construct(InvoicePdfService $pdfService)
    {
        $this->pdfService = $pdfService;
    }

    /**
     * List all invoices for the current user.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Invoice::where(function ($q) use ($user) {
            $q->where('user_id', $user->id);
            if ($user->klien_id) {
                $q->orWhere('klien_id', $user->klien_id);
            }
        })
        ->with(['items'])
        ->orderBy('created_at', 'desc');

        // Filter by type
        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $invoices = $query->paginate(15);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'invoices' => $invoices->through(fn (Invoice $inv) => $this->formatInvoice($inv)),
            ]);
        }

        return view('invoices.index', compact('invoices'));
    }

    /**
     * Show invoice detail.
     */
    public function show(int $id, Request $request)
    {
        $invoice = $this->findOwnedInvoice($id);
        $invoice->load(['items', 'klien', 'user']);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'invoice' => $this->formatInvoiceDetail($invoice),
            ]);
        }

        return view('invoices.show', [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'company' => [
                'name' => config('tax.company_name', config('app.name', 'Talkabiz')),
                'npwp' => config('tax.company_npwp', ''),
                'address' => config('tax.company_address', 'Indonesia'),
                'phone' => config('tax.company_phone', '-'),
                'email' => config('tax.company_email', 'billing@talkabiz.com'),
            ],
        ]);
    }

    /**
     * Stream PDF in browser.
     */
    public function pdf(int $id)
    {
        $invoice = $this->findOwnedInvoice($id);

        if (!$invoice->is_paid) {
            return back()->with('error', 'PDF hanya tersedia untuk invoice yang sudah dibayar.');
        }

        try {
            // Use topup PDF service for topup type, general for others
            if ($invoice->type === Invoice::TYPE_TOPUP) {
                return $this->pdfService->streamTopupPdf($invoice);
            }

            // For subscription invoices, generate inline PDF
            return $this->generateInlinePdf($invoice);
        } catch (\Exception $e) {
            Log::error('[InvoiceWebController] PDF stream failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal membuat PDF: ' . $e->getMessage());
        }
    }

    /**
     * Download PDF file.
     */
    public function download(int $id)
    {
        $invoice = $this->findOwnedInvoice($id);

        if (!$invoice->is_paid) {
            return back()->with('error', 'PDF hanya tersedia untuk invoice yang sudah dibayar.');
        }

        try {
            if ($invoice->type === Invoice::TYPE_TOPUP) {
                return $this->pdfService->downloadTopupPdf($invoice);
            }

            return $this->generateDownloadPdf($invoice);
        } catch (\Exception $e) {
            Log::error('[InvoiceWebController] PDF download failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal mengunduh PDF: ' . $e->getMessage());
        }
    }

    // ========== PRIVATE METHODS ==========

    /**
     * Find invoice owned by the current user.
     */
    private function findOwnedInvoice(int $id): Invoice
    {
        $user = Auth::user();

        return Invoice::where('id', $id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if ($user->klien_id) {
                    $q->orWhere('klien_id', $user->klien_id);
                }
            })
            ->firstOrFail();
    }

    /**
     * Format invoice for JSON list response.
     */
    private function formatInvoice(Invoice $inv): array
    {
        return [
            'id' => $inv->id,
            'invoice_number' => $inv->invoice_number,
            'type' => $inv->type,
            'type_label' => $this->getTypeLabel($inv->type),
            'subtotal' => $inv->subtotal,
            'tax_amount' => $inv->tax_amount ?? $inv->tax ?? 0,
            'total' => $inv->total,
            'formatted_total' => 'Rp ' . number_format($inv->total, 0, ',', '.'),
            'status' => $inv->status,
            'status_label' => $this->getStatusLabel($inv->status),
            'issued_at' => $inv->issued_at?->toIso8601String(),
            'paid_at' => $inv->paid_at?->toIso8601String(),
            'pdf_url' => $inv->is_paid ? route('invoices.pdf', $inv->id) : null,
            'download_url' => $inv->is_paid ? route('invoices.download', $inv->id) : null,
        ];
    }

    /**
     * Format invoice detail for JSON response.
     */
    private function formatInvoiceDetail(Invoice $inv): array
    {
        return array_merge($this->formatInvoice($inv), [
            'items' => $inv->items->map(fn ($item) => [
                'item_name' => $item->item_name,
                'item_description' => $item->item_description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_amount' => $item->tax_amount,
                'total_amount' => $item->total_amount,
                'formatted_unit_price' => $item->formatted_unit_price,
                'formatted_total_amount' => $item->formatted_total_amount,
            ]),
            'klien_name' => $inv->klien?->nama_perusahaan ?? '-',
            'klien_email' => $inv->klien?->email ?? $inv->user?->email ?? '-',
            'seller_name' => $inv->seller_npwp_name ?? config('tax.company_name', 'Talkabiz'),
            'seller_npwp' => $inv->seller_npwp ?? '',
            'payment_method' => $inv->payment_method,
            'formatted_subtotal' => 'Rp ' . number_format($inv->subtotal, 0, ',', '.'),
            'formatted_tax' => 'Rp ' . number_format($inv->tax_amount ?? $inv->tax ?? 0, 0, ',', '.'),
            'tax_rate' => $inv->tax_rate ?? config('tax.default_tax_rate', 11),
            'metadata' => $inv->metadata,
        ]);
    }

    /**
     * Generate inline PDF for subscription invoices using Dompdf.
     */
    private function generateInlinePdf(Invoice $invoice)
    {
        $invoice->loadMissing(['items', 'klien', 'user']);

        $data = $this->preparePdfData($invoice);
        $html = view('invoices.pdf-general', $data)->render();

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"invoice-{$invoice->invoice_number}.pdf\"",
        ]);
    }

    /**
     * Generate downloadable PDF for subscription invoices.
     */
    private function generateDownloadPdf(Invoice $invoice)
    {
        $invoice->loadMissing(['items', 'klien', 'user']);

        $data = $this->preparePdfData($invoice);
        $html = view('invoices.pdf-general', $data)->render();

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $output = $dompdf->output();

        $safeNumber = str_replace(['/', '\\'], '-', $invoice->invoice_number);

        return response($output, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"invoice-{$safeNumber}.pdf\"",
        ]);
    }

    /**
     * Prepare data for PDF template.
     */
    private function preparePdfData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'items' => $invoice->items,
            'user' => $invoice->user,
            'klien' => $invoice->klien,
            'company' => [
                'name' => config('tax.company_name', config('app.name', 'Talkabiz')),
                'npwp' => config('tax.company_npwp', ''),
                'address' => config('tax.company_address', 'Indonesia'),
                'phone' => config('tax.company_phone', '-'),
                'email' => config('tax.company_email', 'billing@talkabiz.com'),
                'logo' => public_path('assets/img/logo.png'),
            ],
            'formatted' => [
                'subtotal' => 'Rp ' . number_format($invoice->subtotal, 0, ',', '.'),
                'discount' => 'Rp ' . number_format($invoice->discount ?? 0, 0, ',', '.'),
                'tax' => 'Rp ' . number_format($invoice->tax_amount ?? $invoice->tax ?? 0, 0, ',', '.'),
                'total' => 'Rp ' . number_format($invoice->total, 0, ',', '.'),
                'issued_at' => $invoice->issued_at?->translatedFormat('d F Y, H:i') ?? '-',
                'due_at' => $invoice->due_at?->translatedFormat('d F Y, H:i') ?? '-',
                'paid_at' => $invoice->paid_at?->translatedFormat('d F Y, H:i') ?? '-',
            ],
        ];
    }

    /**
     * Get human-readable type label.
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            Invoice::TYPE_SUBSCRIPTION => 'Langganan',
            Invoice::TYPE_SUBSCRIPTION_UPGRADE => 'Upgrade Paket',
            Invoice::TYPE_SUBSCRIPTION_RENEWAL => 'Perpanjangan',
            Invoice::TYPE_TOPUP => 'Topup Saldo',
            Invoice::TYPE_ADDON => 'Add-on',
            default => 'Lainnya',
        };
    }

    /**
     * Get human-readable status label.
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            Invoice::STATUS_DRAFT => 'Draft',
            Invoice::STATUS_PENDING => 'Menunggu Pembayaran',
            Invoice::STATUS_PAID => 'Lunas',
            Invoice::STATUS_EXPIRED => 'Kedaluwarsa',
            Invoice::STATUS_CANCELLED => 'Dibatalkan',
            Invoice::STATUS_REFUNDED => 'Dikembalikan',
            default => $status,
        };
    }
}
