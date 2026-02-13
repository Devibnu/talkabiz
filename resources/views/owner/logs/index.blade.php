@extends('owner.layouts.app')

@section('page-title', 'Logs & Audit')

@section('content')
{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-8">
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Activity Logs</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['activity_total'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
                            <i class="fas fa-history text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Webhooks Hari Ini</p>
                        <h5 class="font-weight-bolder mb-0">{{ number_format($stats['webhooks_today'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md">
                            <i class="fas fa-plug text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Failed Webhooks</p>
                        <h5 class="font-weight-bolder mb-0 text-danger">{{ number_format($stats['webhooks_failed'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-exclamation-triangle text-lg opacity-10"></i>
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
                        <p class="text-sm mb-0 text-capitalize font-weight-bold">Pesan Hari Ini</p>
                        <h5 class="font-weight-bolder mb-0 text-success">{{ number_format($stats['messages_today'] ?? 0) }}</h5>
                    </div>
                    <div class="col-4 text-end">
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-envelope text-lg opacity-10"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Quick Links --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-3">
                <div class="row">
                    <div class="col-md-3">
                        <a href="{{ route('owner.logs.activity') }}" class="btn bg-gradient-primary w-100 mb-0">
                            <i class="fas fa-history me-2"></i> Activity Logs
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="{{ route('owner.logs.webhooks') }}" class="btn bg-gradient-info w-100 mb-0">
                            <i class="fas fa-plug me-2"></i> Webhook Logs
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="{{ route('owner.logs.messages') }}" class="btn bg-gradient-success w-100 mb-0">
                            <i class="fas fa-envelope me-2"></i> Message Logs
                        </a>
                    </div>
                    <div class="col-md-3">
                        <a href="#" class="btn btn-outline-secondary w-100 mb-0">
                            <i class="fas fa-download me-2"></i> Export Logs
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Recent Activity --}}
<div class="row">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Activity Log Terbaru</h6>
                <a href="{{ route('owner.logs.activity') }}" class="btn btn-sm btn-outline-primary mb-0">
                    Lihat Semua
                </a>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table align-items-center mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">User</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activityLogs as $log)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold">{{ $log->user?->name ?? 'System' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ Str::limit($log->action, 30) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-muted">{{ $log->created_at->diffForHumans() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center py-4">
                                        <p class="text-muted mb-0">Tidak ada log</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Webhook Log Terbaru</h6>
                <a href="{{ route('owner.logs.webhooks') }}" class="btn btn-sm btn-outline-primary mb-0">
                    Lihat Semua
                </a>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0" style="max-height: 400px; overflow-y: auto;">
                    <table class="table align-items-center mb-0">
                        <thead class="sticky-top bg-white">
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Event</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($webhookLogs as $log)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold">{{ $log->event_type }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm 
                                            @if($log->status == 'success') bg-gradient-success
                                            @else bg-gradient-danger
                                            @endif">
                                            {{ $log->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-muted">{{ $log->created_at->diffForHumans() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center py-4">
                                        <p class="text-muted mb-0">Tidak ada log</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Message Log Preview --}}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Message Log Terbaru</h6>
                <a href="{{ route('owner.logs.messages') }}" class="btn btn-sm btn-outline-primary mb-0">
                    Lihat Semua
                </a>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Klien</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Nomor Tujuan</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tipe</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($messageLogs as $log)
                                <tr>
                                    <td class="ps-3">
                                        <span class="text-xs font-weight-bold">
                                            {{ $log->client?->nama_perusahaan ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $log->recipient_number }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm bg-gradient-secondary">{{ $log->message_type }}</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm 
                                            @if($log->status == 'sent' || $log->status == 'delivered') bg-gradient-success
                                            @elseif($log->status == 'pending') bg-gradient-warning
                                            @else bg-gradient-danger
                                            @endif">
                                            {{ $log->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-xs text-muted">{{ $log->created_at->diffForHumans() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <p class="text-muted mb-0">Tidak ada log pesan</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
