@extends('owner.layouts.app')

@section('page-title', 'Activity Log')

@section('content')
{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <form action="{{ route('owner.logs.activity') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label text-sm">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Action atau description..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($users ?? [] as $user)
                        <option value="{{ $user->id }}" {{ request('user_id') == $user->id ? 'selected' : '' }}>
                            {{ $user->name }}
                        </option>
                    @endforeach
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
            <div class="col-md-1">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <div class="col-md-1">
                <a href="{{ route('owner.logs.activity') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
                    <i class="fas fa-redo"></i>
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Activity Table --}}
<div class="card">
    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Activity Log</h6>
        <span class="text-sm text-muted">Total: {{ number_format($logs->total()) }} entries</span>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">User</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Description</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="ps-3">
                                <span class="text-xs">{{ $log->created_at->format('d M Y H:i:s') }}</span>
                            </td>
                            <td>
                                <span class="text-xs font-weight-bold">{{ $log->user?->name ?? 'System' }}</span>
                                @if($log->user)
                                    <br><span class="text-xs text-muted">{{ $log->user->email }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if(str_contains(strtolower($log->action), 'login')) bg-gradient-info
                                    @elseif(str_contains(strtolower($log->action), 'logout')) bg-gradient-secondary
                                    @elseif(str_contains(strtolower($log->action), 'create')) bg-gradient-success
                                    @elseif(str_contains(strtolower($log->action), 'update')) bg-gradient-warning
                                    @elseif(str_contains(strtolower($log->action), 'delete')) bg-gradient-danger
                                    @else bg-gradient-dark
                                    @endif">
                                    {{ $log->action }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">{{ Str::limit($log->description, 50) }}</span>
                            </td>
                            <td>
                                <span class="text-xs text-muted">{{ $log->ip_address ?? '-' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-4">
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
