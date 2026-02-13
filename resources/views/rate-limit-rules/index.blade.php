@extends('layouts.user_type.auth')

@section('content')

<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-tachometer-alt text-primary me-2"></i>
                Rate Limit Rules Configuration
            </h3>
            <p class="text-sm text-secondary mb-0">Atur max request, window, dan throttle policy per endpoint & risk level</p>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Rules</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['total_rules'] }}</h4>
                        <p class="text-xs mb-0">
                            <span class="text-success">{{ $stats['active_rules'] }} active</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card border-2 border-danger">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-danger">Blocks (24h)</p>
                        <h4 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['blocks_24h']) }}</h4>
                        <p class="text-xs mb-0 text-muted">{{ number_format($stats['blocks_7d']) }} in 7d</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card border-2 border-warning">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-warning">Throttles (24h)</p>
                        <h4 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['throttles_24h']) }}</h4>
                        <p class="text-xs mb-0 text-muted">{{ number_format($stats['throttles_7d']) }} in 7d</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card border-2 border-info">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-info">Unique Users (24h)</p>
                        <h4 class="font-weight-bolder mb-0 text-info">{{ $stats['unique_users_24h'] }}</h4>
                        <p class="text-xs mb-0 text-muted">{{ $stats['unique_ips_24h'] }} IPs</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Top Statistics --}}
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header pb-0 px-3">
                    <h6 class="mb-0">
                        <i class="fas fa-link text-danger me-2"></i>
                        Top Blocked Endpoints
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if($stats['top_blocked_endpoints']->isEmpty())
                        <p class="text-sm text-muted mb-0">No blocked endpoints yet</p>
                    @else
                        @foreach($stats['top_blocked_endpoints'] as $endpoint)
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-xs">{{ Str::limit($endpoint->endpoint, 30) }}</span>
                                <span class="badge badge-sm bg-gradient-danger">{{ $endpoint->count }}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header pb-0 px-3">
                    <h6 class="mb-0">
                        <i class="fas fa-network-wired text-warning me-2"></i>
                        Top Blocked IPs
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if($stats['top_blocked_ips']->isEmpty())
                        <p class="text-sm text-muted mb-0">No blocked IPs yet</p>
                    @else
                        @foreach($stats['top_blocked_ips'] as $ip)
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-xs font-monospace">{{ $ip->ip_address }}</span>
                                <span class="badge badge-sm bg-gradient-warning">{{ $ip->count }}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header pb-0 px-3">
                    <h6 class="mb-0">
                        <i class="fas fa-fire text-info me-2"></i>
                        Top Triggered Rules
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if($stats['top_rules_triggered']->isEmpty())
                        <p class="text-sm text-muted mb-0">No triggered rules yet</p>
                    @else
                        @foreach($stats['top_rules_triggered'] as $item)
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-xs">{{ Str::limit($item->rule->name ?? 'Unknown', 25) }}</span>
                                <span class="badge badge-sm bg-gradient-info">{{ $item->count }}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Rules Tabs --}}
    <div class="card">
        <div class="card-header pb-0 px-3">
            <ul class="nav nav-tabs" id="rulesTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="critical-tab" data-bs-toggle="tab" data-bs-target="#critical" type="button" role="tab">
                        <i class="fas fa-exclamation-triangle text-danger"></i> Critical Risk
                        <span class="badge badge-sm bg-danger">{{ $rules['critical_risk']->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="high-tab" data-bs-toggle="tab" data-bs-target="#high" type="button" role="tab">
                        <i class="fas fa-exclamation-circle text-warning"></i> High Risk
                        <span class="badge badge-sm bg-warning">{{ $rules['high_risk']->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="medium-tab" data-bs-toggle="tab" data-bs-target="#medium" type="button" role="tab">
                        <i class="fas fa-info-circle text-info"></i> Medium Risk
                        <span class="badge badge-sm bg-info">{{ $rules['medium_risk']->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="saldo-tab" data-bs-toggle="tab" data-bs-target="#saldo" type="button" role="tab">
                        <i class="fas fa-wallet text-success"></i> Saldo-Based
                        <span class="badge badge-sm bg-success">{{ $rules['saldo_based']->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="endpoint-tab" data-bs-toggle="tab" data-bs-target="#endpoint" type="button" role="tab">
                        <i class="fas fa-link text-primary"></i> Endpoint-Specific
                        <span class="badge badge-sm bg-primary">{{ $rules['endpoint_specific']->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="global-tab" data-bs-toggle="tab" data-bs-target="#global" type="button" role="tab">
                        <i class="fas fa-globe text-secondary"></i> Global
                        <span class="badge badge-sm bg-secondary">{{ $rules['global_rules']->count() }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body px-3">
            <div class="tab-content" id="rulesTabContent">
                {{-- Critical Risk Rules --}}
                <div class="tab-pane fade show active" id="critical" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['critical_risk'], 'title' => 'Critical Risk Rules'])
                </div>

                {{-- High Risk Rules --}}
                <div class="tab-pane fade" id="high" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['high_risk'], 'title' => 'High Risk Rules'])
                </div>

                {{-- Medium Risk Rules --}}
                <div class="tab-pane fade" id="medium" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['medium_risk'], 'title' => 'Medium Risk Rules'])
                </div>

                {{-- Saldo-Based Rules --}}
                <div class="tab-pane fade" id="saldo" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['saldo_based'], 'title' => 'Saldo-Based Rules'])
                </div>

                {{-- Endpoint-Specific Rules --}}
                <div class="tab-pane fade" id="endpoint" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['endpoint_specific'], 'title' => 'Endpoint-Specific Rules'])
                </div>

                {{-- Global Rules --}}
                <div class="tab-pane fade" id="global" role="tabpanel">
                    @include('rate-limit-rules.partials.rules-table', ['rule_list' => $rules['global_rules'], 'title' => 'Global Rules'])
                </div>
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="row mt-4">
        <div class="col-12">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRuleModal">
                <i class="fas fa-plus me-2"></i>Create New Rule
            </button>
            <button type="button" class="btn btn-danger" onclick="showClearLogsModal()">
                <i class="fas fa-trash me-2"></i>Clear Old Logs
            </button>
            <button type="button" class="btn btn-info" onclick="refreshStatistics()">
                <i class="fas fa-sync me-2"></i>Refresh Statistics
            </button>
        </div>
    </div>
</div>

{{-- Create Rule Modal --}}
<div class="modal fade" id="createRuleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Rate Limit Rule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="createRuleForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Rule Name *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Context Type *</label>
                            <select class="form-control" name="context_type" required>
                                <option value="user">User</option>
                                <option value="ip">IP Address</option>
                                <option value="endpoint">Endpoint</option>
                                <option value="api_key">API Key</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Max Requests *</label>
                            <input type="number" class="form-control" name="max_requests" min="0" max="10000" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Window (seconds) *</label>
                            <input type="number" class="form-control" name="window_seconds" min="1" max="86400" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Algorithm *</label>
                            <select class="form-control" name="algorithm" required>
                                <option value="sliding_window">Sliding Window</option>
                                <option value="token_bucket">Token Bucket</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Action *</label>
                            <select class="form-control" name="action" required>
                                <option value="block">Block</option>
                                <option value="throttle">Throttle</option>
                                <option value="warn">Warn</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Priority *</label>
                            <input type="number" class="form-control" name="priority" min="1" max="100" value="50" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Risk Level</label>
                            <select class="form-control" name="risk_level">
                                <option value="">None</option>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="critical">Critical</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Saldo Status</label>
                            <select class="form-control" name="saldo_status">
                                <option value="">None</option>
                                <option value="sufficient">Sufficient</option>
                                <option value="low">Low</option>
                                <option value="critical">Critical</option>
                                <option value="zero">Zero</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Throttle Delay (ms)</label>
                            <input type="number" class="form-control" name="throttle_delay_ms" min="0" max="10000">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Endpoint Pattern</label>
                        <input type="text" class="form-control" name="endpoint_pattern" placeholder="/api/messages/*">
                        <small class="form-text text-muted">Use * for wildcards. Leave empty for all endpoints.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Block Message</label>
                        <input type="text" class="form-control" name="block_message" placeholder="Rate limit exceeded. Please try again later.">
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" checked>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_authenticated" value="1" checked>
                                <label class="form-check-label">Authenticated Users</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to_guest" value="1">
                                <label class="form-check-label">Guest Users</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="createRule()">Create Rule</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
// Toggle rule active status
function toggleRule(ruleId) {
    if (!confirm('Are you sure you want to toggle this rule?')) return;

    $.ajax({
        url: `/owner/rate-limit-rules/${ruleId}/toggle`,
        method: 'POST',
        data: {
            _token: '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success) {
                Swal.fire('Success!', response.message, 'success');
                location.reload();
            }
        },
        error: function(xhr) {
            Swal.fire('Error!', xhr.responseJSON?.message || 'Failed to toggle rule', 'error');
        }
    });
}

