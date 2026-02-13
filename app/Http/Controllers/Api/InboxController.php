<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Facades\Inbox;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use App\Services\RevenueGuardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * InboxController - API Controller untuk Inbox WhatsApp
 * 
 * Controller tipis yang mendelegasikan logic ke InboxService.
 * Hanya bertanggung jawab untuk:
 * 1. Validasi input
 * 2. Otorisasi akses
 * 3. Format response
 * 
 * @package App\Http\Controllers\Api
 */
class InboxController extends Controller
{
    /**
     * Daftar percakapan inbox
     * 
     * GET /api/inbox
     * 
     * Query params:
     * - status: baru|belum_dibaca|aktif|menunggu|selesai
     * - prioritas: tinggi|normal|rendah
     * - ditangani_oleh: me|all|unassigned
     * - search: kata kunci pencarian
     * - per_page: jumlah per halaman (default 20)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $pengguna = Auth::user();
        $klienId = $pengguna->klien_id;

        $query = PercakapanInbox::where('klien_id', $klienId)
            ->with(['pesanTerakhirRelasi', 'penanggungjawab:id,nama,email']);

        // Filter status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter prioritas
        if ($request->filled('prioritas')) {
            $query->where('prioritas', $request->prioritas);
        }

        // Filter berdasarkan siapa yang handle
        if ($request->filled('ditangani_oleh')) {
            switch ($request->ditangani_oleh) {
                case 'me':
                    $query->where('ditangani_oleh', $pengguna->id);
                    break;
                case 'unassigned':
                    $query->whereNull('ditangani_oleh');
                    break;
                // 'all' tidak perlu filter
            }
        }

        // Pencarian
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_customer', 'like', "%{$search}%")
                  ->orWhere('no_whatsapp', 'like', "%{$search}%")
                  ->orWhere('pesan_terakhir', 'like', "%{$search}%");
            });
        }

        // Ordering: prioritas tinggi & pesan terbaru di atas
        // Gunakan CASE WHEN untuk kompatibilitas SQLite & MySQL
        $query->orderByRaw("CASE prioritas WHEN 'urgent' THEN 1 WHEN 'tinggi' THEN 2 WHEN 'normal' THEN 3 WHEN 'rendah' THEN 4 ELSE 5 END")
              ->orderByDesc('waktu_pesan_terakhir');

        $perPage = min($request->input('per_page', 20), 100);
        $percakapan = $query->paginate($perPage);

        return response()->json([
            'sukses' => true,
            'data' => $percakapan
        ]);
    }

    /**
     * Detail percakapan dengan daftar pesan
     * 
     * GET /api/inbox/{percakapanId}
     *
     * @param int $percakapanId
     * @param Request $request
     * @return JsonResponse
     */
    public function show(int $percakapanId, Request $request): JsonResponse
    {
        $pengguna = Auth::user();

        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->with(['penanggungjawab:id,nama,email'])
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Ambil pesan dengan pagination
        $perPage = min($request->input('per_page', 50), 100);
        $pesan = PesanInbox::where('percakapan_id', $percakapanId)
            ->orderByDesc('waktu_pesan')
            ->paginate($perPage);

        return response()->json([
            'sukses' => true,
            'data' => [
                'percakapan' => $percakapan,
                'pesan' => $pesan
            ]
        ]);
    }

    /**
     * Ambil/assign percakapan ke sales yang login
     * 
     * POST /api/inbox/{percakapanId}/ambil
     *
     * @param int $percakapanId
     * @return JsonResponse
     */
    public function ambil(int $percakapanId): JsonResponse
    {
        $pengguna = Auth::user();

        // Validasi akses ke percakapan
        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Delegasi ke service
        $hasil = Inbox::ambilPercakapan($percakapanId, $pengguna->id);

        return response()->json($hasil, $hasil['sukses'] ? 200 : 422);
    }

