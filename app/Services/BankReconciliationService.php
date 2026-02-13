<?php

namespace App\Services;

use App\Models\BankStatement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReconciliationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * BankReconciliationService — Rekonsiliasi Bank & Payment Gateway
 *
 * ATURAN KERAS:
 * ─────────────
 * ✅ Invoice = Single Source of Truth (SSOT) pendapatan
 * ✅ Payment (payments table) = bukti pembayaran gateway
 * ✅ BankStatement = bukti dana masuk bank
 * ❌ TIDAK menghitung dari wallet, plan, atau UI
 * ❌ TIDAK edit data mentah
 * ✅ Semua via service layer
 *
 * ALUR REKONSILIASI:
 * ──────────────────
 * Invoice (PAID) → Payment (SUCCESS) → BankStatement (credit)
 *
 * Setiap link yang MISSING = otomatis FLAG
 *
 * GATEWAY RECONCILIATION:
 * ──────────────────────
 * 1. Invoice PAID harus punya payments SUCCESS
 * 2. Payment SUCCESS amount harus == invoice total
 * 3. Double payment (>1 success per invoice) → flag
 *
 * BANK RECONCILIATION:
 * ────────────────────
 * 1. Total bank credit >= total gateway SUCCESS
 * 2. Setiap payment gateway_order_id idealnya muncul di bank reference
 * 3. Selisih dicatat, TIDAK dihapus
 */
class BankReconciliationService
{
    // ================================================================
    // GATEWAY RECONCILIATION
    // ================================================================

    /**
     * Preview rekonsiliasi Gateway — TANPA menyimpan.
     *
     * Mencocokkan Invoice PAID vs Payment SUCCESS pada periode tertentu.
     *
     * @param  int $year
     * @param  int $month
     * @return array Preview data
     */
    public function previewGatewayReconciliation(int $year, int $month): array
    {
        $this->validatePeriod($year, $month);
        return $this->calculateGatewayReconciliation($year, $month);
    }

    /**
     * Proses rekonsiliasi Gateway — hitung & SIMPAN ke reconciliation_logs.
     *
     * @param  int      $year
     * @param  int      $month
     * @param  int|null $userId
     * @return ReconciliationLog
     *
     * @throws \RuntimeException Jika bulan sudah di-lock atau Monthly Closing CLOSED
     */
    public function reconcileGateway(int $year, int $month, ?int $userId = null): ReconciliationLog
    {
        $this->validatePeriod($year, $month);
        $this->guardMonthlyClosing($year, $month);

        return DB::transaction(function () use ($year, $month, $userId) {
            $data = $this->calculateGatewayReconciliation($year, $month);

            $periodKey = sprintf('%04d-%02d', $year, $month);

            $log = ReconciliationLog::updateOrCreate(
                [
                    'period_year'  => $year,
                    'period_month' => $month,
                    'source'       => ReconciliationLog::SOURCE_GATEWAY,
                ],
                [
                    'period_key'             => $periodKey,
                    'total_expected'         => $data['expected_total'],
                    'total_actual'           => $data['actual_total'],
                    'difference'             => $data['difference'],
                    'total_expected_count'   => $data['expected_count'],
                    'total_actual_count'     => $data['actual_count'],
                    'unmatched_invoice_count' => count($data['unmatched_invoices']),
                    'unmatched_payment_count' => count($data['unmatched_payments']),
                    'double_payment_count'   => count($data['double_payments']),
                    'status'                 => $data['status'],
                    'unmatched_invoices'     => $data['unmatched_invoices'],
                    'unmatched_payments'     => $data['unmatched_payments'],
                    'amount_mismatches'      => $data['amount_mismatches'],
                    'double_payments'        => $data['double_payments'],
                    'summary_snapshot'       => $data,
                    'discrepancy_notes'      => $data['notes'] ?: null,
                    'reconciled_by'          => $userId,
                    'reconciled_at'          => now(),
                ]
            );

            $log->update(['recon_hash' => $log->generateHash()]);

            // Audit
            $this->logAudit('reconciliation.gateway', $log, $data, $userId);

            return $log->fresh();
        });
    }

    // ================================================================
    // BANK RECONCILIATION
    // ================================================================

