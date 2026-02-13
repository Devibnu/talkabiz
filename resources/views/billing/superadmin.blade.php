@extends('layouts.user_type.auth')

@section('content')
<style>
.stat-card {
    border-radius: 16px;
    overflow: hidden;
}
.stat-card .card-body {
    padding: 20px;
}
.transaction-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #8392ab;
    font-weight: 600;
}
.transaction-table td {
    font-size: 0.875rem;
    vertical-align: middle;
}
.status-badge {
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 600;
}
.status-pending { background: #fef4e5; color: #f5a623; }
.status-paid { background: #e6f9f0; color: #2dce89; }
.status-failed, .status-expired { background: #fde8e8; color: #f5365c; }
.status-challenge { background: #fff3cd; color: #856404; }

.pending-card {
    border-left: 4px solid #f5a623;
}
</style>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h4 class="font-weight-bolder mb-0">Billing Monitoring</h4>
                <p class="text-sm text-secondary mb-0">Dashboard transaksi semua klien (Super Admin)</p>
            </div>
            <span class="badge bg-gradient-dark py-2 px-3">
                <i class="ni ni-single-02 me-1"></i> Super Admin View
            </span>
        </div>
    </div>
</div>

{{-- Stats Cards Row 1 --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card stat-card bg-gradient-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-white text-sm mb-1">Top Up Bulan Ini</p>
                        <h3 class="text-white font-weight-bolder mb-0">Rp {{ number_format($stats['total_topup_bulan_ini'] ?? 0, 0, ',', '.') }}</h3>
                        <p class="text-white text-xs opacity-8 mt-1 mb-0">Total top up terkonfirmasi</p>
                    </div>
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                        <i class="ni ni-money-coins text-success text-lg opacity-10"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card stat-card bg-gradient-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-white text-sm mb-1">Pemakaian Bulan Ini</p>
                        <h3 class="text-white font-weight-bolder mb-0">Rp {{ number_format($stats['total_debit_bulan_ini'] ?? 0, 0, ',', '.') }}</h3>
                        <p class="text-white text-xs opacity-8 mt-1 mb-0">Total debit semua klien</p>
                    </div>
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                        <i class="ni ni-send text-danger text-lg opacity-10"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card stat-card bg-gradient-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-white text-sm mb-1">Pending Top Up</p>
                        <h3 class="text-white font-weight-bolder mb-0">{{ $stats['pending_count'] ?? 0 }}</h3>
                        <p class="text-white text-xs opacity-8 mt-1 mb-0">Menunggu konfirmasi</p>
                    </div>
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                        <i class="ni ni-time-alarm text-warning text-lg opacity-10"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-sm-6">
        <div class="card stat-card bg-gradient-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="text-white text-sm mb-1">Total Saldo Klien</p>
                        <h3 class="text-white font-weight-bolder mb-0">Rp {{ number_format($stats['total_saldo_all'] ?? 0, 0, ',', '.') }}</h3>
                        <p class="text-white text-xs opacity-8 mt-1 mb-0">{{ $stats['total_clients'] ?? 0 }} klien aktif</p>
                    </div>
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                        <i class="ni ni-building text-primary text-lg opacity-10"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Stats Cards Row 2 --}}
<div class="row mb-4">
    <div class="col-xl-6 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md me-3" style="width:48px;height:48px;">
                        <i class="ni ni-check-bold text-white" style="font-size:1.25rem;line-height:48px;"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary mb-0">Pembayaran Sukses</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total_success_payment'] ?? 0) }} transaksi</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-6 col-sm-6">
        <div class="card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md me-3" style="width:48px;height:48px;">
                        <i class="ni ni-fat-remove text-white" style="font-size:1.25rem;line-height:48px;"></i>
                    </div>
                    <div>
                        <p class="text-xs text-secondary mb-0">Pembayaran Gagal/Expired</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total_failed_payment'] ?? 0) }} transaksi</h5>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Pending Top Ups --}}
@if(isset($pendingTopups) && $pendingTopups->count() > 0)
<div class="row mb-4">
    <div class="col-12">
        <div class="card pending-card">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <i class="ni ni-bell-55 text-warning me-2"></i>
                        Top Up Menunggu Konfirmasi
                    </h6>
                    <span class="badge bg-warning">{{ $pendingTopups->count() }} pending</span>
                </div>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Order ID</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Metode</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pendingTopups as $trx)
                            <tr>
                                <td class="ps-4">
                                    <span class="text-xs font-weight-bold">{{ $trx->referensi ?? $trx->kode_transaksi }}</span>
                                </td>
                                <td>
                                    <span class="text-xs">{{ $trx->klien->nama ?? 'N/A' }}</span>
                                </td>
                                <td>
                                    <span class="text-xs font-weight-bold text-success">Rp {{ number_format($trx->nominal, 0, ',', '.') }}</span>
                                </td>
                                <td>
                                    <span class="text-xs">{{ $trx->metode_bayar ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="text-xs">{{ $trx->created_at->format('d M Y H:i') }}</span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm bg-gradient-success mb-0" onclick="confirmTopUp({{ $trx->id }})">
                                        <i class="ni ni-check-bold"></i>
                                    </button>
                                    <button class="btn btn-sm bg-gradient-danger mb-0" onclick="rejectTopUp({{ $trx->id }})">
                                        <i class="ni ni-fat-remove"></i>
                                    </button>
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

{{-- All Transactions --}}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6>Riwayat Transaksi Semua Klien</h6>
                <p class="text-sm text-secondary mb-0">50 transaksi terakhir</p>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table transaction-table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">Order ID</th>
                                <th>Klien</th>
                                <th>Jenis</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Metode</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions as $trx)
                            <tr>
                                <td class="ps-4">
                                    <span class="text-xs font-weight-bold">{{ $trx->referensi ?? $trx->kode_transaksi }}</span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar avatar-sm bg-gradient-primary me-2" style="width:32px;height:32px;border-radius:8px;">
                                            <span class="text-white text-xs" style="line-height:32px;">{{ substr($trx->klien->nama ?? 'N', 0, 1) }}</span>
                                        </div>
                                        <span class="text-xs">{{ $trx->klien->nama ?? 'N/A' }}</span>
                                    </div>
                                </td>
                                <td>
                                    @if($trx->jenis === 'topup')
                                        <span class="badge bg-gradient-success">Top Up</span>
                                    @elseif($trx->jenis === 'debit')
                                        <span class="badge bg-gradient-danger">Debit</span>
                                    @else
                                        <span class="badge bg-gradient-secondary">{{ ucfirst($trx->jenis) }}</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-xs font-weight-bold {{ $trx->jenis === 'topup' ? 'text-success' : 'text-danger' }}">
                                        {{ $trx->jenis === 'topup' ? '+' : '' }}Rp {{ number_format($trx->nominal, 0, ',', '.') }}
                                    </span>
                                </td>
                                <td>
                                    @php
                                        $status = $trx->status_topup ?? ($trx->jenis === 'debit' ? 'completed' : 'pending');
                                    @endphp
                                    <span class="status-badge status-{{ $status }}">{{ ucfirst($status) }}</span>
                                </td>
                                <td>
                                    <span class="text-xs">{{ $trx->metode_bayar ?? '-' }}</span>
                                </td>
                                <td>
                                    <span class="text-xs text-secondary">{{ $trx->created_at->format('d M Y H:i') }}</span>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="ni ni-basket text-secondary mb-3" style="font-size: 2rem;"></i>
                                    <p class="text-sm text-secondary mb-0">Belum ada transaksi</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmTopUp(id) {
    if (!confirm('Konfirmasi top up ini? Saldo klien akan bertambah.')) return;
    
    fetch(`/billing/topup/${id}/confirm`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Top up berhasil dikonfirmasi!');
            location.reload();
        } else {
            alert('Gagal: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan');
        console.error(err);
    });
}

function rejectTopUp(id) {
    const catatan = prompt('Alasan penolakan (opsional):');
    if (catatan === null) return; // User cancelled
    
    fetch(`/billing/topup/${id}/reject`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        },
        body: JSON.stringify({ catatan: catatan })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('Top up ditolak.');
            location.reload();
        } else {
            alert('Gagal: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        alert('Terjadi kesalahan');
        console.error(err);
    });
}
</script>
@endsection
