@extends('owner.layouts.app')

@section('page-title', 'Preview Closing — ' . ($preview['period'] ?? ''))

@section('content')
<div class="container-fluid py-4">

    {{-- Back + Title --}}
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center">
            <div>
                <a href="{{ route('owner.closing.index') }}" class="text-sm text-dark">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
                </a>
                <h5 class="mt-2 mb-0 font-weight-bold">
                    <i class="fas fa-eye text-info me-2"></i>
                    Preview Closing — {{ $preview['period'] }}
                </h5>
                <p class="text-sm text-muted mb-0">Data dihitung dari Invoice (SSOT), belum disimpan</p>
            </div>
        </div>
    </div>

    {{-- Warning if already closed --}}
    @if(!empty($preview['existing_closing']) && $preview['existing_closing']['is_locked'])
        <div class="alert alert-danger">
            <i class="fas fa-lock me-2"></i>
            Periode ini sudah berstatus <strong>CLOSED</strong> ({{ $preview['existing_closing']['finance_closed_at'] }}).
            Tidak bisa di-regenerate. Hubungi owner untuk reopen.
        </div>
    @elseif(!empty($preview['existing_closing']))
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Periode ini sudah memiliki data closing (Status: <strong>{{ $preview['existing_closing']['finance_status'] }}</strong>).
            Proses closing ulang akan menimpa data sebelumnya.
        </div>
    @endif

    {{-- Revenue Cards --}}
    <div class="row mb-4">
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-muted">Jumlah Invoice</p>
                    <h5 class="font-weight-bolder mb-0">{{ number_format($preview['revenue']['total_invoice_count']) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-muted">Subscription</p>
                    <h5 class="font-weight-bolder mb-0 text-primary">Rp {{ number_format($preview['revenue']['total_subscription_revenue'], 0, ',', '.') }}</h5>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-muted">Topup Saldo</p>
                    <h5 class="font-weight-bolder mb-0 text-success">Rp {{ number_format($preview['revenue']['total_topup_revenue'], 0, ',', '.') }}</h5>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-muted">Lain-lain</p>
                    <h5 class="font-weight-bolder mb-0">Rp {{ number_format($preview['revenue']['total_other_revenue'], 0, ',', '.') }}</h5>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-warning">PPN</p>
                    <h5 class="font-weight-bolder mb-0 text-warning">Rp {{ number_format($preview['revenue']['total_ppn'], 0, ',', '.') }}</h5>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-3">
            <div class="card bg-gradient-dark">
                <div class="card-body p-3">
                    <p class="text-xs mb-0 text-uppercase font-weight-bold text-white opacity-8">GROSS Revenue</p>
                    <h5 class="font-weight-bolder mb-0 text-white">Rp {{ number_format($preview['revenue']['total_gross_revenue'], 0, ',', '.') }}</h5>
                </div>
            </div>
        </div>
    </div>

    {{-- Reconciliation --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">
                        <i class="fas fa-balance-scale me-2"></i>Hasil Rekonsiliasi
                        @if($preview['reconciliation']['status'] === 'MATCH')
                            <span class="badge bg-gradient-success badge-sm ms-2">MATCH</span>
                        @elseif($preview['reconciliation']['status'] === 'MISMATCH')
                            <span class="badge bg-gradient-danger badge-sm ms-2">MISMATCH</span>
                        @else
                            <span class="badge bg-gradient-secondary badge-sm ms-2">UNCHECKED</span>
                        @endif
                    </h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-sm text-muted" style="width: 50%;">Topup Invoice (DPP)</td>
                            <td class="text-sm font-weight-bold">Rp {{ number_format($preview['revenue']['total_topup_revenue'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Topup Wallet (completed)</td>
                            <td class="text-sm font-weight-bold">Rp {{ number_format($preview['reconciliation']['wallet_topup_total'], 0, ',', '.') }}</td>
                        </tr>
                        <tr class="{{ $preview['reconciliation']['topup_discrepancy'] > 0 ? 'table-warning' : '' }}">
                            <td class="text-sm text-muted">Selisih Topup</td>
                            <td class="text-sm font-weight-bold {{ $preview['reconciliation']['topup_discrepancy'] > 0 ? 'text-danger' : 'text-success' }}">
                                Rp {{ number_format($preview['reconciliation']['topup_discrepancy'], 0, ',', '.') }}
                            </td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Wallet Usage</td>
                            <td class="text-sm">Rp {{ number_format($preview['reconciliation']['wallet_usage_total'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Saldo Negatif?</td>
                            <td>
                                @if($preview['reconciliation']['has_negative_balance'])
                                    <span class="badge bg-gradient-danger badge-sm">YA ({{ $preview['reconciliation']['negative_wallet_count'] }} wallet)</span>
                                @else
                                    <span class="badge bg-gradient-success badge-sm">Tidak</span>
                                @endif
                            </td>
                        </tr>
                    </table>

                    @if(!empty($preview['reconciliation']['notes']))
                        <div class="alert alert-warning mt-3 mb-0 text-sm">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            {{ $preview['reconciliation']['notes'] }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Revenue Breakdown per Type --}}
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Breakdown per Tipe Invoice</h6>
                </div>
                <div class="card-body">
                    @if(!empty($preview['revenue']['breakdown']))
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
                                    @foreach($preview['revenue']['breakdown'] as $type => $data)
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
                        <p class="text-muted text-sm mb-0">Tidak ada invoice PAID pada periode ini.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Action Buttons --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <p class="text-sm mb-0">
                            @if($preview['reconciliation']['status'] === 'MATCH')
                                <i class="fas fa-check-circle text-success me-1"></i>
                                Rekonsiliasi <strong>MATCH</strong> — siap untuk closing.
                            @else
                                <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                Rekonsiliasi <strong>{{ $preview['reconciliation']['status'] }}</strong> — review data sebelum closing.
                            @endif
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="{{ route('owner.closing.index') }}" class="btn btn-sm btn-outline-dark mb-0">
                            <i class="fas fa-arrow-left me-1"></i> Kembali
                        </a>
                        @if(empty($preview['existing_closing']) || !$preview['existing_closing']['is_locked'])
                            <form action="{{ route('owner.closing.close') }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Yakin proses closing {{ $preview['period'] }}?');">
                                @csrf
                                <input type="hidden" name="year" value="{{ $preview['year'] }}">
                                <input type="hidden" name="month" value="{{ $preview['month'] }}">
                                <button type="submit" class="btn btn-sm bg-gradient-success mb-0">
                                    <i class="fas fa-lock me-1"></i> Proses Closing
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
