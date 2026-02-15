<?php

namespace App\Services;

use App\Models\TransaksiSaldo;
use App\Models\DompetSaldo;
use App\Models\Pengguna;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class MidtransService
{
    protected string $serverKey;
    protected string $clientKey;
    protected bool $isProduction;

    public function __construct()
    {
        // Try to get config from database first
        $gateway = PaymentGateway::where('name', 'midtrans')->first();
        
        if ($gateway && $gateway->isConfigured()) {
            $this->serverKey = $gateway->server_key;
            $this->clientKey = $gateway->client_key;
            $this->isProduction = $gateway->isProduction();
            
            Log::debug('MidtransService initialized from database config', [
                'is_production' => $this->isProduction,
            ]);
        } else {
            // Fallback to .env config for backward compatibility
            $this->serverKey = config('midtrans.server_key', '');
            $this->clientKey = config('midtrans.client_key', '');
            $this->isProduction = config('midtrans.is_production', false);
            
            Log::debug('MidtransService initialized from .env config (fallback)', [
                'is_production' => $this->isProduction,
            ]);
        }

        // Configure Midtrans SDK
        \Midtrans\Config::$serverKey = $this->serverKey;
        \Midtrans\Config::$clientKey = $this->clientKey;
        \Midtrans\Config::$isProduction = $this->isProduction;
        \Midtrans\Config::$isSanitized = config('midtrans.is_sanitized', true);
        \Midtrans\Config::$is3ds = config('midtrans.is_3ds', true);
    }

    /**
     * Generate unique order ID untuk Midtrans
     */
    public function generateOrderId(): string
    {
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(6));
        return "TOPUP-{$timestamp}-{$random}";
    }

    /**
     * Create Snap transaction untuk top up
     */
    public function createSnapTransaction(
        int $amount,
        Pengguna $user,
        int $klienId,
        string $orderId = null
    ): array {
        $orderId = $orderId ?? $this->generateOrderId();

        // Get or create wallet first
        $wallet = DompetSaldo::firstOrCreate(
            ['klien_id' => $klienId],
            [
                'saldo_tersedia' => 0,
                'saldo_tertahan' => 0,
                'total_topup' => 0,
                'total_terpakai' => 0,
            ]
        );

        // Create pending transaction in database FIRST
        $transaksi = TransaksiSaldo::create([
            'kode_transaksi' => $orderId,
            'dompet_id' => $wallet->id,
            'klien_id' => $klienId,
            'pengguna_id' => $user->id,
            'jenis' => 'topup',
            'nominal' => $amount,
            'saldo_sebelum' => $wallet->saldo_tersedia,
            'saldo_sesudah' => $wallet->saldo_tersedia, // Belum berubah sampai paid
            'keterangan' => 'Top up saldo via Midtrans',
            'referensi' => $orderId,
            'status_topup' => 'pending',
            'metode_bayar' => 'midtrans_snap',
            'batas_bayar' => now()->addMinutes(config('midtrans.expiry_duration', 60)),
        ]);

        // Build Midtrans payload
        $params = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $user->nama_lengkap ?? $user->name ?? 'Customer',
                'email' => $user->email,
                'phone' => $user->no_telepon ?? '',
            ],
            'item_details' => [
                [
                    'id' => 'TOPUP-SALDO',
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => 'Top Up Saldo WhatsApp Talkabiz',
                ]
            ],
            'callbacks' => [
                'finish' => url(config('midtrans.finish_url')),
                'unfinish' => url(config('midtrans.unfinish_url')),
                'error' => url(config('midtrans.error_url')),
            ],
            'expiry' => [
                'start_time' => now()->format('Y-m-d H:i:s O'),
                'unit' => 'minutes',
                'duration' => config('midtrans.expiry_duration', 60),
            ],
            'enabled_payments' => config('midtrans.enabled_payments'),
        ];

        try {
            $snapToken = \Midtrans\Snap::getSnapToken($params);
            
            // Update transaksi with snap token
            $transaksi->update([
                'midtrans_snap_token' => $snapToken,
            ]);

            Log::info('Midtrans Snap token created', [
                'order_id' => $orderId,
                'amount' => $amount,
                'user_id' => $user->id,
            ]);

            return [
                'success' => true,
                'snap_token' => $snapToken,
                'order_id' => $orderId,
                'transaksi_id' => $transaksi->id,
                'redirect_url' => $this->isProduction
                    ? "https://app.midtrans.com/snap/v2/vtweb/{$snapToken}"
                    : "https://app.sandbox.midtrans.com/snap/v2/vtweb/{$snapToken}",
            ];

        } catch (Exception $e) {
            Log::error('Midtrans Snap error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            // Mark transaction as failed
            $transaksi->update([
                'status_topup' => 'failed',
                'catatan_admin' => 'Gagal generate Snap token: ' . $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Verify webhook signature dari Midtrans
     */
    public function verifySignature(array $notification): bool
    {
        $orderId = $notification['order_id'] ?? '';
        $statusCode = $notification['status_code'] ?? '';
        $grossAmount = $notification['gross_amount'] ?? '';
        $signatureKey = $notification['signature_key'] ?? '';

        $expectedSignature = hash('sha512', 
            $orderId . $statusCode . $grossAmount . $this->serverKey
        );

        return hash_equals($expectedSignature, $signatureKey);
    }

    /**
     * Handle webhook notification dari Midtrans
     * CRITICAL: Idempotent - tidak akan double credit
     */
    public function handleNotification(array $notification): array
    {
        $orderId = $notification['order_id'] ?? null;
        $transactionStatus = $notification['transaction_status'] ?? null;
        $fraudStatus = $notification['fraud_status'] ?? 'accept';
        $paymentType = $notification['payment_type'] ?? null;

        Log::info('Midtrans webhook received', [
            'order_id' => $orderId,
            'status' => $transactionStatus,
            'payment_type' => $paymentType,
        ]);

        if (!$orderId) {
            return ['success' => false, 'message' => 'Order ID tidak ditemukan'];
        }

        // Verify signature
        if (!$this->verifySignature($notification)) {
            Log::warning('Midtrans invalid signature', ['order_id' => $orderId]);
            return ['success' => false, 'message' => 'Invalid signature'];
        }

        // Find transaction by order_id (referensi)
        $transaksi = TransaksiSaldo::where('referensi', $orderId)
            ->where('jenis', 'topup')
            ->first();

        if (!$transaksi) {
            Log::warning('Midtrans transaction not found', ['order_id' => $orderId]);
            return ['success' => false, 'message' => 'Transaksi tidak ditemukan'];
        }

        // IDEMPOTENCY CHECK: Jika sudah paid, jangan proses lagi
        if ($transaksi->status_topup === 'paid') {
            Log::info('Midtrans already processed (idempotent)', ['order_id' => $orderId]);
            return ['success' => true, 'message' => 'Already processed', 'idempotent' => true];
        }

        // Handle status
        $result = $this->processTransactionStatus(
            $transaksi,
            $transactionStatus,
            $fraudStatus,
            $paymentType,
            $notification
        );

        return $result;
    }

    /**
     * Process transaction based on Midtrans status
     */
    protected function processTransactionStatus(
        TransaksiSaldo $transaksi,
        string $status,
        string $fraudStatus,
        ?string $paymentType,
        array $notification
    ): array {
        // Determine final status
        $isPaid = false;
        $newStatus = $transaksi->status_topup;

        switch ($status) {
            case 'capture':
                // For credit card
                $isPaid = ($fraudStatus === 'accept');
                $newStatus = $isPaid ? 'paid' : 'challenge';
                break;

            case 'settlement':
                // Payment confirmed
                $isPaid = true;
                $newStatus = 'paid';
                break;

            case 'pending':
                $newStatus = 'pending';
                break;

            case 'deny':
            case 'cancel':
                $newStatus = 'failed';
                break;

            case 'expire':
                $newStatus = 'expired';
                break;

            case 'refund':
            case 'partial_refund':
                $newStatus = 'refunded';
                break;

            default:
                Log::warning('Midtrans unknown status', [
                    'order_id' => $transaksi->referensi,
                    'status' => $status,
                ]);
                return ['success' => false, 'message' => 'Unknown status: ' . $status];
        }

        // If payment is confirmed, add balance (ATOMIC)
        if ($isPaid) {
            return $this->creditWallet($transaksi, $paymentType, $notification);
        }

        // Update status only (no balance change)
        $transaksi->update([
            'status_topup' => $newStatus,
            'metode_bayar' => $paymentType ?? $transaksi->metode_bayar,
            'midtrans_response' => json_encode($notification),
            'waktu_diproses' => now(),
        ]);

        Log::info('Midtrans status updated', [
            'order_id' => $transaksi->referensi,
            'new_status' => $newStatus,
        ]);

        return [
            'success' => true,
            'message' => 'Status updated to ' . $newStatus,
            'status' => $newStatus,
        ];
    }

    /**
     * Credit wallet after successful payment (ATOMIC & IDEMPOTENT)
     */
    protected function creditWallet(
        TransaksiSaldo $transaksi,
        ?string $paymentType,
        array $notification
    ): array {
        return DB::transaction(function () use ($transaksi, $paymentType, $notification) {
            // Lock wallet for update
            $wallet = DompetSaldo::where('id', $transaksi->dompet_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new Exception('Wallet tidak ditemukan');
            }

            // Re-check idempotency inside transaction
            $freshTransaksi = TransaksiSaldo::where('id', $transaksi->id)
                ->lockForUpdate()
                ->first();

            if ($freshTransaksi->status_topup === 'paid') {
                Log::info('Midtrans double-check idempotent', [
                    'order_id' => $transaksi->referensi
                ]);
                return [
                    'success' => true,
                    'message' => 'Already processed (double-check)',
                    'idempotent' => true,
                ];
            }

            $saldoSebelum = $wallet->saldo_tersedia;
            $saldoSesudah = $saldoSebelum + $transaksi->nominal;

            // Update wallet balance
            $wallet->update([
                'saldo_tersedia' => $saldoSesudah,
                'total_topup' => $wallet->total_topup + $transaksi->nominal,
                'terakhir_topup' => now(),
                'terakhir_transaksi' => now(),
            ]);

            // Update transaction
            $freshTransaksi->update([
                'status_topup' => 'paid',
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $saldoSesudah,
                'metode_bayar' => $paymentType ?? 'midtrans',
                'midtrans_response' => json_encode($notification),
                'waktu_diproses' => now(),
            ]);

            Log::info('Midtrans payment successful - wallet credited', [
                'order_id' => $transaksi->referensi,
                'amount' => $transaksi->nominal,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $saldoSesudah,
            ]);

            return [
                'success' => true,
                'message' => 'Payment successful',
                'status' => 'paid',
                'amount' => $transaksi->nominal,
                'new_balance' => $saldoSesudah,
            ];
        });
    }

    // checkStatus() REMOVED → Webhook-only architecture

    /**
     * Get client key untuk frontend
     */
    public function getClientKey(): string
    {
        return $this->clientKey;
    }

    /**
     * Get Snap JS URL
     */
    public function getSnapUrl(): string
    {
        return config('midtrans.snap_url');
    }
}
