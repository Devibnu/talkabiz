<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanTransaction;
use App\Services\InvoiceNumberGenerator;
use App\Services\TaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PlanTransactionObserver
 * 
 * Bridges PlanTransaction lifecycle into the main Invoice system.
 * 
 * FLOW:
 *   1. PlanTransaction created (status=pending/waiting_payment)
 *      → Create Invoice (status=pending) + InvoiceItem
 *      → Tax calculated via TaxService (PPN 11%)
 *      → Invoice number via InvoiceNumberGenerator
 * 
 *   2. PlanTransaction updated to status=success
 *      → Mark Invoice as paid + set paid_at
 *      → Lock invoice for immutability
 * 
 *   3. PlanTransaction updated to status=expired/cancelled/failed
 *      → Mark Invoice as expired/cancelled accordingly
 * 
 * This observer DOES NOT modify subscription/wallet logic.
 * It purely creates financial records in the Invoice system.
 */
class PlanTransactionObserver
{
    /**
     * Handle the PlanTransaction "created" event.
     * Auto-create an Invoice + InvoiceItem when a plan transaction is created.
     */
    public function created(PlanTransaction $transaction): void
    {
        try {
            // Skip if transaction was created with success status (e.g., admin_assign)
            // For those, handle in the updated() hook or create + mark paid immediately.
            if ($transaction->status === PlanTransaction::STATUS_SUCCESS) {
                $this->createAndMarkPaid($transaction);
                return;
            }

            // Only create invoices for real payment transactions
            if (!in_array($transaction->status, [
                PlanTransaction::STATUS_PENDING,
                PlanTransaction::STATUS_WAITING_PAYMENT,
            ])) {
                return;
            }

            $this->createInvoiceForTransaction($transaction);

        } catch (\Throwable $e) {
            // Non-blocking: never fail the transaction creation
            Log::error('[PlanTransactionObserver] Failed to create invoice on created', [
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle the PlanTransaction "updated" event.
     * Mark invoice as paid/expired/cancelled when transaction status changes.
     */
    public function updated(PlanTransaction $transaction): void
    {
        // Only react to status changes
        if (!$transaction->isDirty('status')) {
            return;
        }

        try {
            $newStatus = $transaction->status;

            // Find the linked Invoice
            $invoice = Invoice::where('invoiceable_type', PlanTransaction::class)
                ->where('invoiceable_id', $transaction->id)
                ->first();

            if (!$invoice) {
                // If transaction just became success and no invoice exists, create + mark paid
                if ($newStatus === PlanTransaction::STATUS_SUCCESS) {
                    $this->createAndMarkPaid($transaction);
                }
                return;
            }

            // Already processed — idempotent guard
            if ($invoice->status === Invoice::STATUS_PAID && $newStatus === PlanTransaction::STATUS_SUCCESS) {
                return;
            }

            switch ($newStatus) {
                case PlanTransaction::STATUS_SUCCESS:
                    $this->markInvoicePaid($invoice, $transaction);
                    break;

                case PlanTransaction::STATUS_EXPIRED:
                    if ($invoice->status !== Invoice::STATUS_EXPIRED) {
                        $invoice->update([
                            'status' => Invoice::STATUS_EXPIRED,
                            'expired_at' => now(),
                        ]);
                        Log::info('[PlanTransactionObserver] Invoice marked expired', [
                            'invoice_id' => $invoice->id,
                            'transaction_id' => $transaction->id,
                        ]);
                    }
                    break;

                case PlanTransaction::STATUS_CANCELLED:
                case PlanTransaction::STATUS_FAILED:
                    if (!in_array($invoice->status, [Invoice::STATUS_CANCELLED, Invoice::STATUS_PAID])) {
                        $invoice->update([
                            'status' => Invoice::STATUS_CANCELLED,
                            'metadata' => array_merge($invoice->metadata ?? [], [
                                'cancelled_reason' => $transaction->failure_reason ?? "Transaction {$newStatus}",
                                'cancelled_at' => now()->toIso8601String(),
                            ]),
                        ]);
                        Log::info('[PlanTransactionObserver] Invoice marked cancelled', [
                            'invoice_id' => $invoice->id,
                            'transaction_id' => $transaction->id,
                            'reason' => $newStatus,
                        ]);
                    }
                    break;
            }

        } catch (\Throwable $e) {
            // Non-blocking: never fail the transaction update
            Log::error('[PlanTransactionObserver] Failed to update invoice on status change', [
                'transaction_id' => $transaction->id,
                'new_status' => $transaction->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create Invoice + InvoiceItem for a PlanTransaction.
     */
    private function createInvoiceForTransaction(PlanTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            // Resolve services
            $numberGenerator = app(InvoiceNumberGenerator::class);
            $taxService = app(TaxService::class);

            // Determine invoice type based on transaction type
            $invoiceType = match ($transaction->type) {
                PlanTransaction::TYPE_RENEWAL => Invoice::TYPE_SUBSCRIPTION_RENEWAL,
                PlanTransaction::TYPE_UPGRADE => Invoice::TYPE_SUBSCRIPTION_UPGRADE,
                default => Invoice::TYPE_SUBSCRIPTION,
            };

            // Tax calculation
            $subtotal = (float) $transaction->final_price;
            $tax = $taxService->calculatePPN($subtotal);

            // Generate invoice number
            $numbering = $numberGenerator->generate();

            // Seller info
            $company = $taxService->getCompanyInfo();

            // Business snapshot from klien (if available)
            $businessSnapshot = null;
            $klien = $transaction->klien;
            if ($klien) {
                try {
                    $klien->load(['taxProfile', 'businessType']);
                    $businessSnapshot = $klien->generateBusinessSnapshot();
                } catch (\Throwable $e) {
                    Log::warning('[PlanTransactionObserver] Business snapshot failed', [
                        'klien_id' => $klien->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Plan info from transaction
            $planName = $transaction->plan?->name ?? 'Subscription';
            $planCode = $transaction->plan?->code ?? '-';

            // === Create Invoice ===
            $invoice = new Invoice();
            $invoice->invoice_number = $numbering['invoice_number'];
            $invoice->klien_id = $transaction->klien_id;
            $invoice->user_id = $klien?->user?->id;
            $invoice->type = $invoiceType;
            $invoice->invoiceable_type = PlanTransaction::class;
            $invoice->invoiceable_id = $transaction->id;

            // Financial — tax-aware
            $invoice->subtotal = $tax['subtotal'];
            $invoice->discount = (float) ($transaction->discount_amount ?? 0);
            $invoice->tax = $tax['tax_amount'];
            $invoice->tax_amount = $tax['tax_amount'];
            $invoice->total = $tax['total_amount'] - (float) ($transaction->discount_amount ?? 0);

            // Tax fields
            $invoice->tax_rate = $tax['tax_rate'];
            $invoice->tax_type = $tax['tax_type'];
            $invoice->tax_included = $tax['tax_included'];
            $invoice->fiscal_year = $numbering['fiscal_year'];
            $invoice->fiscal_month = $numbering['fiscal_month'];

            // Seller snapshot
            $invoice->seller_npwp = $company['npwp'];
            $invoice->seller_npwp_name = $company['name'];
            $invoice->seller_npwp_address = $company['address'];

            // Business snapshot
            if ($businessSnapshot) {
                $invoice->billing_snapshot = $businessSnapshot;
                $invoice->snapshot_business_name = $businessSnapshot['business_name'] ?? null;
                $invoice->snapshot_business_type = $businessSnapshot['business_type_code'] ?? null;
                $invoice->snapshot_npwp = $businessSnapshot['npwp'] ?? null;
            }

            // Status & dates
            $invoice->currency = $transaction->currency ?? 'IDR';
            $invoice->status = Invoice::STATUS_PENDING;
            $invoice->issued_at = now();
            $invoice->due_at = $transaction->payment_expires_at ?? now()->addDay();

            // Line items (JSON — legacy field)
            $invoice->line_items = [
                [
                    'name' => $planName,
                    'description' => "Paket {$planName} ({$planCode})",
                    'qty' => 1,
                    'price' => $subtotal,
                    'total' => $subtotal,
                ],
            ];

            // Metadata
            $invoice->metadata = [
                'plan_transaction_id' => $transaction->id,
                'plan_transaction_code' => $transaction->transaction_code,
                'idempotency_key' => $transaction->idempotency_key,
                'plan_id' => $transaction->plan_id,
                'plan_name' => $planName,
                'plan_code' => $planCode,
                'transaction_type' => $transaction->type,
                'payment_gateway' => $transaction->payment_gateway,
                'pg_order_id' => $transaction->pg_order_id,
                'tax_calculation' => $tax,
                'source' => 'PlanTransactionObserver',
            ];

            $invoice->save();

            // === Create InvoiceItem (detailed line item) ===
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'item_code' => $planCode,
                'item_name' => $planName,
                'item_description' => "Langganan Paket {$planName} — " . ucfirst($transaction->type),
                'quantity' => 1,
                'unit' => 'bulan',
                'unit_price' => $subtotal,
                'subtotal' => $subtotal,
                'discount_amount' => (float) ($transaction->discount_amount ?? 0),
                'tax_amount' => $tax['tax_amount'],
                'total_amount' => $tax['total_amount'] - (float) ($transaction->discount_amount ?? 0),
                'tax_rate' => $tax['tax_rate'],
                'tax_type' => $tax['tax_type'] ?? 'PPN',
                'is_tax_inclusive' => $tax['tax_included'] ?? false,
                'sort_order' => 1,
            ]);

            Log::info('[PlanTransactionObserver] Invoice created for plan transaction', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'type' => $invoiceType,
                'subtotal' => $tax['subtotal'],
                'tax' => $tax['tax_amount'],
                'total' => $invoice->total,
            ]);
        });
    }

    /**
     * Mark invoice as paid.
     */
    private function markInvoicePaid(Invoice $invoice, PlanTransaction $transaction): void
    {
        DB::transaction(function () use ($invoice, $transaction) {
            $invoice = Invoice::where('id', $invoice->id)->lockForUpdate()->first();

            if (!$invoice || $invoice->status === Invoice::STATUS_PAID) {
                return; // Idempotent
            }

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => $transaction->paid_at ?? now(),
                'payment_method' => $transaction->payment_method ?? $transaction->payment_gateway,
                'payment_channel' => $transaction->payment_channel,
                'is_locked' => true,
                'locked_at' => now(),
            ]);

            Log::info('[PlanTransactionObserver] Invoice marked PAID', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'transaction_id' => $transaction->id,
                'paid_at' => $invoice->paid_at,
            ]);
        });
    }

    /**
     * Create invoice AND mark it paid immediately (for transactions created with success status).
     */
    private function createAndMarkPaid(PlanTransaction $transaction): void
    {
        // Check if invoice already exists (idempotency)
        $existing = Invoice::where('invoiceable_type', PlanTransaction::class)
            ->where('invoiceable_id', $transaction->id)
            ->first();

        if ($existing) {
            if ($existing->status !== Invoice::STATUS_PAID) {
                $this->markInvoicePaid($existing, $transaction);
            }
            return;
        }

        // Create the invoice first
        $this->createInvoiceForTransaction($transaction);

        // Now find and mark as paid
        $invoice = Invoice::where('invoiceable_type', PlanTransaction::class)
            ->where('invoiceable_id', $transaction->id)
            ->first();

        if ($invoice) {
            $this->markInvoicePaid($invoice, $transaction);
        }
    }
}
