@extends('owner.layouts.app')

@section('page-title', 'Master Data - Tipe Bisnis')

@section('content')
{{-- Header & Stats --}}
<div class="row mb-4">
    <div class="col-lg-8 col-md-6">
        <h4 class="font-weight-bolder mb-0">Master Data Tipe Bisnis</h4>
        <p class="mb-0 text-sm">Kelola tipe bisnis untuk kategorisasi klien</p>
    </div>
    <div class="col-lg-4 col-md-6 text-end">
        <a href="{{ route('owner.master.business-types.create') }}" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Tambah Tipe Bisnis
        </a>
    </div>
</div>

{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Tipe Bisnis</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($businessTypes->count()) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-building text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Aktif</p>
                        <h5 class="font-weight-bolder mb-0 text-success">
                            {{ number_format($businessTypes->where('is_active', true)->count()) }}
                        </h5>
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
    <div class="col-xl-4 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Klien</p>
                        <h5 class="font-weight-bolder mb-0 text-info">
                            {{ number_format($businessTypes->sum('kliens_count')) }}
                        </h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-users text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Alert Messages --}}
@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
@endif

{{-- Business Types Table --}}
<div class="card">
    <div class="card-header pb-0">
        <div class="d-flex justify-content-between">
            <div>
                <h6>Daftar Tipe Bisnis</h6>
                <p class="text-sm mb-0">
                    <i class="fas fa-info-circle text-info me-1"></i>
                    <strong>Safety:</strong> Tipe bisnis tidak dapat dihapus, hanya dinonaktifkan.
                </p>
            </div>
        </div>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">
                            Kode
                        </th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">
                            Nama Tipe Bisnis
                        </th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">
                            Jumlah Klien
                        </th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">
                            Status
                        </th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">
                            Urutan
                        </th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">
                            Aksi
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($businessTypes as $type)
                    <tr>
                        <td>
                            <div class="d-flex px-3 py-1">
                                <div class="d-flex flex-column justify-content-center">
                                    <h6 class="mb-0 text-sm font-weight-bold">{{ $type->code }}</h6>
                                </div>
                            </div>
                        </td>
                        <td>
                            <p class="text-sm font-weight-bold mb-0">{{ $type->name }}</p>
                            @if($type->description)
                                <p class="text-xs text-secondary mb-0">{{ $type->description }}</p>
                            @endif
                        </td>
                        <td class="align-middle text-center">
                            <span class="badge badge-sm bg-gradient-info">
                                {{ number_format($type->kliens_count) }} klien
                            </span>
                        </td>
                        <td class="align-middle text-center text-sm">
                            @if($type->is_active)
                                <span class="badge badge-sm bg-gradient-success">
                                    <i class="fas fa-check me-1"></i>Aktif
                                </span>
                            @else
                                <span class="badge badge-sm bg-gradient-secondary">
                                    <i class="fas fa-times me-1"></i>Nonaktif
                                </span>
                            @endif
                        </td>
                        <td class="align-middle text-center">
                            <span class="text-secondary text-xs font-weight-bold">
                                {{ $type->display_order }}
                            </span>
                        </td>
                        <td class="align-middle text-center">
                            <div class="btn-group" role="group">
                                <a href="{{ route('owner.master.business-types.edit', $type) }}" 
                                   class="btn btn-sm btn-outline-primary mb-0"
                                   data-bs-toggle="tooltip" 
                                   title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                
                                <form action="{{ route('owner.master.business-types.toggle-active', $type) }}" 
                                      method="POST" 
                                      class="d-inline"
                                      onsubmit="return confirm('{{ $type->is_active ? 'Nonaktifkan' : 'Aktifkan' }} tipe bisnis \'{{ $type->name }}\'?')">
                                    @csrf
                                    <button type="submit" 
                                            class="btn btn-sm btn-outline-{{ $type->is_active ? 'warning' : 'success' }} mb-0"
                                            data-bs-toggle="tooltip" 
                                            title="{{ $type->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                            @if($type->is_active && !$type->canBeDeactivated())
                                                disabled
                                            @endif>
                                        <i class="fas fa-{{ $type->is_active ? 'times-circle' : 'check-circle' }}"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <div class="text-center">
                                <i class="fas fa-building fa-3x text-secondary mb-3"></i>
                                <p class="text-sm text-secondary mb-0">Belum ada tipe bisnis.</p>
                                <a href="{{ route('owner.master.business-types.create') }}" class="btn btn-primary btn-sm mt-3">
                                    <i class="fas fa-plus me-2"></i>Tambah Tipe Bisnis
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Architecture Notes --}}
<div class="row mt-4">
    <div class="col-12">
        <div class="card bg-gradient-secondary">
            <div class="card-body">
                <h6 class="text-white mb-3">
                    <i class="fas fa-info-circle me-2"></i>Catatan Arsitektur
                </h6>
                <ul class="text-white text-sm mb-0">
                    <li><strong>NO Hard Delete:</strong> Tipe bisnis tidak dapat dihapus permanen, hanya dinonaktifkan</li>
                    <li><strong>Code Format:</strong> Kode harus lowercase_snake_case untuk kompatibilitas dengan klien ENUM</li>
                    <li><strong>Deactivation Check:</strong> Tipe bisnis tidak dapat dinonaktifkan jika masih digunakan oleh klien aktif</li>
                    <li><strong>Display Order:</strong> Menentukan urutan tampilan di form dropdown</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
</script>
@endpush
