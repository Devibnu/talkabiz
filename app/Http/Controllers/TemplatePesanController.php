<?php

namespace App\Http\Controllers;

use App\Models\TemplatePesan;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $templates = TemplatePesan::where('dibuat_oleh', Auth::id())
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
        $templates = TemplatePesan::where('dibuat_oleh', Auth::id())
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
            'kategori' => 'required|string|in:marketing,utility,authentication,transactional,notification,greeting,follow_up,other',
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
            'nama' => $request->nama,
            'kategori' => $request->kategori,
            'isi_body' => $request->konten,
            'status' => TemplatePesan::STATUS_DRAFT,
            'dibuat_oleh' => Auth::id(),
            'klien_id' => Auth::user()->klien_id ?? null,
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
            ->where('dibuat_oleh', Auth::id())
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
            'nama' => $request->nama,
            'kategori' => $request->kategori,
            'isi_body' => $request->konten,
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
            ->where('dibuat_oleh', Auth::id())
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
}
