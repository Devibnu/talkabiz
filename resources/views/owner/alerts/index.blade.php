@extends('layouts.app')

@section('title', 'Alert Logs - Owner')

@push('css')
<style>
    .alert-card {
        transition: all 0.2s ease;
        border-left: 4px solid transparent;
    }
    .alert-card.unread {
        background-color: #f8f9fa;
    }
    .alert-card.level-critical {
        border-left-color: #dc3545;
    }
    .alert-card.level-warning {
        border-left-color: #ffc107;
    }
    .alert-card.level-info {
        border-left-color: #17a2b8;
    }
    .badge-critical { background-color: #dc3545; }
    .badge-warning { background-color: #ffc107; color: #333; }
    .badge-info { background-color: #17a2b8; }
    .stat-card {
        border-radius: 10px;
        padding: 20px;
        text-align: center;
    }
    .stat-card h3 {
        font-size: 2rem;
        margin: 0;
    }
    .stat-card p {
        margin: 5px 0 0 0;
        opacity: 0.9;
    }
    .filter-section {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1">🔔 Alert Logs</h4>
                    <p class="text-muted mb-0">Monitor semua alert sistem</p>
                </div>
                <div>
                    @if($criticalUnacked > 0)
                    <span class="badge badge-critical me-2">{{ $criticalUnacked }} Critical Unacknowledged</span>
                    @endif
                    @if($unreadCount > 0)
                    <span class="badge bg-secondary me-2">{{ $unreadCount }} Unread</span>
                    @endif
                    <button class="btn btn-sm btn-outline-primary" onclick="markAllRead()">
                        <i class="fas fa-check-double"></i> Mark All Read
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4" id="stats-section">
        <div class="col-md-3">
            <div class="stat-card bg-danger text-white">
                <h3 id="stat-critical">-</h3>
                <p>🚨 Critical</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-warning">
                <h3 id="stat-warning">-</h3>
                <p>⚠️ Warning</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-info text-white">
                <h3 id="stat-info">-</h3>
                <p>ℹ️ Info</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-secondary text-white">
                <h3 id="stat-today">-</h3>
                <p>📅 Today</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <div class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select class="form-select form-select-sm" id="filter-type">
                    <option value="">All Types</option>
                    @foreach($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-select form-select-sm" id="filter-level">
                    <option value="">All Levels</option>
                    @foreach($levels as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" id="filter-read">
                    <option value="">All</option>
                    <option value="0">Unread</option>
                    <option value="1">Read</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" class="form-control form-control-sm" id="filter-from">
            </div>
            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" class="form-control form-control-sm" id="filter-to">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary btn-sm w-100" onclick="loadAlerts()">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </div>
    </div>

    <!-- Alert List -->
    <div class="card">
        <div class="card-body p-0">
            <div id="alerts-container">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading alerts...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4" id="pagination-container">
    </div>
</div>

<!-- Alert Detail Modal -->
<div class="modal fade" id="alertDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-title">Alert Detail</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modal-body">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="btn-acknowledge" onclick="acknowledgeAlert()">
                    <i class="fas fa-check"></i> Acknowledge
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">⚙️ Alert Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="settings-form">
                    <h6>Telegram</h6>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="telegram_enabled" name="telegram_enabled">
                            <label class="form-check-label" for="telegram_enabled">Enable Telegram Notifications</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Chat ID</label>
                        <input type="text" class="form-control" name="telegram_chat_id" id="telegram_chat_id">
                    </div>

                    <h6>Email</h6>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled">
                            <label class="form-check-label" for="email_enabled">Enable Email Notifications</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email_address" id="email_address">
                    </div>

                    <h6>Throttling</h6>
                    <div class="mb-3">
                        <label class="form-label">Throttle Minutes</label>
                        <input type="number" class="form-control" name="throttle_minutes" id="throttle_minutes" min="1" max="1440">
                        <small class="text-muted">Minimum interval between duplicate alerts</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" onclick="testTelegram()">Test Telegram</button>
                <button type="button" class="btn btn-outline-primary" onclick="testEmail()">Test Email</button>
                <button type="button" class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('js')
<script>
let currentPage = 1;
let currentAlertId = null;

document.addEventListener('DOMContentLoaded', function() {
    loadStats();
    loadAlerts();
});

// Load statistics
async function loadStats() {
    try {
        const response = await fetch('/owner/alerts/stats');
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('stat-critical').textContent = data.data.by_level.critical;
            document.getElementById('stat-warning').textContent = data.data.by_level.warning;
            document.getElementById('stat-info').textContent = data.data.by_level.info;
            document.getElementById('stat-today').textContent = data.data.today_count;
        }
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
}

// Load alerts
async function loadAlerts(page = 1) {
    currentPage = page;
    const container = document.getElementById('alerts-container');
    
    container.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status"></div>
        </div>
    `;

    const params = new URLSearchParams({
        page: page,
        per_page: 20
    });

    const type = document.getElementById('filter-type').value;
    const level = document.getElementById('filter-level').value;
    const isRead = document.getElementById('filter-read').value;
    const dateFrom = document.getElementById('filter-from').value;
    const dateTo = document.getElementById('filter-to').value;

    if (type) params.append('type', type);
    if (level) params.append('level', level);
    if (isRead !== '') params.append('is_read', isRead);
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);

    try {
        const response = await fetch(`/owner/alerts?${params}`);
        const data = await response.json();

        if (data.success) {
            renderAlerts(data.data);
            renderPagination(data.meta);
        }
    } catch (error) {
        container.innerHTML = `
            <div class="alert alert-danger m-3">
                Failed to load alerts: ${error.message}
            </div>
        `;
    }
}

// Render alerts
function renderAlerts(alerts) {
    const container = document.getElementById('alerts-container');

    if (alerts.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                <p class="text-muted">No alerts found</p>
            </div>
        `;
        return;
    }

    const html = alerts.map(alert => `
        <div class="alert-card p-3 border-bottom ${alert.is_read ? '' : 'unread'} level-${alert.level}" 
             onclick="showAlertDetail(${alert.id})" style="cursor: pointer;">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center mb-2">
                        <span class="badge badge-${alert.level} me-2">${alert.level.toUpperCase()}</span>
                        <span class="badge bg-secondary me-2">${alert.type}</span>
                        ${!alert.is_read ? '<span class="badge bg-primary">NEW</span>' : ''}
                        ${alert.occurrence_count > 1 ? `<span class="badge bg-info ms-1">x${alert.occurrence_count}</span>` : ''}
                    </div>
                    <h6 class="mb-1">${alert.title}</h6>
                    <p class="text-muted mb-0 small">${alert.message.substring(0, 100)}...</p>
                </div>
                <div class="text-end">
                    <small class="text-muted">${new Date(alert.created_at).toLocaleString()}</small>
                    <div class="mt-1">
                        ${alert.telegram_sent ? '<i class="fab fa-telegram text-info" title="Telegram sent"></i>' : ''}
                        ${alert.email_sent ? '<i class="fas fa-envelope text-success" title="Email sent"></i>' : ''}
                        ${alert.is_acknowledged ? '<i class="fas fa-check-circle text-success" title="Acknowledged"></i>' : ''}
                    </div>
                </div>
            </div>
        </div>
    `).join('');

    container.innerHTML = html;
}

// Render pagination
function renderPagination(meta) {
    const container = document.getElementById('pagination-container');
    
    if (meta.last_page <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '<nav><ul class="pagination">';
    
    for (let i = 1; i <= meta.last_page; i++) {
        html += `
            <li class="page-item ${i === meta.current_page ? 'active' : ''}">
                <a class="page-link" href="#" onclick="loadAlerts(${i}); return false;">${i}</a>
            </li>
        `;
    }

    html += '</ul></nav>';
    container.innerHTML = html;
}

// Show alert detail
async function showAlertDetail(id) {
    currentAlertId = id;
    
    try {
        const response = await fetch(`/owner/alerts/${id}`);
        const data = await response.json();

        if (data.success) {
            const alert = data.data;
            
            document.getElementById('modal-title').innerHTML = `
                <span class="badge badge-${alert.level} me-2">${alert.level.toUpperCase()}</span>
                ${alert.title}
            `;

            document.getElementById('modal-body').innerHTML = `
                <div class="mb-3">
                    <strong>Type:</strong> ${alert.type}<br>
                    <strong>Time:</strong> ${new Date(alert.created_at).toLocaleString()}
                </div>
                <div class="mb-3">
                    <strong>Message:</strong>
                    <pre class="bg-light p-3 rounded" style="white-space: pre-wrap;">${alert.message}</pre>
                </div>
                ${alert.context ? `
                <div class="mb-3">
                    <strong>Context:</strong>
                    <pre class="bg-light p-3 rounded">${JSON.stringify(alert.context, null, 2)}</pre>
                </div>
                ` : ''}
                <div class="row text-center">
                    <div class="col">
                        <i class="fab fa-telegram fa-2x ${alert.telegram_sent ? 'text-success' : 'text-muted'}"></i>
                        <p class="small mb-0">${alert.telegram_sent ? 'Sent' : 'Not sent'}</p>
                    </div>
                    <div class="col">
                        <i class="fas fa-envelope fa-2x ${alert.email_sent ? 'text-success' : 'text-muted'}"></i>
                        <p class="small mb-0">${alert.email_sent ? 'Sent' : 'Not sent'}</p>
                    </div>
                    <div class="col">
                        <i class="fas fa-check-circle fa-2x ${alert.is_acknowledged ? 'text-success' : 'text-muted'}"></i>
                        <p class="small mb-0">${alert.is_acknowledged ? 'Acknowledged' : 'Not acked'}</p>
                    </div>
                </div>
            `;

            // Hide acknowledge button if already acknowledged
            document.getElementById('btn-acknowledge').style.display = alert.is_acknowledged ? 'none' : 'block';

            // Mark as read
            fetch(`/owner/alerts/${id}/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }});

            new bootstrap.Modal(document.getElementById('alertDetailModal')).show();
        }
    } catch (error) {
        console.error('Failed to load alert detail:', error);
    }
}

// Acknowledge alert
async function acknowledgeAlert() {
    if (!currentAlertId) return;

    const { value: note } = await Swal.fire({
        title: 'Acknowledge Alert',
        input: 'textarea',
        inputLabel: 'Add a note (optional)',
        inputPlaceholder: 'Enter your note here...',
        showCancelButton: true,
        confirmButtonText: 'Acknowledge',
        cancelButtonText: 'Cancel',
        customClass: {
            confirmButton: 'btn btn-warning text-dark px-4',
            cancelButton: 'btn btn-secondary px-4'
        },
        buttonsStyling: false
    });

    if (note === undefined) return; // Cancelled

    OwnerPopup.loading('Processing...');

    try {
        const response = await fetch(`/owner/alerts/${currentAlertId}/ack`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ note: note })
        });

        const data = await response.json();
        
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('alertDetailModal')).hide();
            OwnerPopup.toast('Alert acknowledged!', 'success');
            loadAlerts(currentPage);
            loadStats();
        } else {
            OwnerPopup.error(data.message || 'Failed to acknowledge');
        }
    } catch (error) {
        OwnerPopup.error('Failed to acknowledge: ' + error.message);
    }
}

// Mark all as read
async function markAllRead() {
    const confirmed = await OwnerPopup.confirmWarning({
        title: 'Mark All as Read?',
        text: 'Semua alert akan ditandai sebagai sudah dibaca.',
        confirmText: '<i class="fas fa-check-double me-1"></i> Ya, Mark All Read'
    });

    if (!confirmed) return;

    OwnerPopup.loading('Processing...');

    try {
        const response = await fetch('/owner/alerts/read-all', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        const data = await response.json();
        
        if (data.success) {
            OwnerPopup.toast('All alerts marked as read!', 'success');
            loadAlerts(currentPage);
        } else {
            OwnerPopup.error(data.message || 'Failed');
        }
    } catch (error) {
        OwnerPopup.error('Failed: ' + error.message);
    }
}

// Test Telegram
async function testTelegram() {
    OwnerPopup.loading('Sending test...');
    try {
        const response = await fetch('/owner/alerts/test/telegram', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        const data = await response.json();
        if (data.success) {
            OwnerPopup.success('Telegram test sent!');
        } else {
            OwnerPopup.error('Failed: ' + data.message);
        }
    } catch (error) {
        OwnerPopup.error('Error: ' + error.message);
    }
}

// Test Email
async function testEmail() {
    OwnerPopup.loading('Sending test...');
    try {
        const response = await fetch('/owner/alerts/test/email', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        const data = await response.json();
        if (data.success) {
            OwnerPopup.success('Email test sent!');
        } else {
            OwnerPopup.error('Failed: ' + data.message);
        }
    } catch (error) {
        OwnerPopup.error('Error: ' + error.message);
    }
}
</script>
@endpush
