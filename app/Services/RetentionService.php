<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LegalArchive;
use App\Models\RetentionPolicy;
use App\Models\AdminActionLog;
use App\Models\AccessLog;
use App\Models\ConfigChangeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RetentionService - Archive & Purge Management
 * 
 * Purpose:
 * - Archive logs based on retention policies
 * - Compress & store in legal_archives
 * - Purge expired archives
 * - Maintain data integrity
 * 
 * Lifecycle:
 * 1. Hot Storage (main table) → for active queries
 * 2. Warm Storage (legal_archives) → compressed, queryable via metadata
 * 3. Cold Storage (external) → optional, for very old data
 * 4. Deletion → after total retention period
 * 
 * @author Compliance & Legal Engineering Specialist
 */
class RetentionService
{
    protected AuditLogService $auditService;
    
    // Batch sizes for processing
    protected int $archiveBatchSize = 500;
    protected int $purgeBatchSize = 100;
    
    public function __construct(AuditLogService $auditService)
    {
        $this->auditService = $auditService;
    }
    
    // ==================== ARCHIVE OPERATIONS ====================
    
    /**
     * Archive logs based on retention policy
     */
    public function archiveLogs(string $logType, ?string $category = null): array
    {
        $policy = RetentionPolicy::getForLogType($logType, $category);
        
        if (!$policy || !$policy->auto_archive) {
            return [
                'success' => false,
                'message' => "No active archive policy for {$logType}",
                'archived' => 0,
            ];
        }
        
        $cutoffDate = $policy->getArchiveDate();
        $result = [
            'success' => true,
            'log_type' => $logType,
            'policy' => $policy->code,
            'cutoff_date' => $cutoffDate->toDateString(),
            'archived' => 0,
            'errors' => [],
        ];
        
        try {
            $query = $this->getArchiveQuery($logType, $cutoffDate);
            $totalToArchive = $query->count();
            
            if ($totalToArchive === 0) {
                $result['message'] = 'No records to archive';
                return $result;
            }
            
            $result['total_to_archive'] = $totalToArchive;
            
            // Process in batches
            $processed = 0;
            while ($processed < $totalToArchive) {
                $batch = $this->getArchiveQuery($logType, $cutoffDate)
                              ->limit($this->archiveBatchSize)
                              ->get();
                
                if ($batch->isEmpty()) {
                    break;
                }
                
                foreach ($batch as $record) {
                    try {
                        $this->archiveRecord($record, $logType, $policy);
                        $result['archived']++;
                    } catch (\Exception $e) {
                        $result['errors'][] = [
                            'id' => $record->id,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
                
                $processed += $batch->count();
                
                // Prevent memory issues
                gc_collect_cycles();
            }
            
            // Log the archive operation
            $this->auditService->log('archive_logs', 'retention', null, [
                'actor_type' => AuditLog::ACTOR_SYSTEM,
                'category' => AuditLog::CATEGORY_CORE,
                'context' => $result,
            ]);
            
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
            Log::error("Archive failed for {$logType}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Archive single record
     */
    protected function archiveRecord($record, string $logType, RetentionPolicy $policy): LegalArchive
    {
        // Prepare data for archiving
        $originalData = $record->toArray();
        $originalDate = $this->getRecordDate($record);
        
        // Compress data
        $compressed = LegalArchive::compressData($originalData);
        
        // Create archive entry
        $archive = LegalArchive::create([
            'source_table' => $this->getTableName($logType),
            'source_id' => $record->id,
            'source_uuid' => $record->log_uuid ?? $record->uuid ?? null,
            'archive_category' => $this->getArchiveCategory($logType),
            'retention_policy' => $policy->code,
            'original_date' => $originalDate,
            'archived_date' => now()->toDateString(),
            'expires_at' => $policy->calculateExpirationDate($originalDate)->toDateString(),
            'archived_data' => $compressed['data'],
            'is_compressed' => $compressed['is_compressed'],
            'compression_type' => $compressed['compression_type'],
            'data_checksum' => $compressed['data_checksum'],
            'archive_checksum' => $compressed['archive_checksum'],
            'original_size' => $compressed['original_size'],
            'archived_size' => $compressed['archived_size'],
            'klien_id' => $record->klien_id ?? null,
            'record_count' => 1,
            'metadata' => $this->extractMetadata($record, $logType),
            'status' => LegalArchive::STATUS_ACTIVE,
        ]);
        
        // Mark original as archived (or delete depending on policy)
        $this->markRecordArchived($record, $logType);
        
        return $archive;
    }
    
    /**
     * Archive multiple records in batch (more efficient)
     */
    public function archiveBatch(array $records, string $logType, RetentionPolicy $policy): LegalArchive
    {
        if (empty($records)) {
            throw new \InvalidArgumentException('No records to archive');
        }
        
        $originalData = [];
        $earliestDate = null;
        $klienIds = [];
        $sourceIds = [];
        
        foreach ($records as $record) {
            $originalData[] = $record->toArray();
            $recordDate = $this->getRecordDate($record);
            
            if ($earliestDate === null || $recordDate < $earliestDate) {
                $earliestDate = $recordDate;
            }
            
            if ($record->klien_id) {
                $klienIds[] = $record->klien_id;
            }
            $sourceIds[] = $record->id;
        }
        
        // Compress batch
        $compressed = LegalArchive::compressData($originalData);
        
        // Create batch archive
        $archive = LegalArchive::create([
            'source_table' => $this->getTableName($logType),
            'source_id' => $sourceIds[0], // First ID
            'source_uuid' => 'batch_' . Str::uuid(),
            'archive_category' => $this->getArchiveCategory($logType),
            'retention_policy' => $policy->code,
            'original_date' => $earliestDate,
            'archived_date' => now()->toDateString(),
            'expires_at' => $policy->calculateExpirationDate($earliestDate)->toDateString(),
            'archived_data' => $compressed['data'],
            'is_compressed' => true,
            'compression_type' => 'gzip',
            'data_checksum' => $compressed['data_checksum'],
            'archive_checksum' => $compressed['archive_checksum'],
            'original_size' => $compressed['original_size'],
            'archived_size' => $compressed['archived_size'],
            'klien_id' => count(array_unique($klienIds)) === 1 ? $klienIds[0] : null,
            'record_count' => count($records),
            'metadata' => [
                'source_ids' => $sourceIds,
                'klien_ids' => array_unique($klienIds),
                'date_range' => [
                    'start' => $earliestDate->toDateString(),
                    'end' => $this->getRecordDate(end($records))->toDateString(),
                ],
            ],
            'status' => LegalArchive::STATUS_ACTIVE,
        ]);
        
        // Mark all as archived
        foreach ($records as $record) {
            $this->markRecordArchived($record, $logType);
        }
        
        return $archive;
    }
    
    // ==================== PURGE OPERATIONS ====================
    
    /**
     * Purge expired archives
     */
    public function purgeExpiredArchives(): array
    {
        $result = [
            'success' => true,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
        
        try {
            // Find expired archives
            $expired = LegalArchive::expired()->limit($this->purgeBatchSize * 10)->get();
            
            foreach ($expired as $archive) {
                try {
                    // Check if policy allows deletion
                    $policy = RetentionPolicy::where('code', $archive->retention_policy)->first();
                    
                    if (!$policy || !$policy->can_be_deleted) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    // Request deletion (soft)
                    $archive->requestDeletion(0, 'Auto-purge: retention period expired');
                    
                    // Actually delete if auto_delete is enabled
                    if ($policy->auto_delete) {
                        $archive->delete();
                        $result['deleted']++;
                    } else {
                        $result['skipped']++;
                    }
                    
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'archive_id' => $archive->id,
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            // Log purge operation
            $this->auditService->log('purge_archives', 'retention', null, [
                'actor_type' => AuditLog::ACTOR_SYSTEM,
                'category' => AuditLog::CATEGORY_CORE,
                'context' => $result,
            ]);
            
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
            Log::error("Purge failed: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Purge pending deletion archives (after approval period)
     */
    public function purgePendingDeletions(int $daysWait = 7): array
    {
        $result = [
            'success' => true,
            'purged' => 0,
            'errors' => [],
        ];
        
        $cutoff = now()->subDays($daysWait);
        
        $pending = LegalArchive::pendingDeletion()
                               ->where('deletion_requested_at', '<=', $cutoff)
                               ->limit($this->purgeBatchSize)
                               ->get();
        
        foreach ($pending as $archive) {
            try {
                $archive->status = LegalArchive::STATUS_DELETED;
                $archive->save();
                $archive->delete();
                $result['purged']++;
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'archive_id' => $archive->id,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $result;
    }
    
    // ==================== RETRIEVAL ====================
    
    /**
     * Retrieve archived data
     */
    public function retrieveArchive(int $archiveId, ?int $accessorId = null, ?string $purpose = null): array
    {
        $archive = LegalArchive::findOrFail($archiveId);
        
        // Verify integrity before returning
        $integrity = $archive->verifyIntegrity();
        
        if (!$integrity['data_valid']) {
            throw new \RuntimeException('Archive integrity check failed: ' . implode(', ', $integrity['errors']));
        }
        
        // Log the access
        $this->auditService->logAccess(
            'legal_archive',
            $archiveId,
            AccessLog::ACCESS_VIEW,
            [
                'accessor_id' => $accessorId,
                'purpose' => $purpose ?? 'Archive retrieval',
                'classification' => AccessLog::CLASS_CONFIDENTIAL,
            ]
        );
        
        return [
            'archive' => $archive,
            'data' => $archive->decompressData(),
            'integrity' => $integrity,
            'metadata' => $archive->metadata,
        ];
    }
    
    /**
     * Search archives by metadata
     */
    public function searchArchives(array $criteria): \Illuminate\Database\Eloquent\Builder
    {
        $query = LegalArchive::active();
        
        if (isset($criteria['source_table'])) {
            $query->bySourceTable($criteria['source_table']);
        }
        
        if (isset($criteria['category'])) {
            $query->byCategory($criteria['category']);
        }
        
        if (isset($criteria['klien_id'])) {
            $query->byKlien($criteria['klien_id']);
        }
        
        if (isset($criteria['date_from']) && isset($criteria['date_to'])) {
            $query->inDateRange($criteria['date_from'], $criteria['date_to']);
        }
        
        if (isset($criteria['source_uuid'])) {
            $query->bySourceUuid($criteria['source_uuid']);
        }
        
        return $query->orderBy('original_date', 'desc');
    }
    
    // ==================== INTEGRITY ====================
    
    /**
     * Verify integrity of all active archives
     */
    public function verifyAllIntegrity(int $limit = 1000): array
    {
        $result = [
            'checked' => 0,
            'valid' => 0,
            'invalid' => 0,
            'errors' => [],
        ];
        
        LegalArchive::active()
            ->limit($limit)
            ->chunk(100, function ($archives) use (&$result) {
                foreach ($archives as $archive) {
                    $result['checked']++;
                    
                    try {
                        $integrity = $archive->verifyIntegrity();
                        
                        if ($integrity['data_valid'] && $integrity['archive_valid']) {
                            $result['valid']++;
                        } else {
                            $result['invalid']++;
                            $result['errors'][] = [
                                'archive_id' => $archive->id,
                                'archive_uuid' => $archive->archive_uuid,
                                'issues' => $integrity['errors'],
                            ];
                        }
                    } catch (\Exception $e) {
                        $result['invalid']++;
                        $result['errors'][] = [
                            'archive_id' => $archive->id,
                            'exception' => $e->getMessage(),
                        ];
                    }
                }
            });
        
        // Log integrity check
        $this->auditService->log('integrity_check', 'retention', null, [
            'actor_type' => AuditLog::ACTOR_SYSTEM,
            'category' => AuditLog::CATEGORY_CORE,
            'context' => $result,
        ]);
        
        return $result;
    }
    
    /**
     * Verify audit log chain integrity
     */
    public function verifyAuditLogChain(int $klienId, int $limit = 1000): array
    {
        $result = [
            'checked' => 0,
            'valid' => 0,
            'invalid' => 0,
            'broken_chain' => [],
        ];
        
        $logs = AuditLog::byKlien($klienId)
                        ->notArchived()
                        ->orderBy('id', 'asc')
                        ->limit($limit)
                        ->get();
        
        foreach ($logs as $log) {
            $result['checked']++;
            
            if ($log->verifyIntegrity()) {
                $result['valid']++;
            } else {
                $result['invalid']++;
                $result['broken_chain'][] = [
                    'log_id' => $log->id,
                    'log_uuid' => $log->log_uuid,
                    'occurred_at' => $log->occurred_at->toIso8601String(),
                ];
            }
        }
        
        return $result;
    }
    
    // ==================== STATISTICS ====================
    
    /**
     * Get retention statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'audit_logs' => [
                'total' => AuditLog::count(),
                'not_archived' => AuditLog::notArchived()->count(),
                'archived' => AuditLog::where('is_archived', true)->count(),
                'by_category' => AuditLog::notArchived()
                                         ->selectRaw('action_category, count(*) as count')
                                         ->groupBy('action_category')
                                         ->pluck('count', 'action_category')
                                         ->toArray(),
            ],
            'legal_archives' => [
                'total' => LegalArchive::count(),
                'active' => LegalArchive::active()->count(),
                'pending_deletion' => LegalArchive::pendingDeletion()->count(),
                'expired' => LegalArchive::expired()->count(),
                'total_size_bytes' => LegalArchive::active()->sum('archived_size'),
                'total_original_size' => LegalArchive::active()->sum('original_size'),
                'by_category' => LegalArchive::active()
                                             ->selectRaw('archive_category, count(*) as count')
                                             ->groupBy('archive_category')
                                             ->pluck('count', 'archive_category')
                                             ->toArray(),
            ],
            'retention_policies' => [
                'active' => RetentionPolicy::active()->count(),
                'policies' => RetentionPolicy::active()
                                             ->orderedByPriority()
                                             ->get(['code', 'name', 'log_type', 'total_retention_days'])
                                             ->toArray(),
            ],
        ];
        
        // Calculate compression ratio
        if ($stats['legal_archives']['total_original_size'] > 0) {
            $stats['legal_archives']['compression_ratio'] = round(
                (1 - ($stats['legal_archives']['total_size_bytes'] / $stats['legal_archives']['total_original_size'])) * 100,
                2
            );
        }
        
        return $stats;
    }
    
    // ==================== HELPERS ====================
    
    /**
     * Get archive query for log type
     */
    protected function getArchiveQuery(string $logType, \Carbon\Carbon $cutoff): \Illuminate\Database\Eloquent\Builder
    {
        return match ($logType) {
            'audit_logs' => AuditLog::notArchived()
                                    ->where('occurred_at', '<=', $cutoff)
                                    ->orderBy('occurred_at', 'asc'),
            
            'admin_action_logs' => AdminActionLog::where('performed_at', '<=', $cutoff)
                                                  ->orderBy('performed_at', 'asc'),
            
            'access_logs' => AccessLog::where('accessed_at', '<=', $cutoff)
                                      ->orderBy('accessed_at', 'asc'),
            
            'config_change_logs' => ConfigChangeLog::where('changed_at', '<=', $cutoff)
                                                    ->orderBy('changed_at', 'asc'),
            
            default => throw new \InvalidArgumentException("Unknown log type: {$logType}"),
        };
    }
    
    /**
     * Get table name for log type
     */
    protected function getTableName(string $logType): string
    {
        return match ($logType) {
            'audit_logs' => 'audit_logs',
            'admin_action_logs' => 'admin_action_logs',
            'access_logs' => 'access_logs',
            'config_change_logs' => 'config_change_logs',
            default => $logType,
        };
    }
    
    /**
     * Get archive category for log type
     */
    protected function getArchiveCategory(string $logType): string
    {
        return match ($logType) {
            'audit_logs' => LegalArchive::CATEGORY_AUDIT,
            'admin_action_logs' => LegalArchive::CATEGORY_AUDIT,
            'access_logs' => LegalArchive::CATEGORY_SYSTEM,
            'config_change_logs' => LegalArchive::CATEGORY_SYSTEM,
            default => LegalArchive::CATEGORY_SYSTEM,
        };
    }
    
    /**
     * Get record date
     */
    protected function getRecordDate($record): \Carbon\Carbon
    {
        return $record->occurred_at 
            ?? $record->performed_at 
            ?? $record->accessed_at 
            ?? $record->changed_at 
            ?? $record->created_at;
    }
    
    /**
     * Mark record as archived
     */
    protected function markRecordArchived($record, string $logType): void
    {
        if ($logType === 'audit_logs' && method_exists($record, 'markAsArchived')) {
            $record->markAsArchived();
        } else {
            // For other tables, we might just delete after archiving
            // depending on policy configuration
        }
    }
    
    /**
     * Extract searchable metadata from record
     */
    protected function extractMetadata($record, string $logType): array
    {
        $metadata = [];
        
        // Common fields
        if (isset($record->actor_type)) $metadata['actor_type'] = $record->actor_type;
        if (isset($record->actor_id)) $metadata['actor_id'] = $record->actor_id;
        if (isset($record->entity_type)) $metadata['entity_type'] = $record->entity_type;
        if (isset($record->entity_id)) $metadata['entity_id'] = $record->entity_id;
        if (isset($record->action)) $metadata['action'] = $record->action;
        if (isset($record->action_category)) $metadata['action_category'] = $record->action_category;
        if (isset($record->correlation_id)) $metadata['correlation_id'] = $record->correlation_id;
        
        return $metadata;
    }
}
