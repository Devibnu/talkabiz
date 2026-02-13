@extends('owner.layouts.app')

@section('page-title', 'Detail Paket: ' . $plan->name)

@section('content')
<div class="row">
    <div class="col-12">
        {{-- Breadcrumb --}}
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.dashboard') }}">Dashboard</a>
                </li>
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.plans.index') }}">Paket</a>
                </li>
                <li class="breadcrumb-item text-sm text-dark active" aria-current="page">{{ $plan->name }}</li>
            </ol>
        </nav>
        
        <div class="row">
            {{-- Main Content --}}
            <div class="col-lg-8">
                {{-- Plan Header --}}
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-xl" style="width: 70px; height: 70px;">
                                    <i class="fas fa-cubes text-lg opacity-10 text-white" style="line-height: 70px;"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h4 class="mb-1">
                                    {{ $plan->name }}
                                    @if($plan->is_popular)
                                        <x-plan-badge type="popular" size="md" class="ms-2" />
                                    @endif
                                </h4>
                                <p class="text-sm text-muted mb-0">
                                    <code class="me-2">{{ $plan->code }}</code>
                                    @if($plan->is_active)
                                        <x-plan-badge type="active" size="sm" />
                                    @else
                                        <x-plan-badge type="inactive" size="sm" />
                                    @endif
                                    @if($plan->is_self_serve)
                                        <x-plan-badge type="self-serve" size="sm" class="ms-1" />
                                    @endif
                                    @if($plan->is_visible)
                                        <span class="badge bg-info badge-sm ms-1">Visible</span>
                                    @endif
                                </p>
                            </div>
                            <div class="col-auto">
                                <a href="{{ route('owner.plans.edit', $plan) }}" class="btn bg-gradient-warning btn-sm mb-0">
                                    <i class="fas fa-edit me-1"></i> Edit
                                </a>
                            </div>
                        </div>
                        
                        @if($plan->description)
                            <hr class="horizontal dark my-3">
                            <p class="text-sm mb-0">{{ $plan->description }}</p>
                        @endif
                    </div>
                </div>
                
                {{-- Price & Capacity --}}
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <h6 class="mb-0">
                                    <i class="fas fa-money-bill-wave text-success me-1"></i>
                                    Harga & Durasi
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 text-center border-end">
                                        <h3 class="mb-0 text-success">
                                            @if($plan->price_monthly == 0)
                                                GRATIS
                                            @else
                                                Rp {{ number_format($plan->price_monthly, 0, ',', '.') }}
                                            @endif
                                        </h3>
                                        <p class="text-xs text-muted mb-0">Harga Bulanan</p>
                                    </div>
                                    <div class="col-6 text-center">
                                        <h3 class="mb-0 text-primary">{{ $plan->duration_days }}</h3>
                                        <p class="text-xs text-muted mb-0">Hari</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <h6 class="mb-0">
                                    <i class="fas fa-tachometer-alt text-info me-1"></i>
                                    Kapasitas
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4 border-end">
                                        <h3 class="mb-0 text-info">{{ $plan->max_wa_numbers ?? '∞' }}</h3>
                                        <p class="text-xs text-muted mb-0">Nomor WA</p>
                                    </div>
                                    <div class="col-4 border-end">
                                        <h3 class="mb-0 text-warning">{{ $plan->max_campaigns ?? '∞' }}</h3>
                                        <p class="text-xs text-muted mb-0">Campaign</p>
                                    </div>
                                    <div class="col-4">
                                        <h3 class="mb-0 text-primary">{{ $plan->max_recipients_per_campaign ?? '∞' }}</h3>
                                        <p class="text-xs text-muted mb-0">Penerima</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                {{-- Features --}}
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">
                            <i class="fas fa-list-check text-primary me-1"></i>
                            Fitur Termasuk
                        </h6>
                    </div>
                    <div class="card-body">
                        @php
                            $planFeatures = $plan->features ?? [];
                            if (is_string($planFeatures)) {
                                $planFeatures = json_decode($planFeatures, true) ?? [];
                            }
                            $allFeatures = \App\Models\Plan::getAllFeatures();
                        @endphp
                        
                        @if(count($planFeatures) > 0)
                            <div class="row">
                                @foreach($planFeatures as $feature)
                                    <div class="col-md-4 col-6 mb-3">
                                        <div class="d-flex align-items-center">
                                            <div class="icon icon-shape bg-gradient-success shadow-success text-center border-radius-md me-2" style="width: 35px; height: 35px;">
                                                <i class="fas fa-check text-white text-xs" style="line-height: 35px;"></i>
                                            </div>
                                            <span class="text-sm">{{ $allFeatures[$feature] ?? ucfirst(str_replace('_', ' ', $feature)) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4">
                                <i class="fas fa-box-open fa-2x text-muted opacity-50 mb-2"></i>
                                <p class="text-muted text-sm mb-0">Tidak ada fitur yang dikonfigurasi</p>
                            </div>
                        @endif
                    </div>
                </div>
                
                {{-- Snapshot --}}
                <div class="card">
                    <div class="card-header pb-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">
                                <i class="fas fa-camera text-secondary me-1"></i>
                                Snapshot Data
                            </h6>
                            <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#snapshotCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                    </div>
                    <div class="collapse" id="snapshotCollapse">
                        <div class="card-body">
                            <pre class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow: auto; font-size: 11px;"><code>{{ json_encode($plan->toSnapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                            <p class="text-xs text-muted mt-2 mb-0">
                                Snapshot ini digunakan saat membuat subscription baru untuk menyimpan kondisi paket saat itu.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            {{-- Sidebar --}}
            <div class="col-lg-4">
                {{-- Quick Actions --}}
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0"><i class="fas fa-bolt text-warning me-1"></i> Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" 
                                    class="btn btn-outline-{{ $plan->is_active ? 'danger' : 'success' }} btn-sm toggle-active-btn"
                                    data-plan-id="{{ $plan->id }}" data-is-active="{{ $plan->is_active ? '1' : '0' }}">
                                <i class="fas fa-{{ $plan->is_active ? 'times' : 'check' }}-circle me-1"></i>
                                {{ $plan->is_active ? 'Nonaktifkan' : 'Aktifkan' }} Paket
                            </button>
                            <button type="button" 
                                    class="btn btn-outline-{{ $plan->is_popular ? 'secondary' : 'warning' }} btn-sm toggle-popular-btn"
                                    data-plan-id="{{ $plan->id }}" data-is-popular="{{ $plan->is_popular ? '1' : '0' }}">
                                <i class="fas fa-star me-1"></i>
                                {{ $plan->is_popular ? 'Hapus dari Populer' : 'Jadikan Populer' }}
                            </button>
                        </div>
                    </div>
                </div>
                
                {{-- Info --}}
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0"><i class="fas fa-info-circle text-info me-1"></i> Informasi</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0 border-0">
                                <span class="text-sm">ID:</span>
                                <span class="text-sm font-weight-bold">#{{ $plan->id }}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 border-0">
                                <span class="text-sm">Dibuat:</span>
                                <span class="text-sm">{{ $plan->created_at->format('d M Y H:i') }}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 border-0">
                                <span class="text-sm">Update:</span>
                                <span class="text-sm">{{ $plan->updated_at->format('d M Y H:i') }}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0 border-0">
                                <span class="text-sm">Total Perubahan:</span>
                                <span class="text-sm font-weight-bold">{{ $auditLogs->count() }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                
                {{-- Audit Log --}}
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0"><i class="fas fa-history text-warning me-1"></i> Riwayat Perubahan</h6>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        @if($auditLogs->count() > 0)
                            <div style="max-height: 500px; overflow-y: auto;">
                                @foreach($auditLogs as $log)
                                    <div class="px-3 py-2 border-bottom">
                                        <div class="d-flex align-items-start">
                                            <span class="badge badge-sm me-2 
                                                @if($log->action === 'create') bg-success
                                                @elseif($log->action === 'update') bg-warning
                                                @elseif($log->action === 'delete') bg-danger
                                                @elseif($log->action === 'toggle_active') bg-info
                                                @elseif($log->action === 'toggle_popular') bg-primary
                                                @else bg-secondary
                                                @endif">
                                                {{ strtoupper($log->action) }}
                                            </span>
                                            <div class="flex-grow-1">
                                                <p class="text-xs text-muted mb-0">{{ $log->created_at->format('d M Y H:i:s') }}</p>
                                                <p class="text-sm mb-0">Oleh: {{ $log->actor?->name ?? 'System' }}</p>
                                                
                                                @if($log->old_values || $log->new_values)
                                                    <details class="mt-1">
                                                        <summary class="text-xs text-info cursor-pointer">Detail perubahan</summary>
                                                        <div class="mt-1 p-2 bg-light rounded text-xs">
                                                            @php
                                                                $oldValues = is_array($log->old_values) ? $log->old_values : json_decode($log->old_values, true);
                                                                $newValues = is_array($log->new_values) ? $log->new_values : json_decode($log->new_values, true);
                                                            @endphp
                                                            @if($oldValues)
                                                                <div class="mb-1">
                                                                    <strong class="text-danger">Before:</strong>
                                                                    <pre class="mb-0 text-xs">{{ json_encode($oldValues, JSON_PRETTY_PRINT) }}</pre>
                                                                </div>
                                                            @endif
                                                            @if($newValues)
                                                                <div>
                                                                    <strong class="text-success">After:</strong>
                                                                    <pre class="mb-0 text-xs">{{ json_encode($newValues, JSON_PRETTY_PRINT) }}</pre>
                                                                </div>
                                                            @endif
                                                        </div>
                                                    </details>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4 px-3">
                                <i class="fas fa-history fa-2x text-muted opacity-50 mb-2"></i>
                                <p class="text-muted text-sm mb-0">Belum ada riwayat perubahan</p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle Active
    document.querySelector('.toggle-active-btn')?.addEventListener('click', async function() {
        const planId = this.dataset.planId;
        const isActive = this.dataset.isActive === '1';
        
        const confirmed = await OwnerPopup.confirmWarning({
            title: isActive ? 'Nonaktifkan Paket?' : 'Aktifkan Paket?',
            text: isActive ? 'Paket tidak akan tersedia untuk pelanggan baru.' : 'Paket akan tersedia untuk pelanggan.',
            confirmText: isActive ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan'
        });
        if (!confirmed) return;
        
        OwnerPopup.loading('Memproses...');
        try {
            const response = await fetch(`{{ url('owner/plans') }}/${planId}/toggle-active`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-HTTP-Method-Override': 'PATCH', 'Accept': 'application/json' },
                body: JSON.stringify({ _method: 'PATCH' })
            });
            const data = await response.json();
            OwnerPopup.close();
            if (data.success) { await showSuccessAutoClose(data.message); window.location.reload(); }
            else { OwnerPopup.error(data.message || 'Gagal mengubah status'); }
        } catch (error) { console.error('Error:', error); OwnerPopup.error('Terjadi kesalahan koneksi'); }
    });
    
    // Toggle Popular
    document.querySelector('.toggle-popular-btn')?.addEventListener('click', async function() {
        const planId = this.dataset.planId;
        const isPopular = this.dataset.isPopular === '1';
        
        const confirmed = await OwnerPopup.confirmWarning({
            title: isPopular ? 'Hapus Label Populer?' : 'Jadikan Paket Populer?',
            text: isPopular ? 'Badge "Populer" akan dihapus.' : 'Paket ini akan ditandai sebagai populer.',
            confirmText: isPopular ? 'Ya, Hapus' : 'Ya, Jadikan Populer'
        });
        if (!confirmed) return;
        
        OwnerPopup.loading('Memproses...');
        try {
            const response = await fetch(`{{ url('owner/plans') }}/${planId}/toggle-popular`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-HTTP-Method-Override': 'PATCH', 'Accept': 'application/json' },
                body: JSON.stringify({ _method: 'PATCH' })
            });
            const data = await response.json();
            OwnerPopup.close();
            if (data.success) { await showSuccessAutoClose(data.message); window.location.reload(); }
            else { OwnerPopup.error(data.message || 'Gagal mengubah status popular'); }
        } catch (error) { console.error('Error:', error); OwnerPopup.error('Terjadi kesalahan koneksi'); }
    });
    
    function showSuccessAutoClose(message) {
        return Swal.fire({ icon: 'success', title: 'Berhasil!', text: message, timer: 1500, timerProgressBar: true, showConfirmButton: false });
    }
});
</script>
@endpush
