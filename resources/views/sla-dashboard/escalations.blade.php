@extends('layouts.app')

@section('title', 'Escalation Management - SLA Dashboard')

@push('styles')
<style>
  .escalation-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    transition: all 0.3s ease;
    border-left: 4px solid;
  }
  
  .escalation-critical {
    border-left-color: #dc3545;
  }
  
  .escalation-high {
    border-left-color: #fd7e14;
  }
  
  .escalation-medium {
    border-left-color: #ffc107;
  }
  
  .escalation-low {
    border-left-color: #28a745;
  }
  
  .escalation-status {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
  }
  
  .status-pending {
    background: #fff3cd;
    color: #856404;
  }
  
  .status-assigned {
    background: #d4edda;
    color: #155724;
  }
  
  .status-resolved {
    background: #d1ecf1;
    color: #0c5460;
  }
  
  .time-indicator {
    font-size: 0.875rem;
    font-weight: 500;
  }
  
  .time-critical {
    color: #dc3545;
  }
  
  .time-warning {
    color: #fd7e14;
  }
  
  .time-normal {
    color: #28a745;
  }
  
  .escalation-timeline {
    position: relative;
    padding-left: 30px;
  }
  
  .escalation-timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e5e7eb;
  }
  
  .timeline-item {
    position: relative;
    margin-bottom: 20px;
    background: white;
    padding: 15px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
  }
  
  .timeline-item::before {
    content: '';
    position: absolute;
    left: -23px;
    top: 15px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: white;
    border: 3px solid;
  }
  
  .timeline-created::before {
    border-color: #dc3545;
  }
  
  .timeline-assigned::before {
    border-color: #ffc107;
  }
  
  .timeline-resolved::before {
    border-color: #28a745;
  }
  
  .filter-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
  }
  
  .metric-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    border: 1px solid #e5e7eb;
  }
  
  .metric-value {
    font-size: 1.875rem;
    font-weight: 700;
    line-height: 1;
  }
  
  .metric-label {
    font-size: 0.875rem;
    color: #64748b;
    margin-top: 4px;
  }
  
  .metric-trend {
    font-size: 0.75rem;
    font-weight: 600;
    margin-top: 8px;
  }
</style>
@endpush

