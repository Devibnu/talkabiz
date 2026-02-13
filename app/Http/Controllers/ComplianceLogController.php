<?php

namespace App\Http\Controllers;

use App\Models\ComplianceLog;
use App\Models\Klien;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ComplianceLogController - READ-ONLY Owner Panel
 * 
 * No create, update, or delete operations.
 * All data is append-only, managed by ComplianceLogger service.
 */
class ComplianceLogController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('role:owner,admin');
    }

    /**
     * Read-only compliance log dashboard with filters
     */
    public function index(Request $request)
    {
        $query = ComplianceLog::query()
            ->orderByDesc('occurred_at');

        // --- Filters ---

        if ($request->filled('module')) {
            $query->byModule($request->module);
        }

        if ($request->filled('action')) {
            $query->byAction($request->action);
        }

        if ($request->filled('severity')) {
            $query->bySeverity($request->severity);
        }

        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->actor_type);
        }

        if ($request->filled('klien_id')) {
            $query->forKlien($request->klien_id);
        }

        if ($request->filled('is_financial')) {
            $query->where('is_financial', $request->is_financial === '1');
        }

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->outcome);
        }

        if ($request->filled('date_from')) {
            $query->where('occurred_at', '>=', $request->date_from . ' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('occurred_at', '<=', $request->date_to . ' 23:59:59');
        }

        if ($request->filled('correlation_id')) {
            $query->byCorrelation($request->correlation_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('description', 'LIKE', "%{$s}%")
                  ->orWhere('actor_name', 'LIKE', "%{$s}%")
                  ->orWhere('actor_email', 'LIKE', "%{$s}%")
                  ->orWhere('target_label', 'LIKE', "%{$s}%")
                  ->orWhere('log_ulid', 'LIKE', "%{$s}%")
                  ->orWhere('correlation_id', 'LIKE', "%{$s}%");
            });
        }

        $perPage = $request->get('per_page', 25);
        $logs = $query->paginate($perPage)->withQueryString();

        // Stats
        $stats = $this->buildStats($request);

        // Filter options
        $kliens = Klien::select('id', 'nama_perusahaan')->orderBy('nama_perusahaan')->get();

        $modules = ComplianceLog::select('module')
            ->distinct()->orderBy('module')->pluck('module');

        $actions = ComplianceLog::select('action')
            ->when($request->filled('module'), fn($q) => $q->where('module', $request->module))
            ->distinct()->orderBy('action')->pluck('action');

        return view('compliance-log.index', compact(
            'logs', 'stats', 'kliens', 'modules', 'actions'
        ));
    }

    /**
     * Show single log detail (AJAX / JSON)
     */
    public function show($id)
    {
        $log = ComplianceLog::with(['klien', 'actorUser'])->findOrFail($id);

        // Verify integrity inline
        $integrityOk = $log->verifyIntegrity();

        if (request()->wantsJson() || request()->ajax()) {
            return response()->json([
                'log' => $log,
                'integrity' => $integrityOk,
            ]);
        }

        return view('compliance-log.show', compact('log', 'integrityOk'));
    }

    /**
     * Export filtered logs to CSV (read-only)
     */
    public function export(Request $request)
    {
        $query = ComplianceLog::query()->orderByDesc('occurred_at');

        // Apply same filters
        if ($request->filled('module'))    $query->byModule($request->module);
        if ($request->filled('action'))    $query->byAction($request->action);
        if ($request->filled('severity'))  $query->bySeverity($request->severity);
        if ($request->filled('actor_type'))$query->where('actor_type', $request->actor_type);
        if ($request->filled('klien_id'))  $query->forKlien($request->klien_id);
        if ($request->filled('outcome'))   $query->where('outcome', $request->outcome);
        if ($request->filled('is_financial')) $query->where('is_financial', $request->is_financial === '1');
        if ($request->filled('date_from')) $query->where('occurred_at', '>=', $request->date_from . ' 00:00:00');
        if ($request->filled('date_to'))   $query->where('occurred_at', '<=', $request->date_to . ' 23:59:59');
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('description', 'LIKE', "%{$s}%")
                  ->orWhere('actor_name', 'LIKE', "%{$s}%")
                  ->orWhere('target_label', 'LIKE', "%{$s}%");
            });
        }

        $logs = $query->limit(10000)->get();
        $filename = 'compliance_logs_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($logs) {
            $f = fopen('php://output', 'w');

            fputcsv($f, [
                'ULID', 'Seq#', 'Module', 'Action', 'Severity', 'Outcome',
                'Actor Type', 'Actor Name', 'Actor Email', 'Actor Role', 'Actor IP',
                'Target Type', 'Target ID', 'Target Label',
                'Klien ID', 'Description',
                'Amount', 'Currency', 'Financial',
                'Legal Basis', 'Regulation',
                'Correlation ID', 'Record Hash',
                'Occurred At', 'Retention Until',
            ]);

            foreach ($logs as $log) {
                fputcsv($f, [
                    $log->log_ulid,
                    $log->sequence_number,
                    $log->module,
                    $log->action,
                    $log->severity,
                    $log->outcome,
                    $log->actor_type,
                    $log->actor_name,
                    $log->actor_email,
                    $log->actor_role,
                    $log->actor_ip,
                    $log->target_type,
                    $log->target_id,
                    $log->target_label,
                    $log->klien_id,
                    $log->description,
                    $log->amount,
                    $log->currency,
                    $log->is_financial ? 'Yes' : 'No',
                    $log->legal_basis,
                    $log->regulation_ref,
                    $log->correlation_id,
                    $log->record_hash,
                    $log->occurred_at?->format('Y-m-d H:i:s'),
                    $log->retention_until?->format('Y-m-d'),
                ]);
            }

            fclose($f);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Verify hash chain integrity (read-only check)
     */
    public function verifyIntegrity(Request $request)
    {
        $limit = min((int) $request->get('limit', 100), 500);
        $fromId = $request->get('from_id');

        $result = ComplianceLog::verifyChain($fromId ? (int)$fromId : null, $limit);

        return response()->json($result);
    }

    /**
     * Build dashboard stats
     */
    protected function buildStats(Request $request): array
    {
        $base = ComplianceLog::query();

        if ($request->filled('date_from')) {
            $base->where('occurred_at', '>=', $request->date_from . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $base->where('occurred_at', '<=', $request->date_to . ' 23:59:59');
        }

        return [
            'total' => (clone $base)->count(),
            'today' => (clone $base)->whereDate('occurred_at', today())->count(),
            'critical' => (clone $base)->whereIn('severity', ['critical', 'legal'])->count(),
            'financial' => (clone $base)->where('is_financial', true)->count(),

            'by_module' => (clone $base)
                ->select('module', DB::raw('COUNT(*) as cnt'))
                ->groupBy('module')
                ->pluck('cnt', 'module')
                ->toArray(),

            'by_severity' => (clone $base)
                ->select('severity', DB::raw('COUNT(*) as cnt'))
                ->groupBy('severity')
                ->pluck('cnt', 'severity')
                ->toArray(),
        ];
    }
}
