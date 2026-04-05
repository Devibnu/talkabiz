<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Controller;
use App\Models\PercakapanInbox;
use App\Models\PesanInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileInboxController extends Controller
{
    public function __construct(private readonly InboxController $legacyInboxController)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = PercakapanInbox::query()
            ->where('klien_id', $user->klien_id)
            ->with(['pesanTerakhirRelasi'])
            ->orderByDesc('waktu_pesan_terakhir');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($inner) use ($search) {
                $inner->where('nama_customer', 'like', "%{$search}%")
                    ->orWhere('no_whatsapp', 'like', "%{$search}%")
                    ->orWhere('pesan_terakhir', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $perPage = min((int) $request->integer('per_page', 20), 100);
        $items = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $items->getCollection()->map(fn (PercakapanInbox $item) => [
                'id' => $item->id,
                'contact_name' => $item->nama_customer,
                'phone' => $item->no_whatsapp,
                'last_message' => $item->pesan_terakhir,
                'last_message_at' => optional($item->waktu_pesan_terakhir)?->toIso8601String(),
                'unread_count' => (int) ($item->pesan_belum_dibaca ?? 0),
                'status' => $item->status,
                'assigned_to_me' => (int) $item->ditangani_oleh === (int) $user->id,
            ])->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function show(Request $request, int $percakapanId): JsonResponse
    {
        $user = $request->user();

        $conversation = PercakapanInbox::where('id', $percakapanId)
            ->where('klien_id', $user->klien_id)
            ->first();

        if (!$conversation) {
            return response()->json([
                'success' => false,
                'message' => 'Percakapan tidak ditemukan',
            ], 404);
        }

        $messages = PesanInbox::where('percakapan_id', $percakapanId)
            ->orderBy('waktu_pesan')
            ->get()
            ->map(fn (PesanInbox $item) => [
                'id' => $item->id,
                'direction' => $item->arah === 'masuk' ? 'inbound' : 'outbound',
                'type' => $item->tipe ?? 'teks',
                'content' => $item->preview,
                'timestamp' => optional($item->waktu_pesan)?->toIso8601String(),
                'status' => $item->status ?? 'unknown',
            ])->values();

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'conversation' => [
                    'id' => $conversation->id,
                    'contact_name' => $conversation->nama_customer,
                    'phone' => $conversation->no_whatsapp,
                    'status' => $conversation->status,
                    'priority' => $conversation->prioritas ?? 'normal',
                ],
                'messages' => $messages,
            ],
        ]);
    }

    public function send(Request $request, int $percakapanId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $request->merge([
            'tipe' => 'teks',
            'isi_pesan' => $request->input('message'),
        ]);

        $response = $this->legacyInboxController->kirimPesan($percakapanId, $request);
        $payload = $response->getData(true);
        $data = [
            'status' => ($payload['sukses'] ?? false) ? 'queued' : 'failed',
        ];

        foreach (['error_code', 'topup_url', 'errors'] as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        return response()->json([
            'success' => (bool) ($payload['sukses'] ?? false),
            'message' => $payload['pesan'] ?? 'Pesan diproses',
            'data' => $data,
        ], $response->getStatusCode());
    }

    public function read(Request $request, int $percakapanId): JsonResponse
    {
        $response = $this->legacyInboxController->tandaiBaca($percakapanId);
        $payload = $response->getData(true);

        return response()->json([
            'success' => (bool) ($payload['sukses'] ?? false),
            'message' => $payload['pesan'] ?? 'Percakapan ditandai sudah dibaca',
        ], $response->getStatusCode());
    }
}