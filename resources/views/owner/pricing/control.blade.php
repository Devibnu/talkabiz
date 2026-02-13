@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Pricing Control Panel'])
    
    <div class="container-fluid py-4">
        <!-- Summary Cards Row -->
        <div class="row">
            <!-- Average Margin -->
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Avg Margin</p>
                                    <h5 class="font-weight-bolder mb-0 {{ $summary['avg_margin'] < $settings['min_margin'] ? 'text-danger' : 'text-success' }}">
                                        {{ number_format($summary['avg_margin'], 1) }}%
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                                    <i class="fas fa-percentage text-lg opacity-10"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Target Margin -->
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Target Margin</p>
                                    <h5 class="font-weight-bolder mb-0">{{ number_format($settings['base_margin'], 0) }}%</h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                    <i class="fas fa-bullseye text-lg opacity-10"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- High Risk Clients -->
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">High Risk Clients</p>
                                    <h5 class="font-weight-bolder mb-0 {{ $summary['risk_summary']['high_risk'] > 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $summary['risk_summary']['high_risk'] + $summary['risk_summary']['blocked'] }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                    <i class="fas fa-exclamation-triangle text-lg opacity-10"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Active Alerts -->
            <div class="col-xl-3 col-sm-6 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Active Alerts</p>
                                    <h5 class="font-weight-bolder mb-0 {{ $summary['alerts']['critical'] > 0 ? 'text-danger' : '' }}">
                                        {{ $summary['alerts']['total_unresolved'] }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                    <i class="fas fa-bell text-lg opacity-10"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Meta Cost by Category -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Meta Cost per Category</h6>
                        <small class="text-muted">Ini adalah biaya real dari Meta/Gupshup</small>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($metaCosts['categories'] as $category => $cost)
                                <div class="col-md-3 col-sm-6 mb-3">
                                    <div class="card bg-gradient-{{ $category === 'marketing' ? 'primary' : ($category === 'utility' ? 'info' : ($category === 'authentication' ? 'warning' : 'secondary')) }} h-100">
                                        <div class="card-body p-3">
                                            <h6 class="text-white mb-2">{{ $cost['display_name'] }}</h6>
                                            <h4 class="text-white mb-1">{{ $cost['formatted_cost'] }}</h4>
                                            @if($cost['change'] != 0)
                                                <small class="text-white opacity-8">
                                                    <i class="fas fa-{{ $cost['change'] > 0 ? 'arrow-up' : 'arrow-down' }}"></i>
                                                    {{ $cost['change'] > 0 ? '+' : '' }}{{ $cost['change'] }}%
                                                </small>
                                            @endif
                                            <div class="mt-2">
                                                <button class="btn btn-sm btn-white" 
                                                        onclick="openCostModal('{{ $category }}', '{{ $cost['display_name'] }}', {{ $cost['cost'] }})">
                                                    <i class="fas fa-edit me-1"></i> Update
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Prices & Overrides -->
        <div class="row mb-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">Client Pricing (Calculated)</h6>
                            <p class="text-sm text-muted mb-0">
                                Harga yang dilihat client (Meta Cost + Margin + Adjustments)
                            </p>
                        </div>
                        <button class="btn btn-sm btn-outline-primary" onclick="recalculateAll()">
                            <i class="fas fa-sync me-1"></i> Recalculate
                        </button>
                    </div>
                    <div class="card-body px-0 pb-2">
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Category</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Meta Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Client Price</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Margin</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($categoryPricing as $category => $pricing)
                                        <tr>
                                            <td class="ps-3">
                                                <span class="badge bg-{{ $category === 'marketing' ? 'primary' : ($category === 'utility' ? 'info' : ($category === 'authentication' ? 'warning' : 'secondary')) }}">
                                                    {{ ucfirst($category) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-muted">Rp {{ number_format($pricing['meta_cost'], 0, ',', '.') }}</span>
                                            </td>
                                            <td>
                                                <strong>Rp {{ number_format($pricing['client_price'], 0, ',', '.') }}</strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $pricing['margin'] >= $settings['min_margin'] ? 'success' : 'danger' }}">
                                                    {{ number_format($pricing['margin'], 1) }}%
                                                </span>
                                            </td>
                                            <td>
                                                @if($pricing['is_locked'])
                                                    <span class="badge bg-dark"><i class="fas fa-lock me-1"></i> Locked</span>
                                                @elseif($pricing['has_override'])
                                                    <span class="badge bg-warning">Override</span>
                                                @else
                                                    <span class="badge bg-success">Auto</span>
                                                @endif
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-link text-primary p-0" 
                                                        onclick="openOverrideModal('{{ $category }}', {{ $pricing['client_price'] }}, {{ $pricing['is_locked'] ? 'true' : 'false' }})">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                @if($pricing['is_locked'])
                                                    <button class="btn btn-sm btn-link text-warning p-0 ms-2" 
                                                            onclick="unlockCategory('{{ $category }}')">
                                                        <i class="fas fa-unlock"></i>
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Owner Controls</h6>
                    </div>
                    <div class="card-body">
                        <form id="settingsForm">
                            <div class="mb-3">
                                <label class="form-label text-sm">Minimum Margin (%)</label>
                                <input type="number" class="form-control" name="global_minimum_margin" 
                                       value="{{ $settings['min_margin'] }}" min="5" max="50" step="1">
                                <small class="text-muted">Margin minimum yang dijamin</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-sm">Target Margin (%)</label>
                                <input type="number" class="form-control" name="target_margin_percent" 
                                       value="{{ $settings['base_margin'] }}" min="10" max="80" step="1">
                                <small class="text-muted">Target margin normal</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-sm">Max Discount (%)</label>
                                <input type="number" class="form-control" name="global_max_discount" 
                                       value="{{ $settings['max_discount'] }}" min="0" max="30" step="1">
                                <small class="text-muted">Diskon maksimal per client</small>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="risk_pricing_enabled" 
                                       {{ $settings['risk_pricing_enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label">Risk-based Pricing</label>
                            </div>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="category_pricing_enabled" 
                                       {{ $settings['category_pricing_enabled'] ? 'checked' : '' }}>
                                <label class="form-check-label">Category Pricing</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save me-1"></i> Save Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Risk Levels Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Client Risk Levels</h6>
                        <p class="text-sm text-muted mb-0">
                            Risk tinggi = margin lebih tinggi untuk proteksi
                        </p>
                    </div>
                    <div class="card-body px-0 pb-2">
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Client</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Risk Level</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Risk Score</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Payment Score</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Margin Adj.</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Max Discount</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($clientRisks as $risk)
                                        <tr>
                                            <td class="ps-3">
                                                <span class="font-weight-bold">{{ $risk->klien->nama ?? 'Client #' . $risk->klien_id }}</span>
                                            </td>
                                            <td>{!! $risk->risk_level_badge !!}</td>
                                            <td>{{ $risk->risk_score }}</td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <span class="me-2">{{ $risk->payment_score }}%</span>
                                                    <div class="progress" style="width: 60px; height: 6px;">
                                                        <div class="progress-bar bg-{{ $risk->payment_score >= 80 ? 'success' : ($risk->payment_score >= 50 ? 'warning' : 'danger') }}" 
                                                             style="width: {{ $risk->payment_score }}%"></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>+{{ $risk->margin_adjustment_percent }}%</td>
                                            <td>{{ $risk->max_discount_percent }}%</td>
                                            <td>
                                                <button class="btn btn-sm btn-link text-primary p-0" 
                                                        onclick="viewRiskDetail({{ $risk->id }})">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-sm btn-link text-warning p-0 ms-2" 
                                                        onclick="reevaluateRisk({{ $risk->id }})">
                                                    <i class="fas fa-sync"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Belum ada data risk level
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

        <!-- Recent Alerts -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Recent Alerts</h6>
                    </div>
                    <div class="card-body px-0 pb-2">
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Time</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Type</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Severity</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Title</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($alerts as $alert)
                                        <tr class="{{ !$alert->is_resolved ? 'table-warning' : '' }}">
                                            <td class="ps-3">
                                                <small>{{ $alert->created_at->diffForHumans() }}</small>
                                            </td>
                                            <td>{{ $alert->type_label }}</td>
                                            <td>{!! $alert->severity_badge !!}</td>
                                            <td>{{ $alert->title }}</td>
                                            <td>
                                                @if($alert->is_resolved)
                                                    <span class="badge bg-success">Resolved</span>
                                                @else
                                                    <span class="badge bg-warning">Open</span>
                                                @endif
                                            </td>
                                            <td>
                                                @unless($alert->is_resolved)
                                                    <button class="btn btn-sm btn-success" onclick="resolveAlert({{ $alert->id }})">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                Tidak ada alert aktif
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
                    <h5 class="modal-title">Update Meta Cost</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="costForm">
                    <div class="modal-body">
                        <input type="hidden" name="category" id="costCategory">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <input type="text" class="form-control" id="costCategoryDisplay" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Cost (IDR)</label>
                            <input type="number" class="form-control" name="cost" id="costValue" 
                                   min="0" step="10" required>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Mengubah Meta Cost akan mempengaruhi margin secara langsung.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Update Cost</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Override Modal -->
    <div class="modal fade" id="overrideModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Override Client Price</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="overrideForm">
                    <div class="modal-body">
                        <input type="hidden" name="category" id="overrideCategory">
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <input type="text" class="form-control" id="overrideCategoryDisplay" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Client Price (IDR)</label>
                            <input type="number" class="form-control" name="price" id="overridePrice" 
                                   min="0" step="10" required>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="lock" id="overrideLock">
                            <label class="form-check-label">Lock price (tidak auto-adjust)</label>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Override akan diterapkan ke semua client.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Apply Override</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('js')
<script>
    const costModal = new bootstrap.Modal(document.getElementById('costModal'));
    const overrideModal = new bootstrap.Modal(document.getElementById('overrideModal'));

    function openCostModal(category, displayName, currentCost) {
        document.getElementById('costCategory').value = category;
        document.getElementById('costCategoryDisplay').value = displayName;
        document.getElementById('costValue').value = currentCost;
        costModal.show();
    }

    function openOverrideModal(category, currentPrice, isLocked) {
        document.getElementById('overrideCategory').value = category;
        document.getElementById('overrideCategoryDisplay').value = category.charAt(0).toUpperCase() + category.slice(1);
        document.getElementById('overridePrice').value = currentPrice;
        document.getElementById('overrideLock').checked = isLocked;
        overrideModal.show();
    }

    // Cost form submit
    document.getElementById('costForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const response = await fetch('{{ route("owner.pricing.update-cost") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    category: formData.get('category'),
                    cost: parseFloat(formData.get('cost')),
                }),
            });
            
            const result = await response.json();
            
            if (result.success) {
                costModal.hide();
                location.reload();
            } else {
                OwnerPopup.error(result.message || 'Failed to update cost');
            }
        } catch (error) {
            OwnerPopup.error('Error updating cost');
        }
    });

    // Override form submit
    document.getElementById('overrideForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const response = await fetch('{{ route("owner.pricing.override") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    category: formData.get('category'),
                    price: parseFloat(formData.get('price')),
                    lock: formData.get('lock') === 'on',
                }),
            });
            
            const result = await response.json();
            
            if (result.success) {
                overrideModal.hide();
                location.reload();
            } else {
                OwnerPopup.error(result.message || 'Failed to apply override');
            }
        } catch (error) {
            OwnerPopup.error('Error applying override');
        }
    });

    // Settings form submit
    document.getElementById('settingsForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        const data = {
            global_minimum_margin: parseFloat(formData.get('global_minimum_margin')),
            target_margin_percent: parseFloat(formData.get('target_margin_percent')),
            global_max_discount: parseFloat(formData.get('global_max_discount')),
            risk_pricing_enabled: formData.get('risk_pricing_enabled') === 'on',
            category_pricing_enabled: formData.get('category_pricing_enabled') === 'on',
        };

        try {
            const response = await fetch('{{ route("owner.pricing.update-settings") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-HTTP-Method-Override': 'PUT',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ ...data, _method: 'PUT' }),
            });
            
            const result = await response.json();
            
            if (result.success) {
                OwnerPopup.success('Settings saved successfully').then(() => location.reload());
            } else {
                OwnerPopup.error(result.message || 'Failed to save settings');
            }
        } catch (error) {
            OwnerPopup.error('Error saving settings');
        }
    });

    async function recalculateAll() {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Recalculate Semua Harga?',
            text: 'Semua harga akan dihitung ulang berdasarkan cost terbaru.',
            confirmText: '<i class="fas fa-calculator me-1"></i> Ya, Recalculate'
        });
        
        if (confirmed) {
            OwnerPopup.loading('Menghitung ulang...');
            fetch('{{ route("owner.pricing.recalculate") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    OwnerPopup.success('Recalculation complete!').then(() => location.reload());
                } else {
                    OwnerPopup.error(result.message || 'Failed to recalculate');
                }
            })
            .catch(error => OwnerPopup.error(error.message));
        }
    }

    async function unlockCategory(category) {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Unlock Harga ' + category + '?',
            text: 'Harga kategori ini akan kembali auto-adjust berdasarkan cost.',
            confirmText: '<i class="fas fa-unlock me-1"></i> Ya, Unlock'
        });
        
        if (confirmed) {
            OwnerPopup.loading('Unlocking...');
            fetch('{{ route("owner.pricing.unlock") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ category }),
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    OwnerPopup.success('Category unlocked!').then(() => location.reload());
                } else {
                    OwnerPopup.error(result.message || 'Failed to unlock');
                }
            })
            .catch(error => OwnerPopup.error(error.message));
        }
    }

    async function resolveAlert(id) {
        const confirmed = await OwnerPopup.confirmWarning({
            title: 'Resolve Alert?',
            text: 'Tandai alert ini sebagai resolved.',
            confirmText: '<i class="fas fa-check me-1"></i> Ya, Resolve'
        });
        
        if (confirmed) {
            fetch('{{ route("owner.pricing.resolve-alert", "") }}/' + id, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                },
            })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    OwnerPopup.toast('Alert resolved!', 'success');
                    location.reload();
                }
            });
        }
    }

    function reevaluateRisk(id) {
        fetch('{{ route("owner.pricing.reevaluate-risk", "") }}/' + id, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
            },
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                location.reload();
            }
        });
    }
</script>
@endpush
