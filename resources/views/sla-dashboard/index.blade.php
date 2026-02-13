@extends('layouts.sla-dashboard')

@section('title', 'SLA Dashboard - Real-time Overview')

@push('styles')
<style>
  .sla-metric-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
  }
  
  .sla-metric-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.12);
  }
  
  .compliance-gauge {
    width: 120px;
    height: 120px;
  }
  
  .alert-card {
    border-left: 4px solid #dc3545;
    background: #fff5f5;
  }
  
  .alert-card.warning {
    border-left-color: #f59e0b;
    background: #fffbf0;
  }
  
  .alert-card.success {
    border-left-color: #10b981;
    background: #f0fdf4;
  }
  
  .trend-arrow {
    font-size: 0.875rem;
  }
  
  .trend-up { color: #10b981; }
  .trend-down { color: #dc3545; }
  .trend-stable { color: #6b7280; }
  
  .chart-container {
    min-height: 300px;
  }
  
  .real-time-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
    border: 1px solid #10b981;
    border-radius: 20px;
    padding: 4px 8px;
    font-size: 0.75rem;
    font-weight: 600;
  }
  
  .real-time-indicator {
    display: inline-block;
    width: 8px;
    height: 8px;
    background: #10b981;
    border-radius: 50%;
    margin-right: 6px;
    animation: blink 2s infinite;
  }
  
  @keyframes blink {
    0% { opacity: 1; }
    50% { opacity: 0.3; }
    100% { opacity: 1; }
  }
  
  .animate-pulse {
    animation: pulse 1s ease-in-out;
  }
  
  @keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
  }
  
  .real-time-indicator {
    animation: pulse 2s infinite;
    color: #10b981;
  }
  
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
  }
  
  .package-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
  }
  
  .package-starter { background: #dbeafe; color: #1d4ed8; }
  .package-professional { background: #f3e8ff; color: #7c3aed; }
  .package-enterprise { background: #fef3c7; color: #d97706; }
</style>
@endpush

@section('dashboard-content')
      <!-- Header Section -->
      <div class="row mb-4">
        <div class="col-12">
          <div class="page-header min-height-300 border-radius-xl mt-4" 
               style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <div class="container">
              <div class="row">
                <div class="col-lg-6 my-auto">
                  <h1 class="text-white mb-0">SLA Dashboard</h1>
                  <p class="text-white opacity-8 mb-0">
                    Real-time SLA compliance monitoring and analytics
                  </p>
                  <div class="mt-3">
                    <span class="real-time-indicator">
                      <i class="fas fa-circle"></i> Live Data
                    </span>
                    <span class="text-white ms-3">
                      <i class="fas fa-clock"></i> Last Updated: <span id="last-updated">{{ now()->format('H:i:s') }}</span>
                    </span>
                  </div>
                </div>
                <div class="col-lg-6 text-end">
                  <div class="mt-4">
                    <button class="btn btn-warning me-2" onclick="refreshDashboard()">
                      <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <button class="btn btn-light" onclick="exportReport()">
                      <i class="fas fa-download"></i> Export Report
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Key Metrics Row -->
      <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
          <div class="card sla-metric-card h-100 position-relative">
            <div class="real-time-badge">
              <span class="real-time-indicator"></span>LIVE
            </div>
            <div class="card-body p-4">
              <div class="row">
                <div class="col">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold text-muted">Compliance Rate</p>
                    <h5 class="font-weight-bolder mb-0">
                      <span id="overall-compliance">{{ $metrics['compliance_rate'] ?? 85 }}%</span>
                      <span class="trend-arrow trend-up ms-2">
                        <i class="fas fa-arrow-up"></i> 2.1%
                      </span>
                    </h5>
                  </div>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-gradient-success shadow-success text-center rounded-circle">
                    <i class="fas fa-chart-line text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
          <div class="card sla-metric-card h-100 position-relative">
            <div class="real-time-badge">
              <span class="real-time-indicator"></span>LIVE
            </div>
            <div class="card-body p-4">
              <div class="row">
                <div class="col">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold text-muted">Active Tickets</p>
                    <h5 class="font-weight-bolder mb-0">
                      <span id="active-tickets">{{ $metrics['active_tickets'] ?? 127 }}</span>
                      <small class="text-muted">/ {{ $metrics['total_tickets'] ?? 156 }} total</small>
                    </h5>
                  </div>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-gradient-primary shadow-primary text-center rounded-circle">
                    <i class="fas fa-ticket-alt text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
          <div class="card sla-metric-card h-100">
            <div class="card-body p-4">
              <div class="row">
                <div class="col">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold text-muted">SLA Breaches</p>
                    <h5 class="font-weight-bolder mb-0 text-danger">
                      <span id="sla-breaches">{{ $metrics['breaches'] ?? 8 }}</span>
                      <span class="trend-arrow trend-down ms-2">
                        <i class="fas fa-arrow-down"></i> 1.2%
                      </span>
                    </h5>
                  </div>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-gradient-danger shadow-danger text-center rounded-circle">
                    <i class="fas fa-exclamation-triangle text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
          <div class="card sla-metric-card h-100">
            <div class="card-body p-4">
              <div class="row">
                <div class="col">
                  <div class="numbers">
                    <p class="text-sm mb-0 text-capitalize font-weight-bold text-muted">Avg Response Time</p>
                    <h5 class="font-weight-bolder mb-0">
                      <span id="avg-response">{{ $metrics['avg_response_minutes'] ?? 42 }}</span> min
                      <span class="trend-arrow trend-stable ms-2">
                        <i class="fas fa-minus"></i> 0.3%
                      </span>
                    </h5>
                  </div>
                </div>
                <div class="col-auto">
                  <div class="icon icon-shape bg-gradient-info shadow-info text-center rounded-circle">
                    <i class="fas fa-clock text-lg opacity-10" aria-hidden="true"></i>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts and Analytics Row -->
      <div class="row mb-4">
        <!-- Compliance Trend Chart -->
        <div class="col-lg-8">
          <div class="card sla-metric-card h-100">
            <div class="card-header pb-0">
              <div class="row">
                <div class="col">
                  <h6 class="mb-0">SLA Compliance Trend</h6>
                  <p class="text-sm mb-0">7-day compliance rate tracking</p>
                </div>
                <div class="col-auto">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" 
                            data-bs-toggle="dropdown" aria-expanded="false">
                      Last 7 Days
                    </button>
                    <ul class="dropdown-menu">
                      <li><a class="dropdown-item" href="#" onclick="changePeriod('7d')">Last 7 Days</a></li>
                      <li><a class="dropdown-item" href="#" onclick="changePeriod('30d')">Last 30 Days</a></li>
                      <li><a class="dropdown-item" href="#" onclick="changePeriod('90d')">Last 90 Days</a></li>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="complianceTrendChart"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Compliance By Package -->
        <div class="col-lg-4">
          <div class="card sla-metric-card h-100">
            <div class="card-header pb-0">
              <h6 class="mb-0">Package Performance</h6>
              <p class="text-sm mb-0">Compliance by subscription tier</p>
            </div>
            <div class="card-body">
              <div class="chart-container">
                <canvas id="packageComplianceChart"></canvas>
              </div>
              <div class="mt-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="package-badge package-enterprise">Enterprise</span>
                  <span class="font-weight-bold">95.2%</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <span class="package-badge package-professional">Professional</span>
                  <span class="font-weight-bold">88.7%</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="package-badge package-starter">Starter</span>
                  <span class="font-weight-bold">79.3%</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Live Alerts and Recent Activity -->
      <div class="row mb-4">
        <!-- Live SLA Breach Alerts -->
        <div class="col-lg-6">
          <div class="card sla-metric-card h-100">
            <div class="card-header pb-0">
              <div class="d-flex align-items-center">
                <h6 class="mb-0">
                  <i class="fas fa-exclamation-circle text-danger me-2"></i>
                  Live SLA Alerts
                </h6>
                <span class="real-time-indicator ms-2">
                  <i class="fas fa-circle"></i>
                </span>
              </div>
            </div>
            <div class="card-body">
              <div id="sla-alerts-container">
                @if(isset($alerts) && count($alerts) > 0)
                  @foreach($alerts as $alert)
                  <div class="alert-card p-3 mb-3 {{ $alert['severity'] === 'critical' ? '' : ($alert['severity'] === 'medium' ? 'warning' : 'success') }}">
                    <div class="row align-items-center">
                      <div class="col">
                        <h6 class="mb-1">{{ $alert['title'] }}</h6>
                        <p class="mb-0 text-sm text-muted">
                          Ticket #{{ $alert['ticket_number'] }} • {{ $alert['customer'] }}
                        </p>
                        <small class="text-muted">
                          {{ $alert['minutes_overdue'] }} minutes overdue
                        </small>
                      </div>
                      <div class="col-auto">
                        <span class="package-badge package-{{ $alert['package_level'] }}">
                          {{ ucfirst($alert['package_level']) }}
                        </span>
                      </div>
                    </div>
                  </div>
                  @endforeach
                @else
                <div class="text-center py-4">
                  <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                  <h6 class="text-muted">No Active SLA Breaches</h6>
                  <p class="text-sm text-muted">All tickets are within SLA compliance</p>
                </div>
                @endif
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Escalations -->
        <div class="col-lg-6">
          <div class="card sla-metric-card h-100">
            <div class="card-header pb-0">
              <h6 class="mb-0">
                <i class="fas fa-level-up-alt text-warning me-2"></i>
                Recent Escalations
              </h6>
            </div>
            <div class="card-body">
              <div id="recent-escalations">
                @if(isset($escalations) && count($escalations) > 0)
                  @foreach($escalations as $escalation)
                  <div class="d-flex align-items-center py-3 border-bottom">
                    <div class="col">
                      <h6 class="mb-1">#{{ $escalation['ticket_number'] }}</h6>
                      <p class="mb-0 text-sm text-muted">{{ $escalation['reason'] }}</p>
                      <small class="text-muted">{{ $escalation['created_at'] }}</small>
                    </div>
                    <div class="col-auto">
                      <span class="badge badge-sm bg-warning">{{ $escalation['level'] }}</span>
                    </div>
                  </div>
                  @endforeach
                @else
                <div class="text-center py-4">
                  <i class="fas fa-clipboard-check text-success fa-3x mb-3"></i>
                  <h6 class="text-muted">No Recent Escalations</h6>
                  <p class="text-sm text-muted">System operating smoothly</p>
                </div>
                @endif
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Agent Performance Table -->
      <div class="row">
        <div class="col-12">
          <div class="card sla-metric-card">
            <div class="card-header pb-0">
              <div class="row">
                <div class="col">
                  <h6 class="mb-0">Agent Performance</h6>
                  <p class="text-sm mb-0">Individual agent SLA metrics</p>
                </div>
                <div class="col-auto">
                  <button class="btn btn-sm btn-primary" onclick="showDetailedReport()">
                    View Detailed Report
                  </button>
                </div>
              </div>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-flush" id="agent-performance-table">
                  <thead class="thead-light">
                    <tr>
                      <th>Agent</th>
                      <th>Active Tickets</th>
                      <th>Compliance Rate</th>
                      <th>Avg Response Time</th>
                      <th>Escalations</th>
                      <th>Performance Score</th>
                    </tr>
                  </thead>
                  <tbody>
                    @if(isset($agents) && count($agents) > 0)
                      @foreach($agents as $agent)
                      <tr>
                        <td>
                          <div class="d-flex px-2">
                            <div class="my-auto">
                              <h6 class="mb-0 text-sm">{{ $agent['name'] }}</h6>
                            </div>
                          </div>
                        </td>
                        <td class="align-middle text-sm">{{ $agent['active_tickets'] }}</td>
                        <td class="align-middle">
                          <div class="progress-wrapper w-75 mx-auto">
                            <div class="progress-info">
                              <div class="progress-percentage">
                                <span class="text-xs font-weight-bold">{{ $agent['compliance_rate'] }}%</span>
                              </div>
                            </div>
                            <div class="progress">
                              <div class="progress-bar bg-gradient-success" 
                                   style="width: {{ $agent['compliance_rate'] }}%"></div>
                            </div>
                          </div>
                        </td>
                        <td class="align-middle text-sm">{{ $agent['avg_response_time'] }} min</td>
                        <td class="align-middle text-sm">{{ $agent['escalations'] }}</td>
                        <td class="align-middle">
                          <span class="badge badge-sm bg-gradient-{{ $agent['performance_score'] >= 80 ? 'success' : ($agent['performance_score'] >= 60 ? 'warning' : 'danger') }}">
                            {{ $agent['performance_score'] }}%
                          </span>
                        </td>
                      </tr>
                      @endforeach
                    @else
                      <tr>
                        <td colspan="6" class="text-center py-4">
                          <i class="fas fa-users text-muted fa-2x mb-3"></i>
                          <p class="text-muted mb-0">No agent data available</p>
                        </td>
                      </tr>
                    @endif
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
@endsection

@push('scripts')
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeDashboard();
    
    // Set up auto-refresh every 5 minutes
    setInterval(function() {
        refreshDashboard();
    }, 300000);
});

function initializeDashboard() {
    initializeComplianceTrendChart();
    initializePackageComplianceChart();
    updateLastUpdatedTime();
}

function initializeComplianceTrendChart() {
    const ctx = document.getElementById('complianceTrendChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'SLA Compliance %',
                data: [85, 87, 83, 91, 88, 85, 89],
                backgroundColor: 'rgba(26, 115, 232, 0.1)',
                borderColor: '#1a73e8',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: false,
                    min: 70,
                    max: 100,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

function initializePackageComplianceChart() {
    const ctx = document.getElementById('packageComplianceChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Enterprise', 'Professional', 'Starter'],
            datasets: [{
                data: [95.2, 88.7, 79.3],
                backgroundColor: ['#d97706', '#7c3aed', '#1d4ed8'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
}

function refreshDashboard() {
    // Show loading indicator
    showLoadingIndicator();
    
    // Fetch updated data from API
    fetch('/api/sla-dashboard/metrics/realtime', {
        method: 'GET',
        headers: {
            'Authorization': 'Bearer ' + '{{ auth()->user()->createToken("dashboard")->plainTextToken ?? "" }}',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            updateDashboardMetrics(data.data);
            updateLastUpdatedTime();
            showSuccessMessage('Dashboard refreshed successfully');
        } else {
            showErrorMessage('Failed to refresh dashboard');
        }
    })
    .catch(error => {
        console.error('Error refreshing dashboard:', error);
        showErrorMessage('Connection error occurred');
    })
    .finally(() => {
        hideLoadingIndicator();
    });
}

function updateDashboardMetrics(data) {
    // Update key metrics
    if (data.compliance_overview) {
        document.getElementById('overall-compliance').textContent = data.compliance_overview.compliance_rate + '%';
        document.getElementById('active-tickets').textContent = data.compliance_overview.total_active_tickets;
        document.getElementById('sla-breaches').textContent = data.compliance_overview.breached;
    }
    
    // Update alerts
    if (data.live_alerts) {
        updateAlertsSection(data.live_alerts);
    }
    
    // Update escalations
    if (data.recent_escalations) {
        updateEscalationsSection(data.recent_escalations);
    }
}

function updateAlertsSection(alerts) {
    const container = document.getElementById('sla-alerts-container');
    
    if (alerts.length === 0) {
        container.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                <h6 class="text-muted">No Active SLA Breaches</h6>
                <p class="text-sm text-muted">All tickets are within SLA compliance</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    alerts.forEach(alert => {
        const severityClass = alert.severity === 'critical' ? '' : 
                             alert.severity === 'medium' ? 'warning' : 'success';
        html += `
            <div class="alert-card ${severityClass} p-3 mb-3">
                <div class="row align-items-center">
                    <div class="col">
                        <h6 class="mb-1">${alert.title}</h6>
                        <p class="mb-0 text-sm text-muted">
                            Ticket #${alert.ticket_number} • ${alert.customer}
                        </p>
                        <small class="text-muted">
                            ${alert.minutes_overdue} minutes overdue
                        </small>
                    </div>
                    <div class="col-auto">
                        <span class="package-badge package-${alert.package_level}">
                            ${alert.package_level.charAt(0).toUpperCase() + alert.package_level.slice(1)}
                        </span>
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateEscalationsSection(escalations) {
    const container = document.getElementById('recent-escalations');
    
    if (escalations.length === 0) {
        container.innerHTML = `
            <div class="text-center py-4">
                <i class="fas fa-clipboard-check text-success fa-3x mb-3"></i>
                <h6 class="text-muted">No Recent Escalations</h6>
                <p class="text-sm text-muted">System operating smoothly</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    escalations.forEach(escalation => {
        html += `
            <div class="d-flex align-items-center py-3 border-bottom">
                <div class="col">
                    <h6 class="mb-1">#${escalation.ticket_number}</h6>
                    <p class="mb-0 text-sm text-muted">${escalation.reason}</p>
                    <small class="text-muted">${escalation.created_at}</small>
                </div>
                <div class="col-auto">
                    <span class="badge badge-sm bg-warning">${escalation.level}</span>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

function updateLastUpdatedTime() {
    const now = new Date();
    document.getElementById('last-updated').textContent = now.toLocaleTimeString();
}

function changePeriod(period) {
    // Implementation to change chart period
    console.log('Changing period to:', period);
}

function exportReport() {
    // Implementation to export SLA report
    window.open('/api/sla-dashboard/reports/export?format=pdf', '_blank');
}

function showDetailedReport() {
    // Implementation to show detailed agent report
    window.open('/sla-dashboard/agents', '_blank');
}

function showLoadingIndicator() {
    // Show loading spinner
}

function hideLoadingIndicator() {
    // Hide loading spinner
}

function showSuccessMessage(message) {
    // Show success toast
    console.log('Success:', message);
}

function showErrorMessage(message) {
    // Show error toast
    console.log('Error:', message);
}
</script>
@endpush