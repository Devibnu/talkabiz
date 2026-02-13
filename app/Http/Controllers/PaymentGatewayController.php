<?php

namespace App\Http\Controllers;

use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentGatewayController extends Controller
{
    /**
     * Constructor - Apply middleware
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if (!$user || $user->role !== 'super_admin') {
                abort(403, 'Akses ditolak. Hanya Super Admin yang dapat mengakses halaman ini.');
            }
            return $next($request);
        });
    }

    /**
     * Display payment gateway settings page
     */
    public function index()
    {
        $gateways = PaymentGateway::all()->keyBy('name');
        
        // Ensure both gateways exist
        $midtrans = $gateways->get('midtrans') ?? $this->createDefaultGateway('midtrans');
        $xendit = $gateways->get('xendit') ?? $this->createDefaultGateway('xendit');
        
        return view('settings.payment-gateway', [
            'midtrans' => $midtrans,
            'xendit' => $xendit,
            'activeGateway' => PaymentGateway::getActive(),
        ]);
    }

    /**
     * Update Midtrans configuration
     */
    public function updateMidtrans(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'environment' => 'required|in:sandbox,production',
            'server_key' => 'nullable|string|max:255',
            'client_key' => 'nullable|string|max:255',
            'is_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $gateway = PaymentGateway::firstOrCreate(
                ['name' => 'midtrans'],
                [
                    'display_name' => 'Midtrans',
                    'description' => 'Payment gateway untuk Indonesia (QRIS, GoPay, OVO, Bank Transfer, dll)',
                ]
            );

            $updateData = [
                'environment' => $request->environment,
                'is_enabled' => $request->boolean('is_enabled'),
                'updated_by' => auth()->id(),
            ];

            // Only update keys if provided (not empty)
            if ($request->filled('server_key')) {
                $updateData['server_key'] = $request->server_key;
            }
            if ($request->filled('client_key')) {
                $updateData['client_key'] = $request->client_key;
            }

            $gateway->update($updateData);

            // If disabling and this was active, deactivate it
            if (!$request->boolean('is_enabled') && $gateway->is_active) {
                $gateway->update(['is_active' => false]);
            }

            // IMPORTANT: If enabling Midtrans, disable Xendit (mutual exclusion)
            if ($request->boolean('is_enabled') && $gateway->is_active) {
                PaymentGateway::where('name', 'xendit')->update(['is_active' => false]);
            }

            Log::info('Midtrans configuration updated', [
                'admin_id' => auth()->id(),
                'environment' => $request->environment,
                'is_enabled' => $request->boolean('is_enabled'),
            ]);

            return back()->with('success', 'Konfigurasi Midtrans berhasil diperbarui.');

        } catch (\Exception $e) {
            Log::error('Failed to update Midtrans configuration', [
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal memperbarui konfigurasi Midtrans.');
        }
    }

    /**
     * Update Xendit configuration
     * HARDENED: Cannot enable if API keys are empty
     */
    public function updateXendit(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'environment' => 'required|in:sandbox,production',
            'server_key' => 'nullable|string|max:255',
            'client_key' => 'nullable|string|max:255',
            'webhook_secret' => 'nullable|string|max:255',
            'is_enabled' => 'boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        try {
            $gateway = PaymentGateway::firstOrCreate(
                ['name' => 'xendit'],
                [
                    'display_name' => 'Xendit',
                    'description' => 'Payment gateway untuk Southeast Asia (Virtual Account, E-Wallet, dll)',
                ]
            );

            // HARDENING: Jika mau enable, harus punya API key (at least server_key)
            if ($request->boolean('is_enabled')) {
                $hasServerKey = $request->filled('server_key') || !empty($gateway->server_key);
                
                if (!$hasServerKey) {
                    return back()->withErrors([
                        'is_enabled' => 'Tidak dapat mengaktifkan gateway. Secret Key (Server Key) wajib diisi.',
                    ])->withInput();
                }
            }

            $updateData = [
                'environment' => $request->environment,
                'is_enabled' => $request->boolean('is_enabled'),
                'updated_by' => auth()->id(),
            ];

            // Only update keys if provided
            if ($request->filled('server_key')) {
                $updateData['server_key'] = $request->server_key;
            }
            if ($request->filled('client_key')) {
                $updateData['client_key'] = $request->client_key;
            }
            if ($request->filled('webhook_secret')) {
                $updateData['webhook_secret'] = $request->webhook_secret;
            }

            $gateway->update($updateData);

            // If disabling and this was active, deactivate it
            if (!$request->boolean('is_enabled') && $gateway->is_active) {
                $gateway->update(['is_active' => false]);
            }

            // IMPORTANT: If enabling Xendit, disable Midtrans (mutual exclusion)
            if ($request->boolean('is_enabled') && $gateway->is_active) {
                PaymentGateway::where('name', 'midtrans')->update(['is_active' => false]);
            }

            Log::info('Xendit configuration updated', [
                'admin_id' => auth()->id(),
                'environment' => $request->environment,
                'is_enabled' => $request->boolean('is_enabled'),
            ]);

            return back()->with('success', 'Konfigurasi Xendit berhasil diperbarui.');

        } catch (\Exception $e) {
            Log::error('Failed to update Xendit configuration', [
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Gagal memperbarui konfigurasi Xendit.');
        }
    }

    /**
     * Set active payment gateway
     */
    public function setActive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gateway' => 'required|in:midtrans,xendit',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        try {
            $gatewayName = $request->gateway;
            $gateway = PaymentGateway::where('name', $gatewayName)->first();

            if (!$gateway) {
                return back()->with('error', 'Gateway tidak ditemukan.');
            }

            if (!$gateway->is_enabled) {
                return back()->with('error', 'Gateway harus diaktifkan (enabled) terlebih dahulu sebelum dijadikan gateway aktif.');
            }

            if (!$gateway->isConfigured()) {
                return back()->with('error', 'Gateway belum dikonfigurasi. Silakan isi Server Key dan Client Key terlebih dahulu.');
            }

            $success = PaymentGateway::setActiveGateway($gatewayName);

            if ($success) {
                Log::info('Active payment gateway changed', [
                    'admin_id' => auth()->id(),
                    'gateway' => $gatewayName,
                ]);
                return back()->with('success', ucfirst($gatewayName) . ' berhasil dijadikan gateway aktif.');
            }

            return back()->with('error', 'Gagal mengubah gateway aktif.');

        } catch (\Exception $e) {
            Log::error('Failed to set active gateway', [
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Terjadi kesalahan saat mengubah gateway aktif.');
        }
    }

    /**
     * Create default gateway record
     */
    private function createDefaultGateway(string $name): PaymentGateway
    {
        $defaults = [
            'midtrans' => [
                'display_name' => 'Midtrans',
                'description' => 'Payment gateway untuk Indonesia (QRIS, GoPay, OVO, Bank Transfer, dll)',
            ],
            'xendit' => [
                'display_name' => 'Xendit',
                'description' => 'Payment gateway untuk Southeast Asia (Virtual Account, E-Wallet, dll)',
            ],
        ];

        return PaymentGateway::create([
            'name' => $name,
            'display_name' => $defaults[$name]['display_name'],
            'description' => $defaults[$name]['description'],
            'is_enabled' => false,
            'is_active' => false,
            'environment' => 'sandbox',
        ]);
    }

    /**
     * Test gateway connection (optional - for verification)
     */
    public function testConnection(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'gateway' => 'required|in:midtrans,xendit',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Invalid gateway'], 400);
        }

        $gateway = PaymentGateway::where('name', $request->gateway)->first();

        if (!$gateway || !$gateway->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Gateway belum dikonfigurasi. Simpan credentials terlebih dahulu.',
            ]);
        }

        if ($request->gateway === 'midtrans') {
            return $this->testMidtransConnection($gateway);
        }

        if ($request->gateway === 'xendit') {
            return $this->testXenditConnection($gateway);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gateway tidak dikenal.',
        ]);
    }

    /**
     * Test Midtrans connection using Basic Auth
     * 
     * REQUIREMENTS:
     * - Basic Auth: base64(server_key + ":")
     * - Endpoint: SANDBOX https://api.sandbox.midtrans.com/v2/status/TEST-AUTH-CHECK
     * - Method: GET
     * - Success: HTTP 404 (order not found but auth passed) or 200
     * - Fail: 401 (invalid key), 403 (forbidden), HTML response
     * 
     * NOTE: READ-ONLY, non-transactional. Uses dummy order ID.
     */
    private function testMidtransConnection(PaymentGateway $gateway): \Illuminate\Http\JsonResponse
    {
        // Get server_key from DATABASE (already decrypted by accessor)
        $serverKey = $gateway->server_key;
        
        if (empty($serverKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Server Key belum dikonfigurasi.',
            ]);
        }

        // Use /v2/status/{order_id} with dummy order ID
        // Returns 404 if auth valid (order not found), 401 if auth invalid
        $endpoint = 'https://api.sandbox.midtrans.com/v2/status/TEST-AUTH-CHECK';
        
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
                'http_code' => $httpCode,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200),
            ]);

            // Connection error (timeout, refused)
            if ($curlErrno !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Koneksi gagal: ' . $curlError,
                ]);
            }

            // HTTP 401 = Invalid credentials
            if ($httpCode === 401) {
                return response()->json([
                    'success' => false,
                    'message' => 'Server Key tidak valid. Periksa kembali credentials Anda.',
                ]);
            }

            // 403 Forbidden = FAIL
            if ($httpCode === 403) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akses ditolak (403 Forbidden).',
                ]);
            }

            // Check if response is HTML (network/proxy issue)
            $trimmedResponse = trim($response);
            if (str_starts_with($trimmedResponse, '<') || str_starts_with($trimmedResponse, '<!DOCTYPE')) {
                \Log::warning('Midtrans returned HTML', ['response' => substr($response, 0, 500)]);
                return response()->json([
                    'success' => false,
                    'message' => 'Response bukan JSON. Kemungkinan ada masalah jaringan.',
                ]);
            }

            // HTTP 404 = Auth valid, order tidak ditemukan = SUCCESS
            // HTTP 200 = Auth valid = SUCCESS
            // Any 2xx/4xx (except 401/403) with non-HTML = connection works
            if ($httpCode === 404 || $httpCode === 200 || ($httpCode >= 200 && $httpCode < 500)) {
                $gateway->update(['last_verified_at' => now()]);
                return response()->json([
                    'success' => true,
                    'message' => 'Koneksi ke Midtrans berhasil! Server Key valid.',
                    'verified_at' => now()->format('d M Y H:i'),
                ]);
            }

            // Other errors
            return response()->json([
                'success' => false,
                'message' => 'Midtrans returned HTTP ' . $httpCode,
            ]);

        } catch (\Exception $e) {
            \Log::error('Midtrans test error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Test Xendit connection
     */
    private function testXenditConnection(PaymentGateway $gateway): \Illuminate\Http\JsonResponse
    {
        $secretKey = $gateway->server_key;
        
        if (empty($secretKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Secret Key belum dikonfigurasi.',
            ]);
        }

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api.xendit.co/balance');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $gateway->update(['last_verified_at' => now()]);
                return response()->json([
                    'success' => true,
                    'message' => 'Koneksi ke Xendit berhasil!',
                    'verified_at' => now()->format('d M Y H:i'),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Xendit API returned HTTP ' . $httpCode,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
            ]);
        }
    }
}
