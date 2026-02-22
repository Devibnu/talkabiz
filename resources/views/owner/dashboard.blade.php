@extends('owner.layouts.app')

@section('page-title', 'Dashboard Owner')
@section('title', 'Owner Dashboard')

@section('content')
<style>
/* Owner Dashboard - Soft UI Compliant */
.chart-container { position: relative; height: 280px; }
.flagged-alert { background-color: #fff5f5; border-left: 4px solid #ea4c89; }
/* Responsive table */
.table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.table-responsive table { min-width: 600px; }
/* Card equal height */
.row > [class*="col-"] > .card.h-100 { height: 100% !important; }
/* Mobile fixes */
@media (max-width: 576px) {
    .btn-group { flex-wrap: wrap; }
    .btn-group .btn { margin-bottom: 4px; }
    .d-flex.gap-2 { gap: 0.5rem !important; }
}
</style>

{{-- Page Header --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h5 class="font-weight-bolder mb-0">Owner Dashboard</h5>
                <p class="text-sm text-secondary mb-0">Monitoring Profit & Cost</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="btn-group" role="group">
                    <a href="{{ route('owner.dashboard', ['period' => 'today']) }}" 
                       class="btn btn-sm {{ $period === 'today' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Hari Ini
                    </a>
                    <a href="{{ route('owner.dashboard', ['period' => 'month']) }}" 
                       class="btn btn-sm {{ $period === 'month' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Bulan Ini
                    </a>
                    <a href="{{ route('owner.dashboard', ['period' => 'year']) }}" 
                       class="btn btn-sm {{ $period === 'year' ? 'btn-primary' : 'btn-outline-primary' }}">
                        Tahun Ini
                    </a>
                </div>
                <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Section 1: KPI Cards (4 Columns) --}}
<div class="row">
    {{-- Total Revenue --}}
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <div class="numbers">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                            <h5 class="font-weight-bolder mb-0">
                                Rp {{ number_format($summary['total_revenue'] ?? 0, 0, ',', '.') }}
                            </h5>
                            <p class="text-xs text-secondary mb-0">{{ $summary['period_label'] ?? 'Bulan Ini' }}</p>
                        </div>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="ni ni-money-coins text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Total Cost --}}
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <div class="numbers">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Cost Meta WA</p>
                            <h5 class="font-weight-bolder mb-0">
                                Rp {{ number_format($summary['total_cost_meta'] ?? 0, 0, ',', '.') }}
                            </h5>
                            <p class="text-xs text-secondary mb-0">{{ number_format($summary['total_messages'] ?? 0) }} pesan</p>
                        </div>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fab fa-whatsapp text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Gross Profit --}}
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <div class="numbers">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Gross Profit</p>
                            <h5 class="font-weight-bolder mb-0 {{ ($summary['gross_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                                Rp {{ number_format($summary['gross_profit'] ?? 0, 0, ',', '.') }}
                            </h5>
                            <p class="text-xs text-secondary mb-0">Revenue - Cost</p>
                        </div>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="ni ni-chart-pie-35 text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Profit Margin --}}
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <div class="numbers">
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Profit Margin</p>
                            <h5 class="font-weight-bolder mb-0">{{ $summary['profit_margin'] ?? 0 }}%</h5>
                            <p class="text-xs text-secondary mb-0">Target: >20%</p>
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

{{-- Business Status Alert --}}
@php $alertStatus = $summary['alert_status'] ?? ['status' => 'normal', 'message' => 'Semua berjalan normal', 'icon' => '✓']; @endphp
<div class="row mb-4">
    <div class="col-12">
        <div class="card bg-gradient-{{ $alertStatus['status'] === 'danger' ? 'danger' : ($alertStatus['status'] === 'warning' ? 'warning' : 'success') }}">
            <div class="card-body p-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-white shadow text-center border-radius-md me-3">
                        <i class="fas fa-shield-alt text-{{ $alertStatus['status'] === 'danger' ? 'danger' : ($alertStatus['status'] === 'warning' ? 'warning' : 'success') }} text-lg"></i>
                    </div>
                    <div>
                        <p class="text-white text-sm font-weight-bold mb-0">Status Bisnis: {{ ucfirst($alertStatus['status']) }}</p>
                        <p class="text-white text-xs mb-0 opacity-8">{{ $alertStatus['message'] }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Section 2: Subscription & Top-up (2 Columns) --}}
