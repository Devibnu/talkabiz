@extends('owner.layouts.app')

@section('page-title', 'Webhook Log')

@section('content')
{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <form action="{{ route('owner.logs.webhooks') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label text-sm">Event Type</label>
                <select name="event_type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="message.sent" {{ request('event_type') == 'message.sent' ? 'selected' : '' }}>message.sent</option>
                    <option value="message.delivered" {{ request('event_type') == 'message.delivered' ? 'selected' : '' }}>message.delivered</option>
                    <option value="message.read" {{ request('event_type') == 'message.read' ? 'selected' : '' }}>message.read</option>
                    <option value="message.failed" {{ request('event_type') == 'message.failed' ? 'selected' : '' }}>message.failed</option>
                    <option value="connection.status" {{ request('event_type') == 'connection.status' ? 'selected' : '' }}>connection.status</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="success" {{ request('status') == 'success' ? 'selected' : '' }}>Success</option>
                    <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Tanggal Dari</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Tanggal Sampai</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('owner.logs.webhooks') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Stats --}}
<div class="row mb-4">
    <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Webhooks</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-plug text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Success</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['success'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-check text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-sm-6">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Failed</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['failed'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-times text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Webhook Table --}}
<div class="card">
    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Webhook Log</h6>
        <span class="text-sm text-muted">Total: {{ number_format($logs->total()) }} entries</span>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Event Type</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Connection</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Response Time</th>
                        <th class="text-secondary opacity-7">Detail</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="ps-3">
                                <span class="text-xs">{{ $log->created_at->format('d M Y H:i:s') }}</span>
                            </td>
                            <td>
                                <span class="badge badge-sm bg-gradient-info">{{ $log->event_type }}</span>
                            </td>
                            <td>
                                @if($log->connection)
                                    <a href="{{ route('owner.whatsapp.show', $log->connection) }}" class="text-primary text-xs">
                                        {{ $log->connection->phone_number }}
                                    </a>
                                @else
                                    <span class="text-xs text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if($log->status == 'success') bg-gradient-success
                                    @else bg-gradient-danger
                                    @endif">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">{{ $log->response_time ?? '-' }} ms</span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-link text-info px-2 mb-0" 
                                        data-bs-toggle="modal" data-bs-target="#detailModal{{ $log->id }}">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>

                        {{-- Detail Modal --}}
                        <div class="modal fade" id="detailModal{{ $log->id }}" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Webhook Detail - {{ $log->event_type }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Event Type</label>
                                                <p class="font-weight-bold mb-0">{{ $log->event_type }}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Status</label>
                                                <p class="font-weight-bold mb-0">
                                                    <span class="badge @if($log->status == 'success') bg-success @else bg-danger @endif">
                                                        {{ $log->status }}
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Response Time</label>
                                                <p class="font-weight-bold mb-0">{{ $log->response_time ?? '-' }} ms</p>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Created At</label>
                                                <p class="font-weight-bold mb-0">{{ $log->created_at->format('d M Y H:i:s') }}</p>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="text-xs text-muted">Request Payload</label>
                                            <pre class="bg-gray-100 p-3 rounded text-xs" style="max-height: 200px; overflow: auto;">{{ json_encode($log->request_payload, JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                        <div class="mb-3">
                                            <label class="text-xs text-muted">Response</label>
                                            <pre class="bg-gray-100 p-3 rounded text-xs" style="max-height: 200px; overflow: auto;">{{ json_encode($log->response, JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                        @if($log->error_message)
                                            <div>
                                                <label class="text-xs text-muted">Error Message</label>
                                                <div class="alert alert-danger text-white">
                                                    {{ $log->error_message }}
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <p class="text-muted mb-0">Tidak ada log ditemukan</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3">
            {{ $logs->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
