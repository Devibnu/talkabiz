@extends('layouts.user_type.auth')

@section('content')

<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-shield-alt text-danger me-2"></i>
                Abuse Monitor Panel
            </h3>
            <p class="text-sm text-secondary mb-0">Monitor dan kelola abuse scoring system</p>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Tracked</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['total_tracked'] }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card border-left-success">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-success">None</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['by_level']['none'] }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card border-left-info">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-info">Low</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['by_level']['low'] }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card border-left-warning">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-warning">Medium</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['by_level']['medium'] }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card border-left-danger">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-danger">High</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['by_level']['high'] }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-sm-6 mb-3">
            <div class="card border-left-dark">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-dark">Critical</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['by_level']['critical'] }}</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Content Card --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <div class="row">
                        <div class="col-md-6">
                            {{-- Filter Tabs --}}
                            <ul class="nav nav-tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link {{ $level === 'all' ? 'active' : '' }}" 
                                       href="{{ route('abuse-monitor.index', ['level' => 'all']) }}">
                                        All
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $level === 'critical' ? 'active' : '' }}" 
                                       href="{{ route('abuse-monitor.index', ['level' => 'critical']) }}">
                                        Critical
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $level === 'high' ? 'active' : '' }}" 
                                       href="{{ route('abuse-monitor.index', ['level' => 'high']) }}">
                                        High
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $level === 'medium' ? 'active' : '' }}" 
                                       href="{{ route('abuse-monitor.index', ['level' => 'medium']) }}">
                                        Medium
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            {{-- Search Form --}}
                            <form method="GET" class="d-flex justify-content-end">
                                <input type="hidden" name="level" value="{{ $level }}">
                                <input type="text" 
                                       name="search" 
                                       class="form-control form-control-sm me-2" 
                                       placeholder="Search by klien name..." 
                                       value="{{ $search }}"
                                       style="max-width: 250px;">
                                <button type="submit" class="btn btn-sm btn-primary mb-0">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    @if($abuseScores->isEmpty())
                        <div class="text-center py-5">
                            <i class="fas fa-inbox text-secondary" style="font-size: 3rem;"></i>
                            <p class="text-secondary mt-3">No abuse scores found</p>
                        </div>
                    @else
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Business Type</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Score</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Level</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Policy</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Last Event</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($abuseScores as $score)
                                    <tr>
                                        <td>
                                            <div class="d-flex px-3 py-1">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $score->klien->nama_perusahaan ?? 'N/A' }}</h6>
                                                    <p class="text-xs text-secondary mb-0">
                                                        <i class="fas fa-envelope me-1"></i>{{ $score->klien->user->email ?? '-' }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">{{ $score->klien->businessType->name ?? '-' }}</p>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-secondary text-xs font-weight-bold">{{ number_format($score->current_score, 1) }}</span>
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            <span class="badge badge-sm bg-gradient-{{ $score->getBadgeColor() }}">
                                                {{ $score->getLevelLabel() }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-xs">{{ $score->getActionLabel() }}</span>
                                        </td>
                                        <td class="align-middle text-center">
                                            @if($score->is_suspended)
                                                <span class="badge badge-sm bg-danger">Suspended</span>
                                            @else
                                                <span class="badge badge-sm bg-success">Active</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center">
                                            @if($score->last_event_at)
                                                <span class="text-xs">{{ $score->last_event_at->diffForHumans() }}</span>
                                            @else
                                                <span class="text-xs text-secondary">Never</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center">
                                            <button type="button" 
                                                    class="btn btn-sm btn-info mb-0 me-1 btn-view-detail"
                                                    data-klien-id="{{ $score->klien_id }}"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            <button type="button" 
                                                    class="btn btn-sm btn-warning mb-0 me-1 btn-reset-score"
                                                    data-klien-id="{{ $score->klien_id }}"
                                                    data-klien-name="{{ $score->klien->nama_perusahaan }}"
                                                    title="Reset Score">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                            
                                            @if(!$score->is_suspended)
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger mb-0 btn-suspend"
                                                        data-klien-id="{{ $score->klien_id }}"
                                                        data-klien-name="{{ $score->klien->nama_perusahaan }}"
                                                        title="Suspend">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            @else
                                                <button type="button" 
                                                        class="btn btn-sm btn-success mb-0 btn-approve"
                                                        data-klien-id="{{ $score->klien_id }}"
                                                        data-klien-name="{{ $score->klien->nama_perusahaan }}"
                                                        title="Approve/Unsuspend">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        <div class="px-3 py-3">
                            {{ $abuseScores->appends(['level' => $level, 'search' => $search])->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Recent High-Risk Events --}}
    @if($recentHighRiskEvents->isNotEmpty())
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Recent High-Risk Events</h6>
                </div>
                <div class="card-body p-3">
                    <div class="timeline timeline-one-side">
                        @foreach($recentHighRiskEvents as $event)
                        <div class="timeline-block mb-3">
                            <span class="timeline-step">
                                <i class="fas fa-exclamation-triangle text-{{ $event->severity === 'critical' ? 'danger' : 'warning' }}"></i>
                            </span>
                            <div class="timeline-content">
                                <h6 class="text-dark text-sm font-weight-bold mb-0">
                                    {{ $event->signal_type }}: {{ $event->klien->nama_perusahaan ?? 'Unknown' }}
                                </h6>
                                <p class="text-secondary font-weight-normal text-xs mt-1 mb-0">
                                    {{ $event->detected_at->diffForHumans() }} • {{ $event->abuse_points }} points
                                </p>
                                <p class="text-xs text-secondary mb-0 mt-1">
                                    {{ $event->description }}
                                </p>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- Modal: Detail Abuse Score & Events --}}
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Abuse Score Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="detailContent">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Action Confirmation (Reset/Suspend/Approve) --}}
<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="actionModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="actionKlienId">
                <input type="hidden" id="actionType">
                
                <div class="alert alert-warning" id="actionWarning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span id="actionWarningText"></span>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        <span id="actionNotesLabel">Reason / Notes</span>
                        <span class="text-danger" id="notesRequired">*</span>
                    </label>
                    <textarea class="form-control" id="actionNotes" rows="4" 
                              placeholder="Enter reason or notes for this action"></textarea>
                    <div class="invalid-feedback" id="notesError"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnConfirmAction">Confirm</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // View Detail
    $('.btn-view-detail').on('click', function() {
        const klienId = $(this).data('klien-id');
        
        $('#detailContent').html(`
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `);
        
        $('#detailModal').modal('show');
        
        $.ajax({
            url: `/owner/abuse-monitor/${klienId}`,
            method: 'GET',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    let eventsHtml = '';
                    if (data.recent_events && data.recent_events.length > 0) {
                        eventsHtml = '<h6 class="mt-4">Recent Events (Last 20)</h6><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Time</th><th>Signal Type</th><th>Severity</th><th>Points</th><th>Description</th></tr></thead><tbody>';
                        data.recent_events.forEach(function(event) {
                            const severityClass = event.severity === 'critical' ? 'danger' : (event.severity === 'high' ? 'warning' : 'info');
                            eventsHtml += `
                                <tr>
                                    <td class="text-xs">${event.detected_at}</td>
                                    <td class="text-xs">${event.signal_type}</td>
                                    <td><span class="badge badge-sm bg-${severityClass}">${event.severity}</span></td>
                                    <td class="text-xs">${event.abuse_points}</td>
                                    <td class="text-xs">${event.description}</td>
                                </tr>
                            `;
                        });
                        eventsHtml += '</tbody></table></div>';
                    }
                    
                    $('#detailContent').html(`
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Klien Information</h6>
                                <p class="text-sm mb-2"><strong>Company:</strong> ${data.klien.nama_perusahaan}</p>
                                <p class="text-sm mb-2"><strong>Email:</strong> ${data.klien.email}</p>
                                <p class="text-sm mb-2"><strong>Business Type:</strong> ${data.klien.business_type}</p>
                                <p class="text-sm mb-2"><strong>Status:</strong> <span class="badge bg-${data.klien.status === 'aktif' ? 'success' : 'danger'}">${data.klien.status}</span></p>
                            </div>
                            <div class="col-md-6">
                                <h6>Abuse Score</h6>
                                <p class="text-sm mb-2"><strong>Current Score:</strong> ${data.abuse_score.current_score}</p>
                                <p class="text-sm mb-2"><strong>Level:</strong> <span class="badge bg-${data.abuse_score.badge_color}">${data.abuse_score.level_label}</span></p>
                                <p class="text-sm mb-2"><strong>Policy Action:</strong> ${data.abuse_score.action_label}</p>
                                <p class="text-sm mb-2"><strong>Suspended:</strong> ${data.abuse_score.is_suspended ? 'YES' : 'NO'}</p>
                                <p class="text-sm mb-2"><strong>Last Event:</strong> ${data.abuse_score.last_event_at || 'Never'}</p>
                                <p class="text-sm mb-2"><strong>Days Since Last Event:</strong> ${data.abuse_score.days_since_last_event || 'N/A'}</p>
                                <p class="text-sm mb-2"><strong>Total Events:</strong> ${data.event_count}</p>
                            </div>
                        </div>
                        ${eventsHtml}
                    `);
                }
            },
            error: function() {
                $('#detailContent').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Failed to load details
                    </div>
                `);
            }
        });
    });

    // Reset Score
    $('.btn-reset-score').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Reset Abuse Score');
        $('#actionType').val('reset');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Reset abuse score for: ${klienName}. This will set score to 0.`);
        $('#actionWarning').removeClass('alert-danger').addClass('alert-warning');
        $('#actionNotesLabel').text('Reason for Reset');
        $('#notesRequired').show();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Suspend
    $('.btn-suspend').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Suspend Klien');
        $('#actionType').val('suspend');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Suspend klien: ${klienName}. This will block all actions.`);
        $('#actionWarning').removeClass('alert-warning').addClass('alert-danger');
        $('#actionNotesLabel').text('Reason for Suspension');
        $('#notesRequired').show();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Approve
    $('.btn-approve').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Approve / Unsuspend Klien');
        $('#actionType').val('approve');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Approve/unsuspend klien: ${klienName}. Account will be activated.`);
        $('#actionWarning').removeClass('alert-danger').addClass('alert-warning');
        $('#actionNotesLabel').text('Notes (Optional)');
        $('#notesRequired').hide();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Confirm Action
    $('#btnConfirmAction').on('click', function() {
        const actionType = $('#actionType').val();
        const klienId = $('#actionKlienId').val();
        const notes = $('#actionNotes').val().trim();
        
        // Validate required notes
        if ((actionType === 'reset' || actionType === 'suspend') && !notes) {
            $('#actionNotes').addClass('is-invalid');
            $('#notesError').text('Reason is required for this action');
            return;
        }
        
        $('#actionNotes').removeClass('is-invalid');
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
        
        const endpoints = {
            'reset': `/owner/abuse-monitor/${klienId}/reset-score`,
            'suspend': `/owner/abuse-monitor/${klienId}/suspend`,
            'approve': `/owner/abuse-monitor/${klienId}/approve`,
        };
        
        const dataPayload = actionType === 'reset' ? { reason: notes } : 
                           actionType === 'suspend' ? { reason: notes } : 
                           { notes: notes };
        
        $.ajax({
            url: endpoints[actionType],
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken },
            data: dataPayload,
            success: function(response) {
                if (response.success) {
                    $('#actionModal').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            },
            error: function(xhr) {
                let errorMsg = 'An error occurred';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                
                Swal.fire({
                    icon: 'error',
                    title: 'Failed!',
                    text: errorMsg,
                    confirmButtonText: 'OK'
                });
            },
            complete: function() {
                $('#btnConfirmAction').prop('disabled', false).text('Confirm');
            }
        });
    });
});
</script>
@endpush

@endsection