<div class="row">
    {{-- Subscription Revenue --}}
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0 p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-0">Subscription Revenue</h6>
                        <p class="text-xs text-secondary mb-0">Pendapatan dari langganan</p>
                    </div>
                    <span class="badge bg-gradient-primary">Subscription</span>
                </div>
            </div>
            <div class="card-body p-3">
                @if(isset($revenueBreakdown['subscription']['by_plan']) && count($revenueBreakdown['subscription']['by_plan']) > 0)
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paket</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Harga</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-end">Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($revenueBreakdown['subscription']['by_plan'] as $plan)
                                @php $plan = (array) $plan; @endphp
                                <tr>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ $plan['display_name'] }}</span>
                                    </td>
                                    <td><span class="text-xs">{{ $plan['client_count'] }}</span></td>
                                    <td><span class="text-xs">Rp {{ number_format($plan['monthly_fee'], 0, ',', '.') }}</span></td>
                                    <td class="text-end">
                                        <span class="text-xs font-weight-bold">Rp {{ number_format($plan['total_revenue'], 0, ',', '.') }}</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <hr class="horizontal dark my-3">
                    <div class="d-flex justify-content-between">
                        <span class="text-sm font-weight-bold">Total</span>
                        <span class="text-sm font-weight-bold text-success">
                            Rp {{ number_format($revenueBreakdown['subscription']['total'] ?? 0, 0, ',', '.') }}
                        </span>
                    </div>
                @else
                    <div class="text-center py-4">
                        <i class="ni ni-box-2 text-secondary opacity-5" style="font-size: 3rem;"></i>
                        <p class="text-sm text-secondary mt-3 mb-0">Belum ada data subscription</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
    
    {{-- Top Up Margin --}}
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0 p-3">
                <div class="d-flex justify-content-between">
                    <div>
                        <h6 class="mb-0">Top Up Margin</h6>
                        <p class="text-xs text-secondary mb-0">Hidden dari client</p>
                    </div>
                    <span class="badge bg-gradient-success">Top-up</span>
                </div>
            </div>
            <div class="card-body p-3">
                @php $topup = $revenueBreakdown['topup'] ?? []; @endphp
                <div class="row">
                    <div class="col-6 mb-3">
                        <p class="text-xs text-secondary mb-1">Total Top Up Masuk</p>
                        <h6 class="font-weight-bold text-dark mb-0">
                            Rp {{ number_format($topup['total_topup'] ?? 0, 0, ',', '.') }}
                        </h6>
                        <p class="text-xs text-secondary mb-0">{{ $topup['transaction_count'] ?? 0 }} transaksi</p>
                    </div>
                    <div class="col-6 mb-3">
                        <p class="text-xs text-secondary mb-1">Cost Meta Aktual</p>
                        <h6 class="font-weight-bold text-danger mb-0">
                            Rp {{ number_format($topup['cost_meta'] ?? 0, 0, ',', '.') }}
                        </h6>
                        <p class="text-xs text-secondary mb-0">Estimasi 65%</p>
                    </div>
                </div>
                <hr class="horizontal dark my-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-xs text-secondary mb-1">Margin Talkabiz</p>
                        <h5 class="font-weight-bold text-success mb-0">
                            Rp {{ number_format($topup['margin'] ?? 0, 0, ',', '.') }}
                        </h5>
                    </div>
                    <span class="badge bg-gradient-success badge-lg">{{ $topup['margin_percentage'] ?? 0 }}%</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Section 3: Cost Analysis Table (Full Width) --}}
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header pb-0 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Cost Analysis - WhatsApp</h6>
                        <p class="text-xs text-secondary mb-0">Biaya per kategori pesan</p>
                    </div>
                    <span class="badge bg-gradient-dark">Anti Boncos</span>
                </div>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Kategori</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Jumlah Pesan</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Harga Jual</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Cost Meta</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-end">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(['marketing', 'utility', 'authentication', 'service'] as $category)
                            @php 
                                $cat = $costAnalysis[$category] ?? [];
                                $badgeColor = match($category) {
                                    'marketing' => 'primary',
                                    'utility' => 'info',
                                    'authentication' => 'warning',
                                    default => 'success'
                                };
                            @endphp
                            <tr>
                                <td class="ps-4">
                                    <span class="badge bg-gradient-{{ $badgeColor }}">{{ ucfirst($category) }}</span>
                                </td>
                                <td><span class="text-xs font-weight-bold">{{ number_format($cat['message_count'] ?? 0) }}</span></td>
                                <td><span class="text-xs font-weight-bold">Rp {{ number_format($cat['total_cost'] ?? 0, 0, ',', '.') }}</span></td>
                                <td><span class="text-xs font-weight-bold text-danger">Rp {{ number_format($cat['meta_cost'] ?? 0, 0, ',', '.') }}</span></td>
                                <td class="text-end pe-4">
                                    <span class="text-xs font-weight-bold text-success">Rp {{ number_format($cat['margin'] ?? 0, 0, ',', '.') }}</span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            @php $summary_cost = $costAnalysis['summary'] ?? []; @endphp
                            <tr class="bg-gray-100">
                                <td class="ps-4"><span class="text-sm font-weight-bolder">TOTAL</span></td>
                                <td><span class="text-sm font-weight-bolder">{{ number_format($summary_cost['total_messages'] ?? 0) }}</span></td>
                                <td><span class="text-sm font-weight-bolder">Rp {{ number_format($summary_cost['total_cost'] ?? 0, 0, ',', '.') }}</span></td>
                                <td><span class="text-sm font-weight-bolder text-danger">Rp {{ number_format($summary_cost['total_meta_cost'] ?? 0, 0, ',', '.') }}</span></td>
                                <td class="text-end pe-4">
                                    <span class="text-sm font-weight-bolder text-success">Rp {{ number_format($summary_cost['total_margin'] ?? 0, 0, ',', '.') }}</span>
                                    <span class="badge bg-success ms-2">{{ $summary_cost['margin_percentage'] ?? 0 }}%</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Section 4: Client Profitability Table (Full Width) --}}
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header pb-0 p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Client Profitability</h6>
                        <p class="text-xs text-secondary mb-0">Performa profit per klien</p>
                    </div>
                    @if(!empty($flaggedClients) && count($flaggedClients) > 0)
                    <span class="badge bg-gradient-danger">{{ count($flaggedClients) }} Klien Flagged</span>
                    @endif
                </div>
            </div>
            
            {{-- Flagged Clients Alert --}}
            @if(!empty($flaggedClients) && count($flaggedClients) > 0)
            <div class="px-3 pt-3">
                <div class="flagged-alert rounded p-3">
                    <p class="text-xs text-danger font-weight-bold mb-2">
                        <i class="fas fa-exclamation-triangle me-1"></i> FLAGGED: Klien rugi 3+ hari berturut
                    </p>
                    @foreach($flaggedClients as $flagged)
                    <div class="d-flex justify-content-between align-items-center bg-white rounded p-2 mb-2">
                        <div>
                            <span class="text-sm font-weight-bold">{{ $flagged['nama'] }}</span>
                            <span class="text-xs text-danger ms-2">{{ $flagged['reason'] }}</span>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-danger me-1" onclick="pauseClient({{ $flagged['id'] }})">
                                <i class="fas fa-pause fa-sm"></i> Pause
                            </button>
                            <button class="btn btn-sm btn-warning" onclick="limitClient({{ $flagged['id'] }})">
                                <i class="fas fa-lock fa-sm"></i> Limit
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            
            {{-- Client Table --}}
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Plan</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Saldo</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Revenue</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Cost</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Profit</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($clientProfitability as $client)
                            <tr>
                                <td>
                                    <div class="d-flex px-2 py-1">
                                        <div class="avatar avatar-sm bg-gradient-primary rounded-circle me-2 d-flex align-items-center justify-content-center">
                                            <span class="text-white text-xs">{{ strtoupper(substr($client['nama'], 0, 1)) }}</span>
                                        </div>
                                        <div class="d-flex flex-column justify-content-center">
                                            <h6 class="mb-0 text-sm">{{ $client['nama'] }}</h6>
                                            <p class="text-xs text-secondary mb-0">{{ number_format($client['message_count']) }} pesan</p>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-gradient-secondary">{{ $client['plan'] }}</span></td>
                                <td>
                                    <span class="text-xs font-weight-bold {{ $client['saldo'] < 50000 ? 'text-danger' : '' }}">
                                        Rp {{ number_format($client['saldo'], 0, ',', '.') }}
                                    </span>
                                </td>
                                <td><span class="text-xs font-weight-bold">Rp {{ number_format($client['revenue'], 0, ',', '.') }}</span></td>
                                <td><span class="text-xs font-weight-bold text-danger">Rp {{ number_format($client['cost_meta'], 0, ',', '.') }}</span></td>
                                <td>
                                    <span class="text-xs font-weight-bold {{ $client['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        Rp {{ number_format($client['profit'], 0, ',', '.') }}
                                    </span>
                                    <span class="badge bg-{{ $client['status_color'] }} ms-1">{{ $client['margin'] }}%</span>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $client['status'] === 'healthy' ? 'success' : ($client['status'] === 'warning' ? 'warning' : 'danger') }}">
                                        {{ $client['status_label'] }}
                                    </span>
                                </td>
                                <td class="align-middle text-center">
                                    <form action="{{ route('owner.impersonate.start', $client['id']) }}" method="POST" class="d-inline" title="Lihat sebagai Client">
                                        @csrf
                                        <button type="submit" class="btn btn-link text-primary p-1 mb-0" title="Impersonate Client">
                                            <i class="fas fa-eye text-sm"></i>
                                        </button>
                                    </form>
                                    <button class="btn btn-link text-warning p-1 mb-0" onclick="limitClient({{ $client['id'] }})" title="Batasi Limit">
                                        <i class="fas fa-lock text-sm"></i>
                                    </button>
                                    <button class="btn btn-link text-danger p-1 mb-0" onclick="pauseClient({{ $client['id'] }})" title="Pause">
                                        <i class="fas fa-pause text-sm"></i>
                                    </button>
                                    <button class="btn btn-link text-info p-1 mb-0" onclick="followUp({{ $client['id'] }})" title="Follow Up">
                                        <i class="fas fa-phone text-sm"></i>
                                    </button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="ni ni-single-02 text-secondary opacity-5" style="font-size: 3rem;"></i>
                                    <p class="text-sm text-secondary mt-3 mb-0">Belum ada data klien</p>
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

