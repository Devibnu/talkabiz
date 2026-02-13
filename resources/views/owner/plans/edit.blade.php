@extends('owner.layouts.app')

@section('page-title', 'Edit Paket: ' . $plan->name)

@section('content')
<div class="row">
    {{-- Main Form (Left) --}}
    <div class="col-lg-8">
        {{-- Breadcrumb --}}
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.dashboard') }}">Dashboard</a>
                </li>
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.plans.index') }}">Paket</a>
                </li>
                <li class="breadcrumb-item text-sm text-dark active" aria-current="page">Edit: {{ $plan->name }}</li>
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

        <form action="{{ route('owner.plans.update', $plan) }}" method="POST" id="planForm">
            @csrf
            @method('PUT')
            <input type="hidden" name="code" value="{{ $plan->code }}">

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
                    {{-- Code (read-only) --}}
                    <div class="mb-3">
                        <label class="form-label">Kode Paket</label>
                        <input type="text" class="form-control bg-light" value="{{ $plan->code }}" disabled readonly>
                        <div class="form-text"><i class="fas fa-lock me-1"></i>Kode tidak dapat diubah.</div>
                    </div>

                    {{-- Name --}}
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Nama Paket <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name"
                               value="{{ old('name', $plan->name) }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Description --}}
                    <div class="mb-0">
                        <label for="description" class="form-label">Deskripsi</label>
                        <textarea class="form-control @error('description') is-invalid @enderror"
                                  id="description" name="description" rows="3"
                                  placeholder="Jelaskan keunggulan paket ini">{{ old('description', $plan->description) }}</textarea>
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
                            <div class="mb-3">
                                <label for="price_monthly" class="form-label">
                                    Harga per Bulan <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number"
                                           class="form-control @error('price_monthly') is-invalid @enderror"
                                           id="price_monthly" name="price_monthly"
                                           value="{{ old('price_monthly', $plan->price_monthly) }}"
                                           min="0" step="1000" required>
                                </div>
                                <div class="form-text">0 = paket gratis.</div>
                                @error('price_monthly')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="duration_days" class="form-label">
                                    Durasi <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="number"
                                           class="form-control @error('duration_days') is-invalid @enderror"
                                           id="duration_days" name="duration_days"
                                           value="{{ old('duration_days', $plan->duration_days) }}"
                                           min="1" max="365" required>
                                    <span class="input-group-text">hari</span>
                                </div>
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
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $plan->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">
                                    <strong>Active</strong>
                                    <div class="text-xs text-muted">Paket dapat digunakan</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_self_serve" name="is_self_serve" value="1" {{ old('is_self_serve', $plan->is_self_serve) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_self_serve">
                                    <strong>Self Serve</strong>
                                    <div class="text-xs text-muted">User bisa beli langsung</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_visible" name="is_visible" value="1" {{ old('is_visible', $plan->is_visible) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_visible">
                                    <strong>Visible</strong>
                                    <div class="text-xs text-muted">Tampil di landing page</div>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 col-6">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="is_popular" name="is_popular" value="1" {{ old('is_popular', $plan->is_popular) ? 'checked' : '' }}>
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
                                           value="{{ old('max_wa_numbers', $plan->max_wa_numbers) }}"
                                           min="1" required>
                                </div>
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
                                           value="{{ old('max_campaigns', $plan->max_campaigns) }}"
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
                                           value="{{ old('max_recipients_per_campaign', $plan->max_recipients_per_campaign) }}"
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
                        $currentFeatures = old('features', $plan->features ?? []);
                        if (is_string($currentFeatures)) {
                            $currentFeatures = json_decode($currentFeatures, true) ?? [];
                        }
                    @endphp

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label text-xs text-uppercase text-muted font-weight-bolder mb-2">Fitur Inti</label>
                            @foreach($coreFeatures as $key)
                                @if(isset($allFeatures[$key]))
                                    <div class="form-check mb-2">
                                        <input class="form-check-input feature-checkbox" type="checkbox"
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
                                    <input class="form-check-input feature-checkbox" type="checkbox"
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="{{ route('owner.plans.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Kembali
                </a>
                <button type="submit" class="btn bg-gradient-warning">
                    <i class="fas fa-save me-1"></i> Update Paket
                </button>
            </div>
        </form>
    </div>

    {{-- Sidebar (Right) --}}
    <div class="col-lg-4">
        {{-- Plan Info Card --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-info-circle text-info me-1"></i> Info Paket</h6>
            </div>
            <div class="card-body pt-3">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">ID</span>
                        <span class="text-sm font-weight-bold">#{{ $plan->id }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">Status</span>
                        @if($plan->is_active)
                            <span class="badge badge-sm bg-gradient-success">Active</span>
                        @else
                            <span class="badge badge-sm bg-gradient-secondary">Draft</span>
                        @endif
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">Tipe</span>
                        @if($plan->is_self_serve)
                            <span class="badge badge-sm bg-gradient-info">Self Serve</span>
                        @else
                            <span class="badge badge-sm bg-gradient-dark">Corporate</span>
                        @endif
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">Harga</span>
                        <span class="text-sm font-weight-bold">{{ $plan->formatted_price }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">Dibuat</span>
                        <span class="text-xs">{{ $plan->created_at->format('d M Y H:i') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between px-0 border-0 py-1">
                        <span class="text-sm text-muted">Update</span>
                        <span class="text-xs">{{ $plan->updated_at->format('d M Y H:i') }}</span>
                    </li>
                </ul>
            </div>
        </div>

        {{-- Audit Log Card --}}
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-history text-warning me-1"></i> Riwayat Perubahan</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                @if($auditLogs->count() > 0)
                    <div class="timeline timeline-one-side" style="max-height: 400px; overflow-y: auto;">
                        @foreach($auditLogs as $log)
                            <div class="timeline-block mb-3 px-3">
                                <span class="timeline-step
                                    @if($log->action === 'create') bg-success
                                    @elseif($log->action === 'update') bg-warning
                                    @elseif($log->action === 'delete') bg-danger
                                    @else bg-info
                                    @endif p-2">
                                    @if($log->action === 'create')
                                        <i class="fas fa-plus text-white text-xs"></i>
                                    @elseif($log->action === 'update')
                                        <i class="fas fa-edit text-white text-xs"></i>
                                    @else
                                        <i class="fas fa-info text-white text-xs"></i>
                                    @endif
                                </span>
                                <div class="timeline-content">
                                    <h6 class="text-dark text-sm font-weight-bold mb-0">{{ ucfirst($log->action) }}</h6>
                                    <p class="text-secondary text-xs mt-1 mb-0">{{ $log->created_at->format('d M Y H:i') }}</p>
                                    <p class="text-xs mb-0">Oleh: {{ $log->actor?->name ?? 'System' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if($auditLogs->count() > 5)
                        <div class="px-3 pt-2">
                            <a href="{{ route('owner.plans.show', $plan) }}" class="btn btn-link btn-sm p-0">Lihat semua &rarr;</a>
                        </div>
                    @endif
                @else
                    <div class="text-center py-4 px-3">
                        <i class="fas fa-history fa-2x text-muted opacity-50 mb-2"></i>
                        <p class="text-muted text-sm mb-0">Belum ada riwayat</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const planForm = document.getElementById('planForm');
    if (planForm) {
        planForm.addEventListener('submit', function(e) {
            if (planForm.dataset.confirmed === 'true') return;
            e.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Update paket?',
                    text: 'Perubahan akan disimpan.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Update',
                    cancelButtonText: 'Batal',
                    customClass: { confirmButton: 'btn bg-gradient-warning', cancelButton: 'btn bg-gradient-secondary' },
                    buttonsStyling: false
                }).then((result) => {
                    if (result.isConfirmed) {
                        planForm.dataset.confirmed = 'true';
                        planForm.submit();
                    }
                });
            } else {
                if (confirm('Update paket?')) {
                    planForm.dataset.confirmed = 'true';
                    planForm.submit();
                }
            }
        });
    }

    // Feature checkbox visual feedback
    document.querySelectorAll('.feature-checkbox').forEach(cb => {
        const label = document.querySelector(`label[for="${cb.id}"]`);
        const highlight = () => {
            label?.classList.toggle('text-primary', cb.checked);
            label?.classList.toggle('font-weight-bold', cb.checked);
        };
        cb.addEventListener('change', highlight);
        highlight();
    });
});
</script>
@endpush
