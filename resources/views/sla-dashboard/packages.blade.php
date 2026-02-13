@extends('layouts.app')

@section('title', 'Package Comparison - SLA Dashboard')

@push('styles')
<style>
  .package-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
  }
  
  .package-card.starter {
    border-top: 4px solid #10b981;
  }
  
  .package-card.professional {
    border-top: 4px solid #3b82f6;
  }
  
  .package-card.enterprise {
    border-top: 4px solid #8b5cf6;
  }
  
  .package-header {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
    padding: 20px;
    text-align: center;
    border-radius: 16px 16px 0 0;
  }
  
  .package-title {
    font-size: 1.5rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  
  .package-subtitle {
    font-size: 0.875rem;
    opacity: 0.7;
    margin-top: 4px;
  }
  
  .metric-value {
    font-size: 2rem;
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
    padding: 2px 8px;
    border-radius: 12px;
    margin-top: 8px;
  }
  
  .trend-up {
    background: #dcfce7;
    color: #166534;
  }
  
  .trend-down {
    background: #fee2e2;
    color: #991b1b;
  }
  
  .comparison-chart {
    height: 300px;
    margin: 20px 0;
  }
  
  .performance-indicator {
    width: 100%;
    height: 12px;
    border-radius: 6px;
    background: #e5e7eb;
    overflow: hidden;
    margin: 8px 0;
  }
  
  .performance-fill {
    height: 100%;
    border-radius: 6px;
    transition: width 0.5s ease;
    position: relative;
  }
  
  .feature-list {
    list-style: none;
    padding: 0;
  }
  
  .feature-item {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
  }
  
  .feature-icon {
    width: 20px;
    margin-right: 12px;
    text-align: center;
  }
  
  .sla-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
  }
  
  .filter-tabs {
    background: #f8fafc;
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 24px;
  }
  
  .filter-tab {
    padding: 12px 24px;
    border: none;
    background: transparent;
    border-radius: 8px;
    font-weight: 600;
    color: #64748b;
    transition: all 0.2s ease;
  }
  
  .filter-tab.active {
    background: white;
    color: #1e293b;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
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
              <h1 class="mb-1">Package Performance Comparison</h1>
              <p class="text-muted mb-0">Compare SLA performance across different subscription packages</p>
            </div>
            <div>
              <a href="{{ route('sla-dashboard.index') }}" class="btn btn-outline-primary me-2">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
              </a>
              <button class="btn btn-primary" onclick="exportPackageComparison()">
                <i class="fas fa-download"></i> Export Comparison
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Time Period Filter -->
      <div class="filter-tabs d-flex justify-content-center">
        <button class="filter-tab active" onclick="loadDashboard('7days')">Last 7 Days</button>
        <button class="filter-tab" onclick="loadDashboard('30days')">Last 30 Days</button>
        <button class="filter-tab" onclick="loadDashboard('90days')">Last 90 Days</button>
        <button class="filter-tab" onclick="loadDashboard('1year')">Last Year</button>
      </div>

      <!-- Package Overview Cards -->
      <div class="row mb-5">
        @if(isset($packageComparison['packages']))
          @foreach($packageComparison['packages'] as $package => $data)
          <div class="col-lg-4 mb-4">
            <div class="package-card {{ $package }}">
              <div class="package-header">
                <div class="package-title text-{{ $package === 'starter' ? 'success' : ($package === 'professional' ? 'primary' : 'purple') }}">
                  {{ ucfirst($package) }}
                </div>
                <div class="package-subtitle">
                  {{ $data['user_count'] ?? 0 }} active users
                </div>
              </div>
              <div class="p-4">
                <!-- Key Metrics -->
                <div class="row mb-4">
                  <div class="col-6 text-center">
                    <div class="metric-value text-{{ $data['sla_compliance_rate'] >= 95 ? 'success' : ($data['sla_compliance_rate'] >= 90 ? 'primary' : ($data['sla_compliance_rate'] >= 80 ? 'warning' : 'danger')) }}">
                      {{ number_format($data['sla_compliance_rate'] ?? 0, 1) }}%
                    </div>
                    <div class="metric-label">SLA Compliance</div>
                    <div class="performance-indicator">
                      <div class="performance-fill bg-{{ $data['sla_compliance_rate'] >= 95 ? 'success' : ($data['sla_compliance_rate'] >= 90 ? 'primary' : ($data['sla_compliance_rate'] >= 80 ? 'warning' : 'danger')) }}" 
                           style="width: {{ $data['sla_compliance_rate'] ?? 0 }}%"></div>
                    </div>
                  </div>
                  <div class="col-6 text-center">
                    <div class="metric-value text-dark">
                      {{ $data['total_tickets'] ?? 0 }}
                    </div>
                    <div class="metric-label">Total Tickets</div>
                    @if(isset($data['ticket_trend']))
                    <div class="metric-trend {{ $data['ticket_trend'] > 0 ? 'trend-up' : 'trend-down' }}">
                      <i class="fas fa-arrow-{{ $data['ticket_trend'] > 0 ? 'up' : 'down' }}"></i>
                      {{ abs($data['ticket_trend']) }}%
                    </div>
                    @endif
                  </div>
                </div>

                <!-- Response Times -->
                <div class="row mb-4">
                  <div class="col-12">
                    <h6 class="mb-3">Response Performance</h6>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span class="text-sm">First Response</span>
                      <span class="font-weight-bold">{{ $data['avg_first_response_time'] ?? 0 }}m</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <span class="text-sm">Resolution Time</span>
                      <span class="font-weight-bold">{{ $data['avg_resolution_time'] ?? 0 }}m</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                      <span class="text-sm">Customer Satisfaction</span>
                      <div>
                        <span class="font-weight-bold">{{ number_format($data['customer_satisfaction'] ?? 0, 1) }}</span>
                        <div class="text-warning d-inline-block ml-2">
                          @for($i = 1; $i <= 5; $i++)
                            <i class="fas fa-star{{ $i <= floor($data['customer_satisfaction'] ?? 0) ? '' : ($i <= ($data['customer_satisfaction'] ?? 0) ? '-half-alt' : ' text-muted opacity-50') }}"></i>
                          @endfor
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- SLA Targets -->
                <div class="mb-4">
                  <h6 class="mb-3">SLA Targets</h6>
                  <div class="feature-list">
                    @if(isset($data['sla_targets']))
                      @foreach($data['sla_targets'] as $priority => $target)
                      <div class="feature-item">
                        <div class="feature-icon">
                          <i class="fas fa-{{ $priority === 'critical' ? 'exclamation-triangle text-danger' : ($priority === 'high' ? 'arrow-up text-warning' : ($priority === 'medium' ? 'minus text-primary' : 'arrow-down text-success')) }}"></i>
                        </div>
                        <div class="flex-grow-1">
                          <span class="text-capitalize">{{ $priority }}</span>
                          <span class="sla-badge bg-light text-dark ml-auto">{{ $target }}min</span>
                        </div>
                      </div>
                      @endforeach
                    @endif
                  </div>
                </div>

                <!-- Available Channels -->
                <div>
                  <h6 class="mb-3">Support Channels</h6>
                  <div class="feature-list">
                    @if(isset($data['channels']))
                      @foreach($data['channels'] as $channel)
                      <div class="feature-item">
                        <div class="feature-icon">
                          <i class="fas fa-{{ 
                            $channel === 'email' ? 'envelope text-primary' : (
                            $channel === 'chat' ? 'comments text-success' : (
                            $channel === 'phone' ? 'phone text-warning' : (
                            $channel === 'whatsapp' ? 'whatsapp text-success' : 'question-circle'
                          ))) }}"></i>
                        </div>
                        <div class="flex-grow-1">
                          <span class="text-capitalize">{{ str_replace('_', ' ', $channel) }}</span>
                          <span class="badge badge-sm bg-success text-white ml-auto">Available</span>
                        </div>
                      </div>
                      @endforeach
                    @endif
                  </div>
                </div>
              </div>
            </div>
          </div>
          @endforeach
        @endif
      </div>

      <!-- Comparison Charts -->
      <div class="row mb-4">
        <div class="col-lg-6 mb-4">
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">SLA Compliance Comparison</h5>
              <p class="text-sm text-muted mb-0">Performance across package levels</p>
            </div>
            <div class="card-body">
              <canvas id="complianceComparisonChart" class="comparison-chart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-lg-6 mb-4">
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">Response Time Comparison</h5>
              <p class="text-sm text-muted mb-0">Average response times by package</p>
            </div>
            <div class="card-body">
              <canvas id="responseTimeChart" class="comparison-chart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Detailed Metrics Table -->
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">Detailed Package Metrics</h5>
              <p class="text-sm text-muted mb-0">Complete performance breakdown</p>
            </div>
            <div class="card-body">
              @if(isset($packageComparison['packages']) && count($packageComparison['packages']) > 0)
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Package</th>
                      <th>Active Users</th>
                      <th>Total Tickets</th>
                      <th>SLA Compliance</th>
                      <th>Avg First Response</th>
                      <th>Avg Resolution</th>
                      <th>Escalations</th>
                      <th>Satisfaction</th>
                      <th>Revenue Impact</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($packageComparison['packages'] as $package => $data)
                    <tr>
                      <td>
                        <div class="d-flex align-items-center">
                          <div class="badge badge-lg bg-{{ $package === 'starter' ? 'success' : ($package === 'professional' ? 'primary' : 'purple') }} text-white me-3">
                            {{ strtoupper(substr($package, 0, 1)) }}
                          </div>
                          <div>
                            <h6 class="mb-0 text-capitalize">{{ $package }}</h6>
                            <small class="text-muted">Level {{ $loop->iteration }}</small>
                          </div>
                        </div>
                      </td>
                      <td>
                        <strong>{{ number_format($data['user_count'] ?? 0) }}</strong>
                        @if(isset($data['user_growth']))
                        <br><small class="text-{{ $data['user_growth'] > 0 ? 'success' : 'danger' }}">
                          {{ $data['user_growth'] > 0 ? '+' : '' }}{{ $data['user_growth'] }}%
                        </small>
                        @endif
                      </td>
                      <td>
                        <strong>{{ number_format($data['total_tickets'] ?? 0) }}</strong>
                        @if(isset($data['ticket_trend']))
                        <br><small class="text-{{ $data['ticket_trend'] > 0 ? 'warning' : 'success' }}">
                          {{ $data['ticket_trend'] > 0 ? '+' : '' }}{{ $data['ticket_trend'] }}%
                        </small>
                        @endif
                      </td>
                      <td>
                        <div class="d-flex align-items-center">
                          <span class="font-weight-bold {{ ($data['sla_compliance_rate'] ?? 0) >= 95 ? 'text-success' : (($data['sla_compliance_rate'] ?? 0) >= 90 ? 'text-primary' : (($data['sla_compliance_rate'] ?? 0) >= 80 ? 'text-warning' : 'text-danger')) }}">
                            {{ number_format($data['sla_compliance_rate'] ?? 0, 1) }}%
                          </span>
                        </div>
                        <div class="performance-indicator">
                          <div class="performance-fill bg-{{ ($data['sla_compliance_rate'] ?? 0) >= 95 ? 'success' : (($data['sla_compliance_rate'] ?? 0) >= 90 ? 'primary' : (($data['sla_compliance_rate'] ?? 0) >= 80 ? 'warning' : 'danger')) }}" 
                               style="width: {{ $data['sla_compliance_rate'] ?? 0 }}%"></div>
                        </div>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark">{{ $data['avg_first_response_time'] ?? 0 }}m</span>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark">{{ $data['avg_resolution_time'] ?? 0 }}m</span>
                      </td>
                      <td>
                        <span class="badge {{ ($data['escalation_rate'] ?? 0) > 10 ? 'bg-danger' : (($data['escalation_rate'] ?? 0) > 5 ? 'bg-warning' : 'bg-success') }} text-white">
                          {{ number_format($data['escalation_rate'] ?? 0, 1) }}%
                        </span>
                      </td>
                      <td>
                        <div class="text-center">
                          <span class="font-weight-bold">{{ number_format($data['customer_satisfaction'] ?? 0, 1) }}</span>
                          <div class="text-warning">
                            @for($i = 1; $i <= 5; $i++)
                              <i class="fas fa-star{{ $i <= floor($data['customer_satisfaction'] ?? 0) ? '' : ($i <= ($data['customer_satisfaction'] ?? 0) ? '-half-alt' : ' text-muted opacity-50') }}"></i>
                            @endfor
                          </div>
                        </div>
                      </td>
                      <td>
                        <span class="font-weight-bold text-{{ ($data['revenue_impact'] ?? 0) >= 0 ? 'success' : 'danger' }}">
                          {{ ($data['revenue_impact'] ?? 0) >= 0 ? '+' : '' }}${{ number_format(abs($data['revenue_impact'] ?? 0)) }}
                        </span>
                      </td>
                    </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
              @else
              <div class="text-center py-4">
                <i class="fas fa-chart-bar text-muted fa-3x mb-3"></i>
                <h5 class="text-muted">No comparison data available</h5>
                <p class="text-muted">Package performance data will appear here once tickets are processed.</p>
              </div>
              @endif
            </div>
          </div>
        </div>
      </div>

      <!-- Insights and Recommendations -->
      @if(isset($packageComparison['insights']) && count($packageComparison['insights']) > 0)
      <div class="row mt-4">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="mb-0">📊 Performance Insights & Recommendations</h5>
              <p class="text-sm text-muted mb-0">Data-driven insights for package optimization</p>
            </div>
            <div class="card-body">
              <div class="row">
                @foreach($packageComparison['insights'] as $insight)
                <div class="col-lg-4 mb-3">
                  <div class="p-3 border-left-{{ $insight['type'] === 'positive' ? 'success' : ($insight['type'] === 'warning' ? 'warning' : 'danger') }} border rounded">
                    <div class="d-flex">
                      <div class="flex-shrink-0">
                        <i class="fas fa-{{ $insight['type'] === 'positive' ? 'check-circle text-success' : ($insight['type'] === 'warning' ? 'exclamation-triangle text-warning' : 'times-circle text-danger') }} me-3"></i>
                      </div>
                      <div>
                        <h6 class="mb-1">{{ $insight['title'] }}</h6>
                        <p class="text-sm text-muted mb-2">{{ $insight['description'] }}</p>
                        @if(isset($insight['action']))
                        <span class="badge badge-sm bg-{{ $insight['type'] === 'positive' ? 'success' : ($insight['type'] === 'warning' ? 'warning' : 'danger') }} text-white">
                          {{ $insight['action'] }}
                        </span>
                        @endif
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let complianceChart, responseChart;

