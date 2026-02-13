<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExecutiveOwnerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EXECUTIVE OWNER DASHBOARD CONTROLLER
 * 
 * API Controller untuk Executive Dashboard khusus Owner.
 * 
 * Endpoints:
 * - GET /api/executive/dashboard - Get dashboard data
 * - POST /api/executive/dashboard/refresh - Force refresh cache
 * 
 * Target User: Owner/C-Level (non-teknis)
 */
class ExecutiveOwnerDashboardController extends Controller
{
    public function __construct(
        private ExecutiveOwnerDashboardService $dashboardService
    ) {}

    /**
     * Get Executive Owner Dashboard
     * 
     * GET /api/executive/dashboard
     * 
     * Response di-cache selama 60-120 detik untuk performa optimal.
     * Data aggregated dari snapshot & risk system (tidak query raw tables).
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $dashboard = $this->dashboardService->getDashboard();

            return response()->json([
                'success' => true,
                'data' => $dashboard->toArray(),
            ], 200, [], JSON_PRETTY_PRINT);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Terjadi kesalahan saat memuat dashboard.',
                    'code' => 'DASHBOARD_ERROR',
                ],
                'data' => $this->getFallbackData(),
            ], 500);
        }
    }

    /**
     * Force refresh dashboard (bypass cache)
     * 
     * POST /api/executive/dashboard/refresh
     * 
     * Gunakan dengan bijak - hanya jika data terlihat stale.
     * 
     * @return JsonResponse
     */
    public function refresh(): JsonResponse
    {
        try {
            $dashboard = $this->dashboardService->refreshDashboard();

            return response()->json([
                'success' => true,
                'message' => 'Dashboard berhasil di-refresh.',
                'data' => $dashboard->toArray(),
            ], 200, [], JSON_PRETTY_PRINT);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Gagal me-refresh dashboard.',
                    'code' => 'REFRESH_ERROR',
                ],
            ], 500);
        }
    }

    /**
     * Get minimal health status only
     * 
     * GET /api/executive/health
     * 
     * Lightweight endpoint untuk quick status check.
     * 
     * @return JsonResponse
     */
    public function health(): JsonResponse
    {
        try {
            $dashboard = $this->dashboardService->getDashboard();

            return response()->json([
                'success' => true,
                'data' => [
                    'health' => $dashboard->toArray()['health'],
                    'action' => $dashboard->actionRecommendation,
                    'has_critical_issues' => $dashboard->hasCriticalIssues(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'data' => [
                    'health' => [
                        'score' => 0,
                        'status' => 'error',
                        'message' => 'Tidak dapat memuat status.',
                    ],
                ],
            ], 500);
        }
    }

    /**
     * Get fallback data when error occurs
     */
    private function getFallbackData(): array
    {
        return [
            'health' => [
                'score' => 0,
                'status' => 'error',
                'message' => 'Sedang dalam perbaikan. Silakan coba lagi.',
            ],
            'top_risks' => [[
                'risk' => 'Data Tidak Tersedia',
                'severity' => 'warning',
                'impact' => 'Silakan refresh halaman atau coba lagi nanti',
            ]],
            'platform_stability' => [
                'overall' => [
                    'status' => 'unknown',
                    'label' => 'Status Tidak Diketahui',
                    'icon' => '⚪',
                ],
            ],
            'revenue_at_risk' => [
                'at_risk_revenue_formatted' => 'Rp -',
                'at_risk_customers' => 0,
                'message' => 'Data tidak tersedia',
            ],
            'incident_summary' => [
                'message' => 'Data tidak tersedia',
            ],
            'action_recommendation' => '⚠️ Sistem sedang mengalami gangguan, tim teknis sudah diinformasikan.',
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'cache_expires_in' => 0,
                'note' => 'Data fallback - sistem sedang recovery',
            ],
        ];
    }
}
