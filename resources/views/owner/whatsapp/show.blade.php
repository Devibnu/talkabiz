@extends('owner.layouts.app')

@section('page-title', 'WhatsApp Detail: ' . $connection->phone_number)

@section('content')
{{-- Alerts --}}
@if(session('success'))
    <div class="alert alert-success text-white" role="alert">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger text-white" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
    </div>
@endif

{{-- Header --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center">
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-lg me-3">
                                <i class="fab fa-whatsapp text-lg opacity-10"></i>
                            </div>
                            <div>
                                <h4 class="mb-0">{{ $connection->phone_number }}</h4>
                                <p class="text-sm text-muted mb-0">
                                    {{ $connection->client?->nama_perusahaan ?? 'No Client' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge badge-lg 
                            @if($connection->status == 'connected') bg-gradient-success
                            @elseif($connection->status == 'pending') bg-gradient-warning
                            @else bg-gradient-danger
                            @endif">
                            {{ ucfirst($connection->status) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Main Content --}}
<div class="row">
    {{-- Left Column - Connection Info --}}
    <div class="col-lg-8">
        {{-- Basic Info --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Informasi Koneksi</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Nomor WhatsApp</label>
                        <p class="font-weight-bold mb-0">{{ $connection->phone_number }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Status</label>
                        <p class="font-weight-bold mb-0">
                            <span class="badge 
                                @if($connection->status == 'connected') bg-gradient-success
                                @elseif($connection->status == 'pending') bg-gradient-warning
                                @else bg-gradient-danger
                                @endif">
                                {{ ucfirst($connection->status) }}
                            </span>
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Klien</label>
                        <p class="font-weight-bold mb-0">
                            @if($connection->client)
                                <a href="{{ route('owner.clients.show', $connection->client) }}">
                                    {{ $connection->client->nama_perusahaan }}
                                </a>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Provider</label>
                        <p class="font-weight-bold mb-0">{{ $connection->provider ?? 'Gupshup' }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Connected At</label>
                        <p class="font-weight-bold mb-0">
                            {{ $connection->connected_at ? $connection->connected_at->format('d M Y H:i') : '-' }}
                        </p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Last Sync</label>
                        <p class="font-weight-bold mb-0">
                            {{ $connection->last_sync_at ? $connection->last_sync_at->format('d M Y H:i') : 'Never' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Message Stats --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Statistik Pesan</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 text-center">
                        <h3 class="font-weight-bolder text-success mb-0">
                            {{ number_format($stats['messages_today'] ?? 0) }}
                        </h3>
                        <p class="text-sm text-muted">Hari Ini</p>
                    </div>
                    <div class="col-md-4 text-center">
                        <h3 class="font-weight-bolder text-info mb-0">
                            {{ number_format($stats['messages_week'] ?? 0) }}
                        </h3>
                        <p class="text-sm text-muted">Minggu Ini</p>
                    </div>
                    <div class="col-md-4 text-center">
                        <h3 class="font-weight-bolder text-primary mb-0">
                            {{ number_format($stats['messages_month'] ?? 0) }}
                        </h3>
                        <p class="text-sm text-muted">Bulan Ini</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent Webhooks --}}
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Webhook Log (Terbaru)</h6>
                <a href="{{ route('owner.logs.webhooks', ['connection_id' => $connection->id]) }}" 
                   class="btn btn-sm btn-outline-primary mb-0">
                    Lihat Semua
                </a>
            </div>
            <div class="card-body">
                @if(isset($webhooks) && $webhooks->count() > 0)
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Event</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($webhooks as $webhook)
                                    <tr>
                                        <td class="ps-3">
                                            <span class="text-xs font-weight-bold">{{ $webhook->event_type }}</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm 
                                                @if($webhook->status == 'success') bg-gradient-success
                                                @else bg-gradient-danger
                                                @endif">
                                                {{ $webhook->status }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-xs">{{ $webhook->created_at->diffForHumans() }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center py-3 mb-0">Belum ada webhook log</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Right Column - Actions --}}
    <div class="col-lg-4">
        {{-- Quick Actions --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Kontrol Status</h6>
            </div>
            <div class="card-body">
                <form action="{{ route('owner.whatsapp.force-connect', $connection) }}" method="POST" class="mb-2">
                    @csrf
                    <button type="submit" class="btn bg-gradient-success w-100 mb-0"
                            {{ $connection->status == 'connected' ? 'disabled' : '' }}>
                        <i class="fas fa-plug me-2"></i> Force Connect
                    </button>
                </form>

                <form action="{{ route('owner.whatsapp.force-pending', $connection) }}" method="POST" class="mb-2">
                    @csrf
                    <button type="submit" class="btn bg-gradient-warning w-100 mb-0"
                            {{ $connection->status == 'pending' ? 'disabled' : '' }}>
                        <i class="fas fa-clock me-2"></i> Force Pending
                    </button>
                </form>

                <form action="{{ route('owner.whatsapp.force-fail', $connection) }}" method="POST" class="mb-2">
                    @csrf
                    <button type="submit" class="btn bg-gradient-danger w-100 mb-0"
                            {{ $connection->status == 'failed' ? 'disabled' : '' }}>
                        <i class="fas fa-times me-2"></i> Force Fail
                    </button>
                </form>

                <hr>

                <form action="{{ route('owner.whatsapp.re-verify', $connection) }}" method="POST" class="mb-2">
                    @csrf
                    <button type="submit" class="btn btn-outline-info w-100 mb-0">
                        <i class="fas fa-sync me-2"></i> Re-Verify via Gupshup
                    </button>
                </form>

                <form action="{{ route('owner.whatsapp.disconnect', $connection) }}" method="POST" class="mb-2"
                      id="disconnect-form-main"
                      onsubmit="return false;">
                    @csrf
                    <button type="button" class="btn btn-outline-danger w-100 mb-0" onclick="confirmDisconnectMain()">
                        <i class="fas fa-unlink me-2"></i> Disconnect
                    </button>
                </form>
            </div>
        </div>

        {{-- Connection Metadata --}}
        @if($connection->metadata)
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Metadata</h6>
                </div>
                <div class="card-body">
                    <pre class="text-xs bg-gray-100 p-3 rounded" style="max-height: 200px; overflow: auto;">{{ json_encode($connection->metadata, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>
        @endif

        {{-- Back Button --}}
        <a href="{{ route('owner.whatsapp.index') }}" class="btn btn-outline-dark w-100">
            <i class="fas fa-arrow-left me-2"></i> Kembali ke Daftar
        </a>
    </div>
</div>
@endsection

@push('scripts')
<script>
function confirmDisconnectMain() {
    OwnerPopup.confirmDanger({
        title: 'Disconnect WhatsApp?',
        text: `
            <p class="mb-2">Anda akan memutuskan koneksi nomor:</p>
            <p class="fw-bold mb-3">{{ $connection->phone_number }}</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                    <strong>Dampak:</strong> Klien harus menghubungkan ulang nomor ini.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-unlink me-1"></i> Ya, Disconnect',
        onConfirm: () => {
            document.getElementById('disconnect-form-main').submit();
        }
    });
}
</script>
@endpush
