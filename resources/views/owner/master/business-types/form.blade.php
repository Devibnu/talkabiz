@extends('owner.layouts.app')

@section('page-title', $isEdit ? 'Edit Tipe Bisnis' : 'Tambah Tipe Bisnis')

@section('content')
{{-- Breadcrumb --}}
<nav aria-label="breadcrumb">
    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
        <li class="breadcrumb-item text-sm">
            <a class="opacity-5 text-dark" href="{{ route('owner.master.business-types.index') }}">
                Master Data
            </a>
        </li>
        <li class="breadcrumb-item text-sm text-dark active" aria-current="page">
            {{ $isEdit ? 'Edit' : 'Tambah' }} Tipe Bisnis
        </li>
    </ol>
</nav>

<div class="row mt-4">
    <div class="col-lg-8 col-md-10">
        <div class="card">
            <div class="card-header pb-0">
                <div class="d-flex align-items-center">
                    <h6 class="mb-0">{{ $isEdit ? 'Edit' : 'Tambah' }} Tipe Bisnis</h6>
                    <a href="{{ route('owner.master.business-types.index') }}" 
                       class="btn btn-outline-secondary btn-sm ms-auto">
                        <i class="fas fa-arrow-left me-2"></i>Kembali
                    </a>
                </div>
            </div>
            <div class="card-body">
                {{-- Alert Messages --}}
                @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                @endif

                @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Terdapat kesalahan:</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                @endif

                {{-- Form --}}
                <form method="POST" 
                      action="{{ $isEdit ? route('owner.master.business-types.update', $businessType) : route('owner.master.business-types.store') }}">
                    @csrf
                    @if($isEdit)
                        @method('PUT')
                    @endif

                    {{-- Code Field --}}
                    <div class="mb-3">
                        <label for="code" class="form-label">
                            Kode <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control @error('code') is-invalid @enderror" 
                               id="code" 
                               name="code" 
                               value="{{ old('code', $businessType->code) }}"
                               placeholder="Contoh: perorangan, cv, pt, lainnya"
                               {{ $isEdit ? 'readonly' : 'required' }}>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Format: lowercase_snake_case (huruf kecil, gunakan underscore untuk spasi).
                            @if($isEdit)
                                <strong class="text-warning">Kode tidak dapat diubah.</strong>
                            @endif
                        </small>
                        @error('code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Name Field --}}
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Nama Tipe Bisnis <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control @error('name') is-invalid @enderror" 
                               id="name" 
                               name="name" 
                               value="{{ old('name', $businessType->name) }}"
                               placeholder="Contoh: Perorangan, CV (Commanditaire Vennootschap), PT (Perseroan Terbatas)"
                               required>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Nama lengkap tipe bisnis yang akan ditampilkan.
                        </small>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Description Field --}}
                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Deskripsi <span class="text-muted">(Opsional)</span>
                        </label>
                        <textarea class="form-control @error('description') is-invalid @enderror" 
                                  id="description" 
                                  name="description" 
                                  rows="3"
                                  placeholder="Deskripsi singkat tentang tipe bisnis ini...">{{ old('description', $businessType->description) }}</textarea>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Deskripsi tambahan untuk membantu memahami tipe bisnis ini.
                        </small>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Display Order Field --}}
                    <div class="mb-3">
                        <label for="display_order" class="form-label">
                            Urutan Tampilan <span class="text-danger">*</span>
                        </label>
                        <input type="number" 
                               class="form-control @error('display_order') is-invalid @enderror" 
                               id="display_order" 
                               name="display_order" 
                               value="{{ old('display_order', $businessType->display_order ?? 0) }}"
                               min="0"
                               required>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Menentukan urutan tampilan di dropdown. Angka lebih kecil akan ditampilkan lebih dulu.
                        </small>
                        @error('display_order')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Is Active Field --}}
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input @error('is_active') is-invalid @enderror" 
                                   type="checkbox" 
                                   id="is_active" 
                                   name="is_active" 
                                   value="1"
                                   {{ old('is_active', $businessType->is_active ?? true) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_active">
                                Status Aktif
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Hanya tipe bisnis aktif yang dapat dipilih saat membuat klien baru.
                            @if($isEdit && !$businessType->canBeDeactivated())
                                <strong class="text-danger d-block mt-1">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Tipe bisnis ini tidak dapat dinonaktifkan karena masih digunakan oleh klien aktif.
                                </strong>
                            @endif
                        </small>
                        @error('is_active')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Action Buttons --}}
                    <div class="d-flex justify-content-between mt-4">
                        <a href="{{ route('owner.master.business-types.index') }}" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-times me-2"></i>Batal
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>
                            {{ $isEdit ? 'Update' : 'Simpan' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Help Card --}}
        <div class="card mt-4 bg-gradient-info">
            <div class="card-body">
                <h6 class="text-white mb-3">
                    <i class="fas fa-question-circle me-2"></i>Panduan Pengisian
                </h6>
                <ul class="text-white text-sm mb-0">
                    <li class="mb-2">
                        <strong>Kode:</strong> Harus unik dan dalam format lowercase_snake_case
                        <br><small class="opacity-8">Contoh yang benar: perorangan, cv, pt, ud, lainnya</small>
                    </li>
                    <li class="mb-2">
                        <strong>Nama:</strong> Nama lengkap tipe bisnis yang mudah dipahami
                        <br><small class="opacity-8">Contoh: "PT (Perseroan Terbatas)"</small>
                    </li>
                    <li class="mb-2">
                        <strong>Urutan Tampilan:</strong> Digunakan untuk mengurutkan dropdown
                        <br><small class="opacity-8">Tipe bisnis umum sebaiknya diberi urutan lebih kecil</small>
                    </li>
                    <li>
                        <strong>Status:</strong> Hanya tipe bisnis aktif yang dapat dipilih
                        <br><small class="opacity-8">Nonaktifkan tipe bisnis yang sudah tidak digunakan</small>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Example Card --}}
    <div class="col-lg-4 col-md-6 mt-4 mt-lg-0">
        <div class="card">
            <div class="card-header pb-0">
                <h6><i class="fas fa-lightbulb me-2"></i>Contoh Data</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="p-3 bg-gray-100 border-radius-lg">
                        <p class="text-xs text-secondary mb-1">Kode:</p>
                        <p class="text-sm font-weight-bold mb-0">perorangan</p>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="p-3 bg-gray-100 border-radius-lg">
                        <p class="text-xs text-secondary mb-1">Nama:</p>
                        <p class="text-sm font-weight-bold mb-0">Perorangan / Individu</p>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="p-3 bg-gray-100 border-radius-lg">
                        <p class="text-xs text-secondary mb-1">Deskripsi:</p>
                        <p class="text-sm mb-0">Usaha yang dimiliki dan dijalankan oleh individu</p>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="p-3 bg-gray-100 border-radius-lg">
                        <p class="text-xs text-secondary mb-1">Urutan:</p>
                        <p class="text-sm font-weight-bold mb-0">1</p>
                    </div>
                </div>

                <hr class="horizontal dark my-3">

                <h6 class="text-sm mb-3">Contoh Tipe Bisnis Umum:</h6>
                <ul class="text-xs mb-0">
                    <li>perorangan - Perorangan / Individu</li>
                    <li>cv - CV (Commanditaire Vennootschap)</li>
                    <li>pt - PT (Perseroan Terbatas)</li>
                    <li>ud - UD (Usaha Dagang)</li>
                    <li>lainnya - Lainnya</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Auto-convert code input to lowercase_snake_case
    document.getElementById('code').addEventListener('input', function(e) {
        // Only apply if not readonly (i.e., on create form)
        if (!this.hasAttribute('readonly')) {
            let value = e.target.value;
            // Convert to lowercase and replace spaces with underscores
            value = value.toLowerCase().replace(/\s+/g, '_');
            // Remove invalid characters (keep only a-z, 0-9, and underscore)
            value = value.replace(/[^a-z0-9_]/g, '');
            e.target.value = value;
        }
    });

    // Prevent deactivation if not allowed
    @if($isEdit && $businessType->exists && !$businessType->canBeDeactivated())
    document.getElementById('is_active').addEventListener('change', function(e) {
        if (!e.target.checked) {
            e.preventDefault();
            e.target.checked = true;
            alert('Tipe bisnis ini tidak dapat dinonaktifkan karena masih digunakan oleh {{ $businessType->kliens_count }} klien aktif.');
        }
    });
    @endif
</script>
@endpush
