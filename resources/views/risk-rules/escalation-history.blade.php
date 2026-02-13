@extends('layouts.user_type.auth')

@section('content')

<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-history text-info me-2"></i>
                Escalation History
            </h3>
            <p class="text-sm text-secondary mb-0">Riwayat escalation dan policy enforcement</p>
        </div>
    </div>

    {{-- Back Button --}}
    <div class="row mb-3">
        <div class="col-12">
            <a href="{{ route('risk-rules.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Risk Rules
            </a>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row mb-4">
        @foreach($summary as $item)
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-capitalize">{{ str_replace('_', ' ', $item->action_taken) }}</p>
                        <h4 class="font-weight-bolder mb-0">{{ $item->count }}</h4>
                        <p class="text-xs text-secondary mb-0">Avg Points: {{ number_format($item->avg_points, 2) }}</p>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('risk-rules.escalation-history') }}" class="row align-items-end">
                        <div class="col-md-3">
                            <label class="form-label text-sm">Time Period</label>
                            <select name="days" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="7" {{ $days == 7 ? 'selected' : '' }}>Last 7 Days</option>
                                <option value="14" {{ $days == 14 ? 'selected' : '' }}>Last 14 Days</option>
                                <option value="30" {{ $days == 30 ? 'selected' : '' }}>Last 30 Days</option>
                                <option value="60" {{ $days == 60 ? 'selected' : '' }}>Last 60 Days</option>
                                <option value="90" {{ $days == 90 ? 'selected' : '' }}>Last 90 Days</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-sm">Severity</label>
                            <select name="severity" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">All Severities</option>
                                <option value="low" {{ $severity == 'low' ? 'selected' : '' }}>Low</option>
                                <option value="medium" {{ $severity == 'medium' ? 'selected' : '' }}>Medium</option>
                                <option value="high" {{ $severity == 'high' ? 'selected' : '' }}>High</option>
                                <option value="critical" {{ $severity == 'critical' ? 'selected' : '' }}>Critical</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-sm btn-info" onclick="refreshData()">
                                <i class="fas fa-sync-alt me-1"></i> Refresh
                            </button>
                            <a href="{{ route('risk-rules.escalation-history') }}" class="btn btn-sm btn-secondary">
                                <i class="fas fa-times me-1"></i> Clear Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Events Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Escalation Events</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table table-hover align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Signal Type</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Severity</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Points</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Auto</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Detected At</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($events as $event)
                                <tr>
                                    <td>
                                        <div class="d-flex px-3 py-1">
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">{{ $event->klien->nama_perusahaan ?? 'N/A' }}</h6>
                                                <p class="text-xs text-secondary mb-0">ID: {{ $event->klien_id }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <p class="text-xs font-weight-bold mb-0">{{ $event->signal_type }}</p>
                                        <p class="text-xs text-secondary mb-0">{{ $event->rule_code }}</p>
                                    </td>
                                    <td class="align-middle">
                                        <span class="badge badge-sm badge-{{ 
                                            $event->severity === 'critical' ? 'danger' : 
                                            ($event->severity === 'high' ? 'warning' : 
                                            ($event->severity === 'medium' ? 'info' : 'secondary'))
                                        }}">
                                            {{ strtoupper($event->severity) }}
                                        </span>
                                    </td>
                                    <td class="align-middle text-center">
                                        <span class="text-secondary text-xs font-weight-bold">{{ $event->abuse_points }}</span>
                                    </td>
                                    <td class="align-middle text-center">
                                        <span class="badge badge-sm badge-primary">{{ $event->action_taken }}</span>
                                    </td>
                                    <td class="align-middle text-center">
                                        @if($event->auto_action)
                                            <i class="fas fa-robot text-success" title="Auto Action"></i>
                                        @else
                                            <i class="fas fa-user text-secondary" title="Manual Action"></i>
                                        @endif
                                    </td>
                                    <td class="align-middle text-center">
                                        <span class="text-secondary text-xs">{{ $event->detected_at->format('Y-m-d H:i') }}</span>
                                    </td>
                                    <td class="align-middle">
                                        <button class="btn btn-link text-secondary mb-0" 
                                                onclick="showEventDetail({{ $event->id }})"
                                                data-bs-toggle="tooltip" 
                                                title="View Details">
                                            <i class="fas fa-eye text-xs"></i>
                                        </button>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <p class="text-secondary mb-0">No escalation events found</p>
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if($events->hasPages())
                <div class="card-footer">
                    {{ $events->links() }}
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Event Detail Modal --}}
<div class="modal fade" id="eventDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Event Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="eventDetailContent">
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
function refreshData() {
    location.reload();
}

function showEventDetail(eventId) {
    const modal = new bootstrap.Modal(document.getElementById('eventDetailModal'));
    modal.show();
    
    $('#eventDetailContent').html('<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>');
    
    $.ajax({
        url: '{{ route("abuse-monitor.show", ":id") }}'.replace(':id', eventId),
        method: 'GET',
        success: function(response) {
            if (response.success && response.event) {
                const event = response.event;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-sm">Basic Information</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td class="text-sm"><strong>Event ID:</strong></td>
                                    <td class="text-sm">${event.id}</td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Klien:</strong></td>
                                    <td class="text-sm">${event.klien_name || 'N/A'}</td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Signal Type:</strong></td>
                                    <td class="text-sm">${event.signal_type}</td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Severity:</strong></td>
                                    <td><span class="badge badge-${event.severity === 'critical' ? 'danger' : (event.severity === 'high' ? 'warning' : 'info')}">${event.severity.toUpperCase()}</span></td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Abuse Points:</strong></td>
                                    <td class="text-sm">${event.abuse_points}</td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Action Taken:</strong></td>
                                    <td><span class="badge badge-primary">${event.action_taken}</span></td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Auto Action:</strong></td>
                                    <td class="text-sm">${event.auto_action ? 'Yes' : 'No'}</td>
                                </tr>
                                <tr>
                                    <td class="text-sm"><strong>Detected At:</strong></td>
                                    <td class="text-sm">${event.detected_at}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-sm">Description</h6>
                            <p class="text-sm">${event.description || 'N/A'}</p>
                            
                            <h6 class="text-sm mt-3">Evidence</h6>
                            <pre class="bg-light p-2 text-xs">${JSON.stringify(event.evidence, null, 2)}</pre>
                        </div>
                    </div>
                `;
                $('#eventDetailContent').html(html);
            }
        },
        error: function() {
            $('#eventDetailContent').html('<div class="alert alert-danger">Failed to load event details</div>');
        }
    });
}
</script>
@endpush

@endsection
