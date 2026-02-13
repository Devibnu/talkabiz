@extends('owner.layouts.app')

@section('page-title', 'Monthly Closing & Rekonsiliasi')

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
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                <i class="fas fa-calendar-check text-white text-lg"></i>
                            </div>
                        </div>
                        <div class="col">
                            <h5 class="text-white mb-0 font-weight-bold">Monthly Closing & Rekonsiliasi Keuangan</h5>
                            <p class="text-white text-sm mb-0 opacity-8">
                                Proses closing bulanan — Invoice = SSOT pendapatan, Wallet = cross-check saja
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        {{-- Generate / Close Form --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Proses Closing Bulan</h6>
                    <p class="text-xs text-muted mb-0">Pilih periode, preview dulu lalu proses closing</p>
                </div>
                <div class="card-body">
                    {{-- Preview Form --}}
                    <form action="{{ route('owner.closing.preview') }}" method="POST" class="row g-3 align-items-end mb-3">
                        @csrf
                        <div class="col-5">
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
                        <div class="col-3">
                            <label class="form-label text-xs font-weight-bold text-uppercase">Tahun</label>
                            <select name="year" class="form-select form-select-sm">
                                @for($y = now()->year; $y >= 2024; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-sm bg-gradient-info w-100 mb-0">
                                <i class="fas fa-eye me-1"></i> Preview
                            </button>
                        </div>
                    </form>

                    <hr class="horizontal dark my-2">

                    {{-- Close Form --}}
                    <form action="{{ route('owner.closing.close') }}" method="POST" class="row g-3 align-items-end"
                          onsubmit="return confirm('Yakin proses closing bulan ini? Data revenue & rekonsiliasi akan dihitung dan disimpan.');">
                        @csrf
                        <div class="col-5">
                            <select name="month" class="form-select form-select-sm">
                                @foreach($bulanList as $num => $nama)
                                    <option value="{{ $num }}" {{ $num == now()->month ? 'selected' : '' }}>
                                        {{ $nama }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-3">
                            <select name="year" class="form-select form-select-sm">
                                @for($y = now()->year; $y >= 2024; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-sm bg-gradient-success w-100 mb-0">
                                <i class="fas fa-lock me-1"></i> Close
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        {{-- Info Card --}}
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2 text-info"></i>Alur Proses</h6>
                </div>
                <div class="card-body">
                    <ul class="text-sm mb-0 ps-3">
                        <li class="mb-2"><strong>Preview</strong> — lihat data tanpa simpan</li>
                        <li class="mb-2"><strong>Close</strong> — hitung, simpan, rekonsiliasi (DRAFT)</li>
                        <li class="mb-2"><strong>Finalize</strong> — kunci permanen (CLOSED)</li>
                        <li class="mb-0"><strong>Reopen</strong> — buka ulang (owner override)</li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Status Legend --}}
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0"><i class="fas fa-tags me-2 text-warning"></i>Keterangan Status</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <span class="badge bg-gradient-info badge-sm">DRAFT</span>
                        <span class="text-xs ms-1">Bisa di-regenerate / edit</span>
                    </div>
                    <div class="mb-2">
                        <span class="badge bg-gradient-success badge-sm">CLOSED</span>
                        <span class="text-xs ms-1">Final, terkunci permanen</span>
                    </div>
                    <div class="mb-2">
                        <span class="badge bg-gradient-danger badge-sm">FAILED</span>
                        <span class="text-xs ms-1">Rekonsiliasi gagal (mismatch)</span>
                    </div>
                    <hr class="horizontal dark my-2">
                    <div class="mb-2">
                        <span class="badge bg-gradient-success badge-sm">MATCH</span>
                        <span class="text-xs ms-1">Invoice = Wallet (cross-check OK)</span>
                    </div>
                    <div class="mb-0">
                        <span class="badge bg-gradient-danger badge-sm">MISMATCH</span>
                        <span class="text-xs ms-1">Ada selisih / anomali</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter Tahun --}}
    <div class="row mb-3">
        <div class="col-12 d-flex align-items-center gap-2">
            <span class="text-sm font-weight-bold">Tahun:</span>
            @foreach($availableYears as $y)
                <a href="{{ route('owner.closing.index', ['year' => $y]) }}"
                   class="btn btn-sm {{ $y == $year ? 'bg-gradient-dark' : 'btn-outline-dark' }} mb-0">
                    {{ $y }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Closings Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Daftar Monthly Closing — {{ $year }}</h6>
                        <p class="text-xs text-muted mb-0">{{ $closings->count() }} closing ditemukan</p>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    @if($closings->isEmpty())
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times text-muted mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="text-muted">Belum ada closing untuk tahun {{ $year }}.</p>
                            <p class="text-xs text-muted">Gunakan form di atas untuk memulai closing bulan.</p>
                        </div>
                    @else
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-3">Periode</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Invoice</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Revenue (DPP)</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">PPN</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Rekon</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Closed</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($closings as $c)
                                        @if($c->finance_status)
                                        <tr>
                                            <td class="ps-3">
                                                <a href="{{ route('owner.closing.show', ['year' => $c->year, 'month' => $c->month]) }}"
                                                   class="text-sm font-weight-bold text-dark">
                                                    {{ $c->period_label }}
                                                </a>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold">{{ number_format($c->invoice_count ?? 0) }}</span>
                                            </td>
                                            <td>
                                                <span class="text-sm">{{ $c->formatted_invoice_gross ?? '-' }}</span>
                                            </td>
                                            <td>
                                                <span class="text-sm font-weight-bold text-warning">{{ $c->formatted_invoice_ppn ?? '-' }}</span>
                                            </td>
                                            <td>
                                                {!! $c->recon_badge !!}
                                            </td>
                                            <td>
                                                {!! $c->finance_status_badge !!}
                                            </td>
                                            <td>
                                                <span class="text-xs text-muted">
                                                    {{ $c->finance_closed_at ? $c->finance_closed_at->format('d/m/Y H:i') : '-' }}
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group">
                                                    <a href="{{ route('owner.closing.show', ['year' => $c->year, 'month' => $c->month]) }}"
                                                       class="btn btn-sm btn-outline-dark mb-0" title="Detail">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    @if($c->finance_status !== 'FAILED')
                                                    <a href="{{ route('owner.closing.pdf', ['year' => $c->year, 'month' => $c->month]) }}"
                                                       class="btn btn-sm btn-outline-danger mb-0" title="Download PDF">
                                                        <i class="fas fa-file-pdf"></i>
                                                    </a>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                        @endif
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
