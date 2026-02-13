@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Campaign Profit Detail'])

    <div class="container-fluid py-4">
        <!-- Breadcrumb -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item"><a href="{{ route('owner.profit.index') }}">Profit Dashboard</a></li>
                <li class="breadcrumb-item"><a href="{{ route('owner.profit.client.detail', $campaign->klien_id) }}">{{ $campaign->klien?->nama_perusahaan }}</a></li>
                <li class="breadcrumb-item active">{{ Str::limit($campaign->name, 30) }}</li>
            </ol>
        </nav>

        <!-- Campaign Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h4 class="mb-1">{{ $campaign->name }}</h4>
                                <p class="text-sm text-secondary mb-0">
                                    <span class="badge bg-{{ $campaign->status === 'completed' ? 'success' : ($campaign->status === 'running' ? 'info' : 'secondary') }}">
                                        {{ ucfirst($campaign->status) }}
                                    </span>
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-building me-1"></i> {{ $campaign->klien?->nama_perusahaan ?? '-' }}
                                    <span class="mx-2">|</span>
                                    <i class="fas fa-calendar me-1"></i> {{ $campaign->created_at->format('d M Y H:i') }}
                                </p>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <span class="badge bg-light text-dark px-3 py-2">
                                    Template: {{ $campaign->template?->name ?? '-' }}
                                </span>
                                <br>
                                <span class="text-xs text-secondary">Category: {{ ucfirst($category) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Financial Summary -->
        <div class="row mb-4">
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                                <h5 class="font-weight-bolder mb-0 text-success">
                                    Rp {{ number_format($financials['revenue'], 0, ',', '.') }}
                                </h5>
                                <p class="text-xs text-secondary mb-0">
                                    Rp {{ number_format($financials['revenue_per_message'], 0) }}/msg
                                </p>
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
                                    Rp {{ number_format($financials['cost'], 0, ',', '.') }}
                                </h5>
                                <p class="text-xs text-secondary mb-0">
                                    Rp {{ number_format($financials['cost_per_message'], 0) }}/msg
                                </p>
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
                                <h5 class="font-weight-bolder mb-0 {{ $financials['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                    Rp {{ number_format($financials['profit'], 0, ',', '.') }}
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
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Profit Margin</p>
                                <h5 class="font-weight-bolder mb-0">
                                    {{ number_format($financials['margin'], 1) }}%
                                </h5>
                                @php
                                    $status = $financials['margin'] >= 20 ? 'PROFIT' : ($financials['margin'] >= 0 ? 'WARNING' : 'LOSS');
                                    $color = $financials['margin'] >= 20 ? 'success' : ($financials['margin'] >= 0 ? 'warning' : 'danger');
                                @endphp
                                <span class="badge bg-gradient-{{ $color }}">{{ $status }}</span>
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

        <!-- Message Breakdown -->
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Message Status Breakdown</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Count</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $statuses = ['pending', 'sent', 'delivered', 'read', 'failed'];
                                        $statusColors = [
                                            'pending' => 'secondary',
                                            'sent' => 'info',
                                            'delivered' => 'success',
                                            'read' => 'primary',
                                            'failed' => 'danger',
                                        ];
                                    @endphp
                                    @foreach($statuses as $status)
                                    @php $stat = $messageStats[$status] ?? null; @endphp
                                    @if($stat)
                                    <tr>
                                        <td>
                                            <span class="badge bg-gradient-{{ $statusColors[$status] }}">{{ ucfirst($status) }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-sm font-weight-bold">{{ number_format($stat->count) }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs text-danger">Rp {{ number_format($stat->cost ?? 0, 0, ',', '.') }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs text-success">Rp {{ number_format($stat->revenue ?? 0, 0, ',', '.') }}</span>
                                        </td>
                                    </tr>
                                    @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Delivery Performance</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="statusChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campaign Details -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Campaign Details</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Total Recipients</p>
                                <h5 class="mb-0">{{ number_format($campaign->total_recipients ?? 0) }}</h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Sent Count</p>
                                <h5 class="mb-0 text-info">{{ number_format($campaign->sent_count ?? 0) }}</h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Delivered Count</p>
                                <h5 class="mb-0 text-success">{{ number_format($campaign->delivered_count ?? 0) }}</h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Failed Count</p>
                                <h5 class="mb-0 text-danger">{{ number_format($campaign->failed_count ?? 0) }}</h5>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Started At</p>
                                <p class="mb-0">{{ $campaign->started_at ? $campaign->started_at->format('d M Y H:i') : '-' }}</p>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Completed At</p>
                                <p class="mb-0">{{ $campaign->completed_at ? $campaign->completed_at->format('d M Y H:i') : '-' }}</p>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Estimated Cost</p>
                                <p class="mb-0">Rp {{ number_format($campaign->estimated_cost ?? 0, 0, ',', '.') }}</p>
                            </div>
                            <div class="col-md-3 mb-3">
                                <p class="text-xs text-secondary mb-1">Actual Cost</p>
                                <p class="mb-0">Rp {{ number_format($campaign->actual_cost ?? 0, 0, ',', '.') }}</p>
                            </div>
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
    const messageStats = @json($messageStats->toArray());

    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('statusChart').getContext('2d');
        
        const labels = [];
        const data = [];
        const colors = {
            'pending': '#8898aa',
            'sent': '#11cdef',
            'delivered': '#2dce89',
            'read': '#5e72e4',
            'failed': '#f5365c'
        };
        const bgColors = [];

        for (const [status, stat] of Object.entries(messageStats)) {
            labels.push(status.charAt(0).toUpperCase() + status.slice(1));
            data.push(stat.count);
            bgColors.push(colors[status] || '#adb5bd');
        }

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: bgColors,
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
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