@section('auth')
  @include('layouts.navbars.auth.sidebar')
  
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg">
    @include('layouts.navbars.auth.nav')

    <div class="container-fluid py-4">
      <!-- Header -->
      <div class="row mb-4">
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <h1 class="mb-1">Escalation Management</h1>
              <p class="text-muted mb-0">Monitor and manage SLA escalations across all support channels</p>
            </div>
            <div>
              <a href="{{ route('sla-dashboard.index') }}" class="btn btn-outline-primary me-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
              </a>
              <button class="btn btn-success" onclick="exportEscalations()">
                <i class="fas fa-download"></i> Export Report
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-section">
        <form method="GET" action="{{ route('sla-dashboard.escalations') }}">
          <div class="row align-items-end">
            <div class="col-md-2">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="">All Status</option>
                <option value="pending" {{ ($filters['status'] ?? '') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="assigned" {{ ($filters['status'] ?? '') === 'assigned' ? 'selected' : '' }}>Assigned</option>
                <option value="resolved" {{ ($filters['status'] ?? '') === 'resolved' ? 'selected' : '' }}>Resolved</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Priority</label>
              <select name="priority" class="form-select">
                <option value="">All Priorities</option>
                <option value="critical" {{ ($filters['priority'] ?? '') === 'critical' ? 'selected' : '' }}>Critical</option>
                <option value="high" {{ ($filters['priority'] ?? '') === 'high' ? 'selected' : '' }}>High</option>
                <option value="medium" {{ ($filters['priority'] ?? '') === 'medium' ? 'selected' : '' }}>Medium</option>
                <option value="low" {{ ($filters['priority'] ?? '') === 'low' ? 'selected' : '' }}>Low</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Package</label>
              <select name="package_level" class="form-select">
                <option value="">All Packages</option>
                <option value="starter" {{ ($filters['package_level'] ?? '') === 'starter' ? 'selected' : '' }}>Starter</option>
                <option value="professional" {{ ($filters['package_level'] ?? '') === 'professional' ? 'selected' : '' }}>Professional</option>
                <option value="enterprise" {{ ($filters['package_level'] ?? '') === 'enterprise' ? 'selected' : '' }}>Enterprise</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">Date From</label>
              <input type="date" name="date_from" class="form-control" 
                     value="{{ $filters['date_from'] ?? now()->subDays(7)->format('Y-m-d') }}">
            </div>
            <div class="col-md-2">
              <label class="form-label">Date To</label>  
              <input type="date" name="date_to" class="form-control" 
                     value="{{ $filters['date_to'] ?? now()->format('Y-m-d') }}">
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-filter"></i> Filter
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Escalation Metrics -->
      <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="metric-card">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-danger fa-2x"></i>
              </div>
              <div class="ms-3">
                <div class="metric-value text-danger">
                  {{ $escalationMetrics['total_escalations'] ?? 0 }}
                </div>
                <div class="metric-label">Total Escalations</div>
                @if(isset($escalationMetrics['escalation_trend']))
                <div class="metric-trend {{ $escalationMetrics['escalation_trend'] > 0 ? 'text-danger' : 'text-success' }}">
                  <i class="fas fa-arrow-{{ $escalationMetrics['escalation_trend'] > 0 ? 'up' : 'down' }}"></i>
                  {{ abs($escalationMetrics['escalation_trend']) }}% vs last period
                </div>
                @endif
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="metric-card">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <i class="fas fa-clock text-warning fa-2x"></i>
              </div>
              <div class="ms-3">
                <div class="metric-value text-warning">
                  {{ $escalationMetrics['pending_escalations'] ?? 0 }}
                </div>
                <div class="metric-label">Pending Action</div>
                <div class="metric-trend text-muted">
                  Avg age: {{ $escalationMetrics['avg_pending_age'] ?? 0 }}h
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="metric-card">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <i class="fas fa-chart-line text-primary fa-2x"></i>
              </div>
              <div class="ms-3">
                <div class="metric-value text-primary">
                  {{ number_format($escalationMetrics['resolution_rate'] ?? 0, 1) }}%
                </div>
                <div class="metric-label">Resolution Rate</div>
                <div class="metric-trend text-muted">
                  Avg time: {{ $escalationMetrics['avg_resolution_time'] ?? 0 }}h  
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="metric-card">
            <div class="d-flex align-items-center">
              <div class="flex-shrink-0">
                <i class="fas fa-users text-success fa-2x"></i>
              </div>
              <div class="ms-3">
                <div class="metric-value text-success">
                  {{ $escalationMetrics['active_agents'] ?? 0 }}
                </div>
                <div class="metric-label">Agents Involved</div>
                <div class="metric-trend text-muted">
                  {{ number_format($escalationMetrics['avg_workload'] ?? 0, 1) }} avg/agent
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Critical Escalations Alert -->
      @if(isset($escalationMetrics['critical_escalations']) && $escalationMetrics['critical_escalations'] > 0)
      <div class="row mb-4">
        <div class="col-12">
          <div class="alert alert-danger d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
            <div>
              <h5 class="mb-1">⚠️ Critical Escalations Require Immediate Attention</h5>
              <p class="mb-0">You have {{ $escalationMetrics['critical_escalations'] }} critical escalations that are overdue. Please review and take action immediately.</p>
            </div>
            <div class="ms-auto">
              <button class="btn btn-outline-light" onclick="filterCritical()">View Critical</button>
            </div>
          </div>
        </div>
      </div>
      @endif

      <!-- Escalation List -->
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">Active Escalations</h5>
              <p class="text-sm text-muted mb-0">Current escalations requiring attention</p>
            </div>
            <div class="card-body">
              @if(isset($escalations) && count($escalations) > 0)
              <div class="row">
                @foreach($escalations as $escalation)
                <div class="col-lg-6 mb-4">
                  <div class="escalation-card escalation-{{ strtolower($escalation['priority']) }}">
                    <div class="card-body">
                      <!-- Header -->
                      <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                          <h6 class="mb-1">Escalation #{{ $escalation['id'] }}</h6>
                          <div class="d-flex align-items-center">
                            <span class="escalation-status status-{{ $escalation['status'] }}">
                              {{ ucfirst($escalation['status']) }}
                            </span>
                            <span class="badge badge-sm bg-{{ 
                              $escalation['priority'] === 'critical' ? 'danger' : (
                              $escalation['priority'] === 'high' ? 'warning' : (
                              $escalation['priority'] === 'medium' ? 'primary' : 'success'
                            )) }} text-white ms-2">
                              {{ ucfirst($escalation['priority']) }}
                            </span>
                          </div>
                        </div>
                        <div class="text-end">
                          <div class="time-indicator {{ $escalation['hours_elapsed'] > 24 ? 'time-critical' : ($escalation['hours_elapsed'] > 12 ? 'time-warning' : 'time-normal') }}">
                            <i class="fas fa-clock me-1"></i>
                            {{ $escalation['hours_elapsed'] }}h ago
                          </div>
                          
                        </div>
                      </div>

                      <!-- Ticket Info -->
                      <div class="mb-3">
                        <h6 class="text-muted mb-1">Related Ticket</h6>
                        <div class="d-flex align-items-center justify-content-between">
                          <div>
                            <strong>Ticket #{{ $escalation['ticket_id'] }}</strong>
                            <span class="text-muted"> • {{ $escalation['ticket_subject'] ?? 'No subject' }}</span>
                          </div>
                          <span class="badge bg-light text-dark">{{ ucfirst($escalation['package_level']) }}</span>
                        </div>
                      </div>

                      <!-- Customer Info -->
                      <div class="mb-3">
                        <h6 class="text-muted mb-1">Customer</h6>
                        <div>
                          <strong>{{ $escalation['customer_name'] ?? 'Unknown Customer' }}</strong>
                          <br>
                          <small class="text-muted">{{ $escalation['customer_email'] ?? 'No email' }}</small>
                        </div>
                      </div>

                      <!-- Escalation Details -->
                      <div class="mb-3">
                        <h6 class="text-muted mb-1">Escalation Reason</h6>
                        <p class="text-sm mb-2">{{ $escalation['reason'] ?? 'SLA breach detected' }}</p>
                        @if(isset($escalation['notes']) && $escalation['notes'])
                        <div class="bg-light p-2 rounded text-sm">
                          <strong>Notes:</strong> {{ $escalation['notes'] }}
                        </div>
                        @endif
                      </div>

                      <!-- Assignment -->
                      <div class="mb-3">
                        <h6 class="text-muted mb-1">Assignment</h6>
                        @if($escalation['assigned_to'])
                        <div class="d-flex align-items-center">
                          <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 0.75rem;">
                            {{ strtoupper(substr($escalation['assigned_to_name'], 0, 2)) }}
                          </div>
                          <div>
                            <strong>{{ $escalation['assigned_to_name'] }}</strong>
                            <br>
                            <small class="text-muted">Assigned {{ $escalation['hours_since_assignment'] ?? 0 }}h ago</small>
                          </div>
                        </div>
                        @else
                        <div class="text-warning">
                          <i class="fas fa-exclamation-triangle me-1"></i>
                          <strong>Unassigned</strong> - Awaiting agent assignment
                        </div>
                        @endif
                      </div>

                      <!-- Actions -->
                      <div class="d-flex justify-content-between">
                        <div>
                          <button class="btn btn-sm btn-outline-primary" onclick="viewEscalationDetails({{ $escalation['id'] }})">
                            <i class="fas fa-eye"></i> View Details
                          </button>
                          <button class="btn btn-sm btn-outline-secondary" onclick="viewTicket({{ $escalation['ticket_id'] }})">
                            <i class="fas fa-ticket-alt"></i> View Ticket
                          </button>
                        </div>
                        <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Actions
                          </button>
                          <ul class="dropdown-menu">
                            @if($escalation['status'] === 'pending')
                            <li><a class="dropdown-item" href="#" onclick="assignEscalation({{ $escalation['id'] }})">
                              <i class="fas fa-user-plus me-2"></i>Assign to Agent
                            </a></li>
                            @endif
                            @if($escalation['status'] !== 'resolved')
                            <li><a class="dropdown-item" href="#" onclick="resolveEscalation({{ $escalation['id'] }})">
                              <i class="fas fa-check me-2"></i>Mark Resolved
                            </a></li>
                            @endif
                            <li><a class="dropdown-item" href="#" onclick="addNote({{ $escalation['id'] }})">
                              <i class="fas fa-sticky-note me-2"></i>Add Note
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#" onclick="escalateHigher({{ $escalation['id'] }})">
                              <i class="fas fa-arrow-up me-2"></i>Escalate Further
                            </a></li>
                          </ul>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                @endforeach
              </div>

              <!-- Pagination -->
              @if(isset($pagination) && $pagination['total_pages'] > 1)
              <nav aria-label="Escalations pagination">
                <ul class="pagination justify-content-center">
                  <li class="page-item {{ $pagination['current_page'] <= 1 ? 'disabled' : '' }}">
                    <a class="page-link" href="?page={{ $pagination['current_page'] - 1 }}&{{ http_build_query(request()->except('page')) }}">Previous</a>
                  </li>
                  @for($i = max(1, $pagination['current_page'] - 2); $i <= min($pagination['total_pages'], $pagination['current_page'] + 2); $i++)
                  <li class="page-item {{ $i == $pagination['current_page'] ? 'active' : '' }}">
                    <a class="page-link" href="?page={{ $i }}&{{ http_build_query(request()->except('page')) }}">{{ $i }}</a>
                  </li>
                  @endfor
                  <li class="page-item {{ $pagination['current_page'] >= $pagination['total_pages'] ? 'disabled' : '' }}">
                    <a class="page-link" href="?page={{ $pagination['current_page'] + 1 }}&{{ http_build_query(request()->except('page')) }}">Next</a>
                  </li>
                </ul>
              </nav>
              @endif

              @else
              <div class="text-center py-4">
                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                <h5 class="text-muted">No Active Escalations</h5>
                <p class="text-muted">Great job! There are currently no escalations requiring attention.</p>
              </div>
              @endif
            </div>
          </div>
        </div>
      </div>

      <!-- Escalation Timeline Modal -->
      <div class="modal fade" id="escalationTimelineModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Escalation Timeline</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="escalationTimelineContent">
                <!-- Timeline content will be loaded here -->
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
@endsection

