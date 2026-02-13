@extends('owner.layouts.app')

@section('page-title', 'Laporan Pajak (PPN)')

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
                            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                <i class="fas fa-file-invoice text-white text-lg"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h5 class="text-white mb-0 font-weight-bold">Laporan Pajak Bulanan (PPN)</h5>
                            <p class="text-white text-sm mb-0 opacity-8">
                                Generate, review & export laporan PPN per bulan — data dari Invoice (SSOT)
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Generate Form --}}
    <div class="row mb-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Generate Laporan</h6>
                    <p class="text-xs text-muted mb-0">Pilih periode lalu generate untuk menghitung PPN dari invoice PAID</p>
                </div>
                <div class="card-body">
                    <form action="{{ route('owner.tax-report.generate') }}" method="POST" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label text-xs font-weight-bold text-uppercase">Bulan</label>
                            <select name="month" class="form-select form-select-sm">
                                @php
                                    $bulanList = [
                                        1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
                                        4 => 'April', 5 => 'Mei', 6 => 'Juni',
                                        7 => 'Juli', 8 => 'Agustus', 9 => 'September',
                                        10 => 'Oktober', 11 => 'November', 12 => 'Desember',
                                    ];
                                @endphp
                                @foreach($bulanList as $num => $nama)
                                    <option value="{{ $num }}" {{ $num == now()->month ? 'selected' : '' }}>
                                        {{ $nama }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-xs font-weight-bold text-uppercase">Tahun</label>
                            <select name="year" class="form-select form-select-sm">
                                @for($y = now()->year; $y >= 2024; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-sm bg-gradient-warning w-100 mb-0">
                                <i class="fas fa-sync-alt me-1"></i> Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Info Card --}}
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-info"></i>Informasi</h6>
                </div>
                <div class="card-body">
                    <ul class="text-sm mb-0 ps-3">
                        <li class="mb-1">Data diambil <strong>hanya dari Invoice</strong> (status PAID, tax_type PPN)</li>
                        <li class="mb-1">Tarif PPN: <strong>{{ config('tax.default_tax_rate', 11) }}%</strong> (dari config)</li>
                        <li class="mb-1">Report bisa di-<strong>generate ulang</strong> selama status <span class="badge bg-gradient-info badge-sm">Draft</span></li>
                        <li class="mb-1">Report <span class="badge bg-gradient-success badge-sm">Final</span> tidak bisa diubah sampai dibuka kembali</li>
                        <li class="mb-0">Hash integritas (SHA-256) untuk verifikasi data</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter Tahun --}}
    <div class="row mb-3">
        <div class="col-12 d-flex align-items-center gap-2">
            <span class="text-sm font-weight-bold">Tahun:</span>
            @foreach($availableYears as $y)
                <a href="{{ route('owner.tax-report.index', ['year' => $y]) }}"
                   class="btn btn-sm {{ $y == $year ? 'bg-gradient-dark' : 'btn-outline-dark' }} mb-0">
                    {{ $y }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Reports Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Daftar Laporan PPN — {{ $year }}</h6>
                        <p class="text-xs text-muted mb-0">{{ $reports->count() }} laporan ditemukan</p>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    @if($reports->isEmpty())
                        <div class="text-center py-5">
                            <i class="fas fa-file-invoice text-muted mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="text-muted">Belum ada laporan PPN untuk tahun {{ $year }}.</p>
                            <p class="text-xs text-muted">Gunakan form di atas untuk generate laporan.</p>
                        </div>
                    @else
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Periode</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Invoice</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Total DPP</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Total PPN</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Total Bruto</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Generated</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($reports as $report)
                                        <tr>
                                            <td class="ps-3">
                                                <a href="{{ route('owner.tax-report.show', ['year' => $report->year, 'month' => $report->month]) }}"
                                                   class="text-sm font-weight-bold text-dark">
                                                    {{ $report->period_label }}
                                                </a>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold">{{ number_format($report->total_invoices) }}</span>
                                            </td>
                                            <td>
                                                <span class="text-sm">{{ $report->formatted_dpp }}</span>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold text-warning">{{ $report->formatted_ppn }}</span>
                                            </td>
                                            <td>
                                                <span class="text-sm">{{ $report->formatted_total }}</span>
                                            </td>
                                            <td>
                                                @if($report->status === 'final')
                                                    <span class="badge bg-gradient-success badge-sm">Final</span>
                                                @else
                                                    <span class="badge bg-gradient-info badge-sm">Draft</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="text-xs text-muted">
                                                    {{ $report->generated_at ? $report->generated_at->format('d/m/Y H:i') : '-' }}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <a href="{{ route('owner.tax-report.show', ['year' => $report->year, 'month' => $report->month]) }}"
                                                       class="btn btn-sm btn-outline-dark mb-0" title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="{{ route('owner.tax-report.pdf', ['year' => $report->year, 'month' => $report->month]) }}"
                                                       class="btn btn-sm btn-outline-danger mb-0" title="Download PDF">
                                                        <i class="fas fa-file-pdf"></i>
                                                    </a>
                                                    <a href="{{ route('owner.tax-report.csv', ['year' => $report->year, 'month' => $report->month]) }}"
                                                       class="btn btn-sm btn-outline-success mb-0" title="Export CSV">
                                                        <i class="fas fa-file-csv"></i>
                                                    </a>
                                                </div>
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
@endsection
