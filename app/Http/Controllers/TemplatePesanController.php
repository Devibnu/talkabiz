<?php

namespace App\Http\Controllers;

use App\Models\TemplatePesan;
use App\Models\WhatsappConnection;
use App\Models\WhatsappTemplate;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TemplatePesanController extends Controller
{
    protected OnboardingService $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }

    /**
     * Display template list page
     */
    public function index()
    {
        $klienId = Auth::user()->klien_id;
        $templates = TemplatePesan::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Auto-track onboarding step: template_viewed
        $this->onboardingService->trackTemplateViewed(Auth::user());
            
        return view('template', compact('templates'));
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

        $template = TemplatePesan::create([
            'nama_template' => $request->nama,
            'nama_tampilan' => $request->nama,
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

        $template->update([
            'nama_template' => $request->nama,
            'nama_tampilan' => $request->nama,
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