    /**
     * Lepas/unassign percakapan
     * 
     * POST /api/inbox/{percakapanId}/lepas
     *
     * @param int $percakapanId
     * @return JsonResponse
     */
    public function lepas(int $percakapanId): JsonResponse
    {
        $pengguna = Auth::user();

        // Validasi akses ke percakapan
        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Delegasi ke service
        $hasil = Inbox::lepasPercakapan($percakapanId, $pengguna->id);

        return response()->json($hasil, $hasil['sukses'] ? 200 : 422);
    }

    /**
     * Tandai pesan sudah dibaca
     * 
     * POST /api/inbox/{percakapanId}/baca
     *
     * @param int $percakapanId
     * @return JsonResponse
     */
    public function tandaiBaca(int $percakapanId): JsonResponse
    {
        $pengguna = Auth::user();

        // Validasi akses ke percakapan
        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Delegasi ke service
        $hasil = Inbox::tandaiSudahDibaca($percakapanId, $pengguna->id);

        return response()->json($hasil, $hasil['sukses'] ? 200 : 422);
    }

    /**
     * Kirim pesan balasan
     * 
     * POST /api/inbox/{percakapanId}/kirim
     * 
     * Body:
     * - tipe: teks|gambar|dokumen|audio|video
     * - isi_pesan: string (wajib untuk teks)
     * - media_url: string (wajib untuk media)
     * - caption: string (opsional untuk media)
     *
     * @param int $percakapanId
     * @param Request $request
     * @return JsonResponse
     */
    public function kirimPesan(int $percakapanId, Request $request): JsonResponse
    {
        $pengguna = Auth::user();

        // Validasi input
        $validator = Validator::make($request->all(), [
            'tipe' => 'required|in:teks,gambar,dokumen,audio,video',
            'isi_pesan' => 'required_if:tipe,teks|string|max:4096',
            'media_url' => 'required_unless:tipe,teks|url',
            'caption' => 'nullable|string|max:1024',
        ], [
            'tipe.required' => 'Tipe pesan wajib diisi',
            'tipe.in' => 'Tipe pesan tidak valid',
            'isi_pesan.required_if' => 'Isi pesan wajib diisi untuk tipe teks',
            'media_url.required_unless' => 'URL media wajib diisi untuk tipe media',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validasi akses ke percakapan
        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Cek apakah user yang handle
        if ($percakapan->ditangani_oleh !== $pengguna->id) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Anda harus mengambil percakapan ini terlebih dahulu'
            ], 403);
        }

        // ============ REVENUE GUARD LAYER 4: Atomic Deduction ============
        // Potong saldo SEBELUM kirim pesan. Fail-closed: jika gagal, pesan TIDAK dikirim.
        try {
            $revenueGuard = app(RevenueGuardService::class);
            $sendRef = abs(crc32("inbox_reply_{$percakapanId}_{$pengguna->id}_" . floor(time() / 5)));

            $guardResult = $revenueGuard->executeDeduction(
                userId: $pengguna->id,
                messageCount: 1,
                category: 'utility',
                referenceType: 'inbox_reply',
                referenceId: $sendRef,
                costPreview: $request->attributes->get('revenue_guard', []),
            );

            if (!$guardResult['success'] && !($guardResult['duplicate'] ?? false)) {
                return response()->json([
                    'sukses' => false,
                    'pesan' => $guardResult['message'] ?? 'Gagal memproses pembayaran',
                    'error_code' => 'REVENUE_GUARD_FAILED',
                ], 402);
            }
        } catch (\RuntimeException $e) {
            return response()->json([
                'sukses' => false,
                'pesan' => $e->getMessage(),
                'error_code' => 'INSUFFICIENT_BALANCE',
            ], 402);
        }

        // Delegasi ke service (saldo sudah dipotong oleh RGS)
        $hasil = Inbox::kirimBalasan($percakapanId, $pengguna->id, $request->all());