{{-- Section 5: Usage Monitor & Quick Stats --}}

{{-- Section 4.5: Trial Activation Monitor --}}
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header pb-0 p-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h6 class="mb-0">
                            <i class="fas fa-user-clock me-1 text-warning"></i>
                            Trial Belum Aktif
                        </h6>
                        <p class="text-xs text-secondary mb-0">User trial_selected yang belum bayar</p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="badge bg-gradient-warning">
                            {{ $trialStats['total_trial'] ?? 0 }} Total Trial
                        </span>
                        <span class="badge bg-gradient-danger">
                            {{ $trialStats['overdue_24h'] ?? 0 }} > 24 Jam
                        </span>
                        <span class="badge bg-gradient-success">
                            {{ $trialStats['conversion_rate'] ?? 0 }}% Konversi (30d)
                        </span>
                        <span class="badge bg-gradient-info">
                            {{ $trialStats['reminders_sent_7d'] ?? 0 }} Reminder (7d)
                        </span>
                    </div>
                </div>
            </div>

            {{-- KPI Mini Cards --}}
            <div class="card-body pt-3 pb-0 px-3">
                <div class="row">
                    <div class="col-md-3 col-6 mb-3">
                        <div class="border rounded p-3 text-center">
                            <p class="text-xs text-secondary mb-0">Total Trial</p>
                            <h4 class="font-weight-bolder mb-0 text-warning">{{ $trialStats['total_trial'] ?? 0 }}</h4>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="border rounded p-3 text-center">
                            <p class="text-xs text-secondary mb-0">Sudah > 24 Jam</p>
                            <h4 class="font-weight-bolder mb-0 text-danger">{{ $trialStats['overdue_24h'] ?? 0 }}</h4>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="border rounded p-3 text-center">
                            <p class="text-xs text-secondary mb-0">Konversi 30d</p>
                            <h4 class="font-weight-bolder mb-0 text-success">{{ $trialStats['conversion_rate'] ?? 0 }}%</h4>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-3">
                        <div class="border rounded p-3 text-center">
                            <p class="text-xs text-secondary mb-0">Reminder 7d</p>
                            <h4 class="font-weight-bolder mb-0 text-info">{{ $trialStats['reminders_sent_7d'] ?? 0 }}</h4>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Overdue Trial Users Table --}}
            @if(!empty($trialStats['overdue_list']) && count($trialStats['overdue_list']) > 0)
            <div class="card-body px-0 pt-0 pb-2">
                <div class="px-3 pb-2">
                    <p class="text-xs text-danger font-weight-bold mb-0">
                        <i class="fas fa-exclamation-circle me-1"></i> User > 24 Jam Belum Bayar (Top 10)
                    </p>
                </div>
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">User</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Telepon</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Daftar</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Lama (jam)</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($trialStats['overdue_list'] as $trial)
                            <tr>
                                <td>
                                    <div class="d-flex px-2 py-1">
                                        <div class="avatar avatar-sm bg-gradient-warning rounded-circle me-2 d-flex align-items-center justify-content-center">
                                            <span class="text-white text-xs">{{ strtoupper(substr($trial['name'], 0, 1)) }}</span>
                                        </div>
                                        <div class="d-flex flex-column justify-content-center">
                                            <h6 class="mb-0 text-sm">{{ $trial['name'] }}</h6>
                                            <p class="text-xs text-secondary mb-0">{{ $trial['email'] }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="text-xs">{{ $trial['phone'] ?? '-' }}</span></td>
                                <td><span class="text-xs">{{ $trial['registered_at'] }}</span></td>
                                <td>
                                    <span class="badge bg-gradient-{{ $trial['hours_since'] >= 48 ? 'danger' : 'warning' }}">
                                        {{ $trial['hours_since'] }} jam
                                    </span>
                                </td>
                                <td class="align-middle text-center">
                                    <button class="btn btn-link text-info p-1 mb-0" onclick="followUpTrial({{ $trial['id'] }})" title="Follow Up Manual">
                                        <i class="fas fa-phone text-sm"></i>
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @else
            <div class="card-body text-center py-4">
                <i class="fas fa-check-circle text-success" style="font-size: 2rem;"></i>
                <p class="text-sm text-secondary mt-2 mb-0">Tidak ada user trial overdue > 24 jam 🎉</p>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Section 5: Usage Monitor & Quick Stats --}}
