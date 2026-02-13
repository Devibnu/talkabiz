@extends('owner.layouts.app')

@section('page-title', 'Integrity Check — Audit Trail')

@section('content')
{{-- Breadcrumb --}}
<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item">
                    <a href="{{ route('owner.audit-trail.index') }}" class="text-primary">
                        <i class="fas fa-shield-alt me-1"></i> Audit Trail
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">Integrity Check</li>
            </ol>
        </nav>
    </div>
</div>

{{-- Summary Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Diperiksa</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($total) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                            <i class="fas fa-search text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Valid</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($valid) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-check-circle text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Tampered</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($invalid) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-exclamation-triangle text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Integritas</p>
                        <h5 class="font-weight-bolder mb-0 {{ $invalid > 0 ? 'text-danger' : 'text-success' }}">
                            {{ $total > 0 ? number_format(($valid / $total) * 100, 1) : 0 }}%
                        </h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape {{ $invalid > 0 ? 'bg-gradient-danger' : 'bg-gradient-success' }} shadow text-center border-radius-md">
                            <i class="fas fa-{{ $invalid > 0 ? 'times' : 'shield-alt' }} text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Status Banner --}}
<div class="row mb-4">
    <div class="col-12">
        @if($invalid === 0)
            <div class="alert text-white font-weight-bold d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%); border: none;">
                <i class="fas fa-check-circle me-3 text-lg"></i>
                <div>
                    <strong>SEMUA VALID</strong> — {{ $valid }} dari {{ $total }} log terverifikasi. 
                    Tidak ada manipulasi terdeteksi. Checksum SHA-256 cocok untuk semua record.
                </div>
            </div>
        @else
            <div class="alert text-white font-weight-bold d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #ea0606 0%, #ff667c 100%); border: none;">
                <i class="fas fa-exclamation-triangle me-3 text-lg"></i>
                <div>
                    <strong>MANIPULASI TERDETEKSI!</strong> — {{ $invalid }} dari {{ $total }} log memiliki checksum yang TIDAK COCOK. 
                    Data mungkin telah diubah di luar sistem. Periksa dan investigasi segera.
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Re-check Form --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-3">
                <form method="GET" action="{{ route('owner.audit-trail.integrity') }}" class="d-flex align-items-center gap-3">
                    <label class="form-label mb-0 text-sm font-weight-bold text-nowrap">Jumlah log diperiksa:</label>
                    <select name="limit" class="form-select" style="max-width: 150px;">
                        <option value="50" {{ $limit == 50 ? 'selected' : '' }}>50</option>
                        <option value="100" {{ $limit == 100 ? 'selected' : '' }}>100</option>
                        <option value="200" {{ $limit == 200 ? 'selected' : '' }}>200</option>
                        <option value="500" {{ $limit == 500 ? 'selected' : '' }}>500</option>
                    </select>
                    <button type="submit" class="btn bg-gradient-warning mb-0">
                        <i class="fas fa-sync-alt me-1"></i> Periksa Ulang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Tampered Logs (if any) --}}
@if($invalid > 0)
<div class="row mb-4">
    <div class="col-12">
        <div class="card border border-danger">
            <div class="card-header pb-0" style="background: rgba(234, 6, 6, 0.05);">
                <h6 class="mb-0 text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Log Terdeteksi Tampered ({{ $invalid }})
                </h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">UUID</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Entity</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tamperedLogs as $tampered)
                                <tr style="background: rgba(234, 6, 6, 0.03);">
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold text-danger">#{{ $tampered['id'] }}</span>
                                    </td>
                                    <td>
                                        <code class="text-xs">{{ Str::limit($tampered['log_uuid'] ?? '-', 12) }}</code>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $tampered['occurred_at'] ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ $tampered['action'] ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $tampered['entity'] ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-danger">
                                            <i class="fas fa-times me-1"></i> TAMPERED
                                        </span>
                                    </td>
                                    <td>
                                        <a href="{{ route('owner.audit-trail.show', $tampered['id']) }}" 
                                           class="btn btn-sm btn-outline-danger mb-0 px-2">
                                            <i class="fas fa-eye"></i> Investigasi
                                        </a>
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

{{-- All Results --}}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-list-check me-2"></i>Semua Hasil Verifikasi
                    <span class="badge bg-gradient-dark ms-2">{{ $total }} records</span>
                </h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0" style="max-height: 500px; overflow-y: auto;">
                    <table class="table align-items-center mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">ID</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Entity</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Checksum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($results as $result)
                                <tr style="{{ !$result['valid'] ? 'background: rgba(234, 6, 6, 0.05);' : '' }}">
                                    <td class="ps-3">
                                        <a href="{{ route('owner.audit-trail.show', $result['id']) }}" class="text-xs font-weight-bold text-primary">
                                            #{{ $result['id'] }}
                                        </a>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $result['occurred_at'] ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ Str::limit($result['action'] ?? '-', 25) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $result['entity'] ?? '-' }}</span>
                                    </td>
                                    <td>
                                        @if($result['valid'])
                                            <span class="badge badge-sm bg-gradient-success">
                                                <i class="fas fa-check me-1"></i> VALID
                                            </span>
                                        @else
                                            <span class="badge badge-sm bg-gradient-danger">
                                                <i class="fas fa-times me-1"></i> TAMPERED
                                            </span>
                                        @endif
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

{{-- Back --}}
<div class="row mt-4">
    <div class="col-12">
        <a href="{{ route('owner.audit-trail.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Kembali ke Audit Trail
        </a>
    </div>
</div>
@endsection
