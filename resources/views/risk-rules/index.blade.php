@extends('layouts.user_type.auth')

@section('content')

<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-cog text-primary me-2"></i>
                Risk Rules Configuration
            </h3>
            <p class="text-sm text-secondary mb-0">Atur threshold, cooldown, dan policy enforcement</p>
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
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold">Auto-Unlock</p>
                        <h4 class="font-weight-bolder mb-0">
                            @if($stats['enabled_auto_unlock'])
                                <span class="badge badge-success">Enabled</span>
                            @else
                                <span class="badge badge-secondary">Disabled</span>
                            @endif
                        </h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold">Suspended Users</p>
                        <h4 class="font-weight-bolder mb-0">{{ $stats['total_suspended'] }}</h4>
                        <p class="text-xs mb-0">
                            <span class="text-warning">{{ $stats['temp_suspended'] }} temp</span> / 
                            <span class="text-danger">{{ $stats['perm_suspended'] }} perm</span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card border-left-success">
                <div class="card-body p-3">
                    <div class="text-center">
                        <p class="text-sm mb-0 font-weight-bold text-success">Pending Unlock</p>
                        <h4 class="font-weight-bolder mb-0 text-success">{{ $stats['pending_unlock'] }}</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Activity Stats --}}
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">
                        <i class="fas fa-chart-line me-2"></i>
                        Activity (Last 24h)
                    </h6>
                    <div class="row">
                        <div class="col-6">
                            <p class="text-sm mb-0 text-secondary">Total Events</p>
                            <h5 class="font-weight-bolder">{{ $stats['recent_events_24h'] }}</h5>
                        </div>
                        <div class="col-6">
                            <p class="text-sm mb-0 text-secondary">Auto Actions</p>
                            <h5 class="font-weight-bolder">{{ $stats['auto_actions_24h'] }}</h5>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h6 class="mb-3">
                        <i class="fas fa-history me-2"></i>
                        Quick Actions
                    </h6>
                    <a href="{{ route('abuse-monitor.index') }}" class="btn btn-sm btn-outline-primary me-2">
                        <i class="fas fa-shield-alt me-1"></i> Abuse Monitor
                    </a>
                    <a href="{{ route('risk-rules.escalation-history') }}" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-list me-1"></i> Escalation History
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Configuration Tabs --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <ul class="nav nav-tabs" id="riskRulesTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="thresholds-tab" data-bs-toggle="tab" href="#thresholds" role="tab">
                                <i class="fas fa-sliders-h me-1"></i> Thresholds
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="cooldown-tab" data-bs-toggle="tab" href="#cooldown" role="tab">
                                <i class="fas fa-clock me-1"></i> Cooldown & Auto-Unlock
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="auto-suspend-tab" data-bs-toggle="tab" href="#auto-suspend" role="tab">
                                <i class="fas fa-ban me-1"></i> Auto-Suspend
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="decay-tab" data-bs-toggle="tab" href="#decay" role="tab">
                                <i class="fas fa-arrow-down me-1"></i> Score Decay
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="weights-tab" data-bs-toggle="tab" href="#weights" role="tab">
                                <i class="fas fa-balance-scale me-1"></i> Signal Weights
                            </a>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="riskRulesTabsContent">
                        {{-- Thresholds Tab --}}
                        <div class="tab-pane fade show active" id="thresholds" role="tabpanel">
                            <h6 class="mb-3">Score Thresholds per Level</h6>
                            <p class="text-sm text-secondary">Tentukan range score untuk setiap abuse level</p>
                            
                            <form id="thresholds-form">
                                @csrf
                                <input type="hidden" name="setting_group" value="thresholds">
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Level</th>
                                                <th>Min Score</th>
                                                <th>Max Score</th>
                                                <th>Policy Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($config['thresholds'] as $level => $threshold)
                                            <tr>
                                                <td>
                                                    <span class="badge badge-{{ $level === 'none' ? 'success' : ($level === 'low' ? 'info' : ($level === 'medium' ? 'warning' : ($level === 'high' ? 'danger' : 'dark'))) }}">
                                                        {{ strtoupper($level) }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" 
                                                           name="settings[{{ $level }}_min]" 
                                                           value="{{ $threshold['min'] }}" 
                                                           min="0" step="1">
                                                </td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm" 
                                                           name="settings[{{ $level }}_max]" 
                                                           value="{{ $threshold['max'] === PHP_INT_MAX ? 999999 : $threshold['max'] }}" 
                                                           min="0" step="1">
                                                </td>
                                                <td>
                                                    <span class="text-sm">{{ $config['policy_actions'][$level] ?? 'none' }}</span>
                                                </td>
                                            </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Thresholds
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Cooldown Tab --}}
                        <div class="tab-pane fade" id="cooldown" role="tabpanel">
                            <h6 class="mb-3">Suspension Cooldown & Auto-Unlock</h6>
                            <p class="text-sm text-secondary">Konfigurasi temporary suspension dan automatic unlock</p>
                            
                            <form id="cooldown-form">
                                @csrf
                                <input type="hidden" name="setting_group" value="cooldown">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Enable Cooldown System</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="settings[enabled]" value="1"
                                                       {{ $config['suspension_cooldown']['enabled'] ? 'checked' : '' }}>
                                                <label class="form-check-label">Enabled</label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Enable Auto-Unlock</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="settings[auto_unlock_enabled]" value="1"
                                                       {{ $config['suspension_cooldown']['auto_unlock_enabled'] ? 'checked' : '' }}>
                                                <label class="form-check-label">Enabled</label>
                                            </div>
                                            <small class="text-muted">Automatis unlock user setelah cooldown selesai dan score membaik</small>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Default Cooldown Days</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[default_temp_suspension_days]" 
                                                   value="{{ $config['suspension_cooldown']['default_temp_suspension_days'] }}"
                                                   min="1" max="90">
                                            <small class="text-muted">Default periode cooldown untuk temporary suspension</small>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Auto-Unlock Score Threshold</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[auto_unlock_score_threshold]" 
                                                   value="{{ $config['suspension_cooldown']['auto_unlock_score_threshold'] }}"
                                                   min="0" step="1">
                                            <small class="text-muted">Score harus di bawah threshold ini untuk auto-unlock</small>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Minimum Cooldown Days</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[min_cooldown_days]" 
                                                   value="{{ $config['suspension_cooldown']['min_cooldown_days'] }}"
                                                   min="1" max="30">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Maximum Cooldown Days</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[max_cooldown_days]" 
                                                   value="{{ $config['suspension_cooldown']['max_cooldown_days'] }}"
                                                   min="1" max="365">
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Require Score Improvement</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="settings[require_score_improvement]" value="1"
                                                       {{ $config['suspension_cooldown']['require_score_improvement'] ? 'checked' : '' }}>
                                                <label class="form-check-label">Required</label>
                                            </div>
                                            <small class="text-muted">Score harus lebih rendah dari waktu suspend untuk unlock</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Cooldown Settings
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Auto-Suspend Tab --}}
                        <div class="tab-pane fade" id="auto-suspend" role="tabpanel">
                            <h6 class="mb-3">Auto-Suspend Configuration</h6>
                            <p class="text-sm text-secondary">Atur trigger untuk automatic suspension</p>
                            
                            <form id="auto-suspend-form">
                                @csrf
                                <input type="hidden" name="setting_group" value="auto_suspend">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Enable Auto-Suspend</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="settings[enabled]" value="1"
                                                       {{ $config['auto_suspend']['enabled'] ? 'checked' : '' }}>
                                                <label class="form-check-label">Enabled</label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Score Threshold</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[score_threshold]" 
                                                   value="{{ $config['auto_suspend']['score_threshold'] }}"
                                                   min="0" step="1">
                                            <small class="text-muted">Auto-suspend ketika score mencapai threshold ini</small>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Critical Events Count</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[critical_events_count]" 
                                                   value="{{ $config['auto_suspend']['critical_events_count'] }}"
                                                   min="1" max="10">
                                            <small class="text-muted">Jumlah critical events dalam window</small>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Critical Events Window (Hours)</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[critical_events_window_hours]" 
                                                   value="{{ $config['auto_suspend']['critical_events_window_hours'] }}"
                                                   min="1" max="168">
                                            <small class="text-muted">Time window untuk menghitung critical events</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Auto-Suspend Settings
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Decay Tab --}}
                        <div class="tab-pane fade" id="decay" role="tabpanel">
                            <h6 class="mb-3">Score Decay Configuration</h6>
                            <p class="text-sm text-secondary">Atur pengurangan score otomatis seiring waktu</p>
                            
                            <form id="decay-form">
                                @csrf
                                <input type="hidden" name="setting_group" value="decay">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Enable Score Decay</label>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="settings[enabled]" value="1"
                                                       {{ $config['decay']['enabled'] ? 'checked' : '' }}>
                                                <label class="form-check-label">Enabled</label>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label class="form-label">Decay Rate (Points per Day)</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[rate_per_day]" 
                                                   value="{{ $config['decay']['rate_per_day'] }}"
                                                   min="0" step="0.1">
                                            <small class="text-muted">Berapa point dikurangi per hari</small>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Min Days Without Event</label>
                                            <input type="number" class="form-control" 
                                                   name="settings[min_days_without_event]" 
                                                   value="{{ $config['decay']['min_days_without_event'] }}"
                                                   min="0" max="30">
                                            <small class="text-muted">Tunggu X hari tanpa event sebelum mulai decay</small>
                                        </div>

                                        <div class="alert alert-info mt-3">
                                            <i class="fas fa-info-circle me-2"></i>
                                            <strong>Note:</strong> Decay berjalan otomatis setiap hari jam 03:00 via scheduler
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Decay Settings
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Signal Weights Tab --}}
                        <div class="tab-pane fade" id="weights" role="tabpanel">
                            <h6 class="mb-3">Signal Weights</h6>
                            <p class="text-sm text-secondary">Atur bobot point untuk setiap jenis pelanggaran</p>
                            
                            <form id="weights-form">
                                @csrf
                                <input type="hidden" name="setting_group" value="signal_weights">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-sm text-primary mt-3">Usage-Based Signals</h6>
                                        @foreach(['excessive_messages', 'rate_limit_exceeded', 'burst_activity', 'quota_exceeded'] as $signal)
                                        <div class="form-group">
                                            <label class="form-label text-sm">{{ str_replace('_', ' ', ucfirst($signal)) }}</label>
                                            <input type="number" class="form-control form-control-sm" 
                                                   name="settings[{{ $signal }}]" 
                                                   value="{{ $config['signal_weights'][$signal] ?? 0 }}"
                                                   min="0" max="100" step="1">
                                        </div>
                                        @endforeach

                                        <h6 class="text-sm text-primary mt-3">Pattern-Based Signals</h6>
                                        @foreach(['suspicious_pattern', 'spam_detected', 'bot_like_behavior', 'unusual_timing'] as $signal)
                                        <div class="form-group">
                                            <label class="form-label text-sm">{{ str_replace('_', ' ', ucfirst($signal)) }}</label>
                                            <input type="number" class="form-control form-control-sm" 
                                                   name="settings[{{ $signal }}]" 
                                                   value="{{ $config['signal_weights'][$signal] ?? 0 }}"
                                                   min="0" max="100" step="1">
                                        </div>
                                        @endforeach
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="text-sm text-danger mt-3">Security Signals</h6>
                                        @foreach(['multiple_failed_auth', 'ip_change_rapid', 'suspicious_payload', 'api_abuse'] as $signal)
                                        <div class="form-group">
                                            <label class="form-label text-sm">{{ str_replace('_', ' ', ucfirst($signal)) }}</label>
                                            <input type="number" class="form-control form-control-sm" 
                                                   name="settings[{{ $signal }}]" 
                                                   value="{{ $config['signal_weights'][$signal] ?? 0 }}"
                                                   min="0" max="100" step="1">
                                        </div>
                                        @endforeach

                                        <h6 class="text-sm text-danger mt-3">Violation Signals</h6>
                                        @foreach(['tos_violation', 'copyright_violation', 'fraud_detected', 'illegal_content'] as $signal)
                                        <div class="form-group">
                                            <label class="form-label text-sm">{{ str_replace('_', ' ', ucfirst($signal)) }}</label>
                                            <input type="number" class="form-control form-control-sm" 
                                                   name="settings[{{ $signal }}]" 
                                                   value="{{ $config['signal_weights'][$signal] ?? 0 }}"
                                                   min="0" max="100" step="1">
                                        </div>
                                        @endforeach

                                        <h6 class="text-sm text-warning mt-3">Manual Signals</h6>
                                        @foreach(['user_report', 'admin_flag'] as $signal)
                                        <div class="form-group">
                                            <label class="form-label text-sm">{{ str_replace('_', ' ', ucfirst($signal)) }}</label>
                                            <input type="number" class="form-control form-control-sm" 
                                                   name="settings[{{ $signal }}]" 
                                                   value="{{ $config['signal_weights'][$signal] ?? 0 }}"
                                                   min="0" max="100" step="1">
                                        </div>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Save Signal Weights
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    // Handle all form submissions
    $('form[id$="-form"]').on('submit', function(e) {
        e.preventDefault();
        
        const $form = $(this);
        const formData = new FormData($form[0]);
        
        // Convert checkbox values
        $form.find('input[type="checkbox"]').each(function() {
            const $cb = $(this);
            if ($cb.is(':checked')) {
                formData.set($cb.attr('name'), '1');
            } else {
                formData.set($cb.attr('name'), '0');
            }
        });
        
        // Convert to JSON
        const data = {};
        for (let [key, value] of formData.entries()) {
            if (key.startsWith('settings[')) {
                const settingKey = key.match(/\[(.*?)\]/)[1];
                if (!data.settings) data.settings = {};
                data.settings[settingKey] = value;
            } else {
                data[key] = value;
            }
        }
        
        const $submitBtn = $form.find('button[type="submit"]');
        const originalText = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');
        
        $.ajax({
            url: '{{ route("risk-rules.update") }}',
            method: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            },
            success: function(response) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: response.message || 'Settings updated successfully',
                    timer: 2000,
                    showConfirmButton: false
                });
            },
            error: function(xhr) {
                const errorMsg = xhr.responseJSON?.message || 'Failed to update settings';
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: errorMsg
                });
            },
            complete: function() {
                $submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });
});
</script>
@endpush

@endsection
