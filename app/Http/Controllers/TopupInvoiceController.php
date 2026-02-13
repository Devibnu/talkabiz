<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TopupInvoiceController
 *
 * Menampilkan dan mengelola invoice topup saldo.
 *
 * Routes:
 *   GET  /invoices/topup           → index  (daftar invoice topup user)
 *   GET  /invoices/topup/{id}      → show   (detail invoice)
 *   GET  /invoices/topup/{id}/pdf  → pdf    (stream PDF)
 *   GET  /invoices/topup/{id}/download → download (download PDF)
 */
class TopupInvoiceController extends Controller
{
    protected InvoiceService $invoiceService;
    protected InvoicePdfService $pdfService;

    public function __construct(InvoiceService $invoiceService, InvoicePdfService $pdfService)
    {
        $this->invoiceService = $invoiceService;
        $this->pdfService = $pdfService;
    }

    /**
     * Daftar invoice topup milik user yang sedang login.
     */
    public function index(Request $request)
    {
        $invoices = $this->invoiceService->getTopupInvoices(
            Auth::id(),
            $request->input('limit', 20)
        );

        // API response (untuk Vue / AJAX)
        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'invoices' => $invoices->map(fn (Invoice $inv) => [
                    'id'             => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'amount'         => $inv->total,
                    'formatted_amount' => 'Rp ' . number_format($inv->total, 0, ',', '.'),
                    'status'         => $inv->status,
                    'issued_at'      => $inv->issued_at?->toIso8601String(),
                    'paid_at'        => $inv->paid_at?->toIso8601String(),
                    'payment_method' => $inv->payment_method,
                    'pdf_url'        => route('invoices.topup.pdf', $inv->id),
                    'download_url'   => route('invoices.topup.download', $inv->id),
                ]),
            ]);
        }

        return view('invoices.topup-index', compact('invoices'));
    }

    /**
     * Detail invoice topup.
     */
    public function show(int $id)
    {
        $invoice = $this->findOwnedTopupInvoice($id);

        return view('invoices.topup-show', [
            'invoice'     => $invoice,
            'user'        => $invoice->user,
            'transaction' => $invoice->walletTransaction,
            'formatted'   => [
                'amount'    => 'Rp ' . number_format($invoice->total, 0, ',', '.'),
                'issued_at' => $invoice->issued_at?->format('d M Y, H:i'),
                'paid_at'   => $invoice->paid_at?->format('d M Y, H:i'),
            ],
        ]);
    }

    /**
     * Stream PDF invoice di browser.
     */
    public function pdf(int $id)
    {
        $invoice = $this->findOwnedTopupInvoice($id);

        return $this->pdfService->streamTopupPdf($invoice);
    }

    /**
     * Download PDF invoice.
     */
    public function download(int $id)
    {
        $invoice = $this->findOwnedTopupInvoice($id);

        return $this->pdfService->downloadTopupPdf($invoice);
    }

    /**
     * Find invoice milik user yang sedang login + tipe topup.
     * Abort 404 jika tidak ditemukan / bukan punya user ini.
     */
    protected function findOwnedTopupInvoice(int $id): Invoice
    {
        $invoice = Invoice::where('id', $id)
            ->where('user_id', Auth::id())
            ->where('type', Invoice::TYPE_TOPUP)
            ->with(['user', 'walletTransaction'])
            ->firstOrFail();

        return $invoice;
    }
}
