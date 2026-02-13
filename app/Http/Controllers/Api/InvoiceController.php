<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\InvoiceService;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceController
 * 
 * API endpoints untuk invoice management.
 * 
 * ENDPOINTS:
 * ==========
 * GET  /api/invoices              - List invoices for current klien
 * GET  /api/invoices/{id}         - Get invoice detail
 * POST /api/invoices/{id}/pay     - Create payment link (get snap token)
 * POST /api/invoices/subscription - Create new subscription invoice
 * POST /api/invoices/upgrade      - Create upgrade invoice
 * POST /api/invoices/renewal      - Create renewal invoice
 * 
 * IMPORTANT:
 * - Status tidak bisa diubah via API (hanya via webhook)
 * - Semua perubahan di-log ke invoice_events
 */
class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;
    protected PaymentGatewayService $paymentGatewayService;

    public function __construct(
        InvoiceService $invoiceService,
        PaymentGatewayService $paymentGatewayService
    ) {
        $this->invoiceService = $invoiceService;
        $this->paymentGatewayService = $paymentGatewayService;
    }

    /**
     * List invoices for current klien
     * 
     * GET /api/invoices
     */
    public function index(Request $request)
    {
        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        $query = Invoice::forKlien($klien->id)
            ->with(['payments' => function ($q) {
                $q->latest()->take(1);
            }])
            ->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $invoices = $query->paginate($request->get('per_page', 10));

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    /**
     * Get invoice detail
     * 
     * GET /api/invoices/{id}
     */
    public function show(Request $request, $id)
    {
        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        $invoice = Invoice::forKlien($klien->id)
            ->with(['payments', 'events' => function ($q) {
                $q->orderBy('created_at', 'desc')->take(10);
            }])
            ->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * Create payment link for invoice
     * 
     * POST /api/invoices/{id}/pay
     * 
     * Returns snap token for Midtrans payment
     */
    public function pay(Request $request, $id)
    {
        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        // Find invoice
        $invoice = Invoice::forKlien($klien->id)->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found',
            ], 404);
        }

        // Check invoice is payable
        if (!$invoice->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice tidak dapat dibayar (status: ' . $invoice->status . ')',
            ], 400);
        }

        // Create payment link
        $result = $this->paymentGatewayService->createPaymentLink($invoice);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat payment link',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->total,
                'snap_token' => $result['snap_token'] ?? null,
                'redirect_url' => $result['redirect_url'] ?? null,
                'gateway' => $result['gateway'] ?? null,
                'payment_id' => $result['payment']->id ?? null,
            ],
        ]);
    }

    /**
     * Create subscription invoice
     * 
     * POST /api/invoices/subscription
     * 
     * @body plan_id integer required
     */
    public function createSubscription(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        // Check no existing pending subscription
        $hasPending = Invoice::forKlien($klien->id)
            ->pending()
            ->subscription()
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah memiliki invoice subscription yang pending',
            ], 400);
        }

        $plan = Plan::find($request->plan_id);

        if (!$plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Plan tidak tersedia',
            ], 400);
        }

        $result = $this->invoiceService->createForSubscription($klien, $plan);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat invoice',
            ], 500);
        }

        // Create payment link
        $paymentResult = $this->paymentGatewayService->createPaymentLink($result['invoice']);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => $result['invoice'],
                'payment' => $result['payment'],
                'snap_token' => $paymentResult['snap_token'] ?? null,
            ],
        ]);
    }

    /**
     * Create upgrade invoice
     * 
     * POST /api/invoices/upgrade
     * 
     * @body plan_id integer required (new plan)
     */
    public function createUpgrade(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
        ]);

        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        // Check no existing pending upgrade
        $hasPending = Invoice::forKlien($klien->id)
            ->pending()
            ->where('type', Invoice::TYPE_SUBSCRIPTION_UPGRADE)
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah memiliki invoice upgrade yang pending',
            ], 400);
        }

        $plan = Plan::find($request->plan_id);

        if (!$plan->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Plan tidak tersedia',
            ], 400);
        }

        $result = $this->invoiceService->createForUpgrade($klien, $plan);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat invoice upgrade',
            ], 500);
        }

        // Create payment link
        $paymentResult = $this->paymentGatewayService->createPaymentLink($result['invoice']);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => $result['invoice'],
                'payment' => $result['payment'],
                'snap_token' => $paymentResult['snap_token'] ?? null,
            ],
        ]);
    }

    /**
     * Create renewal invoice
     * 
     * POST /api/invoices/renewal
     */
    public function createRenewal(Request $request)
    {
        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        // Check no existing pending renewal
        $hasPending = Invoice::forKlien($klien->id)
            ->pending()
            ->where('type', Invoice::TYPE_SUBSCRIPTION_RENEWAL)
            ->exists();

        if ($hasPending) {
            return response()->json([
                'success' => false,
                'message' => 'Anda sudah memiliki invoice renewal yang pending',
            ], 400);
        }

        $result = $this->invoiceService->createForRenewal($klien);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Gagal membuat invoice renewal',
            ], 500);
        }

        // Create payment link
        $paymentResult = $this->paymentGatewayService->createPaymentLink($result['invoice']);

        return response()->json([
            'success' => true,
            'data' => [
                'invoice' => $result['invoice'],
                'payment' => $result['payment'],
                'snap_token' => $paymentResult['snap_token'] ?? null,
            ],
        ]);
    }

    /**
     * Check payment status
     * 
     * GET /api/invoices/{id}/status
     */
    public function status(Request $request, $id)
    {
        $klien = Auth::user()->klien;

        if (!$klien) {
            return response()->json([
                'success' => false,
                'message' => 'Klien not found',
            ], 404);
        }

        $invoice = Invoice::forKlien($klien->id)
            ->with(['payments' => function ($q) {
                $q->latest()->take(1);
            }])
            ->find($id);

        if (!$invoice) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found',
            ], 404);
        }

        $latestPayment = $invoice->payments->first();

        return response()->json([
            'success' => true,
            'data' => [
                'invoice_id' => $invoice->id,
                'invoice_status' => $invoice->status,
                'payment_status' => $latestPayment?->status,
                'is_paid' => $invoice->isPaid(),
                'paid_at' => $invoice->paid_at,
            ],
        ]);
    }
}
