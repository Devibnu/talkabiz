@extends('layouts.app')

@section('title', 'SLA Dashboard')

@push('styles')
<style>
  .dashboard-card {
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
    position: relative;
    overflow: hidden;
  }
  
  .dashboard-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  }
  
  .metric-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
  }
  
  .metric-card.success {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
  }
  
  .metric-card.warning {
    background: linear-gradient(135deg, #fcb045 0%, #fd1d1d 100%);
  }
  
  .metric-card.info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  }
  
  .metric-icon {
    font-size: 2.5rem;
    opacity: 0.2;
    position: absolute;
    right: 20px;
    top: 20px;
  }
  
  .metric-value {
    font-size: 2.5rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 8px;
  }
  
  .metric-label {
    font-size: 0.875rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  
  .metric-trend {
    font-size: 0.75rem;
    margin-top: 8px;
    padding: 4px 8px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    display: inline-block;
  }
  
  .status-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 8px;
  }
  
  .status-success { background-color: #10b981; }
  .status-warning { background-color: #f59e0b; }
  .status-danger { background-color: #ef4444; }
  .status-info { background-color: #3b82f6; }
  
  .quick-action-btn {
    background: #f8fafc;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px;
    text-decoration: none;
    color: #374151;
    transition: all 0.3s ease;
    display: block;
    text-align: center;
  }
  
  .quick-action-btn:hover {
    border-color: #3b82f6;
    background: #eff6ff;
    color: #3b82f6;
    transform: translateY(-2px);
  }
  
  .alert-card {
    border-left: 4px solid;
    background: white;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 16px;
  }
  
  .alert-critical {
    border-left-color: #ef4444;
    background: linear-gradient(90deg, rgba(239, 68, 68, 0.05) 0%, transparent 100%);
  }
  
  .alert-warning {
    border-left-color: #f59e0b;
    background: linear-gradient(90deg, rgba(245, 158, 11, 0.05) 0%, transparent 100%);
  }
  
  .alert-success {
    border-left-color: #10b981;
    background: linear-gradient(90deg, rgba(16, 185, 129, 0.05) 0%, transparent 100%);
  }
  
  .chart-container {
    position: relative;
    height: 300px;
    margin: 20px 0;
  }
  
  .navigation-tabs {
    background: #f8fafc;
    border-radius: 12px;
    padding: 4px;
    margin-bottom: 24px;
  }
  
  .nav-tab {
    border: none;
    background: transparent;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    color: #64748b;
    transition: all 0.2s ease;
    text-decoration: none;
    display: inline-block;
  }
  
  .nav-tab.active {
    background: white;
    color: #1e293b;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  }
  
  .nav-tab:hover {
    color: #3b82f6;
  }
  
  .recent-activity-item {
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
  }
  
  .recent-activity-item:last-child {
    border-bottom: none;
  }
  
  .activity-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 0.875rem;
  }
  
  .page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px 0;
    margin: -24px -24px 24px -24px;
    border-radius: 0 0 24px 24px;
  }
</style>
@endpush

@section('auth')
  @include('layouts.navbars.auth.sidebar')
  
  <main class="main-content position-relative max-height-vh-100 h-100 border-radius-lg">
    @include('layouts.navbars.auth.nav')

    <div class="container-fluid py-4">
      @yield('dashboard-content')
    </div>
  </main>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Global dashboard utilities
window.DashboardUtils = {
    // Format numbers with appropriate suffixes
    formatNumber: function(num) {
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1) + 'M';
        } else if (num >= 1000) {
            return (num / 1000).toFixed(1) + 'K';
        }
        return num.toString();
    },
    
    // Format duration in minutes to human readable
    formatDuration: function(minutes) {
        if (minutes < 60) {
            return minutes + 'm';
        } else if (minutes < 1440) {
            return Math.round(minutes / 60) + 'h';
        } else {
            return Math.round(minutes / 1440) + 'd';
        }
    },
    
    // Get status color class
    getStatusClass: function(status) {
        const statusMap = {
            'excellent': 'success',
            'good': 'info',
            'warning': 'warning',
            'critical': 'danger',
            'pending': 'warning',
            'resolved': 'success',
            'escalated': 'danger'
        };
        return statusMap[status] || 'secondary';
    },
    
    // Show loading state
    showLoading: function(element) {
        element.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i><br><small class="text-muted">Loading...</small></div>';
    },
    
    // Show error state
    showError: function(element, message = 'Error loading data') {
        element.innerHTML = `<div class="text-center p-4 text-danger"><i class="fas fa-exclamation-triangle fa-2x"></i><br><small>${message}</small></div>`;
    },
    
    // Refresh dashboard data
    refreshData: function() {
        window.location.reload();
    },
    
    // Export data
    exportData: function(type, format = 'xlsx') {
        const params = new URLSearchParams(window.location.search);
        params.append('format', format);
        params.append('type', type);
        
        window.open(`/sla-dashboard/export?${params}`, '_blank');
    },
    
    // Show notification
    showNotification: function(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }
};

// Auto refresh every 5 minutes
setInterval(() => {
    if (document.visibilityState === 'visible') {
        DashboardUtils.refreshData();
    }
}, 300000);

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add real-time indicators
    setInterval(updateRealTimeIndicators, 30000); // Update every 30 seconds
});

function updateRealTimeIndicators() {
    // Update any real-time indicators on the page
    const indicators = document.querySelectorAll('.real-time-indicator');
    indicators.forEach(indicator => {
        indicator.classList.add('animate-pulse');
        setTimeout(() => {
            indicator.classList.remove('animate-pulse');
        }, 1000);
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl/Cmd + R: Refresh dashboard
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        DashboardUtils.refreshData();
    }
    
    // Ctrl/Cmd + E: Export current view
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        DashboardUtils.exportData('current');
    }
});
</script>
@endpush