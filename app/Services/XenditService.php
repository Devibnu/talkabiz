<?php

namespace App\Services;

use App\Models\PaymentGateway;
use App\Models\TransaksiSaldo;
use App\Models\DompetSaldo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * XenditService
 * 
 * Service untuk integrasi dengan Xendit Payment Gateway
 * Mendukung Invoice, Virtual Account, E-Wallet, dan QRIS
 */
class XenditService
{
    protected ?string $secretKey = null;
    protected ?string $publicKey = null;
    protected ?string $webhookToken = null;
    protected bool $isProduction = false;
    protected string $baseUrl;

    public function __construct()
    {
        $this->loadConfigFromDatabase();
    }

    /**
     * Load configuration from database (PaymentGateway model)
     */
    protected function loadConfigFromDatabase(): void
    {
        $gateway = PaymentGateway::where('name', 'xendit')->first();

        if ($gateway && $gateway->isConfigured()) {
            $this->secretKey = $gateway->server_key;
            $this->publicKey = $gateway->client_key;
            $this->webhookToken = $gateway->webhook_secret;
            $this->isProduction = $gateway->isProduction();
        } else {
            // Fallback to .env configuration
            $this->secretKey = config('xendit.secret_key');
            $this->publicKey = config('xendit.public_key');
            $this->webhookToken = config('xendit.webhook_token');
            $this->isProduction = config('xendit.is_production', false);
        }

        $this->baseUrl = $this->isProduction 
            ? 'https://api.xendit.co' 
            : 'https://api.xendit.co'; // Xendit uses same URL, mode determined by API key

        Log::debug('XenditService initialized', [
            'is_production' => $this->isProduction,
            'has_secret_key' => !empty($this->secretKey),
            'has_public_key' => !empty($this->publicKey),
        ]);
    }

    /**
     * Check if service is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    /**
     * Get public key for frontend
     */
    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * Check if in production mode
     */
    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    /**
     * Create Xendit Invoice for top-up
     * 
     * @param int $nominal Amount in IDR
     * @param object $user User model
     * @param int $klienId Client ID
     * @return array
     */
    public function createInvoice(int $nominal, $user, int $klienId): array
    {
        if (!$this->isConfigured()) {
            throw new \Exception('Xendit belum dikonfigurasi. Silakan hubungi administrator.');
        }

        // Generate unique order ID
        $orderId = 'XENDIT-' . date('Ymd') . '-' . strtoupper(Str::random(8));

        // Get or create wallet
        $dompet = DompetSaldo::firstOrCreate(
            ['klien_id' => $klienId],
            [
                'saldo_tersedia' => 0,
                'saldo_tertahan' => 0,
                'total_topup' => 0,
                'total_terpakai' => 0,
                'status_saldo' => 'normal',
            ]
        );

        // Create pending transaction record
        $transaksi = TransaksiSaldo::create([
            'kode_transaksi' => $orderId,
            'dompet_id' => $dompet->id,
            'klien_id' => $klienId,
            'pengguna_id' => $user->id,
            'jenis' => 'topup',
            'nominal' => $nominal,
            'saldo_sebelum' => $dompet->saldo_tersedia,
            'saldo_sesudah' => $dompet->saldo_tersedia, // Will update on success
            'keterangan' => 'Top up via Xendit',
            'referensi' => null, // Will be updated with invoice_id
            'status_topup' => 'pending',
            'metode_bayar' => 'xendit',
            'batas_bayar' => now()->addHours(24),
        ]);

        try {
            // Create Xendit Invoice
            $invoiceData = [
                'external_id' => $orderId,
                'amount' => $nominal,
                'description' => 'Top up saldo Talkabiz - ' . $user->name,
                'invoice_duration' => 86400, // 24 hours in seconds
                'customer' => [
                    'given_names' => $user->name,
                    'email' => $user->email ?? 'customer@talkabiz.id',
                ],
                'success_redirect_url' => url('/billing?status=finish'),
                'failure_redirect_url' => url('/billing?status=error'),
                'currency' => 'IDR',
                'payment_methods' => [
                    'BANK_TRANSFER', 
                    'EWALLET', 
                    'QR_CODE',
                    'RETAIL_OUTLET',
                ],
            ];

            $response = Http::withBasicAuth($this->secretKey, '')
                ->timeout(30)
                ->post($this->baseUrl . '/v2/invoices', $invoiceData);

            if ($response->successful()) {
                $invoice = $response->json();
                
                // Update transaction with invoice ID
                $transaksi->update([
                    'referensi' => $invoice['id'],
                ]);

                Log::info('Xendit invoice created', [
                    'order_id' => $orderId,
                    'invoice_id' => $invoice['id'],
                    'amount' => $nominal,
                ]);

                return [
                    'success' => true,
                    'order_id' => $orderId,
                    'invoice_id' => $invoice['id'],
                    'invoice_url' => $invoice['invoice_url'],
                    'expiry_date' => $invoice['expiry_date'],
                ];
            }

            // Handle error response
            $error = $response->json();
            Log::error('Xendit invoice creation failed', [
                'order_id' => $orderId,
                'error' => $error,
            ]);

            // Update transaction status
            $transaksi->update(['status_topup' => 'failed']);

            throw new \Exception($error['message'] ?? 'Gagal membuat invoice Xendit');

        } catch (\Illuminate\Http\Client\RequestException $e) {
            Log::error('Xendit API request failed', [
                'error' => $e->getMessage(),
            ]);

            $transaksi->update(['status_topup' => 'failed']);

            throw new \Exception('Koneksi ke Xendit gagal. Silakan coba lagi.');
        }
    }

