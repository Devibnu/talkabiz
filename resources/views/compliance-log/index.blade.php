@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Compliance Log'])

    <div class="container-fluid py-4">
        {{-- Stats Cards --}}
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Records</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($stats['total']) }}
                                        @if($stats['today'] > 0)
                                            <span class="text-success text-sm font-weight-bolder">+{{ $stats['today'] }} today</span>
                                        @endif
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                    <i class="ni ni-archive-2 text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Critical / Legal</p>
                                    <h5 class="font-weight-bolder mb-0">{{ number_format($stats['critical']) }}</h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                    <i class="ni ni-bell-55 text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Financial Logs</p>
                                    <h5 class="font-weight-bolder mb-0">{{ number_format($stats['financial']) }}</h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                    <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Modules</p>
                                    <h5 class="font-weight-bolder mb-0">{{ count($stats['by_module']) }}</h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                    <i class="ni ni-app text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Module breakdown mini-badges --}}
        @if(!empty($stats['by_module']))
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body p-3">
                        <span class="text-sm text-secondary me-2">By Module:</span>
                        @foreach($stats['by_module'] as $mod => $cnt)
                            <a href="{{ route('compliance-log.index', ['module' => $mod]) }}"
                               class="badge bg-gradient-{{ $mod === (request('module')) ? 'primary' : 'secondary' }} me-1">
                                {{ strtoupper($mod) }} <span class="ms-1">{{ number_format($cnt) }}</span>
                            </a>
                        @endforeach
                        <span class="text-sm text-secondary ms-3 me-2">Severity:</span>
                        @foreach($stats['by_severity'] as $sev => $cnt)
                            <a href="{{ route('compliance-log.index', ['severity' => $sev]) }}"
                               class="badge {{ $sev === 'critical' ? 'bg-gradient-danger' : ($sev === 'legal' ? 'bg-gradient-dark' : ($sev === 'warning' ? 'bg-gradient-warning' : 'bg-gradient-info')) }} me-1">
                                {{ ucfirst($sev) }} <span class="ms-1">{{ number_format($cnt) }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Main Card --}}
        <div class="card">
            <div class="card-header pb-0">
                <div class="row">
                    <div class="col-lg-6 col-7">
                        <h6>Compliance & Legal Log</h6>
                        <p class="text-sm mb-0">
                            <i class="ni ni-lock-circle-open text-info"></i>
                            Read-only, append-only, hash-chained audit trail
                        </p>
                    </div>
                    <div class="col-lg-6 col-5 text-end">
                        <button class="btn btn-icon btn-sm btn-primary" onclick="toggleFilters()">
                            <span class="btn-inner--icon"><i class="ni ni-settings-gear-65"></i></span>
                            <span class="btn-inner--text">Filters</span>
                        </button>
                        <a href="{{ route('compliance-log.export', request()->query()) }}" class="btn btn-icon btn-sm btn-success">
                            <span class="btn-inner--icon"><i class="ni ni-cloud-download-95"></i></span>
                            <span class="btn-inner--text">Export CSV</span>
                        </a>
                        <button class="btn btn-icon btn-sm btn-dark" onclick="verifyChain()">
                            <span class="btn-inner--icon"><i class="ni ni-check-bold"></i></span>
                            <span class="btn-inner--text">Verify Chain</span>
                        </button>
                    </div>
                </div>

                {{-- Filter Panel --}}
                <div id="filterPanel" class="row mt-3" style="display: none;">
                    <div class="col-12">
                        <form method="GET" action="{{ route('compliance-log.index') }}">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="row">
                                        {{-- Search --}}
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Search</label>
                                            <input type="text" name="search" class="form-control form-control-sm"
                                                   placeholder="Description, actor, target, ULID..."
                                                   value="{{ request('search') }}">
                                        </div>
                                        {{-- Module --}}
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Module</label>
                                            <select name="module" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                @foreach($modules as $mod)
                                                    <option value="{{ $mod }}" {{ request('module') == $mod ? 'selected' : '' }}>{{ strtoupper($mod) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        {{-- Action --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Action</label>
                                            <select name="action" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                @foreach($actions as $act)
                                                    <option value="{{ $act }}" {{ request('action') == $act ? 'selected' : '' }}>{{ $act }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        {{-- Severity --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Severity</label>
                                            <select name="severity" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                <option value="info" {{ request('severity') == 'info' ? 'selected' : '' }}>Info</option>
                                                <option value="warning" {{ request('severity') == 'warning' ? 'selected' : '' }}>Warning</option>
                                                <option value="critical" {{ request('severity') == 'critical' ? 'selected' : '' }}>Critical</option>
                                                <option value="legal" {{ request('severity') == 'legal' ? 'selected' : '' }}>Legal</option>
                                            </select>
                                        </div>
                                        {{-- Actor Type --}}
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Actor Type</label>
                                            <select name="actor_type" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                <option value="user" {{ request('actor_type') == 'user' ? 'selected' : '' }}>User</option>
                                                <option value="admin" {{ request('actor_type') == 'admin' ? 'selected' : '' }}>Admin</option>
                                                <option value="system" {{ request('actor_type') == 'system' ? 'selected' : '' }}>System</option>
                                                <option value="webhook" {{ request('actor_type') == 'webhook' ? 'selected' : '' }}>Webhook</option>
                                                <option value="cron" {{ request('actor_type') == 'cron' ? 'selected' : '' }}>Cron</option>
                                            </select>
                                        </div>
                                        {{-- Klien --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Klien</label>
                                            <select name="klien_id" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                @foreach($kliens as $kl)
                                                    <option value="{{ $kl->id }}" {{ request('klien_id') == $kl->id ? 'selected' : '' }}>{{ $kl->nama_perusahaan }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        {{-- Outcome --}}
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Outcome</label>
                                            <select name="outcome" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                <option value="success" {{ request('outcome') == 'success' ? 'selected' : '' }}>Success</option>
                                                <option value="failure" {{ request('outcome') == 'failure' ? 'selected' : '' }}>Failure</option>
                                                <option value="partial" {{ request('outcome') == 'partial' ? 'selected' : '' }}>Partial</option>
                                            </select>
                                        </div>
                                        {{-- Financial --}}
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Financial</label>
                                            <select name="is_financial" class="form-select form-select-sm">
                                                <option value="">All</option>
                                                <option value="1" {{ request('is_financial') === '1' ? 'selected' : '' }}>Yes</option>
                                                <option value="0" {{ request('is_financial') === '0' ? 'selected' : '' }}>No</option>
                                            </select>
                                        </div>
                                        {{-- Date From --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Date From</label>
                                            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
                                        </div>
                                        {{-- Date To --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Date To</label>
                                            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
                                        </div>
                                        {{-- Correlation ID --}}
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Correlation ID</label>
                                            <input type="text" name="correlation_id" class="form-control form-control-sm"
                                                   placeholder="cpl_..." value="{{ request('correlation_id') }}">
                                        </div>
                                        {{-- Per Page --}}
                                        <div class="col-md-2 mb-3">
                                            <label class="form-label">Per Page</label>
                                            <select name="per_page" class="form-select form-select-sm">
                                                @foreach([10, 25, 50, 100] as $pp)
                                                    <option value="{{ $pp }}" {{ request('per_page', 25) == $pp ? 'selected' : '' }}>{{ $pp }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        {{-- Buttons --}}
                                        <div class="col-md-4 mb-3 d-flex align-items-end gap-2">
                                            <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                                            <a href="{{ route('compliance-log.index') }}" class="btn btn-sm btn-secondary">Clear</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Table --}}
            <div class="card-body px-0 pt-0 pb-2">
                @if($logs->isEmpty())
                    <div class="text-center py-5">
                        <i class="ni ni-archive-2 text-muted" style="font-size: 3rem;"></i>
                        <h6 class="mt-3">No compliance logs found</h6>
                        <p class="text-sm text-muted">
                            @if(request()->hasAny(['search','module','severity','actor_type','klien_id','date_from','date_to']))
                                Adjust your filters and try again.
                            @else
                                Compliance logs will appear here once critical operations are performed.
                            @endif
                        </p>
                    </div>
                @else
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Seq</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Occurred</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Module</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Severity</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actor</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Target</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Description</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($logs as $log)
                                <tr>
                                    <td>
                                        <p class="text-xs text-secondary mb-0">#{{ $log->sequence_number }}</p>
                                    </td>
                                    <td>
                                        <p class="text-xs font-weight-bold mb-0">{{ $log->occurred_at->format('Y-m-d') }}</p>
                                        <p class="text-xs text-secondary mb-0">{{ $log->occurred_at->format('H:i:s') }}</p>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-primary">{{ strtoupper($log->module) }}</span>
                                    </td>
                                    <td>
                                        <p class="text-xs font-weight-bold mb-0">{{ $log->action }}</p>
                                        <p class="text-xs mb-0">
                                            <span class="badge badge-xs bg-gradient-{{ $log->outcome === 'success' ? 'success' : ($log->outcome === 'failure' ? 'danger' : 'warning') }}">
                                                {{ $log->outcome }}
                                            </span>
                                        </p>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm {{ $log->getSeverityBadgeClass() }}">{{ $log->getSeverityLabel() }}</span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <h6 class="mb-0 text-sm">{{ $log->actor_name ?? 'System' }}</h6>
                                            <p class="text-xs text-secondary mb-0">{{ $log->actor_type }}{{ $log->actor_role ? ' / ' . $log->actor_role : '' }}</p>
                                        </div>
                                    </td>
                                    <td>
                                        @if($log->target_label)
                                            <p class="text-xs font-weight-bold mb-0">{{ Str::limit($log->target_label, 25) }}</p>
                                            <p class="text-xs text-secondary mb-0">{{ $log->target_type }}#{{ $log->target_id }}</p>
                                        @else
                                            <span class="text-xs text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <p class="text-xs mb-0" title="{{ $log->description }}">{{ Str::limit($log->description, 50) }}</p>
                                        @if($log->is_financial)
                                            <span class="badge badge-xs bg-gradient-success">Financial</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($log->amount)
                                            <p class="text-xs font-weight-bold mb-0">Rp {{ number_format($log->amount, 0, ',', '.') }}</p>
                                        @else
                                            <span class="text-xs text-secondary">—</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        <button type="button" class="btn btn-link text-secondary px-2 mb-0" onclick="showDetail({{ $log->id }})" title="View JSON Detail">
                                            <i class="ni ni-bold-right text-lg"></i>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Detail Modal --}}
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Compliance Log Detail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Chain Integrity Modal --}}
    <div class="modal fade" id="chainModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hash Chain Integrity Check</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="chainContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('layouts.footers.auth.footer')
@endsection

@push('js')
<script>
    function toggleFilters() {
        const p = document.getElementById('filterPanel');
        p.style.display = p.style.display === 'none' ? 'block' : 'none';
    }

    @if(request()->hasAny(['search','module','action','severity','actor_type','klien_id','outcome','is_financial','date_from','date_to','correlation_id']))
        document.addEventListener('DOMContentLoaded', () => document.getElementById('filterPanel').style.display = 'block');
    @endif

    function showDetail(id) {
        const modal = new bootstrap.Modal(document.getElementById('detailModal'));
        document.getElementById('detailContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';
        modal.show();

        fetch(`/owner/compliance-log/${id}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            const log = data.log;
            const ok = data.integrity;
            document.getElementById('detailContent').innerHTML = renderDetail(log, ok);
        })
        .catch(() => {
            document.getElementById('detailContent').innerHTML = '<div class="alert alert-danger">Failed to load detail</div>';
        });
    }

    function renderDetail(log, integrityOk) {
        const badge = sev => ({
            info: 'bg-gradient-info', warning: 'bg-gradient-warning',
            critical: 'bg-gradient-danger', legal: 'bg-gradient-dark'
        }[sev] || 'bg-gradient-secondary');

        const jsonBlock = (label, data) => {
            if (!data || Object.keys(data).length === 0) return '';
            return `<div class="mb-3"><h6 class="text-sm mb-1">${label}</h6><pre class="bg-light p-3 rounded text-xs" style="max-height:300px;overflow:auto">${JSON.stringify(data, null, 2)}</pre></div>`;
        };

        return `
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-2">Record Info</h6>
                    <table class="table table-sm table-borderless">
                        <tr><td class="text-sm text-secondary" style="width:40%">ULID</td><td class="text-xs font-family-monospace">${log.log_ulid}</td></tr>
                        <tr><td class="text-sm text-secondary">Sequence</td><td class="text-sm">#${log.sequence_number}</td></tr>
                        <tr><td class="text-sm text-secondary">Module</td><td><span class="badge badge-sm bg-gradient-primary">${log.module.toUpperCase()}</span></td></tr>
                        <tr><td class="text-sm text-secondary">Action</td><td class="text-sm font-weight-bold">${log.action}</td></tr>
                        <tr><td class="text-sm text-secondary">Severity</td><td><span class="badge badge-sm ${badge(log.severity)}">${log.severity}</span></td></tr>
                        <tr><td class="text-sm text-secondary">Outcome</td><td><span class="badge badge-sm bg-gradient-${log.outcome==='success'?'success':'danger'}">${log.outcome}</span></td></tr>
                        <tr><td class="text-sm text-secondary">Occurred</td><td class="text-sm">${log.occurred_at}</td></tr>
                        <tr><td class="text-sm text-secondary">Retention</td><td class="text-sm">${log.retention_until ?? '—'}</td></tr>
                        ${log.amount ? `<tr><td class="text-sm text-secondary">Amount</td><td class="text-sm font-weight-bold">Rp ${Number(log.amount).toLocaleString('id-ID')}</td></tr>` : ''}
                        ${log.legal_basis ? `<tr><td class="text-sm text-secondary">Legal Basis</td><td class="text-sm">${log.legal_basis}</td></tr>` : ''}
                        ${log.regulation_ref ? `<tr><td class="text-sm text-secondary">Regulation</td><td class="text-sm">${log.regulation_ref}</td></tr>` : ''}
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-2">Actor & Target</h6>
                    <table class="table table-sm table-borderless">
                        <tr><td class="text-sm text-secondary" style="width:40%">Actor</td><td class="text-sm font-weight-bold">${log.actor_name ?? 'System'}</td></tr>
                        <tr><td class="text-sm text-secondary">Actor Type</td><td class="text-sm">${log.actor_type}</td></tr>
                        <tr><td class="text-sm text-secondary">Email</td><td class="text-sm">${log.actor_email ?? '—'}</td></tr>
                        <tr><td class="text-sm text-secondary">Role</td><td class="text-sm">${log.actor_role ?? '—'}</td></tr>
                        <tr><td class="text-sm text-secondary">IP</td><td class="text-xs font-family-monospace">${log.actor_ip ?? '—'}</td></tr>
                        <tr><td class="text-sm text-secondary">Target</td><td class="text-sm">${log.target_label ?? '—'} ${log.target_type ? `(${log.target_type}#${log.target_id})` : ''}</td></tr>
                        <tr><td class="text-sm text-secondary">Klien ID</td><td class="text-sm">${log.klien_id ?? '—'}</td></tr>
                        ${log.correlation_id ? `<tr><td class="text-sm text-secondary">Correlation</td><td class="text-xs font-family-monospace">${log.correlation_id}</td></tr>` : ''}
                        ${log.request_id ? `<tr><td class="text-sm text-secondary">Request ID</td><td class="text-xs font-family-monospace">${log.request_id}</td></tr>` : ''}
                    </table>
                </div>
            </div>
            <div class="mb-3">
                <h6 class="text-sm mb-1">Description</h6>
                <div class="bg-light p-3 rounded text-sm">${log.description}</div>
            </div>
            ${jsonBlock('Before State', log.before_state)}
            ${jsonBlock('After State', log.after_state)}
            ${jsonBlock('Context', log.context)}
            ${jsonBlock('Evidence', log.evidence)}
            <div class="row mt-3">
                <div class="col-12">
                    <h6 class="text-sm mb-1">Hash Chain</h6>
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td class="text-sm text-secondary" style="width:25%">Record Hash</td>
                            <td class="text-xs font-family-monospace">${log.record_hash}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-secondary">Previous Hash</td>
                            <td class="text-xs font-family-monospace">${log.previous_hash}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-secondary">Integrity</td>
                            <td>
                                <span class="badge badge-sm bg-gradient-${integrityOk ? 'success' : 'danger'}">
                                    ${integrityOk ? '✓ Valid' : '✗ Tampered!'}
                                </span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        `;
    }

    function verifyChain() {
        const modal = new bootstrap.Modal(document.getElementById('chainModal'));
        document.getElementById('chainContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="text-sm mt-2">Verifying last 200 records...</p></div>';
        modal.show();

        fetch('/owner/compliance-log/verify-integrity?limit=200', {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            let html = '';
            if (data.valid) {
                html = `
                    <div class="text-center">
                        <i class="ni ni-check-bold text-success" style="font-size:3rem"></i>
                        <h5 class="mt-3 text-success">Chain Integrity Verified</h5>
                        <p class="text-sm">All ${data.checked} records verified. No tampering detected.</p>
                    </div>`;
            } else {
                html = `
                    <div class="text-center mb-3">
                        <i class="ni ni-fat-remove text-danger" style="font-size:3rem"></i>
                        <h5 class="mt-3 text-danger">Chain Integrity FAILED</h5>
                        <p class="text-sm">${data.checked} records checked. ${data.errors.length} error(s) found.</p>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>Record ID</th><th>Type</th><th>Message</th></tr></thead>
                            <tbody>
                                ${data.errors.map(e => `<tr><td>#${e.id}</td><td><span class="badge badge-sm bg-gradient-danger">${e.type}</span></td><td class="text-xs">${e.message}</td></tr>`).join('')}
                            </tbody>
                        </table>
                    </div>`;
            }
            document.getElementById('chainContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('chainContent').innerHTML = '<div class="alert alert-danger">Failed to verify chain integrity</div>';
        });
    }
</script>
@endpush
