@extends('layouts.owner')

@section('title', 'Warmup Management')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="fas fa-fire text-warning me-2"></i>
                        Auto Warm-up Engine
                    </h4>
                    <p class="text-sm text-muted mb-0">
                        Kelola state warmup dan limit pengiriman per nomor WhatsApp
                    </p>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-primary" onclick="refreshAll()">
                        <i class="fas fa-sync me-1"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row mb-4">
        @foreach(['NEW' => 'info', 'WARMING' => 'primary', 'STABLE' => 'success', 'COOLDOWN' => 'warning', 'SUSPENDED' => 'danger'] as $state => $color)
        <div class="col-xl col-md-4 col-6 mb-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-3 text-center">
                    <div class="icon icon-shape bg-gradient-{{ $color }} shadow-{{ $color }} text-center rounded-circle mx-auto mb-2" style="width: 48px; height: 48px;">
                        <i class="fas {{ $stateIcons[$state] ?? 'fa-circle' }} text-white" style="line-height: 48px;"></i>
                    </div>
                    <h5 class="mb-1 font-weight-bolder">{{ $summary[$state] ?? 0 }}</h5>
                    <p class="text-xs text-muted mb-0 text-uppercase">{{ $state }}</p>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- State Legend --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        State Machine Rules
                    </h6>
                </div>
                <div class="card-body pt-2">
                    <div class="row text-sm">
                        <div class="col-md-4 mb-2">
                            <span class="badge bg-info me-2">NEW</span>
                            <span class="text-muted">Hari 1-3 • 20-30 msg/day • Utility only</span>
                        </div>
                        <div class="col-md-4 mb-2">
                            <span class="badge bg-primary me-2">WARMING</span>
                            <span class="text-muted">Hari 4-7 • 50-80 msg/day • Marketing 20%</span>
                        </div>
                        <div class="col-md-4 mb-2">
                            <span class="badge bg-success me-2">STABLE</span>
                            <span class="text-muted">Health A • Full limits • All templates</span>
                        </div>
                        <div class="col-md-4 mb-2">
                            <span class="badge bg-warning me-2">COOLDOWN</span>
                            <span class="text-muted">Health C • Blocked 24-72h • Inbox only</span>
                        </div>
                        <div class="col-md-4 mb-2">
                            <span class="badge bg-danger me-2">SUSPENDED</span>
                            <span class="text-muted">Health D • Blast disabled • Owner alert</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Warmup Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Daftar Nomor WhatsApp</h6>
                    <div class="d-flex gap-2">
                        <select id="stateFilter" class="form-select form-select-sm" style="width: auto;" onchange="filterByState()">
                            <option value="">Semua State</option>
                            <option value="NEW">NEW</option>
                            <option value="WARMING">WARMING</option>
                            <option value="STABLE">STABLE</option>
                            <option value="COOLDOWN">COOLDOWN</option>
                            <option value="SUSPENDED">SUSPENDED</option>
                        </select>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0" id="warmupTable">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nomor / Client</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">State</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Limit Aktif</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Sisa Quota</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Health</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Umur</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($warmups as $warmup)
                                <tr data-state="{{ $warmup['state'] }}" data-warmup-id="{{ $warmup['id'] }}">
                                    <td>
                                        <div class="d-flex px-2 py-1">
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">{{ $warmup['phone_number'] }}</h6>
                                                <p class="text-xs text-secondary mb-0">{{ $warmup['client_name'] }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-gradient-{{ $warmup['state_color'] }}">
                                            <i class="fas {{ $warmup['state_icon'] }} me-1"></i>
                                            {{ $warmup['state'] }}
                                        </span>
                                        @if($warmup['is_cooldown'] && $warmup['cooldown_remaining'] > 0)
                                            <div class="text-xs text-warning mt-1">
                                                <i class="fas fa-clock"></i> {{ $warmup['cooldown_remaining'] }}h remaining
                                            </div>
                                        @endif
                                    </td>
                                    <td class="align-middle text-center">
                                        <div class="text-sm">
                                            <span class="font-weight-bold">{{ $warmup['daily_limit'] }}</span>/day
                                        </div>
                                        <div class="text-xs text-muted">
                                            {{ $warmup['hourly_limit'] }}/hour
                                        </div>
                                    </td>
                                    <td class="align-middle text-center">
                                        <div class="d-flex flex-column align-items-center">
                                            <span class="text-sm font-weight-bold {{ $warmup['remaining_today'] <= 10 ? 'text-danger' : '' }}">
                                                {{ $warmup['remaining_today'] }}
                                            </span>
                                            <div class="progress w-75 mt-1" style="height: 4px;">
                                                @php
                                                    $usagePercent = $warmup['daily_limit'] > 0 
                                                        ? (($warmup['daily_limit'] - $warmup['remaining_today']) / $warmup['daily_limit']) * 100 
                                                        : 0;
                                                @endphp
                                                <div class="progress-bar bg-gradient-{{ $usagePercent > 80 ? 'danger' : ($usagePercent > 50 ? 'warning' : 'success') }}" 
                                                     style="width: {{ $usagePercent }}%"></div>
                                            </div>
                                            <span class="text-xxs text-muted">dari {{ $warmup['daily_limit'] }}</span>
                                        </div>
                                    </td>
                                    <td class="align-middle text-center">
                                        @if($warmup['health_grade'])
                                            <span class="badge bg-gradient-{{ $warmup['health_grade'] === 'A' ? 'success' : ($warmup['health_grade'] === 'B' ? 'info' : ($warmup['health_grade'] === 'C' ? 'warning' : 'danger')) }}">
                                                {{ $warmup['health_grade'] }}
                                            </span>
                                            <div class="text-xxs text-muted">{{ $warmup['health_score'] ?? '-' }}</div>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="align-middle text-center">
                                        <span class="text-sm">{{ $warmup['age_days'] }} hari</span>
                                    </td>
                                    <td class="align-middle text-center">
                                        <div class="btn-group btn-group-sm">
                                            @if($warmup['state'] !== 'COOLDOWN' && $warmup['state'] !== 'SUSPENDED')
                                                <button class="btn btn-outline-warning btn-sm mb-0" 
                                                        onclick="forceCooldown({{ $warmup['id'] }})"
                                                        title="Force Cooldown">
                                                    <i class="fas fa-pause"></i>
                                                </button>
                                            @endif
                                            
                                            @if($warmup['state'] === 'COOLDOWN' || $warmup['state'] === 'SUSPENDED')
                                                <button class="btn btn-outline-success btn-sm mb-0" 
                                                        onclick="resumeWarmup({{ $warmup['id'] }})"
                                                        title="Resume">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            @endif
                                            
                                            <button class="btn btn-outline-secondary btn-sm mb-0" 
                                                    onclick="viewHistory({{ $warmup['id'] }})"
                                                    title="View History">
                                                <i class="fas fa-history"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <i class="fas fa-inbox text-muted mb-2" style="font-size: 2rem;"></i>
                                        <p class="text-muted mb-0">Tidak ada warmup aktif</p>
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

{{-- Force Cooldown Modal --}}
<div class="modal fade" id="forceCooldownModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-pause-circle text-warning me-2"></i>
                    Force Cooldown
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Nomor akan dipaksa masuk mode cooldown. Semua blast akan dihentikan.</p>
                
                <div class="mb-3">
                    <label class="form-label">Durasi Cooldown</label>
                    <select class="form-select" id="cooldownHours">
                        <option value="24">24 jam</option>
                        <option value="48" selected>48 jam</option>
                        <option value="72">72 jam</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Alasan (opsional)</label>
                    <input type="text" class="form-control" id="cooldownReason" placeholder="Alasan cooldown...">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" onclick="confirmForceCooldown()">
                    <i class="fas fa-pause me-1"></i> Force Cooldown
                </button>
            </div>
        </div>
    </div>
</div>

{{-- History Modal --}}
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-history text-primary me-2"></i>
                    Warmup History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs mb-3" id="historyTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stateEvents">
                            State Transitions
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#limitChanges">
                            Limit Changes
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#autoBlocks">
                            Auto Blocks
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="historyTabContent">
                    <div class="tab-pane fade show active" id="stateEvents">
                        <div id="stateEventsList" class="timeline-list">
                            <div class="text-center py-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading...
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="limitChanges">
                        <div id="limitChangesList">
                            <div class="text-center py-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading...
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="autoBlocks">
                        <div id="autoBlocksList">
                            <div class="text-center py-4">
                                <i class="fas fa-spinner fa-spin"></i> Loading...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let selectedWarmupId = null;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

function filterByState() {
    const filter = document.getElementById('stateFilter').value;
    const rows = document.querySelectorAll('#warmupTable tbody tr[data-state]');
    
    rows.forEach(row => {
        if (!filter || row.dataset.state === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function refreshAll() {
    window.location.reload();
}

function forceCooldown(warmupId) {
    selectedWarmupId = warmupId;
    new bootstrap.Modal(document.getElementById('forceCooldownModal')).show();
}

function confirmForceCooldown() {
    const hours = document.getElementById('cooldownHours').value;
    const reason = document.getElementById('cooldownReason').value;
    
    fetch(`/owner/warmup/${selectedWarmupId}/force-cooldown`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        },
        body: JSON.stringify({ hours, reason })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'Cooldown berhasil diterapkan');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('error', data.message || 'Gagal menerapkan cooldown');
        }
    })
    .catch(() => showToast('error', 'Terjadi kesalahan'));
    
    bootstrap.Modal.getInstance(document.getElementById('forceCooldownModal')).hide();
}

async function resumeWarmup(warmupId) {
    const confirmed = await OwnerPopup.confirmWarning({
        title: 'Resume Warmup?',
        text: `
            <p class="mb-2">Nomor akan kembali ke state normal berdasarkan umur dan health score.</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Proses warmup akan dilanjutkan sesuai konfigurasi.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-play me-1"></i> Ya, Resume'
    });
    
    if (!confirmed) return;
    
    OwnerPopup.loading('Memproses...');
    
    fetch(`/owner/warmup/${warmupId}/resume`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            OwnerPopup.success('Warmup berhasil di-resume').then(() => window.location.reload());
        } else {
            OwnerPopup.error(data.message || 'Gagal resume warmup');
        }
    })
    .catch(() => OwnerPopup.error('Terjadi kesalahan'));
}

function viewHistory(warmupId) {
    selectedWarmupId = warmupId;
    const modal = new bootstrap.Modal(document.getElementById('historyModal'));
    modal.show();
    
    loadHistory(warmupId);
}

function loadHistory(warmupId) {
    // Load state events
    fetch(`/owner/warmup/${warmupId}/history/states`)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('stateEventsList');
            if (data.events && data.events.length) {
                container.innerHTML = data.events.map(e => `
                    <div class="d-flex align-items-start mb-3">
                        <div class="icon icon-shape bg-gradient-${e.color} shadow text-center rounded-circle me-3" style="width: 32px; height: 32px; min-width: 32px;">
                            <i class="fas fa-arrow-right text-white" style="font-size: 12px; line-height: 32px;"></i>
                        </div>
                        <div>
                            <p class="text-sm mb-0 font-weight-bold">${e.from_state || 'N/A'} → ${e.to_state}</p>
                            <p class="text-xs text-muted mb-0">${e.trigger_label} • ${e.created_at}</p>
                            ${e.description ? `<p class="text-xs text-secondary mb-0">${e.description}</p>` : ''}
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-muted text-center">Tidak ada riwayat state</p>';
            }
        });
    
    // Load limit changes
    fetch(`/owner/warmup/${warmupId}/history/limits`)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('limitChangesList');
            if (data.changes && data.changes.length) {
                container.innerHTML = `
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Tipe</th>
                                <th>Perubahan</th>
                                <th>Alasan</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.changes.map(c => `
                                <tr>
                                    <td><span class="badge bg-light text-dark">${c.limit_type}</span></td>
                                    <td>${c.old_value} → ${c.new_value}</td>
                                    <td class="text-xs">${c.reason_label}</td>
                                    <td class="text-xs text-muted">${c.created_at}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            } else {
                container.innerHTML = '<p class="text-muted text-center">Tidak ada perubahan limit</p>';
            }
        });
    
    // Load auto blocks
    fetch(`/owner/warmup/${warmupId}/history/blocks`)
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('autoBlocksList');
            if (data.blocks && data.blocks.length) {
                container.innerHTML = data.blocks.map(b => `
                    <div class="alert alert-${b.is_resolved ? 'light' : b.severity_color} py-2 mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-ban me-1"></i>
                                <strong>${b.block_label}</strong>
                            </span>
                            ${b.is_resolved 
                                ? '<span class="badge bg-success">Resolved</span>'
                                : '<span class="badge bg-danger">Active</span>'
                            }
                        </div>
                        <p class="text-xs mb-0 mt-1">
                            ${b.trigger_event || 'N/A'} • ${b.blocked_at}
                            ${b.block_duration_hours ? `• ${b.block_duration_hours}h duration` : ''}
                        </p>
                    </div>
                `).join('');
            } else {
                container.innerHTML = '<p class="text-muted text-center">Tidak ada auto block</p>';
            }
        });
}

function showToast(type, message) {
    // Use OwnerPopup for consistency
    if (type === 'success') {
        OwnerPopup.toast(message, 'success');
    } else if (type === 'error') {
        OwnerPopup.toast(message, 'error');
    } else {
        OwnerPopup.toast(message, 'info');
    }
}
</script>
@endpush