        return response()->json($hasil, $hasil['sukses'] ? 200 : 422);
    }

    /**
     * Update prioritas percakapan
     * 
     * PATCH /api/inbox/{percakapanId}/prioritas
     * 
     * Body:
     * - prioritas: tinggi|normal|rendah
     *
     * @param int $percakapanId
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePrioritas(int $percakapanId, Request $request): JsonResponse
    {
        $pengguna = Auth::user();

        $validator = Validator::make($request->all(), [
            'prioritas' => 'required|in:tinggi,normal,rendah'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Prioritas tidak valid',
                'errors' => $validator->errors()
            ], 422);
        }

        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        $percakapan->update([
            'prioritas' => $request->prioritas
        ]);

        return response()->json([
            'sukses' => true,
            'pesan' => 'Prioritas berhasil diupdate'
        ]);
    }

    /**
     * Tandai percakapan selesai
     * 
     * POST /api/inbox/{percakapanId}/selesai
     *
     * @param int $percakapanId
     * @return JsonResponse
     */
    public function selesai(int $percakapanId): JsonResponse
    {
        $pengguna = Auth::user();

        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Hanya yang menangani atau admin/owner yang bisa selesaikan
        if ($percakapan->ditangani_oleh !== $pengguna->id && 
            !in_array($pengguna->role, ['super_admin', 'owner', 'admin'])) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Anda tidak memiliki akses untuk menyelesaikan percakapan ini'
            ], 403);
        }

        $percakapan->update([
            'status' => 'selesai',
            'waktu_selesai' => now()
        ]);

        return response()->json([
            'sukses' => true,
            'pesan' => 'Percakapan ditandai selesai'
        ]);
    }

    /**
     * Ambil badge counter inbox untuk user yang login
     * 
     * GET /api/inbox/counter
     *
     * @return JsonResponse
     */
    public function counter(): JsonResponse
    {
        $pengguna = Auth::user();

        // Hitung jumlah percakapan baru/belum dibaca
        $totalBaru = PercakapanInbox::where('klien_id', $pengguna->klien_id)
            ->whereIn('status', ['baru', 'belum_dibaca'])
            ->count();

        // Hitung yang di-assign ke user ini
        $milikSaya = PercakapanInbox::where('klien_id', $pengguna->klien_id)
            ->where('ditangani_oleh', $pengguna->id)
            ->where('pesan_belum_dibaca', '>', 0)
            ->count();

        // Hitung yang belum di-assign
        $belumDiambil = PercakapanInbox::where('klien_id', $pengguna->klien_id)
            ->whereNull('ditangani_oleh')
            ->whereIn('status', ['baru', 'belum_dibaca'])
            ->count();

        return response()->json([
            'sukses' => true,
            'data' => [
                'total_baru' => $totalBaru,
                'milik_saya' => $milikSaya,
                'belum_diambil' => $belumDiambil
            ]
        ]);
    }

    /**
     * Transfer percakapan ke sales lain
     * 
     * POST /api/inbox/{percakapanId}/transfer
     * 
     * Body:
     * - pengguna_id: ID sales tujuan
     * - catatan: Catatan transfer (opsional)
     *
     * @param int $percakapanId
     * @param Request $request
     * @return JsonResponse
     */
    public function transfer(int $percakapanId, Request $request): JsonResponse
    {
        $pengguna = Auth::user();

        $validator = Validator::make($request->all(), [
            'pengguna_id' => 'required|exists:pengguna,id',
            'catatan' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        $percakapan = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $pengguna->klien_id)
            ->first();

        if (!$percakapan) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Percakapan tidak ditemukan'
            ], 404);
        }

        // Hanya yang menangani atau admin/owner yang bisa transfer
        if ($percakapan->ditangani_oleh !== $pengguna->id && 
            !in_array($pengguna->role, ['super_admin', 'owner', 'admin'])) {
            return response()->json([
                'sukses' => false,
                'pesan' => 'Anda tidak memiliki akses untuk transfer percakapan ini'
            ], 403);
        }

        // Delegasi ke service
        $hasil = Inbox::transferPercakapan(
            $percakapanId, 
            $pengguna->id, 
            $request->pengguna_id,
            $request->catatan
        );

        return response()->json($hasil, $hasil['sukses'] ? 200 : 422);
    }
}
