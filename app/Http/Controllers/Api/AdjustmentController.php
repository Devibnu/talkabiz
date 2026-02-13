<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdjustmentService;
use App\Models\UserAdjustment;
use App\Models\AdjustmentCategory;
use App\Models\User;
use App\Enums\AdjustmentReasonCode;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdjustmentController extends Controller
{
    protected AdjustmentService $adjustmentService;

    public function __construct(AdjustmentService $adjustmentService)
    {
        $this->adjustmentService = $adjustmentService;
        
        // Only owners and admins can access adjustment endpoints
        $this->middleware(['auth:sanctum', 'role:owner,admin']);
    }

    // ==================== ADJUSTMENT MANAGEMENT ====================

    /**
     * Get all adjustments with filtering and pagination
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 100);
            $filters = $request->only(['status', 'direction', 'reason_code', 'date_from', 'date_to', 'user_id']);

            $query = UserAdjustment::with(['user', 'creator', 'approver', 'processor', 'latestApproval'])
                ->orderBy('created_at', 'desc');

            // Apply filters
            if (!empty($filters['status'])) {
                $query->status($filters['status']);
            }

            if (!empty($filters['direction'])) {
                $query->direction($filters['direction']);
            }

            if (!empty($filters['reason_code'])) {
                $query->reasonCode($filters['reason_code']);
            }

            if (!empty($filters['user_id'])) {
                $query->forUser($filters['user_id']);
            }

            if (!empty($filters['date_from'])) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            }

            if (!empty($filters['date_to'])) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            }

            // Search by user name or email
            if (!empty($request->search)) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Search by adjustment ID
            if (!empty($request->adjustment_id)) {
                $query->where('adjustment_id', 'like', "%{$request->adjustment_id}%");
            }

            $adjustments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $adjustments->items(),
                'meta' => [
                    'current_page' => $adjustments->currentPage(),
                    'per_page' => $adjustments->perPage(),
                    'total' => $adjustments->total(),
                    'last_page' => $adjustments->lastPage()
                ],
                'filters_applied' => array_filter($filters)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve adjustments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new balance adjustment
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'direction' => 'required|in:credit,debit',
                'amount' => 'required|numeric|min:0.01|max:10000000',
                'reason_code' => ['required', Rule::in(array_column(AdjustmentReasonCode::cases(), 'value'))],
                'reason_note' => 'required|string|min:5|max:1000',
                'attachment' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
                'supporting_data' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Additional business validations
            $reasonCode = AdjustmentReasonCode::fromValue($request->reason_code);
            
            // Check if direction is allowed for this reason code
            if (!in_array($request->direction, $reasonCode->allowedDirections())) {
                return response()->json([
                    'success' => false,
                    'message' => "Direction '{$request->direction}' not allowed for reason '{$reasonCode->label()}'"
                ], 422);
            }

            // Create adjustment
            $adjustment = $this->adjustmentService->createAdjustment($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Balance adjustment created successfully',
                'data' => $adjustment->load(['user', 'creator', 'latestApproval'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get specific adjustment details
     */
    public function show(int $id): JsonResponse
    {
        try {
            $adjustment = UserAdjustment::with([
                'user', 
                'creator', 
                'approver', 
                'processor', 
                'approvals.approver',
                'ledgerEntry'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $adjustment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Adjustment not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Approve pending adjustment
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'approval_note' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $adjustment = $this->adjustmentService->approveAdjustment($id, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Adjustment approved and processed successfully',
                'data' => $adjustment->load(['user', 'approver', 'latestApproval'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject pending adjustment
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'rejection_reason' => 'required|string|min:10|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $adjustment = $this->adjustmentService->rejectAdjustment($id, $request->rejection_reason);

            return response()->json([
                'success' => true,
                'message' => 'Adjustment rejected successfully',
                'data' => $adjustment->load(['user', 'approver', 'latestApproval'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject adjustment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== APPROVAL WORKFLOW ====================

    /**
     * Get pending adjustments requiring approval
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        try {
            $perPage = min($request->get('per_page', 15), 100);
            
            $adjustments = $this->adjustmentService->getPendingApprovals(['per_page' => $perPage]);

            return response()->json([
                'success' => true,
                'data' => $adjustments->items(),
                'meta' => [
                    'current_page' => $adjustments->currentPage(),
                    'per_page' => $adjustments->perPage(),
                    'total' => $adjustments->total(),
                    'last_page' => $adjustments->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending approvals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk approve multiple adjustments
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'adjustment_ids' => 'required|array|min:1|max:50',
                'adjustment_ids.*' => 'required|integer|exists:user_adjustments,id',
                'approval_note' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $results = ['successful' => [], 'failed' => []];
            
            foreach ($request->adjustment_ids as $id) {
                try {
                    $adjustment = $this->adjustmentService->approveAdjustment($id, [
                        'approval_note' => $request->approval_note
                    ]);
                    
                    $results['successful'][] = [
                        'id' => $id,
                        'adjustment_id' => $adjustment->adjustment_id
                    ];
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'id' => $id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Bulk approval completed',
                'data' => $results,
                'summary' => [
                    'total_requested' => count($request->adjustment_ids),
                    'successful_count' => count($results['successful']),
                    'failed_count' => count($results['failed'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process bulk approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== USER ADJUSTMENT HISTORY ====================

    /**
     * Get adjustment history for specific user
     */
    public function userHistory(int $userId, Request $request): JsonResponse
    {
        try {
            // Verify user exists
            User::findOrFail($userId);

            $filters = $request->only(['status', 'direction', 'reason_code', 'date_from', 'date_to']);
            $filters['per_page'] = min($request->get('per_page', 15), 100);

            $adjustments = $this->adjustmentService->getUserAdjustments($userId, $filters);

            return response()->json([
                'success' => true,
                'data' => $adjustments->items(),
                'meta' => [
                    'current_page' => $adjustments->currentPage(),
                    'per_page' => $adjustments->perPage(),
                    'total' => $adjustments->total(),
                    'last_page' => $adjustments->lastPage(),
                    'user_id' => $userId
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user adjustment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== STATISTICS & REPORTING ====================

    /**
     * Get adjustment statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $days = $request->get('days', 30);
            $stats = $this->adjustmentService->getStatistics(['days' => $days]);

            return response()->json([
                'success' => true,
                'data' => $stats,
                'period' => [
                    'days' => $days,
                    'from' => now()->subDays($days)->format('Y-m-d'),
                    'to' => now()->format('Y-m-d')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== CONFIGURATION & REFERENCE DATA ====================

    /**
     * Get adjustment reason codes
     */
    public function reasonCodes(Request $request): JsonResponse
    {
        try {
            $direction = $request->get('direction');
            
            if ($direction && !in_array($direction, ['credit', 'debit'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid direction. Must be credit or debit'
                ], 422);
            }

            $reasonCodes = $direction 
                ? AdjustmentReasonCode::optionsByDirection($direction)
                : AdjustmentReasonCode::options();

            // Get detailed information
            $detailedCodes = [];
            foreach (AdjustmentReasonCode::cases() as $case) {
                if (!$direction || in_array($direction, $case->allowedDirections())) {
                    $detailedCodes[] = [
                        'code' => $case->value,
                        'label' => $case->label(),
                        'description' => $case->description(),
                        'risk_level' => $case->riskLevel(),
                        'auto_approval_limit' => $case->defaultAutoApprovalLimit(),
                        'allowed_directions' => $case->allowedDirections(),
                        'requires_manager_approval' => $case->requiresManagerApproval(),
                        'requires_attachment' => $case->requiresAttachment(),
                        'documentation_requirements' => $case->documentationRequirements(),
                        'icon' => $case->icon(),
                        'color' => $case->color()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'options' => $reasonCodes,
                    'detailed' => $detailedCodes,
                    'grouped' => AdjustmentReasonCode::groupedOptions()
                ],
                'direction_filter' => $direction
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reason codes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get adjustment categories for dropdown
     */
    public function categories(Request $request): JsonResponse
    {
        try {
            $direction = $request->get('direction');
            
            $categories = AdjustmentCategory::getDropdownOptions($direction);

            return response()->json([
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve categories',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download adjustment attachment
     */
    public function downloadAttachment(int $id): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $adjustment = UserAdjustment::findOrFail($id);
            
            if (!$adjustment->attachment_path) {
                return response()->json([
                    'success' => false,
                    'message' => 'No attachment found for this adjustment'
                ], 404);
            }

            $filePath = storage_path('app/private/' . $adjustment->attachment_path);
            
            if (!file_exists($filePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attachment file not found'
                ], 404);
            }

            $fileName = 'adjustment_' . $adjustment->adjustment_id . '_attachment.' . pathinfo($filePath, PATHINFO_EXTENSION);
            
            return response()->download($filePath, $fileName);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download attachment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== VALIDATION HELPERS ====================

    /**
     * Validate adjustment creation before submission
     */
    public function validateBeforeSubmit(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|exists:users,id',
                'direction' => 'required|in:credit,debit',
                'amount' => 'required|numeric|min:0.01|max:10000000',
                'reason_code' => ['required', Rule::in(array_column(AdjustmentReasonCode::cases(), 'value'))]
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::find($request->user_id);
            $reasonCode = AdjustmentReasonCode::fromValue($request->reason_code);

            // Get current balance
            $currentBalance = $this->adjustmentService->getUserCurrentBalance($request->user_id);
            
            // Calculate new balance
            $newBalance = $request->direction === 'credit' 
                ? $currentBalance + $request->amount 
                : $currentBalance - $request->amount;

            // Check approval requirement
            $requiresApproval = UserAdjustment::requiresApproval($request->amount) || 
                              $reasonCode->requiresManagerApproval();

            return response()->json([
                'success' => true,
                'data' => [
                    'validation_passed' => true,
                    'current_balance' => $currentBalance,
                    'new_balance' => $newBalance,
                    'requires_approval' => $requiresApproval,
                    'approval_threshold' => UserAdjustment::getApprovalThreshold(),
                    'reason_info' => [
                        'risk_level' => $reasonCode->riskLevel(),
                        'requires_attachment' => $reasonCode->requiresAttachment(),
                        'requires_manager_approval' => $reasonCode->requiresManagerApproval(),
                        'auto_approval_limit' => $reasonCode->defaultAutoApprovalLimit()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}