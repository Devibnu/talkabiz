@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Client Profit Detail'])

    <div class="container-fluid py-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item"><a href="{{ route('owner.profit.index') }}">Profit Dashboard</a></li>
                <li class="breadcrumb-item active">{{ $client->nama_perusahaan }}</li>
            </ol>
        </nav>

        <!-- Client Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h4 class="mb-1">{{ $client->nama_perusahaan }}</h4>
                                <p class="text-sm text-secondary mb-0">
                                    <i class="fas fa-envelope me-1"></i> {{ $client->email }}
                                    <span class="mx-2">|</span>
                                    <span class="badge bg-{{ $client->status === 'aktif' ? 'success' : 'secondary' }}">{{ ucfirst($client->status) }}</span>
                                </p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <p class="text-xs text-secondary mb-1">Periode: {{ $startDate->format('d M Y') }} - {{ $endDate->format('d M Y') }}</p>
                                <a href="{{ route('owner.clients.show', $client->id) }}" class="btn btn-sm btn-outline-primary mb-0">
                                    <i class="fas fa-user me-1"></i> Lihat Profil Client
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if($clientProfit)
        <!-- Profit Summary Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                                <h5 class="font-weight-bolder mb-0 text-success">
                                    {{ $clientProfit['formatted']['revenue'] }}
                                </h5>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                    <i class="ni ni-money-coins text-lg opacity-10"></i>
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
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Cost</p>
                                <h5 class="font-weight-bolder mb-0 text-danger">
                                    {{ $clientProfit['formatted']['cost'] }}
                                </h5>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                                    <i class="ni ni-cart text-lg opacity-10"></i>
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
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Net Profit</p>
                                <h5 class="font-weight-bolder mb-0 {{ $clientProfit['total_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $clientProfit['formatted']['profit'] }}
                                </h5>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                    <i class="ni ni-chart-bar-32 text-lg opacity-10"></i>
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
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Profit Status</p>
                                <h5 class="font-weight-bolder mb-0">
                                    <span class="badge bg-gradient-{{ $clientProfit['status_color'] }} px-3 py-2">
                                        {{ $clientProfit['profit_status'] }}
                                    </span>
                                </h5>
                                <p class="text-xs text-secondary mb-0">Margin: {{ $clientProfit['profit_margin'] }}%</p>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                    <i class="ni ni-sound-wave text-lg opacity-10"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Message Stats -->
        <div class="row mb-4">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Statistik Pesan</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <p class="text-xs text-secondary mb-1">Total Messages</p>
                                <h4 class="mb-0">{{ number_format($clientProfit['total_messages']) }}</h4>
                            </div>
                            <div class="col-6 mb-3">
                                <p class="text-xs text-secondary mb-1">Success</p>
                                <h4 class="mb-0 text-success">{{ number_format($clientProfit['success_messages']) }}</h4>
                            </div>
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Failed</p>
                                <h4 class="mb-0 text-danger">{{ number_format($clientProfit['failed_messages']) }}</h4>
                            </div>
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Delivery Rate</p>
                                <h4 class="mb-0">{{ $clientProfit['delivery_rate'] }}%</h4>
                            </div>
                        </div>
                        <hr class="horizontal dark my-3">
                        <div class="progress" style="height: 10px;">
                            <div class="progress-bar bg-success" style="width: {{ $clientProfit['delivery_rate'] }}%"></div>
                            <div class="progress-bar bg-danger" style="width: {{ 100 - $clientProfit['delivery_rate'] }}%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <span class="text-xs text-success">Success</span>
                            <span class="text-xs text-danger">Failed</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Revenue vs Cost Harian</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="clientChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        @if(count($clientProfit['warnings']) > 0)
        <!-- Warnings -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-warning mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Warnings:</strong>
                    @foreach($clientProfit['warnings'] as $warning)
                        <span class="badge bg-warning text-dark ms-2">{{ $warning }}</span>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
        @else
        <div class="row mb-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Tidak ada data pesan untuk periode yang dipilih.
                </div>
            </div>
        </div>
        @endif

        <!-- Campaigns Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Campaign History</h6>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Campaign</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Template</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Messages</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Delivery</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Revenue</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Profit</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($campaigns as $campaign)
                                    <tr>
                                        <td class="ps-3">
                                            <h6 class="mb-0 text-sm">{{ Str::limit($campaign['name'], 30) }}</h6>
                                            <p class="text-xs text-secondary mb-0">{{ \Carbon\Carbon::parse($campaign['created_at'])->format('d M Y H:i') }}</p>
                                        </td>
                                        <td>
                                            <span class="text-xs">{{ $campaign['template_name'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">{{ $campaign['messages']['total'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">{{ $campaign['messages']['delivery_rate'] }}%</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs text-danger">{{ $campaign['formatted']['cost'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs text-success">{{ $campaign['formatted']['revenue'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs {{ $campaign['financial']['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $campaign['formatted']['profit'] }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-gradient-{{ $campaign['status_color'] }}">{{ $campaign['profit_status'] }}</span>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <span class="text-secondary">Tidak ada campaign dalam periode ini</span>
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

        @include('layouts.footers.auth.footer')
    </div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const chartData = @json($chartData);

    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('clientChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: chartData.revenue,
                        backgroundColor: 'rgba(45, 206, 137, 0.8)',
                    },
                    {
                        label: 'Cost',
                        data: chartData.cost,
                        backgroundColor: 'rgba(245, 54, 92, 0.8)',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value / 1000) + 'k';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
@endpush

@section('auth')
    @include('layouts.navbars.auth.sidenav')
    <main class="main-content position-relative border-radius-lg">
        @yield('content')
    </main>
@endsection
