@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Go-Live Checklist'])
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-gradient-{{ $summary['ready'] ? 'success' : ($summary['failed'] > 0 ? 'danger' : 'warning') }}">
                    <div class="card-body p-4">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="text-white mb-2">
                                    @if($summary['ready'])
                                        🎉 SIAP GO-LIVE!
                                    @elseif($summary['failed'] > 0)
                                        ❌ {{ $summary['failed'] }} Masalah Harus Diperbaiki
                                    @else
                                        ⚠️ {{ $summary['warnings'] }} Warning Perlu Review
                                    @endif
                                </h4>
                                <p class="text-white opacity-8 mb-0">
                                    Score: {{ $summary['score'] }}% ({{ $summary['passed'] }}/{{ $summary['total'] }} checks passed)
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <button class="btn btn-white" onclick="refreshChecklist()">
                                    <i class="fas fa-sync me-1"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center py-4">
                        <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle mx-auto mb-3">
                            <i class="fas fa-check text-lg opacity-10"></i>
                        </div>
                        <h3 class="mb-0 text-success">{{ $summary['passed'] }}</h3>
                        <p class="text-sm text-muted mb-0">Passed</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center py-4">
                        <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle mx-auto mb-3">
                            <i class="fas fa-times text-lg opacity-10"></i>
                        </div>
                        <h3 class="mb-0 text-danger">{{ $summary['failed'] }}</h3>
                        <p class="text-sm text-muted mb-0">Failed</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center py-4">
                        <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle mx-auto mb-3">
                            <i class="fas fa-exclamation-triangle text-lg opacity-10"></i>
                        </div>
                        <h3 class="mb-0 text-warning">{{ $summary['warnings'] }}</h3>
                        <p class="text-sm text-muted mb-0">Warnings</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card h-100">
                    <div class="card-body text-center py-4">
                        <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle mx-auto mb-3">
                            <i class="fas fa-percentage text-lg opacity-10"></i>
                        </div>
                        <h3 class="mb-0 text-info">{{ $summary['score'] }}%</h3>
                        <p class="text-sm text-muted mb-0">Score</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checklist Sections -->
        @foreach($categories as $categoryKey => $category)
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">{{ $category['icon'] }} {{ $category['title'] }}</h6>
                        <p class="text-sm text-muted mb-0">{{ $category['description'] }}</p>
                    </div>
                    <div>
                        <span class="badge bg-success">{{ $category['passed'] }} ✓</span>
                        @if($category['failed'] > 0)
                            <span class="badge bg-danger">{{ $category['failed'] }} ✗</span>
                        @endif
                        @if($category['warnings'] > 0)
                            <span class="badge bg-warning">{{ $category['warnings'] }} ⚠</span>
                        @endif
                    </div>
                </div>
                <div class="card-body px-0 pb-0">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3" width="40">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Check</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Detail</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Fix</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($category['checks'] as $check)
                                    <tr class="{{ !$check['passed'] && $check['severity'] !== 'warning' ? 'table-danger' : (!$check['passed'] ? 'table-warning' : '') }}">
                                        <td class="ps-3">
                                            @if($check['passed'])
                                                <span class="text-success fs-5">✅</span>
                                            @elseif($check['severity'] === 'warning')
                                                <span class="text-warning fs-5">⚠️</span>
                                            @else
                                                <span class="text-danger fs-5">❌</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="font-weight-bold">{{ $check['name'] }}</span>
                                        </td>
                                        <td>
                                            <small class="text-muted">{{ $check['detail'] ?? '-' }}</small>
                                        </td>
                                        <td>
                                            @if(!$check['passed'] && $check['fix'])
                                                <code class="text-xs">{{ $check['fix'] }}</code>
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach

        <!-- Risk Assessment -->
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">⚡ Risiko Pasca Go-Live & Mitigasi</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Risiko</th>
                                <th>Severity</th>
                                <th>Mitigasi</th>
                                <th>PIC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Meta cost tiba-tiba naik</td>
                                <td><span class="badge bg-danger">High</span></td>
                                <td>Auto-adjust pricing engine + alert ke owner</td>
                                <td>System</td>
                            </tr>
                            <tr>
                                <td>WhatsApp number banned</td>
                                <td><span class="badge bg-danger">High</span></td>
                                <td>Health Score monitoring + auto warmup + cooldown</td>
                                <td>System</td>
                            </tr>
                            <tr>
                                <td>Payment gateway down</td>
                                <td><span class="badge bg-warning">Medium</span></td>
                                <td>Multiple gateway fallback + manual approval</td>
                                <td>Owner</td>
                            </tr>
                            <tr>
                                <td>High volume spike</td>
                                <td><span class="badge bg-warning">Medium</span></td>
                                <td>Queue worker scaling + rate limiting</td>
                                <td>DevOps</td>
                            </tr>
                            <tr>
                                <td>Client abuse/spam</td>
                                <td><span class="badge bg-warning">Medium</span></td>
                                <td>Risk level auto-adjust + block on high risk</td>
                                <td>System</td>
                            </tr>
                            <tr>
                                <td>Webhook endpoint failure</td>
                                <td><span class="badge bg-info">Low</span></td>
                                <td>Retry mechanism + dead letter queue</td>
                                <td>System</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">🛠️ Quick Actions</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <button class="btn btn-outline-primary w-100" onclick="runCommand('config:cache')">
                            <i class="fas fa-database me-1"></i> Cache Config
                        </button>
                    </div>
                    <div class="col-md-3 mb-3">
                        <button class="btn btn-outline-primary w-100" onclick="runCommand('route:cache')">
                            <i class="fas fa-route me-1"></i> Cache Routes
                        </button>
                    </div>
                    <div class="col-md-3 mb-3">
                        <button class="btn btn-outline-primary w-100" onclick="runCommand('view:cache')">
                            <i class="fas fa-eye me-1"></i> Cache Views
                        </button>
                    </div>
                    <div class="col-md-3 mb-3">
                        <button class="btn btn-outline-warning w-100" onclick="runCommand('optimize:clear')">
                            <i class="fas fa-trash me-1"></i> Clear All Cache
                        </button>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <a href="{{ route('owner.pricing.control') }}" class="btn btn-primary w-100">
                            <i class="fas fa-dollar-sign me-1"></i> Pricing Control
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="{{ route('owner.warmup.index') }}" class="btn btn-info w-100">
                            <i class="fas fa-fire me-1"></i> Warmup Manager
                        </a>
                    </div>
                    <div class="col-md-4 mb-3">
                        <a href="{{ route('owner.profit.index') ?? '#' }}" class="btn btn-success w-100">
                            <i class="fas fa-chart-line me-1"></i> Profit Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('js')
<script>
    function refreshChecklist() {
        location.reload();
    }

    async function runCommand(command) {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Run Artisan Command?',
            text: `<code class="bg-light p-2 rounded d-block">${command}</code>`,
            confirmText: '<i class="fas fa-terminal me-1"></i> Ya, Run'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Executing command...');
        
        fetch('{{ route("owner.golive.run-command") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ command }),
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                OwnerPopup.success(`
                    <p class="mb-2"><strong>Command executed successfully!</strong></p>
                    <pre class="bg-light p-2 rounded text-start" style="max-height: 200px; overflow: auto; font-size: 12px;">${result.output}</pre>
                `, 'Success');
            } else {
                OwnerPopup.error(result.message);
            }
        })
        .catch(err => {
            OwnerPopup.error('Error executing command');
        });
    }
</script>
@endpush
