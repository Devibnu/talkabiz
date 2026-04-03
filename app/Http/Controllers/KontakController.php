<?php

namespace App\Http\Controllers;

use App\Models\Kontak;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class KontakController extends Controller
{
    protected OnboardingService $onboardingService;

    public function __construct(OnboardingService $onboardingService)
    {
        $this->onboardingService = $onboardingService;
    }

    /**
     * Display kontak list page
     */
    public function index()
    {
        $klienId = Auth::user()->klien_id;
        $kontaks = Kontak::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->get();
            
        return view('kontak', compact('kontaks'));
    }

    /**
     * Get kontaks as JSON
     */
    public function list(Request $request)
    {
        $klienId = Auth::user()->klien_id;
        $kontaks = Kontak::where('klien_id', $klienId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $kontaks
        ]);
    }

    /**
     * Store new kontak
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'no_telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'tags' => 'nullable|string',
            'catatan' => 'nullable|string|max:1000',
        ], [
            'nama.required' => 'Nama kontak wajib diisi',
            'no_telepon.required' => 'Nomor telepon wajib diisi',
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

        // Parse tags from comma-separated string
        $tags = null;
        if ($request->tags) {
            $tags = array_map('trim', explode(',', $request->tags));
        }

        $kontak = Kontak::create([
            'nama' => $request->nama,
            'no_telepon' => $request->no_telepon,
            'email' => $request->email,
            'tags' => $tags,
            'catatan' => $request->catatan,
            'source' => Kontak::SOURCE_MANUAL,
            'klien_id' => Auth::user()->klien_id,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Kontak berhasil ditambahkan',
                'data' => $kontak
            ]);
        }

        // Auto-track onboarding step: contact_added
        $this->onboardingService->trackContactAdded(Auth::user());

        return redirect()->route('kontak')->with('success', 'Kontak berhasil ditambahkan');
    }

    /**
     * Import contacts from CSV
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:5120',
        ], [
            'file.required' => 'File CSV wajib diupload',
            'file.mimes' => 'File harus berformat CSV',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $path = $file->getRealPath();
        
        $imported = 0;
        $failed = 0;
        
        if (($handle = fopen($path, 'r')) !== false) {
            $header = fgetcsv($handle); // Skip header row
            
            while (($row = fgetcsv($handle)) !== false) {
                try {
                    if (count($row) >= 2) {
                        Kontak::create([
                            'nama' => $row[0] ?? 'Tanpa Nama',
                            'no_telepon' => $row[1] ?? '',
                            'email' => $row[2] ?? null,
                            'tags' => isset($row[3]) ? array_map('trim', explode(',', $row[3])) : null,
                            'source' => Kontak::SOURCE_IMPORT,
                            'klien_id' => Auth::user()->klien_id,
                        ]);
                        $imported++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                }
            }
            fclose($handle);
        }

        return response()->json([
            'success' => true,
            'message' => "Import selesai. {$imported} kontak berhasil, {$failed} gagal.",
            'imported' => $imported,
            'failed' => $failed
        ]);
    }

    /**
     * Update kontak
     */
    public function update(Request $request, $id)
    {
        $kontak = Kontak::where('id', $id)
            ->where('klien_id', Auth::user()->klien_id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'nama' => 'required|string|max:255',
            'no_telepon' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'tags' => 'nullable|string',
            'catatan' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $tags = null;
        if ($request->tags) {
            $tags = array_map('trim', explode(',', $request->tags));
        }

        $kontak->update([
            'nama' => $request->nama,
            'no_telepon' => $request->no_telepon,
            'email' => $request->email,
            'tags' => $tags,
            'catatan' => $request->catatan,
        ]);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Kontak berhasil diperbarui', 'data' => $kontak]);
        }

        return redirect()->route('kontak')->with('success', 'Kontak berhasil diperbarui');
    }

    /**
     * Download CSV template for import
     */
    public function downloadTemplate()
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="template_import_kontak.csv"',
        ];

        $callback = function () {
            $handle = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['nama', 'no_telepon', 'email', 'tags']);
            fputcsv($handle, ['John Doe', '6281234567890', 'john@example.com', 'customer,vip']);
            fputcsv($handle, ['Jane Smith', '6289876543210', 'jane@example.com', 'prospect']);
            fputcsv($handle, ['Ahmad Budi', '6285551234567', '', 'customer']);
            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Delete kontak
     */
    public function destroy($id)
    {
        $kontak = Kontak::where('id', $id)
            ->where('klien_id', Auth::user()->klien_id)
            ->firstOrFail();

        $kontak->delete();

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Kontak berhasil dihapus'
            ]);
        }

        return redirect()->route('kontak')->with('success', 'Kontak berhasil dihapus');
    }
}
