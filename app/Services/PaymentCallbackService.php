<?php

namespace App\Services;

use App\Models\WalletTransaction;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * PaymentCallbackService
 *
 * Menangani callback dari payment gateway (Midtrans / Xendit / dll).
 * IDEMPOTENT: callback retry tidak menyebabkan topup ganda.
 *
 * Flow:
 *   Gateway callback → validate → idempotency check → WalletService::topup()
 */
class PaymentCallbackService
{
    protected WalletService $walletService;
    protected InvoiceService $invoiceService;

    public function __construct(WalletService $walletService, InvoiceService $invoiceService)
    {
        $this->walletService = $walletService;
        $this->invoiceService = $invoiceService;
    }

    // Status yang dianggap SUKSES (sesuaikan dengan gateway)
    const PAID_STATUSES = ['paid', 'success', 'settlement', 'captured'];
    const FAILED_STATUSES = ['failed', 'expired', 'cancelled', 'deny', 'cancel'];

    /**
     * Handle callback pembayaran topup.
     *
     * @param string $paymentId   ID unik dari payment gateway
     * @param int    $userId      User yang melakukan topup
     * @param int    $amount      Nominal pembayaran (Rupiah)
     * @param string $status      Status dari gateway (paid, failed, expired, dll)
     * @param string $gateway     Nama gateway: midtrans, xendit, manual
     * @param array  $rawPayload  Raw data callback untuk audit trail
     * @return array{success: bool, message: string, transaction?: WalletTransaction}
     */
    public function handle(
        string $paymentId,
        int    $userId,
        int    $amount,
        string $status,
        string $gateway = 'midtrans',
        array  $rawPayload = []
    ): array {
        $status = strtolower(trim($status));

        // 1. Validasi status: hanya proses jika PAID
        if (in_array($status, self::FAILED_STATUSES, true)) {
            Log::info('PaymentCallback: status bukan paid, diabaikan', [
                'payment_id' => $paymentId,
                'status'     => $status,
                'user_id'    => $userId,
            ]);

            return [
                'success' => false,
                'message' => "Pembayaran tidak berhasil (status: {$status}). Saldo tidak diubah.",
            ];
        }

        if (!in_array($status, self::PAID_STATUSES, true)) {
            Log::warning('PaymentCallback: status tidak dikenali', [
                'payment_id' => $paymentId,
                'status'     => $status,
            ]);

            return [
                'success' => false,
                'message' => "Status pembayaran tidak dikenali: {$status}",
            ];
        }

        // 2. Validasi amount
        if ($amount <= 0) {
            Log::warning('PaymentCallback: amount tidak valid', [
                'payment_id' => $paymentId,
                'amount'     => $amount,
            ]);

            return [
                'success' => false,
                'message' => 'Nominal pembayaran tidak valid.',
            ];
        }

        // 3. Idempotency key berbasis payment_id (unik per transaksi gateway)
        $idempotencyKey = "topup_payment_{$paymentId}";

        // 4. Cek apakah payment_id ini sudah pernah diproses
        $existing = WalletTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            Log::info('PaymentCallback: duplikat callback, sudah diproses', [
                'payment_id'     => $paymentId,
                'transaction_id' => $existing->id,
            ]);

            return [
                'success'     => true,
                'message'     => 'Pembayaran sudah pernah diproses sebelumnya.',
                'transaction' => $existing,
            ];
        }

        // 5. Topup via WalletService (atomic + idempotent di dalam)
        try {
            $transaction = $this->walletService->topup(
                $userId,
                $amount,
                $gateway,
                $idempotencyKey
            );

            // Update metadata dengan info payment gateway
            $transaction->update([
                'reference_type' => 'payment',
                'reference_id'   => $paymentId,
                'metadata'       => array_merge($transaction->metadata ?? [], [
                    'gateway'     => $gateway,
                    'payment_id'  => $paymentId,
                    'raw_status'  => $status,
                    'callback_at' => now()->toIso8601String(),
                    'raw_payload' => array_slice($rawPayload, 0, 20), // Limit payload size
                ]),
            ]);

            Log::info('PaymentCallback: topup berhasil', [
                'payment_id'     => $paymentId,
                'user_id'        => $userId,
                'amount'         => $amount,
                'transaction_id' => $transaction->id,
                'balance_after'  => $transaction->balance_after,
            ]);

            // 6. Auto-generate invoice topup
            $invoice = null;
            try {
                $invoice = $this->invoiceService->createForTopup($transaction, $userId);

                Log::info('PaymentCallback: invoice topup dibuat', [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ]);
            } catch (\Throwable $e) {
                // Invoice gagal tidak boleh menggagalkan topup
                Log::error('PaymentCallback: gagal buat invoice topup (non-blocking)', [
                    'transaction_id' => $transaction->id,
                    'error'          => $e->getMessage(),
                ]);
            }

            return [
                'success'     => true,
                'message'     => 'Topup saldo berhasil.',
                'transaction' => $transaction,
                'invoice'     => $invoice,
            ];
        } catch (\Throwable $e) {
            Log::error('PaymentCallback: topup gagal', [
                'payment_id' => $paymentId,
                'user_id'    => $userId,
                'amount'     => $amount,
                'error'      => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Gagal memproses topup: ' . $e->getMessage(),
            ];
        }
    }
}
