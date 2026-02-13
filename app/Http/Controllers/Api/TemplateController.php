<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TemplatePesan;
use App\Services\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;

/**
 * TemplateController
 * 
 * API Controller untuk mengelola template pesan WhatsApp.
 * Menggunakan Policy untuk authorization berbasis role.
 * 
 * ATURAN:
 * - Sales hanya READ
 * - Admin & Owner bisa CRUD
 * - Template dengan status selain draft/ditolak = READ ONLY
 * 
 * Endpoints:
 * - GET    /api/templates           → Daftar template
 * - GET    /api/templates/{id}      → Detail template
 * - POST   /api/templates           → Buat template baru
 * - PUT    /api/templates/{id}      → Update template
 * - POST   /api/templates/{id}/ajukan → Ajukan ke provider
 * - POST   /api/templates/{id}/arsip  → Arsipkan template
 * - DELETE /api/templates/{id}      → Hapus template
 * 
 * @author TalkaBiz Team
 */
class TemplateController extends Controller
{
    protected TemplateService $templateService;

    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    // ==================== HELPER METHODS ====================

    protected function getKlienId(Request $request): ?int
    {
        return $request->user()->klien_id;
    }

    protected function getUserId(Request $request): int
    {
        return $request->user()->id;
    }

    protected function getUserRole(Request $request): string
    {
        return $request->user()->role ?? 'sales';
    }

    protected function canWrite(Request $request): bool
    {
        $role = $this->getUserRole($request);
        return in_array($role, ['super_admin', 'owner', 'admin']);
    }

    protected function noKlienResponse(): JsonResponse
    {
        return response()->json([
            'sukses' => false,
            'pesan' => 'Klien tidak ditemukan',
        ], 403);
    }

    protected function forbiddenResponse(): JsonResponse
    {
        return response()->json([
            'sukses' => false,
            'pesan' => 'Anda tidak memiliki akses untuk aksi ini',
        ], 403);
    }

    // ==================== GET /api/templates ====================

    /**
     * Ambil daftar template
     * 
     * Query params:
     * - status: draft|diajukan|disetujui|ditolak|arsip
     * - kategori: marketing|utility|authentication
     * - search: string
     * - per_page: int (default 15)
     */
    public function index(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $filters = [
            'status' => $request->query('status'),
            'kategori' => $request->query('kategori'),
            'aktif' => $request->has('aktif') ? $request->boolean('aktif') : null,
            'include_arsip' => $request->boolean('include_arsip'),
            'search' => $request->query('search'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => (int) $request->query('per_page', 15),
        ];

        $result = $this->templateService->ambilDaftar($klienId, $filters);

        return response()->json([
            'sukses' => true,
            'data' => $result['templates'],
        ]);
    }

    // ==================== GET /api/templates/{id} ====================

    /**
     * Lihat detail template
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->ambilDetail($klienId, $id);

        if (!$result['sukses']) {
            return response()->json($result, 404);
        }

        return response()->json([
            'sukses' => true,
            'data' => $result['template'],
        ]);
    }

    // ==================== POST /api/templates ====================

    /**
     * Buat template baru (draft)
     * 
     * Body:
     * {
     *   "nama_template": "promo_januari",
     *   "nama_tampilan": "Promo Januari",
     *   "kategori": "marketing",
     *   "bahasa": "id",
     *   "isi_template": "Halo {{1}}, ada promo {{2}} untuk kamu!",
     *   "variable": {"1": "Budi", "2": "50%"}
     * }
     */
    public function store(Request $request): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $validator = Validator::make($request->all(), [
            'nama_template' => 'required|string|max:100|regex:/^[a-z0-9_]+$/',
            'nama_tampilan' => 'nullable|string|max:255',
            'kategori' => 'required|in:marketing,utility,authentication',
            'bahasa' => 'nullable|string|max:10',
            'header' => 'nullable|string|max:60',
            'header_type' => 'nullable|in:none,text,image,video,document',
            'header_media_url' => 'nullable|url',
            'isi_template' => 'required|string|max:1024',
            'footer' => 'nullable|string|max:60',
            'buttons' => 'nullable|array|max:3',
            'variable' => 'nullable|array',
        ], [
            'nama_template.regex' => 'Nama template hanya boleh huruf kecil, angka, dan underscore',
            'isi_template.max' => 'Isi template maksimal 1024 karakter',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->templateService->buatTemplate(
            $klienId,
            $request->all(),
            $this->getUserId($request)
        );

        if (!$result['sukses']) {
            return response()->json($result, 422);
        }

        return response()->json($result, 201);
    }

    // ==================== PUT /api/templates/{id} ====================

    /**
     * Update template (hanya draft & ditolak)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $validator = Validator::make($request->all(), [
            'nama_tampilan' => 'nullable|string|max:255',
            'header' => 'nullable|string|max:60',
            'header_type' => 'nullable|in:none,text,image,video,document',
            'isi_template' => 'nullable|string|max:1024',
            'footer' => 'nullable|string|max:60',
            'buttons' => 'nullable|array|max:3',
            'variable' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->templateService->updateTemplate(
            $klienId,
            $id,
            $request->all(),
            $this->getUserId($request)
        );

        if (!$result['sukses']) {
            $statusCode = str_contains($result['pesan'] ?? '', 'tidak dapat diedit') ? 422 : 404;
            return response()->json($result, $statusCode);
        }

        return response()->json($result);
    }

    // ==================== POST /api/templates/{id}/ajukan ====================

    /**
     * Ajukan template ke provider untuk approval
     */
    public function ajukan(Request $request, int $id): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->ajukanTemplateKeProvider(
            $klienId,
            $id,
            $this->getUserId($request)
        );

        if (!$result['sukses']) {
            $statusCode = str_contains($result['pesan'] ?? '', 'tidak dapat diajukan') ? 422 : 400;
            return response()->json($result, $statusCode);
        }

        return response()->json($result);
    }

    // ==================== POST /api/templates/{id}/arsip ====================

    /**
     * Arsipkan template
     */
    public function arsip(Request $request, int $id): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->arsipkanTemplate(
            $klienId,
            $id,
            $this->getUserId($request)
        );

        if (!$result['sukses']) {
            $statusCode = str_contains($result['pesan'] ?? '', 'campaign aktif') ? 409 : 404;
            return response()->json($result, $statusCode);
        }

        return response()->json($result);
    }

