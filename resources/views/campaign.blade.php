@extends('layouts.user_type.auth')

@push('styles')
<style>
/* ============================================
   TALKABIZ CAMPAIGN - Soft UI Style
   ============================================ */

/* Page Header */
.page-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}

.page-subtitle {
    font-size: 0.875rem;
    color: #67748e;
    margin: 0.25rem 0 0 0;
}

/* Primary Button - Soft UI Style */
.btn-soft-primary {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border: none;
    padding: 0.625rem 1.25rem;
    font-size: 0.8125rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4), 0 2px 4px -1px rgba(94, 114, 228, 0.25);
}

.btn-soft-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 10px -2px rgba(94, 114, 228, 0.5), 0 3px 6px -2px rgba(94, 114, 228, 0.3);
    color: #fff;
}

/* Campaign Card */
.campaign-card {
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 8px 26px -4px hsla(0,0%,8%,.15), 0 8px 9px -5px hsla(0,0%,8%,.06);
    overflow: hidden;
}

.campaign-card-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.campaign-card-title {
    font-size: 1rem;
    font-weight: 700;
    color: #344767;
    margin: 0;
}

.campaign-card-body {
    padding: 1.5rem;
}

/* Empty State */
.empty-state-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 2rem;
    text-align: center;
}

.empty-state-icon {
    width: 6rem;
    height: 6rem;
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    border-radius: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
    box-shadow: 0 8px 16px -4px rgba(94, 114, 228, 0.4);
}

.empty-state-icon i {
    font-size: 2.5rem;
    color: #fff;
}

.empty-state-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #344767;
    margin-bottom: 0.5rem;
}

.empty-state-subtitle {
    font-size: 0.9375rem;
    color: #67748e;
    margin-bottom: 1.5rem;
    max-width: 320px;
}

.empty-state-btn {
    background: linear-gradient(310deg, #5e72e4 0%, #825ee4 100%);
    color: #fff;
    border: none;
    padding: 0.75rem 1.5rem;
    font-size: 0.875rem;
    font-weight: 700;
    border-radius: 0.5rem;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    text-decoration: none;
    box-shadow: 0 4px 7px -1px rgba(94, 114, 228, 0.4);
}

.empty-state-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px -4px rgba(94, 114, 228, 0.5);
    color: #fff;
}

/* Campaign Table */
.campaign-table-container {
    overflow-x: auto;
}

.campaign-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    table-layout: fixed;
}

.campaign-table thead th {
    padding: 0.875rem 1rem;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05rem;
    color: #8392ab;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    white-space: nowrap;
}

.campaign-table thead th:first-child {
    border-radius: 0.5rem 0 0 0;
    width: 30%;
}

.campaign-table thead th:nth-child(2) {
    width: 15%;
}

.campaign-table thead th:nth-child(3) {
    width: 14%;
}

.campaign-table thead th:nth-child(4),
.campaign-table thead th:nth-child(5) {
    width: 10%;
    text-align: center;
}

.campaign-table thead th:last-child {
    border-radius: 0 0.5rem 0 0;
    width: 21%;
}