    /**
     * Preview rekonsiliasi Bank — TANPA menyimpan.
     *
     * Mencocokkan Payment SUCCESS vs BankStatement (credit) pada periode.
     *
     * @param  int $year
     * @param  int $month
     * @return array Preview data
     */
    public function previewBankReconciliation(int $year, int $month): array
    {
        $this->validatePeriod($year, $month);
        return $this->calculateBankReconciliation($year, $month);
    }

    /**
     * Proses rekonsiliasi Bank — hitung & SIMPAN ke reconciliation_logs.
     *
     * @param  int      $year
     * @param  int      $month
     * @param  int|null $userId
     * @return ReconciliationLog
     */
    public function reconcileBank(int $year, int $month, ?int $userId = null): ReconciliationLog
    {
        $this->validatePeriod($year, $month);
        $this->guardMonthlyClosing($year, $month);

        return DB::transaction(function () use ($year, $month, $userId) {
            $data = $this->calculateBankReconciliation($year, $month);

            $periodKey = sprintf('%04d-%02d', $year, $month);

            $log = ReconciliationLog::updateOrCreate(
                [
                    'period_year'  => $year,
                    'period_month' => $month,
                    'source'       => ReconciliationLog::SOURCE_BANK,
                ],
                [
                    'period_key'             => $periodKey,
                    'total_expected'         => $data['expected_total'],
                    'total_actual'           => $data['actual_total'],
                    'difference'             => $data['difference'],
                    'total_expected_count'   => $data['expected_count'],
                    'total_actual_count'     => $data['actual_count'],
                    'unmatched_invoice_count' => 0,
                    'unmatched_payment_count' => count($data['unmatched_payments']),
                    'double_payment_count'   => 0,
                    'status'                 => $data['status'],
                    'unmatched_invoices'     => null,
                    'unmatched_payments'     => $data['unmatched_payments'],
                    'amount_mismatches'      => $data['amount_mismatches'],
                    'double_payments'        => null,
                    'summary_snapshot'       => $data,
                    'discrepancy_notes'      => $data['notes'] ?: null,
                    'reconciled_by'          => $userId,
                    'reconciled_at'          => now(),
                ]
            );

            $log->update(['recon_hash' => $log->generateHash()]);

            $this->logAudit('reconciliation.bank', $log, $data, $userId);

            return $log->fresh();
        });
    }

    // ================================================================
    // MARK OK (Owner manually marks reconciliation as verified)
    // ================================================================

    /**
     * Owner menandai rekonsiliasi OK meskipun ada selisih kecil.
     *
     * @param  int      $year
     * @param  int      $month
     * @param  string   $source  'gateway' | 'bank'
     * @param  string   $notes   Alasan / catatan
     * @param  int|null $userId
     * @return ReconciliationLog
     */
    public function markAsOk(int $year, int $month, string $source, string $notes, ?int $userId = null): ReconciliationLog
    {
        $log = ReconciliationLog::forPeriod($year, $month)
            ->where('source', $source)
            ->firstOrFail();

        if ($log->isLocked()) {
            throw new \RuntimeException("Rekonsiliasi {$log->period_label} ({$source}) sudah terkunci.");
        }

        $log->update([
            'status'        => ReconciliationLog::STATUS_MATCH,
            'notes'         => $notes,
            'reconciled_by' => $userId,
            'reconciled_at' => now(),
        ]);
        $log->update(['recon_hash' => $log->generateHash()]);

        $this->logAudit('reconciliation.marked_ok', $log, ['notes' => $notes], $userId);

        return $log->fresh();
    }

    // ================================================================
    // BANK STATEMENT IMPORT
    // ================================================================

