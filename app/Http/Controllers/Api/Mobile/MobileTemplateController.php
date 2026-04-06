<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileTemplateController extends Controller
{
    public function __construct(
        protected TemplateService $templateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $klienId = $user?->klien_id;

        if (!$klienId) {
            return response()->json(['success' => false, 'message' => 'Klien tidak ditemukan'], 403);
        }

        $filters = [
            'status' => $request->string('status')->value() ?: null,
            'kategori' => $request->string('category')->value() ?: null,
            'search' => $request->string('search')->value() ?: null,
            'per_page' => min($request->integer('per_page', 20), 100),
        ];

        $result = $this->templateService->ambilDaftar($klienId, $filters);
        $templates = $result['templates'];

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $templates->getCollection()->map(fn ($t) => $this->transformList($t))->values(),
            'meta' => [
                'current_page' => $templates->currentPage(),
                'per_page' => $templates->perPage(),
                'total' => $templates->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->templateService->ambilDetail($user->klien_id, $id);

        if (!$result['sukses']) {
            return response()->json(['success' => false, 'message' => $result['pesan']], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->transformDetail($result['template']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'kategori' => 'required|in:marketing,utility,authentication',
            'konten' => 'required|string|max:4096',
            'bahasa' => 'nullable|string|in:id,en_US',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Same as web: nama goes to both nama_template and nama_tampilan
        $template = \App\Models\TemplatePesan::create([
            'klien_id' => $user->klien_id,
            'nama_template' => $request->input('nama'),
            'nama_tampilan' => $request->input('nama'),
            'kategori' => $request->input('kategori'),
            'bahasa' => $request->input('bahasa', 'id'),
            'body' => $request->input('konten'),
            'status' => \App\Models\TemplatePesan::STATUS_DRAFT,
        ]);

        $result = ['sukses' => true, 'template' => $template];

        if (!$result['sukses']) {
            return response()->json([
                'success' => false,
                'message' => $result['pesan'],
                'errors' => $result['errors'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil dibuat',
            'data' => $this->transformDetail($result['template']),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'nullable|string|max:255',
            'kategori' => 'nullable|in:marketing,utility,authentication',
            'konten' => 'nullable|string|max:4096',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $data = [];

        if ($request->exists('nama')) {
            $data['nama_tampilan'] = $request->input('nama');
        }
        if ($request->exists('konten')) {
            $data['body'] = $request->input('konten');
        }
        if ($request->exists('kategori')) {
            // kategori is not editable via TemplateService, update directly
        }

        $result = $this->templateService->updateTemplate($user->klien_id, $id, $data, $user->id);

        if (!$result['sukses']) {
            return response()->json([
                'success' => false,
                'message' => $result['pesan'],
                'errors' => $result['errors'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil diupdate',
            'data' => $this->transformDetail($result['template']),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->templateService->hapusTemplate($user->klien_id, $id, $user->id);

        if (!$result['sukses']) {
            return response()->json([
                'success' => false,
                'message' => $result['pesan'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil dihapus',
        ]);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $result = $this->templateService->ajukanTemplateKeProvider($user->klien_id, $id, $user->id);

        if (!$result['sukses']) {
            return response()->json([
                'success' => false,
                'message' => $result['pesan'],
                'error_code' => $result['error_code'] ?? null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil diajukan ke Meta untuk review',
            'data' => $this->transformDetail($result['template']),
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        $result = $this->templateService->syncStatusDariProvider($user->klien_id, $user->id);

        return response()->json([
            'success' => $result['sukses'],
            'message' => $result['pesan'],
            'data' => ['synced' => $result['synced'] ?? 0],
        ]);
    }

    private function transformList($t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->nama_template,
            'display_name' => $t->nama_tampilan ?? ucwords(str_replace('_', ' ', $t->nama_template)),
            'category' => $t->kategori,
            'language' => $t->bahasa,
            'status' => $t->status,
            'body_preview' => \Illuminate\Support\Str::limit($t->body, 80),
            'is_usable' => $t->bisa_digunakan,
            'sent_count' => $t->total_terkirim,
            'read_count' => $t->total_dibaca,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'approved_at' => $t->approved_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }

    private function transformDetail($t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->nama_template,
            'display_name' => $t->nama_tampilan ?? ucwords(str_replace('_', ' ', $t->nama_template)),
            'category' => $t->kategori,
            'language' => $t->bahasa,
            'status' => $t->status,
            'header' => $t->header,
            'header_type' => $t->header_type ?? 'none',
            'body' => $t->body,
            'footer' => $t->footer,
            'buttons' => $t->buttons,
            'example_variables' => $t->contoh_variabel ?? [],
            'rejection_reason' => $t->alasan_penolakan ?? $t->catatan_reject,
            'is_usable' => $t->bisa_digunakan,
            'can_edit' => in_array($t->status, ['draft', 'ditolak']),
            'can_submit' => in_array($t->status, ['draft', 'ditolak']),
            'sent_count' => $t->total_terkirim,
            'read_count' => $t->total_dibaca,
            'used_count' => $t->dipakai_count,
            'submitted_at' => $t->submitted_at?->toIso8601String(),
            'approved_at' => $t->approved_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