    /**
     * Handle Xendit webhook notification
     * 
     * @param array $notification Webhook payload
     * @param string|null $callbackToken X-CALLBACK-TOKEN header
     * @return array
     */
    public function handleWebhook(array $notification, ?string $callbackToken = null): array
    {
        // Verify callback token if configured
        if ($this->webhookToken && $callbackToken !== $this->webhookToken) {
            Log::warning('Xendit webhook token mismatch', [
                'expected' => substr($this->webhookToken, 0, 8) . '...',
            ]);
            return [
                'success' => false,
                'message' => 'Invalid callback token',
            ];
        }

        $externalId = $notification['external_id'] ?? null;
        $status = $notification['status'] ?? null;
        $paidAmount = $notification['paid_amount'] ?? $notification['amount'] ?? 0;

        if (!$externalId) {
            return [
                'success' => false,
                'message' => 'Missing external_id',
            ];
        }

        // Find transaction
        $transaksi = TransaksiSaldo::where('kode_transaksi', $externalId)->first();

        if (!$transaksi) {
            Log::warning('Xendit webhook: Transaction not found', [
                'external_id' => $externalId,
            ]);
            return [
                'success' => false,
                'message' => 'Transaction not found',
            ];
        }

        // Skip if already processed
        if ($transaksi->status_topup === 'paid') {
            return [
                'success' => true,
                'message' => 'Already processed',
            ];
        }

        Log::info('Xendit webhook received', [
            'external_id' => $externalId,
            'status' => $status,
            'paid_amount' => $paidAmount,
        ]);

        // Process based on status
        if ($status === 'PAID') {
            return $this->processSuccessPayment($transaksi, $paidAmount);
        } elseif ($status === 'EXPIRED') {
            return $this->processExpiredPayment($transaksi);
        } elseif ($status === 'FAILED') {
            return $this->processFailedPayment($transaksi);
        }

        return [
            'success' => true,
            'message' => 'Status noted: ' . $status,
        ];
    }