    // ==================== DELETE /api/templates/{id} ====================

    /**
     * Hapus template (soft delete)
     * Tidak bisa hapus jika sudah dipakai campaign
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->hapusTemplate(
            $klienId,
            $id,
            $this->getUserId($request)
        );

        if (!$result['sukses']) {
            $statusCode = 400;
            if (str_contains($result['pesan'] ?? '', 'campaign aktif') || str_contains($result['pesan'] ?? '', 'pernah digunakan')) {
                $statusCode = 409;
            } elseif (str_contains($result['pesan'] ?? '', 'tidak ditemukan')) {
                $statusCode = 404;
            }
            return response()->json($result, $statusCode);
        }

        return response()->json($result);
    }

    // ==================== POST /api/templates/sync-status ====================

    /**
     * Sync status template dari provider
     */
    public function syncStatus(Request $request): JsonResponse
    {
        // Cek role
        if (!$this->canWrite($request)) {
            return $this->forbiddenResponse();
        }

        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->syncStatusDariProvider(
            $klienId,
            $this->getUserId($request)
        );

        return response()->json($result);
    }

    // ==================== GET /api/templates/disetujui ====================

    /**
     * Ambil template yang sudah disetujui (untuk dropdown)
     */
    public function disetujui(Request $request): JsonResponse
    {
        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $kategori = $request->query('kategori');
        $templates = $this->templateService->ambilTemplateDisetujui($klienId, $kategori);

        return response()->json([
            'sukses' => true,
            'data' => $templates,
        ]);
    }

    // ==================== GET /api/templates/{id}/variabel ====================

    /**
     * Extract variabel dari template
     */
    public function variabel(Request $request, int $id): JsonResponse
    {
        $klienId = $this->getKlienId($request);
        if (!$klienId) {
            return $this->noKlienResponse();
        }

        $result = $this->templateService->ambilDetail($klienId, $id);

        if (!$result['sukses']) {
            return response()->json($result, 404);
        }

        $template = $result['template'];
        $variabel = $this->templateService->extractVariableDariTemplate($template->body);

        return response()->json([
            'sukses' => true,
            'data' => [
                'template_id' => $template->id,
                'body' => $template->body,
                'variabel' => $variabel['variabel'],
                'jumlah' => $variabel['jumlah'],
                'contoh' => $template->contoh_variabel,
            ],
        ]);
    }
}
