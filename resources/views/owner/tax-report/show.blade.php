@extends('owner.layouts.app')

@section('page-title', 'Detail Laporan PPN — ' . $report->period_label)

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
                <a href="{{ route('owner.tax-report.index', ['year' => $report->year]) }}" class="text-sm text-dark">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar
                </a>
                <h5 class="mt-2 mb-0 font-weight-bold">
                    <i class="fas fa-file-invoice text-warning me-2"></i>
                    Laporan PPN — {{ $report->period_label }}
                </h5>
            </div>
            <div class="d-flex gap-2">
                {{-- PDF --}}
                <a href="{{ route('owner.tax-report.pdf', ['year' => $report->year, 'month' => $report->month]) }}"
                   class="btn btn-sm bg-gradient-danger mb-0">
                    <i class="fas fa-file-pdf me-1"></i> Download PDF
                </a>
                {{-- CSV --}}
                <a href="{{ route('owner.tax-report.csv', ['year' => $report->year, 'month' => $report->month]) }}"
                   class="btn btn-sm bg-gradient-success mb-0">
                    <i class="fas fa-file-csv me-1"></i> Export CSV
                </a>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Total Invoice</p>
                                <h5 class="font-weight-bolder mb-0">{{ number_format($report->total_invoices) }}</h5>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Total DPP</p>
                                <h5 class="font-weight-bolder mb-0">{{ $report->formatted_dpp }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                <i class="fas fa-calculator text-white text-lg opacity-10"></i>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Total PPN ({{ $report->tax_rate }}%)</p>
                                <h5 class="font-weight-bolder mb-0 text-warning">{{ $report->formatted_ppn }}</h5>
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
                                <p class="text-sm mb-0 text-uppercase font-weight-bold text-muted">Total Bruto</p>
                                <h5 class="font-weight-bolder mb-0">{{ $report->formatted_total }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                                <i class="fas fa-money-bill-wave text-white text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Report Meta + Actions --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Info Laporan</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-sm text-muted" style="width: 40%;">Status</td>
                            <td>
                                @if($report->status === 'final')
                                    <span class="badge bg-gradient-success">Final (Locked)</span>
                                @else
                                    <span class="badge bg-gradient-info">Draft (Editable)</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Tarif PPN</td>
                            <td class="text-sm font-weight-bold">{{ $report->tax_rate }}%</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Generated</td>
                            <td class="text-sm">{{ $report->generated_at ? $report->generated_at->format('d/m/Y H:i:s') : '-' }}</td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Generated By</td>
                            <td class="text-sm">{{ $report->generatedBy?->name ?? '-' }}</td>
                        </tr>
                        @if($report->finalized_at)
                        <tr>
                            <td class="text-sm text-muted">Finalized</td>
                            <td class="text-sm">{{ $report->finalized_at->format('d/m/Y H:i:s') }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="text-sm text-muted">Integritas Data</td>
                            <td>
                                @if($integrityOk)
                                    <span class="badge bg-gradient-success"><i class="fas fa-check me-1"></i>Valid</span>
                                @else
                                    <span class="badge bg-gradient-danger"><i class="fas fa-exclamation-triangle me-1"></i>Mismatch</span>
                                    <span class="text-xs text-danger ms-1">Data invoice berubah sejak generate terakhir</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-sm text-muted">Report Hash</td>
                            <td><code class="text-xs">{{ Str::limit($report->report_hash, 20) }}</code></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-cogs me-2"></i>Aksi</h6>
                </div>
                <div class="card-body d-flex flex-column gap-3">
                    {{-- Re-generate --}}
                    @if($report->is_editable)
                        <form action="{{ route('owner.tax-report.generate') }}" method="POST">
                            @csrf
                            <input type="hidden" name="year" value="{{ $report->year }}">
                            <input type="hidden" name="month" value="{{ $report->month }}">
                            <button type="submit" class="btn btn-sm bg-gradient-warning w-100 mb-0"
                                    onclick="return confirm('Re-generate akan menghitung ulang semua data dari invoice. Lanjutkan?')">
                                <i class="fas fa-sync-alt me-1"></i> Re-Generate Data
                            </button>
                        </form>
                        <p class="text-xs text-muted mb-0">Hitung ulang data dari invoice PAID + PPN periode ini.</p>
                    @endif

                    {{-- Finalize / Reopen --}}
                    @if($report->status === 'draft')
                        <form action="{{ route('owner.tax-report.finalize', ['year' => $report->year, 'month' => $report->month]) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-sm bg-gradient-success w-100 mb-0"
                                    onclick="return confirm('Finalisasi akan mengunci laporan. Tidak bisa di-generate ulang. Lanjutkan?')">
                                <i class="fas fa-lock me-1"></i> Finalisasi Laporan
                            </button>
                        </form>
                        <p class="text-xs text-muted mb-0">Kunci laporan agar tidak bisa diubah (siap audit).</p>
                    @else
                        <form action="{{ route('owner.tax-report.reopen', ['year' => $report->year, 'month' => $report->month]) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-warning w-100 mb-0"
                                    onclick="return confirm('Buka kembali laporan? Status akan berubah ke Draft.')">
                                <i class="fas fa-unlock me-1"></i> Buka Kembali (Reopen)
                            </button>
                        </form>
                        <p class="text-xs text-muted mb-0">Ubah status ke Draft agar bisa di-generate ulang.</p>
                    @endif

                    {{-- Breakdown by Type --}}
                    @if(!empty($report->metadata['breakdown_by_type']))
                        <hr class="horizontal dark my-2">
                        <h6 class="text-xs font-weight-bold text-uppercase text-muted mb-2">Breakdown per Tipe</h6>
                        @foreach($report->metadata['breakdown_by_type'] as $type => $data)
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-sm">{{ ucfirst(str_replace('_', ' ', $type)) }}</span>
                                <div class="text-end">
                                    <span class="text-xs text-muted">{{ $data['count'] ?? 0 }} inv</span>
                                    <span class="text-sm font-weight-bold ms-2">
                                        Rp {{ number_format($data['ppn'] ?? 0, 0, ',', '.') }}
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Invoice Detail Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Detail Invoice</h6>
                        <p class="text-xs text-muted mb-0">{{ $invoices->count() }} invoice PAID + PPN pada periode ini</p>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    @if($invoices->isEmpty())
                        <div class="text-center py-5">
                            <i class="fas fa-inbox text-muted mb-3" style="font-size: 2rem; opacity: 0.3;"></i>
                            <p class="text-muted text-sm">Tidak ada invoice PPN pada periode ini.</p>
                        </div>
                    @else
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">No</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">No Invoice</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tipe</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tgl Bayar</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">DPP (Rp)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">PPN %</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">PPN (Rp)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-end">Total (Rp)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">NPWP Pembeli</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invoices as $i => $inv)
                                        <tr>
                                            <td class="ps-3"><span class="text-xs">{{ $i + 1 }}</span></td>
                                            <td>
                                                <span class="text-xs font-weight-bold">
                                                    {{ $inv->formatted_invoice_number ?: $inv->invoice_number }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-gradient-{{ $inv->type === 'topup' ? 'info' : 'primary' }} badge-sm">
                                                    {{ ucfirst(str_replace('_', ' ', $inv->type)) }}
                                                </span>
                                            </td>
                                            <td><span class="text-xs">{{ $inv->paid_at ? $inv->paid_at->format('d/m/Y') : '-' }}</span></td>
                                            <td class="text-end"><span class="text-xs">{{ number_format((float) $inv->subtotal, 0, ',', '.') }}</span></td>
                                            <td class="text-center"><span class="text-xs">{{ $inv->tax_rate }}%</span></td>
                                            <td class="text-end"><span class="text-xs font-weight-bold text-warning">{{ number_format((float) $inv->tax_amount, 0, ',', '.') }}</span></td>
                                            <td class="text-end"><span class="text-xs font-weight-bold">{{ number_format((float) $inv->total, 0, ',', '.') }}</span></td>
                                            <td><span class="text-xs text-muted">{{ $inv->buyer_npwp ?: '-' }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="bg-light">
                                        <td colspan="4" class="ps-3 text-sm font-weight-bold">TOTAL</td>
                                        <td class="text-end text-sm font-weight-bold">{{ number_format((float) $report->total_dpp, 0, ',', '.') }}</td>
                                        <td></td>
                                        <td class="text-end text-sm font-weight-bold text-warning">{{ number_format((float) $report->total_ppn, 0, ',', '.') }}</td>
                                        <td class="text-end text-sm font-weight-bold">{{ number_format((float) $report->total_amount, 0, ',', '.') }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
