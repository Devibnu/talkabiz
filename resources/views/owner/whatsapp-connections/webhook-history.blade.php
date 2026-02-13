@extends('layouts.owner')

@section('title', 'Webhook History - ' . $connection->phone_number)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                    <li class="breadcrumb-item"><a href="{{ route('owner.wa-connections.index') }}">WhatsApp Connections</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('owner.wa-connections.show', $connection->id) }}">{{ $connection->phone_number }}</a></li>
                    <li class="breadcrumb-item active">Webhook History</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Connection Info Card --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="mb-0">{{ $connection->display_name ?? $connection->business_name ?? 'N/A' }}</h6>
                            <p class="text-sm text-secondary mb-0">
                                <i class="fab fa-whatsapp me-1"></i> {{ $connection->phone_number }}
                            </p>
                        </div>
                        <div class="col-md-6 text-end">
                            @php
                                $statusEnum = \App\Enums\WhatsAppConnectionStatus::fromString($connection->status);
                            @endphp
                            <span class="badge bg-gradient-{{ $statusEnum->color() }}">
                                <i class="{{ $statusEnum->icon() }} me-1"></i>
                                {{ $statusEnum->label() }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Webhook Events Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Webhook Events History</h6>
                    <p class="text-sm text-secondary mb-0">
                        Semua event webhook yang diterima untuk koneksi ini
                    </p>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Event</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status Transition</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Result</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Security</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($webhookEvents as $event)
                                    <tr>
                                        <td>
                                            <div class="d-flex px-2 py-1">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $event->event_type ?? 'unknown' }}</h6>
                                                    <p class="text-xs text-secondary mb-0">
                                                        ID: {{ Str::limit($event->event_id, 20) }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            @if($event->status_changed)
                                                <span class="badge badge-sm bg-gradient-secondary">{{ $event->old_status }}</span>
                                                <i class="fas fa-arrow-right mx-1 text-xs"></i>
                                                <span class="badge badge-sm bg-gradient-primary">{{ $event->new_status }}</span>
                                            @else
                                                <span class="text-xs text-secondary">Tidak berubah</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            @php
                                                $resultColor = match($event->result) {
                                                    'processed' => 'success',
                                                    'ignored' => 'warning',
                                                    'rejected' => 'danger',
                                                    default => 'secondary',
                                                };
                                            @endphp
                                            <span class="badge badge-sm bg-gradient-{{ $resultColor }}">
                                                {{ $event->result ?? 'unknown' }}
                                            </span>
                                            @if($event->result_reason)
                                                <br>
                                                <span class="text-xs text-secondary">{{ Str::limit($event->result_reason, 30) }}</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center">
                                            <div class="d-flex justify-content-center">
                                                @if($event->signature_valid)
                                                    <span class="badge badge-sm bg-gradient-success me-1" title="Signature Valid">
                                                        <i class="fas fa-lock"></i>
                                                    </span>
                                                @else
                                                    <span class="badge badge-sm bg-gradient-danger me-1" title="Signature Invalid">
                                                        <i class="fas fa-unlock"></i>
                                                    </span>
                                                @endif
                                                @if($event->ip_valid)
                                                    <span class="badge badge-sm bg-gradient-success" title="IP Valid">
                                                        <i class="fas fa-network-wired"></i>
                                                    </span>
                                                @else
                                                    <span class="badge badge-sm bg-gradient-danger" title="IP Invalid">
                                                        <i class="fas fa-ban"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            <span class="text-xs text-secondary">{{ $event->source_ip }}</span>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-xs font-weight-bold">
                                                {{ $event->created_at->format('d M Y') }}
                                            </span>
                                            <br>
                                            <span class="text-xs text-secondary">
                                                {{ $event->created_at->format('H:i:s') }}
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <button class="btn btn-sm btn-link text-secondary mb-0" 
                                                    onclick="showPayload({{ $event->id }})"
                                                    title="View Payload">
                                                <i class="fas fa-code"></i>
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center py-4">
                                            <p class="text-secondary mb-0">Belum ada webhook event</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    
                    {{-- Pagination --}}
                    @if($webhookEvents->hasPages())
                        <div class="px-4 pt-3">
                            {{ $webhookEvents->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Payload Modal --}}
<div class="modal fade" id="payloadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Webhook Payload</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="payloadContent" class="bg-dark text-light p-3 rounded" style="max-height: 400px; overflow: auto;"></pre>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const webhookPayloads = @json($webhookEvents->pluck('payload', 'id'));
    
    function showPayload(id) {
        const payload = webhookPayloads[id] || {};
        document.getElementById('payloadContent').textContent = JSON.stringify(payload, null, 2);
        new bootstrap.Modal(document.getElementById('payloadModal')).show();
    }
</script>
@endpush