<div class="row">
    {{-- Chart: Messages per Day --}}
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header pb-0 p-3">
                <h6 class="mb-0">Usage Monitor</h6>
                <p class="text-xs text-secondary mb-0">7 Hari Terakhir</p>
            </div>
            <div class="card-body p-3">
                <div class="chart-container">
                    <canvas id="usageChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    {{-- Quick Stats --}}
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0 p-3">
                <h6 class="mb-0">Quick Stats</h6>
                <p class="text-xs text-secondary mb-0">Ringkasan cepat</p>
            </div>
            <div class="card-body p-3">
                <ul class="list-group">
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
                        <div class="d-flex align-items-center">
                            <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3">
                                <i class="ni ni-single-02 text-white opacity-10"></i>
                            </div>
                            <span class="text-sm">Klien Aktif</span>
                        </div>
                        <span class="badge bg-gradient-primary">{{ $summary['total_clients_active'] ?? 0 }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
                        <div class="d-flex align-items-center">
                            <div class="icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md me-3">
                                <i class="ni ni-send text-white opacity-10"></i>
                            </div>
                            <span class="text-sm">Pesan Bulan Ini</span>
                        </div>
                        <span class="badge bg-gradient-success">{{ number_format($summary['total_messages'] ?? 0) }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
                        <div class="d-flex align-items-center">
                            <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-3">
                                <i class="ni ni-chart-pie-35 text-white opacity-10"></i>
                            </div>
                            <span class="text-sm">Avg Margin</span>
                        </div>
                        <span class="badge bg-gradient-info">{{ $summary['profit_margin'] ?? 0 }}%</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
                        <div class="d-flex align-items-center">
                            <div class="icon icon-shape icon-sm bg-gradient-danger shadow text-center border-radius-md me-3">
                                <i class="ni ni-bell-55 text-white opacity-10"></i>
                            </div>
                            <span class="text-sm">Klien Flagged</span>
                        </div>
                        @php $flaggedCount = !empty($flaggedClients) ? count($flaggedClients) : 0; @endphp
                        <span class="badge bg-gradient-{{ $flaggedCount > 0 ? 'danger' : 'success' }}">{{ $flaggedCount }}</span>
                    </li>
                    <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
                        <div class="d-flex align-items-center">
                            @php
                                $healthScore = ($summary['profit_margin'] ?? 0) >= 20 ? 'A' : (($summary['profit_margin'] ?? 0) >= 10 ? 'B' : 'C');
                                $healthColor = $healthScore === 'A' ? 'success' : ($healthScore === 'B' ? 'warning' : 'danger');
                            @endphp
                            <div class="icon icon-shape icon-sm bg-gradient-{{ $healthColor }} shadow text-center border-radius-md me-3">
                                <i class="ni ni-like-2 text-white opacity-10"></i>
                            </div>
                            <span class="text-sm">Health Score</span>
                        </div>
                        <span class="badge bg-gradient-{{ $healthColor }} fs-6">{{ $healthScore }}</span>
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
// Usage Chart
const usageData = @json($usageMonitor ?? []);
const labels = usageData.length > 0 ? usageData.map(d => d.label) : ['No Data'];
const messages = usageData.length > 0 ? usageData.map(d => d.messages) : [0];
const costs = usageData.length > 0 ? usageData.map(d => d.cost) : [0];

const ctx = document.getElementById('usageChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Pesan Terkirim',
                data: messages,
                backgroundColor: '#5e72e4',
                borderRadius: 4,
                yAxisID: 'y'
            },
            {
                label: 'Biaya (Rp)',
                data: costs,
                type: 'line',
                borderColor: '#f5365c',
                backgroundColor: 'rgba(245, 54, 92, 0.1)',
                fill: true,
                tension: 0.4,
                yAxisID: 'y1'
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 6 } }
        },
        scales: {
            y: { type: 'linear', position: 'left', title: { display: true, text: 'Jumlah Pesan' } },
            y1: { type: 'linear', position: 'right', title: { display: true, text: 'Biaya (Rp)' }, grid: { drawOnChartArea: false } }
        }
    }
});

