@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Auto Pricing Dashboard'])
    
    <div class="container-fluid py-4">
        <!-- Summary Cards -->
        <div class="row">
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Current Price</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        Rp {{ number_format($summary['current']['price'], 0, ',', '.') }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                    <i class="fas fa-tag text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Current Cost</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        Rp {{ number_format($summary['current']['cost'], 0, ',', '.') }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                    <i class="fas fa-coins text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Current Margin</p>
                                    <h5 class="font-weight-bolder mb-0 {{ $summary['current']['margin'] < $settings->min_margin_percent ? 'text-danger' : ($summary['current']['margin'] < $summary['current']['target_margin'] ? 'text-warning' : 'text-success') }}">
                                        {{ number_format($summary['current']['margin'], 2) }}%
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                    <i class="fas fa-percentage text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Target Margin</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($summary['current']['target_margin'], 0) }}%
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                    <i class="fas fa-bullseye text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Last Change Info -->
        @if($summary['last_change'])
            <div class="row mb-4">
                <div class="col-12">
                    <div class="alert alert-light d-flex align-items-center mb-0">
                        <i class="fas fa-clock me-3"></i>
                        <div>
                            <strong>Last Price Change:</strong> 
                            {{ $summary['last_change']['date_human'] }} 
                            ({{ $summary['last_change']['trigger'] }}) - 
                            Rp {{ number_format($summary['last_change']['previous_price'], 0) }} 
                            → Rp {{ number_format($summary['last_change']['new_price'], 0) }}
                            <span class="{{ $summary['last_change']['change_percent'] > 0 ? 'text-danger' : ($summary['last_change']['change_percent'] < 0 ? 'text-success' : '') }}">
                                ({{ $summary['last_change']['change_percent'] >= 0 ? '+' : '' }}{{ number_format($summary['last_change']['change_percent'], 2) }}%)
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Action Buttons -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary" onclick="recalculateNow()">
                        <i class="fas fa-sync me-1"></i> Recalculate Now
                    </button>
                    <button type="button" class="btn btn-outline-primary" onclick="previewPrice()">
                        <i class="fas fa-eye me-1"></i> Preview
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#costModal">
                        <i class="fas fa-edit me-1"></i> Update Cost
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#settingsModal">
                        <i class="fas fa-cog me-1"></i> Settings
                    </button>
                </div>
            </div>
        </div>

        <!-- Price Chart & Settings -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Price History (Last 7 Days)</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="priceChart" height="200"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Current Settings</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Auto Adjust</span>
                            <span class="badge {{ $settings->auto_adjust_enabled ? 'bg-success' : 'bg-secondary' }}">
                                {{ $settings->auto_adjust_enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Recalculate Interval</span>
                            <strong>{{ $settings->recalculate_interval_minutes }} min</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Margin Range</span>
                            <strong>{{ $settings->min_margin_percent }}% - {{ $settings->max_margin_percent }}%</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Health Warning Markup</span>
                            <strong>+{{ $settings->health_warning_markup }}%</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Health Critical Markup</span>
                            <strong>+{{ $settings->health_critical_markup }}%</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2 border-bottom">
                            <span class="text-sm">Volume Spike Threshold</span>
                            <strong>{{ number_format($settings->volume_spike_threshold) }}</strong>
                        </div>
                        <div class="d-flex justify-content-between py-2">
                            <span class="text-sm">Max Daily Change</span>
                            <strong>{{ $settings->max_daily_price_change }}%</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing Logs Table -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Recent Pricing Logs</h6>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Trigger</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Previous</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">New</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Change</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Margin</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Guardrail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($logs as $log)
                                        <tr>
                                            <td>
                                                <div class="d-flex px-2 py-1">
                                                    <div class="d-flex flex-column justify-content-center">
                                                        <h6 class="mb-0 text-sm">{{ $log->created_at->format('d M Y') }}</h6>
                                                        <p class="text-xs text-secondary mb-0">{{ $log->created_at->format('H:i') }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-gradient-secondary">{{ $log->getTriggerLabel() }}</span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm">Rp {{ number_format($log->previous_price, 0) }}</span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm font-weight-bold">Rp {{ number_format($log->new_price, 0) }}</span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm {{ $log->price_change_percent > 0 ? 'text-danger' : ($log->price_change_percent < 0 ? 'text-success' : '') }}">
                                                    {{ $log->getFormattedChange() }}
                                                </span>
                                            </td>
                                            <td class="align-middle text-center">
                                                <span class="text-sm">{{ number_format($log->actual_margin_percent, 1) }}%</span>
                                            </td>
                                            <td class="align-middle text-center">
                                                @if($log->guardrail_applied)
                                                    <span class="badge bg-gradient-warning" title="{{ $log->guardrail_reason }}">
                                                        <i class="fas fa-shield-alt"></i>
                                                    </span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center py-4">
                                                <p class="text-muted mb-0">No pricing logs yet.</p>
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
    </div>

    <!-- Cost Update Modal -->
    <div class="modal fade" id="costModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Cost</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="costForm">
                        <div class="mb-3">
                            <label class="form-label">Current Cost</label>
                            <input type="text" class="form-control" value="Rp {{ number_format($settings->base_cost_per_message, 0) }}" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Cost (IDR)</label>
                            <input type="number" class="form-control" id="newCost" name="cost" min="1" max="10000" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Source</label>
                            <select class="form-select" id="costSource" name="source">
                                <option value="manual">Manual Update</option>
                                <option value="gupshup">Gupshup Rate Change</option>
                                <option value="api">API Update</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason (optional)</label>
                            <input type="text" class="form-control" id="costReason" name="reason" placeholder="e.g., Gupshup rate increase">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateCost()">Update Cost</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Pricing Settings</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="settingsForm">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Margin Settings</h6>
                                <div class="mb-3">
                                    <label class="form-label">Target Margin (%)</label>
                                    <input type="number" class="form-control" name="target_margin_percent" 
                                           value="{{ $settings->target_margin_percent }}" min="0" max="100" step="0.1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Min Margin (%)</label>
                                    <input type="number" class="form-control" name="min_margin_percent" 
                                           value="{{ $settings->min_margin_percent }}" min="0" max="100" step="0.1">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Max Margin (%)</label>
                                    <input type="number" class="form-control" name="max_margin_percent" 
                                           value="{{ $settings->max_margin_percent }}" min="0" max="200" step="0.1">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Health Adjustments</h6>
                                <div class="mb-3">
                                    <label class="form-label">Warning Markup (%)</label>
                                    <input type="number" class="form-control" name="health_warning_markup" 
                                           value="{{ $settings->health_warning_markup }}" min="0" max="50" step="0.5">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Critical Markup (%)</label>
                                    <input type="number" class="form-control" name="health_critical_markup" 
                                           value="{{ $settings->health_critical_markup }}" min="0" max="100" step="0.5">
                                </div>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="block_on_critical" id="blockOnCritical"
                                           {{ $settings->block_on_critical ? 'checked' : '' }}>
                                    <label class="form-check-label" for="blockOnCritical">Block sending on CRITICAL health</label>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Volume Settings</h6>
                                <div class="mb-3">
                                    <label class="form-label">Spike Threshold (messages)</label>
                                    <input type="number" class="form-control" name="volume_spike_threshold" 
                                           value="{{ $settings->volume_spike_threshold }}" min="100">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Spike Markup (%)</label>
                                    <input type="number" class="form-control" name="volume_spike_markup" 
                                           value="{{ $settings->volume_spike_markup }}" min="0" max="50" step="0.5">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Guardrails</h6>
                                <div class="mb-3">
                                    <label class="form-label">Max Daily Change (%)</label>
                                    <input type="number" class="form-control" name="max_daily_price_change" 
                                           value="{{ $settings->max_daily_price_change }}" min="1" max="50" step="0.5">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Smoothing Factor (0-1)</label>
                                    <input type="number" class="form-control" name="price_smoothing_factor" 
                                           value="{{ $settings->price_smoothing_factor }}" min="0.1" max="1" step="0.05">
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Automation</h6>
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" name="auto_adjust_enabled" id="autoAdjust"
                                           {{ $settings->auto_adjust_enabled ? 'checked' : '' }}>
                                    <label class="form-check-label" for="autoAdjust">Enable Auto Adjust</label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Recalculate Interval (minutes)</label>
                                    <input type="number" class="form-control" name="recalculate_interval_minutes" 
                                           value="{{ $settings->recalculate_interval_minutes }}" min="5" max="1440">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6>Alert Thresholds</h6>
                                <div class="mb-3">
                                    <label class="form-label">Alert if Margin Below (%)</label>
                                    <input type="number" class="form-control" name="alert_margin_threshold" 
                                           value="{{ $settings->alert_margin_threshold }}" min="0" max="100" step="0.5">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Alert if Price Change Above (%)</label>
                                    <input type="number" class="form-control" name="alert_price_change_threshold" 
                                           value="{{ $settings->alert_price_change_threshold }}" min="0" max="50" step="0.5">
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Preview Modal -->
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Price Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="previewContent">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="applyPreview()">Apply This Price</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('js')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Price Chart
    const historyData = @json($summary['history']);
    const labels = historyData.map(d => d.date);
    const prices = historyData.map(d => d.price);
    const margins = historyData.map(d => d.margin);
    
    if (labels.length > 0) {
        new Chart(document.getElementById('priceChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Price (IDR)',
                    data: prices,
                    borderColor: '#5e72e4',
                    backgroundColor: 'rgba(94, 114, 228, 0.1)',
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Margin (%)',
                    data: margins,
                    borderColor: '#2dce89',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: 'Price (IDR)' }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: 'Margin (%)' },
                        grid: { drawOnChartArea: false }
                    }
                }
            }
        });
    }

    async function recalculateNow() {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Recalculate Price?',
            text: 'Harga akan dihitung ulang berdasarkan cost dan parameter saat ini.',
            confirmText: '<i class="fas fa-calculator me-1"></i> Ya, Recalculate'
        });
        
        if (!confirmed) return;
        
        OwnerPopup.loading('Menghitung ulang harga...');
        
        fetch('{{ route("owner.pricing.api.recalculate") }}', {
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
                    <p class="mb-2"><strong>Price Recalculated!</strong></p>
                    <p>New Price: <strong>Rp ${data.data.result.new_price}</strong></p>
                    <p>Change: <strong>${data.data.result.price_change_percent}%</strong></p>
                `).then(() => location.reload());
            } else {
                OwnerPopup.error(data.message || 'Unknown error');
            }
        })
        .catch(error => OwnerPopup.error(error.message));
    }

    function previewPrice() {
        const modal = new bootstrap.Modal(document.getElementById('previewModal'));
        modal.show();
        
        fetch('{{ route("owner.pricing.api.preview") }}')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const r = data.data;
                    document.getElementById('previewContent').innerHTML = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Inputs</h6>
                                <table class="table table-sm">
                                    <tr><td>Cost</td><td>Rp ${r.inputs.cost}</td></tr>
                                    <tr><td>Health Score</td><td>${r.inputs.health_score}</td></tr>
                                    <tr><td>Health Status</td><td>${r.inputs.health_status.toUpperCase()}</td></tr>
                                    <tr><td>Daily Volume</td><td>${r.inputs.daily_volume}</td></tr>
                                    <tr><td>Target Margin</td><td>${r.inputs.target_margin}%</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Calculations</h6>
                                <table class="table table-sm">
                                    <tr><td>Base Price</td><td>Rp ${r.calculations.base_price}</td></tr>
                                    <tr><td>Health Adj</td><td>+${r.calculations.health_adjustment}%</td></tr>
                                    <tr><td>Volume Adj</td><td>+${r.calculations.volume_adjustment}%</td></tr>
                                    <tr><td>Raw Price</td><td>Rp ${r.calculations.raw_price}</td></tr>
                                    <tr><td>Smoothed</td><td>Rp ${r.calculations.smoothed_price}</td></tr>
                                </table>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <h4>Recommended Price: <strong class="text-primary">Rp ${r.result.new_price}</strong></h4>
                            <p class="text-muted mb-0">
                                Change: ${r.result.price_change_percent >= 0 ? '+' : ''}${r.result.price_change_percent}% | 
                                Margin: ${r.result.actual_margin_percent}%
                            </p>
                        </div>
                    `;
                }
            });
    }

    function applyPreview() {
        recalculateNow();
    }

    function updateCost() {
        const cost = document.getElementById('newCost').value;
        const source = document.getElementById('costSource').value;
        const reason = document.getElementById('costReason').value;
        
        if (!cost || cost < 1) {
            OwnerPopup.error('Please enter a valid cost', 'Validation Error');
            return;
        }
        
        OwnerPopup.loading('Updating cost...');
        
        fetch('{{ route("owner.pricing.api.update-cost") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ cost, source, reason })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success('Cost updated and price recalculated!').then(() => location.reload());
            } else {
                OwnerPopup.error(data.message || JSON.stringify(data.errors));
            }
        })
        .catch(error => OwnerPopup.error(error.message));
    }

    function saveSettings() {
        const form = document.getElementById('settingsForm');
        const formData = new FormData(form);
        const data = {};
        
        formData.forEach((value, key) => {
            if (key === 'block_on_critical' || key === 'auto_adjust_enabled') {
                data[key] = true;
            } else {
                data[key] = value;
            }
        });
        
        // Handle unchecked checkboxes
        if (!formData.has('block_on_critical')) data['block_on_critical'] = false;
        if (!formData.has('auto_adjust_enabled')) data['auto_adjust_enabled'] = false;
        
        OwnerPopup.loading('Saving settings...');
        
        fetch('{{ route("owner.pricing.api.update-settings") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'X-HTTP-Method-Override': 'PUT'
            },
            body: JSON.stringify({ ...data, _method: 'PUT' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OwnerPopup.success('Settings saved!').then(() => location.reload());
            } else {
                OwnerPopup.error(data.message || JSON.stringify(data.errors));
            }
        })
        .catch(error => OwnerPopup.error(error.message));
    }
</script>
@endpush
