@extends('owner.layouts.app')

@section('page-title', 'Detail Closing — ' . ($closing->period_label ?? ''))

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

    {{-- Back + Title --}}
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <a href="{{ route('owner.closing.index', ['year' => $year]) }}" class="text-sm text-dark">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
                </a>
                <h5 class="mt-2 mb-0 font-weight-bold">
                    <i class="fas fa-calendar-check text-success me-2"></i>
                    Monthly Closing — {{ $closing->period_label }}
                </h5>
            </div>
            <div class="d-flex gap-2">
                {{-- PDF --}}
                @if($closing->finance_status !== 'FAILED')
                <a href="{{ route('owner.closing.pdf', ['year' => $year, 'month' => $month]) }}"
                   class="btn btn-sm bg-gradient-danger mb-0">
                    <i class="fas fa-file-pdf me-1"></i> Download PDF
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Invoice Count</p>
                                <h5 class="font-weight-bolder mb-0">{{ number_format($closing->invoice_count ?? 0) }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                <i class="fas fa-file-invoice text-white text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Gross Revenue (DPP)</p>
                                <h5 class="font-weight-bolder mb-0">{{ $closing->formatted_invoice_gross ?? '-' }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                <i class="fas fa-money-bill-wave text-white text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-warning">Total PPN</p>
                                <h5 class="font-weight-bolder mb-0 text-warning">{{ $closing->formatted_invoice_ppn ?? '-' }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                <i class="fas fa-percentage text-white text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Net Revenue</p>
                                <h5 class="font-weight-bolder mb-0">{{ $closing->formatted_invoice_net ?? '-' }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                                <i class="fas fa-wallet text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        {{-- Info Closing --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Info Closing</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-sm text-muted" style="width: 40%;">Status Finance</td>
                            <td>{!! $closing->finance_status_badge !!}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Status Rekonsiliasi</td>
                            <td>{!! $closing->recon_badge !!}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Closed At</td>
                            <td class="text-sm">{{ $closing->finance_closed_at ? $closing->finance_closed_at->format('d M Y, H:i:s') : '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Closed By</td>
                            <td class="text-sm">{{ $closing->financeClosedBy?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Hash Integrity</td>
                            <td>
                                @if($closing->finance_closing_hash)
                                    <code class="text-xs">{{ Str::limit($closing->finance_closing_hash, 24) }}</code>
                                    <i class="fas fa-shield-alt text-success ms-1" title="{{ $closing->finance_closing_hash }}"></i>
                                @else
                                    <span class="text-muted text-xs">-</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Revenue Breakdown --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Revenue Breakdown</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-sm text-muted" style="width: 50%;">Subscription Revenue</td>
                            <td class="text-sm font-weight-bold text-primary">{{ $closing->formatted_invoice_subscription ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Topup Revenue</td>
                            <td class="text-sm font-weight-bold text-success">{{ $closing->formatted_invoice_topup ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Other Revenue</td>
                            <td class="text-sm">Rp {{ number_format($closing->invoice_other_revenue ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="table-light">
                            <td class="text-sm font-weight-bold">Gross Revenue (DPP)</td>
                            <td class="text-sm font-weight-bold">{{ $closing->formatted_invoice_gross ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Total PPN</td>
                            <td class="text-sm font-weight-bold text-warning">{{ $closing->formatted_invoice_ppn ?? '-' }}</td>
                        </tr>
                        <tr class="table-dark">
                            <td class="text-sm font-weight-bold text-white">Net Revenue</td>
                            <td class="text-sm font-weight-bold text-white">{{ $closing->formatted_invoice_net ?? '-' }}</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Reconciliation Detail --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">
                        <i class="fas fa-balance-scale me-2"></i>Rekonsiliasi Cross-Check
                        {!! $closing->recon_badge !!}
                    </h6>
                    <p class="text-xs text-muted mb-0">Invoice vs Wallet (cross-check, bukan sumber pendapatan)</p>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-sm text-muted" style="width: 50%;">Topup Invoice (DPP)</td>
                            <td class="text-sm font-weight-bold">{{ $closing->formatted_invoice_topup ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Topup Wallet (completed)</td>
                            <td class="text-sm font-weight-bold">Rp {{ number_format($closing->recon_wallet_topup ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="{{ ($closing->recon_topup_discrepancy ?? 0) > 0 ? 'table-warning' : '' }}">
                            <td class="text-sm text-muted">Selisih Topup</td>
                            <td class="text-sm font-weight-bold {{ ($closing->recon_topup_discrepancy ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                {{ $closing->formatted_recon_discrepancy ?? '-' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Wallet Usage (periode)</td>
                            <td class="text-sm">Rp {{ number_format($closing->recon_wallet_usage ?? 0, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Saldo Negatif?</td>
                            <td>
                                @if($closing->recon_has_negative_balance)
                                    <span class="badge bg-gradient-danger badge-sm">YA</span>
                                @else
                                    <span class="badge bg-gradient-success badge-sm">Tidak</span>
                                @endif
                            </td>
                        </tr>
                    </table>

                    @if($closing->finance_discrepancy_notes)
                        <div class="alert alert-warning mt-3 mb-0 text-sm">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            {{ $closing->finance_discrepancy_notes }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Revenue Snapshot (JSON detail) --}}
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-database me-2"></i>Revenue Snapshot per Tipe</h6>
                    <p class="text-xs text-muted mb-0">Snapshot data saat closing diproses</p>
                </div>
                <div class="card-body">
                    @if($closing->finance_revenue_snapshot)
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-xxs text-uppercase text-muted">Tipe</th>
                                        <th class="text-xxs text-uppercase text-muted">Qty</th>
                                        <th class="text-xxs text-uppercase text-muted">DPP</th>
                                        <th class="text-xxs text-uppercase text-muted">PPN</th>
                                        <th class="text-xxs text-uppercase text-muted">Bruto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($closing->finance_revenue_snapshot as $type => $data)
                                        <tr>
                                            <td class="text-sm">{{ ucfirst(str_replace('_', ' ', $type)) }}</td>
                                            <td class="text-sm">{{ $data['count'] ?? 0 }}</td>
                                            <td class="text-sm">Rp {{ number_format($data['dpp'] ?? 0, 0, ',', '.') }}</td>
                                            <td class="text-sm text-warning">Rp {{ number_format($data['ppn'] ?? 0, 0, ',', '.') }}</td>
                                            <td class="text-sm font-weight-bold">Rp {{ number_format($data['bruto'] ?? 0, 0, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted text-sm mb-0">Snapshot belum tersedia.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Actions --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-sm mb-0">
                            @if($closing->finance_status === 'CLOSED')
                                <i class="fas fa-lock text-success me-1"></i>
                                Closing ini sudah <strong>FINALIZED</strong>. Data terkunci permanen.
                            @elseif($closing->finance_status === 'DRAFT')
                                <i class="fas fa-edit text-info me-1"></i>
                                Status <strong>DRAFT</strong> — bisa di-regenerate atau di-finalize.
                            @elseif($closing->finance_status === 'FAILED')
                                <i class="fas fa-times-circle text-danger me-1"></i>
                                Closing <strong>GAGAL</strong> (rekonsiliasi mismatch). Review data lalu regenerate.
                            @endif
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        {{-- Re-generate (DRAFT/FAILED only) --}}
                        @if(in_array($closing->finance_status, ['DRAFT', 'FAILED']))
                            <form action="{{ route('owner.closing.close') }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Regenerate closing {{ $closing->period_label }}? Data sebelumnya akan ditimpa.');">
                                @csrf
                                <input type="hidden" name="year" value="{{ $year }}">
                                <input type="hidden" name="month" value="{{ $month }}">
                                <button type="submit" class="btn btn-sm bg-gradient-info mb-0">
                                    <i class="fas fa-sync-alt me-1"></i> Regenerate
                                </button>
                            </form>
                        @endif

                        {{-- Finalize (DRAFT only, rekon MATCH) --}}
                        @if($closing->finance_status === 'DRAFT')
                            <form action="{{ route('owner.closing.finalize', ['year' => $year, 'month' => $month]) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('FINALIZE closing {{ $closing->period_label }}? Setelah ini data TIDAK bisa diubah.');">
                                @csrf
                                <button type="submit" class="btn btn-sm bg-gradient-success mb-0">
                                    <i class="fas fa-lock me-1"></i> Finalize (Lock)
                                </button>
                            </form>
                        @endif

                        {{-- Reopen (CLOSED only, owner override) --}}
                        @if($closing->finance_status === 'CLOSED')
                            <form action="{{ route('owner.closing.reopen', ['year' => $year, 'month' => $month]) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('REOPEN closing {{ $closing->period_label }}? Data akan kembali ke DRAFT dan bisa diedit.');">
                                @csrf
                                <button type="submit" class="btn btn-sm bg-gradient-warning mb-0">
                                    <i class="fas fa-unlock me-1"></i> Reopen (Owner Override)
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
