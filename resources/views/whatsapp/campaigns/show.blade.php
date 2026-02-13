@extends('layouts.app')

@section('title', 'Detail Kampanye - ' . $campaign->name)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                            <li class="breadcrumb-item"><a href="{{ route('whatsapp.index') }}">WhatsApp</a></li>
                            <li class="breadcrumb-item"><a href="{{ route('whatsapp.campaigns.index') }}">Kampanye</a></li>
                            <li class="breadcrumb-item active">{{ $campaign->name }}</li>
                        </ol>
                    </nav>
                    <h4 class="mb-0">{{ $campaign->name }}</h4>
                </div>
                <div>
                    @if($campaign->canStart())
                        <form action="{{ route('whatsapp.campaigns.start', $campaign) }}" method="POST" class="d-inline" id="startCampaignForm">
                            @csrf
                            <button type="button" class="btn btn-success" onclick="confirmStartCampaign()">
                                <i class="fas fa-play me-2"></i>Mulai Kampanye
                            </button>
                        </form>
                    @elseif($campaign->canPause())
                        <form action="{{ route('whatsapp.campaigns.pause', $campaign) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-pause me-2"></i>Jeda
                            </button>
                        </form>
                    @elseif($campaign->status === 'paused')
                        <form action="{{ route('whatsapp.campaigns.resume', $campaign) }}" method="POST" class="d-inline">
                            @csrf
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-play me-2"></i>Lanjutkan
                            </button>
                        </form>
                    @endif

                    @if($campaign->canCancel())
                        <form action="{{ route('whatsapp.campaigns.cancel', $campaign) }}" method="POST" class="d-inline" id="cancelCampaignForm">
                            @csrf
                            <button type="button" class="btn btn-outline-danger" onclick="confirmCancelCampaign()">
                                <i class="fas fa-times me-2"></i>Batalkan
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Status Badge --}}
    <div class="row mb-4">
        <div class="col-12">
            @switch($campaign->status)
                @case('draft')
                    <span class="badge bg-gradient-secondary px-3 py-2">
                        <i class="fas fa-file me-1"></i>Draft
                    </span>
                    @break
                @case('scheduled')
                    <span class="badge bg-gradient-warning px-3 py-2">
                        <i class="fas fa-clock me-1"></i>Terjadwal: {{ $campaign->scheduled_at->format('d M Y H:i') }}
                    </span>
                    @break
                @case('running')
                    <span class="badge bg-gradient-info px-3 py-2">
                        <i class="fas fa-spinner fa-spin me-1"></i>Sedang Berjalan
                    </span>
                    @break
                @case('paused')
                    <span class="badge bg-gradient-warning px-3 py-2">
                        <i class="fas fa-pause me-1"></i>Dijeda
                    </span>
                    @break
                @case('completed')
                    <span class="badge bg-gradient-success px-3 py-2">
                        <i class="fas fa-check me-1"></i>Selesai
                    </span>
                    @break
                @case('cancelled')
                    <span class="badge bg-gradient-danger px-3 py-2">
                        <i class="fas fa-times me-1"></i>Dibatalkan
                    </span>
                    @break
            @endswitch
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="row mb-4">
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0">{{ number_format($stats['total']) }}</h3>
                    <p class="text-sm text-muted mb-0">Total Penerima</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0 text-info">{{ number_format($stats['sent']) }}</h3>
                    <p class="text-sm text-muted mb-0">Terkirim</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0 text-success">{{ number_format($stats['delivered']) }}</h3>
                    <p class="text-sm text-muted mb-0">Terdelivery</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0 text-primary">{{ number_format($stats['read']) }}</h3>
                    <p class="text-sm text-muted mb-0">Dibaca</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0 text-danger">{{ number_format($stats['failed']) }}</h3>
                    <p class="text-sm text-muted mb-0">Gagal</p>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-sm-4">
            <div class="card">
                <div class="card-body p-3 text-center">
                    <h3 class="mb-0 text-warning">{{ number_format($stats['pending']) }}</h3>
                    <p class="text-sm text-muted mb-0">Pending</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Progress & Details --}}
        <div class="col-lg-8">
            {{-- Progress --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Progress Pengiriman</h6>
                </div>
                <div class="card-body">
                    @php
                        $progress = $stats['total'] > 0 
                            ? round((($stats['sent'] + $stats['failed']) / $stats['total']) * 100) 
                            : 0;
                    @endphp
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-sm">{{ $progress }}% Complete</span>
                        <span class="text-sm">{{ $stats['sent'] + $stats['failed'] }} / {{ $stats['total'] }}</span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar bg-success" role="progressbar" 
                             style="width: {{ $stats['total'] > 0 ? ($stats['delivered'] / $stats['total'] * 100) : 0 }}%"
                             title="Delivered">
                        </div>
                        <div class="progress-bar bg-info" role="progressbar" 
                             style="width: {{ $stats['total'] > 0 ? (($stats['sent'] - $stats['delivered']) / $stats['total'] * 100) : 0 }}%"
                             title="Sent">
                        </div>
                        <div class="progress-bar bg-danger" role="progressbar" 
                             style="width: {{ $stats['total'] > 0 ? ($stats['failed'] / $stats['total'] * 100) : 0 }}%"
                             title="Failed">
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-2">
                        <small><span class="badge bg-success">&nbsp;</span> Delivered</small>
                        <small><span class="badge bg-info">&nbsp;</span> Sent</small>
                        <small><span class="badge bg-danger">&nbsp;</span> Failed</small>
                    </div>
                </div>
            </div>

            {{-- Template Info --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Template Pesan</h6>
                </div>
                <div class="card-body">
                    @if($campaign->template)
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1">{{ $campaign->template->name }}</h6>
                                <span class="badge badge-sm bg-gradient-secondary">{{ $campaign->template->category }}</span>
                                <span class="badge badge-sm bg-gradient-info">{{ strtoupper($campaign->template->language) }}</span>
                            </div>
                        </div>
                        @if($campaign->template->sample_text)
                            <div class="alert alert-light border mt-3 mb-0">
                                <p class="mb-0 text-sm">{{ $campaign->template->sample_text }}</p>
                            </div>
                        @endif
                    @else
                        <p class="text-muted mb-0">Template tidak tersedia</p>
                    @endif
                </div>
            </div>

            {{-- Failed Recipients --}}
            @if($stats['failed'] > 0)
            <div class="card">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Penerima Gagal</h6>
                        <p class="text-xs text-muted mb-0">{{ $stats['failed'] }} pesan gagal terkirim</p>
                    </div>
                    @if($campaign->status !== 'running')
                    <form action="{{ route('whatsapp.campaigns.retry-failed', $campaign) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="fas fa-redo me-1"></i>Coba Ulang Semua
                        </button>
                    </form>
                    @endif
                </div>
                <div class="card-body px-0 pb-0">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Kontak</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Error</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($campaign->recipients()->failed()->with('contact')->limit(10)->get() as $recipient)
                                <tr>
                                    <td>
                                        <div class="d-flex px-2 py-1">
                                            <div>
                                                <h6 class="mb-0 text-sm">{{ $recipient->contact->name ?? 'N/A' }}</h6>
                                                <p class="text-xs text-muted mb-0">{{ $recipient->phone_number }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-xs text-danger">
                                            {{ $recipient->error_code }}: {{ Str::limit($recipient->error_message, 50) }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $recipient->failed_at?->format('d M H:i') }}</span>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Campaign Info --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Informasi Kampanye</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <p class="text-xs text-muted mb-1">Dibuat</p>
                        <p class="mb-0">{{ $campaign->created_at->format('d M Y H:i') }}</p>
                    </div>
                    @if($campaign->started_at)
                    <div class="mb-3">
                        <p class="text-xs text-muted mb-1">Dimulai</p>
                        <p class="mb-0">{{ $campaign->started_at->format('d M Y H:i') }}</p>
                    </div>
                    @endif
                    @if($campaign->completed_at)
                    <div class="mb-3">
                        <p class="text-xs text-muted mb-1">Selesai</p>
                        <p class="mb-0">{{ $campaign->completed_at->format('d M Y H:i') }}</p>
                    </div>
                    @endif
                    <div class="mb-3">
                        <p class="text-xs text-muted mb-1">Rate Limit</p>
                        <p class="mb-0">{{ $campaign->rate_limit_per_second }} pesan/detik</p>
                    </div>
                    @if($campaign->description)
                    <div>
                        <p class="text-xs text-muted mb-1">Deskripsi</p>
                        <p class="mb-0 text-sm">{{ $campaign->description }}</p>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Rates --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Tingkat Keberhasilan</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-sm">Delivery Rate</span>
                        <span class="font-weight-bold">{{ $campaign->delivery_rate }}%</span>
                    </div>
                    <div class="progress mb-4" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: {{ $campaign->delivery_rate }}%"></div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-sm">Read Rate</span>
                        <span class="font-weight-bold">{{ $campaign->read_rate }}%</span>
                    </div>
                    <div class="progress mb-4" style="height: 8px;">
                        <div class="progress-bar bg-primary" style="width: {{ $campaign->read_rate }}%"></div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-sm">Failure Rate</span>
                        <span class="font-weight-bold text-danger">{{ $campaign->failure_rate }}%</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-danger" style="width: {{ $campaign->failure_rate }}%"></div>
                    </div>
                </div>
            </div>

            {{-- Cost --}}
            <div class="card bg-gradient-dark">
                <div class="card-body">
                    <div class="text-white">
                        <p class="text-sm mb-1 opacity-8">Total Biaya</p>
                        <h3 class="text-white mb-0">
                            Rp {{ number_format($campaign->actual_cost ?: $campaign->estimated_cost, 0, ',', '.') }}
                        </h3>
                        @if($campaign->actual_cost > 0)
                            <p class="text-xs opacity-8 mb-0">Biaya aktual</p>
                        @else
                            <p class="text-xs opacity-8 mb-0">Estimasi biaya</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($campaign->status === 'running')
@push('scripts')
<script>
// Auto-refresh stats every 5 seconds while campaign is running
setInterval(function() {
    fetch('{{ route("whatsapp.campaigns.stats", $campaign) }}')
        .then(response => response.json())
        .then(data => {
            // Update stats - you can implement more granular updates
            if (data.status !== 'running') {
                window.location.reload();
            }
        });
}, 5000);
</script>
@endpush
@endif

@push('scripts')
<script>
// Campaign confirmation functions with friendly popups
async function confirmStartCampaign() {
    const confirmed = await ClientPopup.confirm({
        title: 'Mulai Kampanye?',
        text: 'Kampanye akan segera dikirim ke semua penerima yang terdaftar.',
        confirmText: 'Ya, Mulai Sekarang',
        cancelText: 'Nanti Saja'
    });
    
    if (confirmed) {
        document.getElementById('startCampaignForm').submit();
    }
}

async function confirmCancelCampaign() {
    const confirmed = await ClientPopup.confirm({
        title: 'Batalkan Kampanye?',
        text: 'Kampanye akan dihentikan. Pesan yang sudah terkirim tidak terpengaruh.',
        confirmText: 'Ya, Batalkan',
        cancelText: 'Tidak Jadi'
    });
    
    if (confirmed) {
        document.getElementById('cancelCampaignForm').submit();
    }
}
</script>
@endpush
@endsection
