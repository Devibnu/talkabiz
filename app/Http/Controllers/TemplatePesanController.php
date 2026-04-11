<?php

namespace App\Http\Controllers;

use App\Models\TemplatePesan;
use App\Models\WhatsappConnection;
use App\Models\WhatsappTemplate;
use App\Services\OnboardingService;
use App\Services\WaBlastService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TemplatePesanController extends Controller
{
    private const ALLOWED_TEMPLATE_VARIABLES = [
        'nama',
        'telepon',
        'email',
        'kode',
        'otp',
        'produk',
        'harga',
        'tanggal',
        'no_order',
    ];

    protected OnboardingService $onboardingService;
    protected WaBlastService $waBlastService;

    public function __construct(OnboardingService $onboardingService, WaBlastService $waBlastService)
    {
        $this->onboardingService = $onboardingService;
        $this->waBlastService = $waBlastService;
    }

    /**
     * Display template list page
     */
    public function index()
    {
        $klienId = Auth::user()->klien_id;

        $connection = WhatsappConnection::where('klien_id', $klienId)
            ->where('status', WhatsappConnection::STATUS_CONNECTED)
            ->first();

        if ($connection) {
            try {
                $this->waBlastService->syncTemplatesIfStale($connection);
            } catch (\Throwable $e) {
                Log::warning('Passive template sync failed on template page', [
                    'klien_id' => $klienId,
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $templates = TemplatePesan::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->get();

        $syncedTemplates = WhatsappTemplate::where('klien_id', $klienId)
            ->orderBy('name')
            ->get();

        $latestTemplateSyncAt = $syncedTemplates
            ->pluck('synced_at')
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->sortDesc()
            ->first();

        $syncedByName = $syncedTemplates->keyBy(function (WhatsappTemplate $template) {
            return $this->normalizeTemplateName($template->name);
        });

        $matchedTemplateNames = [];

        $templates = $templates->map(function (TemplatePesan $template) use ($syncedByName, &$matchedTemplateNames) {
            $normalizedName = $this->normalizeTemplateName($template->nama_template ?: $template->nama_tampilan);
            $syncedTemplate = $syncedByName->get($normalizedName);

            $template->effective_status = $template->status;
            $template->meta_synced = false;
            $template->meta_rejection_reason = null;

            if ($syncedTemplate) {
                $matchedTemplateNames[$normalizedName] = true;
                $template->effective_status = $this->mapSyncedStatusToInternal($syncedTemplate->status);
                $template->meta_synced = true;
                $template->meta_status = $syncedTemplate->status;
                $template->meta_rejection_reason = $syncedTemplate->rejection_reason;
                $template->meta_template_name = $syncedTemplate->name;
            }

            return $template;
        });

        $metaOnlyTemplates = $syncedTemplates
            ->filter(function (WhatsappTemplate $template) use ($matchedTemplateNames) {
                return !isset($matchedTemplateNames[$this->normalizeTemplateName($template->name)]);
            })
            ->values();
        
        // Auto-track onboarding step: template_viewed
        $this->onboardingService->trackTemplateViewed(Auth::user());
            
        return view('template', [
            'templates' => $templates,
            'metaOnlyTemplates' => $metaOnlyTemplates,
            'syncedTemplateCount' => $syncedTemplates->count(),
            'latestTemplateSyncAt' => $latestTemplateSyncAt,
            'templateAutoSyncCooldownMinutes' => (int) ceil(WaBlastService::TEMPLATE_AUTO_SYNC_COOLDOWN / 60),
        ]);
    }

    private function normalizeTemplateName(?string $name): string
    {
        $normalized = strtolower((string) $name);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized) ?? '';

        return trim($normalized, '_');
    }

    private function mapSyncedStatusToInternal(string $status): string
    {
        return match (strtolower($status)) {
            WhatsappTemplate::STATUS_APPROVED => TemplatePesan::STATUS_DISETUJUI,
            WhatsappTemplate::STATUS_REJECTED => TemplatePesan::STATUS_DITOLAK,
            WhatsappTemplate::STATUS_PAUSED => TemplatePesan::STATUS_ARSIP,
            default => TemplatePesan::STATUS_DIAJUKAN,
        };
    }

    private function validateTemplateDraft(string $displayName, string $category, string $body, int $klienId, ?int $ignoreTemplateId = null): array
    {
        $errors = [];
        $normalizedName = $this->normalizeTemplateName($displayName);
        $trimmedBody = trim($body);
        $utilityPromoPattern = '/\b(promo|diskon|cashback|voucher|gratis|sale|penawaran|flash sale|stok terbatas|beli sekarang|pesan sekarang)\b|%/i';
        $authPattern = '/\b(otp|kode|verifikasi|verification|password|pin|login)\b/i';
        $highRiskPattern = '/\b(slot|judi|casino|pinjaman online|paylater tanpa syarat|investasi pasti untung|jamin untung|cepat kaya|hadiah tunai instan)\b/i';

        if (mb_strlen(trim($displayName)) < 3) {
            $errors[] = 'Nama template minimal 3 karakter.';
        }

        if ($normalizedName === '') {
            $errors[] = 'Nama template harus mengandung huruf atau angka agar aman untuk Meta.';
        }

        $duplicateQuery = TemplatePesan::where('klien_id', $klienId)
            ->where('nama_template', $normalizedName);

        if ($ignoreTemplateId) {
            $duplicateQuery->where('id', '!=', $ignoreTemplateId);
        }

        if ($duplicateQuery->exists()) {
            $errors[] = 'Nama template bentrok dengan template lain. Ganti nama agar unik.';
        }

        if (mb_strlen($trimmedBody) < 15) {
            $errors[] = 'Isi pesan terlalu pendek. Jelaskan tujuan pesan dengan lebih spesifik.';
        }

        if (substr_count($body, '{{') !== substr_count($body, '}}')) {
            $errors[] = 'Format variabel tidak valid. Pastikan pasangan {{ dan }} lengkap.';
        }

        preg_match_all('/\{\{([^{}]+)\}\}/', $body, $matches);
        $variables = collect($matches[1] ?? [])
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->unique()
            ->values();

        $invalidVariables = $variables
            ->reject(fn ($value) => in_array($value, self::ALLOWED_TEMPLATE_VARIABLES, true))
            ->values();

        if ($invalidVariables->isNotEmpty()) {
            $errors[] = 'Variabel tidak didukung: ' . $invalidVariables->implode(', ') . '. Gunakan hanya: ' . implode(', ', self::ALLOWED_TEMPLATE_VARIABLES) . '.';
        }

        if ($variables->count() > 5) {
            $errors[] = 'Gunakan maksimal 5 variabel agar template lebih mudah di-review Meta.';
        }

        if (preg_match('/(bit\.ly|tinyurl\.com|cutt\.ly|s\.id)/i', $body)) {
            $errors[] = 'Hindari short link seperti bit.ly atau s.id. Gunakan link penuh jika memang perlu.';
        }

        if (preg_match($highRiskPattern, $body)) {
            $errors[] = 'Isi pesan mengandung kata berisiko tinggi untuk review Meta. Gunakan bahasa yang lebih netral dan informatif.';
        }

        if ($category === 'utility' && preg_match($utilityPromoPattern, $body)) {
            $errors[] = 'Isi pesan terlihat promosi, jadi kategori yang lebih aman adalah Marketing, bukan Utility.';
        }

        if ($category === 'authentication' && !preg_match($authPattern, $body)) {
            $errors[] = 'Kategori Authentication sebaiknya hanya dipakai untuk OTP, PIN, login, atau verifikasi akun.';
        }

        if ($category === 'authentication' && preg_match('/https?:\/\//i', $body)) {
            $errors[] = 'Template Authentication sebaiknya tidak berisi link. Fokuskan hanya pada kode atau instruksi verifikasi.';
        }

        if ($category === 'authentication' && preg_match($utilityPromoPattern, $body)) {
            $errors[] = 'Template Authentication tidak boleh berisi promo atau ajakan penjualan.';
        }

        return $errors;
    }

    /**
     * Get templates as JSON (for API)
     */
    public function list(Request $request)
    {
        $klienId = Auth::user()->klien_id;
        $templates = TemplatePesan::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $templates
        ]);
    }

    /**
     * Store new template
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'kategori' => 'required|string|in:marketing,utility,authentication',
            'konten' => 'required|string|max:4096',
        ], [
            'nama.required' => 'Nama template wajib diisi',
            'kategori.required' => 'Kategori wajib dipilih',
            'konten.required' => 'Isi pesan wajib diisi',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $displayName = trim((string) $request->nama);
        $safeTemplateName = $this->normalizeTemplateName($displayName);
        $guardrailErrors = $this->validateTemplateDraft(
            $displayName,
            (string) $request->kategori,
            (string) $request->konten,
            (int) Auth::user()->klien_id,
        );

        if (!empty($guardrailErrors)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'errors' => ['template' => $guardrailErrors],
                ], 422);
            }

            return back()->withErrors(['template' => $guardrailErrors])->withInput();
        }

        $template = TemplatePesan::create([
            'nama_template' => $safeTemplateName,
            'nama_tampilan' => $displayName,
            'kategori' => $request->kategori,
            'bahasa' => 'id',
            'body' => $request->konten,
            'status' => TemplatePesan::STATUS_DRAFT,
            'klien_id' => Auth::user()->klien_id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Template berhasil disimpan',
                'data' => $template
            ]);
        }

        return redirect()->route('template')->with('success', 'Template berhasil disimpan');
    }

    /**
     * Update template
     */
    public function update(Request $request, $id)
    {
        $template = TemplatePesan::where('id', $id)
            ->where('klien_id', Auth::user()->klien_id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'kategori' => 'required|string',
            'konten' => 'required|string|max:4096',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $displayName = trim((string) $request->nama);
        $safeTemplateName = $this->normalizeTemplateName($displayName);
        $guardrailErrors = $this->validateTemplateDraft(
            $displayName,
            (string) $request->kategori,
            (string) $request->konten,
            (int) Auth::user()->klien_id,
            (int) $template->id,
        );

        if (!empty($guardrailErrors)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'errors' => ['template' => $guardrailErrors],
                ], 422);
            }

            return back()->withErrors(['template' => $guardrailErrors])->withInput();
        }

        $template->update([
            'nama_template' => $safeTemplateName,
            'nama_tampilan' => $displayName,
            'kategori' => $request->kategori,
            'body' => $request->konten,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Template berhasil diupdate',
                'data' => $template
            ]);
        }

        return redirect()->route('template')->with('success', 'Template berhasil diupdate');
    }

    /**
     * Delete template
     */
    public function destroy($id)
    {
        $template = TemplatePesan::where('id', $id)
            ->where('klien_id', Auth::user()->klien_id)
            ->firstOrFail();

        $template->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Template berhasil dihapus'
            ]);
        }

        return redirect()->route('template')->with('success', 'Template berhasil dihapus');
    }

    /**
     * Submit template to Meta for approval
     */
    public function submitToMeta($id)
    {
        $klienId = Auth::user()->klien_id;

        $template = TemplatePesan::where('id', $id)
            ->where('klien_id', $klienId)
            ->firstOrFail();

        // Check WhatsApp connection
        $connection = WhatsappConnection::where('klien_id', $klienId)->first();
        if (!$connection || !$connection->isConnected()) {
            return back()->with('error', 'WhatsApp Business belum terhubung. Hubungkan dulu di halaman Nomor WhatsApp.');
        }

        // Ensure connection has WABA ID and access token for Meta Cloud API
        if (empty($connection->waba_id) || empty($connection->getDecryptedAccessToken())) {
            return back()->with('error', 'Koneksi WhatsApp belum lengkap — WABA ID atau Access Token belum tersedia. Silakan hubungi admin untuk setup koneksi Meta Cloud API.');
        }

        // Validate template has body content
        if (empty($template->body)) {
            return back()->with('error', 'Isi pesan template tidak boleh kosong.');
        }

        // Convert template name to Meta format (lowercase, underscores, no spaces)
        $metaName = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $template->nama_template));
        $metaName = preg_replace('/_+/', '_', trim($metaName, '_'));

        // Convert named variables {{nama}} to numbered {{1}}, {{2}}, etc.
        $body = $template->body;
        $variableMap = [];
        $counter = 1;
        $body = preg_replace_callback('/\{\{([a-zA-Z_]+)\}\}/', function ($matches) use (&$variableMap, &$counter) {
            $varName = $matches[1];
            if (!isset($variableMap[$varName])) {
                $variableMap[$varName] = $counter++;
            }
            return '{{' . $variableMap[$varName] . '}}';
        }, $body);

        // Map category to Meta format
        $categoryMap = [
            'marketing' => 'MARKETING',
            'utility' => 'UTILITY',
            'authentication' => 'AUTHENTICATION',
            'transactional' => 'UTILITY',
            'notification' => 'UTILITY',
            'greeting' => 'MARKETING',
            'follow_up' => 'MARKETING',
            'other' => 'MARKETING',
        ];
        $metaCategory = $categoryMap[$template->kategori] ?? 'MARKETING';

        // Build components
        $components = [
            [
                'type' => 'BODY',
                'text' => $body,
            ],
        ];

        // Add example values if there are variables
        if (!empty($variableMap)) {
            $exampleValues = [];
            foreach ($variableMap as $varName => $num) {
                $examples = [
                    'nama' => 'John',
                    'telepon' => '081234567890',
                    'email' => 'john@example.com',
                    'produk' => 'Produk A',
                    'harga' => 'Rp 100.000',
                    'tanggal' => '01 Jan 2026',
                    'no_order' => 'ORD-001',
                ];
                $exampleValues[] = $examples[$varName] ?? 'contoh_' . $varName;
            }
            $components[0]['example'] = ['body_text' => [$exampleValues]];
        }

        // Add footer if exists
        if (!empty($template->footer)) {
            $components[] = [
                'type' => 'FOOTER',
                'text' => $template->footer,
            ];
        }

        // Submit to Meta Graph API
        $graphVersion = env('WHATSAPP_GRAPH_VERSION', 'v22.0');
        $url = "https://graph.facebook.com/{$graphVersion}/{$connection->waba_id}/message_templates";

        try {
            $payload = [
                'name' => $metaName,
                'language' => $template->bahasa ?? 'id',
                'category' => $metaCategory,
                'components' => $components,
            ];

            $response = Http::withToken($connection->getDecryptedAccessToken())
                ->acceptJson()
                ->timeout(30)
                ->post($url, $payload);

            // Save provider payload & response
            $template->update([
                'provider_payload' => $payload,
                'provider_response' => $response->json(),
            ]);

            if ($response->successful()) {
                $metaTemplateId = $response->json('id');

                // Update TemplatePesan status
                $template->update([
                    'status' => TemplatePesan::STATUS_DIAJUKAN,
                    'provider_template_id' => $metaTemplateId,
                    'submitted_at' => now(),
                ]);

                // Also create/update in whatsapp_templates so it appears after approval
                WhatsappTemplate::updateOrCreate(
                    [
                        'klien_id' => $klienId,
                        'template_id' => $metaName,
                    ],
                    [
                        'name' => $metaName,
                        'category' => $metaCategory,
                        'language' => $template->bahasa ?? 'id',
                        'components' => json_encode($components),
                        'sample_text' => $template->body,
                        'status' => 'pending',
                    ]
                );

                Log::info('Template submitted to Meta', [
                    'template_id' => $template->id,
                    'meta_name' => $metaName,
                    'meta_template_id' => $metaTemplateId,
                    'klien_id' => $klienId,
                ]);

                return back()->with('success', 'Template "' . $template->nama_template . '" berhasil dikirim ke Meta untuk review. Status akan diupdate otomatis setelah disetujui.');

            } else {
                $error = $response->json('error.message', 'Unknown error');
                $errorCode = $response->json('error.code', '');

                Log::warning('Template submission to Meta failed', [
                    'template_id' => $template->id,
                    'meta_name' => $metaName,
                    'error' => $error,
                    'error_code' => $errorCode,
                    'response' => $response->json(),
                ]);

                // Handle specific Meta error codes
                if (str_contains($error, 'already exists') || str_contains($error, 'duplicate')) {
                    return back()->with('error', 'Template dengan nama "' . $metaName . '" sudah ada di Meta. Gunakan nama template yang berbeda.');
                }

                if ($errorCode == 100) {
                    return back()->with('error', 'Gagal mengirim template: WABA ID atau permission tidak valid. Hubungi admin untuk cek koneksi WhatsApp.');
                }

                return back()->with('error', 'Gagal mengirim template ke Meta: ' . $error);
            }

        } catch (\Exception $e) {
            Log::error('Template submission to Meta exception', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Gagal mengirim template: ' . $e->getMessage());
        }
    }
}
