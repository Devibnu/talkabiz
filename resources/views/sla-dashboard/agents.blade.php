@extends('layouts.app')

@section('title', 'Agent Performance - SLA Dashboard')

@push('styles')
<style>
  .performance-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
  }
  
  .performance-score {
    font-size: 2rem;
    font-weight: 700;
  }
  
  .score-excellent { color: #10b981; }
  .score-good { color: #3b82f6; }  
  .score-average { color: #f59e0b; }
  .score-poor { color: #ef4444; }
  
  .metric-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.875rem;
    font-weight: 600;
  }
  
  .compliance-bar {
    height: 8px;
    border-radius: 4px;
    background: #e5e7eb;
    overflow: hidden;
  }
  
  .compliance-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
  }
  
  .agent-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
  }
  
  .filter-section {
    background: #f8fafc;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
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
              <h1 class="mb-1">Agent Performance Analytics</h1>
              <p class="text-muted mb-0">Detailed SLA compliance metrics for support agents</p>
            </div>
            <div>
              <a href="{{ route('sla-dashboard.index') }}" class="btn btn-outline-primary me-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
              </a>
              <button class="btn btn-primary" onclick="exportAgentReport()">
                <i class="fas fa-download"></i> Export Report
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-section">
        <form method="GET" action="{{ route('sla-dashboard.agents') }}">
          <div class="row align-items-end">
            <div class="col-md-3">
              <label class="form-label">Date From</label>
              <input type="date" name="date_from" class="form-control" 
                     value="{{ $filters['date_from'] ?? now()->subDays(30)->format('Y-m-d') }}">
            </div>
            <div class="col-md-3">
              <label class="form-label">Date To</label>
              <input type="date" name="date_to" class="form-control" 
                     value="{{ $filters['date_to'] ?? now()->format('Y-m-d') }}">
            </div>
            <div class="col-md-3">
              <label class="form-label">Package Level</label>
              <select name="package_level" class="form-select">
                <option value="">All Packages</option>
                <option value="starter" {{ ($filters['package_level'] ?? '') === 'starter' ? 'selected' : '' }}>Starter</option>
                <option value="professional" {{ ($filters['package_level'] ?? '') === 'professional' ? 'selected' : '' }}>Professional</option>
                <option value="enterprise" {{ ($filters['package_level'] ?? '') === 'enterprise' ? 'selected' : '' }}>Enterprise</option>
              </select>
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-filter"></i> Apply Filters
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- Performance Summary -->
      <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="performance-card p-4">
            <div class="text-center">
              <i class="fas fa-users text-primary fa-2x mb-3"></i>
              <h3 class="mb-1">{{ $agentMetrics['summary']['total_agents'] ?? 0 }}</h3>
              <p class="text-muted mb-0">Total Agents</p>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="performance-card p-4">
            <div class="text-center">
              <i class="fas fa-chart-line text-success fa-2x mb-3"></i>
              <h3 class="mb-1">{{ number_format($agentMetrics['summary']['avg_compliance_rate'] ?? 0, 1) }}%</h3>
              <p class="text-muted mb-0">Avg Compliance Rate</p>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="performance-card p-4">
            <div class="text-center">
              <i class="fas fa-clock text-warning fa-2x mb-3"></i>
              <h3 class="mb-1">{{ $agentMetrics['summary']['avg_resolution_time'] ?? 0 }}m</h3>
              <p class="text-muted mb-0">Avg Resolution Time</p>
            </div>
          </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
          <div class="performance-card p-4">
            <div class="text-center">
              <i class="fas fa-trophy text-warning fa-2x mb-3"></i>
              <h3 class="mb-1">{{ count($agentMetrics['summary']['top_performers'] ?? []) }}</h3>
              <p class="text-muted mb-0">Top Performers</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Top Performers -->
      @if(isset($agentMetrics['summary']['top_performers']) && count($agentMetrics['summary']['top_performers']) > 0)
      <div class="row mb-4">
        <div class="col-12">
          <div class="performance-card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div>
                <h5 class="mb-0">🏆 Top Performers</h5>
                <p class="text-sm text-muted mb-0">Agents with highest performance scores</p>
              </div>
            </div>
            <div class="card-body">
              <div class="row">
                @foreach($agentMetrics['summary']['top_performers'] as $index => $agent)
                <div class="col-md-4 mb-3">
                  <div class="d-flex align-items-center p-3 border rounded-lg">
                    <div class="agent-avatar me-3">
                      {{ strtoupper(substr($agent['agent_name'], 0, 2)) }}
                    </div>
                    <div class="flex-grow-1">
                      <h6 class="mb-1">{{ $agent['agent_name'] }}</h6>
                      <div class="d-flex align-items-center">
                        <span class="performance-score score-{{ $agent['performance_score'] >= 90 ? 'excellent' : ($agent['performance_score'] >= 80 ? 'good' : ($agent['performance_score'] >= 60 ? 'average' : 'poor')) }} me-2">
                          {{ $agent['performance_score'] }}%
                        </span>
                        <i class="fas fa-medal text-warning"></i>
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
      @endif

      <!-- Detailed Agent List -->
      <div class="row">
        <div class="col-12">
          <div class="performance-card">
            <div class="card-header">
              <h5 class="mb-0">All Agents Performance</h5>
              <p class="text-sm text-muted mb-0">Complete performance breakdown</p>
            </div>
            <div class="card-body">
              @if(isset($agentMetrics['agents']) && count($agentMetrics['agents']) > 0)
              <div class="table-responsive">
                <table class="table" id="agentsTable">
                  <thead>
                    <tr>
                      <th>Agent</th>
                      <th>Tickets</th>
                      <th>Compliance Rate</th>
                      <th>Response Time</th>
                      <th>Resolution Time</th>
                      <th>Escalations</th>
                      <th>Satisfaction</th>
                      <th>Performance Score</th>
                      <th>Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($agentMetrics['agents'] as $agent)
                    <tr>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="agent-avatar me-3">
                            {{ strtoupper(substr($agent['agent_name'], 0, 2)) }}
                          </div>
                          <div>
                            <h6 class="mb-0">{{ $agent['agent_name'] }}</h6>
                            <small class="text-muted">ID: {{ $agent['agent_id'] }}</small>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div>
                          <strong>{{ $agent['total_tickets'] }}</strong> total
                          <br>
                          <small class="text-success">{{ $agent['resolved_tickets'] }} resolved</small>
                        </div>
                      </td>
                      <td>
                        <div class="mb-2">
                          <span class="font-weight-bold {{ $agent['sla_compliance_rate'] >= 90 ? 'text-success' : ($agent['sla_compliance_rate'] >= 80 ? 'text-primary' : ($agent['sla_compliance_rate'] >= 60 ? 'text-warning' : 'text-danger')) }}">
                            {{ number_format($agent['sla_compliance_rate'], 1) }}%
                          </span>
                        </div>
                        <div class="compliance-bar">
                          <div class="compliance-fill bg-{{ $agent['sla_compliance_rate'] >= 90 ? 'success' : ($agent['sla_compliance_rate'] >= 80 ? 'primary' : ($agent['sla_compliance_rate'] >= 60 ? 'warning' : 'danger')) }}" 
                               style="width: {{ $agent['sla_compliance_rate'] }}%"></div>
                        </div>
                      </td>
                      <td>
                        <span class="metric-badge bg-light text-dark">
                          {{ $agent['response_time_avg'] }}m
                        </span>
                      </td>
                      <td>
                        <span class="metric-badge bg-light text-dark">
                          {{ $agent['avg_resolution_time'] }}m
                        </span>
                      </td>
                      <td>
                        <span class="metric-badge {{ $agent['escalations_created'] > 5 ? 'bg-danger text-white' : ($agent['escalations_created'] > 2 ? 'bg-warning text-white' : 'bg-success text-white') }}">
                          {{ $agent['escalations_created'] }}
                        </span>
                      </td>
                      <td>
                        <div class="text-center">
                          <span class="font-weight-bold {{ $agent['customer_satisfaction'] >= 4.5 ? 'text-success' : ($agent['customer_satisfaction'] >= 4.0 ? 'text-primary' : ($agent['customer_satisfaction'] >= 3.5 ? 'text-warning' : 'text-danger')) }}">
                            {{ number_format($agent['customer_satisfaction'], 1) }}
                          </span>
                          <br>
                          <div class="text-warning">
                            @for($i = 1; $i <= 5; $i++)
                              <i class="fas fa-star{{ $i <= floor($agent['customer_satisfaction']) ? '' : ($i <= $agent['customer_satisfaction'] ? '-half-alt' : ' text-muted opacity-50') }}"></i>
                            @endfor
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="text-center">
                          <span class="performance-score score-{{ $agent['performance_score'] >= 90 ? 'excellent' : ($agent['performance_score'] >= 80 ? 'good' : ($agent['performance_score'] >= 60 ? 'average' : 'poor')) }}">
                            {{ number_format($agent['performance_score'], 1) }}%
                          </span>
                          <br>
                          <span class="badge badge-sm bg-{{ $agent['performance_score'] >= 90 ? 'success' : ($agent['performance_score'] >= 80 ? 'primary' : ($agent['performance_score'] >= 60 ? 'warning' : 'danger')) }}">
                            {{ $agent['performance_score'] >= 90 ? 'Excellent' : ($agent['performance_score'] >= 80 ? 'Good' : ($agent['performance_score'] >= 60 ? 'Average' : 'Needs Improvement')) }}
                          </span>
                        </div>
                      </td>
                      <td>
                        <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            Actions
                          </button>
                          <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="viewAgentDetails({{ $agent['agent_id'] }})">
                              <i class="fas fa-eye me-2"></i>View Details
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="viewAgentTickets({{ $agent['agent_id'] }})">
                              <i class="fas fa-ticket-alt me-2"></i>View Tickets
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="sendFeedback({{ $agent['agent_id'] }})">
                              <i class="fas fa-comment me-2"></i>Send Feedback
                            </a></li>
                          </ul>
                        </div>
                      </td>
                    </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
              @else
              <div class="text-center py-4">
                <i class="fas fa-users text-muted fa-3x mb-3"></i>
                <h5 class="text-muted">No agent data available</h5>
                <p class="text-muted">No performance metrics found for the selected filters.</p>
              </div>
              @endif
            </div>
          </div>
        </div>
      </div>

      <!-- Performance Improvement Recommendations -->
      @if(isset($agentMetrics['summary']['improvement_needed']) && count($agentMetrics['summary']['improvement_needed']) > 0)
      <div class="row mt-4">
        <div class="col-12">
          <div class="performance-card">
            <div class="card-header">
              <h5 class="mb-0">📈 Performance Improvement Recommendations</h5>
              <p class="text-sm text-muted mb-0">Agents who may benefit from additional training or support</p>
            </div>
            <div class="card-body">
              <div class="row">
                @foreach($agentMetrics['summary']['improvement_needed'] as $agent)
                <div class="col-md-6 mb-3">
                  <div class="p-3 border-left-danger border rounded">
                    <div class="d-flex align-items-center">
                      <div class="agent-avatar me-3">
                        {{ strtoupper(substr($agent['agent_name'], 0, 2)) }}
                      </div>
                      <div class="flex-grow-1">
                        <h6 class="mb-1">{{ $agent['agent_name'] }}</h6>
                        <small class="text-muted">
                          Compliance: {{ number_format($agent['sla_compliance_rate'], 1) }}% • 
                          Score: {{ number_format($agent['performance_score'], 1) }}%
                        </small>
                        <div class="mt-2">
                          @if($agent['sla_compliance_rate'] < 80)
                            <span class="badge badge-sm bg-warning text-white me-1">SLA Training</span>
                          @endif
                          @if($agent['escalations_created'] > 5)
                            <span class="badge badge-sm bg-danger text-white me-1">Escalation Review</span>
                          @endif
                          @if($agent['customer_satisfaction'] < 4.0)
                            <span class="badge badge-sm bg-info text-white me-1">Customer Service</span>
                          @endif
                        </div>
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
      @endif
    </div>
  </main>
@endsection

@push('scripts')
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#agentsTable').DataTable({
        pageLength: 25,
        order: [[7, 'desc']], // Sort by performance score descending
        columnDefs: [
            { orderable: false, targets: [8] } // Disable sorting on Actions column
        ],
        language: {
            search: "Search agents:",
            lengthMenu: "Show _MENU_ agents per page",
            info: "Showing _START_ to _END_ of _TOTAL_ agents",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        }
    });
});

function exportAgentReport() {
    const params = new URLSearchParams(window.location.search);
    params.append('format', 'xlsx');
    params.append('type', 'agent_performance');
    
    window.open(`/sla-dashboard/export?${params}`, '_blank');
}

function viewAgentDetails(agentId) {
    window.open(`/sla-dashboard/agents/${agentId}`, '_blank');
}

function viewAgentTickets(agentId) {
    window.open(`/sla-support/tickets?assigned_to=${agentId}`, '_blank');
}

function sendFeedback(agentId) {
    // Implementation for sending feedback to agent
    alert('Feedback functionality would be implemented here for agent ID: ' + agentId);
}
</script>
@endpush

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
@endpush