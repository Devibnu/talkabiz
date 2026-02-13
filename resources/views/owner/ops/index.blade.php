@extends('layouts.owner')

@section('title', 'Daily Ops - H+' . $currentDay)

@section('content')
<div class="container-fluid py-4">
    {{-- Header Card --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-{{ $theme['color'] }} shadow-lg">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h2 class="text-white mb-1">
                                {{ $theme['icon'] }} H+{{ $currentDay }} — {{ $theme['name'] }}
                            </h2>
                            <p class="text-white opacity-8 mb-0">
                                {{ $theme['focus'] }}
                            </p>
                            <small class="text-white opacity-6">
                                Go-Live: {{ $goLiveDate->format('d M Y') }} | 
                                Hari ini: {{ now()->format('d M Y H:i') }}
                            </small>
                        </div>
                        <div class="col-lg-4 text-end">
                            <button class="btn btn-white btn-lg" id="runCheckBtn">
                                <i class="fas fa-play me-2"></i> Run Check
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Week Progress Timeline --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">📅 Week-1 Progress</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        @foreach($weeklyProgress as $day => $progress)
                            <div class="text-center px-2 mb-2" style="min-width: 100px;">
                                <div class="avatar avatar-sm 
                                    {{ $progress['is_today'] ? 'bg-gradient-' . ($day <= $currentDay ? self::DAY_THEMES[$day]['color'] : 'secondary') : '' }}
                                    {{ $progress['is_completed'] ? 'bg-gradient-success' : ($progress['is_past'] ? 'bg-gradient-warning' : 'bg-light') }}"
                                    style="width: 40px; height: 40px; line-height: 40px; margin: 0 auto;">
                                    @if($progress['is_completed'])
                                        <i class="fas fa-check text-white"></i>
                                    @elseif($progress['is_today'])
                                        <span class="text-white fw-bold">{{ $day }}</span>
                                    @else
                                        <span class="text-muted">{{ $day }}</span>
                                    @endif
                                </div>
                                <small class="d-block mt-1 {{ $progress['is_today'] ? 'fw-bold' : '' }}">
                                    H+{{ $day }}
                                </small>
                                <small class="d-block text-xs text-muted">
                                    {{ \Carbon\Carbon::parse($progress['date'])->format('d/m') }}
                                </small>
                                @if($progress['alerts_count'] > 0)
                                    <span class="badge bg-danger badge-sm">{{ $progress['alerts_count'] }} alerts</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Stats --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Messages Today</p>
                                <h5 class="font-weight-bolder mb-0">
                                    {{ number_format($todayStats['messages_sent']) }}
                                </h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                <i class="fab fa-whatsapp text-lg opacity-10" aria-hidden="true"></i>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Revenue Today</p>
                                <h5 class="font-weight-bolder mb-0">
                                    Rp {{ number_format($todayStats['revenue'], 0, ',', '.') }}
                                </h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                <i class="fas fa-coins text-lg opacity-10" aria-hidden="true"></i>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Delivery Rate</p>
                                <h5 class="font-weight-bolder mb-0">
                                    {{ $todayStats['delivery_rate'] }}%
                                </h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                <i class="fas fa-paper-plane text-lg opacity-10" aria-hidden="true"></i>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Clients</p>
                                <h5 class="font-weight-bolder mb-0">
                                    {{ $todayStats['active_clients'] }}
                                </h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                <i class="fas fa-users text-lg opacity-10" aria-hidden="true"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Active Alerts --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-0">⚠️ Active Alerts</h6>
                        <a href="{{ route('owner.ops.risk-events') }}" class="text-sm">View All</a>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    @if(count($activeAlerts) > 0)
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <tbody>
                                    @foreach($activeAlerts as $alert)
                                        <tr>
                                            <td class="ps-4">
                                                @php
                                                    $severityColors = [
                                                        'critical' => 'danger',
                                                        'high' => 'warning',
                                                        'medium' => 'info',
                                                        'low' => 'secondary'
                                                    ];
                                                @endphp
                                                <span class="badge bg-{{ $severityColors[$alert->severity] ?? 'secondary' }}">
                                                    {{ strtoupper($alert->severity) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-xs font-weight-bold">{{ $alert->event_type }}</span>
                                                <br>
                                                <span class="text-xs text-secondary">
                                                    {{ Str::limit($alert->description, 50) }}
                                                </span>
                                            </td>
                                            <td class="text-end pe-4">
                                                <small class="text-muted">
                                                    {{ \Carbon\Carbon::parse($alert->created_at)->diffForHumans() }}
                                                </small>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                            <p class="text-muted mb-0">No active alerts</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Action Items --}}
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <div class="d-flex justify-content-between">
                        <h6 class="mb-0">📋 Action Items</h6>
                        <a href="{{ route('owner.ops.action-items') }}" class="text-sm">View All</a>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    @if(count($actionItems) > 0)
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <tbody>
                                    @foreach($actionItems as $item)
                                        <tr>
                                            <td class="ps-4">
                                                @php
                                                    $priorityColors = [
                                                        'critical' => 'danger',
                                                        'high' => 'warning',
                                                        'medium' => 'primary',
                                                        'low' => 'secondary'
                                                    ];
                                                @endphp
                                                <span class="badge bg-{{ $priorityColors[$item->priority] ?? 'secondary' }}">
                                                    {{ strtoupper($item->priority) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-xs font-weight-bold">{{ $item->title }}</span>
                                                <br>
                                                <span class="text-xs text-secondary">{{ $item->category }}</span>
                                            </td>
                                            <td class="text-end pe-4">
                                                @if($item->due_date)
                                                    <small class="text-muted">
                                                        Due: {{ \Carbon\Carbon::parse($item->due_date)->format('d/m') }}
                                                    </small>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-check text-success fa-3x mb-3"></i>
                            <p class="text-muted mb-0">No pending action items</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Day-specific Checklist --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">{{ $theme['icon'] }} H+{{ $currentDay }} Checklist</h6>
                </div>
                <div class="card-body">
                    @php
                        $dayChecklists = [
                            1 => [
                                ['item' => 'Cek error log (laravel.log)', 'action' => 'Log 5 error terbanyak'],
                                ['item' => 'Cek queue worker berjalan', 'action' => 'Restart jika mati'],
                                ['item' => 'Cek scheduler aktif', 'action' => 'Cek crontab'],
                                ['item' => 'Cek webhook events', 'action' => 'Cek response time'],
                                ['item' => 'Cek payment gateway', 'action' => 'Test top-up kecil'],
                            ],
                            2 => [
                                ['item' => 'Cek Health Score per nomor', 'action' => 'Paksa COOLDOWN jika C/D'],
                                ['item' => 'Cek Warmup State', 'action' => 'Jangan bypass!'],
                                ['item' => 'Cek delivery rate', 'action' => 'Target >95%'],
                                ['item' => 'Cek template status', 'action' => 'Review rejected'],
                                ['item' => 'Edukasi client', 'action' => 'Banner/tooltip warmup'],
                            ],
                            3 => [
                                ['item' => 'Cek Revenue vs Cost', 'action' => 'Harus profit!'],
                                ['item' => 'Cek Margin', 'action' => 'Minimal 25%'],
                                ['item' => 'Cek top-up anomaly', 'action' => 'Review >10jt'],
                                ['item' => 'Cek negative balance', 'action' => 'Harus 0!'],
                                ['item' => 'Update Meta cost', 'action' => 'Jika ada perubahan'],
                            ],
                            4 => [
                                ['item' => 'Cek user funnel', 'action' => 'Login→Campaign→Send'],
                                ['item' => 'Cek UI errors', 'action' => 'Browser console'],
                                ['item' => 'Cek complaints', 'action' => 'Review feedback'],
                                ['item' => 'Tambah micro-copy', 'action' => 'Jika user bingung'],
                                ['item' => 'Perjelas banner', 'action' => 'Edukasi limit/warmup'],
                            ],
                            5 => [
                                ['item' => 'Cek spam activity', 'action' => 'High volume clients'],
                                ['item' => 'Cek burst messages', 'action' => 'Rate limit enforcement'],
                                ['item' => 'Cek suspicious IPs', 'action' => 'Block jika perlu'],
                                ['item' => 'Audit user akses', 'action' => 'Review permissions'],
                                ['item' => 'Suspend nomor berisiko', 'action' => 'Protect health score'],
                            ],
                            6 => [
                                ['item' => 'Review business metrics', 'action' => 'Revenue, Profit, Margin'],
                                ['item' => 'Review client health', 'action' => 'Risk distribution'],
                                ['item' => 'Prepare decision', 'action' => 'SCALE atau HOLD?'],
                                ['item' => 'Plan Week-2', 'action' => 'Roadmap next steps'],
                                ['item' => 'Team sync', 'action' => 'Alignment meeting'],
                            ],
                            7 => [
                                ['item' => 'Review semua metrics', 'action' => 'Full summary'],
                                ['item' => 'Identify blockers', 'action' => 'Must fix items'],
                                ['item' => 'FINAL DECISION', 'action' => 'SCALE atau HOLD'],
                                ['item' => 'Communicate decision', 'action' => 'To all stakeholders'],
                                ['item' => 'Document learnings', 'action' => 'Week-1 retrospective'],
                            ],
                        ];
                        $checklist = $dayChecklists[$currentDay] ?? [];
                    @endphp
                    
                    <div class="row">
                        @foreach($checklist as $index => $check)
                            <div class="col-md-6 mb-3">
                                <div class="d-flex align-items-start">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="check{{ $index }}">
                                    </div>
                                    <div class="ms-2">
                                        <label class="form-check-label fw-bold" for="check{{ $index }}">
                                            {{ $check['item'] }}
                                        </label>
                                        <br>
                                        <small class="text-muted">→ {{ $check['action'] }}</small>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Checks History --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">📜 Recent Daily Checks</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Day</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Alerts</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentChecks as $check)
                                    <tr>
                                        <td class="ps-4">
                                            <span class="badge bg-gradient-{{ $check->theme['color'] ?? 'secondary' }}">
                                                H+{{ $check->check_day }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-sm">{{ \Carbon\Carbon::parse($check->check_date)->format('d M Y') }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $check->status === 'completed' ? 'success' : 'warning' }}">
                                                {{ $check->status }}
                                            </span>
                                        </td>
                                        <td>
                                            @if($check->alerts_count > 0)
                                                <span class="badge bg-danger">{{ $check->alerts_count }} alerts</span>
                                            @else
                                                <span class="text-success">✓ Clear</span>
                                            @endif
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="{{ route('owner.ops.day-details', $check->check_day) }}" 
                                               class="btn btn-sm btn-outline-primary">
                                                View Details
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            No checks recorded yet. Run your first check!
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Quick Actions --}}
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">⚡ Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('owner.ops.week-summary') }}" class="btn btn-outline-primary w-100">
                                <i class="fas fa-chart-bar me-2"></i> Week Summary
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('owner.ops.action-items') }}" class="btn btn-outline-warning w-100">
                                <i class="fas fa-tasks me-2"></i> Action Items
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('owner.ops.risk-events') }}" class="btn btn-outline-danger w-100">
                                <i class="fas fa-exclamation-triangle me-2"></i> Risk Events
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="{{ route('owner.ops.decision') }}" class="btn btn-outline-dark w-100">
                                <i class="fas fa-gavel me-2"></i> Make Decision
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Check Result Modal --}}
<div class="modal fade" id="checkResultModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Daily Check Results</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="checkResultContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Running checks...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('js')
<script>
document.getElementById('runCheckBtn').addEventListener('click', function() {
    const modal = new bootstrap.Modal(document.getElementById('checkResultModal'));
    modal.show();

    // Reset content
    document.getElementById('checkResultContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3">Running H+{{ $currentDay }} checks...</p>
        </div>
    `;

    fetch('{{ route("owner.ops.run-check") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ day: {{ $currentDay }} })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.result) {
            renderCheckResult(data.result);
        } else {
            document.getElementById('checkResultContent').innerHTML = `
                <div class="alert alert-danger">Failed to run check</div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('checkResultContent').innerHTML = `
            <div class="alert alert-danger">Error: ${error.message}</div>
        `;
    });
});

