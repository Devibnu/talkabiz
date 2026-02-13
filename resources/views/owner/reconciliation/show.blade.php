@extends('owner.layouts.app')

@section('page-title', 'Detail Rekonsiliasi ' . ucfirst($source))

@section('content')
<div class="container-fluid py-4">

    {{-- Flash Messages --}}
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    {{-- Breadcrumb --}}
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                    <li class="breadcrumb-item">
                        <a href="{{ route('owner.reconciliation.index', ['year' => $year]) }}" class="text-sm text-dark">
                            <i class="fas fa-balance-scale me-1"></i>Rekonsiliasi
                        </a>
                    </li>
                    <li class="breadcrumb-item active text-sm">
                        {{ ucfirst($source) }} — {{ $log->period_label ?? ($year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT)) }}
                    </li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            @php
                $headerColor = match($log->status) {
                    'MATCH' => 'success',
                    'PARTIAL_MATCH' => 'warning',
                    'MISMATCH' => 'danger',
                    default => 'dark',
                };
            @endphp
            <div class="card bg-gradient-{{ $headerColor }}">
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                                <i class="fas fa-{{ $source === 'gateway' ? 'credit-card' : 'university' }} text-{{ $headerColor }} text-lg"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h5 class="text-white mb-0">
                                Rekonsiliasi {{ ucfirst($source) }} — {{ $log->period_label ?? '' }}
                            </h5>
                            <p class="text-white text-sm mb-0 opacity-8">
                                Status: {{ $log->status }}
                                @if($log->is_locked) | <i class="fas fa-lock"></i> LOCKED @endif
                            </p>
                        </div>
                        <div class="col-auto">
                            <a href="{{ route('owner.reconciliation.export-csv', ['year' => $year, 'month' => $month, 'source' => $source]) }}"
                               class="btn btn-sm btn-white mb-0">
                                <i class="fas fa-download me-1"></i> Export CSV
                            </a>
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
                    <div class="row">
                        <div class="col-8">
                            <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Expected</p>
                            <h5 class="font-weight-bolder mb-0">Rp {{ number_format($log->total_expected, 0, ',', '.') }}</h5>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                <i class="fas fa-file-invoice-dollar text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-muted mb-0 mt-1">
                        {{ $log->total_invoices ?? $log->total_invoice_count ?? '-' }} records
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Actual</p>
                            <h5 class="font-weight-bolder mb-0">Rp {{ number_format($log->total_actual, 0, ',', '.') }}</h5>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                <i class="fas fa-check-circle text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-muted mb-0 mt-1">
                        {{ $log->total_payments ?? $log->total_payment_count ?? '-' }} records
                    </p>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Selisih</p>
                            <h5 class="font-weight-bolder mb-0 {{ $log->difference != 0 ? 'text-danger' : 'text-success' }}">
                                Rp {{ number_format(abs($log->difference), 0, ',', '.') }}
                            </h5>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-{{ $log->difference != 0 ? 'danger' : 'success' }} shadow text-center border-radius-md">
                                <i class="fas fa-{{ $log->difference != 0 ? 'exclamation-triangle' : 'equals' }} text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Status</p>
                            <h5 class="mb-0">{!! $log->status_badge ?? '<span class="badge bg-gradient-secondary">' . $log->status . '</span>' !!}</h5>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                                <i class="fas fa-flag text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                    <p class="text-xs text-muted mb-0 mt-1">
                        {{ $log->reconciled_at ? \Carbon\Carbon::parse($log->reconciled_at)->format('d M Y H:i') : '-' }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Detail Sections --}}
    @php
        $unmatchedInvoices = $log->unmatched_invoices ?? [];
        $unmatchedPayments = $log->unmatched_payments ?? [];
        $amountMismatches = $log->amount_mismatches ?? [];
        $doublePayments = $log->double_payments ?? [];
    @endphp

    {{-- Unmatched Invoices --}}
    @if(!empty($unmatchedInvoices))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-danger mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        {{ $source === 'gateway' ? 'Invoice PAID tanpa Payment' : 'Payment tanpa Bank Statement' }}
                        ({{ count($unmatchedInvoices) }})
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
                                @foreach($unmatchedInvoices as $item)
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

    {{-- Unmatched Payments --}}
    @if(!empty($unmatchedPayments))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-warning mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        {{ $source === 'gateway' ? 'Payment SUCCESS tanpa Invoice PAID' : 'Bank Statement tanpa Payment' }}
                        ({{ count($unmatchedPayments) }})
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
                                @foreach($unmatchedPayments as $item)
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

    {{-- Amount Mismatches --}}
    @if(!empty($amountMismatches))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="text-warning mb-0">
                        <i class="fas fa-not-equal me-2"></i>
                        Selisih Nominal ({{ count($amountMismatches) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">ID</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Expected</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actual</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Diff</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($amountMismatches as $item)
                                    <tr>
                                        <td class="ps-4"><span class="text-sm font-weight-bold">{{ $item['invoice_id'] ?? $item['payment_id'] ?? '-' }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['expected'] ?? 0, 0, ',', '.') }}</span></td>
                                        <td><span class="text-sm">Rp {{ number_format($item['actual'] ?? 0, 0, ',', '.') }}</span></td>
                                        <td><span class="text-sm text-danger font-weight-bold">Rp {{ number_format(abs($item['difference'] ?? 0), 0, ',', '.') }}</span></td>
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

    {{-- Double Payments --}}
    @if(!empty($doublePayments))
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border border-danger">
                <div class="card-header pb-0">
                    <h6 class="text-danger mb-0">
                        <i class="fas fa-copy me-2"></i>
                        Double Payment ({{ count($doublePayments) }})
                    </h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Invoice</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Jumlah Payment</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($doublePayments as $item)
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

    {{-- All OK --}}
    @if(empty($unmatchedInvoices) && empty($unmatchedPayments) && empty($amountMismatches) && empty($doublePayments))
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-success mb-0">
                <i class="fas fa-check-circle me-2"></i>
                <strong>Semua data cocok!</strong> Tidak ada discrepancy terdeteksi untuk periode ini.
            </div>
        </div>
    </div>
    @endif

    {{-- Notes & Discrepancy Notes --}}
    @if($log->notes || $log->discrepancy_notes)
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Catatan</h6>
                </div>
                <div class="card-body">
                    @if($log->notes)
                        <p class="text-sm mb-1"><strong>Notes:</strong> {{ $log->notes }}</p>
                    @endif
                    @if($log->discrepancy_notes)
                        <p class="text-sm mb-0"><strong>Discrepancy:</strong> {{ $log->discrepancy_notes }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Metadata --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Metadata</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td class="text-xs text-muted" width="40%">Source</td>
                            <td class="text-sm font-weight-bold">{!! $log->source_badge ?? ucfirst($source) !!}</td>
                        </tr>
                        <tr>
                            <td class="text-xs text-muted">Period Key</td>
                            <td class="text-sm">{{ $log->period_key ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs text-muted">Reconciled By</td>
                            <td class="text-sm">{{ $log->reconciledByUser->name ?? 'System' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs text-muted">Reconciled At</td>
                            <td class="text-sm">{{ $log->reconciled_at ? \Carbon\Carbon::parse($log->reconciled_at)->format('d M Y H:i:s') : '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-xs text-muted">Locked</td>
                            <td class="text-sm">
                                @if($log->is_locked)
                                    <span class="badge bg-gradient-danger">LOCKED</span>
                                @else
                                    <span class="badge bg-gradient-success">OPEN</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-xs text-muted">Hash</td>
                            <td class="text-xs font-monospace">{{ $log->recon_hash ?? '-' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Actions --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>Aksi</h6>
                </div>
                <div class="card-body">
                    @if(!$log->is_locked)

                        {{-- Re-run Reconciliation --}}
                        <form action="{{ route('owner.reconciliation.reconcile-' . $source) }}" method="POST" class="mb-3"
                              onsubmit="return confirm('Re-run rekonsiliasi {{ $source }} periode ini? Data lama akan ditimpa.')">
                            @csrf
                            <input type="hidden" name="year" value="{{ $year }}">
                            <input type="hidden" name="month" value="{{ $month }}">
                            <button type="submit" class="btn btn-sm btn-outline-{{ $source === 'gateway' ? 'primary' : 'success' }} w-100">
                                <i class="fas fa-sync me-1"></i> Re-run Rekonsiliasi
                            </button>
                        </form>

                        {{-- Mark as OK --}}
                        @if($log->status !== 'MATCH')
                        <form action="{{ route('owner.reconciliation.mark-ok', ['year' => $year, 'month' => $month, 'source' => $source]) }}" method="POST"
                              onsubmit="return confirm('Tandai rekonsiliasi ini sebagai OK? Catatan wajib diisi.')">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label text-xs font-weight-bold text-uppercase">Catatan (wajib)</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2" required
                                          placeholder="Alasan menandai OK meski ada discrepancy..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-sm btn-warning w-100">
                                <i class="fas fa-check me-1"></i> Tandai Rekonsiliasi OK
                            </button>
                        </form>
                        @else
                        <div class="alert alert-success text-sm mb-0">
                            <i class="fas fa-check-circle me-2"></i>Status sudah MATCH. Siap untuk Monthly Closing.
                        </div>
                        @endif

                    @else
                        <div class="alert alert-warning text-sm mb-0">
                            <i class="fas fa-lock me-2"></i>
                            Rekonsiliasi ini sudah di-lock. Tidak dapat diubah.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Back --}}
    <div class="row">
        <div class="col-12">
            <a href="{{ route('owner.reconciliation.index', ['year' => $year]) }}" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
            </a>
        </div>
    </div>
</div>
@endsection
