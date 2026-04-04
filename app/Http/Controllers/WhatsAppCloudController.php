<?php

namespace App\Http\Controllers;

use App\Models\WhatsappConnection;
use App\Models\WhatsappTemplate;
use App\Models\WhatsappContact;
use App\Models\TemplatePesan;
use App\Services\GupshupService;
use App\Services\RevenueGuardService;
use App\Services\PlanLimitService;
use App\Services\WhatsAppProviderService;
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
        $user = auth()->user();
        $klien = $user->klien;

        Log::info('[WhatsApp.index] Page accessed', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'klien_id' => $user->klien_id,
            'has_klien' => (bool)$klien,
            'is_impersonating' => $user->isImpersonating(),
        ]);
        
        if (!$klien) {
            // Owner/admin in CLIENT VIEW mode — show empty state
            if (in_array($user->role, ['super_admin', 'superadmin', 'owner'], true)) {
                $connection = null;
                $templates = collect();
                $klien = null;
                return view('whatsapp.cloud-index', compact('connection', 'templates', 'klien'));
            }
            
            Log::warning('[WhatsApp.index] User has no klien, redirecting to dashboard', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            
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
        Log::info('[WhatsApp.connect] POST request received', [
            'user_id' => auth()->id(),
            'email' => auth()->user()->email,
            'role' => auth()->user()->role,
            'klien_id' => auth()->user()->klien_id,
            'input' => $request->only(['phone_number', 'business_name']),
            'is_impersonating' => auth()->user()->isImpersonating(),
        ]);

        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Aksi tidak diizinkan dalam mode lihat saja.'], 403);
            }
            return back()->with('error', 'Aksi tidak diizinkan dalam mode lihat saja.');
        }

        $klien = auth()->user()->klien;
        
        if (!$klien) {
            if ($request->wantsJson()) {
                return response()->json(['error' => 'Klien tidak ditemukan'], 404);
            }
            return back()->with('error', 'Profil bisnis tidak ditemukan.');
        }

        // Clean phone number: strip +, spaces, dashes; convert 08xx → 628xx
        $rawPhone = preg_replace('/[\s\-\+]/', '', $request->input('phone_number', ''));
        if (str_starts_with($rawPhone, '0')) {
            $rawPhone = '62' . substr($rawPhone, 1);
        }
        $request->merge(['phone_number' => $rawPhone]);

        // Validate input - HANYA nomor WA dan nama bisnis (NO API key)
        $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^62[0-9]{9,13}$/'],
            'business_name' => 'required|string|min:3|max:100',
        ], [
            'phone_number.required' => 'Nomor WhatsApp wajib diisi.',
            'phone_number.regex' => 'Format nomor harus 62xxxxxxxxxx (contoh: 628123456789).',
            'business_name.required' => 'Nama bisnis wajib diisi.',
            'business_name.min' => 'Nama bisnis minimal 3 karakter.',
            'business_name.max' => 'Nama bisnis maksimal 100 karakter.',
        ]);

        try {
            // HARD LIMIT: Enforce WA number limit from plan (skip if user has no plan yet)
            $user = auth()->user();
            if ($user->currentPlan) {
                app(PlanLimitService::class)->enforceWaNumberLimit($user);
            }

            // Check if Gupshup API is configured
            $gupshupApiKey = config('services.gupshup.api_key');
            $isGupshupConfigured = $gupshupApiKey && !str_contains($gupshupApiKey, 'your-');
            $provider = $this->resolveConnectionProvider($isGupshupConfigured);

            // Create or update connection record
            $connection = WhatsappConnection::updateOrCreate(
                ['klien_id' => $klien->id],
                [
                    'provider' => $provider,
                    'connection_name' => $this->buildConnectionName($request->business_name),
                    'phone_number' => $request->phone_number,
                    'business_name' => $request->business_name,
                    'connected_by_user_id' => $user->id,
                    'verification_status' => $isGupshupConfigured ? 'pending' : 'verified',
                    'gupshup_app_id' => config('services.gupshup.app_id'),
                    'meta_app_id' => $provider === 'meta_cloud' ? config('app.meta_app_id') : null,
                    'status' => $isGupshupConfigured
                        ? WhatsappConnection::STATUS_PENDING
                        : WhatsappConnection::STATUS_CONNECTED,
                    'connected_at' => $isGupshupConfigured ? null : now(),
                    'disconnected_at' => null,
                    'failed_at' => null,
                    'error_reason' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]
            );

            // Store provider API key on connection (model mutator encrypts it)
            if ($gupshupApiKey) {
                $connection->api_key = $gupshupApiKey;
                $connection->save();
            }

            // If Gupshup configured → register via Partner API
            if ($isGupshupConfigured) {
                $gupshup = new GupshupService();
                $result = $gupshup->registerPhoneNumber(
                    $request->phone_number,
                    $request->business_name,
                    $klien->id
                );

                if (isset($result['success']) && $result['success']) {
                    $connection->update([
                        'webhook_last_update' => now(),
                        'last_webhook_payload' => $result['data'] ?? null,
                    ]);

                    Log::info('WhatsApp Cloud: Phone registration initiated via Gupshup', [
                        'klien_id' => $klien->id,
                        'phone' => $request->phone_number,
                    ]);
                } else {
                    $errorMsg = $result['error'] ?? 'Gagal mendaftarkan nomor WhatsApp.';
                    $connection->markAsFailed($errorMsg);

                    Log::warning('WhatsApp Cloud: Gupshup registration failed', [
                        'klien_id' => $klien->id,
                        'error' => $errorMsg,
                    ]);

                    if ($request->wantsJson()) {
                        return response()->json(['error' => $errorMsg], 422);
                    }
                    return back()->with('error', $errorMsg);
                }
            } else {
                // Gupshup NOT configured — direct connect mode
                // Mark connection as connected immediately
                $connection->markAsConnected();
                $connection->update([
                    'webhook_last_update' => now(),
                ]);

                $this->syncLegacyKlienState($klien, $connection);

                Log::info('WhatsApp Connected (direct mode — Gupshup not configured)', [
                    'klien_id' => $klien->id,
                    'phone_number' => $request->phone_number,
                    'business_name' => $request->business_name,
                ]);
            }

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $isGupshupConfigured
                        ? 'Nomor WhatsApp sedang diverifikasi. Status akan diperbarui secara otomatis.'
                        : 'WhatsApp Business berhasil terhubung!',
                    'connection_id' => $connection->id,
                ]);
            }

            return redirect()->route('whatsapp.index')
                ->with('success', $isGupshupConfigured
                    ? 'Nomor WhatsApp sedang diverifikasi. Tunggu konfirmasi via webhook.'
                    : 'WhatsApp Business berhasil terhubung!');

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
                'trace' => $e->getTraceAsString(),
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

        $connection = WhatsappConnection::where('klien_id', $klienId)->first();

        if ($status !== 'success') {
            if ($connection) {
                $connection->markAsFailed('Otorisasi provider gagal.');
            }

            Log::warning('WhatsApp Cloud: Authorization failed', [
                'klien_id' => $klienId,
                'status' => $status,
            ]);
            
            return redirect()->route('whatsapp.index')
                ->with('error', 'Otorisasi WhatsApp Business gagal. Silakan coba lagi.');
        }

        if ($connection) {
            $connection->update([
                'provider' => $connection->provider ?: 'gupshup',
                'gupshup_app_id' => $appId ?: $connection->gupshup_app_id,
                'status' => WhatsappConnection::STATUS_PENDING,
                'verification_status' => 'pending',
                'webhook_last_update' => now(),
                'last_webhook_payload' => $request->all(),
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
        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            return back()->with('error', 'Aksi tidak diizinkan dalam mode lihat saja.');
        }

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
                'provider' => 'gupshup',
                'connection_name' => $this->buildConnectionName($request->business_name),
                'gupshup_app_id' => config('services.gupshup.app_id'),
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret,
                'phone_number' => $request->phone_number,
                'business_name' => $request->business_name,
                'connected_by_user_id' => auth()->id(),
                'verification_status' => 'pending',
                'status' => WhatsappConnection::STATUS_PENDING,
            ]
        );

        // Verify connection by fetching app details
        try {
            $service = GupshupService::forConnection($connection);
            $health = $service->getHealthStatus();
            
            if (isset($health['success']) && $health['success']) {
                $connection->markAsConnected();
                $connection->update([
                    'webhook_last_update' => now(),
                    'verification_status' => 'verified',
                ]);
                $this->syncLegacyKlienState($klien, $connection);
                
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
        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            return response()->json([
                'success' => false,
                'message' => 'Aksi tidak diizinkan dalam mode lihat saja.',
            ], 403);
        }

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
            $connection->update([
                'verification_status' => 'disconnected',
                'webhook_last_update' => now(),
            ]);
            $this->clearLegacyKlienState($klien);
            
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
        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            return response()->json(['success' => false, 'message' => 'Aksi tidak diizinkan dalam mode lihat saja.'], 403);
        }

        $klien = auth()->user()->klien;
        
        if (!$klien) {
            return response()->json(['error' => 'Klien tidak ditemukan'], 404);
        }

        $connection = WhatsappConnection::where('klien_id', $klien->id)->first();
        
        if (!$connection || !$connection->isConnected()) {
            return response()->json(['error' => 'WhatsApp tidak terhubung'], 400);
        }

        try {
            // Use Meta Graph API directly when provider is meta_cloud
            if ($connection->provider === 'meta_cloud' && $connection->waba_id && $connection->access_token) {
                $result = $this->syncTemplatesFromMeta($connection, $klien->id);
            } else {
                $service = GupshupService::forConnection($connection);
                $result = $service->syncTemplates($klien->id);
            }

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
     * Sync templates directly from Meta Graph API
     */
    protected function syncTemplatesFromMeta(WhatsappConnection $connection, int $klienId): array
    {
        $graphVersion = env('WHATSAPP_GRAPH_VERSION', 'v22.0');
        $url = "https://graph.facebook.com/{$graphVersion}/{$connection->waba_id}/message_templates";

        $response = \Illuminate\Support\Facades\Http::withToken($connection->getDecryptedAccessToken())
            ->acceptJson()
            ->timeout(30)
            ->get($url, ['limit' => 100]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown Meta API error');
            throw new \Exception("Meta API error: {$error}");
        }

        $templates = $response->json('data', []);
        $synced = 0;

        foreach ($templates as $template) {
            $bodyText = null;
            $components = $template['components'] ?? [];
            foreach ($components as $comp) {
                if (($comp['type'] ?? '') === 'BODY') {
                    $bodyText = $comp['text'] ?? null;
                    break;
                }
            }

            WhatsappTemplate::updateOrCreate(
                [
                    'klien_id' => $klienId,
                    'template_id' => $template['name'],
                ],
                [
                    'name' => $template['name'],
                    'category' => $template['category'] ?? null,
                    'language' => $template['language'] ?? 'id',
                    'components' => json_encode($components),
                    'sample_text' => $bodyText,
                    'status' => strtolower($template['status'] ?? 'pending') === 'approved' ? 'approved' : strtolower($template['status'] ?? 'pending'),
                    'rejection_reason' => $template['rejected_reason'] ?? null,
                ]
            );

            // Also update TemplatePesan status if this template was submitted from Talkabiz
            $metaStatus = strtolower($template['status'] ?? 'pending');
            $templatePesan = TemplatePesan::where('klien_id', $klienId)
                ->where('status', TemplatePesan::STATUS_DIAJUKAN)
                ->where(function ($q) use ($template) {
                    $q->where('provider_template_id', $template['id'] ?? '')
                      ->orWhere(\Illuminate\Support\Facades\DB::raw("LOWER(REPLACE(REPLACE(nama_template, ' ', '_'), '-', '_'))"), $template['name']);
                })
                ->first();

            if ($templatePesan) {
                if ($metaStatus === 'approved') {
                    $templatePesan->update([
                        'status' => TemplatePesan::STATUS_DISETUJUI,
                        'approved_at' => now(),
                    ]);
                } elseif ($metaStatus === 'rejected') {
                    $templatePesan->update([
                        'status' => TemplatePesan::STATUS_DITOLAK,
                        'alasan_penolakan' => $template['rejected_reason'] ?? 'Ditolak oleh Meta',
                    ]);
                }
            }

            $synced++;
        }

        return ['synced' => $synced];
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
        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            return response()->json(['success' => false, 'message' => 'Aksi tidak diizinkan dalam mode lihat saja.'], 403);
        }

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
        // IMPERSONATION GUARD: Block mutating actions during impersonation
        if (auth()->user()->isImpersonating()) {
            return response()->json(['success' => false, 'message' => 'Aksi tidak diizinkan dalam mode lihat saja.'], 403);
        }

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
                dispatchCallable: function ($transaction) use ($request, $template, $klien) {
                    $service = app(WhatsAppProviderService::class);

                    return $service->sendTemplateMessage(
                        phone: $request->phone_number,
                        templateId: $template->template_id,
                        bodyParams: $request->params ?? [],
                        components: [],
                        klienId: $klien->id,
                        penggunaId: auth()->id()
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
            $messageId = $result['message_id']
                ?? $result['messageId']
                ?? $result['response']['messages'][0]['id']
                ?? $result['response']['messageId']
                ?? null;

            return response()->json([
                'success' => true,
                'message_id' => $messageId,
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

    protected function resolveConnectionProvider(bool $isGupshupConfigured): string
    {
        return $isGupshupConfigured ? 'gupshup' : 'meta_cloud';
    }

    protected function buildConnectionName(string $businessName): string
    {
        $businessName = trim($businessName);

        return $businessName !== '' ? $businessName : 'WhatsApp Utama';
    }

    protected function syncLegacyKlienState($klien, WhatsappConnection $connection): void
    {
        $klien->update([
            'no_whatsapp' => $connection->phone_number ?: $klien->no_whatsapp,
            'wa_phone_number_id' => $connection->phone_number_id ?: $klien->wa_phone_number_id,
            'wa_business_account_id' => $connection->waba_id ?: $klien->wa_business_account_id,
            'wa_access_token' => $connection->access_token ?: $klien->wa_access_token,
            'wa_terhubung' => $connection->isConnected(),
            'wa_terakhir_sync' => $connection->webhook_last_update ?: $connection->connected_at ?: $klien->wa_terakhir_sync,
        ]);
    }

    protected function clearLegacyKlienState($klien): void
    {
        $klien->update([
            'wa_phone_number_id' => null,
            'wa_business_account_id' => null,
            'wa_access_token' => null,
            'wa_terhubung' => false,
            'wa_terakhir_sync' => now(),
        ]);
    }
}
