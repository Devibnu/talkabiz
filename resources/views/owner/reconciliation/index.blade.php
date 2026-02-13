@extends('owner.layouts.app')

@section('page-title', 'Rekonsiliasi Bank & Gateway')

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

    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-dark">
                <div class="card-body p-3">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                <i class="fas fa-balance-scale text-white text-lg"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h5 class="text-white mb-0 font-weight-bold">Rekonsiliasi Bank & Payment Gateway</h5>
                            <p class="text-white text-sm mb-0 opacity-8">
                                Invoice (SSOT) → Gateway (bukti bayar) → Bank (bukti dana masuk). Three-way matching.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Action Forms --}}
    <div class="row mb-4">
        {{-- Gateway Reconciliation --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-credit-card me-2 text-primary"></i>Rekonsiliasi Gateway</h6>
                    <p class="text-xs text-muted mb-0">Invoice PAID vs Payment Gateway SUCCESS</p>
                </div>
                <div class="card-body">
                    <form action="{{ route('owner.reconciliation.preview-gateway') }}" method="POST" class="mb-2">
                        @csrf
                        @include('owner.reconciliation._period-fields')
                        <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fas fa-search me-1"></i> Preview Gateway
                        </button>
                    </form>
                    <hr class="horizontal dark my-2">
                    <form action="{{ route('owner.reconciliation.reconcile-gateway') }}" method="POST"
                          onsubmit="return confirm('Proses Rekonsiliasi Gateway untuk periode ini?')">
                        @csrf
                        @include('owner.reconciliation._period-fields')
                        <button type="submit" class="btn btn-sm btn-primary w-100">
                            <i class="fas fa-check-double me-1"></i> Proses Rekonsiliasi Gateway
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Bank Reconciliation --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-university me-2 text-success"></i>Rekonsiliasi Bank</h6>
                    <p class="text-xs text-muted mb-0">Payment Gateway vs Bank Statements</p>
                </div>
                <div class="card-body">
                    <form action="{{ route('owner.reconciliation.preview-bank') }}" method="POST" class="mb-2">
                        @csrf
                        @include('owner.reconciliation._period-fields')
                        <button type="submit" class="btn btn-sm btn-outline-success w-100">
                            <i class="fas fa-search me-1"></i> Preview Bank
                        </button>
                    </form>
                    <hr class="horizontal dark my-2">
                    <form action="{{ route('owner.reconciliation.reconcile-bank') }}" method="POST"
                          onsubmit="return confirm('Proses Rekonsiliasi Bank untuk periode ini?')">
                        @csrf
                        @include('owner.reconciliation._period-fields')
                        <button type="submit" class="btn btn-sm btn-success w-100">
                            <i class="fas fa-check-double me-1"></i> Proses Rekonsiliasi Bank
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Import Bank Statements --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-file-upload me-2 text-warning"></i>Import Bank Statement</h6>
                    <p class="text-xs text-muted mb-0">Upload CSV mutasi bank (format: tanggal, jumlah, tipe, deskripsi, referensi)</p>
                </div>
                <div class="card-body">
                    <form action="{{ route('owner.reconciliation.import-bank') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label text-xs font-weight-bold text-uppercase">File CSV</label>
                            <input type="file" name="csv_file" class="form-control form-control-sm" accept=".csv,.txt" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-warning w-100">
                            <i class="fas fa-upload me-1"></i> Import CSV
                        </button>
                    </form>
                    <hr class="horizontal dark my-2">
                    <p class="text-xs text-muted mb-1">Format CSV: <code>bank_name,trx_date,amount,trx_type,description,reference</code></p>
                    <p class="text-xs text-muted mb-0">trx_type: <code>credit</code> atau <code>debit</code></p>
                </div>
            </div>
        </div>
    </div>

    {{-- Year Filter --}}
    <div class="row mb-3">
        <div class="col-12">
            <div class="btn-group" role="group">
                @foreach($availableYears as $y)
                    <a href="{{ route('owner.reconciliation.index', ['year' => $y]) }}"
                       class="btn btn-sm {{ $y == $year ? 'btn-dark' : 'btn-outline-dark' }}">
                        {{ $y }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Reconciliation History Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="mb-0"><i class="fas fa-list me-2"></i>Riwayat Rekonsiliasi — {{ $year }}</h6>
                        </div>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">

                    {{-- Tabs: Gateway / Bank --}}
                    <ul class="nav nav-tabs mx-3 mt-3" id="reconTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="tab-gateway" data-bs-toggle="tab" href="#panel-gateway" role="tab">
                                <i class="fas fa-credit-card me-1"></i> Gateway
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="tab-bank" data-bs-toggle="tab" href="#panel-bank" role="tab">
                                <i class="fas fa-university me-1"></i> Bank
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content px-3 pb-3">
                        {{-- Gateway Tab --}}
                        <div class="tab-pane fade show active" id="panel-gateway" role="tabpanel">
                            @php
                                $gatewayLogs = collect($logs)->where('source', 'gateway')->sortByDesc('period_month');
                            @endphp
                            @if($gatewayLogs->isEmpty())
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox text-muted fa-3x mb-3"></i>
                                    <p class="text-muted">Belum ada rekonsiliasi gateway untuk tahun {{ $year }}</p>
                                </div>
                            @else
                                <div class="table-responsive mt-3">
                                    <table class="table align-items-center mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Periode</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Expected</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actual</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Selisih</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tanggal</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($gatewayLogs as $log)
                                                <tr>
                                                    <td class="ps-4">
                                                        <span class="text-sm font-weight-bold">{{ $log->period_label ?? ($log->period_year . '-' . str_pad($log->period_month, 2, '0', STR_PAD_LEFT)) }}</span>
                                                    </td>
                                                    <td><span class="text-sm">Rp {{ number_format($log->total_expected, 0, ',', '.') }}</span></td>
                                                    <td><span class="text-sm">Rp {{ number_format($log->total_actual, 0, ',', '.') }}</span></td>
                                                    <td>
                                                        <span class="text-sm {{ $log->difference != 0 ? 'text-danger font-weight-bold' : 'text-success' }}">
                                                            Rp {{ number_format(abs($log->difference), 0, ',', '.') }}
                                                        </span>
                                                    </td>
                                                    <td>{!! $log->status_badge ?? '<span class="badge badge-sm bg-gradient-secondary">' . $log->status . '</span>' !!}</td>
                                                    <td><span class="text-xs text-muted">{{ $log->reconciled_at ? \Carbon\Carbon::parse($log->reconciled_at)->format('d M Y H:i') : '-' }}</span></td>
                                                    <td>
                                                        <a href="{{ route('owner.reconciliation.show', ['year' => $log->period_year, 'month' => $log->period_month, 'source' => 'gateway']) }}"
                                                           class="btn btn-xs btn-outline-dark mb-0">
                                                            <i class="fas fa-eye"></i> Detail
                                                        </a>
                                                        <a href="{{ route('owner.reconciliation.export-csv', ['year' => $log->period_year, 'month' => $log->period_month, 'source' => 'gateway']) }}"
                                                           class="btn btn-xs btn-outline-info mb-0">
                                                            <i class="fas fa-download"></i> CSV
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                        {{-- Bank Tab --}}
                        <div class="tab-pane fade" id="panel-bank" role="tabpanel">
                            @php
                                $bankLogs = collect($logs)->where('source', 'bank')->sortByDesc('period_month');
                            @endphp
                            @if($bankLogs->isEmpty())
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox text-muted fa-3x mb-3"></i>
                                    <p class="text-muted">Belum ada rekonsiliasi bank untuk tahun {{ $year }}</p>
                                </div>
                            @else
                                <div class="table-responsive mt-3">
                                    <table class="table align-items-center mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Periode</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Expected</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actual</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Selisih</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tanggal</th>
                                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($bankLogs as $log)
                                                <tr>
                                                    <td class="ps-4">
                                                        <span class="text-sm font-weight-bold">{{ $log->period_label ?? ($log->period_year . '-' . str_pad($log->period_month, 2, '0', STR_PAD_LEFT)) }}</span>
                                                    </td>
                                                    <td><span class="text-sm">Rp {{ number_format($log->total_expected, 0, ',', '.') }}</span></td>
                                                    <td><span class="text-sm">Rp {{ number_format($log->total_actual, 0, ',', '.') }}</span></td>
                                                    <td>
                                                        <span class="text-sm {{ $log->difference != 0 ? 'text-danger font-weight-bold' : 'text-success' }}">
                                                            Rp {{ number_format(abs($log->difference), 0, ',', '.') }}
                                                        </span>
                                                    </td>
                                                    <td>{!! $log->status_badge ?? '<span class="badge badge-sm bg-gradient-secondary">' . $log->status . '</span>' !!}</td>
                                                    <td><span class="text-xs text-muted">{{ $log->reconciled_at ? \Carbon\Carbon::parse($log->reconciled_at)->format('d M Y H:i') : '-' }}</span></td>
                                                    <td>
                                                        <a href="{{ route('owner.reconciliation.show', ['year' => $log->period_year, 'month' => $log->period_month, 'source' => 'bank']) }}"
                                                           class="btn btn-xs btn-outline-dark mb-0">
                                                            <i class="fas fa-eye"></i> Detail
                                                        </a>
                                                        <a href="{{ route('owner.reconciliation.export-csv', ['year' => $log->period_year, 'month' => $log->period_month, 'source' => 'bank']) }}"
                                                           class="btn btn-xs btn-outline-info mb-0">
                                                            <i class="fas fa-download"></i> CSV
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Info Box --}}
    <div class="row mt-3">
        <div class="col-12">
            <div class="card card-body border border-info border-1 p-3">
                <div class="d-flex align-items-start">
                    <i class="fas fa-info-circle text-info me-3 mt-1"></i>
                    <div>
                        <h6 class="text-sm mb-1">Alur Rekonsiliasi</h6>
                        <ol class="text-xs text-muted mb-0 ps-3">
                            <li><strong>Import</strong> bank statements (CSV / manual)</li>
                            <li><strong>Preview Gateway</strong> — cek Invoice PAID vs Payment SUCCESS</li>
                            <li><strong>Proses Gateway</strong> — simpan hasil, deteksi mismatch & double payment</li>
                            <li><strong>Preview Bank</strong> — cek Payment SUCCESS vs mutasi bank credit</li>
                            <li><strong>Proses Bank</strong> — simpan hasil, deteksi unmatched</li>
                            <li>Jika OK → lanjut <strong>Monthly Closing</strong></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
