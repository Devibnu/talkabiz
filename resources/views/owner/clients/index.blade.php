@extends('owner.layouts.app')

@section('page-title', 'Klien UMKM')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Klien</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-users text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Aktif</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['aktif']) }}</h5>
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
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Pending</p>
                        <h5 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['pending']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                            <i class="fas fa-clock text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Suspend</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['suspend']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-ban text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filter & Search --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <form action="{{ route('owner.clients.index') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label text-sm">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Nama, email, atau WA..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="aktif" {{ request('status') == 'aktif' ? 'selected' : '' }}>Aktif</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="suspend" {{ request('status') == 'suspend' ? 'selected' : '' }}>Suspend</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">WA Status</label>
                <select name="wa_status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="connected" {{ request('wa_status') == 'connected' ? 'selected' : '' }}>Connected</option>
                    <option value="pending" {{ request('wa_status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="failed" {{ request('wa_status') == 'failed' ? 'selected' : '' }}>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('owner.clients.index') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
                    <i class="fas fa-redo me-1"></i> Reset
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Alerts --}}
@if(session('success'))
    <div class="alert alert-success text-white" role="alert">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger text-white" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
    </div>
@endif

{{-- Client Table --}}
<div class="card">
    <div class="card-header pb-0">
        <h6 class="mb-0">Daftar Klien</h6>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">WhatsApp</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Paket</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Registered</th>
                        <th class="text-secondary opacity-7">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clients as $client)
                        <tr>
                            <td>
                                <div class="d-flex px-2 py-1">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">{{ $client->nama_perusahaan }}</h6>
                                        <p class="text-xs text-secondary mb-0">
                                            {{ $client->email ?? $client->user?->email ?? '-' }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @php $waConn = $client->whatsappConnections->first(); @endphp
                                @if($waConn)
                                    <span class="text-xs">{{ $waConn->phone_number }}</span>
                                    <br>
                                    <span class="badge badge-sm 
                                        @if($waConn->status == 'connected') bg-gradient-success
                                        @elseif($waConn->status == 'pending') bg-gradient-warning
                                        @else bg-gradient-danger
                                        @endif">
                                        {{ $waConn->status }}
                                    </span>
                                @else
                                    <span class="text-xs text-muted">Belum connect</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if($client->status == 'aktif') bg-gradient-success
                                    @elseif($client->status == 'pending') bg-gradient-warning
                                    @else bg-gradient-danger
                                    @endif">
                                    {{ ucfirst($client->status) }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">
                                    {{ $client->user?->currentPlan?->nama ?? 'No Plan' }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs text-secondary">
                                    {{ $client->created_at->format('d M Y') }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('owner.clients.show', $client) }}" 
                                   class="btn btn-link text-info px-2 mb-0">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <div class="dropdown d-inline">
                                    <button class="btn btn-link text-secondary px-2 mb-0 dropdown-toggle" 
                                            type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        @if($client->status == 'pending')
                                            <li>
                                                <form action="{{ route('owner.clients.approve', $client) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fas fa-check me-2"></i> Approve
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                        @if($client->status == 'aktif')
                                            <li>
                                                <button type="button" class="dropdown-item text-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#suspendModal{{ $client->id }}">
                                                    <i class="fas fa-ban me-2"></i> Suspend
                                                </button>
                                            </li>
                                        @endif
                                        @if($client->status == 'suspend')
                                            <li>
                                                <form action="{{ route('owner.clients.activate', $client) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fas fa-undo me-2"></i> Aktifkan
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                    </ul>
                                </div>
                            </td>
                        </tr>

                        {{-- Suspend Modal --}}
                        <div class="modal fade" id="suspendModal{{ $client->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="{{ route('owner.clients.suspend', $client) }}" method="POST">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title">Suspend {{ $client->nama_perusahaan }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="mb-3">
                                                <label class="form-label">Alasan Suspend</label>
                                                <textarea name="reason" class="form-control" rows="3" required 
                                                          placeholder="Masukkan alasan suspend..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                            <button type="submit" class="btn bg-gradient-danger">Suspend</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <p class="text-muted mb-0">Tidak ada klien ditemukan</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3">
            {{ $clients->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
