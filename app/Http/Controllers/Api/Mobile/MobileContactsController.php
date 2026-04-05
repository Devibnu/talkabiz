<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Kontak;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MobileContactsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $klienId = $user?->klien_id;

        if (!$klienId) {
            return response()->json([
                'success' => false,
                'message' => 'Klien tidak ditemukan',
            ], 403);
        }

        $query = Kontak::query()
            ->where('klien_id', $klienId)
            ->latest('created_at');

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(function ($inner) use ($search) {
                $inner->where('nama', 'like', "%{$search}%")
                    ->orWhere('no_telepon', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tag')) {
            $query->whereJsonContains('tags', $request->string('tag')->value());
        }

        $perPage = min((int) $request->integer('per_page', 20), 100);
        $contacts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $contacts->getCollection()->map(fn (Kontak $kontak) => $this->transformListItem($kontak))->values(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $kontak = $this->findOwnedContact($request, $id);

        if (!$kontak) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $this->transformDetailItem($kontak),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = $this->validator($request, false);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $kontak = Kontak::create([
            'klien_id' => $user->klien_id,
            'nama' => $request->string('name')->value(),
            'no_telepon' => $request->string('phone')->value(),
            'email' => $request->string('email')->value() ?: null,
            'tags' => $this->normalizeTags($request),
            'catatan' => $request->string('notes')->value() ?: null,
            'source' => Kontak::SOURCE_API,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kontak berhasil ditambahkan',
            'data' => $this->transformDetailItem($kontak),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $kontak = $this->findOwnedContact($request, $id);

        if (!$kontak) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan',
            ], 404);
        }

        $validator = $this->validator($request, true);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = [];

        if ($request->exists('name')) {
            $payload['nama'] = $request->string('name')->value();
        }

        if ($request->exists('phone')) {
            $payload['no_telepon'] = $request->string('phone')->value();
        }

        if ($request->exists('email')) {
            $payload['email'] = $request->string('email')->value() ?: null;
        }

        if ($request->exists('tags')) {
            $payload['tags'] = $this->normalizeTags($request);
        }

        if ($request->exists('notes')) {
            $payload['catatan'] = $request->string('notes')->value() ?: null;
        }

        $kontak->update($payload);

        return response()->json([
            'success' => true,
            'message' => 'Kontak berhasil diperbarui',
            'data' => $this->transformDetailItem($kontak->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $kontak = $this->findOwnedContact($request, $id);

        if (!$kontak) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan',
            ], 404);
        }

        $kontak->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kontak berhasil dihapus',
        ]);
    }

    private function findOwnedContact(Request $request, int $id): ?Kontak
    {
        return Kontak::where('id', $id)
            ->where('klien_id', $request->user()?->klien_id)
            ->first();
    }

    private function validator(Request $request, bool $isUpdate)
    {
        return Validator::make($request->all(), [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function normalizeTags(Request $request): ?array
    {
        $tags = $request->input('tags');
        if (!is_array($tags) || empty($tags)) {
            return null;
        }

        return array_values(array_filter(array_map(static fn ($tag) => trim((string) $tag), $tags)));
    }

    private function transformListItem(Kontak $kontak): array
    {
        return [
            'id' => $kontak->id,
            'name' => $kontak->nama,
            'phone' => $kontak->no_telepon,
            'email' => $kontak->email,
            'tags' => $kontak->tags ?? [],
            'last_interaction_at' => null,
        ];
    }

    private function transformDetailItem(Kontak $kontak): array
    {
        return [
            'id' => $kontak->id,
            'name' => $kontak->nama,
            'phone' => $kontak->no_telepon,
            'email' => $kontak->email,
            'tags' => $kontak->tags ?? [],
            'notes' => $kontak->catatan,
            'source' => $kontak->source,
        ];
    }
}
