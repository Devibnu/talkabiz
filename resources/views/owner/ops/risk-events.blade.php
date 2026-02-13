@extends('layouts.owner')

@section('title', 'Risk Events')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-danger shadow-lg">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h2 class="text-white mb-1">
                                ⚠️ Risk Events
                            </h2>
                            <p class="text-white opacity-8 mb-0">
                                Monitor and mitigate operational risks
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body py-3">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <ul class="nav nav-pills">
                                <li class="nav-item">
                                    <a class="nav-link {{ $status === 'open' ? 'active' : '' }}" 
                                       href="{{ route('owner.ops.risk-events', ['status' => 'open']) }}">
                                        Open
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $status === 'acknowledged' ? 'active' : '' }}" 
                                       href="{{ route('owner.ops.risk-events', ['status' => 'acknowledged']) }}">
                                        Acknowledged
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $status === 'mitigated' ? 'active' : '' }}" 
                                       href="{{ route('owner.ops.risk-events', ['status' => 'mitigated']) }}">
                                        Mitigated
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $status === 'resolved' ? 'active' : '' }}" 
                                       href="{{ route('owner.ops.risk-events', ['status' => 'resolved']) }}">
                                        Resolved
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link {{ $status === 'all' ? 'active' : '' }}" 
                                       href="{{ route('owner.ops.risk-events', ['status' => 'all']) }}">
                                        All
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6 text-end">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="{{ route('owner.ops.risk-events', array_merge(request()->query(), ['severity' => ''])) }}" 
                                   class="btn btn-outline-secondary {{ !$severity ? 'active' : '' }}">All</a>
                                <a href="{{ route('owner.ops.risk-events', array_merge(request()->query(), ['severity' => 'critical'])) }}" 
                                   class="btn btn-outline-danger {{ $severity === 'critical' ? 'active' : '' }}">Critical</a>
                                <a href="{{ route('owner.ops.risk-events', array_merge(request()->query(), ['severity' => 'high'])) }}" 
                                   class="btn btn-outline-warning {{ $severity === 'high' ? 'active' : '' }}">High</a>
                                <a href="{{ route('owner.ops.risk-events', array_merge(request()->query(), ['severity' => 'medium'])) }}" 
                                   class="btn btn-outline-info {{ $severity === 'medium' ? 'active' : '' }}">Medium</a>
                                <a href="{{ route('owner.ops.risk-events', array_merge(request()->query(), ['severity' => 'low'])) }}" 
                                   class="btn btn-outline-secondary {{ $severity === 'low' ? 'active' : '' }}">Low</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Events List --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body px-0 pt-0 pb-2">
                    @if($events->count() > 0)
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Severity</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Event</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Time</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end pe-4">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($events as $event)
                                        @php
                                            $severityColors = [
                                                'critical' => 'danger',
                                                'high' => 'warning',
                                                'medium' => 'info',
                                                'low' => 'secondary'
                                            ];
                                            $statusColors = [
                                                'open' => 'danger',
                                                'acknowledged' => 'warning',
                                                'mitigated' => 'info',
                                                'resolved' => 'success'
                                            ];
                                        @endphp
                                        <tr>
                                            <td class="ps-4">
                                                <span class="badge bg-{{ $severityColors[$event->severity] ?? 'secondary' }}">
                                                    {{ strtoupper($event->severity) }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold">{{ $event->event_type }}</span>
                                                <br>
                                                <small class="text-muted">{{ Str::limit($event->description, 80) }}</small>
                                                @if($event->mitigation_notes)
                                                    <br>
                                                    <small class="text-info">
                                                        <i class="fas fa-comment me-1"></i>
                                                        {{ Str::limit($event->mitigation_notes, 50) }}
                                                    </small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $statusColors[$event->status] ?? 'secondary' }}">
                                                    {{ strtoupper($event->status) }}
                                                </span>
                                                @if($event->mitigated_by)
                                                    <br>
                                                    <small class="text-muted">by {{ $event->mitigated_by }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="text-xs">
                                                    {{ \Carbon\Carbon::parse($event->created_at)->format('d M Y') }}
                                                </span>
                                                <br>
                                                <small class="text-muted">
                                                    {{ \Carbon\Carbon::parse($event->created_at)->diffForHumans() }}
                                                </small>
                                            </td>
                                            <td class="text-end pe-4">
                                                @if(in_array($event->status, ['open', 'acknowledged']))
                                                    <button class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#mitigateModal{{ $event->id }}">
                                                        Mitigate
                                                    </button>
                                                @elseif($event->status === 'mitigated')
                                                    <form action="{{ route('owner.ops.mitigate-risk', $event->id) }}" 
                                                          method="POST" class="d-inline">
                                                        @csrf
                                                        <input type="hidden" name="status" value="resolved">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            Resolve
                                                        </button>
                                                    </form>
                                                @else
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check"></i> Resolved
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>

                                        {{-- Mitigate Modal --}}
                                        <div class="modal fade" id="mitigateModal{{ $event->id }}" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form action="{{ route('owner.ops.mitigate-risk', $event->id) }}" method="POST">
                                                        @csrf
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Mitigate Risk Event</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="alert alert-{{ $severityColors[$event->severity] ?? 'secondary' }}">
                                                                <strong>{{ $event->event_type }}</strong>
                                                                <p class="mb-0 small">{{ $event->description }}</p>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Status</label>
                                                                <select name="status" class="form-select" required>
                                                                    <option value="acknowledged">Acknowledged</option>
                                                                    <option value="mitigated">Mitigated</option>
                                                                    <option value="resolved">Resolved</option>
                                                                </select>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label">Notes</label>
                                                                <textarea name="mitigation_notes" class="form-control" rows="3"
                                                                          placeholder="What action was taken?"></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-primary">Save</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        <div class="px-4 pt-3">
                            {{ $events->links() }}
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-shield-alt fa-3x text-success mb-3"></i>
                            <p class="text-muted mb-0">No risk events found</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Back Button --}}
    <div class="row mt-4">
        <div class="col-12">
            <a href="{{ route('owner.ops.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to Daily Ops
            </a>
        </div>
    </div>
</div>
@endsection
