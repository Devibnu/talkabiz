@extends('layouts.owner')

@section('title', 'WhatsApp Connections')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-dark">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-white text-sm mb-0 text-uppercase font-weight-bold opacity-7">
                                    WhatsApp Connections
                                </p>
                                <h5 class="text-white font-weight-bolder mb-0">
                                    Kelola Semua Koneksi WhatsApp
                                </h5>
                                <p class="text-white text-xs mb-0 opacity-7">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Status CONNECTED hanya bisa di-set oleh webhook Gupshup
                                </p>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-white shadow text-center border-radius-md">
                                <i class="fab fa-whatsapp text-dark text-lg opacity-10" aria-hidden="true"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Status Summary Cards --}}
    <div class="row mb-4">
        @php
            $statusCards = [
                ['status' => 'pending', 'label' => 'Pending', 'color' => 'warning', 'icon' => 'fas fa-clock'],
                ['status' => 'connected', 'label' => 'Connected', 'color' => 'success', 'icon' => 'fas fa-check-circle'],
                ['status' => 'failed', 'label' => 'Failed', 'color' => 'danger', 'icon' => 'fas fa-times-circle'],
                ['status' => 'disconnected', 'label' => 'Disconnected', 'color' => 'secondary', 'icon' => 'fas fa-unlink'],
            ];
        @endphp
        
        @foreach($statusCards as $card)
            <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
                <div class="card">
                    <div class="card-body p-3">
                        <div class="row">
                            <div class="col-8">
                                <div class="numbers">
                                    <p class="text-sm mb-0 text-uppercase font-weight-bold">{{ $card['label'] }}</p>
                                    <h5 class="font-weight-bolder mb-0">
                                        {{ $statusCounts[$card['status']] ?? 0 }}
                                    </h5>
                                </div>
                            </div>
                            <div class="col-4 text-end">
                                <div class="icon icon-shape bg-gradient-{{ $card['color'] }} shadow text-center border-radius-md">
                                    <i class="{{ $card['icon'] }} text-lg opacity-10 text-white"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-3">
                    <form method="GET" action="{{ route('owner.wa-connections.index') }}" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label text-xs">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Semua Status</option>
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" {{ ($filters['status'] ?? '') === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label text-xs">Cari</label>
                            <input type="text" name="search" class="form-control form-control-sm" 
                                   placeholder="Nomor HP, nama bisnis..." 
                                   value="{{ $filters['search'] ?? '' }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-xs">&nbsp;</label>
                            <button type="submit" class="btn btn-sm btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label text-xs">&nbsp;</label>
                            <a href="{{ route('owner.wa-connections.index') }}" class="btn btn-sm btn-outline-secondary w-100">
                                Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- Connections Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Daftar Koneksi WhatsApp</h6>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Nomor WA</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Quality</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Webhook Update</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($connections as $connection)
                                    @php
                                        $statusEnum = \App\Enums\WhatsAppConnectionStatus::fromString($connection->status);
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="d-flex px-2 py-1">
                                                <div>
                                                    <div class="icon icon-shape icon-sm bg-gradient-dark shadow text-center border-radius-md me-2">
                                                        <i class="fas fa-building text-white opacity-10"></i>
                                                    </div>
                                                </div>
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $connection->klien?->nama_perusahaan ?? 'N/A' }}</h6>
                                                    <p class="text-xs text-secondary mb-0">
                                                        {{ $connection->display_name ?? $connection->business_name ?? '-' }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-sm font-weight-bold mb-0">{{ $connection->phone_number }}</p>
                                            <p class="text-xs text-secondary mb-0">
                                                App: {{ Str::limit($connection->gupshup_app_id, 15) ?? '-' }}
                                            </p>
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            <span class="badge badge-sm bg-gradient-{{ $statusEnum->color() }}">
                                                <i class="{{ $statusEnum->icon() }} me-1"></i>
                                                {{ $statusEnum->label() }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center">
                                            @if($connection->quality_rating)
                                                @php
                                                    $qColor = match($connection->quality_rating) {
                                                        'GREEN' => 'success',
                                                        'YELLOW' => 'warning',
                                                        default => 'danger',
                                                    };
                                                @endphp
                                                <span class="badge badge-sm bg-gradient-{{ $qColor }}">
                                                    {{ $connection->quality_rating }}
                                                </span>
                                            @else
                                                <span class="text-xs text-secondary">-</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center">
                                            @if($connection->webhook_last_update)
                                                <span class="text-xs">
                                                    {{ $connection->webhook_last_update->diffForHumans() }}
                                                </span>
                                            @else
                                                <span class="text-xs text-secondary">Belum ada</span>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-link text-secondary mb-0" type="button" 
                                                        data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('owner.wa-connections.show', $connection->id) }}">
                                                            <i class="fas fa-eye me-2"></i> Detail
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('owner.wa-connections.webhook-history', $connection->id) }}">
                                                            <i class="fas fa-history me-2"></i> Webhook History
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    @if($statusEnum !== \App\Enums\WhatsAppConnectionStatus::DISCONNECTED)
                                                        <li>
                                                            <button class="dropdown-item text-danger" 
                                                                    onclick="forceDisconnect({{ $connection->id }})">
                                                                <i class="fas fa-unlink me-2"></i> Force Disconnect
                                                            </button>
                                                        </li>
                                                    @endif
                                                    @if(in_array($statusEnum, [\App\Enums\WhatsAppConnectionStatus::FAILED, \App\Enums\WhatsAppConnectionStatus::DISCONNECTED]))
                                                        <li>
                                                            <button class="dropdown-item text-warning" 
                                                                    onclick="resetToPending({{ $connection->id }})">
                                                                <i class="fas fa-redo me-2"></i> Reset to Pending
                                                            </button>
                                                        </li>
                                                    @endif
                                                    <li>
                                                        <button class="dropdown-item" 
                                                                onclick="refreshStatus({{ $connection->id }})">
                                                            <i class="fas fa-sync me-2"></i> Refresh Status
                                                        </button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <p class="text-secondary mb-0">Tidak ada data koneksi</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    {{-- Pagination --}}
                    @if($connections->hasPages())
                        <div class="px-4 pt-3">
                            {{ $connections->withQueryString()->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Source of Truth Notice --}}
    <div class="row mt-4">
        <div class="col-12">
            <div class="alert alert-info text-white" role="alert">
                <div class="d-flex">
                    <div class="me-3">
                        <i class="fas fa-info-circle fa-2x"></i>
                    </div>
                    <div>
                        <h6 class="text-white mb-1">Source of Truth: Webhook</h6>
                        <p class="text-sm mb-0">
                            Status <strong>CONNECTED</strong> hanya bisa di-set oleh webhook dari Gupshup.
                            Owner hanya dapat melakukan <strong>Force Disconnect</strong> atau <strong>Reset to Pending</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function forceDisconnect(id) {
        Swal.fire({
            title: 'Force Disconnect?',
            text: 'Koneksi WhatsApp akan diputus. User harus menunggu verifikasi ulang dari Gupshup.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Ya, Disconnect!',
            cancelButtonText: 'Batal',
            input: 'text',
            inputLabel: 'Alasan (opsional)',
            inputPlaceholder: 'Masukkan alasan disconnect...',
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`/owner/whatsapp-connections/${id}/force-disconnect`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ reason: result.value || 'Force disconnected by owner' }),
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Berhasil!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', data.message, 'error');
                    }
                });
            }
        });
    }

    function resetToPending(id) {
        Swal.fire({
            title: 'Reset to Pending?',
            text: 'Status akan di-reset ke PENDING untuk menunggu verifikasi ulang dari Gupshup.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, Reset!',
            cancelButtonText: 'Batal',
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`/owner/whatsapp-connections/${id}/reset-to-pending`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Berhasil!', data.message, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Gagal!', data.message, 'error');
                    }
                });
            }
        });
    }

    function refreshStatus(id) {
        fetch(`/owner/whatsapp-connections/${id}/refresh-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Status Refreshed',
                    html: `
                        <div class="text-start">
                            <p><strong>Status:</strong> ${data.connection.status_label}</p>
                            <p><strong>Last Webhook:</strong> ${data.connection.webhook_last_update || 'N/A'}</p>
                            <p class="text-muted small">${data.note}</p>
                        </div>
                    `,
                    icon: 'info',
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
    }
</script>
@endpush
