@extends('owner.layouts.app')

@section('page-title', 'WhatsApp Control')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Koneksi</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                            <i class="fab fa-whatsapp text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Connected</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['connected']) }}</h5>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Failed</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['failed']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-times-circle text-lg opacity-10"></i>
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
        <form action="{{ route('owner.whatsapp.index') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label text-sm">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Nomor atau nama klien..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="connected" {{ request('status') == 'connected' ? 'selected' : '' }}>Connected</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="failed" {{ request('status') == 'failed' ? 'selected' : '' }}>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
            </div>
            <div class="col-md-2">
                <a href="{{ route('owner.whatsapp.index') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
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

{{-- Connections Table --}}
<div class="card">
    <div class="card-header pb-0">
        <h6 class="mb-0">Daftar Koneksi WhatsApp</h6>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nomor</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Last Sync</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Pesan Hari Ini</th>
                        <th class="text-secondary opacity-7">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($connections as $conn)
                        <tr>
                            <td class="ps-3">
                                <div class="d-flex align-items-center">
                                    <div class="icon icon-shape icon-sm bg-gradient-success shadow text-center me-2">
                                        <i class="fab fa-whatsapp text-white opacity-10"></i>
                                    </div>
                                    <span class="text-sm font-weight-bold">{{ $conn->phone_number }}</span>
                                </div>
                            </td>
                            <td>
                                @if($conn->client)
                                    <a href="{{ route('owner.clients.show', $conn->client) }}" class="text-primary">
                                        {{ $conn->client->nama_perusahaan }}
                                    </a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if($conn->status == 'connected') bg-gradient-success
                                    @elseif($conn->status == 'pending') bg-gradient-warning
                                    @else bg-gradient-danger
                                    @endif">
                                    {{ $conn->status }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">
                                    {{ $conn->last_sync_at ? $conn->last_sync_at->diffForHumans() : 'Never' }}
                                </span>
                            </td>
                            <td>
                                <span class="text-sm">{{ $conn->messages_today ?? 0 }}</span>
                            </td>
                            <td>
                                <a href="{{ route('owner.whatsapp.show', $conn) }}" 
                                   class="btn btn-link text-info px-2 mb-0" title="Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <div class="dropdown d-inline">
                                    <button class="btn btn-link text-secondary px-2 mb-0 dropdown-toggle" 
                                            type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <form action="{{ route('owner.whatsapp.force-connect', $conn) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="dropdown-item text-success">
                                                    <i class="fas fa-plug me-2"></i> Force Connect
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form action="{{ route('owner.whatsapp.force-pending', $conn) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="dropdown-item text-warning">
                                                    <i class="fas fa-clock me-2"></i> Force Pending
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form action="{{ route('owner.whatsapp.force-fail', $conn) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fas fa-times me-2"></i> Force Fail
                                                </button>
                                            </form>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form action="{{ route('owner.whatsapp.re-verify', $conn) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="dropdown-item text-info">
                                                    <i class="fas fa-sync me-2"></i> Re-Verify
                                                </button>
                                            </form>
                                        </li>
                                        <li>
                                            <form action="{{ route('owner.whatsapp.disconnect', $conn) }}" method="POST"
                                                  id="disconnect-form-{{ $conn->id }}"
                                                  onsubmit="return false;">
                                                @csrf
                                                <button type="button" class="dropdown-item text-danger" onclick="confirmDisconnect({{ $conn->id }}, '{{ $conn->phone_number }}')">
                                                    <i class="fas fa-unlink me-2"></i> Disconnect
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <p class="text-muted mb-0">Tidak ada koneksi ditemukan</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3">
            {{ $connections->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function confirmDisconnect(connId, phoneNumber) {
    OwnerPopup.confirmDanger({
        title: 'Disconnect WhatsApp?',
        text: `
            <p class="mb-2">Anda akan memutuskan koneksi nomor:</p>
            <p class="fw-bold mb-3">${phoneNumber}</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                    <strong>Dampak:</strong> Klien harus menghubungkan ulang nomor ini.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-unlink me-1"></i> Ya, Disconnect',
        onConfirm: () => {
            document.getElementById('disconnect-form-' + connId).submit();
        }
    });
}
</script>
@endpush
