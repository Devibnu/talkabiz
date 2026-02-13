@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Health Score Detail'])
    
    <div class="container-fluid py-4">
        <!-- Back Button -->
        <div class="row mb-4">
            <div class="col-12">
                <a href="{{ route('owner.health.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            </div>
        </div>

        @if(isset($health['error']))
            <div class="row">
                <div class="col-12">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        {{ $health['error'] }}
                        <button class="btn btn-primary btn-sm ms-3" onclick="recalculate()">Calculate Now</button>
                    </div>
                </div>
            </div>
        @else
            <!-- Header with Connection Info & Score -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h4 class="mb-1">{{ $connection->phone_number ?? 'N/A' }}</h4>
                                    <p class="text-sm text-muted mb-3">
                                        <i class="fas fa-user me-1"></i> {{ $connection->klien?->name ?? 'Unknown Client' }}
                                    </p>
                                    <div class="d-flex align-items-center mb-3">
                                        <span class="badge {{ $health['status_info']['badge_class'] }}" style="font-size: 1rem;">
                                            <i class="fas {{ $health['status_info']['icon'] }} me-1"></i>
                                            {{ strtoupper($health['status']) }}
                                        </span>
                                        <span class="ms-3 text-sm text-muted">
                                            @if($health['trend']['direction'] === 'up')
                                                <i class="fas fa-arrow-up text-success"></i> Improving
                                            @elseif($health['trend']['direction'] === 'down')
                                                <i class="fas fa-arrow-down text-danger"></i> Declining
                                            @else
                                                <i class="fas fa-minus text-secondary"></i> Stable
                                            @endif
                                        </span>
                                    </div>
                                    <p class="text-xs text-muted mb-0">
                                        <i class="fas fa-clock me-1"></i>
                                        Last calculated: {{ $health['last_calculated'] ? \Carbon\Carbon::parse($health['last_calculated'])->diffForHumans() : 'Never' }}
                                    </p>
                                </div>
                                <div class="col-md-6 text-center">
                                    <div class="position-relative d-inline-block">
                                        <svg viewBox="0 0 36 36" width="140" height="140">
                                            <path d="M18 2.0845
                                                a 15.9155 15.9155 0 0 1 0 31.831
                                                a 15.9155 15.9155 0 0 1 0 -31.831"
                                                fill="none"
                                                stroke="#eee"
                                                stroke-width="3"
                                            />
                                            <path d="M18 2.0845
                                                a 15.9155 15.9155 0 0 1 0 31.831
                                                a 15.9155 15.9155 0 0 1 0 -31.831"
                                                fill="none"
                                                stroke="{{ $health['status_info']['color'] == 'success' ? '#2dce89' : ($health['status_info']['color'] == 'info' ? '#11cdef' : ($health['status_info']['color'] == 'warning' ? '#fb6340' : '#f5365c')) }}"
                                                stroke-width="3"
                                                stroke-dasharray="{{ $health['score'] }}, 100"
                                                stroke-linecap="round"
                                            />
                                        </svg>
                                        <div class="position-absolute top-50 start-50 translate-middle text-center">
                                            <h2 class="mb-0 font-weight-bolder">{{ number_format($health['score'], 1) }}</h2>
                                            <small class="text-muted">/ 100</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Quick Stats</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-3">
                                <span class="text-sm">Total Sent</span>
                                <strong>{{ number_format($health['metrics']['total_sent']) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="text-sm">Delivered</span>
                                <strong class="text-success">{{ number_format($health['metrics']['total_delivered']) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span class="text-sm">Failed</span>
                                <strong class="text-danger">{{ number_format($health['metrics']['total_failed']) }}</strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-sm">Blocked</span>
                                <strong class="text-warning">{{ number_format($health['metrics']['total_blocked']) }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score Breakdown -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Score Breakdown</h6>
                            <p class="text-xs text-muted mb-0">Each component contributes to the overall score based on its weight</p>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                @foreach($health['breakdown'] as $key => $component)
                                    <div class="col-md">
                                        <div class="text-center p-3 border rounded mb-3 mb-md-0">
                                            <h3 class="mb-1 {{ $component['score'] >= 70 ? 'text-success' : ($component['score'] >= 50 ? 'text-warning' : 'text-danger') }}">
                                                {{ number_format($component['score'], 1) }}
                                            </h3>
                                            <p class="text-sm font-weight-bold text-uppercase mb-1">
                                                {{ str_replace('_', ' ', $key) }}
                                            </p>
                                            <span class="badge bg-light text-dark">{{ $component['weight'] }}%</span>
                                            <div class="progress mt-2" style="height: 4px;">
                                                <div class="progress-bar {{ $component['score'] >= 70 ? 'bg-success' : ($component['score'] >= 50 ? 'bg-warning' : 'bg-danger') }}" 
                                                     style="width: {{ $component['score'] }}%"></div>
                                            </div>
                                            <p class="text-xs text-muted mt-2 mb-0">
                                                @if(isset($component['rate']))
                                                    Rate: {{ number_format($component['rate'], 2) }}%
                                                @elseif(isset($component['block_rate']))
                                                    Block: {{ number_format($component['block_rate'], 2) }}%
                                                @elseif(isset($component['spike_factor']))
                                                    Spike: {{ number_format($component['spike_factor'], 2) }}x
                                                @elseif(isset($component['unique_templates']))
                                                    Templates: {{ $component['unique_templates'] }}
                                                @endif
                                            </p>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trend Chart & Auto Actions -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">7-Day Trend</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="trendChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">Auto-Actions Status</h6>
                        </div>
                        <div class="card-body">
                            @php
                                $actions = [
                                    'batch_size_reduced' => ['label' => 'Batch Size Reduced', 'icon' => 'fa-compress-arrows-alt'],
                                    'delay_added' => ['label' => 'Delay Added', 'icon' => 'fa-clock'],
                                    'campaign_paused' => ['label' => 'Campaigns Paused', 'icon' => 'fa-pause-circle'],
                                    'warmup_paused' => ['label' => 'Warmup Paused', 'icon' => 'fa-thermometer-empty'],
                                    'reconnect_blocked' => ['label' => 'Reconnect Blocked', 'icon' => 'fa-ban'],
                                ];
                            @endphp
                            @foreach($actions as $key => $action)
                                <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                                    <div>
                                        <i class="fas {{ $action['icon'] }} me-2 text-muted"></i>
                                        <span class="text-sm">{{ $action['label'] }}</span>
                                    </div>
                                    @if($health['auto_actions'][$key])
                                        <span class="badge bg-gradient-warning">Active</span>
                                    @else
                                        <span class="badge bg-light text-dark">Inactive</span>
                                    @endif
                                </div>
                            @endforeach
                            
                            @if(collect($health['auto_actions'])->contains(true))
                                <div class="mt-3">
                                    <button class="btn btn-warning btn-sm w-100" onclick="resetActions()">
                                        <i class="fas fa-undo me-1"></i> Reset Actions
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recommendations -->
            @if(!empty($health['recommendations']))
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header pb-0">
                                <h6 class="mb-0">
                                    <i class="fas fa-lightbulb text-warning me-2"></i>
                                    Recommendations
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    @foreach($health['recommendations'] as $rec)
                                        <div class="col-md-6 mb-3">
                                            <div class="d-flex align-items-start p-3 border rounded">
                                                <span class="badge me-3 {{ $rec['priority'] === 'critical' ? 'bg-danger' : ($rec['priority'] === 'high' ? 'bg-warning' : ($rec['priority'] === 'medium' ? 'bg-info' : 'bg-secondary')) }}">
                                                    {{ strtoupper($rec['priority']) }}
                                                </span>
                                                <div>
                                                    <p class="mb-0 text-sm">{{ $rec['message'] }}</p>
                                                    <small class="text-muted text-uppercase">{{ $rec['type'] }}</small>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Action Buttons -->
            <div class="row">
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary" onclick="recalculate()">
                            <i class="fas fa-sync me-1"></i> Recalculate Now
                        </button>
                        <button class="btn btn-outline-secondary" onclick="recalculate('7d')">
                            Recalculate (7 Days)
                        </button>
                        <button class="btn btn-outline-secondary" onclick="recalculate('30d')">
                            Recalculate (30 Days)
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    @if(!isset($health['error']))
    // Trend Chart
    const trendData = @json($health['trend']['data']);
    const labels = trendData.map(d => d.date);
    const scores = trendData.map(d => d.score);
    
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Health Score',
                data: scores,
                borderColor: '#5e72e4',
                backgroundColor: 'rgba(94, 114, 228, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#5e72e4'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    min: 0,
                    max: 100,
                    grid: {
                        display: true,
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
    @endif

    function recalculate(window = '24h') {
        OwnerPopup.loading('Recalculating...');
        
        fetch(`{{ url('owner/health/api') }}/{{ $connection->id }}/recalculate`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ window: window, async: false })
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

    async function resetActions() {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Reset All Auto-Actions?',
            text: 'Auto-actions untuk koneksi ini akan direset.',
            confirmText: '<i class="fas fa-redo me-1"></i> Ya, Reset'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Resetting...');
        
        fetch(`{{ url('owner/health/api') }}/{{ $connection->id }}/reset-actions`, {
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
</script>
@endpush