// Actions
async function limitClient(clientId) {
    const confirmed = await OwnerPopup.confirmDanger({
        title: 'Batasi Limit Klien?',
        text: `
            <p class="mb-2">Anda akan membatasi limit klien ini.</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                    Klien tidak akan bisa mengirim pesan melebihi batas yang ditentukan.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-limit me-1"></i> Ya, Batasi'
    });
    
    if (confirmed) {
        OwnerPopup.loading('Memproses...');
        fetch(`/owner/client/${clientId}/limit`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
        })
        .then(r => r.json())
        .then(data => { 
            OwnerPopup.success(data.message).then(() => location.reload()); 
        })
        .catch(error => {
            OwnerPopup.error('Terjadi kesalahan: ' + error.message);
        });
    }
}

async function pauseClient(clientId) {
    const confirmed = await OwnerPopup.confirmDanger({
        title: 'Pause Semua Campaign?',
        text: `
            <p class="mb-2">Anda akan mem-pause semua campaign klien ini.</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-pause-circle text-warning me-1"></i>
                    Semua pengiriman pesan akan dihentikan sementara.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-pause me-1"></i> Ya, Pause'
    });
    
    if (confirmed) {
        OwnerPopup.loading('Memproses...');
        fetch(`/owner/client/${clientId}/pause`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' }
        })
        .then(r => r.json())
        .then(data => { 
            OwnerPopup.success(data.message).then(() => location.reload()); 
        })
        .catch(error => {
            OwnerPopup.error('Terjadi kesalahan: ' + error.message);
        });
    }
}

function followUp(clientId) {
    OwnerPopup.info('Feature follow up coming soon!', 'Coming Soon');
}

function followUpTrial(userId) {
    OwnerPopup.info('Feature follow up trial coming soon! User ID: ' + userId, 'Coming Soon');
}
</script>
@endpush