.campaign-table tbody td {
    padding: 0.875rem 1rem;
    font-size: 0.875rem;
    color: #344767;
    border-bottom: 1px solid #e9ecef;
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.campaign-table tbody td:nth-child(4),
.campaign-table tbody td:nth-child(5) {
    text-align: center;
}

.campaign-table tbody tr:last-child td {
    border-bottom: none;
}

.campaign-table tbody tr:hover {
    background: #f8f9fa;
}

/* Campaign Status Badge */
.campaign-status {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    border-radius: 0.375rem;
}

.campaign-status.draft {
    background: linear-gradient(310deg, #e9ecef 0%, #f0f2f5 100%);
    color: #67748e;
}

.campaign-status.scheduled {
    background: linear-gradient(310deg, #fbcf33 0%, #fcd34d 100%);
    color: #92400e;
}

.campaign-status.sent,
.campaign-status.completed {
    background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%);
    color: #fff;
}

.campaign-status.running {
    background: linear-gradient(310deg, #2152ff 0%, #21d4fd 100%);
    color: #fff;
}

.campaign-status.failed,
.campaign-status.cancelled {
    background: linear-gradient(310deg, #ea0606 0%, #f87171 100%);
    color: #fff;
}

.campaign-status.paused {
    background: linear-gradient(310deg, #627594 0%, #a8b8d8 100%);
    color: #fff;
}

/* Action Buttons */
.btn-action-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    border-radius: 0.375rem;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.btn-action-sm.btn-view {
    background: #f8f9fa;
    color: #67748e;
    border: 1px solid #e9ecef;
}

.btn-action-sm.btn-view:hover {
    background: #e9ecef;
    color: #344767;
}

.btn-action-sm.btn-view:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
@endpush

@section('content')
@php
    $user = auth()->user();
    $quotaInfo = app(\App\Services\PlanLimitService::class)->getQuotaInfo($user);
@endphp

<div class="container-fluid py-4">
    {{-- Flash Messages --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    @endif

    {{-- Impersonation View-Only Banner --}}
    @if($__isImpersonating ?? false)
    <div class="alert alert-info border-0 shadow-sm mb-4" style="background: linear-gradient(310deg, #e8f4fd 0%, #f0e8fd 100%); border-left: 4px solid #5e72e4 !important;">
        <div class="d-flex align-items-center">
            <i class="fas fa-eye me-3 text-primary" style="font-size: 1.25rem;"></i>
            <div>
                <strong class="text-dark">Mode Lihat Saja</strong>
                <p class="text-sm text-secondary mb-0">Anda sedang melihat halaman campaign milik <strong>{{ $__impersonationMeta['client_name'] ?? 'Klien' }}</strong>. Aksi pembuatan campaign dinonaktifkan.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Upgrade Nudge (non-intrusive) --}}
    @if(!($__isImpersonating ?? false))
        @include('components.upgrade-nudge', ['quotaInfo' => $quotaInfo])
    @endif
    
    {{-- Page Header --}}
    <div class="page-header-row">
        <div>
            <h4 class="page-title">Campaign</h4>
            <p class="page-subtitle">Kelola campaign WhatsApp Anda</p>
        </div>
        
        @if($__isImpersonating ?? false)
            {{-- Impersonation: No action buttons --}}
        @elseif($subscriptionIsActive ?? false)
            {{-- Subscription Gate → SaldoGuard Protection untuk Buat Campaign --}}
            @include('components.saldo-guard', [
                'requiredMessages' => 10,
                'actionText' => 'membuat campaign',
                'ctaText' => 'Buat Campaign',
                'ctaIcon' => 'ni ni-fat-add',
                'ctaClass' => 'btn-soft-primary',
                'ctaAttributes' => 'id="btnCreateCampaign" data-bs-toggle="modal" data-bs-target="#createCampaignModal"'
            ])
        @else
            {{-- HIDDEN: Campaign button removed, show activate CTA --}}
            <a href="{{ route('subscription.index') }}" class="btn bg-gradient-primary btn-sm"
               onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('clicked_pay', {source: 'campaign_header'});">
                <i class="fas fa-bolt me-1"></i> Aktifkan Paket
            </a>
        @endif
    </div>

    <div class="row">
        <div class="col-lg-9">
            {{-- Campaign Card --}}
            <div class="campaign-card">
                <div class="campaign-card-header">
                    <h6 class="campaign-card-title">Daftar Campaign</h6>
                </div>
                <div class="campaign-card-body">
                    @if(($campaigns ?? collect())->count() > 0)
                    {{-- Campaign Table --}}
                    <div class="campaign-table-container">
                        <table class="campaign-table">
                            <thead>
                                <tr>
                                    <th>Nama Campaign</th>
                                    <th>Template</th>
                                    <th>Status</th>
                                    <th>Penerima</th>
                                    <th>Terkirim</th>
                                    <th>Dibuat</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($campaigns as $campaign)
                                <tr>
                                    <td title="{{ $campaign->name }}"><strong>{{ $campaign->name }}</strong></td>
                                    <td title="{{ $campaign->template->name ?? '-' }}">{{ $campaign->template->name ?? '-' }}</td>
                                    <td>
                                        <span class="campaign-status {{ $campaign->status }}">
                                            {{ ucfirst($campaign->status) }}
                                        </span>
                                    </td>
                                    <td>{{ number_format($campaign->total_recipients, 0, ',', '.') }}</td>
                                    <td>{{ number_format($campaign->sent_count, 0, ',', '.') }}</td>
                                    <td>{{ $campaign->created_at->format('d M Y H:i') }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    {{-- Empty State --}}
                    <div class="empty-state-container" id="campaignEmptyState">
                        <div class="empty-state-icon">
                            <i class="ni ni-send"></i>
                        </div>
                        <h5 class="empty-state-title">Belum ada campaign</h5>
                        <p class="empty-state-subtitle">
                            @if($__isImpersonating ?? false)
                                Klien ini belum memiliki campaign WhatsApp
                            @else
                                Buat campaign WhatsApp pertama Anda untuk menjangkau pelanggan
                            @endif
                        </p>
                        
                        @if($__isImpersonating ?? false)
                            {{-- Impersonation: No action buttons --}}
                        @elseif($subscriptionIsActive ?? false)
                            {{-- Subscription Gate → SaldoGuard Protection untuk Empty State Button --}}
                            @include('components.saldo-guard', [
                                'requiredMessages' => 10,
                                'actionText' => 'membuat campaign',
                                'ctaText' => 'Buat Campaign',
                                'ctaIcon' => 'ni ni-fat-add',
                                'ctaClass' => 'empty-state-btn',
                                'ctaAttributes' => 'data-bs-toggle="modal" data-bs-target="#createCampaignModal"'
                            ])
                        @else
                            {{-- HIDDEN: Show activation prompt instead of dead button --}}
                            <div class="text-center">
                                <p class="text-sm text-secondary mb-2">Aktifkan paket untuk mulai membuat campaign</p>
                                <a href="{{ route('subscription.index') }}" class="empty-state-btn"
                                   onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('clicked_pay', {source: 'campaign_empty'});">
                                    <i class="fas fa-bolt me-1"></i> Aktifkan Paket
                                </a>
                            </div>
                        @endif
                    </div>
                    @endif
                </div>
            </div>
        </div>
        
        {{-- Sidebar: Delivery Tips --}}
        <div class="col-lg-3">
            @include('components.delivery-tips', ['tier' => $user->currentPlan?->code ?? 'starter'])
            
            {{-- Quota Summary Card --}}
            @if(isset($quotaInfo['monthly']))
            <div class="card border-0 shadow-sm">
                <div class="card-header pb-0 pt-3 bg-transparent">
                    <h6 class="font-weight-bolder mb-0">Sisa Kuota</h6>
                </div>
                <div class="card-body pt-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-xs text-secondary">Bulanan</span>
                        <span class="text-sm font-weight-bold">
                            @if($quotaInfo['monthly']['remaining'] >= PHP_INT_MAX)
                                Unlimited
                            @else
                                {{ number_format($quotaInfo['monthly']['remaining'], 0, ',', '.') }} / {{ is_numeric($quotaInfo['monthly']['limit']) ? number_format($quotaInfo['monthly']['limit'], 0, ',', '.') : $quotaInfo['monthly']['limit'] }}
                            @endif
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-xs text-secondary">Harian</span>
                        <span class="text-sm font-weight-bold">
                            @if($quotaInfo['daily']['remaining'] >= PHP_INT_MAX)
                                Unlimited
                            @else
                                {{ number_format($quotaInfo['daily']['remaining'], 0, ',', '.') }} / {{ is_numeric($quotaInfo['daily']['limit']) ? number_format($quotaInfo['daily']['limit'], 0, ',', '.') : $quotaInfo['daily']['limit'] }}
                            @endif
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-xs text-secondary">Campaign Aktif</span>
                        <span class="text-sm font-weight-bold">{{ $quotaInfo['campaigns']['active'] }} / {{ $quotaInfo['campaigns']['limit'] }}</span>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- Create Campaign Modal (only for non-impersonation) --}}
@if(!($__isImpersonating ?? false))
<div class="modal fade" id="createCampaignModal" tabindex="-1" aria-labelledby="createCampaignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 1rem; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid #e9ecef; padding: 1.25rem 1.5rem;">
                <h5 class="modal-title" id="createCampaignModalLabel" style="font-weight: 700; color: #344767;">Buat Campaign Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <form id="createCampaignForm" action="{{ route('campaign.store') }}" method="POST">
                    @csrf
                    {{-- Nama Campaign --}}
                    <div class="mb-3">
                        <label class="form-label" style="font-size: 0.875rem; font-weight: 600; color: #344767;">Nama Campaign</label>
                        <input type="text" name="name" class="form-control" placeholder="Contoh: Promo Akhir Tahun 2026" required style="border-radius: 0.5rem; border: 1px solid #e9ecef; padding: 0.75rem 1rem;">
                    </div>

                    {{-- Template Pesan --}}
                    <div class="mb-3">
                        <label class="form-label" style="font-size: 0.875rem; font-weight: 600; color: #344767;">Template Pesan</label>
                        <select name="template_id" class="form-select" required style="border-radius: 0.5rem; border: 1px solid #e9ecef; padding: 0.75rem 1rem;">
                            <option value="">Pilih template pesan...</option>
                            @foreach($templates ?? [] as $tpl)
                                <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->category ?? 'utility' }})</option>
                            @endforeach
                        </select>
                        <small class="text-muted" style="font-size: 0.75rem;">Template harus disetujui Meta/Gupshup terlebih dahulu</small>
                    </div>

                    {{-- Target Audience --}}
                    <div class="mb-3">
                        <label class="form-label" style="font-size: 0.875rem; font-weight: 600; color: #344767;">Target Audience</label>
                        <select name="audience" class="form-select" required style="border-radius: 0.5rem; border: 1px solid #e9ecef; padding: 0.75rem 1rem;">
                            <option value="">Pilih target...</option>
                            <option value="all">Semua Kontak ({{ count($kontaks ?? []) }})</option>
                            @foreach($tags ?? [] as $tag)
                                <option value="tag:{{ $tag }}">Tag: {{ $tag }} ({{ ($kontaks ?? collect())->filter(fn($k) => in_array($tag, $k->tags ?? []))->count() }})</option>
                            @endforeach
                        </select>
                        <small class="text-muted" style="font-size: 0.75rem;">Belum ada kontak? <a href="{{ route('kontak') }}" style="color: #5e72e4;">Import kontak</a></small>
                    </div>

                    {{-- Jadwal Kirim --}}
                    <div class="mb-3">
                        <label class="form-label" style="font-size: 0.875rem; font-weight: 600; color: #344767;">Jadwal Kirim</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_type" id="scheduleNow" value="now" checked>
                                <label class="form-check-label" for="scheduleNow" style="font-size: 0.875rem;">Simpan Draft</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="schedule_type" id="scheduleLater" value="later">
                                <label class="form-check-label" for="scheduleLater" style="font-size: 0.875rem;">Jadwalkan</label>
                            </div>
                        </div>
                    </div>

                    {{-- Datetime Picker (hidden by default) --}}
                    <div class="mb-3" id="scheduleDatetimeContainer" style="display: none;">
                        <input type="datetime-local" name="scheduled_at" class="form-control" style="border-radius: 0.5rem; border: 1px solid #e9ecef; padding: 0.75rem 1rem;">
                    </div>
            </div>
                </form>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e9ecef; padding: 1rem 1.5rem;">
                <button type="button" class="btn" data-bs-dismiss="modal" style="background: #f8f9fa; color: #67748e; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 0.625rem 1.25rem; font-weight: 600;">Batal</button>
                <button type="submit" form="createCampaignForm" class="btn-soft-primary">
                    <i class="ni ni-send"></i>
                    <span>Buat Campaign</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@push('dashboard')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle schedule datetime
    const scheduleNow = document.getElementById('scheduleNow');
    const scheduleLater = document.getElementById('scheduleLater');
    const datetimeContainer = document.getElementById('scheduleDatetimeContainer');

    if (scheduleNow && scheduleLater) {
        scheduleNow.addEventListener('change', function() {
            datetimeContainer.style.display = 'none';
        });
        
        scheduleLater.addEventListener('change', function() {
            datetimeContainer.style.display = 'block';
        });
    }
});
</script>
@endpush