@extends('owner.layouts.app')

@section('page-title', 'Billing & Revenue')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Revenue Hari Ini</p>
                        <h5 class="font-weight-bolder mb-0">Rp {{ number_format($stats['revenue_today']) }}</h5>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Revenue Bulan Ini</p>
                        <h5 class="font-weight-bolder mb-0">Rp {{ number_format($stats['revenue_month']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-chart-line text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Revenue Topup</p>
                        <h5 class="font-weight-bolder mb-0">Rp {{ number_format($stats['topup_revenue_month']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-coins text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Pending Payment</p>
                        <h5 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['pending_payments']) }}</h5>
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

{{-- Revenue Chart --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Revenue Overview</h6>
                <div class="btn-group btn-group-sm" role="group">
                    <a href="{{ route('owner.billing.index', ['period' => '7d']) }}" 
                       class="btn {{ request('period', '30d') == '7d' ? 'bg-gradient-primary' : 'btn-outline-primary' }}">
                        7 Hari
                    </a>
                    <a href="{{ route('owner.billing.index', ['period' => '30d']) }}" 
                       class="btn {{ request('period', '30d') == '30d' ? 'bg-gradient-primary' : 'btn-outline-primary' }}">
                        30 Hari
                    </a>
                    <a href="{{ route('owner.billing.index', ['period' => '90d']) }}" 
                       class="btn {{ request('period', '30d') == '90d' ? 'bg-gradient-primary' : 'btn-outline-primary' }}">
                        90 Hari
                    </a>
                </div>
            </div>
            <div class="card-body p-3">
                <div class="chart">
                    <canvas id="revenueChart" class="chart-canvas" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    {{-- Recent Transactions --}}
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Transaksi Terbaru</h6>
                <a href="{{ route('owner.billing.transactions') }}" class="btn btn-sm btn-outline-primary mb-0">
                    Lihat Semua
                </a>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tipe</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Jumlah</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($transactions as $tx)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-sm font-weight-bold">
                                            {{ optional($tx->klien)->nama_perusahaan ?? optional($tx->createdBy)->name ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-info">
                                            {{ $tx->display_type }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-sm font-weight-bold">
                                            Rp {{ number_format($tx->final_price) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm 
                                            @if(in_array($tx->status, ['success', 'completed'])) bg-gradient-success
                                            @elseif(in_array($tx->status, ['pending', 'waiting_payment'])) bg-gradient-warning
                                            @else bg-gradient-danger
                                            @endif">
                                            {{ $tx->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-secondary">
                                            {{ optional($tx->created_at)->format('d M Y H:i') ?? '-' }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <p class="text-muted mb-0">Tidak ada transaksi</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Top Clients --}}
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Top Klien (Revenue Bulan Ini)</h6>
            </div>
            <div class="card-body">
                @forelse($topClients as $index => $client)
                    <div class="d-flex mb-3 align-items-center">
                        <div class="icon icon-shape bg-gradient-{{ $index == 0 ? 'warning' : ($index == 1 ? 'secondary' : 'dark') }} shadow text-center border-radius-md me-3">
                            <span class="text-white font-weight-bold">#{{ $index + 1 }}</span>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="mb-0 text-sm">{{ $client->nama_perusahaan }}</h6>
                            <p class="text-xs text-muted mb-0">
                                {{ $client->transactions_count ?? 0 }} transaksi
                            </p>
                        </div>
                        <div class="text-end">
                            <span class="text-sm font-weight-bold">
                                Rp {{ number_format($client->total_revenue ?? 0) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-3 mb-0">Tidak ada data</p>
                @endforelse
            </div>
        </div>

        {{-- Quick Summary --}}
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">Ringkasan Bulan Ini</h6>
            </div>
            <div class="card-body">
                <ul class="list-group">
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 pt-0">
                        <span class="text-sm">Total Revenue</span>
                        <span class="font-weight-bold text-sm">Rp {{ number_format($stats['revenue_month']) }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0">
                        <span class="text-sm">Revenue Topup</span>
                        <span class="font-weight-bold text-sm">Rp {{ number_format($stats['topup_revenue_month']) }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0">
                        <span class="text-sm">Transaksi Sukses</span>
                        <span class="font-weight-bold text-success text-sm">{{ number_format($stats['success_transactions'] ?? 0) }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 pb-0">
                        <span class="text-sm">Transaksi Pending</span>
                        <span class="font-weight-bold text-warning text-sm">{{ number_format($stats['pending_payments']) }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Revenue Chart
    const ctx = document.getElementById('revenueChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: {!! json_encode($chartData['labels'] ?? []) !!},
            datasets: [{
                label: 'Revenue',
                data: {!! json_encode($chartData['data'] ?? []) !!},
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'Rp ' + value.toLocaleString('id-ID');
                        }
                    }
                }
            }
        }
    });
</script>
@endpush