// Update rule
function updateRule(ruleId) {
    const form = $(`#editForm-${ruleId}`);
    const formData = form.serialize();

    $.ajax({
        url: `/owner/rate-limit-rules/${ruleId}/update`,
        method: 'POST',
        data: formData + '&_token={{ csrf_token() }}',
        success: function(response) {
            if (response.success) {
                Swal.fire('Success!', response.message, 'success');
                $(`#editModal-${ruleId}`).modal('hide');
                location.reload();
            }
        },
        error: function(xhr) {
            Swal.fire('Error!', xhr.responseJSON?.message || 'Failed to update rule', 'error');
        }
    });
}

// Create new rule
function createRule() {
    const form = $('#createRuleForm');
    const formData = new FormData(form[0]);

    $.ajax({
        url: '/owner/rate-limit-rules/create',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success) {
                Swal.fire('Success!', response.message, 'success');
                $('#createRuleModal').modal('hide');
                location.reload();
            }
        },
        error: function(xhr) {
            Swal.fire('Error!', xhr.responseJSON?.message || 'Failed to create rule', 'error');
        }
    });
}

// Delete rule
function deleteRule(ruleId) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the rate limit rule!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `/owner/rate-limit-rules/${ruleId}`,
                method: 'DELETE',
                data: {
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Deleted!', response.message, 'success');
                        location.reload();
                    }
                },
                error: function(xhr) {
                    Swal.fire('Error!', xhr.responseJSON?.message || 'Failed to delete rule', 'error');
                }
            });
        }
    });
}

// Clear old logs
function showClearLogsModal() {
    Swal.fire({
        title: 'Clear Old Logs',
        text: 'Delete rate limit logs older than how many days?',
        input: 'number',
        inputValue: 30,
        inputAttributes: {
            min: 1,
            max: 365
        },
        showCancelButton: true,
        confirmButtonText: 'Clear Logs',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            clearLogs(result.value);
        }
    });
}

function clearLogs(days) {
    $.ajax({
        url: '/owner/rate-limit-rules/clear-logs',
        method: 'POST',
        data: {
            days: days,
            _token: '{{ csrf_token() }}'
        },
        success: function(response) {
            if (response.success) {
                Swal.fire('Success!', response.message, 'success');
            }
        },
        error: function(xhr) {
            Swal.fire('Error!', xhr.responseJSON?.message || 'Failed to clear logs', 'error');
        }
    });
}

// Refresh statistics
function refreshStatistics() {
    location.reload();
}
</script>
@endpush