@push('scripts')
<script>
function filterCritical() {
    const url = new URL(window.location);
    url.searchParams.set('priority', 'critical');
    url.searchParams.set('status', 'pending');
    window.location.href = url.toString();
}

function viewEscalationDetails(escalationId) {
    // Load escalation timeline
    fetch(`/sla-dashboard/escalations/${escalationId}/timeline`)
        .then(response => response.json())
        .then(data => {
            let timelineHtml = '<div class="escalation-timeline">';
            
            data.timeline.forEach(event => {
                timelineHtml += `
                    <div class="timeline-item timeline-${event.type}">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">${event.title}</h6>
                                <p class="text-sm text-muted mb-1">${event.description}</p>
                                ${event.agent ? `<small class="text-muted">by ${event.agent}</small>` : ''}
                            </div>
                            <small class="text-muted">${event.timestamp}</small>
                        </div>
                    </div>
                `;
            });
            
            timelineHtml += '</div>';
            
            document.getElementById('escalationTimelineContent').innerHTML = timelineHtml;
            new bootstrap.Modal(document.getElementById('escalationTimelineModal')).show();
        })
        .catch(error => {
            console.error('Error loading escalation timeline:', error);
            alert('Error loading escalation details. Please try again.');
        });
}

function viewTicket(ticketId) {
    window.open(`/sla-support/tickets/${ticketId}`, '_blank');
}

