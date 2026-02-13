@extends('owner.layouts.app')

@section('page-title', 'Preview Rekonsiliasi ' . ucfirst($tab))

@section('content')
<div class="container-fluid py-4">

    {{-- Breadcrumb --}}
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                    <li class="breadcrumb-item">
                        <a href="{{ route('owner.reconciliation.index') }}" class="text-sm text-dark">
                            <i class="fas fa-balance-scale me-1"></i>Rekonsiliasi
                        </a>
                    </li>
                    <li class="breadcrumb-item active text-sm">Preview {{ ucfirst($tab) }}</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-{{ $tab === 'gateway' ? 'primary' : 'success' }}">
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                                <i class="fas fa-{{ $tab === 'gateway' ? 'credit-card text-primary' : 'university text-success' }} text-lg"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h5 class="text-white mb-0">Preview Rekonsiliasi {{ ucfirst($tab) }}</h5>
                            <p class="text-white text-sm mb-0 opacity-8">
                                Periode: {{ $preview['period'] ?? 'N/A' }} — Preview saja, belum disimpan.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Expected</p>
                    <h5 class="font-weight-bolder mb-0">
                        Rp {{ number_format($preview['total_expected'] ?? 0, 0, ',', '.') }}
                    </h5>
                    <p class="text-xs text-muted mb-0">
                        {{ $tab === 'gateway' ? 'Total Invoice PAID' : 'Total Payment SUCCESS' }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Actual</p>
                    <h5 class="font-weight-bolder mb-0">
                        Rp {{ number_format($preview['total_actual'] ?? 0, 0, ',', '.') }}
                    </h5>
                    <p class="text-xs text-muted mb-0">
                        {{ $tab === 'gateway' ? 'Total Payment SUCCESS' : 'Total Bank Credit' }}
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Selisih</p>
                    <h5 class="font-weight-bolder mb-0 {{ ($preview['difference'] ?? 0) != 0 ? 'text-danger' : 'text-success' }}">
                        Rp {{ number_format(abs($preview['difference'] ?? 0), 0, ',', '.') }}
                    </h5>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Status</p>
                    @php
                        $status = $preview['status'] ?? 'UNKNOWN';
                        $statusColor = match($status) {
                            'MATCH' => 'success',
                            'PARTIAL_MATCH' => 'warning',
                            'MISMATCH' => 'danger',
                            default => 'secondary',
                        };
                    @endphp
                    <h5 class="mb-0">
                        <span class="badge bg-gradient-{{ $statusColor }}">{{ $status }}</span>
                    </h5>
                </div>
            </div>
        </div>
    </div>

    {{-- Detail Tables --}}
    @if(!empty($preview['unmatched_invoices']))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        {{ $tab === 'gateway' ? 'Invoice PAID tanpa Payment' : 'Payment tanpa Bank Statement' }}
                        ({{ count($preview['unmatched_invoices']) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">ID</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Detail</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['unmatched_invoices'] as $item)
                                    <tr>
                                        <td class="ps-4"><span class="text-sm font-weight-bold">{{ $item['id'] ?? '-' }}</span></td>
                                        <td><span class="text-xs">{{ $item['invoice_number'] ?? $item['reference'] ?? '-' }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['amount'] ?? 0, 0, ',', '.') }}</span></td>
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

    @if(!empty($preview['unmatched_payments']))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-warning mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        {{ $tab === 'gateway' ? 'Payment SUCCESS tanpa Invoice PAID' : 'Bank Statement tanpa Payment' }}
                        ({{ count($preview['unmatched_payments']) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">ID</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Reference</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['unmatched_payments'] as $item)
                                    <tr>
                                        <td class="ps-4"><span class="text-sm font-weight-bold">{{ $item['id'] ?? '-' }}</span></td>
                                        <td><span class="text-xs">{{ $item['gateway_order_id'] ?? $item['reference'] ?? '-' }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['amount'] ?? 0, 0, ',', '.') }}</span></td>
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

    @if(!empty($preview['amount_mismatches']))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-warning mb-0">
                        <i class="fas fa-not-equal me-2"></i>
                        Selisih Nominal ({{ count($preview['amount_mismatches']) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Invoice</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Expected</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actual</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Difference</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['amount_mismatches'] as $item)
                                    <tr>
                                        <td class="ps-4"><span class="text-sm font-weight-bold">{{ $item['invoice_id'] ?? $item['payment_id'] ?? '-' }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['expected'] ?? 0, 0, ',', '.') }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['actual'] ?? 0, 0, ',', '.') }}</span></td>
                                        <td><span class="text-sm text-danger">Rp {{ number_format(abs($item['difference'] ?? 0), 0, ',', '.') }}</span></td>
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

    @if(!empty($preview['double_payments']))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border border-danger">
                <div class="card-header pb-0">
                    <h6 class="text-danger mb-0">
                        <i class="fas fa-copy me-2"></i>
                        Double Payment Terdeteksi ({{ count($preview['double_payments']) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Invoice</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Jumlah Payment</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['double_payments'] as $item)
                                    <tr>
                                        <td class="ps-4"><span class="text-sm font-weight-bold">{{ $item['invoice_id'] ?? '-' }}</span></td>
                                        <td><span class="badge bg-gradient-danger">{{ $item['payment_count'] ?? 0 }}x</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['total_amount'] ?? 0, 0, ',', '.') }}</span></td>
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

    {{-- All OK Message --}}
    @if(($preview['status'] ?? '') === 'MATCH')
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-success mb-0">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Semua data cocok!</strong> Tidak ditemukan discrepancy. Silakan proses rekonsiliasi untuk menyimpan hasil.
            </div>
        </div>
    </div>
    @endif

    {{-- Actions --}}
    <div class="row">
        <div class="col-12 d-flex justify-content-between">
            <a href="{{ route('owner.reconciliation.index') }}" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
            <form action="{{ route('owner.reconciliation.reconcile-' . $tab) }}" method="POST"
                  onsubmit="return confirm('Simpan hasil rekonsiliasi {{ $tab }}?')">
                @csrf
                <input type="hidden" name="year" value="{{ $preview['year'] ?? now()->year }}">
                <input type="hidden" name="month" value="{{ $preview['month'] ?? now()->month }}">
                <button type="submit" class="btn btn-sm btn-{{ $tab === 'gateway' ? 'primary' : 'success' }}">
                    <i class="fas fa-check-double me-1"></i> Simpan Rekonsiliasi {{ ucfirst($tab) }}
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
