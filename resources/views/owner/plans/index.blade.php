@extends('owner.layouts.app')

@section('page-title', 'Manajemen Paket')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Paket</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['total']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-cubes text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Aktif</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['active']) }}</h5>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Self-Serve</p>
                        <h5 class="font-weight-bolder mb-0 text-info">{{ number_format($stats['self_serve']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-shopping-cart text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Visible</p>
                        <h5 class="font-weight-bolder mb-0 text-warning">{{ number_format($stats['visible']) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                            <i class="fas fa-eye text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Toolbar --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        @if($popularPlan)
            <span class="text-sm text-muted">
                <i class="fas fa-star text-warning me-1"></i>Populer: <strong>{{ $popularPlan->name }}</strong>
            </span>
        @endif
    </div>
    <a href="{{ route('owner.plans.create') }}" class="btn bg-gradient-success btn-sm mb-0">
        <i class="fas fa-plus me-1"></i> Tambah Paket
    </a>
</div>

{{-- Alerts --}}
@if(session('success'))
    <div class="alert alert-success text-white mb-4" role="alert">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger text-white mb-4" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
    </div>
@endif

{{-- Plans Table --}}
<div class="card">
    <div class="card-header pb-0">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0">Daftar Paket</h6>
            <span class="badge bg-gradient-dark">{{ $plans->total() }} paket</span>
        </div>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive p-0">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nama Paket</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Harga / bulan</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">WA Limit</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Campaign</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Recipients</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Tipe</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2 text-center">Popular</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($plans as $plan)
                        <tr>
                            {{-- Nama Paket --}}
                            <td>
                                <div class="d-flex px-2 py-1">
                                    <div>
                                        <h6 class="mb-0 text-sm">{{ $plan->name }}</h6>
                                        <p class="text-xs text-secondary mb-0"><code>{{ $plan->code }}</code></p>
                                    </div>
                                </div>
                            </td>

                            {{-- Harga / bulan --}}
                            <td>
                                @if($plan->price_monthly == 0)
                                    <span class="badge badge-sm bg-gradient-success">GRATIS</span>
                                @else
                                    <span class="text-sm font-weight-bold">Rp {{ number_format($plan->price_monthly, 0, ',', '.') }}</span>
                                    <p class="text-xs text-secondary mb-0">/ {{ $plan->duration_days }} hari</p>
                                @endif
                            </td>

                            {{-- WA Limit --}}
                            <td class="text-center">
                                <span class="text-sm font-weight-bold">{{ $plan->max_wa_numbers }}</span>
                            </td>

                            {{-- Campaign Limit --}}
                            <td class="text-center">
                                <span class="text-sm font-weight-bold">{{ $plan->max_campaigns ?: '∞' }}</span>
                            </td>

                            {{-- Recipients Limit --}}
                            <td class="text-center">
                                <span class="text-sm">{{ $plan->max_recipients_per_campaign ? number_format($plan->max_recipients_per_campaign) : '∞' }}</span>
                            </td>

                            {{-- Status (Active / Draft) --}}
                            <td class="text-center">
                                <div class="form-check form-switch d-flex justify-content-center mb-0">
                                    <input class="form-check-input toggle-active"
                                           type="checkbox"
                                           data-plan-id="{{ $plan->id }}"
                                           {{ $plan->is_active ? 'checked' : '' }}>
                                </div>
                                <span class="text-xxs {{ $plan->is_active ? 'text-success' : 'text-secondary' }}">
                                    {{ $plan->is_active ? 'Active' : 'Draft' }}
                                </span>
                            </td>

                            {{-- Tipe: Self Serve / Corporate --}}
                            <td class="text-center">
                                @if($plan->is_self_serve)
                                    <span class="badge badge-sm bg-gradient-info">Self Serve</span>
                                @else
                                    <span class="badge badge-sm bg-gradient-dark">Corporate</span>
                                @endif
                            </td>

                            {{-- Popular Badge --}}
                            <td class="text-center">
                                <button type="button"
                                        class="btn btn-link p-0 mb-0 toggle-popular {{ $plan->is_popular ? 'text-warning' : 'text-secondary opacity-50' }}"
                                        data-plan-id="{{ $plan->id }}"
                                        data-is-popular="{{ $plan->is_popular ? '1' : '0' }}"
                                        title="{{ $plan->is_popular ? 'Hapus dari Populer' : 'Jadikan Populer' }}">
                                    <i class="fas fa-star fa-lg"></i>
                                </button>
                            </td>

                            {{-- Aksi --}}
                            <td class="text-center">
                                <a href="{{ route('owner.plans.edit', $plan) }}"
                                   class="btn btn-link text-warning px-2 mb-0" title="Edit">
                                    <i class="fas fa-pencil-alt text-sm"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x mb-3 text-secondary opacity-50"></i>
                                <p class="text-secondary mb-0">Belum ada paket.</p>
                                <a href="{{ route('owner.plans.create') }}" class="btn bg-gradient-success btn-sm mt-3">
                                    <i class="fas fa-plus me-1"></i> Buat Paket Pertama
                                </a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($plans->hasPages())
            <div class="d-flex justify-content-center mt-3 px-3 pb-3">
                {{ $plans->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Info Notice --}}
<div class="alert alert-light border mt-4" role="alert">
    <div class="d-flex align-items-center">
        <i class="fas fa-info-circle text-primary me-3 fa-lg"></i>
        <div>
            <span class="text-sm"><strong>Subscription-Only Model</strong> — Paket = FITUR & AKSES. Pengiriman pesan menggunakan SALDO TOPUP (terpisah dari paket).</span>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const baseUrl = '{{ url("owner/plans") }}';

    // Toggle Active
    document.querySelectorAll('.toggle-active').forEach(function(toggle) {
        toggle.addEventListener('change', async function(e) {
            e.preventDefault();
            const planId = this.dataset.planId;
            const isChecked = this.checked;
            this.checked = !isChecked;

            const confirmed = await OwnerPopup.confirmWarning({
                title: isChecked ? 'Aktifkan Paket?' : 'Nonaktifkan Paket?',
                text: isChecked ? 'Paket akan tersedia untuk pelanggan.' : 'Paket tidak akan tersedia untuk pelanggan baru.',
                confirmText: isChecked ? 'Ya, Aktifkan' : 'Ya, Nonaktifkan'
            });
            if (!confirmed) return;

            OwnerPopup.loading('Memproses...');
            try {
                const res = await fetch(`${baseUrl}/${planId}/toggle-active`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ _method: 'PATCH' })
                });
                const data = await res.json();
                OwnerPopup.close();
                if (data.success) {
                    this.checked = isChecked;
                    Swal.fire({ icon: 'success', title: 'Berhasil!', text: data.message, timer: 1500, showConfirmButton: false });
                    setTimeout(() => location.reload(), 1600);
                } else {
                    OwnerPopup.error(data.message || 'Gagal mengubah status');
                }
            } catch (err) {
                OwnerPopup.error('Terjadi kesalahan koneksi');
            }
        });
    });

    // Toggle Popular
    document.querySelectorAll('.toggle-popular').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            const planId = this.dataset.planId;
            const isPopular = this.dataset.isPopular === '1';

            const confirmed = await OwnerPopup.confirmWarning({
                title: isPopular ? 'Hapus Label Populer?' : 'Jadikan Paket Populer?',
                text: isPopular ? 'Badge "Populer" akan dihapus.' : 'Paket lain yang populer akan otomatis di-unmark.',
                confirmText: isPopular ? 'Ya, Hapus' : 'Ya, Jadikan Populer'
            });
            if (!confirmed) return;

            OwnerPopup.loading('Memproses...');
            try {
                const res = await fetch(`${baseUrl}/${planId}/toggle-popular`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: JSON.stringify({ _method: 'PATCH' })
                });
                const data = await res.json();
                OwnerPopup.close();
                if (data.success) {
                    Swal.fire({ icon: 'success', title: 'Berhasil!', text: data.message, timer: 1500, showConfirmButton: false });
                    setTimeout(() => location.reload(), 1600);
                } else {
                    OwnerPopup.error(data.message || 'Gagal mengubah status');
                }
            } catch (err) {
                OwnerPopup.error('Terjadi kesalahan koneksi');
            }
        });
    });
});
</script>
@endpush