function assignEscalation(escalationId) {
    // Implementation for assigning escalation to agent
    const agentId = prompt('Enter agent ID to assign this escalation:');
    if (agentId) {
        fetch(`/sla-dashboard/escalations/${escalationId}/assign`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                agent_id: agentId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Escalation assigned successfully!');
                window.location.reload();
            } else {
                alert('Error assigning escalation: ' + data.message);
            }
        });
    }
}

function resolveEscalation(escalationId) {
    if (confirm('Are you sure you want to mark this escalation as resolved?')) {
        const resolution = prompt('Enter resolution notes:');
        
        fetch(`/sla-dashboard/escalations/${escalationId}/resolve`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                resolution: resolution
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Escalation resolved successfully!');
                window.location.reload();
            } else {
                alert('Error resolving escalation: ' + data.message);
            }
        });
    }
}

function addNote(escalationId) {
    const note = prompt('Enter your note:');
    if (note) {
        fetch(`/sla-dashboard/escalations/${escalationId}/notes`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({
                note: note
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Note added successfully!');
                window.location.reload();
            } else {
                alert('Error adding note: ' + data.message);
            }
        });
    }
}

function escalateHigher(escalationId) {
    if (confirm('This will escalate to the next management level. Continue?')) {
        fetch(`/sla-dashboard/escalations/${escalationId}/escalate-higher`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Escalation has been escalated to higher management!');
                window.location.reload();
            } else {
                alert('Error escalating: ' + data.message);
            }
        });
    }
}

function exportEscalations() {
    const params = new URLSearchParams(window.location.search);
    params.append('format', 'xlsx');
    params.append('type', 'escalations');
    
    window.open(`/sla-dashboard/export?${params}`, '_blank');
}
</script>
@endpush