// Load dashboard data
function loadDashboard(period = '30days') {
    // Update active tab
    document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
    event.target.classList.add('active');
    
    // Reload page with new period
    const url = new URL(window.location);
    url.searchParams.set('period', period);
    window.location.href = url.toString();
}

// Initialize charts when page loads
document.addEventListener('DOMContentLoaded', function() {
    initializeCharts();
});

function initializeCharts() {
    const packageData = @json($packageComparison['packages'] ?? []);
    
    // Prepare data for charts
    const labels = Object.keys(packageData).map(pkg => pkg.charAt(0).toUpperCase() + pkg.slice(1));
    const complianceData = Object.values(packageData).map(data => data.sla_compliance_rate || 0);
    const responseTimeData = Object.values(packageData).map(data => data.avg_first_response_time || 0);
    
    // SLA Compliance Chart
    const complianceCtx = document.getElementById('complianceComparisonChart').getContext('2d');
    complianceChart = new Chart(complianceCtx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'SLA Compliance Rate (%)',
                data: complianceData,
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(59, 130, 246, 0.8)',
                    'rgba(139, 92, 246, 0.8)'
                ],
                borderColor: [
                    'rgb(16, 185, 129)',
                    'rgb(59, 130, 246)',
                    'rgb(139, 92, 246)'
                ],
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white',
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return 'Compliance: ' + context.parsed.y + '%';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Response Time Chart
    const responseCtx = document.getElementById('responseTimeChart').getContext('2d');
    responseChart = new Chart(responseCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Average Response Time (minutes)',
                data: responseTimeData,
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: 'rgb(59, 130, 246)',
                pointBorderColor: 'white',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: 'white',
                    bodyColor: 'white',
                    cornerRadius: 8,
                    callbacks: {
                        label: function(context) {
                            return 'Response Time: ' + context.parsed.y + ' minutes';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.1)'
                    },
                    ticks: {
                        callback: function(value) {
                            return value + 'm';
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}

function exportPackageComparison() {
    const params = new URLSearchParams(window.location.search);
    params.append('format', 'xlsx');
    params.append('type', 'package_comparison');
    
    window.open(`/sla-dashboard/export?${params}`, '_blank');
}
</script>
@endpush