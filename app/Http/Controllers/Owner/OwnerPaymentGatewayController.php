<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Owner Payment Gateway Controller
 * 
 * Mengelola konfigurasi payment gateway oleh Owner.
 * 
 * FITUR:
 * - Pilih provider (Midtrans, Xendit)
 * - Input & simpan API keys (encrypted)
 * - Toggle active/inactive
 * - Hanya 1 gateway yang bisa aktif dalam satu waktu
 * 
 * SECURITY:
 * - Hanya role owner/super_admin
 * - API keys di-encrypt dengan Laravel Crypt
 * - Semua perubahan di-audit
 */
class OwnerPaymentGatewayController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'ensure.owner']);
    }

    /**
     * Display payment gateway management page
     */
    public function index()
    {
        // Ensure default gateways exist
        $this->ensureDefaultGatewaysExist();

        $gateways = PaymentGateway::all();
        $activeGateway = PaymentGateway::getActive();

        return view('owner.payment-gateway.index', [
            'gateways' => $gateways,
            'activeGateway' => $activeGateway,
        ]);
    }

    /**
     * Update gateway configuration
     */
    public function update(Request $request, PaymentGateway $gateway)
    {
        $validator = Validator::make($request->all(), [
            'server_key' => 'nullable|string|max:500',
            'client_key' => 'nullable|string|max:500',
            'webhook_secret' => 'nullable|string|max:500',
            'environment' => 'required|in:sandbox,production',
            'is_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            // Identify gateway by NAME (not by ID from route binding)
            $gatewayName = $gateway->name;

            $oldValues = [
                'environment' => $gateway->environment,
                'is_enabled' => $gateway->is_enabled,
                'has_server_key' => !empty($gateway->server_key),
                'has_client_key' => !empty($gateway->client_key),
            ];

            // Build update payload
            $updateData = [
                'display_name' => $gateway->display_name ?? ucfirst($gatewayName),
                'description' => $gateway->description ?? '',
                'environment' => $request->environment,
                'is_enabled' => $request->boolean('is_enabled'),
                'updated_by' => auth()->id(),
            ];

            // Only update keys if user typed new values
            if ($request->filled('server_key')) {
                $updateData['server_key'] = $request->server_key;
            }
            if ($request->filled('client_key')) {
                $updateData['client_key'] = $request->client_key;
            }
            if ($request->filled('webhook_secret')) {
                $updateData['webhook_secret'] = $request->webhook_secret;
            }

            // HARDENING: Cannot enable if no API keys
            if ($request->boolean('is_enabled')) {
                $hasServerKey = $request->filled('server_key') || !empty($gateway->server_key);
                if (!$hasServerKey) {
                    return back()->withErrors([
                        'server_key' => 'Server Key wajib diisi untuk mengaktifkan gateway.',
                    ])->withInput();
                }
            }

            // If disabling, also deactivate
            if (!$request->boolean('is_enabled') && $gateway->is_active) {
                $updateData['is_active'] = false;
            }

            // AUTO-ACTIVATE: If enabling a configured gateway and no other gateway is active,
            // automatically set this one as active (saves the extra "SET ACTIVE" click)
            if ($request->boolean('is_enabled')) {
                $willBeConfigured = ($request->filled('server_key') || !empty($gateway->server_key))
                    && ($request->filled('client_key') || !empty($gateway->client_key));

                if ($willBeConfigured) {
                    $otherActive = PaymentGateway::where('name', '!=', $gatewayName)
                        ->where('is_active', true)
                        ->exists();

                    if (!$otherActive) {
                        DB::table('payment_gateways')
                            ->where('name', '!=', $gatewayName)
                            ->update(['is_active' => false]);
                        $updateData['is_active'] = true;
                    }
                }
            }

            // updateOrCreate by NAME — single source of truth, prevents duplicates
            $gateway = PaymentGateway::updateOrCreate(
                ['name' => $gatewayName],
                $updateData
            );

            // Verify keys are persisted (read raw from DB)
            $gateway->refresh();

            Log::info('Payment gateway saved — verification', [
                'gateway' => $gateway->name,
                'id' => $gateway->id,
                'server_key_decrypted' => !empty($gateway->server_key),
                'client_key_decrypted' => !empty($gateway->client_key),
                'server_key_raw_len' => strlen($gateway->getRawOriginal('server_key') ?? ''),
                'client_key_raw_len' => strlen($gateway->getRawOriginal('client_key') ?? ''),
                'request_had_server_key' => $request->filled('server_key'),
                'request_had_client_key' => $request->filled('client_key'),
            ]);

            // Audit log
            $this->logAudit('update_payment_gateway', $gateway, $oldValues, [
                'environment' => $gateway->environment,
                'is_enabled' => $gateway->is_enabled,
                'has_server_key' => !empty($gateway->server_key),
                'has_client_key' => !empty($gateway->client_key),
            ]);

            return back()->with('success', "Konfigurasi {$gateway->display_name} berhasil diperbarui.");

        } catch (\Exception $e) {
            Log::error('Failed to update payment gateway', [
                'gateway' => $gateway->name ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal memperbarui konfigurasi gateway.');
        }
    }

    /**
     * Set a gateway as active (only 1 can be active)
     */
    public function setActive(Request $request, PaymentGateway $gateway)
    {
        // Validate gateway is enabled and has keys
        if (!$gateway->is_enabled) {
            return back()->with('error', 'Gateway harus di-enable terlebih dahulu.');
        }

        if (!$gateway->isConfigured()) {
            return back()->with('error', 'API keys belum dikonfigurasi untuk gateway ini.');
        }

        try {
            DB::transaction(function () use ($gateway) {
                // Deactivate ALL gateways first (single active rule)
                PaymentGateway::query()->update(['is_active' => false]);

                // Activate this gateway + force is_enabled
                $gateway->update([
                    'is_active' => true,
                    'is_enabled' => true,
                ]);
            });

            // Audit log
            $this->logAudit('set_active_payment_gateway', $gateway, null, [
                'gateway' => $gateway->name,
            ]);

            Log::info('Payment gateway set as active', [
                'admin_id' => auth()->id(),
                'gateway' => $gateway->name,
                'is_active' => true,
                'is_enabled' => true,
            ]);

            return back()->with('success', "{$gateway->display_name} diaktifkan sebagai payment gateway utama.");

        } catch (\Exception $e) {
            Log::error('Failed to set active payment gateway', [
                'gateway' => $gateway->name,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal mengaktifkan payment gateway.');
        }
    }

    /**
     * Deactivate a gateway
     */
    public function deactivate(Request $request, PaymentGateway $gateway)
    {
        try {
            $gateway->update(['is_active' => false]);

            // Audit log
            $this->logAudit('deactivate_payment_gateway', $gateway, null, [
                'gateway' => $gateway->name,
            ]);

            Log::info('Payment gateway deactivated', [
                'admin_id' => auth()->id(),
                'gateway' => $gateway->name,
            ]);

            return back()->with('success', "{$gateway->display_name} dinonaktifkan.");

        } catch (\Exception $e) {
            Log::error('Failed to deactivate payment gateway', [
                'gateway' => $gateway->name,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal menonaktifkan payment gateway.');
        }
    }

    /**
     * Test gateway connection
     * 
     * READS FROM DB BY NAME — not by route model binding ID.
     * This guarantees we test the SAME record that was saved.
     * No config() or .env — purely database-driven.
     */
    public function testConnection(PaymentGateway $gateway)
    {
        try {
            // Fetch by NAME from DB — single source of truth, not route model binding
            $gatewayName = $gateway->name;
            $gateway = PaymentGateway::where('name', $gatewayName)->first();

            if (!$gateway) {
                return response()->json([
                    'success' => false,
                    'message' => "Gateway '{$gatewayName}' tidak ditemukan di database.",
                ], 404);
            }

            // Log for debugging
            Log::info('Test connection initiated', [
                'gateway' => $gateway->name,
                'id' => $gateway->id,
                'has_server_key' => !empty($gateway->server_key),
                'has_client_key' => !empty($gateway->client_key),
                'server_key_raw_len' => strlen($gateway->getRawOriginal('server_key') ?? ''),
                'environment' => $gateway->environment,
            ]);

            if ($gateway->name === 'midtrans') {
                return $this->testMidtransConnection($gateway);
            } elseif ($gateway->name === 'xendit') {
                return $this->testXenditConnection($gateway);
            }

            return response()->json([
                'success' => false,
                'message' => 'Provider tidak dikenali.',
            ], 400);

        } catch (\Exception $e) {
            \Log::error('Midtrans Test Error: ' . $e->getMessage(), [
                'gateway' => $gateway->name ?? 'unknown',
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * API: Get gateway status
     */
    public function getStatus()
    {
        $active = PaymentGateway::getActive();
        $gateways = PaymentGateway::all()->map(function ($g) {
            return [
                'id' => $g->id,
                'name' => $g->name,
                'display_name' => $g->display_name,
                'is_enabled' => $g->is_enabled,
                'is_active' => $g->is_active,
                'is_configured' => $g->isConfigured(),
                'environment' => $g->environment,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'active_gateway' => $active ? $active->name : null,
                'gateways' => $gateways,
            ],
        ]);
    }

    // ==========================================
    // PRIVATE METHODS
    // ==========================================

    private function ensureDefaultGatewaysExist()
    {
        PaymentGateway::firstOrCreate(
            ['name' => 'midtrans'],
            [
                'display_name' => 'Midtrans',
                'description' => 'Payment gateway untuk Indonesia (QRIS, GoPay, OVO, Bank Transfer, Kartu Kredit)',
                'is_enabled' => false,
                'is_active' => false,
                'environment' => 'sandbox',
            ]
        );

        PaymentGateway::firstOrCreate(
            ['name' => 'xendit'],
            [
                'display_name' => 'Xendit',
                'description' => 'Payment gateway untuk Southeast Asia (Virtual Account, E-Wallet, QRIS)',
                'is_enabled' => false,
                'is_active' => false,
                'environment' => 'sandbox',
            ]
        );
    }

    private function testMidtransConnection(PaymentGateway $gateway): \Illuminate\Http\JsonResponse
    {
        // Read server_key from DATABASE (decrypted by model accessor)
        // Fallback: try raw column + manual decrypt if accessor silently fails
        $serverKey = $gateway->server_key;

        if (empty($serverKey)) {
            // Fallback: check if raw encrypted value exists but decryption failed
            $rawValue = $gateway->getRawOriginal('server_key');
            if (!empty($rawValue)) {
                try {
                    $serverKey = Crypt::decryptString($rawValue);
                } catch (\Exception $e) {
                    Log::error('Midtrans server_key decryption failed', [
                        'gateway_id' => $gateway->id,
                        'error' => $e->getMessage(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Server Key tersimpan tapi gagal didekripsi. Silakan simpan ulang Server Key.',
                    ]);
                }
            }
        }

        if (empty($serverKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Server Key belum diisi. Simpan Server Key terlebih dahulu, lalu klik Test.',
            ]);
        }

        // Dynamic endpoint based on environment from DB
        $isProduction = $gateway->environment === 'production';
        $baseUrl = $isProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
        $endpoint = $baseUrl . '/v2/status/TEST-AUTH-CHECK';
        
        // Build Basic Auth header
        // WAJIB: server_key + ":" (titik dua) sebelum base64 encode
        $authString = base64_encode($serverKey . ':');
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Basic ' . $authString,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            // Log for debugging
            \Log::info('Midtrans connection test', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 300),
            ]);

            // Connection error
            if ($curlErrno !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Koneksi gagal: ' . $curlError,
                    'error_code' => 'CONNECTION_ERROR',
                ]);
            }

            // HTTP 401 = Invalid credentials (FAIL)
            if ($httpCode === 401) {
                return response()->json([
                    'success' => false,
                    'message' => 'Server Key tidak valid. Periksa kembali credentials Anda.',
                    'environment' => ucfirst($gateway->environment),
                    'http_code' => $httpCode,
                ]);
            }

            // HTTP 403 = Forbidden
            if ($httpCode === 403) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak (403 Forbidden).',
                    'error_code' => 'FORBIDDEN',
                ]);
            }

            // Check if response is HTML (network/proxy issue)
            $trimmedResponse = trim($response);
            if (str_starts_with($trimmedResponse, '<') || str_starts_with($trimmedResponse, '<!DOCTYPE')) {
                \Log::warning('Midtrans returned HTML', ['response' => substr($response, 0, 500)]);
                return response()->json([
                    'success' => false,
                    'message' => 'Response bukan JSON. Kemungkinan ada masalah jaringan.',
                    'error_code' => 'HTML_RESPONSE',
                ]);
            }

            // HTTP 404 = Auth valid, order tidak ditemukan = SUCCESS
            // HTTP 200 = Auth valid = SUCCESS
            // Any other 2xx/4xx with non-HTML = connection works
            if ($httpCode === 404 || $httpCode === 200 || ($httpCode >= 200 && $httpCode < 500)) {
                $gateway->update(['last_verified_at' => now()]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Koneksi ke Midtrans berhasil! Server Key valid.',
                    'environment' => ucfirst($gateway->environment),
                    'http_code' => $httpCode,
                ]);
            }

            // Other errors
            return response()->json([
                'success' => false,
                'message' => 'Midtrans returned HTTP ' . $httpCode,
                'http_code' => $httpCode,
            ]);

        } catch (\Exception $e) {
            \Log::error('Midtrans test exception', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Test connection error: ' . $e->getMessage(),
                'error_code' => 'EXCEPTION',
            ]);
        }
    }

    private function testXenditConnection(PaymentGateway $gateway): \Illuminate\Http\JsonResponse
    {
        // Read secret key from DATABASE (decrypted by model accessor)
        $secretKey = $gateway->server_key;

        if (empty($secretKey)) {
            // Fallback: try raw column + manual decrypt
            $rawValue = $gateway->getRawOriginal('server_key');
            if (!empty($rawValue)) {
                try {
                    $secretKey = Crypt::decryptString($rawValue);
                } catch (\Exception $e) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Secret Key tersimpan tapi gagal didekripsi. Silakan simpan ulang.',
                    ]);
                }
            }
        }

        if (empty($secretKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Secret Key belum diisi. Simpan Secret Key terlebih dahulu.',
            ]);
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.xendit.co/balance');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $gateway->update(['last_verified_at' => now()]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Koneksi ke Xendit berhasil! Credentials valid.',
                    'environment' => $gateway->isProduction() ? 'Production' : 'Sandbox',
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Xendit API returned HTTP ' . $httpCode,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test connection error: ' . $e->getMessage(),
            ]);
        }
    }

    private function logAudit(string $action, PaymentGateway $gateway, ?array $oldValues, ?array $newValues)
    {
        try {
            AuditLog::create([
                'actor_id' => auth()->id(),
                'actor_role' => auth()->user()->role ?? 'owner',
                'target_type' => 'payment_gateway',
                'target_id' => $gateway->id,
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'status' => 'success',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create audit log', ['error' => $e->getMessage()]);
        }
    }
}
