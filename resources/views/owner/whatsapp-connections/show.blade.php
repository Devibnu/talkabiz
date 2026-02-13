@extends('layouts.owner')

@section('title', 'Detail Koneksi - ' . $connection->phone_number)

@section('content')
<div class="container-fluid py-4">
    {{-- Breadcrumb --}}
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                    <li class="breadcrumb-item"><a href="{{ route('owner.wa-connections.index') }}">WhatsApp Connections</a></li>
                    <li class="breadcrumb-item active">{{ $connection->phone_number }}</li>
                </ol>
            </nav>
        </div>
    </div>

    <div class="row">
        {{-- Main Info Card --}}
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="mb-0">{{ $connection->display_name ?? $connection->business_name ?? 'WhatsApp Connection' }}</h5>
                            <p class="text-sm text-secondary mb-0">
                                <i class="fab fa-whatsapp me-1"></i> {{ $connection->phone_number }}
                            </p>
                        </div>
                        <div>
                            <span class="badge bg-gradient-{{ $statusEnum->color() }} badge-lg">
                                <i class="{{ $statusEnum->icon() }} me-1"></i>
                                {{ $statusEnum->label() }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-uppercase text-body text-xs font-weight-bolder">Informasi Koneksi</h6>
                            <ul class="list-group">
                                <li class="list-group-item border-0 ps-0 pt-0 text-sm">
                                    <strong class="text-dark">ID:</strong> {{ $connection->id }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Klien:</strong> 
                                    {{ $connection->klien?->nama_perusahaan ?? 'N/A' }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Gupshup App ID:</strong> 
                                    <code>{{ $connection->gupshup_app_id ?? '-' }}</code>
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Quality Rating:</strong>
                                    @if($connection->quality_rating)
                                        @php
                                            $qColor = match($connection->quality_rating) {
                                                'GREEN' => 'success',
                                                'YELLOW' => 'warning',
                                                default => 'danger',
                                            };
                                        @endphp
                                        <span class="badge bg-gradient-{{ $qColor }}">{{ $connection->quality_rating }}</span>
                                    @else
                                        <span class="text-secondary">-</span>
                                    @endif
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-uppercase text-body text-xs font-weight-bolder">Timestamps</h6>
                            <ul class="list-group">
                                <li class="list-group-item border-0 ps-0 pt-0 text-sm">
                                    <strong class="text-dark">Created:</strong> 
                                    {{ $connection->created_at->format('d M Y H:i') }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Connected At:</strong> 
                                    {{ $connection->connected_at?->format('d M Y H:i') ?? '-' }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Disconnected At:</strong> 
                                    {{ $connection->disconnected_at?->format('d M Y H:i') ?? '-' }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Failed At:</strong> 
                                    {{ $connection->failed_at?->format('d M Y H:i') ?? '-' }}
                                </li>
                                <li class="list-group-item border-0 ps-0 text-sm">
                                    <strong class="text-dark">Webhook Last Update:</strong> 
                                    {{ $connection->webhook_last_update?->format('d M Y H:i') ?? '-' }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    @if($connection->error_reason)
                        <div class="alert alert-danger mt-3" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Error Reason:</strong> {{ $connection->error_reason }}
                        </div>
                    @endif
                </div>
            </div>

            {{-- Last Webhook Payload --}}
            @if($connection->last_webhook_payload)
                <div class="card mb-4">
                    <div class="card-header pb-0">
                        <h6>Last Webhook Payload</h6>
                    </div>
                    <div class="card-body">
                        <pre class="bg-dark text-light p-3 rounded" style="max-height: 300px; overflow: auto;">{{ json_encode($connection->last_webhook_payload, JSON_PRETTY_PRINT) }}</pre>
                    </div>
                </div>
            @endif

            {{-- Recent Webhook Events --}}
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between">
                    <h6>Recent Webhook Events</h6>
                    <a href="{{ route('owner.wa-connections.webhook-history', $connection->id) }}" 
                       class="btn btn-sm btn-outline-primary">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Event</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Transition</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Result</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($webhookHistory->take(10) as $event)
                                    <tr>
                                        <td class="ps-4">
                                            <span class="text-sm">{{ $event->event_type }}</span>
                                        </td>
                                        <td>
                                            @if($event->status_changed)
                                                <span class="text-xs">{{ $event->old_status }} → {{ $event->new_status }}</span>
                                            @else
                                                <span class="text-xs text-secondary">-</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-sm bg-gradient-{{ $event->result === 'processed' ? 'success' : 'secondary' }}">
                                                {{ $event->result }}
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <span class="text-xs">{{ $event->created_at->diffForHumans() }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-3">
                                            <span class="text-secondary">Belum ada webhook event</span>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Actions Sidebar --}}
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6>Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        {{-- Refresh Status --}}
                        <button class="btn btn-outline-primary" onclick="refreshStatus({{ $connection->id }})">
                            <i class="fas fa-sync me-2"></i> Refresh Status
                        </button>

                        {{-- Force Disconnect --}}
                        @if($statusEnum !== \App\Enums\WhatsAppConnectionStatus::DISCONNECTED)
                            <button class="btn btn-outline-danger" onclick="forceDisconnect({{ $connection->id }})">
                                <i class="fas fa-unlink me-2"></i> Force Disconnect
                            </button>
                        @endif

                        {{-- Reset to Pending --}}
                        @if(in_array($statusEnum, [\App\Enums\WhatsAppConnectionStatus::FAILED, \App\Enums\WhatsAppConnectionStatus::DISCONNECTED]))
                            <button class="btn btn-outline-warning" onclick="resetToPending({{ $connection->id }})">
                                <i class="fas fa-redo me-2"></i> Reset to Pending
                            </button>
                        @endif

                        {{-- View Webhook History --}}
                        <a href="{{ route('owner.wa-connections.webhook-history', $connection->id) }}" 
                           class="btn btn-outline-secondary">
                            <i class="fas fa-history me-2"></i> Webhook History
                        </a>
                    </div>
                </div>
            </div>

            {{-- Source of Truth Notice --}}
            <div class="card bg-gradient-info">
                <div class="card-body p-3">
                    <div class="d-flex">
                        <div class="me-3">
                            <i class="fas fa-info-circle text-white fa-2x"></i>
                        </div>
                        <div>
                            <h6 class="text-white mb-1">Source of Truth</h6>
                            <p class="text-white text-sm mb-0">
                                Status <strong>CONNECTED</strong> hanya dapat di-set oleh webhook dari Gupshup.
                            </p>
                        </div>
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
            text: 'Koneksi WhatsApp akan diputus.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Ya, Disconnect!',
            cancelButtonText: 'Batal',
            input: 'text',
            inputLabel: 'Alasan (opsional)',
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
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Ya, Reset!',
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
                Swal.fire('Refreshed!', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        });
    }
</script>
@endpush