    /**
     * Import bank statements dari array data.
     *
     * @param  array       $rows       Array of [bank_name, trx_date, amount, trx_type, description, reference]
     * @param  string      $source     'manual' | 'csv' | 'api'
     * @param  int|null    $userId
     * @return array       Import result
     */
    public function importBankStatements(array $rows, string $source = 'manual', ?int $userId = null): array
    {
        $batchId = 'IMP-' . now()->format('YmdHis') . '-' . strtoupper(substr(uniqid(), -4));
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $i => $row) {
            try {
                $stmt = BankStatement::create([
                    'bank_name'       => $row['bank_name'] ?? 'Unknown',
                    'bank_account'    => $row['bank_account'] ?? null,
                    'trx_date'        => $row['trx_date'],
                    'amount'          => abs((float) $row['amount']),
                    'trx_type'        => $row['trx_type'] ?? 'credit',
                    'description'     => $row['description'] ?? null,
                    'reference'       => $row['reference'] ?? null,
                    'import_source'   => $source,
                    'import_batch_id' => $batchId,
                    'imported_at'     => now(),
                    'imported_by'     => $userId,
                    'match_status'    => BankStatement::MATCH_UNMATCHED,
                ]);
                $stmt->update(['statement_hash' => $stmt->generateHash()]);
                $imported++;
            } catch (\Exception $e) {
                $skipped++;
                $errors[] = "Row {$i}: {$e->getMessage()}";
            }
        }

