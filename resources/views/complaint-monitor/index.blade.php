@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Spam & Complaint Monitor'])
    
    <div class="container-fluid py-4">
        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Total Complaints</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($stats['total_complaints']) }}
                                        @if($stats['today_complaints'] > 0)
                                            <span class="text-success text-sm font-weight-bolder">+{{ $stats['today_complaints'] }} today</span>
                                        @endif
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                                    <i class="ni ni-notification-70 text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Unprocessed</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($stats['total_unprocessed']) }}
                                        @if($stats['total_unprocessed'] > 10)
                                            <span class="text-warning text-sm font-weight-bolder">!</span>
                                        @endif
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-warning shadow-warning text-center rounded-circle">
                                    <i class="ni ni-time-alarm text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Critical</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($stats['total_critical']) }}
                                        @if($stats['total_high'] > 0)
                                            <span class="text-sm">+ {{ $stats['total_high'] }} high</span>
                                        @endif
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                                    <i class="ni ni-sound-wave text-lg opacity-10" aria-hidden="true"></i>
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
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">Affected Users</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($stats['affected_kliens']) }}
                                        <span class="text-sm">/ {{ $stats['unique_recipients'] }} recipients</span>
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                                    <i class="ni ni-single-02 text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Card -->
        <div class="card">
            <!-- Card Header with Filters -->
            <div class="card-header pb-0">
                <div class="row">
                    <div class="col-lg-6 col-7">
                        <h6>Spam & Complaint Monitor</h6>
                        <p class="text-sm mb-0">
                            Monitor and manage spam/abuse complaints from recipients
                        </p>
                    </div>
                    <div class="col-lg-6 col-5 text-end">
                        <button class="btn btn-icon btn-sm btn-primary" onclick="toggleFilters()">
                            <span class="btn-inner--icon"><i class="ni ni-settings-gear-65"></i></span>
                            <span class="btn-inner--text">Filters</span>
                        </button>
                        <a href="{{ route('complaint-monitor.export', request()->query()) }}" class="btn btn-icon btn-sm btn-success">
                            <span class="btn-inner--icon"><i class="ni ni-cloud-download-95"></i></span>
                            <span class="btn-inner--text">Export CSV</span>
                        </a>
                    </div>
                </div>

                <!-- Filter Panel (collapsible) -->
                <div id="filterPanel" class="row mt-3" style="display: none;">
                    <div class="col-12">
                        <form method="GET" action="{{ route('complaint-monitor.index') }}" id="filterForm">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <div class="row">
                                        <!-- Search -->
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Search</label>
                                            <input type="text" name="search" class="form-control form-control-sm" 
                                                   placeholder="Phone, name, message ID..." 
                                                   value="{{ request('search') }}">
                                        </div>

                                        <!-- User/Klien -->
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">User/Klien</label>
                                            <select name="klien_id" class="form-select form-select-sm">
                                                <option value="">All Users</option>
                                                @foreach($kliens as $klien)
                                                    <option value="{{ $klien->id }}" {{ request('klien_id') == $klien->id ? 'selected' : '' }}>
                                                        {{ $klien->nama_perusahaan }} ({{ $klien->email }})
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <!-- Recipient Phone -->
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Recipient Phone</label>
                                            <input type="text" name="recipient_phone" class="form-control form-control-sm" 
                                                   placeholder="628xxx..." 
                                                   value="{{ request('recipient_phone') }}">
                                        </div>

                                        <!-- Complaint Type -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Type</label>
                                            <select name="complaint_type" class="form-select form-select-sm">
                                                <option value="">All Types</option>
                                                <option value="spam" {{ request('complaint_type') == 'spam' ? 'selected' : '' }}>Spam</option>
                                                <option value="abuse" {{ request('complaint_type') == 'abuse' ? 'selected' : '' }}>Abuse</option>
                                                <option value="phishing" {{ request('complaint_type') == 'phishing' ? 'selected' : '' }}>Phishing</option>
                                                <option value="inappropriate" {{ request('complaint_type') == 'inappropriate' ? 'selected' : '' }}>Inappropriate</option>
                                                <option value="frequency" {{ request('complaint_type') == 'frequency' ? 'selected' : '' }}>Frequency</option>
                                                <option value="other" {{ request('complaint_type') == 'other' ? 'selected' : '' }}>Other</option>
                                            </select>
                                        </div>

                                        <!-- Severity -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Severity</label>
                                            <select name="severity" class="form-select form-select-sm">
                                                <option value="">All Levels</option>
                                                <option value="low" {{ request('severity') == 'low' ? 'selected' : '' }}>Low</option>
                                                <option value="medium" {{ request('severity') == 'medium' ? 'selected' : '' }}>Medium</option>
                                                <option value="high" {{ request('severity') == 'high' ? 'selected' : '' }}>High</option>
                                                <option value="critical" {{ request('severity') == 'critical' ? 'selected' : '' }}>Critical</option>
                                            </select>
                                        </div>

                                        <!-- Status -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Status</label>
                                            <select name="status" class="form-select form-select-sm">
                                                <option value="">All Status</option>
                                                <option value="unprocessed" {{ request('status') == 'unprocessed' ? 'selected' : '' }}>Unprocessed</option>
                                                <option value="processed" {{ request('status') == 'processed' ? 'selected' : '' }}>Processed</option>
                                            </select>
                                        </div>

                                        <!-- Provider -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Provider</label>
                                            <select name="provider_name" class="form-select form-select-sm">
                                                <option value="">All Providers</option>
                                                @foreach($providers as $provider)
                                                    <option value="{{ $provider }}" {{ request('provider_name') == $provider ? 'selected' : '' }}>
                                                        {{ ucfirst($provider) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <!-- Date From -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Date From</label>
                                            <input type="date" name="date_from" class="form-control form-control-sm" 
                                                   value="{{ request('date_from') }}">
                                        </div>

                                        <!-- Date To -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Date To</label>
                                            <input type="date" name="date_to" class="form-control form-control-sm" 
                                                   value="{{ request('date_to') }}">
                                        </div>

                                        <!-- Per Page -->
                                        <div class="col-md-3 mb-3">
                                            <label class="form-label">Per Page</label>
                                            <select name="per_page" class="form-select form-select-sm">
                                                <option value="10" {{ request('per_page') == 10 ? 'selected' : '' }}>10</option>
                                                <option value="25" {{ request('per_page', 25) == 25 ? 'selected' : '' }}>25</option>
                                                <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>50</option>
                                                <option value="100" {{ request('per_page') == 100 ? 'selected' : '' }}>100</option>
                                            </select>
                                        </div>

                                        <!-- Buttons -->
                                        <div class="col-md-3 mb-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-sm btn-primary me-2">Apply Filters</button>
                                            <a href="{{ route('complaint-monitor.index') }}" class="btn btn-sm btn-secondary">Clear</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Card Body with Table -->
            <div class="card-body px-0 pt-0 pb-2">
                @if($complaints->isEmpty())
                    <div class="text-center py-5">
                        <i class="ni ni-check-bold text-success" style="font-size: 4rem;"></i>
                        <h5 class="mt-3">No complaints found</h5>
                        <p class="text-sm text-muted">
                            @if(request()->hasAny(['search', 'klien_id', 'complaint_type', 'severity', 'status']))
                                Try adjusting your filters
                            @else
                                Great! No complaints have been reported yet.
                            @endif
                        </p>
                    </div>
                @else
                    <!-- Bulk Actions -->
                    <div class="px-3 mb-3">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                    <label class="form-check-label" for="selectAll">
                                        Select All (<span id="selectedCount">0</span> selected)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <button class="btn btn-sm btn-success" onclick="bulkMarkProcessed()" id="bulkProcessBtn" style="display: none;">
                                    <i class="ni ni-check-bold"></i> Mark Processed
                                </button>
                                <button class="btn btn-sm btn-warning" onclick="bulkDismiss()" id="bulkDismissBtn" style="display: none;">
                                    <i class="ni ni-fat-remove"></i> Dismiss
                                </button>
                                <button class="btn btn-sm btn-danger" onclick="bulkSuspend()" id="bulkSuspendBtn" style="display: none;">
                                    <i class="ni ni-button-pause"></i> Suspend Users
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width: 30px;"></th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">User/Klien</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Recipient</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Type</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Severity</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Source</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Score</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Received</th>
                                    <th class="text-secondary opacity-7">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($complaints as $complaint)
                                    <tr id="complaint-{{ $complaint->id }}">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input complaint-checkbox" 
                                                   value="{{ $complaint->id }}" 
                                                   onchange="updateBulkButtons()">
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">#{{ $complaint->id }}</p>
                                        </td>
                                        <td>
                                            <div class="d-flex px-2 py-1">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $complaint->klien ? $complaint->klien->nama_perusahaan : 'N/A' }}</h6>
                                                    <p class="text-xs text-secondary mb-0">{{ $complaint->klien ? $complaint->klien->email : '' }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">{{ $complaint->recipient_phone }}</p>
                                            @if($complaint->recipient_name)
                                                <p class="text-xs text-secondary mb-0">{{ $complaint->recipient_name }}</p>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge badge-sm bg-gradient-{{ $complaint->complaint_type === 'phishing' ? 'danger' : ($complaint->complaint_type === 'spam' ? 'warning' : 'info') }}">
                                                {{ $complaint->getTypeDisplayName() }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm {{ $complaint->getSeverityBadgeClass() }}">
                                                {{ ucfirst($complaint->severity) }}
                                            </span>
                                        </td>
                                        <td>
                                            <p class="text-xs mb-0">{{ ucfirst(str_replace('_', ' ', $complaint->complaint_source)) }}</p>
                                            <p class="text-xs text-secondary mb-0">{{ $complaint->provider_name ?? 'N/A' }}</p>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">{{ number_format($complaint->abuse_score_impact, 0) }}</p>
                                        </td>
                                        <td>
                                            @if($complaint->is_processed)
                                                <span class="badge badge-sm bg-gradient-success">
                                                    <i class="ni ni-check-bold"></i> Processed
                                                </span>
                                                @if($complaint->action_taken)
                                                    <p class="text-xxs text-secondary mb-0">{{ $complaint->action_taken }}</p>
                                                @endif
                                            @else
                                                <span class="badge badge-sm bg-gradient-warning">
                                                    <i class="ni ni-time-alarm"></i> Pending
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <p class="text-xs mb-0">{{ $complaint->complaint_received_at->format('Y-m-d') }}</p>
                                            <p class="text-xs text-secondary mb-0">{{ $complaint->complaint_received_at->format('H:i') }}</p>
                                        </td>
                                        <td class="align-middle">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-link text-secondary px-2 mb-0" 
                                                        onclick="showComplaintDetail({{ $complaint->id }})" 
                                                        title="View Detail">
                                                    <i class="ni ni-bold-right text-lg"></i>
                                                </button>
                                                
                                                @if(!$complaint->is_processed)
                                                    <button type="button" class="btn btn-link text-success px-2 mb-0" 
                                                            onclick="markAsProcessed({{ $complaint->id }})" 
                                                            title="Mark as Processed">
                                                        <i class="ni ni-check-bold text-lg"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-link text-danger px-2 mb-0" 
                                                            onclick="suspendKlien({{ $complaint->id }})" 
                                                            title="Suspend User">
                                                        <i class="ni ni-button-pause text-lg"></i>
                                                    </button>
                                                    
                                                    <button type="button" class="btn btn-link text-warning px-2 mb-0" 
                                                            onclick="dismissComplaint({{ $complaint->id }})" 
                                                            title="Dismiss">
                                                        <i class="ni ni-fat-remove text-lg"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="px-3 py-3">
                        {{ $complaints->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Complaint Detail Modal -->
    <div class="modal fade" id="complaintDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Complaint Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="complaintDetailContent">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Suspend Klien Modal -->
    <div class="modal fade" id="suspendKlienModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="suspendKlienForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Suspend User/Klien</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="suspendComplaintId">
                        
                        <div class="mb-3">
                            <label class="form-label">Suspension Duration (Days)</label>
                            <input type="number" class="form-control" id="suspensionDays" min="1" max="365" value="7" required>
                            <small class="text-muted">1-365 days</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Suspension</label>
                            <textarea class="form-control" id="suspensionReason" rows="3" required 
                                      placeholder="Explain why this user is being suspended..."></textarea>
                        </div>

                        <div class="alert alert-warning" role="alert">
                            <i class="ni ni-bell-55"></i>
                            <strong>Warning:</strong> This will temporarily suspend the user's account and prevent them from sending messages.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Suspend User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Dismiss Complaint Modal -->
    <div class="modal fade" id="dismissComplaintModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="dismissComplaintForm">
                    <div class="modal-header">
                        <h5 class="modal-title">Dismiss Complaint</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="dismissComplaintId">
                        
                        <div class="mb-3">
                            <label class="form-label">Reason for Dismissal</label>
                            <textarea class="form-control" id="dismissReason" rows="3" required 
                                      placeholder="Explain why this complaint is being dismissed as false positive..."></textarea>
                        </div>

                        <div class="alert alert-info" role="alert">
                            <i class="ni ni-bulb-61"></i>
                            This will mark the complaint as a false positive and may reduce the user's abuse score.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Dismiss Complaint</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @include('layouts.footers.auth.footer')
@endsection

@push('js')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Toggle filter panel
    function toggleFilters() {
        const panel = document.getElementById('filterPanel');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    }

    // Show filter panel if any filter is active
    @if(request()->hasAny(['search', 'klien_id', 'recipient_phone', 'complaint_type', 'severity', 'status', 'provider_name', 'date_from', 'date_to']))
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('filterPanel').style.display = 'block';
        });
    @endif

    // Select all checkboxes
    function toggleSelectAll() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.complaint-checkbox');
        checkboxes.forEach(cb => cb.checked = selectAll.checked);
        updateBulkButtons();
    }

    // Update bulk action buttons visibility
    function updateBulkButtons() {
        const checkboxes = document.querySelectorAll('.complaint-checkbox:checked');
        const count = checkboxes.length;
        
        document.getElementById('selectedCount').textContent = count;
        
        const buttons = ['bulkProcessBtn', 'bulkDismissBtn', 'bulkSuspendBtn'];
        buttons.forEach(btnId => {
            document.getElementById(btnId).style.display = count > 0 ? 'inline-block' : 'none';
        });
    }

    // Get selected complaint IDs
    function getSelectedIds() {
        const checkboxes = document.querySelectorAll('.complaint-checkbox:checked');
        return Array.from(checkboxes).map(cb => cb.value);
    }

    // Show complaint detail (load via AJAX)
    function showComplaintDetail(id) {
        const modal = new bootstrap.Modal(document.getElementById('complaintDetailModal'));
        document.getElementById('complaintDetailContent').innerHTML = `
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `;
        modal.show();

        fetch(`/owner/complaint-monitor/${id}`)
            .then(response => response.text())
            .then(html => {
                document.getElementById('complaintDetailContent').innerHTML = html;
            })
            .catch(error => {
                document.getElementById('complaintDetailContent').innerHTML = `
                    <div class="alert alert-danger">Failed to load complaint details</div>
                `;
            });
    }

    // Mark as processed
    function markAsProcessed(id) {
        Swal.fire({
            title: 'Mark as Processed?',
            text: 'This will mark the complaint as reviewed and processed.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Mark Processed',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`/owner/complaint-monitor/${id}/mark-processed`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Success!', data.message, 'success');
                        location.reload();
                    } else {
                        Swal.fire('Error!', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error!', 'Failed to process complaint', 'error');
                });
            }
        });
    }

    // Suspend klien
    function suspendKlien(id) {
        document.getElementById('suspendComplaintId').value = id;
        const modal = new bootstrap.Modal(document.getElementById('suspendKlienModal'));
        modal.show();
    }

    document.getElementById('suspendKlienForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const id = document.getElementById('suspendComplaintId').value;
        const days = document.getElementById('suspensionDays').value;
        const reason = document.getElementById('suspensionReason').value;

        fetch(`/owner/complaint-monitor/${id}/suspend-klien`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                suspension_days: days,
                suspension_reason: reason
            })
        })
        .then(response => response.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('suspendKlienModal')).hide();
            
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                location.reload();
            } else {
                Swal.fire('Error!', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error!', 'Failed to suspend user', 'error');
        });
    });

    // Dismiss complaint
    function dismissComplaint(id) {
        document.getElementById('dismissComplaintId').value = id;
        const modal = new bootstrap.Modal(document.getElementById('dismissComplaintModal'));
        modal.show();
    }

    document.getElementById('dismissComplaintForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const id = document.getElementById('dismissComplaintId').value;
        const reason = document.getElementById('dismissReason').value;

        fetch(`/owner/complaint-monitor/${id}/dismiss`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                dismiss_reason: reason
            })
        })
        .then(response => response.json())
        .then(data => {
            bootstrap.Modal.getInstance(document.getElementById('dismissComplaintModal')).hide();
            
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                location.reload();
            } else {
                Swal.fire('Error!', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error!', 'Failed to dismiss complaint', 'error');
        });
    });

    // Bulk actions
    function bulkMarkProcessed() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;

        Swal.fire({
            title: `Mark ${ids.length} Complaints as Processed?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Process All'
        }).then((result) => {
            if (result.isConfirmed) {
                performBulkAction('mark_processed', ids);
            }
        });
    }

    function bulkDismiss() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;

        Swal.fire({
            title: `Dismiss ${ids.length} Complaints?`,
            input: 'textarea',
            inputLabel: 'Reason for dismissal',
            inputPlaceholder: 'Enter reason...',
            showCancelButton: true,
            confirmButtonText: 'Dismiss All',
            inputValidator: (value) => {
                if (!value) return 'You need to provide a reason!';
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performBulkAction('dismiss', ids, result.value);
            }
        });
    }

    function bulkSuspend() {
        const ids = getSelectedIds();
        if (ids.length === 0) return;

        Swal.fire({
            title: `Suspend ${ids.length} Users?`,
            html: `
                <input type="number" id="bulk-days" class="swal2-input" placeholder="Days" min="1" max="365" value="7">
                <textarea id="bulk-reason" class="swal2-textarea" placeholder="Reason for suspension"></textarea>
            `,
            showCancelButton: true,
            confirmButtonText: 'Suspend All',
            preConfirm: () => {
                const days = document.getElementById('bulk-days').value;
                const reason = document.getElementById('bulk-reason').value;
                
                if (!days || days < 1) {
                    Swal.showValidationMessage('Please enter valid suspension days');
                    return false;
                }
                if (!reason) {
                    Swal.showValidationMessage('Please enter a reason');
                    return false;
                }
                
                return { days, reason };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                performBulkAction('suspend_klien', ids, result.value.reason, result.value.days);
            }
        });
    }

    function performBulkAction(action, ids, reason = null, days = null) {
        const body = {
            action: action,
            complaint_ids: ids
        };
        
        if (reason) body.reason = reason;
        if (days) body.suspension_days = days;

        fetch('/owner/complaint-monitor/bulk-action', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify(body)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Success!', data.message, 'success');
                location.reload();
            } else {
                Swal.fire('Error!', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.fire('Error!', 'Bulk action failed', 'error');
        });
    }
</script>
@endpush
