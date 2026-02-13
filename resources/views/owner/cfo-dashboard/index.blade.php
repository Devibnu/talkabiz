@extends('owner.layouts.app')

@section('page-title', 'CFO Dashboard — ' . ($result['dashboard']['period']['label'] ?? ''))

@section('content')
{{-- ================================================================ --}}
{{-- DATA SOURCE & VALIDATION STATUS INDICATOR                         --}}
{{-- ================================================================ --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-{{ $dataSourceInfo['badge'] }} alert-dismissible fade show mb-0" role="alert">
            <span class="alert-icon">
                @if($dataSourceInfo['badge'] === 'success')
                    <i class="fas fa-lock"></i>
                @elseif($dataSourceInfo['badge'] === 'warning')
                    <i class="fas fa-chart-line"></i>
                @elseif($dataSourceInfo['badge'] === 'danger')
                    <i class="fas fa-exclamation-triangle"></i>
                @else
                    <i class="fas fa-info-circle"></i>
                @endif
            </span>
            <span class="alert-text">
                <strong class="text-uppercase">{{ $dataSourceInfo['badge_text'] }}</strong> — 
                {{ $dataSourceInfo['message'] }}
                
                @if(isset($result['validation']) && !$result['validation']['is_valid'])
                    <br>
                    <span class="text-danger font-weight-bold">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        PERINGATAN: Data tidak konsisten dengan Monthly Closing! 
                        Ditemukan {{ count($result['validation']['mismatches']) }} perbedaan.
                    </span>
                    <a href="#" class="text-white text-decoration-underline" onclick="viewMismatchReport(event)">
                        Lihat Detail Mismatch
                    </a>
                @endif
            </span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    </div>
</div>

{{-- Period Filter --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('owner.cfo.index') }}" class="d-flex align-items-center gap-3 flex-wrap">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-chart-line text-primary me-2 text-lg"></i>
                        <h6 class="mb-0">CFO Dashboard</h6>
                    </div>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <select name="month" class="form-select form-select-sm" style="width: 140px;">
                            @foreach($months as $m)
                                <option value="{{ $m['value'] }}" {{ $month == $m['value'] ? 'selected' : '' }}>
                                    {{ $m['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <select name="year" class="form-select form-select-sm" style="width: 100px;">
                            @foreach($years as $y)
                                <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm bg-gradient-primary mb-0">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@php
    // Shorthand untuk dashboard data
    $dashboard = $result['dashboard'];
@endphp
{{-- ================================================================ --}}
{{-- SUMMARY CARDS: Revenue, AR, Cashflow                             --}}
{{-- ================================================================ --}}
<div class="row mb-4">
    {{-- Total Revenue --}}
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Revenue</p>
                        <h5 class="font-weight-bolder mb-0">
                            Rp {{ number_format($dashboard['revenue']['total_revenue'] ?? 0, 0, ',', '.') }}
                        </h5>
                        <p class="text-xs mt-1 mb-0">
                            @php $growth = $dashboard['revenue']['growth_mom'] @endphp
                            @if($growth !== null)
                                <span class="font-weight-bold {{ $growth >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ $growth >= 0 ? '+' : '' }}{{ $growth }}%
                                </span>
                                <span class="text-muted">MoM</span>
                            @else
                                <span class="text-muted">Bulan pertama</span>
                            @endif
                        </p>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-money-bill-wave text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Unpaid AR --}}
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        @php
            $arTotal = $dashboard['ar']['total_outstanding'] ?? 0;
            $arColor = $arTotal <= 0 ? 'success' : ($arTotal > ($dashboard['revenue']['total_revenue'] ?? 1) * 0.5 ? 'danger' : 'warning');
        @endphp
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Piutang (AR)</p>
                        <h5 class="font-weight-bolder mb-0 text-{{ $arColor }}">
                            Rp {{ number_format($arTotal, 0, ',', '.') }}
                        </h5>
                        <p class="text-xs mt-1 mb-0">
                            <span class="text-muted">{{ $dashboard['ar']['total_invoices'] ?? 0 }} invoice unpaid</span>
                        </p>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-{{ $arColor }} shadow text-center border-radius-md">
                            <i class="fas fa-file-invoice text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Net Cashflow --}}
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        @php
            $netCf = $dashboard['cashflow']['net_cashflow'] ?? 0;
            $cfColor = $netCf >= 0 ? 'success' : 'danger';
        @endphp
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Net Cashflow</p>
                        <h5 class="font-weight-bolder mb-0 text-{{ $cfColor }}">
                            Rp {{ number_format($netCf, 0, ',', '.') }}
                        </h5>
                        <p class="text-xs mt-1 mb-0">
                            <span class="text-muted">{{ $dashboard['cashflow']['cash_in_count'] ?? 0 }} transaksi</span>
                        </p>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-{{ $cfColor }} shadow text-center border-radius-md">
                            <i class="fas fa-exchange-alt text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Collection Rate --}}
    <div class="col-xl-3 col-sm-6">
        @php
            $collRate = $dashboard['kpi']['collection_rate'] ?? 0;
            $collColor = $collRate >= 80 ? 'success' : ($collRate >= 50 ? 'warning' : 'danger');
        @endphp
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Collection Rate</p>
                        <h5 class="font-weight-bolder mb-0 text-{{ $collColor }}">
                            {{ $collRate !== null ? $collRate . '%' : '-' }}
                        </h5>
                        <p class="text-xs mt-1 mb-0">
                            <span class="text-muted">DSO: {{ $dashboard['kpi']['dso_days'] ?? 0 }} hari</span>
                        </p>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-{{ $collColor }} shadow text-center border-radius-md">
                            <i class="fas fa-percentage text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- ROW 2: Revenue Breakdown + Cashflow Detail                       --}}
{{-- ================================================================ --}}
<div class="row mb-4">
    {{-- Revenue Breakdown --}}
    <div class="col-lg-4 mb-4 mb-lg-0">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Revenue Breakdown</h6>
            </div>
            <div class="card-body">
                @php
                    $revSub   = $dashboard['revenue']['revenue_subscription'] ?? 0;
                    $revTop   = $dashboard['revenue']['revenue_topup'] ?? 0;
                    $revOther = $dashboard['revenue']['revenue_other'] ?? 0;
                    $revTotal = $dashboard['revenue']['total_revenue'] ?? 1;
                @endphp
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-sm font-weight-bold">Subscription</span>
                        <span class="text-sm text-success font-weight-bold">Rp {{ number_format($revSub, 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-gradient-success" style="width: {{ $revTotal > 0 ? round($revSub / $revTotal * 100) : 0 }}%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-sm font-weight-bold">Topup</span>
                        <span class="text-sm text-info font-weight-bold">Rp {{ number_format($revTop, 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-gradient-info" style="width: {{ $revTotal > 0 ? round($revTop / $revTotal * 100) : 0 }}%"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-sm font-weight-bold">Other</span>
                        <span class="text-sm text-secondary font-weight-bold">Rp {{ number_format($revOther, 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-gradient-secondary" style="width: {{ $revTotal > 0 ? round(max(0, $revOther) / $revTotal * 100) : 0 }}%"></div>
                    </div>
                </div>
                <hr class="horizontal dark">
                <div class="d-flex justify-content-between">
                    <span class="text-sm font-weight-bold">Total</span>
                    <span class="text-sm font-weight-bolder">Rp {{ number_format($revTotal, 0, ',', '.') }}</span>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <span class="text-xs text-muted">Jumlah Invoice</span>
                    <span class="text-xs font-weight-bold">{{ $dashboard['revenue']['invoice_count'] ?? 0 }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Cashflow Detail --}}
    <div class="col-lg-4 mb-4 mb-lg-0">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-wallet me-2"></i>Cashflow Detail</h6>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <p class="text-xs text-uppercase text-muted mb-0">Cash In</p>
                        <h6 class="mb-0 text-success">Rp {{ number_format($dashboard['cashflow']['cash_in'] ?? 0, 0, ',', '.') }}</h6>
                    </div>
                    <div class="icon icon-shape bg-gradient-success shadow-sm text-center border-radius-md">
                        <i class="fas fa-arrow-down text-white"></i>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <p class="text-xs text-uppercase text-muted mb-0">Cash Out (Refund)</p>
                        <h6 class="mb-0 text-danger">Rp {{ number_format($dashboard['cashflow']['cash_out'] ?? 0, 0, ',', '.') }}</h6>
                    </div>
                    <div class="icon icon-shape bg-gradient-danger shadow-sm text-center border-radius-md">
                        <i class="fas fa-arrow-up text-white"></i>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                    <div>
                        <p class="text-xs text-uppercase text-muted mb-0">Gateway Fees</p>
                        <h6 class="mb-0 text-warning">Rp {{ number_format($dashboard['cashflow']['gateway_fees'] ?? 0, 0, ',', '.') }}</h6>
                    </div>
                    <div class="icon icon-shape bg-gradient-warning shadow-sm text-center border-radius-md">
                        <i class="fas fa-percent text-white"></i>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-xs text-uppercase text-muted mb-0">Net Cashflow</p>
                        <h5 class="mb-0 font-weight-bolder text-{{ ($dashboard['cashflow']['net_cashflow'] ?? 0) >= 0 ? 'success' : 'danger' }}">
                            Rp {{ number_format($dashboard['cashflow']['net_cashflow'] ?? 0, 0, ',', '.') }}
                        </h5>
                    </div>
                    <div class="icon icon-shape bg-gradient-dark shadow-sm text-center border-radius-md">
                        <i class="fas fa-balance-scale text-white"></i>
                    </div>
                </div>
                @if($dashboard['cashflow']['bank_balance'] !== null)
                    <hr class="horizontal dark">
                    <div class="d-flex justify-content-between">
                        <span class="text-xs text-muted">Est. Saldo Bank</span>
                        <span class="text-sm font-weight-bold">Rp {{ number_format($dashboard['cashflow']['bank_balance'], 0, ',', '.') }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- AR Aging --}}
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-clock me-2"></i>AR Aging Analysis</h6>
            </div>
            <div class="card-body">
                @php
                    $ar = $dashboard['ar'];
                    $arTotalBucket = max(1, $ar['aging_0_7'] + $ar['aging_8_30'] + $ar['aging_over_30']);
                @endphp
                {{-- 0-7 days --}}
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <span class="badge badge-sm bg-gradient-success me-1">0–7 hari</span>
                            <span class="text-xs text-muted">{{ $ar['aging_0_7_count'] }} invoice</span>
                        </div>
                        <span class="text-sm font-weight-bold">Rp {{ number_format($ar['aging_0_7'], 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-gradient-success" style="width: {{ round($ar['aging_0_7'] / $arTotalBucket * 100) }}%"></div>
                    </div>
                </div>
                {{-- 8-30 days --}}
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <span class="badge badge-sm bg-gradient-warning me-1">8–30 hari</span>
                            <span class="text-xs text-muted">{{ $ar['aging_8_30_count'] }} invoice</span>
                        </div>
                        <span class="text-sm font-weight-bold text-warning">Rp {{ number_format($ar['aging_8_30'], 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-gradient-warning" style="width: {{ round($ar['aging_8_30'] / $arTotalBucket * 100) }}%"></div>
                    </div>
                </div>
                {{-- >30 days --}}
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div>
                            <span class="badge badge-sm bg-gradient-danger me-1">>30 hari</span>
                            <span class="text-xs text-muted">{{ $ar['aging_over_30_count'] }} invoice</span>
                        </div>
                        <span class="text-sm font-weight-bold text-danger">Rp {{ number_format($ar['aging_over_30'], 0, ',', '.') }}</span>
                    </div>
                    <div class="progress" style="height: 6px;">
                        <div class="progress-bar bg-gradient-danger" style="width: {{ round($ar['aging_over_30'] / $arTotalBucket * 100) }}%"></div>
                    </div>
                </div>
                <hr class="horizontal dark">
                <div class="d-flex justify-content-between">
                    <span class="text-sm font-weight-bold">Total Outstanding</span>
                    <span class="text-sm font-weight-bolder text-{{ $ar['total_outstanding'] > 0 ? 'danger' : 'success' }}">
                        Rp {{ number_format($ar['total_outstanding'], 0, ',', '.') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- KPI CARDS                                                        --}}
{{-- ================================================================ --}}
<div class="row mb-4">
    @php $kpi = $dashboard['kpi']; @endphp
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
        <div class="card">
            <div class="card-body p-3 text-center">
                @php
                    $arRatio = $kpi['ar_ratio'];
                    $arRatColor = ($arRatio === null || $arRatio <= 30) ? 'success' : ($arRatio <= 60 ? 'warning' : 'danger');
                @endphp
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">AR Ratio</p>
                <h5 class="font-weight-bolder text-{{ $arRatColor }} mb-0">
                    {{ $arRatio !== null ? $arRatio . '%' : '-' }}
                </h5>
                <p class="text-xs text-muted mb-0">Piutang / Revenue</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
        <div class="card">
            <div class="card-body p-3 text-center">
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">Avg Invoice</p>
                <h5 class="font-weight-bolder mb-0">
                    Rp {{ number_format($kpi['avg_invoice_value'] ?? 0, 0, ',', '.') }}
                </h5>
                <p class="text-xs text-muted mb-0">Rata-rata nilai</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
        <div class="card">
            <div class="card-body p-3 text-center">
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">Topup Freq</p>
                <h5 class="font-weight-bolder text-info mb-0">
                    {{ $kpi['topup_frequency'] ?? 0 }}x
                </h5>
                <p class="text-xs text-muted mb-0">per klien / bulan</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
        <div class="card">
            <div class="card-body p-3 text-center">
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">Rev / Client</p>
                <h5 class="font-weight-bolder mb-0">
                    Rp {{ number_format($kpi['revenue_per_client'] ?? 0, 0, ',', '.') }}
                </h5>
                <p class="text-xs text-muted mb-0">{{ $kpi['active_klien'] ?? 0 }} klien aktif</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
        @php
            $churn = $kpi['churn_rate'] ?? 0;
            $churnColor = $churn <= 3 ? 'success' : ($churn <= 8 ? 'warning' : 'danger');
        @endphp
        <div class="card">
            <div class="card-body p-3 text-center">
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">Churn Rate</p>
                <h5 class="font-weight-bolder text-{{ $churnColor }} mb-0">
                    {{ $churn }}%
                </h5>
                <p class="text-xs text-muted mb-0">{{ $kpi['churned_count'] ?? 0 }} churn / {{ $kpi['active_subs'] ?? 0 }} aktif</p>
            </div>
        </div>
    </div>
    <div class="col-xl-2 col-sm-4">
        <div class="card">
            <div class="card-body p-3 text-center">
                <p class="text-xs mb-1 text-uppercase font-weight-bold text-muted">DSO</p>
                <h5 class="font-weight-bolder mb-0">
                    {{ $kpi['dso_days'] ?? 0 }}
                    <span class="text-xs font-weight-normal">hari</span>
                </h5>
                <p class="text-xs text-muted mb-0">Days Sales Outstanding</p>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- CHARTS: Revenue & Cashflow (12 bulan)                            --}}
{{-- ================================================================ --}}
<div class="row mb-4">
    {{-- Revenue Chart --}}
    <div class="col-lg-6 mb-4 mb-lg-0">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Revenue Trend (12 Bulan)</h6>
            </div>
            <div class="card-body p-3">
                <canvas id="revenueChart" height="280"></canvas>
            </div>
        </div>
    </div>

    {{-- Cashflow Chart --}}
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Cashflow Trend (12 Bulan)</h6>
            </div>
            <div class="card-body p-3">
                <canvas id="cashflowChart" height="280"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- ================================================================ --}}
{{-- TABLES: Top Unpaid + High-Value Customers                        --}}
{{-- ================================================================ --}}
<div class="row mb-4">
    {{-- Top Unpaid Invoices --}}
    <div class="col-lg-6 mb-4 mb-lg-0">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-exclamation-circle text-danger me-2"></i>Top Unpaid Invoices</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0" style="max-height: 380px; overflow-y: auto;">
                    <table class="table align-items-center mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Invoice</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Total</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Due</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dashboard['ar']['overdue_invoices'] ?? [] as $inv)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold">{{ $inv['invoice_number'] ?? '#' . $inv['id'] }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ Str::limit($inv['klien'] ?? '-', 20) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">
                                            Rp {{ number_format($inv['total'] ?? 0, 0, ',', '.') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs {{ ($inv['days_overdue'] ?? 0) > 7 ? 'text-danger font-weight-bold' : 'text-muted' }}">
                                            {{ $inv['due_at'] ?? '-' }}
                                            @if(($inv['days_overdue'] ?? 0) > 0)
                                                <br><small class="text-danger">{{ $inv['days_overdue'] }}d overdue</small>
                                            @endif
                                        </span>
                                    </td>
                                    <td>
                                        @php $invStatus = $inv['status'] ?? 'unknown'; @endphp
                                        <span class="badge badge-sm
                                            @if($invStatus === 'expired') bg-gradient-danger
                                            @elseif($invStatus === 'pending') bg-gradient-warning
                                            @else bg-gradient-secondary
                                            @endif">
                                            {{ $invStatus }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="fas fa-check-circle text-success mb-2" style="font-size: 2rem;"></i>
                                        <p class="text-success text-sm mb-0 font-weight-bold">Tidak ada invoice overdue</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- High-Value Customers --}}
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-crown text-warning me-2"></i>Top Revenue Customers</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0" style="max-height: 380px; overflow-y: auto;">
                    <table class="table align-items-center mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Revenue</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($dashboard['top_customers'] ?? [] as $i => $cust)
                                <tr>
                                    <td class="ps-3">
                                        @if($i < 3)
                                            <span class="badge badge-sm bg-gradient-warning">{{ $i + 1 }}</span>
                                        @else
                                            <span class="text-xs text-muted">{{ $i + 1 }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ Str::limit($cust['nama_perusahaan'] ?? '-', 25) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold text-success">
                                            Rp {{ number_format($cust['total_revenue'] ?? 0, 0, ',', '.') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $cust['invoice_count'] ?? 0 }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-4">
                                        <p class="text-muted text-sm mb-0">Belum ada data</p>
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

{{-- ================================================================ --}}
{{-- TOP DEBTORS TABLE                                                --}}
{{-- ================================================================ --}}
@if(!empty($dashboard['ar']['top_debtors']))
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-user-clock text-danger me-2"></i>Top Debtors (Piutang Terbesar)</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">#</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Outstanding</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Invoice Unpaid</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Piutang Tertua</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Risk</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dashboard['ar']['top_debtors'] as $i => $debtor)
                                @php
                                    $oldestDue = $debtor['oldest_due'] ? \Carbon\Carbon::parse($debtor['oldest_due']) : null;
                                    $daysOld = $oldestDue ? max(0, now()->diffInDays($oldestDue, false)) : 0;
                                    $riskColor = $daysOld <= 7 ? 'success' : ($daysOld <= 30 ? 'warning' : 'danger');
                                @endphp
                                <tr>
                                    <td class="ps-3"><span class="text-xs">{{ $i + 1 }}</span></td>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ $debtor['nama_perusahaan'] }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold text-danger">
                                            Rp {{ number_format($debtor['outstanding'], 0, ',', '.') }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $debtor['invoice_count'] }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $oldestDue ? $oldestDue->format('d M Y') : '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-{{ $riskColor }}">
                                            @if($riskColor === 'success') Low
                                            @elseif($riskColor === 'warning') Medium
                                            @else High
                                            @endif
                                        </span>
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

{{-- Read-Only Notice --}}
<div class="row">
    <div class="col-12">
        <div class="alert text-white d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #344767 0%, #7b809a 100%); border: none;">
            <i class="fas fa-lock me-2"></i>
            <span class="text-sm">
                <strong>Read-Only Dashboard</strong> — Semua data real-time dari Invoice & Payment. Tidak ada tombol edit. 
                SSOT: Invoice. Sumber cashflow: Payment Gateway.
            </span>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // === Revenue Line Chart ===
    const revenueData = @json($dashboard['revenue_monthly'] ?? []);
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx && revenueData.length > 0) {
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: revenueData.map(d => d.label),
                datasets: [{
                    label: 'Revenue',
                    data: revenueData.map(d => d.amount),
                    borderColor: '#17ad37',
                    backgroundColor: 'rgba(23, 173, 55, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#17ad37',
                    pointRadius: 3,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return 'Rp ' + new Intl.NumberFormat('id-ID').format(ctx.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) {
                                if (val >= 1000000) return 'Rp ' + (val/1000000).toFixed(0) + 'M';
                                if (val >= 1000) return 'Rp ' + (val/1000).toFixed(0) + 'K';
                                return 'Rp ' + val;
                            },
                            font: { size: 10 }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        ticks: { font: { size: 10 }, maxRotation: 45 },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // === Cashflow Bar Chart ===
    const cashflowData = @json($dashboard['cashflow_monthly'] ?? []);
    const cashflowCtx = document.getElementById('cashflowChart');
    if (cashflowCtx && cashflowData.length > 0) {
        new Chart(cashflowCtx, {
            type: 'bar',
            data: {
                labels: cashflowData.map(d => d.label),
                datasets: [
                    {
                        label: 'Cash In',
                        data: cashflowData.map(d => d.cash_in),
                        backgroundColor: 'rgba(23, 173, 55, 0.7)',
                        borderRadius: 4,
                        barPercentage: 0.6,
                    },
                    {
                        label: 'Cash Out',
                        data: cashflowData.map(d => d.cash_out),
                        backgroundColor: 'rgba(234, 6, 6, 0.7)',
                        borderRadius: 4,
                        barPercentage: 0.6,
                    },
                    {
                        label: 'Net',
                        data: cashflowData.map(d => d.net),
                        type: 'line',
                        borderColor: '#5e72e4',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.4,
                        pointBackgroundColor: '#5e72e4',
                        pointRadius: 3,
                        yAxisID: 'y',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { font: { size: 10 }, usePointStyle: true, padding: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': Rp ' + new Intl.NumberFormat('id-ID').format(ctx.raw);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(val) {
                                if (val >= 1000000) return 'Rp ' + (val/1000000).toFixed(0) + 'M';
                                if (val >= 1000) return 'Rp ' + (val/1000).toFixed(0) + 'K';
                                return 'Rp ' + val;
                            },
                            font: { size: 10 }
                        },
                        grid: { color: 'rgba(0,0,0,0.05)' }
                    },
                    x: {
                        ticks: { font: { size: 10 }, maxRotation: 45 },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // === AR Aging Donut Chart ===
    const arCtx = document.getElementById('arAgingChart');
    if (arCtx) {
        const arData = @json($dashboard['ar'] ?? []);
        new Chart(arCtx, {
            type: 'doughnut',
            data: {
                labels: ['0-7 Days', '8-30 Days', 'Over 30 Days'],
                datasets: [{
                    data: [
                        arData.aging_0_7 || 0,
                        arData.aging_8_30 || 0,
                        arData.aging_over_30 || 0
                    ],
                    backgroundColor: [
                        'rgba(82, 196, 26, 0.7)',
                        'rgba(250, 173, 20, 0.7)',
                        'rgba(234, 6, 6, 0.7)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 10 }, padding: 10 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.label + ': Rp ' + new Intl.NumberFormat('id-ID').format(ctx.raw);
                            }
                        }
                    }
                }
            }
        });
    }
});

// === Finance Validation: View Mismatch Report ===
function viewMismatchReport(event) {
    event.preventDefault();
    
    const year = {{ $year }};
    const month = {{ $month }};
    
    fetch(`{{ route('owner.cfo.mismatch-report') }}?year=${year}&month=${month}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.message || 'Tidak ada mismatch report.');
                return;
            }
            
            const report = data.report;
            let html = '<div class="table-responsive">';
            html += '<h6 class="mb-3">Detail Perbedaan Data</h6>';
            html += '<table class="table table-sm table-bordered">';
            html += '<thead><tr>';
            html += '<th>Field</th><th class="text-end">Closing (Final)</th>';
            html += '<th class="text-end">Live Data</th><th class="text-end">Selisih</th>';
            html += '</tr></thead><tbody>';
            
            report.mismatches.forEach(m => {
                html += '<tr>';
                html += `<td><strong>${m.field}</strong></td>`;
                html += `<td class="text-end">Rp ${new Intl.NumberFormat('id-ID').format(m.expected)}</td>`;
                html += `<td class="text-end">Rp ${new Intl.NumberFormat('id-ID').format(m.actual)}</td>`;
                html += `<td class="text-end ${m.diff >= 0 ? 'text-success' : 'text-danger'}">`;
                html += `${m.diff >= 0 ? '+' : ''}Rp ${new Intl.NumberFormat('id-ID').format(m.diff)}`;
                html += '</td></tr>';
            });
            
            html += '</tbody></table>';
            html += `<p class="text-xs text-muted mt-2">Detected at: ${report.summary.detected_at}</p>`;
            html += '</div>';
            
            // Show in modal (if available) or alert
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                // Create modal dynamically
                let modalHtml = `
                    <div class="modal fade" id="mismatchModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Finance Data Mismatch</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">${html}</div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Remove old modal if exists
                const oldModal = document.getElementById('mismatchModal');
                if (oldModal) oldModal.remove();
                
                // Insert and show
                document.body.insertAdjacentHTML('beforeend', modalHtml);
                const modal = new bootstrap.Modal(document.getElementById('mismatchModal'));
                modal.show();
            } else {
                // Fallback: show in alert (not ideal but works)
                alert('View mismatch in browser console');
                console.log('Mismatch Report:', report);
            }
        })
        .catch(err => {
            console.error('Error fetching mismatch report:', err);
            alert('Error loading mismatch report. Check console.');
        });
}
</script>
@endpush
