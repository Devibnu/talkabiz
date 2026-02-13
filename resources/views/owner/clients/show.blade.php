@extends('owner.layouts.app')

@section('page-title', 'Detail Klien: ' . $client->nama_perusahaan)

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
                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-lg me-3">
                                <i class="fas fa-building text-lg opacity-10"></i>
                            </div>
                            <div>
                                <h4 class="mb-0">{{ $client->nama_perusahaan }}</h4>
                                <p class="text-sm text-muted mb-0">
                                    {{ $client->email ?? $client->user?->email ?? 'No email' }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge badge-lg 
                            @if($client->status == 'aktif') bg-gradient-success
                            @elseif($client->status == 'pending') bg-gradient-warning
                            @else bg-gradient-danger
                            @endif">
                            {{ ucfirst($client->status) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Main Content --}}
<div class="row">
    {{-- Left Column - Client Info --}}
    <div class="col-lg-8">
        {{-- Basic Info --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Informasi Klien</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Nama Perusahaan</label>
                        <p class="font-weight-bold mb-0">{{ $client->nama_perusahaan }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Nama PIC</label>
                        <p class="font-weight-bold mb-0">{{ $client->nama_pic ?? '-' }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Email</label>
                        <p class="font-weight-bold mb-0">{{ $client->email ?? '-' }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Telepon</label>
                        <p class="font-weight-bold mb-0">{{ $client->telepon ?? '-' }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Alamat</label>
                        <p class="font-weight-bold mb-0">{{ $client->alamat ?? '-' }}</p>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-xs text-muted mb-1">Registered</label>
                        <p class="font-weight-bold mb-0">{{ $client->created_at->format('d M Y H:i') }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- WhatsApp Connections --}}
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">WhatsApp Connections</h6>
                <a href="{{ route('owner.whatsapp.index', ['client_id' => $client->id]) }}" 
                   class="btn btn-sm bg-gradient-primary mb-0">
                    <i class="fas fa-cog me-1"></i> Manage
                </a>
            </div>
            <div class="card-body">
                @if($client->whatsappConnections && $client->whatsappConnections->count() > 0)
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Nomor</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Last Sync</th>
                                    <th class="text-secondary opacity-7">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($client->whatsappConnections as $conn)
                                    <tr>
                                        <td class="ps-3">
                                            <span class="text-sm font-weight-bold">{{ $conn->phone_number }}</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm 
                                                @if($conn->status == 'connected') bg-gradient-success
                                                @elseif($conn->status == 'pending') bg-gradient-warning
                                                @else bg-gradient-danger
                                                @endif">
                                                {{ $conn->status }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-xs">
                                                {{ $conn->last_sync_at ? $conn->last_sync_at->diffForHumans() : 'Never' }}
                                            </span>
                                        </td>
                                        <td>
                                            <a href="{{ route('owner.whatsapp.show', $conn) }}" 
                                               class="btn btn-link text-info px-2 mb-0">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-muted text-center py-3 mb-0">Belum ada koneksi WhatsApp</p>
                @endif
            </div>
        </div>

        {{-- Recent Activity --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Activity Log (Terbaru)</h6>
            </div>
            <div class="card-body">
                @if(isset($activities) && $activities->count() > 0)
                    <ul class="list-group">
                        @foreach($activities as $activity)
                            <li class="list-group-item border-0 d-flex justify-content-between ps-0 mb-2">
                                <div class="d-flex align-items-center">
                                    <div class="icon icon-shape icon-xs bg-gradient-primary shadow text-center me-3">
                                        <i class="fas fa-history text-white opacity-10"></i>
                                    </div>
                                    <div>
                                        <h6 class="mb-0 text-sm">{{ $activity->action }}</h6>
                                        <p class="text-xs text-secondary mb-0">{{ $activity->description }}</p>
                                    </div>
                                </div>
                                <span class="text-xs text-muted">{{ $activity->created_at->diffForHumans() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted text-center py-3 mb-0">Belum ada aktivitas</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Right Column - Stats & Actions --}}
    <div class="col-lg-4">
        {{-- Quick Stats --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Statistik</h6>
            </div>
            <div class="card-body">
                <div class="d-flex mb-3">
                    <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md me-3">
                        <i class="fas fa-envelope text-lg opacity-10"></i>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-0">Pesan Terkirim (Bulan ini)</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['messages_sent'] ?? 0) }}</h5>
                    </div>
                </div>
                <div class="d-flex mb-3">
                    <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md me-3">
                        <i class="fas fa-coins text-lg opacity-10"></i>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-0">Saldo</p>
                        <h5 class="font-weight-bolder mb-0">Rp {{ number_format($stats['balance'] ?? 0) }}</h5>
                    </div>
                </div>
                <div class="d-flex">
                    <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md me-3">
                        <i class="fas fa-calendar text-lg opacity-10"></i>
                    </div>
                    <div>
                        <p class="text-xs text-muted mb-0">Paket Berakhir</p>
                        <h5 class="font-weight-bolder mb-0">
                            {{ isset($stats['plan_expires']) ? $stats['plan_expires']->format('d M Y') : 'N/A' }}
                        </h5>
                    </div>
                </div>
            </div>
        </div>

        {{-- Quick Actions --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Aksi Cepat</h6>
            </div>
            <div class="card-body">
                @if($client->status == 'pending')
                    <form action="{{ route('owner.clients.approve', $client) }}" method="POST" class="mb-2">
                        @csrf
                        <button type="submit" class="btn bg-gradient-success w-100 mb-0">
                            <i class="fas fa-check me-2"></i> Approve Klien
                        </button>
                    </form>
                @endif

                @if($client->status == 'aktif')
                    <button type="button" class="btn bg-gradient-danger w-100 mb-2" 
                            data-bs-toggle="modal" data-bs-target="#suspendModal">
                        <i class="fas fa-ban me-2"></i> Suspend Klien
                    </button>
                @endif

                @if($client->status == 'suspend')
                    <form action="{{ route('owner.clients.activate', $client) }}" method="POST" class="mb-2">
                        @csrf
                        <button type="submit" class="btn bg-gradient-success w-100 mb-0">
                            <i class="fas fa-undo me-2"></i> Aktifkan Kembali
                        </button>
                    </form>
                @endif

                <form action="{{ route('owner.clients.reset-quota', $client) }}" method="POST" class="mb-2"
                      id="reset-quota-form"
                      onsubmit="return false;">
                    @csrf
                    <button type="button" class="btn btn-outline-primary w-100 mb-0" onclick="confirmResetQuota()">
                        <i class="fas fa-redo me-2"></i> Reset Quota
                    </button>
                </form>

                <a href="{{ route('owner.logs.activity', ['user_id' => $client->user_id]) }}" 
                   class="btn btn-outline-secondary w-100 mb-0">
                    <i class="fas fa-history me-2"></i> Lihat Log Lengkap
                </a>
            </div>
        </div>

        {{-- Back Button --}}
        <a href="{{ route('owner.clients.index') }}" class="btn btn-outline-dark w-100">
            <i class="fas fa-arrow-left me-2"></i> Kembali ke Daftar
        </a>
    </div>
</div>

{{-- Suspend Modal --}}
<div class="modal fade" id="suspendModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('owner.clients.suspend', $client) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Suspend {{ $client->nama_perusahaan }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning text-white">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Aksi ini akan menonaktifkan semua layanan klien!
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Alasan Suspend <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required 
                                  placeholder="Masukkan alasan suspend..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn bg-gradient-danger">
                        <i class="fas fa-ban me-2"></i> Suspend
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function confirmResetQuota() {
    OwnerPopup.confirmWarning({
        title: 'Reset Quota Klien?',
        text: `
            <p class="mb-2">Anda akan mereset quota untuk:</p>
            <p class="fw-bold mb-3">{{ $client->nama_perusahaan }}</p>
            <div class="alert alert-light border mb-0">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    Quota akan dikembalikan ke nilai default berdasarkan plan aktif.
                </small>
            </div>
        `,
        confirmText: '<i class="fas fa-redo me-1"></i> Ya, Reset Quota',
        onConfirm: () => {
            const form = document.getElementById('reset-quota-form');
            form.onsubmit = null;
            form.submit();
        }
    });
}
</script>
@endpush
