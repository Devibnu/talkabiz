@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
    @include('layouts.navbars.auth.topnav', ['title' => 'Owner Profit Dashboard'])

    <div class="container-fluid py-4">
        <!-- Date Filter -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body py-3">
                        <form id="filterForm" class="row align-items-end g-3">
                            <div class="col-md-3">
                                <label class="form-label text-xs">Tanggal Mulai</label>
                                <input type="date" class="form-control" id="startDate" 
                                       value="{{ $startDate->format('Y-m-d') }}">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-xs">Tanggal Akhir</label>
                                <input type="date" class="form-control" id="endDate" 
                                       value="{{ $endDate->format('Y-m-d') }}">
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100 mb-0">
                                    <i class="fas fa-filter me-1"></i> Filter
                                </button>
                            </div>
                            <div class="col-md-4 text-end">
                                <button type="button" class="btn btn-outline-secondary mb-0 me-2" onclick="setQuickDate('today')">Hari Ini</button>
                                <button type="button" class="btn btn-outline-secondary mb-0 me-2" onclick="setQuickDate('week')">7 Hari</button>
                                <button type="button" class="btn btn-outline-secondary mb-0" onclick="setQuickDate('month')">Bulan Ini</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts Section -->
        @if(count($alerts) > 0)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-left-warning">
                    <div class="card-header pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                Alerts & Warnings ({{ count($alerts) }})
                            </h6>
                            <button class="btn btn-sm btn-link" type="button" data-bs-toggle="collapse" data-bs-target="#alertsCollapse">
                                Lihat Semua
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="alertsCollapse">
                        <div class="card-body pt-2">
                            <div class="table-responsive">
                                <table class="table table-sm align-items-center mb-0">
                                    <tbody>
                                        @foreach(array_slice($alerts, 0, 10) as $alert)
                                        <tr>
                                            <td class="ps-3">
                                                <span class="badge bg-gradient-{{ $alert['severity'] }}">{{ strtoupper($alert['severity']) }}</span>
                                            </td>
                                            <td>
                                                <span class="text-xs font-weight-bold">{{ $alert['title'] }}</span>
                                            </td>
                                            <td>
                                                <span class="text-xs text-secondary">{{ $alert['message'] }}</span>
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
        </div>
        @endif

        <!-- Global Summary Cards -->
        <div class="row mb-4">
            <!-- Total Revenue -->
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ $globalSummary['formatted']['total_revenue'] }}
                                    </h5>
                                    <p class="text-xs text-secondary mb-0">
                                        {{ number_format($globalSummary['messages']['total']) }} pesan
                                    </p>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                    <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Cost -->
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Cost</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ $globalSummary['formatted']['total_cost'] }}
                                    </h5>
                                    <p class="text-xs text-secondary mb-0">
                                        Avg: Rp {{ number_format($globalSummary['metrics']['avg_cost_per_message'], 0) }}/msg
                                    </p>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                                    <i class="ni ni-cart text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Profit -->
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Net Profit</p>
                                    <h5 class="font-weight-bolder mb-0 {{ $globalSummary['financial']['total_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $globalSummary['formatted']['total_profit'] }}
                                    </h5>
                                    <p class="text-xs mb-0">
                                        <span class="badge bg-gradient-{{ $globalSummary['financial']['profit_status'] === 'PROFIT' ? 'success' : ($globalSummary['financial']['profit_status'] === 'WARNING' ? 'warning' : 'danger') }}">
                                            {{ $globalSummary['financial']['profit_status'] }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                    <i class="ni ni-chart-bar-32 text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Margin & ARPU -->
            <div class="col-xl-3 col-sm-6">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-capitalize font-weight-bold">Margin</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ number_format($globalSummary['financial']['profit_margin'], 1) }}%
                                    </h5>
                                    <p class="text-xs text-secondary mb-0">
                                        ARPU: {{ $globalSummary['formatted']['arpu'] }}
                                    </p>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                    <i class="ni ni-sound-wave text-lg opacity-10" aria-hidden="true"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today Stats vs Period -->
        <div class="row mb-4">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Statistik Hari Ini</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Revenue</p>
                                <h5 class="mb-3">{{ $todaySummary['formatted']['total_revenue'] }}</h5>
                            </div>
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Cost</p>
                                <h5 class="mb-3">{{ $todaySummary['formatted']['total_cost'] }}</h5>
                            </div>
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Profit</p>
                                <h5 class="mb-3 {{ $todaySummary['financial']['total_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $todaySummary['formatted']['total_profit'] }}
                                </h5>
                            </div>
                            <div class="col-6">
                                <p class="text-xs text-secondary mb-1">Messages</p>
                                <h5 class="mb-3">{{ number_format($todaySummary['messages']['total']) }}</h5>
                            </div>
                        </div>
                        <hr class="horizontal dark my-3">
                        <div class="d-flex justify-content-between">
                            <span class="text-xs">Delivery Rate</span>
                            <span class="text-xs font-weight-bold">{{ $todaySummary['messages']['delivery_rate'] }}%</span>
                        </div>
                        <div class="progress mt-2" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: {{ $todaySummary['messages']['delivery_rate'] }}%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Revenue vs Cost (Daily)</h6>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-primary active" onclick="updateChartType('daily')">Daily</button>
                                <button type="button" class="btn btn-outline-primary" onclick="updateChartType('monthly')">Monthly</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <canvas id="revenueVsCostChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Client Profit Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Profit Per Client</h6>
                            <div>
                                <select id="clientSort" class="form-select form-select-sm d-inline-block w-auto">
                                    <option value="profit">Sort: Profit</option>
                                    <option value="revenue">Sort: Revenue</option>
                                    <option value="cost">Sort: Cost</option>
                                    <option value="margin">Sort: Margin</option>
                                    <option value="messages">Sort: Messages</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0" id="clientTable">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Client</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Messages</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Delivery</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Revenue</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Profit</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Margin</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($topClients as $client)
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $client['nama_perusahaan'] }}</h6>
                                                    <p class="text-xs text-secondary mb-0">{{ $client['email'] }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs font-weight-bold">{{ number_format($client['total_messages']) }}</span>
                                            @if($client['failed_messages'] > 0)
                                            <br><span class="text-xs text-danger">({{ $client['failed_messages'] }} failed)</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs font-weight-bold">{{ $client['delivery_rate'] }}%</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-danger">{{ $client['formatted']['cost'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-success">{{ $client['formatted']['revenue'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold {{ $client['total_profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $client['formatted']['profit'] }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs font-weight-bold">{{ number_format($client['profit_margin'], 1) }}%</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-gradient-{{ $client['status_color'] }}">{{ $client['profit_status'] }}</span>
                                            @if(count($client['warnings']) > 0)
                                            <br><span class="text-xs text-warning" title="{{ implode(', ', $client['warnings']) }}">
                                                <i class="fas fa-exclamation-triangle"></i>
                                            </span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <a href="{{ route('owner.profit.client.detail', $client['klien_id']) }}?start_date={{ $startDate->format('Y-m-d') }}&end_date={{ $endDate->format('Y-m-d') }}" 
                                               class="btn btn-link text-info text-sm mb-0 px-0">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
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

        <!-- Campaign Profit Table -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Profit Per Campaign (Recent)</h6>
                            <a href="{{ route('owner.profit.api.campaigns') }}?start_date={{ $startDate->format('Y-m-d') }}&end_date={{ $endDate->format('Y-m-d') }}" 
                               class="btn btn-sm btn-outline-primary mb-0">
                                <i class="fas fa-download me-1"></i> Export JSON
                            </a>
                        </div>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0" id="campaignTable">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Campaign</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Template</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Sent/Delivered/Failed</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Delivery</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Revenue</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Profit</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Cost/Delivered</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentCampaigns as $campaign)
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex flex-column">
                                                <h6 class="mb-0 text-sm">{{ Str::limit($campaign['name'], 30) }}</h6>
                                                <p class="text-xs text-secondary mb-0">{{ $campaign['klien_name'] }}</p>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="text-xs">{{ $campaign['template_name'] }}</span>
                                            <br><span class="badge bg-light text-dark text-xxs">{{ $campaign['template_category'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">
                                                {{ $campaign['messages']['sent'] }} / 
                                                <span class="text-success">{{ $campaign['messages']['delivered'] }}</span> / 
                                                <span class="text-danger">{{ $campaign['messages']['failed'] }}</span>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs font-weight-bold {{ $campaign['messages']['delivery_rate'] < 50 ? 'text-warning' : '' }}">
                                                {{ $campaign['messages']['delivery_rate'] }}%
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-danger">{{ $campaign['formatted']['cost'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-success">{{ $campaign['formatted']['revenue'] }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold {{ $campaign['financial']['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $campaign['formatted']['profit'] }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">{{ $campaign['formatted']['cost_per_delivered'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-gradient-{{ $campaign['status_color'] }}">{{ $campaign['profit_status'] }}</span>
                                            @if(count($campaign['warnings']) > 0)
                                            <i class="fas fa-exclamation-triangle text-warning ms-1" title="{{ implode(', ', $campaign['warnings']) }}"></i>
                                            @endif
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

        <!-- Monthly Trend -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Trend Profit Bulanan (6 Bulan Terakhir)</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Bulan</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Messages</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Revenue</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Cost</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Profit</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Margin</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width: 30%">Visual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $maxRevenue = collect($monthlyTrend)->max('revenue') ?: 1;
                                    @endphp
                                    @foreach($monthlyTrend as $month)
                                    <tr>
                                        <td class="ps-3">
                                            <span class="text-sm font-weight-bold">{{ $month['month'] }}</span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">{{ number_format($month['messages']) }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-success">Rp {{ number_format($month['revenue'], 0, ',', '.') }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold text-danger">Rp {{ number_format($month['cost'], 0, ',', '.') }}</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-xs font-weight-bold {{ $month['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                                Rp {{ number_format($month['profit'], 0, ',', '.') }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-gradient-{{ $month['margin'] >= 20 ? 'success' : ($month['margin'] >= 0 ? 'warning' : 'danger') }}">
                                                {{ number_format($month['margin'], 1) }}%
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress w-100" style="height: 8px;">
                                                    @php
                                                        $revenueWidth = ($month['revenue'] / $maxRevenue) * 100;
                                                        $costWidth = ($month['cost'] / $maxRevenue) * 100;
                                                    @endphp
                                                    <div class="progress-bar bg-success" style="width: {{ $revenueWidth }}%" title="Revenue"></div>
                                                </div>
                                            </div>
                                            <div class="d-flex align-items-center mt-1">
                                                <div class="progress w-100" style="height: 4px;">
                                                    <div class="progress-bar bg-danger" style="width: {{ $costWidth }}%" title="Cost"></div>
                                                </div>
                                            </div>
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

        @include('layouts.footers.auth.footer')
    </div>
@endsection

@push('styles')
<style>
    .card {
        border-radius: 1rem;
    }
    .border-left-warning {
        border-left: 4px solid #fb6340 !important;
    }
    .table th {
        padding: 0.75rem 1rem;
    }
    .table td {
        padding: 0.75rem 1rem;
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Chart data from PHP
    const chartData = @json($chartData);
    let revenueVsCostChart;

    // Initialize chart
    document.addEventListener('DOMContentLoaded', function() {
        initChart(chartData);
    });

    function initChart(data) {
        const ctx = document.getElementById('revenueVsCostChart').getContext('2d');
        
        if (revenueVsCostChart) {
            revenueVsCostChart.destroy();
        }

        revenueVsCostChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [
                    {
                        label: 'Revenue',
                        data: data.revenue,
                        borderColor: '#2dce89',
                        backgroundColor: 'rgba(45, 206, 137, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Cost',
                        data: data.cost,
                        borderColor: '#f5365c',
                        backgroundColor: 'rgba(245, 54, 92, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Profit',
                        data: data.profit,
                        borderColor: '#5e72e4',
                        backgroundColor: 'rgba(94, 114, 228, 0.1)',
                        fill: false,
                        tension: 0.4,
                        borderDash: [5, 5]
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': Rp ' + context.raw.toLocaleString('id-ID');
                            }
                        }
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
    }

    // Quick date buttons
    function setQuickDate(type) {
        const today = new Date();
        let startDate, endDate;

        switch(type) {
            case 'today':
                startDate = today;
                endDate = today;
                break;
            case 'week':
                startDate = new Date(today);
                startDate.setDate(today.getDate() - 6);
                endDate = today;
                break;
            case 'month':
                startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                endDate = today;
                break;
        }

        document.getElementById('startDate').value = formatDate(startDate);
        document.getElementById('endDate').value = formatDate(endDate);
        document.getElementById('filterForm').submit();
    }

    function formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    // Form submit
    document.getElementById('filterForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        window.location.href = `{{ route('owner.profit.index') }}?start_date=${startDate}&end_date=${endDate}`;
    });

    // Update chart type
    function updateChartType(type) {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;

        fetch(`{{ route('owner.profit.api.chart') }}?type=${type}&start_date=${startDate}&end_date=${endDate}`)
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    if (type === 'monthly') {
                        // Convert monthly data to chart format
                        const data = {
                            labels: result.data.map(d => d.month),
                            revenue: result.data.map(d => d.revenue),
                            cost: result.data.map(d => d.cost),
                            profit: result.data.map(d => d.profit)
                        };
                        initChart(data);
                    } else {
                        initChart(result.data);
                    }
                }
            });
    }

    // Client sort
    document.getElementById('clientSort').addEventListener('change', function() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const sortBy = this.value;
        window.location.href = `{{ route('owner.profit.index') }}?start_date=${startDate}&end_date=${endDate}&sort_by=${sortBy}`;
    });
</script>
@endpush

@section('auth')
    @include('layouts.navbars.auth.sidenav')
    <main class="main-content position-relative border-radius-lg">
        @yield('content')
    </main>
@endsection