        return [
            'batch_id' => $batchId,
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Parse CSV content to array rows for import.
     *
     * Expected columns: bank_name, trx_date, amount, trx_type, description, reference
     *
     * @param  string $csvContent
     * @return array
     */
    public function parseCsvForImport(string $csvContent): array
    {
        $rows   = [];
        $lines  = array_filter(explode("\n", $csvContent));
        $header = null;

        foreach ($lines as $line) {
            $cols = str_getcsv(trim($line));
            if (!$header) {
                $header = array_map('strtolower', array_map('trim', $cols));
                continue;
            }
            if (count($cols) < count($header)) {
                continue;
            }
            $row = array_combine($header, $cols);
            $rows[] = [
                'bank_name'    => $row['bank_name'] ?? $row['bank'] ?? 'Unknown',
                'bank_account' => $row['bank_account'] ?? $row['account'] ?? null,
                'trx_date'     => $row['trx_date'] ?? $row['date'] ?? $row['tanggal'] ?? now()->format('Y-m-d'),
                'amount'       => $row['amount'] ?? $row['jumlah'] ?? 0,
                'trx_type'     => $row['trx_type'] ?? $row['type'] ?? 'credit',
                'description'  => $row['description'] ?? $row['keterangan'] ?? null,
                'reference'    => $row['reference'] ?? $row['ref'] ?? $row['berita'] ?? null,
            ];
        }

        return $rows;
    }

    // ================================================================
    // QUERY HELPERS
    // ================================================================

    /**
     * Get reconciliation logs per tahun.
     */
    public function getReconciliationList(?int $year = null): Collection
    {
        $query = ReconciliationLog::query()
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->orderBy('source');

        if ($year) {
            $query->where('period_year', $year);
        }

        return $query->get();
    }

    /**
     * Cek status rekonsiliasi untuk periode.
     */
    public function getReconciliationStatus(int $year, int $month): array
    {
        $gateway = ReconciliationLog::forPeriod($year, $month)->gateway()->first();
        $bank    = ReconciliationLog::forPeriod($year, $month)->bank()->first();

        return [
            'gateway' => $gateway ? [
                'status'     => $gateway->status,
                'difference' => $gateway->difference,
                'is_locked'  => $gateway->is_locked,
            ] : null,
            'bank' => $bank ? [
                'status'     => $bank->status,
                'difference' => $bank->difference,
                'is_locked'  => $bank->is_locked,
            ] : null,
            'both_matched' => ($gateway?->isMatch() ?? false) && ($bank?->isMatch() ?? false),
        ];
    }

    /**
     * Export CSV data rekonsiliasi.
     */
    public function exportCsv(int $year, int $month, string $source): string
    {
        $log = ReconciliationLog::forPeriod($year, $month)
            ->where('source', $source)
            ->firstOrFail();

        $output = "Rekonsiliasi {$source} - {$log->period_label}\n";
        $output .= "Status,{$log->status}\n";
        $output .= "Expected,{$log->total_expected}\n";
        $output .= "Actual,{$log->total_actual}\n";
        $output .= "Difference,{$log->difference}\n\n";

        if ($source === 'gateway') {
            // Unmatched invoices
            if ($log->unmatched_invoices) {
                $output .= "Unmatched Invoices (PAID tanpa Payment SUCCESS)\n";
                $output .= "Invoice ID,Invoice Number,Amount,Type,Paid At\n";
                foreach ($log->unmatched_invoices as $inv) {
                    $output .= "{$inv['id']},{$inv['invoice_number']},{$inv['total']},{$inv['type']},{$inv['paid_at']}\n";
                }
                $output .= "\n";
            }
            // Unmatched payments
            if ($log->unmatched_payments) {
                $output .= "Unmatched Payments (SUCCESS tanpa Invoice PAID)\n";
                $output .= "Payment ID,Gateway Order ID,Amount,Gateway,Paid At\n";
                foreach ($log->unmatched_payments as $pay) {
                    $output .= "{$pay['id']},{$pay['gateway_order_id']},{$pay['amount']},{$pay['gateway']},{$pay['paid_at']}\n";
                }
                $output .= "\n";
            }
            // Amount mismatches
            if ($log->amount_mismatches) {
                $output .= "Amount Mismatches (Invoice vs Payment amount berbeda)\n";
                $output .= "Invoice ID,Invoice Amount,Payment ID,Payment Amount,Difference\n";
                foreach ($log->amount_mismatches as $mm) {
                    $output .= "{$mm['invoice_id']},{$mm['invoice_total']},{$mm['payment_id']},{$mm['payment_amount']},{$mm['difference']}\n";
                }
            }
        } else {
            // Bank unmatched payments
            if ($log->unmatched_payments) {
                $output .= "Unmatched Payments (tidak match di bank statement)\n";
                $output .= "Payment ID,Gateway Order ID,Amount,Gateway,Paid At\n";
                foreach ($log->unmatched_payments as $pay) {
                    $output .= "{$pay['id']},{$pay['gateway_order_id']},{$pay['amount']},{$pay['gateway']},{$pay['paid_at']}\n";
                }
            }
        }

        return $output;
    }

    // ================================================================
    // PRIVATE: GATEWAY CALCULATION
    // ================================================================

    /**
     * Hitung gateway reconciliation.
     *
     * Logic:
     * 1. Get all Invoice PAID in fiscal period
     * 2. Get all Payment SUCCESS in same date range
     * 3. Match invoice → payment via invoice_id
     * 4. Find unmatched on both sides
     * 5. Check amount mismatches
     * 6. Check double payments
     */
    private function calculateGatewayReconciliation(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd   = Carbon::create($year, $month, 1)->endOfMonth();

        // ── 1. Invoice PAID (fiscal period) ──
        $invoices = Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->where('fiscal_year', $year)
            ->where('fiscal_month', $month)
            ->get(['id', 'invoice_number', 'type', 'total', 'subtotal', 'tax_amount', 'paid_at', 'klien_id']);

        $expectedTotal = (float) $invoices->sum('total');
        $expectedCount = $invoices->count();

        // ── 2. Payment SUCCESS (paid_at in period) ──
        $payments = Payment::query()
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->get(['id', 'invoice_id', 'gateway', 'gateway_order_id', 'gateway_transaction_id', 'amount', 'fee', 'net_amount', 'paid_at', 'payment_method', 'payment_channel']);

        $actualTotal = (float) $payments->sum('amount');
        $actualCount = $payments->count();

        // ── 3. Build invoice_id lookup sets ──
        $invoiceIds     = $invoices->pluck('id')->toArray();
        $paymentInvIds  = $payments->pluck('invoice_id')->filter()->toArray();

        // ── 4. Unmatched Invoices: PAID but no payment SUCCESS ──
        $unmatchedInvoices = [];
        foreach ($invoices as $inv) {
            $hasPayment = $payments->where('invoice_id', $inv->id)->isNotEmpty();
            if (!$hasPayment) {
                $unmatchedInvoices[] = [
                    'id'             => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'type'           => $inv->type,
                    'total'          => (float) $inv->total,
                    'paid_at'        => $inv->paid_at?->toDateTimeString(),
                ];
            }
        }

        // ── 5. Unmatched Payments: SUCCESS but no invoice PAID in period ──
        $unmatchedPayments = [];
        foreach ($payments as $pay) {
            if (!$pay->invoice_id || !in_array($pay->invoice_id, $invoiceIds)) {
                $unmatchedPayments[] = [
                    'id'               => $pay->id,
                    'invoice_id'       => $pay->invoice_id,
                    'gateway'          => $pay->gateway,
                    'gateway_order_id' => $pay->gateway_order_id,
                    'amount'           => (float) $pay->amount,
                    'paid_at'          => $pay->paid_at?->toDateTimeString(),
                ];
            }
        }

        // ── 6. Amount Mismatches: invoice.total != payment.amount ──
        $amountMismatches = [];
        foreach ($invoices as $inv) {
            $invPayments = $payments->where('invoice_id', $inv->id);
            if ($invPayments->isEmpty()) {
                continue;
            }
            foreach ($invPayments as $pay) {
                $diff = abs((float) $inv->total - (float) $pay->amount);
                if ($diff > 1) { // Tolerance Rp 1 for rounding
                    $amountMismatches[] = [
                        'invoice_id'    => $inv->id,
                        'invoice_number' => $inv->invoice_number,
                        'invoice_total' => (float) $inv->total,
                        'payment_id'    => $pay->id,
                        'payment_amount' => (float) $pay->amount,
                        'difference'    => $diff,
                    ];
                }
            }
        }

        // ── 7. Double Payments: invoice has >1 success payment ──
        $doublePayments = [];
        $paymentsByInvoice = $payments->groupBy('invoice_id');
        foreach ($paymentsByInvoice as $invoiceId => $invPayments) {
            if (!$invoiceId || $invPayments->count() <= 1) {
                continue;
            }
            $invoice = $invoices->firstWhere('id', $invoiceId);
            $doublePayments[] = [
                'invoice_id'     => $invoiceId,
                'invoice_number' => $invoice?->invoice_number ?? '-',
                'invoice_total'  => (float) ($invoice?->total ?? 0),
                'payment_count'  => $invPayments->count(),
                'total_paid'     => (float) $invPayments->sum('amount'),
                'payments'       => $invPayments->map(fn ($p) => [
                    'id'      => $p->id,
                    'amount'  => (float) $p->amount,
                    'gateway' => $p->gateway,
                    'paid_at' => $p->paid_at?->toDateTimeString(),
                ])->values()->toArray(),
            ];
        }

        // ── 8. Determine status ──
        $difference = $expectedTotal - $actualTotal;
        $status     = $this->determineStatus(
            $unmatchedInvoices,
            $unmatchedPayments,
            $amountMismatches,
            $doublePayments,
            $difference
        );

        // ── 9. Notes ──
        $notes = $this->buildGatewayNotes($unmatchedInvoices, $unmatchedPayments, $amountMismatches, $doublePayments, $difference);

        return [
            'year'              => $year,
            'month'             => $month,
            'period'            => $this->getPeriodLabel($month, $year),
            'source'            => 'gateway',
            'expected_total'    => $expectedTotal,
            'expected_count'    => $expectedCount,
            'actual_total'      => $actualTotal,
            'actual_count'      => $actualCount,
            'difference'        => $difference,
            'status'            => $status,
            'unmatched_invoices' => $unmatchedInvoices,
            'unmatched_payments' => $unmatchedPayments,
            'amount_mismatches' => $amountMismatches,
            'double_payments'   => $doublePayments,
            'notes'             => $notes,
        ];
    }

    // ================================================================
    // PRIVATE: BANK CALCULATION
    // ================================================================

    /**
     * Hitung bank reconciliation.
     *
     * Logic:
     * 1. Total Payment SUCCESS (paid_at in period) = expected
     * 2. Total BankStatement credit (trx_date in period) = actual
     * 3. Match payment gateway_order_id / amount to bank reference / amount
     * 4. Find unmatched payments (no bank statement match)
     */
    private function calculateBankReconciliation(int $year, int $month): array
    {
        $periodStart = Carbon::create($year, $month, 1)->startOfMonth();
        $periodEnd   = Carbon::create($year, $month, 1)->endOfMonth();

        // ── 1. Payment SUCCESS (paid_at in period) ──
        $payments = Payment::query()
            ->where('status', Payment::STATUS_SUCCESS)
            ->whereBetween('paid_at', [$periodStart, $periodEnd])
            ->get(['id', 'invoice_id', 'gateway', 'gateway_order_id', 'gateway_transaction_id', 'amount', 'net_amount', 'fee', 'paid_at']);

        $expectedTotal = (float) $payments->sum('amount');
        $expectedCount = $payments->count();

        // ── 2. BankStatement credit (trx_date in period) ──
        $statements = BankStatement::query()
            ->credit()
            ->forPeriod($year, $month)
            ->get();

        $actualTotal = (float) $statements->sum('amount');
        $actualCount = $statements->count();

        // ── 3. Try to match payments to bank statements ──
        $unmatchedPayments = [];
        $amountMismatches  = [];
        $matchedCount      = 0;

        foreach ($payments as $pay) {
            // Try match by reference (gateway_order_id in bank description/reference)
            $matchedStmt = $statements->first(function ($stmt) use ($pay) {
                if (!$stmt->reference && !$stmt->description) {
                    return false;
                }
                $searchIn = strtolower(($stmt->reference ?? '') . ' ' . ($stmt->description ?? ''));
                $orderId  = strtolower($pay->gateway_order_id ?? '');
                $txnId    = strtolower($pay->gateway_transaction_id ?? '');

                return ($orderId && str_contains($searchIn, $orderId))
                    || ($txnId && str_contains($searchIn, $txnId));
            });

            if ($matchedStmt) {
                $matchedCount++;
                // Check amount (allow fee difference — bank may show net_amount)
                $diff = abs((float) $pay->amount - (float) $matchedStmt->amount);
                $feeTolerance = max(1, (float) ($pay->fee ?? 0));
                if ($diff > $feeTolerance && $diff > 1) {
                    $amountMismatches[] = [
                        'payment_id'       => $pay->id,
                        'gateway_order_id' => $pay->gateway_order_id,
                        'payment_amount'   => (float) $pay->amount,
                        'bank_amount'      => (float) $matchedStmt->amount,
                        'difference'       => $diff,
                        'bank_ref'         => $matchedStmt->reference,
                    ];
                }
            } else {
                $unmatchedPayments[] = [
                    'id'               => $pay->id,
                    'invoice_id'       => $pay->invoice_id,
                    'gateway'          => $pay->gateway,
                    'gateway_order_id' => $pay->gateway_order_id,
                    'amount'           => (float) $pay->amount,
                    'paid_at'          => $pay->paid_at?->toDateTimeString(),
                ];
            }
        }

        // ── 4. Determine status ──
        $difference = $expectedTotal - $actualTotal;
        $status = ReconciliationLog::STATUS_MATCH;

        if (count($unmatchedPayments) > 0 || count($amountMismatches) > 0) {
            $status = ReconciliationLog::STATUS_PARTIAL_MATCH;
        }
        if (abs($difference) > max(1000, $expectedTotal * 0.01)) {
            $status = ReconciliationLog::STATUS_MISMATCH;
        }
        if ($expectedCount === 0 && $actualCount === 0) {
            $status = ReconciliationLog::STATUS_MATCH; // Empty period = match
        }

        // ── 5. Notes ──
        $notes = '';
        if (count($unmatchedPayments) > 0) {
            $notes .= count($unmatchedPayments) . " payment tidak ditemukan di bank statement. ";
        }
        if (count($amountMismatches) > 0) {
            $notes .= count($amountMismatches) . " payment memiliki selisih amount dengan bank. ";
        }
        if (abs($difference) > 0) {
            $notes .= "Total difference: Rp " . number_format(abs($difference), 0, ',', '.') . ". ";
        }

        return [
            'year'              => $year,
            'month'             => $month,
            'period'            => $this->getPeriodLabel($month, $year),
            'source'            => 'bank',
            'expected_total'    => $expectedTotal,
            'expected_count'    => $expectedCount,
            'actual_total'      => $actualTotal,
            'actual_count'      => $actualCount,
            'difference'        => $difference,
            'matched_count'     => $matchedCount,
            'status'            => $status,
            'unmatched_payments' => $unmatchedPayments,
            'amount_mismatches' => $amountMismatches,
            'notes'             => trim($notes),
        ];
    }

    // ================================================================
    // PRIVATE: HELPERS
    // ================================================================

    private function determineStatus(array $unmatchedInvoices, array $unmatchedPayments, array $amountMismatches, array $doublePayments, float $difference): string
    {
        $hasIssues = count($unmatchedInvoices) > 0
            || count($unmatchedPayments) > 0
            || count($amountMismatches) > 0
            || count($doublePayments) > 0;

        if (!$hasIssues && abs($difference) < 1) {
            return ReconciliationLog::STATUS_MATCH;
        }

        if (!$hasIssues && abs($difference) < 1000) {
            return ReconciliationLog::STATUS_PARTIAL_MATCH;
        }

        if (count($unmatchedInvoices) === 0 && count($amountMismatches) === 0 && count($doublePayments) === 0 && count($unmatchedPayments) <= 2) {
            return ReconciliationLog::STATUS_PARTIAL_MATCH;
        }

        return ReconciliationLog::STATUS_MISMATCH;
    }

    private function buildGatewayNotes(array $unmatchedInvoices, array $unmatchedPayments, array $amountMismatches, array $doublePayments, float $difference): string
    {
        $notes = '';
        if (count($unmatchedInvoices) > 0) {
            $notes .= count($unmatchedInvoices) . " invoice PAID tanpa payment SUCCESS. ";
        }
        if (count($unmatchedPayments) > 0) {
            $notes .= count($unmatchedPayments) . " payment SUCCESS tanpa invoice PAID di periode. ";
        }
        if (count($amountMismatches) > 0) {
            $notes .= count($amountMismatches) . " invoice-payment amount mismatch. ";
        }
        if (count($doublePayments) > 0) {
            $notes .= count($doublePayments) . " invoice dengan double payment (>1 SUCCESS). ";
        }
        if (abs($difference) > 0) {
            $notes .= "Total selisih: Rp " . number_format(abs($difference), 0, ',', '.') . ". ";
        }
        return trim($notes);
    }

    private function guardMonthlyClosing(int $year, int $month): void
    {
        try {
            $closingService = app(FinanceClosingService::class);
            if ($closingService->isMonthClosed($year, $month)) {
                throw new \RuntimeException(
                    "Bulan {$this->getPeriodLabel($month, $year)} sudah di-CLOSE. Rekonsiliasi tidak bisa diubah."
                );
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            // FinanceClosingService not available, skip guard
        }
    }

    private function validatePeriod(int $year, int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException("Bulan harus 1-12, diberikan: {$month}");
        }
        if ($year < 2020 || $year > 2100) {
            throw new \InvalidArgumentException("Tahun harus 2020-2100, diberikan: {$year}");
        }
    }

    private function getPeriodLabel(int $month, int $year): string
    {
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
            4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        return ($bulan[$month] ?? 'N/A') . ' ' . $year;
    }

    private function logAudit(string $action, ReconciliationLog $log, array $data, ?int $userId): void
    {
        Log::info("BankReconciliation: {$action}", [
            'recon_id'   => $log->id,
            'period'     => "{$log->period_year}-{$log->period_month}",
            'source'     => $log->source,
            'status'     => $log->status,
            'expected'   => $log->total_expected,
            'actual'     => $log->total_actual,
            'difference' => $log->difference,
            'user'       => $userId,
        ]);

        try {
            app(AuditLogService::class)->log(
                $action,
                'ReconciliationLog',
                $log->id,
                [
                    'description' => "Rekonsiliasi {$log->source} {$log->period_label}: " .
                                     "Expected Rp " . number_format($log->total_expected, 0, ',', '.') .
                                     " vs Actual Rp " . number_format($log->total_actual, 0, ',', '.') .
                                     " — Status: {$log->status}",
                    'new_values'  => [
                        'status'     => $log->status,
                        'expected'   => $log->total_expected,
                        'actual'     => $log->total_actual,
                        'difference' => $log->difference,
                    ],
                    'category' => 'finance',
                ]
            );
        } catch (\Exception $e) {
            Log::warning('AuditLog failed for reconciliation', ['error' => $e->getMessage()]);
        }
    }
}
