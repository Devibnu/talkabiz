@extends('owner.layouts.app')

@section('page-title', 'Audit Trail & Immutable Ledger')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Logs</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                            <i class="fas fa-database text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Hari Ini</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['today'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-clock text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Finansial</p>
                        <h5 class="font-weight-bolder mb-0 text-info">{{ number_format($stats['financial'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-money-bill-wave text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Gagal</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['failed'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-exclamation-triangle text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Reversal</p>
                        <h5 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['reversals'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                            <i class="fas fa-undo text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Log Terakhir</p>
                        <p class="text-xs text-muted mb-0">
                            {{ $stats['last_entry'] ? \Carbon\Carbon::parse($stats['last_entry'])->diffForHumans() : '-' }}
                        </p>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-check-circle text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Quick Actions --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-md-4">
                        <a href="{{ route('owner.audit-trail.integrity') }}" class="btn bg-gradient-warning w-100 mb-0">
                            <i class="fas fa-shield-alt me-2"></i> Integrity Check
                        </a>
                    </div>
                    <div class="col-md-4">
                        <a href="{{ route('owner.audit-trail.export-csv', request()->query()) }}" class="btn bg-gradient-success w-100 mb-0">
                            <i class="fas fa-download me-2"></i> Export CSV
                        </a>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-secondary w-100 mb-0" onclick="document.getElementById('filterForm').classList.toggle('d-none')">
                            <i class="fas fa-filter me-2"></i> Toggle Filter
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filter Form --}}
<div class="row mb-4 {{ request()->hasAny(['from','to','actor_type','entity_type','category','status','search','action','actor_id','entity_id']) ? '' : 'd-none' }}" id="filterForm">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Audit Logs</h6>
                    <a href="{{ route('owner.audit-trail.index') }}" class="btn btn-sm btn-outline-secondary mb-0">
                        <i class="fas fa-times me-1"></i> Reset
                    </a>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('owner.audit-trail.index') }}">
                    <div class="row">
                        {{-- Date Range --}}
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Dari Tanggal</label>
                            <input type="date" name="from" class="form-control" value="{{ request('from') }}">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Sampai Tanggal</label>
                            <input type="date" name="to" class="form-control" value="{{ request('to') }}">
                        </div>
                        {{-- Actor Type --}}
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Actor Type</label>
                            <select name="actor_type" class="form-select">
                                <option value="">-- Semua --</option>
                                @foreach($actorTypes as $type)
                                    <option value="{{ $type }}" {{ request('actor_type') === $type ? 'selected' : '' }}>
                                        {{ ucfirst($type) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Entity Type --}}
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Entity Type</label>
                            <select name="entity_type" class="form-select">
                                <option value="">-- Semua --</option>
                                @foreach($entityTypes as $type)
                                    <option value="{{ $type }}" {{ request('entity_type') === $type ? 'selected' : '' }}>
                                        {{ $type }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        {{-- Category --}}
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Kategori</label>
                            <select name="category" class="form-select">
                                <option value="">-- Semua --</option>
                                @foreach($categories as $cat)
                                    <option value="{{ $cat }}" {{ request('category') === $cat ? 'selected' : '' }}>
                                        {{ ucfirst($cat) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Status --}}
                        <div class="col-md-3 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Status</label>
                            <select name="status" class="form-select">
                                <option value="">-- Semua --</option>
                                @foreach($statuses as $s)
                                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                                        {{ ucfirst($s) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {{-- Search --}}
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-xs text-uppercase font-weight-bold">Search</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Action, description, entity, correlation ID..." 
                                   value="{{ request('search') }}">
                        </div>
                        {{-- Submit --}}
                        <div class="col-md-2 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn bg-gradient-primary w-100 mb-0">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Immutable Ledger Notice --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-info text-white font-weight-bold d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #2152ff 0%, #21d4fd 100%); border: none;">
            <i class="fas fa-lock me-3 text-lg"></i>
            <div>
                <strong>Immutable Ledger</strong> — Semua log bersifat <strong>append-only</strong>. 
                Tidak ada tombol edit/hapus. Data tidak bisa diubah atau dihapus. 
                Koreksi dilakukan melalui record baru (reversal/adjustment).
            </div>
        </div>
    </div>
</div>

{{-- Audit Logs Table --}}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="fas fa-shield-alt me-2"></i>Audit Trail
                        <span class="badge bg-gradient-dark ms-2">{{ number_format($logs->total()) }} records</span>
                    </h6>
                </div>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Actor</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Entity</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Kategori</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $log)
                                <tr>
                                    <td class="ps-3">
                                        <div>
                                            <span class="text-xs font-weight-bold">
                                                {{ $log->occurred_at ? $log->occurred_at->format('d M Y') : ($log->created_at ? $log->created_at->format('d M Y') : '-') }}
                                            </span>
                                        </div>
                                        <div>
                                            <span class="text-xs text-muted">
                                                {{ $log->occurred_at ? $log->occurred_at->format('H:i:s') : ($log->created_at ? $log->created_at->format('H:i:s') : '') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm 
                                            @if($log->actor_type === 'user') bg-gradient-primary
                                            @elseif($log->actor_type === 'admin') bg-gradient-warning
                                            @elseif($log->actor_type === 'system') bg-gradient-dark
                                            @elseif($log->actor_type === 'webhook') bg-gradient-info
                                            @elseif($log->actor_type === 'cron') bg-gradient-secondary
                                            @else bg-gradient-secondary
                                            @endif">
                                            {{ $log->actor_type ?? '-' }}
                                        </span>
                                        <div class="mt-1">
                                            <span class="text-xs text-muted">
                                                {{ $log->actor_email ?? ('ID: ' . ($log->actor_id ?? '-')) }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">
                                            {{ Str::limit($log->action ?? '-', 30) }}
                                        </span>
                                        @if($log->description)
                                            <div class="mt-1">
                                                <span class="text-xs text-muted">{{ Str::limit($log->description, 40) }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($log->entity_type)
                                            <a href="{{ route('owner.audit-trail.entity-history', ['entityType' => $log->entity_type, 'entityId' => $log->entity_id]) }}" 
                                               class="text-xs font-weight-bold text-primary">
                                                {{ class_basename($log->entity_type) }}
                                            </a>
                                            <div>
                                                <span class="text-xs text-muted">#{{ $log->entity_id ?? '-' }}</span>
                                            </div>
                                        @else
                                            <span class="text-xs text-muted">-</span>
                                        @endif
                                    </td>
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
                                            <span class="text-xs text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($log->status === 'success')
                                            <span class="badge badge-sm bg-gradient-success">success</span>
                                        @elseif($log->status === 'failed')
                                            <span class="badge badge-sm bg-gradient-danger">failed</span>
                                        @elseif($log->status === 'pending')
                                            <span class="badge badge-sm bg-gradient-warning">pending</span>
                                        @else
                                            <span class="badge badge-sm bg-gradient-secondary">{{ $log->status ?? '-' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('owner.audit-trail.show', $log->id) }}" 
                                           class="btn btn-sm btn-outline-primary mb-0 px-3" title="Lihat Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="fas fa-shield-alt text-muted mb-3" style="font-size: 3rem;"></i>
                                        <p class="text-muted mb-0">Tidak ada audit log ditemukan</p>
                                        <p class="text-xs text-muted">Coba ubah filter atau tunggu aktivitas baru</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($logs->hasPages())
                    <div class="d-flex justify-content-center mt-4 px-4 pb-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
