@extends('owner.layouts.app')

@section('page-title', 'Tambah Paket Baru')

@section('content')
<div class="row">
    <div class="col-lg-8 mx-auto">
        {{-- Breadcrumb --}}
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.dashboard') }}">Dashboard</a>
                </li>
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.plans.index') }}">Paket</a>
                </li>
                <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Tambah Baru</li>
            </ol>
        </nav>

        {{-- Alerts --}}
        @if($errors->any())
            <div class="alert alert-danger text-white mb-4" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Ada kesalahan:</strong>
                <ul class="mb-0 mt-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('owner.plans.store') }}" method="POST" id="planForm">
            @csrf

            {{-- ============================================ --}}
            {{-- SECTION 1 — Informasi Paket                  --}}
            {{-- ============================================ --}}
            <div class="card mb-4">
                <div class="card-header bg-gradient-primary py-3">
                    <h6 class="mb-0 text-white">
                        <i class="fas fa-box me-2"></i>
                        1. Informasi Paket
                    </h6>
                </div>
                <div class="card-body">
                    {{-- Code --}}
                    <div class="mb-3">
                        <label for="code" class="form-label">
                            Kode Paket <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control @error('code') is-invalid @enderror"
                               id="code" name="code"
                               value="{{ old('code') }}"
                               placeholder="contoh: umkm-starter"
                               pattern="[a-z0-9\-]+"
                               oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9\-]/g, '')"
                               required>
                        <div class="form-text">Slug unik. Huruf kecil, angka, dan tanda hubung.</div>
                        @error('code')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Name --}}
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Nama Paket <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name"
                               value="{{ old('name') }}"
                               placeholder="contoh: Paket Starter"
                               required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Description --}}
                    <div class="mb-0">
                        <label for="description" class="form-label">Deskripsi</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="3"
                                  placeholder="Deskripsi singkat tentang paket ini...">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            {{-- ============================================ --}}
            {{-- SECTION 2 — Harga & Status                   --}}
            {{-- ============================================ --}}
            <div class="card mb-4">
                <div class="card-header bg-gradient-info py-3">
                    <h6 class="mb-0 text-white">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        2. Harga & Status
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            {{-- Price Monthly --}}
                            <div class="mb-3">
                                <label for="price_monthly" class="form-label">
                                    Harga per Bulan <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number"
                                           class="form-control @error('price_monthly') is-invalid @enderror"
                                           id="price_monthly" name="price_monthly"
                                           value="{{ old('price_monthly', 149000) }}"
                                           min="0" step="1000" required>
                                </div>
                                <div class="form-text">0 = paket gratis.</div>
                                @error('price_monthly')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            {{-- Duration --}}
                            <div class="mb-3">
                                <label for="duration_days" class="form-label">
                                    Durasi <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number"
                                           class="form-control @error('duration_days') is-invalid @enderror"
                                           id="duration_days" name="duration_days"
                                           value="{{ old('duration_days', 30) }}"
                                           min="1" max="365" required>
                                    <span class="input-group-text">hari</span>
                                </div>
                                <div class="form-text">30 = bulanan, 365 = tahunan.</div>
                                @error('duration_days')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <hr class="horizontal dark my-3">

                    <div class="row">
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    <strong>Active</strong>
                                    <div class="text-xs text-muted">Paket dapat digunakan</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_self_serve" name="is_self_serve" value="1" {{ old('is_self_serve', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_self_serve">
                                    <strong>Self Serve</strong>
                                    <div class="text-xs text-muted">User bisa beli langsung</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_visible" name="is_visible" value="1" {{ old('is_visible', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_visible">
                                    <strong>Visible</strong>
                                    <div class="text-xs text-muted">Tampil di landing page</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_popular" name="is_popular" value="1" {{ old('is_popular') ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_popular">
                                    <strong>Popular</strong>
                                    <div class="text-xs text-muted">Badge "Recommended"</div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================ --}}
            {{-- SECTION 3 — Kapasitas Sistem                 --}}
            {{-- ============================================ --}}
            <div class="card mb-4">
                <div class="card-header bg-gradient-success py-3">
                    <h6 class="mb-0 text-white">
                        <i class="fas fa-sliders-h me-2"></i>
                        3. Kapasitas Sistem
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="max_wa_numbers" class="form-label">
                                    Max Nomor WA <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fab fa-whatsapp text-success"></i></span>
                                    <input type="number"
                                           class="form-control @error('max_wa_numbers') is-invalid @enderror"
                                           id="max_wa_numbers" name="max_wa_numbers"
                                           value="{{ old('max_wa_numbers', 1) }}"
                                           min="1" required>
                                </div>
                                <div class="form-text">Jumlah nomor WA yang bisa dihubungkan.</div>
                                @error('max_wa_numbers')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="max_campaigns" class="form-label">
                                    Max Campaign Aktif <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-bullhorn text-primary"></i></span>
                                    <input type="number"
                                           class="form-control @error('max_campaigns') is-invalid @enderror"
                                           id="max_campaigns" name="max_campaigns"
                                           value="{{ old('max_campaigns', 5) }}"
                                           min="1" required>
                                </div>
                                @error('max_campaigns')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="max_recipients_per_campaign" class="form-label">
                                    Max Penerima / Campaign <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-users text-info"></i></span>
                                    <input type="number"
                                           class="form-control @error('max_recipients_per_campaign') is-invalid @enderror"
                                           id="max_recipients_per_campaign" name="max_recipients_per_campaign"
                                           value="{{ old('max_recipients_per_campaign', 500) }}"
                                           min="100" required>
                                </div>
                                <div class="form-text">Minimal 100.</div>
                                @error('max_recipients_per_campaign')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ============================================ --}}
            {{-- SECTION 4 — Fitur                            --}}
            {{-- ============================================ --}}
            <div class="card mb-4">
                <div class="card-header bg-gradient-warning py-3">
                    <h6 class="mb-0 text-white">
                        <i class="fas fa-check-square me-2"></i>
                        4. Fitur
                    </h6>
                </div>
                <div class="card-body">
                    @php
                        $allFeatures = \App\Models\Plan::getAllFeatures();
                        $coreFeatures = \App\Models\Plan::getCoreFeatures();
                        $advancedFeatures = array_diff_key($allFeatures, array_flip($coreFeatures));
                        $currentFeatures = old('features', []);
                    @endphp

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label text-xs text-uppercase text-muted font-weight-bolder mb-2">Fitur Inti</label>
                            @foreach($coreFeatures as $key)
                                @if(isset($allFeatures[$key]))
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox"
                                               id="feature_{{ $key }}" name="features[]" value="{{ $key }}"
                                               {{ in_array($key, $currentFeatures) ? 'checked' : '' }}>
                                        <label class="form-check-label text-sm" for="feature_{{ $key }}">
                                            {{ $allFeatures[$key] }}
                                        </label>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-xs text-uppercase text-muted font-weight-bolder mb-2">Fitur Lanjutan</label>
                            @foreach($advancedFeatures as $key => $label)
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox"
                                           id="feature_{{ $key }}" name="features[]" value="{{ $key }}"
                                           {{ in_array($key, $currentFeatures) ? 'checked' : '' }}>
                                    <label class="form-check-label text-sm" for="feature_{{ $key }}">
                                        {{ $label }}
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            {{-- Notice --}}
            <div class="alert alert-light border mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-info-circle text-primary me-3 fa-lg"></i>
                    <span class="text-sm">Pengiriman pesan menggunakan <strong>saldo (topup)</strong> terpisah dari paket.</span>
                </div>
            </div>

            {{-- Form Actions --}}
            <div class="d-flex justify-content-between align-items-center">
                <a href="{{ route('owner.plans.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Kembali
                </a>
                <button type="submit" class="btn bg-gradient-success">
                    <i class="fas fa-save me-1"></i> Simpan Paket
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate slug from name
    const nameInput = document.getElementById('name');
    const codeInput = document.getElementById('code');
    let codeManuallyEdited = false;

    codeInput.addEventListener('input', function() {
        codeManuallyEdited = this.value.length > 0;
    });

    nameInput.addEventListener('input', function() {
        if (!codeManuallyEdited || codeInput.value.length === 0) {
            codeInput.value = this.value
                .toLowerCase()
                .replace(/[^a-z0-9\s\-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .substring(0, 50);
        }
    });
});
</script>
@endpush