    /**
     * Process successful payment
     * HARDENED: Atomic transaction with lockForUpdate and idempotency check
     */
    protected function processSuccessPayment(TransaksiSaldo $transaksi, int $paidAmount): array
    {
        // Use atomic transaction with row locking
        return DB::transaction(function () use ($transaksi, $paidAmount) {
            // Lock transaction row first to prevent race condition
            $lockedTransaksi = TransaksiSaldo::where('id', $transaksi->id)
                ->lockForUpdate()
                ->first();

            // IDEMPOTENCY CHECK: If already paid, don't process again
            if ($lockedTransaksi->status_topup === 'paid') {
                Log::info('Xendit payment already processed (idempotent skip)', [
                    'transaksi_id' => $transaksi->id,
                    'kode_transaksi' => $transaksi->kode_transaksi,
                ]);
                return [
                    'success' => true,
                    'message' => 'Already processed (idempotent)',
                    'idempotent' => true,
                ];
            }

            // Lock wallet row for atomic update
            $dompet = DompetSaldo::where('id', $lockedTransaksi->dompet_id)
                ->lockForUpdate()
                ->first();

            if (!$dompet) {
                Log::error('Xendit payment: Wallet not found', [
                    'transaksi_id' => $transaksi->id,
                    'dompet_id' => $lockedTransaksi->dompet_id,
                ]);
                return [
                    'success' => false,
                    'message' => 'Wallet not found',
                ];
            }

            // Update wallet balance atomically
            $saldoSebelum = $dompet->saldo_tersedia;
            $saldoSesudah = $saldoSebelum + $paidAmount;

            $dompet->update([
                'saldo_tersedia' => $saldoSesudah,
                'total_topup' => $dompet->total_topup + $paidAmount,
                'terakhir_topup' => now(),
                'terakhir_transaksi' => now(),
            ]);

            // Update transaction status
            $lockedTransaksi->update([
                'status_topup' => 'paid',
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $saldoSesudah,
                'waktu_diproses' => now(),
            ]);

            Log::info('Xendit payment successful - wallet credited', [
                'transaksi_id' => $transaksi->id,
                'kode_transaksi' => $transaksi->kode_transaksi,
                'amount' => $paidAmount,
                'saldo_sebelum' => $saldoSebelum,
                'saldo_sesudah' => $saldoSesudah,
            ]);

            return [
                'success' => true,
                'message' => 'Payment processed',
                'amount' => $paidAmount,
                'new_balance' => $saldoSesudah,
            ];
        });
    }

    /**
     * Process expired payment
     */
    protected function processExpiredPayment(TransaksiSaldo $transaksi): array
    {
        $transaksi->update([
            'status_topup' => 'expired',
        ]);

        Log::info('Xendit payment expired', [
            'transaksi_id' => $transaksi->id,
        ]);

        return [
            'success' => true,
            'message' => 'Payment marked as expired',
        ];
    }

    /**
     * Process failed payment
     */
    protected function processFailedPayment(TransaksiSaldo $transaksi): array
    {
        $transaksi->update([
            'status_topup' => 'failed',
        ]);

        Log::info('Xendit payment failed', [
            'transaksi_id' => $transaksi->id,
        ]);

        return [
            'success' => true,
            'message' => 'Payment marked as failed',
        ];
    }

    /**
     * Get invoice status from Xendit
     * 
     * @param string $invoiceId Xendit invoice ID
     * @return array
     */
    public function getInvoiceStatus(string $invoiceId): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Xendit not configured',
            ];
        }

        try {
            $response = Http::withBasicAuth($this->secretKey, '')
                ->timeout(30)
                ->get($this->baseUrl . '/v2/invoices/' . $invoiceId);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => $response->json()['message'] ?? 'Failed to get invoice status',
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify webhook signature (X-CALLBACK-TOKEN method)
     * 
     * @param string|null $callbackToken Token from X-CALLBACK-TOKEN header
     * @return bool
     */
    public function verifyWebhookToken(?string $callbackToken): bool
    {
        if (!$this->webhookToken) {
            // No webhook token configured, allow all (not recommended for production)
            return true;
        }

        return $callbackToken === $this->webhookToken;
    }
}
