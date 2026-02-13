@extends('owner.layouts.app')

@section('page-title', 'Semua Transaksi')

@section('content')
{{-- Filter --}}
<div class="card mb-4">
    <div class="card-body p-3">
        <form action="{{ route('owner.billing.transactions') }}" method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label class="form-label text-sm">Cari</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Klien atau ref..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Tipe</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="topup" {{ request('type') == 'topup' ? 'selected' : '' }}>Topup</option>
                    <option value="subscription" {{ request('type') == 'subscription' ? 'selected' : '' }}>Subscription</option>
                    <option value="addon" {{ request('type') == 'addon' ? 'selected' : '' }}>Addon</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-sm">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    <option value="success" {{ request('status') == 'success' ? 'selected' : '' }}>Success</option>
                    <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Pending</option>
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
            <div class="col-md-1">
                <button type="submit" class="btn bg-gradient-primary btn-sm mb-0 w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
            <div class="col-md-1">
                <a href="{{ route('owner.billing.transactions') }}" class="btn btn-outline-secondary btn-sm mb-0 w-100">
                    <i class="fas fa-redo"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Transaksi</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-receipt text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                        <h5 class="font-weight-bolder mb-0 text-success">Rp {{ number_format($stats['total_revenue'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-dollar-sign text-lg opacity-10"></i>
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
    <div class="col-xl-3 col-sm-6">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Pending</p>
                        <h5 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['pending'] ?? 0) }}</h5>
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
</div>

{{-- Transaction Table --}}
<div class="card">
    <div class="card-header pb-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Semua Transaksi</h6>
        <div>
            <a href="{{ route('owner.billing.index') }}" class="btn btn-sm btn-outline-primary mb-0">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID / Ref</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tipe</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Jumlah</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Metode</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tanggal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $tx)
                        <tr>
                            <td class="ps-3">
                                <span class="text-xs font-weight-bold">{{ $tx->transaction_code ?? '#' . $tx->id }}</span>
                            </td>
                            <td>
                                @if($tx->klien)
                                    <a href="{{ route('owner.clients.show', $tx->klien->id) }}" class="text-primary text-xs">
                                        {{ Str::limit($tx->klien->nama_perusahaan, 20) }}
                                    </a>
                                @elseif($tx->createdBy)
                                    <span class="text-xs">{{ $tx->createdBy->name }}</span>
                                @else
                                    <span class="text-xs text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-sm bg-gradient-info">
                                    {{ $tx->display_type }}
                                </span>
                            </td>
                            <td>
                                <span class="text-sm font-weight-bold">Rp {{ number_format($tx->final_price) }}</span>
                            </td>
                            <td>
                                <span class="badge badge-sm 
                                    @if(in_array($tx->status, ['success', 'completed'])) bg-gradient-success
                                    @elseif(in_array($tx->status, ['pending', 'waiting_payment'])) bg-gradient-warning
                                    @else bg-gradient-danger
                                    @endif">
                                    {{ ucfirst($tx->status) }}
                                </span>
                            </td>
                            <td>
                                <span class="text-xs">{{ $tx->payment_method ?? '-' }}</span>
                            </td>
                            <td>
                                <span class="text-xs text-secondary">{{ optional($tx->created_at)->format('d M Y H:i') ?? '-' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <p class="text-muted mb-0">Tidak ada transaksi ditemukan</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-4 py-3">
            {{ $transactions->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection
