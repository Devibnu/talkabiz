<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * OwnerAuditTrailController — Audit Trail & Immutable Ledger Viewer
 *
 * READ-ONLY CONTROLLER:
 * ─────────────────────
 * ❌ Tidak ada create / update / delete
 * ✅ Hanya GET (read, filter, search, export)
 * ✅ Semua data dari AuditLog model
 * ✅ Full filter: tanggal, actor, entity, action, status
 * ✅ Detail view: before/after diff
 */
class OwnerAuditTrailController extends Controller
{
    /**
     * Index — daftar audit logs dengan pagination & filter.
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()
            ->notArchived()
            ->orderByDesc('occurred_at');

        // ============ FILTERS ============

        // Date range
        if ($from = $request->get('from')) {
            $query->whereDate('occurred_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('occurred_at', '<=', $to);
        }

        // Actor type
        if ($actorType = $request->get('actor_type')) {
            $query->where('actor_type', $actorType);
        }

        // Actor ID
        if ($actorId = $request->get('actor_id')) {
            $query->where('actor_id', $actorId);
        }

        // Entity type
        if ($entityType = $request->get('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        // Entity ID
        if ($entityId = $request->get('entity_id')) {
            $query->where('entity_id', $entityId);
        }

        // Action
        if ($action = $request->get('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        // Action category
        if ($category = $request->get('category')) {
            $query->where('action_category', $category);
        }

        // Status
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // Search (action, description, entity_type, correlation_id)
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('entity_type', 'like', "%{$search}%")
                  ->orWhere('correlation_id', 'like', "%{$search}%")
                  ->orWhere('actor_email', 'like', "%{$search}%");
            });
        }

        $logs = $query->paginate(50)->withQueryString();

        // Stats
        $stats = $this->getStats();

        // Filter options
        $actorTypes    = $this->getDistinctValues('actor_type');
        $entityTypes   = $this->getDistinctValues('entity_type');
        $categories    = $this->getDistinctValues('action_category');
        $statuses      = ['success', 'failed', 'pending'];

        return view('owner.audit-trail.index', compact(
            'logs', 'stats', 'actorTypes', 'entityTypes', 'categories', 'statuses'
        ));
    }

    /**
     * Show detail — before/after diff, metadata, checksum.
     */
    public function show(int $id)
    {
        $log = AuditLog::findOrFail($id);

        // Get related logs by correlation
        $relatedLogs = collect();
        if ($log->correlation_id) {
            $relatedLogs = AuditLog::where('correlation_id', $log->correlation_id)
                ->where('id', '!=', $log->id)
                ->orderBy('occurred_at')
                ->limit(50)
                ->get();
        }

        // Integrity check
        $integrityValid = $log->verifyIntegrity();

        return view('owner.audit-trail.show', compact('log', 'relatedLogs', 'integrityValid'));
    }

    /**
     * Entity history — semua audit log untuk 1 entity.
     */
    public function entityHistory(string $entityType, int $entityId)
    {
        $logs = AuditLog::byEntity($entityType, $entityId)
            ->orderByDesc('occurred_at')
            ->paginate(50);

        return view('owner.audit-trail.entity-history', compact('logs', 'entityType', 'entityId'));
    }

    /**
     * Integrity check dashboard.
     */
    public function integrityCheck(Request $request)
    {
        $limit = min((int) ($request->get('limit', 100)), 500);

        $logs = AuditLog::query()
            ->whereNotNull('checksum')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $results = $logs->map(function ($log) {
            return [
                'id'          => $log->id,
                'log_uuid'    => $log->log_uuid,
                'action'      => $log->action,
                'entity'      => $log->entity_description,
                'occurred_at' => $log->occurred_at?->format('Y-m-d H:i:s'),
                'valid'       => $log->verifyIntegrity(),
            ];
        });

        $total     = $results->count();
        $valid     = $results->where('valid', true)->count();
        $invalid   = $results->where('valid', false)->count();
        $tamperedLogs = $results->where('valid', false)->values();

        return view('owner.audit-trail.integrity', compact(
            'results', 'total', 'valid', 'invalid', 'tamperedLogs', 'limit'
        ));
    }

    /**
     * Export audit logs as CSV (download).
     */
    public function exportCsv(Request $request)
    {
        $query = AuditLog::query()->notArchived()->orderByDesc('occurred_at');

        // Apply same filters as index
        if ($from = $request->get('from')) {
            $query->whereDate('occurred_at', '>=', $from);
        }
        if ($to = $request->get('to')) {
            $query->whereDate('occurred_at', '<=', $to);
        }
        if ($entityType = $request->get('entity_type')) {
            $query->where('entity_type', $entityType);
        }
        if ($category = $request->get('category')) {
            $query->where('action_category', $category);
        }

        $logs = $query->limit(5000)->get();

        $filename = 'audit_trail_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');

            // BOM for Excel UTF-8
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'ID', 'UUID', 'Occurred At', 'Actor Type', 'Actor ID', 'Actor Email',
                'Entity Type', 'Entity ID', 'Action', 'Category', 'Status',
                'Description', 'IP Address', 'Checksum Valid',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->log_uuid,
                    $log->occurred_at?->format('Y-m-d H:i:s'),
                    $log->actor_type,
                    $log->actor_id,
                    $log->actor_email,
                    $log->entity_type,
                    $log->entity_id,
                    $log->action,
                    $log->action_category,
                    $log->status,
                    $log->description,
                    $log->actor_ip,
                    $log->verifyIntegrity() ? 'VALID' : 'TAMPERED',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ============ PRIVATE HELPERS ============

    private function getStats(): array
    {
        return [
            'total'          => AuditLog::notArchived()->count(),
            'today'          => AuditLog::whereDate('occurred_at', today())->count(),
            'financial'      => AuditLog::where('action_category', AuditLog::CATEGORY_BILLING)->count(),
            'failed'         => AuditLog::where('status', 'failed')->count(),
            'reversals'      => AuditLog::where('action', 'reversal')->count(),
            'last_entry'     => AuditLog::orderByDesc('occurred_at')->value('occurred_at'),
        ];
    }

    private function getDistinctValues(string $column): array
    {
        return AuditLog::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->selectRaw("DISTINCT {$column}")
            ->orderBy($column)
            ->pluck($column)
            ->toArray();
    }
}
