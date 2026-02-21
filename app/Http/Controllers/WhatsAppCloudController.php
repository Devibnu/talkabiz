<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConnection;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappContact;
use App\Services\GupshupService;
use App\Services\RevenueGuardService;
use App\Services\PlanLimitService;
use App\Exceptions\Subscription\PlanLimitExceededException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppCloudController extends Controller
{
    /**
     * WhatsApp main page - show connection status
     */
    public function index()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return redirect()->route('dashboard')
                ->with('error', 'Anda harus memiliki profil klien untuk menggunakan WhatsApp.');
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        $templates = WhatsappTemplate::where('klien_id', $klien->id)
            ->approved()
            ->latest()
            ->get();

        return view('whatsapp.cloud-index', compact('connection', 'templates', 'klien'));
    }

    /**
     * Show connection setup page
     */
    public function setup()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return redirect()->route('dashboard')
                ->with('error', 'Profil klien diperlukan.');
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();

        return view('whatsapp.cloud-setup', compact('connection', 'klien'));
    }

    /**
     * Initiate WhatsApp Business connection via Gupshup
     * 
     * FLOW SaaS:
     * 1. User input: nomor WA + nama bisnis (TIDAK ada API key)
     * 2. Backend: ambil API key dari .env (platform-owned)
     * 3. Register nomor ke Gupshup via Partner API
     * 4. Webhook akan update status koneksi
     */
    public function connect(Request $request)
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Klien tidak ditemukan'], 404);
            }
            return back()->with('error', 'Profil bisnis tidak ditemukan.');
        }

        // Validate input - HANYA nomor WA dan nama bisnis (NO API key)
        $request->validate([
            'phone_number' => 'required|string|regex:/^62[0-9]{9,12}$/',
            'business_name' => 'required|string|min:3|max:100',
        ], [
            'phone_number.required' => 'Nomor WhatsApp wajib diisi',
            'phone_number.regex' => 'Format nomor: 628xxxxxxxxxx',
            'business_name.required' => 'Nama bisnis wajib diisi',
        ]);

        try {
            // HARD LIMIT: Enforce WA number limit from plan
            app(PlanLimitService::class)->enforceWaNumberLimit(auth()->user());

            // Create or update connection with PLATFORM API key (from .env)
            $connection = WhatsappConnection::updateOrCreate(
                ['klien_id' => $klien->id],
                [
                    'phone_number' => $request->phone_number,
                    'business_name' => $request->business_name,
                    'gupshup_app_id' => config('services.gupshup.app_id'),
                    'api_key' => encrypt(config('services.gupshup.api_key')), // Platform API key
                    'status' => WhatsappConnection::STATUS_PENDING,
                    'last_status_change' => now(),
                ]
            );

            // Register number via Gupshup Partner API
            $gupshup = new GupshupService();
            $result = $gupshup->registerPhoneNumber(
                $request->phone_number,
                $request->business_name,
                $klien->id
            );

            if (isset($result['success']) && $result['success']) {
                Log::info('WhatsApp Cloud: Phone registration initiated', [
                    'klien_id' => $klien->id,
                    'phone' => $request->phone_number,
                ]);

                if ($request->wantsJson()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Nomor WhatsApp sedang diverifikasi. Status akan diperbarui secara otomatis.',
                        'connection_id' => $connection->id,
                    ]);
                }

                return redirect()->route('whatsapp.index')
                    ->with('success', 'Nomor WhatsApp sedang diverifikasi. Tunggu konfirmasi via webhook.');
            }

            // Registration failed
            $connection->update(['status' => WhatsappConnection::STATUS_FAILED]);
            
            $errorMsg = $result['error'] ?? 'Gagal mendaftarkan nomor WhatsApp.';
            
            if ($request->wantsJson()) {
                return response()->json(['error' => $errorMsg], 422);
            }
            
            return back()->with('error', $errorMsg);

        } catch (PlanLimitExceededException $e) {
            Log::info('WA Connect blocked by plan limit', $e->getContext());

            if ($request->wantsJson()) {
                return response()->json($e->toArray(), $e->getHttpStatusCode());
            }

            return back()->with('error', $e->getUserMessage());
        } catch (Exception $e) {
            Log::error('WhatsApp Cloud: Connection failed', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);

            if ($request->wantsJson()) {
                return response()->json(['error' => 'Terjadi kesalahan. Silakan coba lagi.'], 500);
            }

            return back()->with('error', 'Terjadi kesalahan saat menghubungkan WhatsApp.');
        }
    }

    /**
     * Callback from Gupshup after authorization
     */
    public function callback(Request $request)
    {
        $klienId = $request->get('state'); // We pass klien_id as state
        $appId = $request->get('app_id');
        $status = $request->get('status', 'success');

        if ($status !== 'success') {
            Log::warning('WhatsApp Cloud: Authorization failed', [
                'klien_id' => $klienId,
                'status' => $status,
            ]);
            
            return redirect()->route('whatsapp.index')
                ->with('error', 'Otorisasi WhatsApp Business gagal. Silakan coba lagi.');
        }

        // Update connection
        $connection = WhatsappConnection::where('klien_id', $klienId)->first();
        
        if ($connection) {
            $connection->update([
                'gupshup_app_id' => $appId ?: $connection->gupshup_app_id,
                'status' => WhatsappConnection::STATUS_PENDING,
            ]);
        }

        Log::info('WhatsApp Cloud: Authorization callback received', [
            'klien_id' => $klienId,
            'app_id' => $appId,
        ]);

        return redirect()->route('whatsapp.index')
            ->with('success', 'WhatsApp Business sedang dalam proses verifikasi. Status akan diperbarui secara otomatis.');
    }

    /**
     * Store API credentials manually
     */
    public function storeCredentials(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
            'api_secret' => 'nullable|string',
            'phone_number' => 'required|string',
            'business_name' => 'required|string',
        ]);

        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return back()->with('error', 'Klien tidak ditemukan');
        }

        $connection = WhatsappConnection::updateOrCreate(
            ['klien_id' => $klien->id],
            [
                'gupshup_app_id' => config('services.gupshup.app_id'),
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret,
                'phone_number' => $request->phone_number,
                'business_name' => $request->business_name,
                'status' => WhatsappConnection::STATUS_PENDING,
            ]
        );

        // Verify connection by fetching app details
        try {
            $service = GupshupService::forConnection($connection);
            $health = $service->getHealthStatus();
            
            if (isset($health['success']) && $health['success']) {
                $connection->markAsConnected();
                
                // Sync templates
                $service->syncTemplates($klien->id);
                
                return redirect()->route('whatsapp.index')
                    ->with('success', 'WhatsApp Business berhasil terhubung!');
            }
        } catch (Exception $e) {
            Log::error('WhatsApp Cloud: Failed to verify credentials', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()->route('whatsapp.index')
            ->with('info', 'Kredensial disimpan. Status akan diverifikasi via webhook.');
    }

    /**
     * Disconnect WhatsApp
     * Always returns JSON for AJAX compatibility
     */
    public function disconnect(Request $request)
    {
        try {
            $klien = auth()->user()->klien;
            
            if (!$klien) {
                return response()->json([
                    'success' => false,
                    'message' => 'Klien tidak ditemukan',
                ], 404);
            }

            $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
            
            if (!$connection) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada koneksi WhatsApp yang aktif.',
                ], 404);
            }

            $connection->markAsDisconnected();
            
            Log::info('WhatsApp Cloud: Disconnected', [
                'klien_id' => $klien->id,
                'connection_id' => $connection->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'WhatsApp Business berhasil diputuskan.',
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp Cloud: Disconnect failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memutuskan WhatsApp: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get connection status
     */
    public function status()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();

        if (!$connection) {
            return response()->json([
                'connected' => false,
                'status' => 'disconnected',
                'message' => 'Belum ada koneksi WhatsApp Business',
            ]);
        }

        return response()->json([
            'connected' => $connection->isConnected(),
            'status' => $connection->status,
            'business_name' => $connection->business_name,
            'phone_number' => $connection->phone_number,
            'connected_at' => $connection->connected_at?->toISOString(),
        ]);
    }

    /**
     * Sync templates from Gupshup
     */
    public function syncTemplates()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return response()->json(['error' => 'WhatsApp tidak terhubung'], 400);
        }

        try {
            $service = GupshupService::forConnection($connection);
            $result = $service->syncTemplates($klien->id);

            return response()->json([
                'success' => true,
                'synced' => $result['synced'],
                'message' => "Berhasil sinkronisasi {$result['synced']} template.",
            ]);
        } catch (Exception $e) {
            Log::error('WhatsApp Cloud: Failed to sync templates', [
                'klien_id' => $klien->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Gagal sinkronisasi template: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * List templates
     */
    public function templates()
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $templates = WhatsappTemplate::where('klien_id', $klien->id)
            ->orderBy('status')
            ->orderBy('name')
            ->get();

        return response()->json([
            'templates' => $templates,
        ]);
    }

    /**
     * List contacts
     */
    public function contacts(Request $request)
    {
        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $query = WhatsappContact::where('klien_id', $klien->id);

        // Filter by opt-in status
        if ($request->has('opted_in')) {
            $query->where('opted_in', $request->boolean('opted_in'));
        }

        // Filter by tag
        if ($request->has('tag')) {
            $query->withTag($request->get('tag'));
        }

        $contacts = $query->orderBy('name')->paginate(50);

        return response()->json($contacts);
    }

    /**
     * Import contacts
     */
    public function importContacts(Request $request)
    {
        $request->validate([
            'contacts' => 'required|array',
            'contacts.*.phone' => 'required|string',
            'contacts.*.name' => 'nullable|string',
            'contacts.*.email' => 'nullable|email',
            'contacts.*.tags' => 'nullable|array',
            'opt_in' => 'boolean',
        ]);

        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $imported = 0;
        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        $gupshup = $connection ? GupshupService::forConnection($connection) : null;

        foreach ($request->contacts as $contact) {
            $phoneNumber = WhatsappContact::normalizePhoneNumber($contact['phone']);
            
            $waContact = WhatsappContact::updateOrCreate(
                [
                    'klien_id' => $klien->id,
                    'phone_number' => $phoneNumber,
                ],
                [
                    'name' => $contact['name'] ?? null,
                    'email' => $contact['email'] ?? null,
                    'tags' => $contact['tags'] ?? null,
                    'opt_in_source' => WhatsappContact::SOURCE_IMPORT,
                ]
            );

            // Auto opt-in if requested and connection exists
            if ($request->boolean('opt_in') && $gupshup) {
                try {
                    $gupshup->optInUser($phoneNumber);
                    $waContact->optIn(WhatsappContact::SOURCE_IMPORT);
                } catch (Exception $e) {
                    Log::warning('Failed to opt-in contact', [
                        'phone' => $phoneNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $imported++;
        }

        return response()->json([
            'success' => true,
            'imported' => $imported,
            'message' => "Berhasil import {$imported} kontak.",
        ]);
    }

    /**
     * Send test message — Revenue Locked (chargeAndExecute)
     * Sends 1 real WA message: deduct saldo atomically.
     */
    public function sendTestMessage(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'template_id' => 'required|exists:whatsapp_templates,id',
            'params' => 'nullable|array',
        ]);

        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return response()->json(['error' => 'WhatsApp tidak terhubung'], 400);
        }

        $template = WhatsappTemplate::find($request->template_id);
        
        if (!$template || !$template->isApproved()) {
            return response()->json(['error' => 'Template tidak valid atau belum disetujui'], 400);
        }

        try {
            // ============ REVENUE GUARD LAYER 4: chargeAndExecute (atomic) ============
            $revenueGuard = app(RevenueGuardService::class);
            $sendRef = abs(crc32("test_msg_{$klien->id}_" . floor(time() / 5)));

            $guardResult = $revenueGuard->chargeAndExecute(
                userId: auth()->id(),
                messageCount: 1,
                category: 'utility',
                referenceType: 'test_message',
                referenceId: $sendRef,
                dispatchCallable: function ($transaction) use ($connection, $request, $template, $klien) {
                    $service = GupshupService::forConnection($connection);
                    return $service->sendTemplateMessage(
                        destination: $request->phone_number,
                        templateId: $template->template_id,
                        params: $request->params ?? [],
                        klienId: $klien->id
                    );
                },
                costPreview: $request->attributes->get('revenue_guard', []),
            );

            if ($guardResult['duplicate'] ?? false) {
                return response()->json([
                    'success' => true,
                    'message' => $guardResult['message'],
                ]);
            }

            $result = $guardResult['dispatch_result'];

            return response()->json([
                'success' => true,
                'message_id' => $result['messageId'] ?? null,
                'message' => 'Pesan test berhasil dikirim.',
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_balance',
                'message' => $e->getMessage(),
                'topup_url' => route('billing'),
            ], 402);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Gagal mengirim pesan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate Gupshup authorization URL
     */
    protected function getGupshupAuthUrl(int $klienId): string
    {
        // Gupshup embedded signup or partner portal URL
        // This URL should be configured based on your Gupshup partner account
        $baseUrl = 'https://apps.gupshup.io/whatsapp/onboard';
        $appId = config('services.gupshup.app_id');
        $callbackUrl = route('whatsapp.callback');
        
        return "{$baseUrl}?app_id={$appId}&redirect_uri=" . urlencode($callbackUrl) . "&state={$klienId}";
    }
}
