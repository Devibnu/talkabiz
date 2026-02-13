@extends('owner.layouts.app')

@section('page-title', 'Message Log')

@section('content')
{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <form action="{{ route('owner.logs.messages') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label text-sm">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Nomor tujuan..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="sent" {{ request('status') == 'sent' ? 'selected' : '' }}>Sent</option>
                    <option value="delivered" {{ request('status') == 'delivered' ? 'selected' : '' }}>Delivered</option>
                    <option value="read" {{ request('status') == 'read' ? 'selected' : '' }}>Read</option>
                    <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Tipe</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="text" {{ request('type') == 'text' ? 'selected' : '' }}>Text</option>
                    <option value="template" {{ request('type') == 'template' ? 'selected' : '' }}>Template</option>
                    <option value="media" {{ request('type') == 'media' ? 'selected' : '' }}>Media</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Tanggal</label>
                <input type="date" name="date" class="form-control form-control-sm" value="{{ request('date') }}">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('owner.logs.messages') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Stats --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Pesan</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-envelope text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Delivered</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['delivered'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-check-double text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Read</p>
                        <h5 class="font-weight-bolder mb-0 text-info">{{ number_format($stats['read'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-eye text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
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

{{-- Message Table --}}
<div class="card">
    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Message Log</h6>
        <span class="text-sm text-muted">Total: {{ number_format($logs->total()) }} pesan</span>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tujuan</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tipe</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Cost</th>
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
                                @if($log->client)
                                    <a href="{{ route('owner.clients.show', $log->client) }}" class="text-primary text-xs">
                                        {{ Str::limit($log->client->nama_perusahaan, 20) }}
                                    </a>
                                @else
                                    <span class="text-xs text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="text-xs font-weight-bold">{{ $log->recipient_number }}</span>
                            </td>
                            <td>
                                <span class="badge badge-sm bg-gradient-secondary">{{ $log->message_type }}</span>
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if($log->status == 'sent') bg-gradient-success
                                    @elseif($log->status == 'delivered') bg-gradient-success
                                    @elseif($log->status == 'read') bg-gradient-info
                                    @elseif($log->status == 'pending') bg-gradient-warning
                                    @else bg-gradient-danger
                                    @endif">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">Rp {{ number_format($log->cost ?? 0) }}</span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-link text-info px-2 mb-0" 
                                        data-bs-toggle="modal" data-bs-target="#messageModal{{ $log->id }}">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>

                        {{-- Message Detail Modal --}}
                        <div class="modal fade" id="messageModal{{ $log->id }}" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Message Detail</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Klien</label>
                                                <p class="font-weight-bold mb-0">{{ $log->client?->nama_perusahaan ?? '-' }}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Nomor Tujuan</label>
                                                <p class="font-weight-bold mb-0">{{ $log->recipient_number }}</p>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="text-xs text-muted">Tipe</label>
                                                <p class="font-weight-bold mb-0">{{ $log->message_type }}</p>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="text-xs text-muted">Status</label>
                                                <p class="font-weight-bold mb-0">
                                                    <span class="badge 
                                                        @if(in_array($log->status, ['sent', 'delivered', 'read'])) bg-success
                                                        @elseif($log->status == 'pending') bg-warning
                                                        @else bg-danger
                                                        @endif">
                                                        {{ $log->status }}
                                                    </span>
                                                </p>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="text-xs text-muted">Cost</label>
                                                <p class="font-weight-bold mb-0">Rp {{ number_format($log->cost ?? 0) }}</p>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Sent At</label>
                                                <p class="font-weight-bold mb-0">{{ $log->created_at->format('d M Y H:i:s') }}</p>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="text-xs text-muted">Delivered At</label>
                                                <p class="font-weight-bold mb-0">
                                                    {{ $log->delivered_at ? $log->delivered_at->format('d M Y H:i:s') : '-' }}
                                                </p>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="text-xs text-muted">Message Content</label>
                                            <div class="bg-gray-100 p-3 rounded">
                                                @if($log->message_type == 'template')
                                                    <p class="text-xs mb-1"><strong>Template:</strong> {{ $log->template_name ?? '-' }}</p>
                                                    <pre class="text-xs mb-0" style="white-space: pre-wrap;">{{ json_encode($log->template_params ?? [], JSON_PRETTY_PRINT) }}</pre>
                                                @else
                                                    <p class="text-sm mb-0">{{ $log->content ?? 'No content' }}</p>
                                                @endif
                                            </div>
                                        </div>
                                        @if($log->error_message)
                                            <div>
                                                <label class="text-xs text-muted">Error</label>
                                                <div class="alert alert-danger text-white mb-0">
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
                            <td colspan="7" class="text-center py-4">
                                <p class="text-muted mb-0">Tidak ada log pesan ditemukan</p>
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
