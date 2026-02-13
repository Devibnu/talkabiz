@extends('owner.layouts.app')

@section('page-title', 'Detail Audit Log #' . $log->id)

@section('content')
{{-- Breadcrumb --}}
<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item">
                    <a href="{{ route('owner.audit-trail.index') }}" class="text-primary">
                        <i class="fas fa-shield-alt me-1"></i> Audit Trail
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">Log #{{ $log->id }}</li>
            </ol>
        </nav>
    </div>
</div>

{{-- Integrity Banner --}}
<div class="row mb-4">
    <div class="col-12">
        @if($integrityValid)
            <div class="alert text-white font-weight-bold d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%); border: none;">
                <i class="fas fa-check-shield me-3 text-lg"></i>
                <div>
                    <i class="fas fa-check-circle me-1"></i>
                    <strong>INTEGRITY VALID</strong> — Checksum cocok. Data log ini tidak pernah dimanipulasi.
                </div>
            </div>
        @else
            <div class="alert text-white font-weight-bold d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #ea0606 0%, #ff667c 100%); border: none;">
                <i class="fas fa-exclamation-triangle me-3 text-lg"></i>
                <div>
                    <i class="fas fa-times-circle me-1"></i>
                    <strong>INTEGRITY FAILED</strong> — Checksum tidak cocok! Data mungkin telah dimanipulasi. Periksa segera.
                </div>
            </div>
        @endif
    </div>
</div>

