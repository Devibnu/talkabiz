<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Models\Plan;
use App\Services\PlanService;
use App\Services\PlanAuditService;
use Illuminate\Http\Request;

/**
 * OwnerPlanController - Subscription Only (FINAL CLEAN)
 *
 * CRUD operations untuk Paket di Owner Panel.
 * Plan = FITUR dan AKSES saja. Saldo = terpisah (Wallet).
 */
class OwnerPlanController extends Controller
{
    protected PlanService $planService;
    protected PlanAuditService $auditService;

    public function __construct(PlanService $planService, PlanAuditService $auditService)
    {
        $this->planService = $planService;
        $this->auditService = $auditService;
    }

    /**
     * Display all plans with pagination
     */
    public function index(Request $request)
    {
        $stats = [
            'total' => Plan::count(),
            'active' => Plan::where('is_active', true)->count(),
            'self_serve' => Plan::where('is_self_serve', true)->count(),
            'visible' => Plan::where('is_visible', true)->count(),
        ];

        $plans = Plan::query()
            ->orderBy('price_monthly')
            ->paginate(10);

        $popularPlan = $this->planService->getPopularPlan();

        return view('owner.plans.index', compact('plans', 'stats', 'popularPlan'));
    }

    /**
     * Show create form
     */
    public function create()
    {
        $features = Plan::getAllFeatures();

        return view('owner.plans.create', compact('features'));
    }

    /**
     * Store new plan
     */
    public function store(StorePlanRequest $request)
    {
        $validated = $request->validated();

        // Process features from checkboxes
        $validated['features'] = $request->input('features', []);

        // Process boolean fields
        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_visible'] = $request->boolean('is_visible');
        $validated['is_self_serve'] = $request->boolean('is_self_serve');
        $validated['is_popular'] = $request->boolean('is_popular');

        $plan = $this->planService->createPlan($validated, auth()->id());

        return redirect()
            ->route('owner.plans.index')
            ->with('success', "Paket \"{$plan->name}\" berhasil dibuat.");
    }

    /**
     * Show edit form
     */
    public function edit(Plan $plan)
    {
        if (!session()->has('errors')) {
            session()->forget('_old_input');
        }

        $features = Plan::getAllFeatures();
        $auditLogs = $this->auditService->getLogsForPlan($plan, 10);

        return view('owner.plans.edit', compact('plan', 'features', 'auditLogs'));
    }

    /**
     * Update plan
     */
    public function update(UpdatePlanRequest $request, Plan $plan)
    {
        $validated = $request->validated();

        // Process features from checkboxes
        $validated['features'] = $request->input('features', []);

        // Process boolean fields
        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_visible'] = $request->boolean('is_visible');
        $validated['is_self_serve'] = $request->boolean('is_self_serve');
        $validated['is_popular'] = $request->boolean('is_popular');

        $this->planService->updatePlan($plan, $validated, auth()->id());

        return redirect()
            ->route('owner.plans.index')
            ->with('success', "Paket \"{$plan->name}\" berhasil diupdate.");
    }

    /**
     * Toggle active status (AJAX)
     */
    public function toggleActive(Plan $plan)
    {
        try {
            $plan = $this->planService->toggleActive($plan, auth()->id());

            if (request()->ajax() || request()->wantsJson() || request()->header('Accept') === 'application/json') {
                return response()->json([
                    'success' => true,
                    'is_active' => $plan->is_active,
                    'message' => $plan->is_active ? 'Paket diaktifkan' : 'Paket dinonaktifkan',
                ]);
            }

            return redirect()
                ->route('owner.plans.index')
                ->with('success', $plan->is_active ? 'Paket diaktifkan' : 'Paket dinonaktifkan');
        } catch (\Exception $e) {
            if (request()->ajax() || request()->wantsJson() || request()->header('Accept') === 'application/json') {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengubah status: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('owner.plans.index')
                ->with('error', 'Gagal mengubah status aktif');
        }
    }

    /**
     * Toggle popular status (AJAX)
     */
    public function togglePopular(Plan $plan)
    {
        try {
            $plan = $plan->is_popular
                ? $this->planService->unmarkAsPopular($plan, auth()->id())
                : $this->planService->markAsPopular($plan, auth()->id());

            if (request()->ajax() || request()->wantsJson() || request()->header('Accept') === 'application/json') {
                return response()->json([
                    'success' => true,
                    'is_popular' => $plan->is_popular,
                    'message' => $plan->is_popular ? 'Ditandai sebagai Popular' : 'Dihapus dari Popular',
                ]);
            }

            return redirect()
                ->route('owner.plans.index')
                ->with('success', $plan->is_popular ? 'Ditandai sebagai Popular' : 'Dihapus dari Popular');
        } catch (\Exception $e) {
            if (request()->ajax() || request()->wantsJson() || request()->header('Accept') === 'application/json') {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengubah status popular: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('owner.plans.index')
                ->with('error', 'Gagal mengubah status popular');
        }
    }

    /**
     * Show plan detail with audit history
     */
    public function show(Plan $plan)
    {
        $auditLogs = $this->auditService->getLogsForPlan($plan, 50);
        $stats = $this->auditService->getStatsForPlan($plan);

        return view('owner.plans.show', compact('plan', 'auditLogs', 'stats'));
    }
}
