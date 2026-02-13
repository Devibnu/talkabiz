<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * OwnerBusinessTypeController - Master Data Business Type Management
 * 
 * FEATURES:
 * - List all business types (active & inactive)
 * - Create new business type
 * - Edit existing business type
 * - Toggle active status (NO hard delete)
 * 
 * SECURITY:
 * - Only accessible by Owner/Super Admin
 * - Code uniqueness enforced
 * - Code format: UPPERCASE_SNAKE_CASE
 * - Cannot delete (only disable)
 */
class OwnerBusinessTypeController extends Controller
{
    /**
     * Display a listing of business types.
     */
    public function index()
    {
        $businessTypes = BusinessType::query()
            ->withCount('kliens')
            ->ordered()
            ->get();

        Log::info('📋 Owner accessed business types list', [
            'user_id' => auth()->id(),
            'total_types' => $businessTypes->count(),
        ]);

        return view('owner.master.business-types.index', compact('businessTypes'));
    }

    /**
     * Show the form for creating a new business type.
     */
    public function create()
    {
        $maxDisplayOrder = BusinessType::max('display_order') ?? 0;

        return view('owner.master.business-types.form', [
            'businessType' => new BusinessType(['display_order' => $maxDisplayOrder + 10]),
            'isEdit' => false,
        ]);
    }

    /**
     * Store a newly created business type.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z_]+$/',
                'unique:business_types,code',
            ],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'display_order' => 'required|integer|min:0',
        ], [
            'code.required' => 'Kode tipe bisnis wajib diisi',
            'code.regex' => 'Kode harus format lowercase_snake_case (contoh: perorangan, cv, pt, lainnya)',
            'code.unique' => 'Kode sudah digunakan',
            'name.required' => 'Nama tipe bisnis wajib diisi',
            'display_order.required' => 'Urutan tampilan wajib diisi',
        ]);

        try {
            $businessType = BusinessType::create($validated);

            Log::info('✅ Business type created', [
                'id' => $businessType->id,
                'code' => $businessType->code,
                'name' => $businessType->name,
                'created_by' => auth()->id(),
            ]);

            return redirect()
                ->route('owner.master.business-types.index')
                ->with('success', "Tipe bisnis '{$businessType->name}' berhasil ditambahkan.");

        } catch (\Exception $e) {
            Log::error('❌ Failed to create business type', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);

            return back()
                ->withInput()
                ->with('error', 'Gagal menambahkan tipe bisnis. Silakan coba lagi.');
        }
    }

    /**
     * Show the form for editing the business type.
     */
    public function edit(BusinessType $businessType)
    {
        return view('owner.master.business-types.form', [
            'businessType' => $businessType,
            'isEdit' => true,
        ]);
    }

    /**
     * Update the business type.
     */
    public function update(Request $request, BusinessType $businessType)
    {
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z_]+$/',
                Rule::unique('business_types', 'code')->ignore($businessType->id),
            ],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'display_order' => 'required|integer|min:0',
        ], [
            'code.required' => 'Kode tipe bisnis wajib diisi',
            'code.regex' => 'Kode harus format lowercase_snake_case',
            'code.unique' => 'Kode sudah digunakan',
            'name.required' => 'Nama tipe bisnis wajib diisi',
            'display_order.required' => 'Urutan tampilan wajib diisi',
        ]);

        try {
            $oldData = $businessType->toArray();
            $businessType->update($validated);

            Log::info('✅ Business type updated', [
                'id' => $businessType->id,
                'code' => $businessType->code,
                'changes' => array_diff_assoc($validated, $oldData),
                'updated_by' => auth()->id(),
            ]);

            return redirect()
                ->route('owner.master.business-types.index')
                ->with('success', "Tipe bisnis '{$businessType->name}' berhasil diperbarui.");

        } catch (\Exception $e) {
            Log::error('❌ Failed to update business type', [
                'id' => $businessType->id,
                'error' => $e->getMessage(),
            ]);

            return back()
                ->withInput()
                ->with('error', 'Gagal memperbarui tipe bisnis. Silakan coba lagi.');
        }
    }

    /**
     * Toggle active status of business type.
     * NO hard delete - only soft disable.
     */
    public function toggleActive(BusinessType $businessType)
    {
        try {
            // Check if can be deactivated (no active kliens using it)
            if ($businessType->is_active && !$businessType->canBeDeactivated()) {
                return back()->with('error', 
                    "Tidak dapat menonaktifkan tipe bisnis '{$businessType->name}' karena masih digunakan oleh klien aktif."
                );
            }

            $newStatus = !$businessType->is_active;
            $businessType->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? 'diaktifkan' : 'dinonaktifkan';

            Log::info('✅ Business type status toggled', [
                'id' => $businessType->id,
                'code' => $businessType->code,
                'new_status' => $newStatus ? 'active' : 'inactive',
                'toggled_by' => auth()->id(),
            ]);

            return back()->with('success', 
                "Tipe bisnis '{$businessType->name}' berhasil {$statusText}."
            );

        } catch (\Exception $e) {
            Log::error('❌ Failed to toggle business type status', [
                'id' => $businessType->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Gagal mengubah status. Silakan coba lagi.');
        }
    }
}