<div class="row">
    {{-- Left Column: Metadata --}}
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Metadata</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm align-items-center mb-0">
                    <tbody>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted" style="width: 35%;">ID</td>
                            <td class="text-sm">{{ $log->id }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">UUID</td>
                            <td class="text-sm"><code class="text-xs">{{ $log->log_uuid ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Occurred At</td>
                            <td class="text-sm">
                                {{ $log->occurred_at ? $log->occurred_at->format('d M Y H:i:s') : ($log->created_at ? $log->created_at->format('d M Y H:i:s') : '-') }}
                                <span class="text-muted text-xs ms-1">
                                    ({{ $log->occurred_at ? $log->occurred_at->diffForHumans() : '' }})
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Action</td>
                            <td class="text-sm font-weight-bold">{{ $log->action ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Description</td>
                            <td class="text-sm">{{ $log->description ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Category</td>
                            <td>
                                @if($log->action_category)
                                    <span class="badge badge-sm
                                        @if($log->action_category === 'billing') bg-gradient-success
                                        @elseif($log->action_category === 'auth') bg-gradient-info
                                        @elseif($log->action_category === 'config') bg-gradient-warning
                                        @elseif($log->action_category === 'trust_safety') bg-gradient-danger
                                        @else bg-gradient-secondary
                                        @endif">
                                        {{ $log->action_category }}
                                    </span>
                                @else
                                    <span class="text-muted text-xs">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Status</td>
                            <td>
                                @if($log->status === 'success')
                                    <span class="badge badge-sm bg-gradient-success">success</span>
                                @elseif($log->status === 'failed')
                                    <span class="badge badge-sm bg-gradient-danger">failed</span>
                                @else
                                    <span class="badge badge-sm bg-gradient-secondary">{{ $log->status ?? '-' }}</span>
                                @endif
                            </td>
                        </tr>
                        @if($log->failure_reason)
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Failure Reason</td>
                            <td class="text-sm text-danger">{{ $log->failure_reason }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Data Classification</td>
                            <td>
                                <span class="badge badge-sm
                                    @if($log->data_classification === 'restricted') bg-gradient-danger
                                    @elseif($log->data_classification === 'confidential') bg-gradient-warning
                                    @elseif($log->data_classification === 'internal') bg-gradient-info
                                    @else bg-gradient-secondary
                                    @endif">
                                    {{ $log->data_classification ?? 'public' }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Right Column: Actor & Entity --}}
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-user-shield me-2"></i>Actor & Entity</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm align-items-center mb-0">
                    <tbody>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted" style="width: 35%;">Actor Type</td>
                            <td>
                                <span class="badge badge-sm
                                    @if($log->actor_type === 'user') bg-gradient-primary
                                    @elseif($log->actor_type === 'admin') bg-gradient-warning
                                    @elseif($log->actor_type === 'system') bg-gradient-dark
                                    @else bg-gradient-secondary
                                    @endif">
                                    {{ $log->actor_type ?? '-' }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Actor ID</td>
                            <td class="text-sm">{{ $log->actor_id ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Actor Email</td>
                            <td class="text-sm">{{ $log->actor_email ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">IP Address</td>
                            <td class="text-sm"><code class="text-xs">{{ $log->actor_ip ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">User Agent</td>
                            <td class="text-xs text-muted" style="word-break: break-all;">
                                {{ Str::limit($log->actor_user_agent ?? '-', 80) }}
                            </td>
                        </tr>

                        <tr><td colspan="2"><hr class="horizontal dark my-1"></td></tr>

                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Entity Type</td>
                            <td class="text-sm font-weight-bold">
                                @if($log->entity_type)
                                    <a href="{{ route('owner.audit-trail.entity-history', ['entityType' => $log->entity_type, 'entityId' => $log->entity_id]) }}" class="text-primary">
                                        {{ class_basename($log->entity_type) }}
                                    </a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Entity ID</td>
                            <td class="text-sm">{{ $log->entity_id ?? '-' }}</td>
                        </tr>
                        @if($log->entity_uuid)
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Entity UUID</td>
                            <td class="text-sm"><code class="text-xs">{{ $log->entity_uuid }}</code></td>
                        </tr>
                        @endif
                        @if($log->klien_id)
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Klien ID</td>
                            <td class="text-sm">{{ $log->klien_id }}</td>
                        </tr>
                        @endif

                        <tr><td colspan="2"><hr class="horizontal dark my-1"></td></tr>

                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Correlation ID</td>
                            <td class="text-sm"><code class="text-xs">{{ $log->correlation_id ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Session ID</td>
                            <td class="text-sm"><code class="text-xs">{{ $log->session_id ?? '-' }}</code></td>
                        </tr>
                        <tr>
                            <td class="text-xs font-weight-bold text-uppercase text-muted">Checksum</td>
                            <td class="text-xs" style="word-break: break-all;">
                                <code>{{ $log->checksum ?? '-' }}</code>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Before / After Data Diff --}}
<div class="row mb-4">
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-minus-circle text-danger me-2"></i>Before (Old Values)
                </h6>
            </div>
            <div class="card-body">
                @php
                    $oldValues = $log->old_values;
                    if (is_string($oldValues)) {
                        $oldValues = json_decode($oldValues, true);
                    }
                @endphp
                @if($oldValues && count((array)$oldValues) > 0)
                    <div class="bg-light rounded p-3" style="max-height: 400px; overflow-y: auto;">
                        <pre class="mb-0 text-xs" style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($oldValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @else
                    <div class="text-center py-4">
                        <i class="fas fa-inbox text-muted mb-2" style="font-size: 2rem;"></i>
                        <p class="text-muted text-sm mb-0">Tidak ada data sebelumnya</p>
                        <p class="text-xs text-muted">Kemungkinan ini adalah record create baru</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-plus-circle text-success me-2"></i>After (New Values)
                </h6>
            </div>
            <div class="card-body">
                @php
                    $newValues = $log->new_values;
                    if (is_string($newValues)) {
                        $newValues = json_decode($newValues, true);
                    }
                @endphp
                @if($newValues && count((array)$newValues) > 0)
                    <div class="bg-light rounded p-3" style="max-height: 400px; overflow-y: auto;">
                        <pre class="mb-0 text-xs" style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($newValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @else
                    <div class="text-center py-4">
                        <i class="fas fa-inbox text-muted mb-2" style="font-size: 2rem;"></i>
                        <p class="text-muted text-sm mb-0">Tidak ada data baru</p>
                        <p class="text-xs text-muted">Kemungkinan ini adalah record delete</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Context Data --}}
@php
    $contextData = $log->context;
    if (is_string($contextData)) {
        $contextData = json_decode($contextData, true);
    }
@endphp
@if($contextData && count((array)$contextData) > 0)
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-code me-2"></i>Context Data</h6>
            </div>
            <div class="card-body">
                <div class="bg-light rounded p-3" style="max-height: 300px; overflow-y: auto;">
                    <pre class="mb-0 text-xs" style="white-space: pre-wrap; word-break: break-word;">{{ json_encode($contextData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Related Logs (Correlation) --}}
@if($relatedLogs->count() > 0)
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-link me-2"></i>Related Logs
                    <span class="badge bg-gradient-info ms-2">correlation: {{ Str::limit($log->correlation_id, 16) }}</span>
                    <span class="badge bg-gradient-dark ms-1">{{ $relatedLogs->count() }} records</span>
                </h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Entity</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($relatedLogs as $related)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold">#{{ $related->id }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">
                                            {{ $related->occurred_at ? $related->occurred_at->format('d M H:i:s') : '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ Str::limit($related->action, 25) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">
                                            {{ $related->entity_type ? class_basename($related->entity_type) : '-' }}
                                            #{{ $related->entity_id ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($related->status === 'success')
                                            <span class="badge badge-sm bg-gradient-success">success</span>
                                        @elseif($related->status === 'failed')
                                            <span class="badge badge-sm bg-gradient-danger">failed</span>
                                        @else
                                            <span class="badge badge-sm bg-gradient-secondary">{{ $related->status ?? '-' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('owner.audit-trail.show', $related->id) }}" 
                                           class="btn btn-sm btn-outline-primary mb-0 px-2">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Navigation --}}
<div class="row">
    <div class="col-12">
        <a href="{{ route('owner.audit-trail.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
        </a>
    </div>
</div>
@endsection
