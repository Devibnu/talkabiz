<?php

namespace App\Http\Controllers\Api\Owner;

use App\Http\Controllers\Controller;
use App\Models\MonthlyClosing;
use App\Models\MonthlyClosingDetail;
use App\Services\MonthlyClosingService;
use App\Services\Exports\MonthlyClosingCsvExportService;
use App\Services\Exports\MonthlyClosingPdfExportService;
use App\Jobs\MonthlyClosingJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class MonthlyClosingController extends Controller
{
    protected MonthlyClosingService $closingService;
    protected MonthlyClosingCsvExportService $csvService;
    protected MonthlyClosingPdfExportService $pdfService;

    public function __construct(
        MonthlyClosingService $closingService,
        MonthlyClosingCsvExportService $csvService,
        MonthlyClosingPdfExportService $pdfService
    ) {
        $this->closingService = $closingService;
        $this->csvService = $csvService;
        $this->pdfService = $pdfService;
        
        // Middleware auth untuk owner panel
        $this->middleware('auth:api');
        $this->middleware('role:owner,admin'); // Assuming role-based access control
    }

    /**
     * Get list of monthly closings
     * GET /api/owner/monthly-closings
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'nullable|integer|min:2020|max:' . (date('Y') + 1),
            'status' => 'nullable|in:in_progress,completed,failed',
            'include_details' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $query = MonthlyClosing::with('creator:id,name,email')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc');

            // Apply filters
            if ($request->filled('year')) {
                $query->forYear($request->year);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            // Include details if requested
            if ($request->boolean('include_details')) {
                $query->withCount([
                    'details',
                    'details as balanced_details_count' => function($q) {
                        $q->where('is_balanced', true);
                    },
                    'details as variance_details_count' => function($q) {
                        $q->where('is_balanced', false);
                    }
                ]);
            }

            $perPage = $request->integer('per_page', 20);
            $closings = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Monthly closings retrieved successfully',
                'data' => [
                    'closings' => $closings->items(),
                    'pagination' => [
                        'current_page' => $closings->currentPage(),
                        'last_page' => $closings->lastPage(),
                        'per_page' => $closings->perPage(),
                        'total' => $closings->total(),
                        'from' => $closings->firstItem(),
                        'to' => $closings->lastItem()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve monthly closings", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve monthly closings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific monthly closing details
     * GET /api/owner/monthly-closings/{closing}
     */
    public function show(MonthlyClosing $closing): JsonResponse
    {
        try {
            $closing->load([
                'creator:id,name,email',
                'details' => function($query) {
                    $query->with('user:id,name,email')
                          ->orderBy('closing_balance', 'desc')
                          ->limit(100); // Limit untuk performance
                }
            ]);

            // Get summary
            $summary = $this->closingService->getClosingSummary($closing->id);
            
            // Get recommendations
            $recommendations = $this->closingService->getRecommendations($closing);

            return response()->json([
                'success' => true,
                'message' => 'Monthly closing details retrieved successfully',
                'data' => [
                    'closing' => $closing,
                    'summary' => $summary,
                    'recommendations' => $recommendations
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve closing details", [
                'closing_id' => $closing->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve closing details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new monthly closing
     * POST /api/owner/monthly-closings
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'process_async' => 'nullable|boolean',
            'auto_export' => 'nullable|boolean',
            'export_options' => 'nullable|array'
        ]);

        try {
            $year = $request->integer('year');
            $month = $request->integer('month');
            $processAsync = $request->boolean('process_async', true);
            $autoExport = $request->boolean('auto_export', true);

            // Validate periode
            if (!MonthlyClosing::canClosePeriod($year, $month)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot create closing for future period',
                    'error' => "Period {$year}-{$month} is in the future"
                ], 422);
            }

            // Check if already exists
            $existingClosing = MonthlyClosing::forPeriod($year, $month)->first();
            if ($existingClosing && $existingClosing->is_locked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Closing already exists and is locked',
                    'data' => ['existing_closing' => $existingClosing]
                ], 422);
            }

            if ($processAsync) {
                // Dispatch job
                MonthlyClosingJob::dispatchForPeriod(
                    $year,
                    $month,
                    Auth::id(),
                    [
                        'auto_export' => $autoExport,
                        'send_notifications' => true,
                        'export_options' => $request->get('export_options', [])
                    ]
                );

                return response()->json([
                    'success' => true,
                    'message' => 'Monthly closing job dispatched successfully',
                    'data' => [
                        'period' => sprintf('%04d-%02d', $year, $month),
                        'estimated_completion' => now()->addMinutes(5)->toISOString(),
                        'process_async' => true
                    ]
                ], 202);

            } else {
                // Process synchronously
                $closing = $this->closingService->processMonthlyClosing($year, $month, Auth::id());

                return response()->json([
                    'success' => true,
                    'message' => 'Monthly closing processed successfully',
                    'data' => [
                        'closing' => $closing,
                        'process_async' => false
                    ]
                ], 201);
            }

        } catch (\Exception $e) {
            Log::error("Failed to create monthly closing", [
                'year' => $request->integer('year'),
                'month' => $request->integer('month'),
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create monthly closing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry failed closing
     * POST /api/owner/monthly-closings/{closing}/retry
     */
    public function retry(MonthlyClosing $closing): JsonResponse
    {
        try {
            if ($closing->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed closings can be retried',
                    'data' => ['current_status' => $closing->status]
                ], 422);
            }

            $retriedClosing = $this->closingService->retryFailedClosing($closing->id);

            return response()->json([
                'success' => true,
                'message' => 'Closing retry completed successfully',
                'data' => ['closing' => $retriedClosing]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retry closing", [
                'closing_id' => $closing->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retry closing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Force unlock closing (admin only)
     * POST /api/owner/monthly-closings/{closing}/unlock
     */
    public function unlock(Request $request, MonthlyClosing $closing): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        try {
            // Additional admin check
            if (!Auth::user()->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only administrators can unlock closings'
                ], 403);
            }

            $unlocked = $this->closingService->forceUnlockClosing(
                $closing->id,
                $request->reason,
                Auth::id()
            );

            return response()->json([
                'success' => $unlocked,
                'message' => $unlocked ? 'Closing unlocked successfully' : 'Failed to unlock closing',
                'data' => ['closing' => $closing->fresh()]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to unlock closing", [
                'closing_id' => $closing->id,
                'user_id' => Auth::id(),
                'reason' => $request->reason,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to unlock closing',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export closing data
     * POST /api/owner/monthly-closings/{closing}/export
     */
    public function export(Request $request, MonthlyClosing $closing): JsonResponse
    {
        $request->validate([
            'format' => 'required|in:csv,pdf',
            'type' => 'required|string',
            'filters' => 'nullable|array',
            'options' => 'nullable|array'
        ]);

        try {
            if (!$closing->is_locked) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot export unlocked closing'
                ], 422);
            }

            $format = $request->format;
            $type = $request->type;
            $filters = $request->get('filters', []);
            $options = $request->get('options', []);

            $result = match($format) {
                'csv' => $this->csvService->exportClosingTransactions($closing->id, $type, $filters),
                'pdf' => $this->pdfService->generateClosingReport($closing->id, $type, $options),
                default => throw new \Exception("Unsupported export format: {$format}")
            };

            // Log export activity
            Log::info("Closing export completed", [
                'closing_id' => $closing->id,
                'format' => $format,
                'type' => $type,
                'user_id' => Auth::id(),
                'filename' => $result['filename'],
                'file_size' => $result['file_size']
            ]);

            return response()->json([
                'success' => true,
                'message' => ucfirst($format) . ' export completed successfully',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("Export failed", [
                'closing_id' => $closing->id,
                'format' => $request->format,
                'type' => $request->type,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Export failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available export types
     * GET /api/owner/monthly-closings/export-types
     */
    public function exportTypes(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'message' => 'Export types retrieved successfully',
                'data' => [
                    'csv_types' => $this->csvService->getAvailableExportTypes(),
                    'pdf_types' => $this->pdfService->getAvailableReportTypes()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve export types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download export file
     * GET /api/owner/monthly-closings/exports/{filename}
     */
    public function downloadExport(string $filename): \Illuminate\Http\Response
    {
        try {
            // Validate filename format untuk security
            if (!preg_match('/^closing_\d{4}-\d{2}_[a-z_]+_\d{14}\.(csv|pdf)$/', $filename)) {
                abort(400, 'Invalid filename format');
            }

            // Try CSV path first, then PDF path
            $csvPath = 'exports/monthly-closings/csv/' . $filename;
            $pdfPath = 'exports/monthly-closings/pdf/' . $filename;

            $filepath = Storage::exists($csvPath) ? $csvPath : 
                       (Storage::exists($pdfPath) ? $pdfPath : null);

            if (!$filepath) {
                abort(404, 'Export file not found');
            }

            // Log download activity
            Log::info("Export file downloaded", [
                'filename' => $filename,
                'user_id' => Auth::id(),
                'downloaded_at' => now()
            ]);

            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $mimeType = $extension === 'csv' ? 'text/csv' : 'application/pdf';

            return response(Storage::get($filepath), 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => Storage::size($filepath),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            ]);

        } catch (\Exception $e) {
            Log::error("Export download failed", [
                'filename' => $filename,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            abort(500, 'Failed to download export file');
        }
    }

    /**
     * Get user details for a closing
     * GET /api/owner/monthly-closings/{closing}/users
     */
    public function users(Request $request, MonthlyClosing $closing): JsonResponse
    {
        $request->validate([
            'tier' => 'nullable|string',
            'activity_level' => 'nullable|in:inactive,low,medium,high,very_high',
            'has_variance' => 'nullable|boolean',
            'min_balance' => 'nullable|numeric',
            'max_balance' => 'nullable|numeric',
            'sort_by' => 'nullable|in:balance,activity,variance',
            'sort_order' => 'nullable|in:asc,desc',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        try {
            $query = $closing->details()->with('user:id,name,email');

            // Apply filters
            if ($request->filled('tier')) {
                $query->where('user_tier', $request->tier);
            }

            if ($request->filled('has_variance')) {
                if ($request->boolean('has_variance')) {
                    $query->withVariance();
                } else {
                    $query->balanced();
                }
            }

            if ($request->filled('min_balance')) {
                $query->where('closing_balance', '>=', $request->min_balance);
            }

            if ($request->filled('max_balance')) {
                $query->where('closing_balance', '<=', $request->max_balance);
            }

            if ($request->filled('activity_level')) {
                $activityLevel = $request->activity_level;
                $query->whereRaw('
                    CASE 
                        WHEN transaction_count = 0 THEN "inactive"
                        WHEN transaction_count < 5 THEN "low"
                        WHEN transaction_count < 20 THEN "medium"
                        WHEN transaction_count < 50 THEN "high"
                        ELSE "very_high"
                    END = ?
                ', [$activityLevel]);
            }

            // Apply sorting
            $sortBy = $request->get('sort_by', 'balance');
            $sortOrder = $request->get('sort_order', 'desc');

            switch ($sortBy) {
                case 'balance':
                    $query->orderBy('closing_balance', $sortOrder);
                    break;
                case 'activity':
                    $query->orderBy('transaction_count', $sortOrder);
                    break;
                case 'variance':
                    $query->orderBy('balance_variance', $sortOrder);
                    break;
            }

            $perPage = $request->integer('per_page', 50);
            $details = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'data' => [
                    'users' => $details->items(),
                    'pagination' => [
                        'current_page' => $details->currentPage(),
                        'last_page' => $details->lastPage(),
                        'per_page' => $details->perPage(),
                        'total' => $details->total(),
                        'from' => $details->firstItem(),
                        'to' => $details->lastItem()
                    ],
                    'filters_applied' => array_filter($request->only([
                        'tier', 'activity_level', 'has_variance', 'min_balance', 'max_balance'
                    ]))
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve user details", [
                'closing_id' => $closing->id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user details',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard summary
     * GET /api/owner/monthly-closings/dashboard
     */
    public function dashboard(): JsonResponse
    {
        try {
            // Get recent closings
            $recentClosings = MonthlyClosing::recent(6)
                ->withCount(['details as variance_details_count' => function($q) {
                    $q->where('is_balanced', false);
                }])
                ->get();

            // Get status summary
            $statusSummary = MonthlyClosing::selectRaw('
                status,
                COUNT(*) as count,
                SUM(CASE WHEN is_balanced = 0 THEN 1 ELSE 0 END) as with_variance
            ')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

            // Get latest completed closing
            $latestClosing = MonthlyClosing::completed()
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc')
                ->first();

            // Calculate key metrics
            $metrics = [
                'total_closings' => MonthlyClosing::count(),
                'completed_closings' => MonthlyClosing::completed()->count(),
                'failed_closings' => MonthlyClosing::failed()->count(),
                'closings_with_variance' => MonthlyClosing::withVariance()->count()
            ];

            if ($latestClosing) {
                $metrics['latest_period'] = $latestClosing->formatted_period;
                $metrics['latest_balance'] = $latestClosing->closing_balance;
                $metrics['latest_variance'] = $latestClosing->balance_variance;
            }

            return response()->json([
                'success' => true,
                'message' => 'Dashboard data retrieved successfully',
                'data' => [
                    'recent_closings' => $recentClosings,
                    'status_summary' => $statusSummary,
                    'latest_closing' => $latestClosing,
                    'metrics' => $metrics
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to retrieve dashboard data", [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard data',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}