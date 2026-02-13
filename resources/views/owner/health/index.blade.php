@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Health Score Dashboard'])
    
    <div class="container-fluid py-4">
        <!-- Summary Cards -->
        <div class="row">
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Connections</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ $summary['total_connections'] }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                    <i class="fab fa-whatsapp text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Average Score</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($summary['average_score'], 1) }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                    <i class="fas fa-heartbeat text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Needs Attention</p>
                                    <h5 class="font-weight-bolder mb-0 {{ count($summary['needs_attention']) > 0 ? 'text-danger' : '' }}">
                                        {{ count($summary['needs_attention']) }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                    <i class="fas fa-exclamation-triangle text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Auto-Actions Active</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ $summary['auto_actions_active'] }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                    <i class="fas fa-robot text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6>Status Distribution</h6>
                    </div>
                    <div class="card-body pt-0 pb-2">
                        <div class="row text-center">
                            @foreach($summary['by_status'] as $status => $count)
                                <div class="col-md-3">
                                    <div class="border-radius-md py-3">
                                        <span class="badge {{ $statuses[$status]['badge_class'] }} mb-2" style="font-size: 1rem;">
                                            <i class="fas {{ $statuses[$status]['icon'] }} me-1"></i>
                                            {{ strtoupper($status) }}
                                        </span>
                                        <h3 class="mb-0">{{ $count }}</h3>
                                        <p class="text-xs text-secondary mb-0">connections</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">All Connections</h5>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="recalculateAll()">
                            <i class="fas fa-sync me-1"></i> Recalculate All
                        </button>
                        <select id="statusFilter" class="form-select form-select-sm d-inline-block ms-2" style="width: auto;" onchange="filterByStatus()">
                            <option value="">All Status</option>
                            @foreach($statuses as $key => $status)
                                <option value="{{ $key }}">{{ $status['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connections Table -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0" id="healthTable">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Connection</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Score</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Delivery Rate</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Failure Rate</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Trend</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                        <th class="text-secondary opacity-7"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($connections as $connection)
                                        <tr data-status="{{ $connection['status'] }}">
                                            <td>
                                                <div class="d-flex px-2 py-1">
                                                    <div class="d-flex flex-column justify-content-center">
                                                        <h6 class="mb-0 text-sm">{{ $connection['phone_number'] ?? 'N/A' }}</h6>
                                                        <p class="text-xs text-secondary mb-0">{{ $connection['klien_name'] ?? 'Unknown' }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2 text-sm font-weight-bold">{{ number_format($connection['score'], 1) }}</span>
                                                    <div class="progress" style="width: 80px; height: 6px;">
                                                        <div class="progress-bar" role="progressbar" 
                                                             style="width: {{ $connection['score'] }}%; background-color: {{ $connection['status_info']['color'] == 'success' ? '#2dce89' : ($connection['status_info']['color'] == 'info' ? '#11cdef' : ($connection['status_info']['color'] == 'warning' ? '#fb6340' : '#f5365c')) }};"
                                                             aria-valuenow="{{ $connection['score'] }}" aria-valuemin="0" aria-valuemax="100">
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="align-middle text-center text-sm">
                                                <span class="badge badge-sm {{ $connection['status_info']['badge_class'] }}">
                                                    <i class="fas {{ $connection['status_info']['icon'] }} me-1"></i>
                                                    {{ strtoupper($connection['status']) }}
                                                </span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm font-weight-bold {{ $connection['delivery_rate'] >= 90 ? 'text-success' : ($connection['delivery_rate'] >= 70 ? 'text-warning' : 'text-danger') }}">
                                                    {{ number_format($connection['delivery_rate'], 1) }}%
                                                </span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm font-weight-bold {{ $connection['failure_rate'] <= 5 ? 'text-success' : ($connection['failure_rate'] <= 15 ? 'text-warning' : 'text-danger') }}">
                                                    {{ number_format($connection['failure_rate'], 1) }}%
                                                </span>
                                            </td>
                                            <td class="align-middle text-center">
                                                @if($connection['trend'] === 'up')
                                                    <i class="fas fa-arrow-up text-success"></i>
                                                @elseif($connection['trend'] === 'down')
                                                    <i class="fas fa-arrow-down text-danger"></i>
                                                @else
                                                    <i class="fas fa-minus text-secondary"></i>
                                                @endif
                                            </td>
                                            <td class="align-middle text-center">
                                                @if($connection['auto_actions_active'])
                                                    <span class="badge bg-gradient-warning" title="Auto-actions are active">
                                                        <i class="fas fa-robot"></i>
                                                    </span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="align-middle">
                                                <div class="dropdown">
                                                    <button class="btn btn-link text-secondary mb-0" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <a class="dropdown-item" href="{{ route('owner.health.show', $connection['connection_id']) }}">
                                                                <i class="fas fa-eye me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="#" onclick="recalculateSingle({{ $connection['connection_id'] }}); return false;">
                                                                <i class="fas fa-sync me-2"></i> Recalculate
                                                            </a>
                                                        </li>
                                                        @if($connection['auto_actions_active'])
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <a class="dropdown-item text-warning" href="#" onclick="resetActions({{ $connection['connection_id'] }}); return false;">
                                                                    <i class="fas fa-undo me-2"></i> Reset Actions
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" href="#" onclick="forceReset({{ $connection['connection_id'] }}); return false;">
                                                                    <i class="fas fa-exclamation-triangle me-2"></i> Force Reset
                                                                </a>
                                                            </li>
                                                        @endif
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="text-center py-4">
                                                <p class="text-muted mb-0">No health scores calculated yet.</p>
                                                <button class="btn btn-primary btn-sm mt-2" onclick="recalculateAll()">
                                                    Calculate Now
                                                </button>
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

        <!-- Needs Attention Section -->
        @if(count($summary['needs_attention']) > 0)
            <div class="row">
                <div class="col-12">
                    <div class="card border-warning">
                        <div class="card-header bg-gradient-warning text-white">
                            <h6 class="text-white mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Connections Needing Attention
                            </h6>
                        </div>
                        <div class="card-body">
                            @foreach($summary['needs_attention'] as $item)
                                <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                                    <div>
                                        <h6 class="mb-0">{{ $item['phone_number'] ?? 'Unknown' }}</h6>
                                        <p class="text-xs text-secondary mb-0">Score: {{ number_format($item['score'], 1) }} | Delivery: {{ number_format($item['delivery_rate'], 1) }}%</p>
                                    </div>
                                    <div>
                                        <span class="badge {{ $item['status'] === 'critical' ? 'bg-gradient-danger' : 'bg-gradient-warning' }}">
                                            {{ strtoupper($item['status']) }}
                                        </span>
                                        <a href="{{ route('owner.health.show', $item['connection_id']) }}" class="btn btn-sm btn-outline-primary ms-2">
                                            View
                                        </a>
                                    </div>
                                </div>
                                @if(!empty($item['recommendations']))
                                    <div class="ps-3 pb-2">
                                        @foreach($item['recommendations'] as $rec)
                                            <small class="text-muted">
                                                <i class="fas fa-lightbulb text-warning me-1"></i>
                                                {{ $rec['message'] }}
                                            </small><br>
                                        @endforeach
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('js')
<script>
    function filterByStatus() {
        const status = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('#healthTable tbody tr');
        
        rows.forEach(row => {
            if (!status || row.dataset.status === status) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    async function recalculateAll() {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Recalculate All Health Scores?',
            text: 'Ini akan menghitung ulang health score untuk semua koneksi.',
            confirmText: '<i class="fas fa-calculator me-1"></i> Ya, Recalculate'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Dispatching job...');
        
        fetch('{{ route("owner.health.api.recalculate-all") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ async: true })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success('Recalculation job dispatched. Refresh in a few moments.');
            } else {
                OwnerPopup.error(data.message);
            }
        })
        .catch(error => {
            OwnerPopup.error(error.message);
        });
    }

    function recalculateSingle(connectionId) {
        OwnerPopup.loading('Recalculating...');
        
        fetch(`{{ url('owner/health/api') }}/${connectionId}/recalculate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ async: false })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success(`
                    <p><strong>Recalculated!</strong></p>
                    <p>Score: <strong>${data.data.score}</strong></p>
                    <p>Status: <strong>${data.data.status}</strong></p>
                `).then(() => location.reload());
            } else {
                OwnerPopup.error(data.message);
            }
        })
        .catch(error => {
            OwnerPopup.error(error.message);
        });
    }

    async function resetActions(connectionId) {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Reset Auto-Actions?',
            text: 'Auto-actions untuk koneksi ini akan direset.',
            confirmText: '<i class="fas fa-redo me-1"></i> Ya, Reset'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Resetting...');
        
        fetch(`{{ url('owner/health/api') }}/${connectionId}/reset-actions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success('Auto-actions reset successfully!').then(() => location.reload());
            } else {
                OwnerPopup.error(data.message);
            }
        })
        .catch(error => {
            OwnerPopup.error(error.message);
        });
    }

    async function forceReset(connectionId) {
        const confirmed = await OwnerPopup.confirmDanger({
            title: 'FORCE Reset?',
            text: `
                <p class="mb-2">Ini akan melakukan force reset pada semua auto-actions.</p>
                <div class="alert alert-danger border mb-0">
                    <small>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> Ini akan mengabaikan safety checks!
                    </small>
                </div>
            `,
            confirmText: '<i class="fas fa-exclamation-triangle me-1"></i> Ya, Force Reset'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Force resetting...');
        
        fetch(`{{ url('owner/health/api') }}/${connectionId}/force-reset`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success('Force reset complete! ' + (data.warning || '')).then(() => location.reload());
            } else {
                OwnerPopup.error(data.message);
            }
        })
        .catch(error => {
            OwnerPopup.error(error.message);
        });
    }
</script>
@endpush