function renderCheckResult(result) {
    let html = `
        <div class="mb-4">
            <h5>${result.theme?.icon || '📋'} ${result.theme?.name || 'Daily Check'}</h5>
            <p class="text-muted">${result.theme?.focus || ''}</p>
        </div>
    `;

    // Alerts
    if (result.alerts && result.alerts.length > 0) {
        html += `<div class="alert alert-danger"><strong>⚠️ ${result.alerts.length} Alert(s) Found</strong></div>`;
        html += '<ul class="list-group mb-4">';
        result.alerts.forEach(alert => {
            html += `<li class="list-group-item list-group-item-danger">${alert.type}: ${JSON.stringify(alert)}</li>`;
        });
        html += '</ul>';
    } else {
        html += `<div class="alert alert-success">✅ No critical alerts</div>`;
    }

    // Actions
    if (result.actions && result.actions.length > 0) {
        html += '<h6>📋 Recommended Actions:</h6>';
        html += '<ol class="mb-4">';
        result.actions.forEach(action => {
            html += `<li>${action}</li>`;
        });
        html += '</ol>';
    }

    // Results summary
    if (result.results) {
        html += '<h6>📊 Check Results:</h6>';
        html += `<pre class="bg-light p-3 rounded" style="max-height: 300px; overflow-y: auto;">${JSON.stringify(result.results, null, 2)}</pre>`;
    }

    document.getElementById('checkResultContent').innerHTML = html;
}
</script>
@endpush
