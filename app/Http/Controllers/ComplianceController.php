<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\RetentionService;
use App\Models\AuditLog;
use App\Models\AdminActionLog;
use App\Models\AccessLog;
use App\Models\ConfigChangeLog;
use App\Models\LegalArchive;
use App\Models\RetentionPolicy;
use App\Jobs\ArchiveLogsJob;
use App\Jobs\PurgeExpiredLogsJob;
use App\Jobs\IntegrityCheckJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * ComplianceController - API for Compliance & Audit Logs
 * 
 * Purpose:
 * - View audit logs (with access logging)
 * - Manage retention policies
 * - Retrieve archived data
 * - Trigger archive/purge operations
 * - Run integrity checks
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class ComplianceController extends Controller
{
    protected AuditLogService $auditService;
    protected RetentionService $retentionService;
    
    public function __construct(
        AuditLogService $auditService,
        RetentionService $retentionService
    ) {
        $this->auditService = $auditService;
        $this->retentionService = $retentionService;
    }
    
    // ==================== AUDIT LOGS ====================
    
    /**
     * List audit logs with filters
     */
    public function listAuditLogs(Request $request): JsonResponse
    {
        $request->validate([
            'klien_id' => 'nullable|integer',
            'actor_type' => 'nullable|string|in:user,admin,system,webhook,cron,api',
            'entity_type' => 'nullable|string|max:50',
            'action' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:30',
            'correlation_id' => 'nullable|string|max:100',
            'status' => 'nullable|string|in:success,failed,pending',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        
        $query = AuditLog::query()->notArchived();
        
        // Apply filters
        if ($request->filled('klien_id')) {
            $query->byKlien($request->input('klien_id'));
        }
        
        if ($request->filled('actor_type')) {
            $query->byActor($request->input('actor_type'));
        }
        
        if ($request->filled('entity_type')) {
            $query->byEntity($request->input('entity_type'));
        }
        
        if ($request->filled('category')) {
            $query->byCategory($request->input('category'));
        }
        
        if ($request->filled('correlation_id')) {
            $query->byCorrelation($request->input('correlation_id'));
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->inDateRange($request->input('date_from'), $request->input('date_to'));
        }
        
        $logs = $query->orderBy('occurred_at', 'desc')
                      ->paginate($request->input('per_page', 50));
        
        // Log this access
        $this->auditService->logBulkAccess(
            'audit_logs',
            $logs->total(),
            'Audit log search',
            ['classification' => AccessLog::CLASS_CONFIDENTIAL]
        );
        
        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
    
    /**
     * Get audit log detail
     */
    public function getAuditLog(int $id): JsonResponse
    {
        $log = AuditLog::findOrFail($id);
        
        // Log access
        $this->auditService->logAccess(
            'audit_logs',
            $id,
            AccessLog::ACCESS_VIEW,
            ['classification' => AccessLog::CLASS_CONFIDENTIAL]
        );
        
        // Get related logs
        $relatedLogs = $log->getRelatedLogs()->limit(20)->get();
        
        return response()->json([
            'success' => true,
            'data' => [
                'log' => $log,
                'integrity_valid' => $log->verifyIntegrity(),
                'related_logs' => $relatedLogs,
            ],
        ]);
    }
    
    /**
     * Get logs by correlation ID
     */
    public function getByCorrelation(string $correlationId): JsonResponse
    {
        $logs = AuditLog::byCorrelation($correlationId)
                        ->orderBy('occurred_at', 'asc')
                        ->get();
        
        // Log access
        $this->auditService->logBulkAccess(
            'audit_logs',
            $logs->count(),
            'Correlation trace',
            ['classification' => AccessLog::CLASS_CONFIDENTIAL]
        );
        
        return response()->json([
            'success' => true,
            'correlation_id' => $correlationId,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }
    
    // ==================== ADMIN ACTION LOGS ====================
    
    /**
     * List admin action logs
     */
    public function listAdminActions(Request $request): JsonResponse
    {
        $request->validate([
            'admin_id' => 'nullable|integer',
            'action' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:30',
            'target_klien_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        
        $query = AdminActionLog::query();
        
        if ($request->filled('admin_id')) {
            $query->byAdmin($request->input('admin_id'));
        }
        
        if ($request->filled('action')) {
            $query->byAction($request->input('action'));
        }
        
        if ($request->filled('category')) {
            $query->byCategory($request->input('category'));
        }
        
        if ($request->filled('target_klien_id')) {
            $query->byTargetKlien($request->input('target_klien_id'));
        }
        
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->inDateRange($request->input('date_from'), $request->input('date_to'));
        }
        
        $logs = $query->orderBy('performed_at', 'desc')
                      ->paginate($request->input('per_page', 50));
        
        // Log access
        $this->auditService->logBulkAccess(
            'admin_action_logs',
            $logs->total(),
            'Admin action search',
            ['classification' => AccessLog::CLASS_RESTRICTED]
        );
        
        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
    
    /**
     * Get sensitive admin actions
     */
    public function getSensitiveActions(Request $request): JsonResponse
    {
        $logs = AdminActionLog::sensitive()
                              ->orderBy('performed_at', 'desc')
                              ->limit(100)
                              ->get();
        
        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }
    
    // ==================== ACCESS LOGS ====================
    
    /**
     * List access logs
     */
    public function listAccessLogs(Request $request): JsonResponse
    {
        $request->validate([
            'accessor_type' => 'nullable|string',
            'accessor_id' => 'nullable|integer',
            'resource_type' => 'nullable|string',
            'classification' => 'nullable|string|in:internal,confidential,restricted',
            'contains_pii' => 'nullable|boolean',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        
        $query = AccessLog::query();
        
        if ($request->filled('accessor_type')) {
            $query->byAccessor($request->input('accessor_type'), $request->input('accessor_id'));
        }
        
        if ($request->filled('resource_type')) {
            $query->byResource($request->input('resource_type'));
        }
        
        if ($request->filled('classification')) {
            $query->byClassification($request->input('classification'));
        }
        
        if ($request->boolean('contains_pii')) {
            $query->withPii();
        }
        
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->inDateRange($request->input('date_from'), $request->input('date_to'));
        }
        
        $logs = $query->orderBy('accessed_at', 'desc')
                      ->paginate($request->input('per_page', 50));
        
        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
    
    /**
     * Get PII access logs (audit trail)
     */
    public function getPiiAccessLogs(Request $request): JsonResponse
    {
        $logs = AccessLog::withPii()
                         ->orderBy('accessed_at', 'desc')
                         ->limit(200)
                         ->get();
        
        return response()->json([
            'success' => true,
            'count' => $logs->count(),
            'data' => $logs,
        ]);
    }
    
    // ==================== CONFIG CHANGE LOGS ====================
    
    /**
     * List config change logs
     */
    public function listConfigChanges(Request $request): JsonResponse
    {
        $request->validate([
            'config_group' => 'nullable|string|max:50',
            'klien_id' => 'nullable|integer',
            'impact_level' => 'nullable|string|in:low,medium,high,critical',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        
        $query = ConfigChangeLog::query();
        
        if ($request->filled('config_group')) {
            $query->byGroup($request->input('config_group'));
        }
        
        if ($request->filled('klien_id')) {
            $query->byKlien($request->input('klien_id'));
        } elseif ($request->boolean('system_only')) {
            $query->systemWide();
        }
        
        if ($request->filled('impact_level')) {
            $query->where('impact_level', $request->input('impact_level'));
        }
        
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->inDateRange($request->input('date_from'), $request->input('date_to'));
        }
        
        $logs = $query->orderBy('changed_at', 'desc')
                      ->paginate($request->input('per_page', 50));
        
        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }
    
    // ==================== LEGAL ARCHIVES ====================
    
    /**
     * List legal archives
     */
    public function listArchives(Request $request): JsonResponse
    {
        $request->validate([
            'source_table' => 'nullable|string',
            'category' => 'nullable|string',
            'klien_id' => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);
        
        $query = $this->retentionService->searchArchives($request->only([
            'source_table', 'category', 'klien_id', 'date_from', 'date_to'
        ]));
        
        $archives = $query->paginate($request->input('per_page', 50));
        
        // Log access
        $this->auditService->logBulkAccess(
            'legal_archives',
            $archives->total(),
            'Archive search',
            ['classification' => AccessLog::CLASS_CONFIDENTIAL]
        );
        
        return response()->json([
            'success' => true,
            'data' => $archives,
        ]);
    }
    
    /**
     * Retrieve archived data
     */
    public function retrieveArchive(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'purpose' => 'required|string|max:255',
        ]);
        
        $userId = auth()->id();
        $purpose = $request->input('purpose');
        
        $result = $this->retentionService->retrieveArchive($id, $userId, $purpose);
        
        return response()->json([
            'success' => true,
            'archive' => [
                'id' => $result['archive']->id,
                'archive_uuid' => $result['archive']->archive_uuid,
                'source_table' => $result['archive']->source_table,
                'original_date' => $result['archive']->original_date,
                'record_count' => $result['archive']->record_count,
                'integrity' => $result['integrity'],
            ],
            'data' => $result['data'],
        ]);
    }
    
    /**
     * Verify archive integrity
     */
    public function verifyArchiveIntegrity(int $id): JsonResponse
    {
        $archive = LegalArchive::findOrFail($id);
        $integrity = $archive->verifyIntegrity();
        
        return response()->json([
            'success' => true,
            'archive_id' => $id,
            'archive_uuid' => $archive->archive_uuid,
            'integrity' => $integrity,
        ]);
    }
    
    // ==================== RETENTION POLICIES ====================
    
    /**
     * List retention policies
     */
    public function listPolicies(): JsonResponse
    {
        $policies = RetentionPolicy::active()
                                   ->orderedByPriority()
                                   ->get();
        
        return response()->json([
            'success' => true,
            'data' => $policies,
        ]);
    }
    
    /**
     * Get policy detail
     */
    public function getPolicy(int $id): JsonResponse
    {
        $policy = RetentionPolicy::findOrFail($id);
        
        return response()->json([
            'success' => true,
            'data' => $policy,
            'retention_summary' => $policy->retention_summary,
            'archive_date' => $policy->getArchiveDate()->toDateString(),
            'deletion_date' => $policy->getDeletionDate()->toDateString(),
        ]);
    }
    
    /**
     * Update retention policy
     */
    public function updatePolicy(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'hot_retention_days' => 'nullable|integer|min:1|max:3650',
            'warm_retention_days' => 'nullable|integer|min:0|max:3650',
            'cold_retention_days' => 'nullable|integer|min:0|max:3650',
            'auto_archive' => 'nullable|boolean',
            'auto_delete' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'reason' => 'required|string|max:500',
        ]);
        
        $policy = RetentionPolicy::findOrFail($id);
        $oldValues = $policy->toArray();
        
        $policy->fill($request->only([
            'hot_retention_days',
            'warm_retention_days',
            'cold_retention_days',
            'auto_archive',
            'auto_delete',
            'is_active',
        ]));
        
        // Recalculate total
        if ($request->hasAny(['hot_retention_days', 'warm_retention_days', 'cold_retention_days'])) {
            $policy->total_retention_days = 
                $policy->hot_retention_days + 
                $policy->warm_retention_days + 
                $policy->cold_retention_days;
        }
        
        $policy->save();
        
        // Log config change
        $this->auditService->logConfigChange(
            'retention_policy',
            $policy->code,
            $oldValues,
            $policy->toArray(),
            [
                'reason' => $request->input('reason'),
                'impact' => ConfigChangeLog::IMPACT_HIGH,
            ]
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Retention policy updated',
            'data' => $policy->fresh(),
        ]);
    }
    
    // ==================== OPERATIONS ====================
    
    /**
     * Trigger archive operation
     */
    public function triggerArchive(Request $request): JsonResponse
    {
        $request->validate([
            'log_type' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:50',
        ]);
        
        $job = new ArchiveLogsJob(
            $request->input('log_type'),
            $request->input('category')
        );
        
        dispatch($job);
        
        // Log admin action
        $this->auditService->logAdminAction(
            'trigger_archive',
            AdminActionLog::CATEGORY_SYSTEM,
            'retention',
            null,
            [
                'params' => $request->only(['log_type', 'category']),
                'reason' => 'Manual archive trigger',
            ]
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Archive job dispatched',
            'log_type' => $request->input('log_type', 'all'),
        ]);
    }
    
    /**
     * Trigger purge operation
     */
    public function triggerPurge(Request $request): JsonResponse
    {
        $request->validate([
            'approval_days' => 'nullable|integer|min:1|max:30',
        ]);
        
        $job = new PurgeExpiredLogsJob(
            $request->input('approval_days', 7)
        );
        
        dispatch($job);
        
        // Log admin action
        $this->auditService->logAdminAction(
            'trigger_purge',
            AdminActionLog::CATEGORY_SYSTEM,
            'retention',
            null,
            [
                'params' => $request->only(['approval_days']),
                'reason' => 'Manual purge trigger',
            ]
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Purge job dispatched',
        ]);
    }
    
    /**
     * Trigger integrity check
     */
    public function triggerIntegrityCheck(Request $request): JsonResponse
    {
        $request->validate([
            'archive_limit' => 'nullable|integer|min:100|max:50000',
            'audit_log_limit' => 'nullable|integer|min:100|max:100000',
            'klien_id' => 'nullable|integer',
        ]);
        
        $job = new IntegrityCheckJob(
            $request->input('archive_limit', 5000),
            $request->input('audit_log_limit', 10000),
            $request->input('klien_id')
        );
        
        dispatch($job);
        
        return response()->json([
            'success' => true,
            'message' => 'Integrity check job dispatched',
        ]);
    }
    
    // ==================== STATISTICS ====================
    
    /**
     * Get compliance statistics
     */
    public function getStatistics(): JsonResponse
    {
        $stats = $this->retentionService->getStatistics();
        
        return response()->json([
            'success' => true,
            'data' => $stats,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
    
    /**
     * Get retention summary by klien
     */
    public function getKlienRetentionSummary(int $klienId): JsonResponse
    {
        $summary = [
            'klien_id' => $klienId,
            'audit_logs' => [
                'total' => AuditLog::byKlien($klienId)->count(),
                'not_archived' => AuditLog::byKlien($klienId)->notArchived()->count(),
                'by_category' => AuditLog::byKlien($klienId)
                                         ->notArchived()
                                         ->selectRaw('action_category, count(*) as count')
                                         ->groupBy('action_category')
                                         ->pluck('count', 'action_category')
                                         ->toArray(),
            ],
            'archives' => [
                'total' => LegalArchive::byKlien($klienId)->count(),
                'active' => LegalArchive::byKlien($klienId)->active()->count(),
                'total_size' => LegalArchive::byKlien($klienId)->active()->sum('archived_size'),
            ],
        ];
        
        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }
}
