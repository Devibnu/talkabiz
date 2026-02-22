<?php

namespace App\Http\Controllers;

use App\Models\Klien;
use App\Models\Plan;
use App\Services\PlanChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use DomainException;
use Throwable;

/**
 * PlanChangeController — Handles plan upgrade/downgrade requests.
 * 
 * ENDPOINTS:
 *   POST /subscription/change-plan        → Execute plan change
 *   POST /subscription/change-plan/preview → Preview prorate calculation
 * 
 * FLOW:
 *   1. Frontend shows available plans on subscription page
 *   2. User clicks "Ganti Paket" → POST preview to see cost/refund
 *   3. User confirms → POST change-plan
 *   4. Upgrade: returns snap_token for Midtrans popup
 *   5. Downgrade: immediate switch + wallet credit
 */
class PlanChangeController extends Controller
{
    protected PlanChangeService $planChangeService;

    public function __construct(PlanChangeService $planChangeService)
    {
        $this->middleware('auth');
        $this->middleware('ensure.client');
        $this->planChangeService = $planChangeService;
    }

    /**
     * Preview prorate calculation before executing.
     * 
     * POST /subscription/change-plan/preview
     * Body: { plan_code: string }
     * 
     * Returns calculation breakdown without making any changes.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
        ]);

        try {
            $user = Auth::user();
            $preview = $this->planChangeService->getChangePlanPreview(
                $user,
                $request->input('plan_code')
            );

            return response()->json([
                'success' => true,
                'data' => $preview,
            ]);

        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            Log::error('[PlanChange] Preview failed', [
                'user_id' => Auth::id(),
                'plan_code' => $request->input('plan_code'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menghitung prorate. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Execute plan change (upgrade/downgrade).
     * 
     * POST /subscription/change-plan
     * Body: { plan_code: string }
     * 
     * UPGRADE → returns { snap_token } for Midtrans popup
     * DOWNGRADE → returns { success, message, refund_amount }
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
        ]);

        try {
            $user = Auth::user();
            $result = $this->planChangeService->changePlan(
                $user,
                $request->input('plan_code')
            );

            $statusCode = ($result['success'] ?? false) ? 200 : 400;

            return response()->json($result, $statusCode);

        } catch (DomainException $e) {
            Log::warning('[PlanChange] Business rule violation', [
                'user_id' => Auth::id(),
                'plan_code' => $request->input('plan_code'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            Log::error('[PlanChange] Execution failed', [
                'user_id' => Auth::id(),
                'plan_code' => $request->input('plan_code'),
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses perubahan paket. Silakan coba lagi.',
            ], 500);
        }
    }
}
