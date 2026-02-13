@extends('layouts.app')

@section('title', 'WA Blast - Kampanye')

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
                            <li class="breadcrumb-item active">Kampanye</li>
                        </ol>
                    </nav>
                    <h4 class="mb-0">WA Blast - Kampanye</h4>
                </div>
                <a href="{{ route('whatsapp.campaigns.create') }}" class="btn btn-success">
                    <i class="fas fa-plus me-2"></i>Buat Kampanye
                </a>
            </div>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
            <div class="card">
                <div class="card-body p-3">
                    <div class="row">
                        <div class="col-8">
                            <div class="numbers">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Kampanye</p>
                                <h5 class="font-weight-bolder mb-0">{{ $campaigns->total() }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                                <i class="fas fa-bullhorn text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Sedang Berjalan</p>
                                <h5 class="font-weight-bolder mb-0">{{ $campaigns->where('status', 'running')->count() }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                                <i class="fas fa-play text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Terjadwal</p>
                                <h5 class="font-weight-bolder mb-0">{{ $campaigns->where('status', 'scheduled')->count() }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                                <i class="fas fa-clock text-lg opacity-10"></i>
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
                            <div class="numbers">
                                <p class="text-sm mb-0 text-capitalize font-weight-bold">Selesai</p>
                                <h5 class="font-weight-bolder mb-0">{{ $campaigns->where('status', 'completed')->count() }}</h5>
                            </div>
                        </div>
                        <div class="col-4 text-end">
                            <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                                <i class="fas fa-check text-lg opacity-10"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Campaigns Table --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Daftar Kampanye</h6>
                </div>
                <div class="card-body px-0 pb-0">
                    @if($campaigns->count() > 0)
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Kampanye</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Template</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Progress</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Biaya</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tanggal</th>
                                    <th class="text-secondary opacity-7"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($campaigns as $campaign)
                                <tr>
                                    <td>
                                        <div class="d-flex px-2 py-1">
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">{{ $campaign->name }}</h6>
                                                <p class="text-xs text-muted mb-0">{{ $campaign->total_recipients }} penerima</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $campaign->template->name ?? 'N/A' }}</span>
                                    </td>
                                    <td>
                                        @switch($campaign->status)
                                            @case('draft')
                                                <span class="badge badge-sm bg-gradient-secondary">Draft</span>
                                                @break
                                            @case('scheduled')
                                                <span class="badge badge-sm bg-gradient-warning">Terjadwal</span>
                                                @break
                                            @case('running')
                                                <span class="badge badge-sm bg-gradient-info">Berjalan</span>
                                                @break
                                            @case('paused')
                                                <span class="badge badge-sm bg-gradient-warning">Dijeda</span>
                                                @break
                                            @case('completed')
                                                <span class="badge badge-sm bg-gradient-success">Selesai</span>
                                                @break
                                            @case('cancelled')
                                                <span class="badge badge-sm bg-gradient-danger">Dibatalkan</span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        @php
                                            $progress = $campaign->total_recipients > 0 
                                                ? round((($campaign->sent_count + $campaign->failed_count) / $campaign->total_recipients) * 100) 
                                                : 0;
                                        @endphp
                                        <div class="d-flex align-items-center">
                                            <span class="me-2 text-xs">{{ $progress }}%</span>
                                            <div class="progress" style="width: 80px; height: 6px;">
                                                <div class="progress-bar bg-gradient-success" role="progressbar" 
                                                     style="width: {{ $progress }}%"></div>
                                            </div>
                                        </div>
                                        <span class="text-xs text-muted">
                                            {{ $campaign->sent_count }} terkirim, {{ $campaign->failed_count }} gagal
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs">Rp {{ number_format($campaign->actual_cost ?: $campaign->estimated_cost, 0, ',', '.') }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $campaign->created_at->format('d M Y') }}</span>
                                    </td>
                                    <td class="align-middle">
                                        <a href="{{ route('whatsapp.campaigns.show', $campaign) }}" 
                                           class="btn btn-link text-secondary mb-0" title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="px-3 py-3">
                        {{ $campaigns->links() }}
                    </div>
                    @else
                    <div class="text-center py-5">
                        <i class="fas fa-bullhorn text-secondary mb-3" style="font-size: 3rem;"></i>
                        <h6>Belum ada kampanye</h6>
                        <p class="text-muted mb-4">Buat kampanye pertama Anda untuk mulai broadcast pesan.</p>
                        <a href="{{ route('whatsapp.campaigns.create') }}" class="btn btn-success">
                            <i class="fas fa-plus me-2"></i>Buat Kampanye
                        </a>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
