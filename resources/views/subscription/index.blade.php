@extends('layouts.user_type.auth')

@section('content')
<style>
.plan-checkout-card { transition: transform 0.2s, box-shadow 0.2s; }
.plan-checkout-card:hover { transform: translateY(-4px); box-shadow: 0 8px 26px rgba(0,0,0,.1) !important; }
.plan-checkout-card.current-plan { border: 2px solid #cb0c9f; }
.checkout-price { font-size: 1.5rem; font-weight: 800; color: #344767; }
.checkout-modal .modal-content { border-radius: 16px; overflow: hidden; }
.checkout-modal .modal-header { background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%); color: #fff; border: 0; }
.checkout-modal .modal-header .btn-close { filter: brightness(0) invert(1); }
.checkout-summary { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
.checkout-summary .plan-name { font-size: 1.2rem; font-weight: 700; color: #344767; }
.checkout-price-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
.checkout-price-row.total { border-bottom: 0; border-top: 2px solid #344767; font-weight: 700; font-size: 1.1rem; margin-top: 8px; padding-top: 12px; }
.active-warning-banner { background: linear-gradient(310deg, #fbb140 0%, #f5365c 100%); border-radius: 12px; }
</style>

{{-- Page Badge --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="d-inline-flex align-items-center px-3 py-2 rounded-pill shadow-sm" style="background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%);">
            <i class="fas fa-file-invoice-dollar text-white me-2"></i>
            <span class="text-white text-xs font-weight-bold text-uppercase letter-spacing-1">Subscription &mdash; Biaya Akses Sistem</span>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="font-weight-bolder mb-0">Paket & Langganan</h5>
                <p class="text-sm text-secondary mb-0">Kelola paket berlangganan dan lihat status aktif Anda.</p>
            </div>
        </div>
    </div>
</div>

{{-- ============================================================ --}}
{{-- ACTIVE WARNING BANNER                                         --}}
{{-- Tampil jika plan_status == active AND expires_at > now()      --}}
{{-- ============================================================ --}}
@if($showActiveWarning && $planExpiresAt)
<div class="row mb-3">
    <div class="col-12">
        <div class="active-warning-banner p-3 shadow-sm">
            <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-triangle text-white me-3 mt-1 fs-5"></i>
                <div>
                    <p class="text-white text-sm font-weight-bold mb-1">Paket Anda masih aktif hingga {{ $planExpiresAt->format('d M Y') }}</p>
                    <p class="text-white text-xs mb-0 opacity-8">Jika Anda upgrade sekarang, sisa masa aktif tidak akan otomatis digabung kecuali menggunakan sistem prorate.</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ============================================================ --}}
{{-- STATUS CARD                                                    --}}
{{-- ============================================================ --}}
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Status Paket</h6>
                <span class="badge bg-gradient-{{ $statusBadge }}">
                    @if($planStatus === 'trial_selected')
                        <i class="fas fa-clock me-1"></i>
                    @elseif($planStatus === 'active')
                        <i class="fas fa-check-circle me-1"></i>
                    @elseif($planStatus === 'expired')
                        <i class="fas fa-times-circle me-1"></i>
                    @endif
                    {{ $statusLabel }}
                </span>
            </div>
            <div class="card-body">
                @if($currentPlan)
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-3">
                                <div class="icon icon-shape icon-sm bg-gradient-primary shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                                    <i class="fas fa-crown text-white text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-secondary mb-0">Nama Paket</p>
                                    <h6 class="mb-0">{{ $currentPlan->name }}</h6>
                                </div>
                            </div>
                            <div class="d-flex align-items-center mb-3">
                                <div class="icon icon-shape icon-sm bg-gradient-info shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                                    <i class="fas fa-calendar-alt text-white text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-secondary mb-0">Berlaku Sampai</p>
                                    <h6 class="mb-0">
                                        @if($planStatus === 'trial_selected')
                                            <span class="text-warning">Menunggu Pembayaran</span>
                                        @elseif($planExpiresAt)
                                            {{ $planExpiresAt->format('d M Y') }}
                                        @else
                                            Unlimited
                                        @endif
                                    </h6>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center mb-3">
                                <div class="icon icon-shape icon-sm bg-gradient-warning shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                                    <i class="fas fa-hourglass-half text-white text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-secondary mb-0">Sisa Hari</p>
                                    <h6 class="mb-0 {{ $daysRemaining <= 7 && $daysRemaining > 0 ? 'text-warning' : ($daysRemaining <= 0 ? 'text-danger' : '') }}">
                                        @if($planStatus === 'trial_selected')
                                            <span class="text-warning">Belum Aktif</span>
                                        @elseif($daysRemaining === 999)
                                            Unlimited
                                        @elseif($daysRemaining <= 0)
                                            Expired
                                        @else
                                            {{ $daysRemaining }} hari
                                        @endif
                                    </h6>
                                </div>
                            </div>
                            <div class="d-flex align-items-center mb-3">
                                <div class="icon icon-shape icon-sm bg-gradient-success shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                                    <i class="fas fa-tags text-white text-sm"></i>
                                </div>
                                <div>
                                    <p class="text-xs text-secondary mb-0">Harga</p>
                                    <h6 class="mb-0">Rp {{ number_format($currentPlan->price_monthly, 0, ',', '.') }}/bulan</h6>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ============================================== --}}
                    {{-- ACTION BUTTONS — berdasarkan planStatus dari DB --}}
                    {{-- ============================================== --}}
                    <div class="d-flex flex-wrap gap-2 mt-3">

                        {{-- TRIAL_SELECTED: belum bayar --}}
                        @if($planStatus === 'trial_selected')
                            <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center w-100">
                                <i class="fas fa-info-circle me-2"></i>
                                <span class="text-sm">Anda sudah memilih paket <strong>{{ $currentPlan->name }}</strong>, tetapi belum melakukan pembayaran.</span>
                            </div>
                            <button class="btn btn-sm bg-gradient-primary mb-0"
                                    onclick="openCheckoutModal('{{ $currentPlan->code }}', '{{ $currentPlan->name }}', {{ (int) $currentPlan->price_monthly }}, {{ $currentPlan->duration_days }})">
                                <i class="fas fa-credit-card me-1"></i> Bayar Paket Ini
                            </button>
                            <button class="btn btn-sm btn-outline-secondary mb-0" onclick="scrollToPlans()">
                                <i class="fas fa-exchange-alt me-1"></i> Ganti Paket
                            </button>

                        {{-- ACTIVE: sudah bayar, masih aktif --}}
                        @elseif($planStatus === 'active')
                            <button class="btn btn-sm bg-gradient-success mb-0"
                                    onclick="openCheckoutModal('{{ $currentPlan->code }}', '{{ $currentPlan->name }}', {{ (int) $currentPlan->price_monthly }}, {{ $currentPlan->duration_days }})">
                                <i class="fas fa-sync-alt me-1"></i> Perpanjang Paket
                            </button>
                            <button class="btn btn-sm btn-outline-primary mb-0" onclick="confirmUpgrade()">
                                <i class="fas fa-arrow-circle-up me-1"></i> Upgrade / Ganti Paket
                            </button>

                        {{-- EXPIRED: sudah bayar tapi habis --}}
                        @elseif($planStatus === 'expired')
                            <div class="alert alert-danger py-2 px-3 mb-0 d-flex align-items-center w-100">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <span class="text-sm">Masa aktif paket Anda telah berakhir. Aktifkan kembali untuk melanjutkan.</span>
                            </div>
                            <button class="btn btn-sm bg-gradient-danger mb-0"
                                    onclick="openCheckoutModal('{{ $currentPlan->code }}', '{{ $currentPlan->name }}', {{ (int) $currentPlan->price_monthly }}, {{ $currentPlan->duration_days }})">
                                <i class="fas fa-bolt me-1"></i> Aktifkan Kembali
                            </button>
                            <button class="btn btn-sm btn-outline-primary mb-0" onclick="scrollToPlans()">
                                <i class="fas fa-arrow-circle-up me-1"></i> Upgrade Paket
                            </button>
                        @endif

                    </div>
                @else
                    {{-- BELUM PILIH PAKET SAMA SEKALI --}}
                    <div class="text-center py-4">
                        <div class="icon icon-shape icon-lg bg-gradient-secondary shadow text-center border-radius-md mx-auto mb-3 d-flex align-items-center justify-content-center">
                            <i class="fas fa-box-open text-white"></i>
                        </div>
                        <h6 class="text-secondary">Belum Ada Paket</h6>
                        <p class="text-sm text-secondary mb-3">Pilih paket di bawah untuk mulai menggunakan fitur WhatsApp Blast.</p>
                        <button class="btn btn-sm bg-gradient-primary" onclick="scrollToPlans()">
                            <i class="fas fa-shopping-cart me-1"></i> Pilih Paket
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Quick Stats --}}
    <div class="col-lg-4">
        {{-- Plan Features Card --}}
        @if($currentPlan)
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Fitur Paket</h6>
                </div>
                <div class="card-body pt-2">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item border-0 d-flex justify-content-between px-0 py-2">
                            <span class="text-sm">Nomor WA</span>
                            <span class="text-sm font-weight-bold">{{ $currentPlan->max_wa_numbers }}</span>
                        </li>
                        <li class="list-group-item border-0 d-flex justify-content-between px-0 py-2">
                            <span class="text-sm">Campaign</span>
                            <span class="text-sm font-weight-bold">{{ $currentPlan->max_campaigns == 0 ? 'Unlimited' : $currentPlan->max_campaigns }}</span>
                        </li>
                        <li class="list-group-item border-0 d-flex justify-content-between px-0 py-2">
                            <span class="text-sm">Penerima / Campaign</span>
                            <span class="text-sm font-weight-bold">{{ $currentPlan->max_recipients_per_campaign == 0 ? 'Unlimited' : number_format($currentPlan->max_recipients_per_campaign) }}</span>
                        </li>
                        <li class="list-group-item border-0 d-flex justify-content-between px-0 py-2">
                            <span class="text-sm">Durasi</span>
                            <span class="text-sm font-weight-bold">{{ $currentPlan->duration_days }} hari</span>
                        </li>
                    </ul>
                </div>
            </div>
        @endif

        {{-- Reminder Notifications Card --}}
        @if($recentNotifications->isNotEmpty())
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Notifikasi Terakhir</h6>
                </div>
                <div class="card-body pt-2">
                    <div class="timeline timeline-one-side">
                        @foreach($recentNotifications as $notif)
                            <div class="timeline-block mb-2">
                                <span class="timeline-step bg-{{ $notif->type === 'expired' ? 'danger' : ($notif->type === 't1' ? 'warning' : 'info') }} p-2">
                                    <i class="fas fa-{{ $notif->channel === 'email' ? 'envelope' : 'comment' }} text-white text-xs"></i>
                                </span>
                                <div class="timeline-content">
                                    <p class="text-xs text-secondary mb-0">{{ $notif->sent_at?->format('d M Y H:i') }}</p>
                                    <p class="text-sm mb-0">{{ $notif->type_label }} ({{ ucfirst($notif->channel) }})</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- Subscription History --}}
@if($subscriptionHistory->isNotEmpty())
<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0">Riwayat Langganan</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Paket</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Harga</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Mulai</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Berakhir</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Tipe</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($subscriptionHistory as $sub)
                                <tr>
                                    <td>
                                        <div class="d-flex px-3 py-1">
                                            <div class="d-flex flex-column justify-content-center">
                                                <h6 class="mb-0 text-sm">{{ $sub->plan_name }}</h6>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <p class="text-sm mb-0">Rp {{ number_format($sub->price, 0, ',', '.') }}</p>
                                    </td>
                                    <td>
                                        <p class="text-sm mb-0">{{ $sub->started_at?->format('d M Y') ?? '-' }}</p>
                                    </td>
                                    <td>
                                        <p class="text-sm mb-0">{{ $sub->expires_at?->format('d M Y') ?? '-' }}</p>
                                    </td>
                                    <td>
                                        @php
                                            $statusColor = match($sub->status) {
                                                'active' => 'success',
                                                'expired' => 'danger',
                                                'cancelled' => 'secondary',
                                                'replaced' => 'info',
                                                'pending' => 'warning',
                                                default => 'dark',
                                            };
                                        @endphp
                                        <span class="badge badge-sm bg-gradient-{{ $statusColor }}">{{ ucfirst($sub->status) }}</span>
                                    </td>
                                    <td>
                                        <span class="text-sm text-secondary">{{ ucfirst($sub->change_type ?? '-') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Pending Transactions --}}
@if(isset($pendingTransactions) && $pendingTransactions->isNotEmpty())
<div class="row">
    <div class="col-12">
        {{-- Alert Banner --}}
        <div class="alert alert-warning d-flex align-items-center mb-3 shadow-sm" role="alert">
            <i class="fas fa-exclamation-triangle me-3 fs-5"></i>
            <div>
                <strong>Anda memiliki transaksi menunggu pembayaran.</strong>
                <span class="text-sm d-block">Selesaikan pembayaran yang tertunda sebelum membuat transaksi baru.</span>
            </div>
        </div>

        <div class="card mb-4 border border-warning">
            <div class="card-header pb-0">
                <h6 class="mb-0 text-warning"><i class="fas fa-clock me-2"></i>Transaksi Menunggu Pembayaran</h6>
            </div>
            <div class="card-body pt-2">
                @foreach($pendingTransactions as $tx)
                <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                    <div>
                        <span class="text-sm font-weight-bold">{{ $tx->plan?->name ?? 'Paket' }}</span>
                        <span class="text-xs text-secondary ms-2">{{ $tx->transaction_code }}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-sm font-weight-bold">Rp {{ number_format($tx->final_price, 0, ',', '.') }}</span>
                        <span class="badge bg-gradient-warning text-xs">{{ ucfirst(str_replace('_', ' ', $tx->status)) }}</span>
                        <button class="btn btn-xs bg-gradient-primary mb-0 ms-2"
                                onclick="openCheckoutModal('{{ $tx->plan?->code ?? '' }}', '{{ $tx->plan?->name ?? 'Paket' }}', {{ (int) ($tx->plan?->price_monthly ?? 0) }}, {{ $tx->plan?->duration_days ?? 30 }})">
                            <i class="fas fa-arrow-right me-1"></i>Lanjutkan Pembayaran
                        </button>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif

{{-- ============================================================ --}}
{{-- AVAILABLE PLANS — with inline checkout                        --}}
{{-- Semua tombol memanggil checkout dengan harga dari DB          --}}
{{-- ============================================================ --}}
<div class="row" id="available-plans">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6 class="mb-0"><i class="fas fa-th-large me-2"></i>Pilih Paket</h6>
                <p class="text-sm text-secondary mb-0">Harga sudah ditentukan dari database. Klik untuk langsung checkout.</p>
            </div>
            <div class="card-body">
                <div class="row">
                    @foreach($availablePlans as $plan)
                        <div class="col-md-4 mb-3">
                            <div class="card plan-checkout-card border shadow-sm {{ $currentPlan && $currentPlan->id === $plan->id ? 'current-plan' : '' }}">
                                <div class="card-body text-center p-4">
                                    @if($plan->is_popular)
                                        <span class="badge bg-gradient-warning mb-2">Populer</span>
                                    @endif
                                    @if($currentPlan && $currentPlan->id === $plan->id)
                                        <span class="badge bg-gradient-primary mb-2">Paket Dipilih</span>
                                    @endif
                                    <h6 class="mb-1">{{ $plan->name }}</h6>
                                    @if($plan->description)
                                        <p class="text-xs text-secondary mb-2">{{ $plan->description }}</p>
                                    @endif
                                    <div class="checkout-price mb-1">
                                        Rp {{ number_format($plan->price_monthly, 0, ',', '.') }}
                                    </div>
                                    <p class="text-xs text-secondary mb-3">/ {{ $plan->duration_days }} hari</p>

                                    {{-- Plan limits --}}
                                    <ul class="list-unstyled text-start text-sm mb-3">
                                        <li class="py-1"><i class="fas fa-check text-success me-2"></i>{{ $plan->max_wa_numbers }} Nomor WA</li>
                                        <li class="py-1"><i class="fas fa-check text-success me-2"></i>{{ $plan->max_campaigns == 0 ? 'Unlimited' : $plan->max_campaigns }} Campaign</li>
                                        <li class="py-1"><i class="fas fa-check text-success me-2"></i>{{ $plan->max_recipients_per_campaign == 0 ? 'Unlimited' : number_format($plan->max_recipients_per_campaign) }} Penerima/Campaign</li>
                                    </ul>

                                    {{-- ============================================ --}}
                                    {{-- BUTTON LOGIC per planStatus                  --}}
                                    {{-- ============================================ --}}

                                    @php
                                        $hasPendingForPlan = isset($pendingByPlan) && $pendingByPlan->has($plan->id);
                                        $isCurrentActivePlan = $planStatus === 'active' && $currentPlan && $currentPlan->id === $plan->id;
                                    @endphp

                                    @if($isCurrentActivePlan && !$hasPendingForPlan)
                                        {{-- Paket ini sedang aktif → disable beli, tampilkan badge --}}
                                        <span class="badge bg-gradient-success w-100 py-2 mb-2">
                                            <i class="fas fa-check-circle me-1"></i> Sudah Aktif
                                        </span>
                                        <button class="btn btn-sm btn-outline-success w-100 mb-0" disabled>
                                            <i class="fas fa-lock me-1"></i> Paket Aktif
                                        </button>
                                        <p class="text-xs text-secondary text-center mt-1 mb-0">
                                            Berlaku sampai {{ $planExpiresAt?->format('d M Y') ?? 'Unlimited' }}
                                        </p>

                                    @elseif($hasPendingForPlan)
                                        {{-- Ada transaksi pending untuk plan ini --}}
                                        @php $pendingTx = $pendingByPlan->get($plan->id); @endphp
                                        <span class="badge bg-gradient-warning w-100 py-2 mb-2">
                                            <i class="fas fa-clock me-1"></i> Menunggu Pembayaran
                                        </span>
                                        <button class="btn btn-sm bg-gradient-warning w-100 mb-0"
                                                onclick="openCheckoutModal('{{ $plan->code }}', '{{ $plan->name }}', {{ (int) $plan->price_monthly }}, {{ $plan->duration_days }})">
                                            <i class="fas fa-arrow-right me-1"></i> Lanjutkan Pembayaran
                                        </button>
                                        <p class="text-xs text-warning text-center mt-1 mb-0">
                                            <i class="fas fa-info-circle me-1"></i>{{ $pendingTx?->transaction_code }}
                                        </p>

                                    @elseif($planStatus === 'trial_selected' && $currentPlan && $currentPlan->id === $plan->id)
                                        {{-- Paket ini dipilih tapi belum bayar --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="openCheckoutModal('{{ $plan->code }}', '{{ $plan->name }}', {{ (int) $plan->price_monthly }}, {{ $plan->duration_days }})">
                                            <i class="fas fa-credit-card me-1"></i> Bayar Paket Ini
                                        </button>

                                    @elseif($planStatus === 'expired' && $currentPlan && $currentPlan->id === $plan->id)
                                        {{-- Paket ini expired, aktifkan kembali --}}
                                        <button class="btn btn-sm bg-gradient-danger w-100 mb-0"
                                                onclick="openCheckoutModal('{{ $plan->code }}', '{{ $plan->name }}', {{ (int) $plan->price_monthly }}, {{ $plan->duration_days }})">
                                            <i class="fas fa-bolt me-1"></i> Aktifkan Kembali
                                        </button>

                                    @elseif($planStatus === 'active')
                                        {{-- Paket lain saat user punya paket aktif → konfirmasi dulu --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="confirmUpgradeToPlan('{{ $plan->code }}', '{{ $plan->name }}', {{ (int) $plan->price_monthly }}, {{ $plan->duration_days }})">
                                            <i class="fas fa-arrow-circle-up me-1"></i> Upgrade ke Paket Ini
                                        </button>

                                    @else
                                        {{-- Default: trial_selected (paket lain), expired (paket lain), atau no plan --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="openCheckoutModal('{{ $plan->code }}', '{{ $plan->name }}', {{ (int) $plan->price_monthly }}, {{ $plan->duration_days }})">
                                            <i class="fas fa-shopping-cart me-1"></i> Pilih Paket
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ============================================================ --}}
{{-- CHECKOUT CONFIRMATION MODAL                                   --}}
{{-- ============================================================ --}}
<div class="modal fade checkout-modal" id="subscriptionCheckoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-invoice-dollar me-2"></i>Konfirmasi Langganan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="checkout-summary">
                    <div class="plan-name" id="checkout-plan-name">-</div>
                    <div class="text-xs text-secondary mt-1">
                        <i class="fas fa-calendar me-1"></i>Durasi: <span id="checkout-duration">0</span> hari
                    </div>
                </div>

                <div class="checkout-price-row">
                    <span>Harga Paket</span>
                    <span id="checkout-price">Rp 0</span>
                </div>
                <div class="checkout-price-row total">
                    <span>Total Bayar</span>
                    <span id="checkout-total">Rp 0</span>
                </div>

                <div class="alert alert-info mt-3 mb-0 py-2">
                    <i class="fas fa-info-circle me-1"></i>
                    <span class="text-xs">Harga sesuai paket dari database. Tidak ada biaya tambahan.</span>
                </div>

                <input type="hidden" id="checkout-plan-code" value="">

                <div class="d-grid gap-2 mt-4">
                    <button class="btn btn-lg bg-gradient-primary" onclick="processSubscriptionCheckout()" id="btn-sub-checkout">
                        <i class="fas fa-credit-card me-2"></i>Bayar Sekarang
                    </button>
                </div>

                <p class="text-center text-muted small mt-3 mb-0">
                    <i class="fas fa-shield-alt me-1"></i>Pembayaran aman melalui payment gateway
                </p>
            </div>
        </div>
    </div>
</div>

{{-- ============================================================ --}}
{{-- UPGRADE CONFIRMATION MODAL                                    --}}
{{-- Tampil jika user klik Upgrade saat paket masih aktif          --}}
{{-- ============================================================ --}}
<div class="modal fade" id="upgradeConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Perubahan Paket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter: brightness(0) invert(1);"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
                </div>
                <p class="text-sm text-center">
                    Paket Anda masih aktif sampai <strong>{{ $planExpiresAt?->format('d M Y') ?? '-' }}</strong>.
                </p>
                <p class="text-sm text-center text-secondary">
                    Apakah Anda yakin ingin mengganti paket sekarang? Sisa masa aktif tidak akan otomatis digabung kecuali menggunakan sistem prorate.
                </p>

                <div class="d-grid gap-2 mt-4">
                    <button class="btn bg-gradient-warning" id="btn-confirm-upgrade" onclick="proceedToUpgrade()">
                        <i class="fas fa-check me-1"></i> Ya, Ganti Paket
                    </button>
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Batal
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ config('midtrans.snap_url') }}" data-client-key="{{ config('midtrans.client_key') }}"></script>
<script>
/**
 * Subscription Checkout — Fixed Price from DB
 * No custom amount, no nominal input.
 * All prices come from plan table.
 */

// Store pending upgrade data
let pendingUpgrade = null;

function scrollToPlans() {
    document.getElementById('available-plans').scrollIntoView({ behavior: 'smooth' });
}

/**
 * Open checkout modal directly (for trial_selected, expired, renew)
 */
function openCheckoutModal(code, name, price, duration) {
    document.getElementById('checkout-plan-code').value = code;
    document.getElementById('checkout-plan-name').textContent = name;
    document.getElementById('checkout-duration').textContent = duration;
    document.getElementById('checkout-price').textContent = 'Rp ' + price.toLocaleString('id-ID');
    document.getElementById('checkout-total').textContent = 'Rp ' + price.toLocaleString('id-ID');

    new bootstrap.Modal(document.getElementById('subscriptionCheckoutModal')).show();
}

/**
 * Scroll to plans section (when user clicks "Upgrade / Ganti Paket" from status card)
 */
function confirmUpgrade() {
    scrollToPlans();
}

/**
 * Show upgrade confirmation for a specific plan (from plan card)
 * Requires confirmation because plan is still active.
 */
function confirmUpgradeToPlan(code, name, price, duration) {
    pendingUpgrade = { code, name, price, duration };
    new bootstrap.Modal(document.getElementById('upgradeConfirmModal')).show();
}

/**
 * After user confirms upgrade, open checkout modal
 */
function proceedToUpgrade() {
    // Close confirmation modal
    bootstrap.Modal.getInstance(document.getElementById('upgradeConfirmModal'))?.hide();

    if (pendingUpgrade) {
        setTimeout(() => {
            openCheckoutModal(
                pendingUpgrade.code,
                pendingUpgrade.name,
                pendingUpgrade.price,
                pendingUpgrade.duration
            );
            pendingUpgrade = null;
        }, 300);
    }
}

/**
 * Process checkout via Midtrans Snap
 */
async function processSubscriptionCheckout() {
    const btn = document.getElementById('btn-sub-checkout');
    const planCode = document.getElementById('checkout-plan-code').value;

    if (!planCode) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memproses...';

    try {
        const response = await fetch('{{ route("subscription.checkout") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ plan_code: planCode })
        });

        const data = await response.json();

        if (data.success && data.data && data.data.snap_token) {
            // Show dev warning if present (APP_ENV=local)
            if (data.data.dev_warning) {
                showPaymentAlert('info', data.data.dev_warning);
            }

            // Store transaction_code for server-side verification
            const transactionCode = data.data.transaction_code;

            // Open Midtrans Snap popup
            snap.pay(data.data.snap_token, {
                onSuccess: function(result) {
                    // Server-side verification: call Midtrans API directly
                    // Works in local (MAMP) without webhook/ngrok
                    verifyPaymentStatus(transactionCode, btn);
                },
                onPending: function(result) {
                    showPaymentAlert('info', 'Pembayaran menunggu konfirmasi. Silakan selesaikan pembayaran Anda.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
                },
                onError: function(result) {
                    showPaymentAlert('danger', 'Pembayaran gagal. Silakan coba lagi.');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
                },
                onClose: function() {
                    // User closed popup — check if payment was completed
                    verifyPaymentStatus(transactionCode, btn);
                }
            });
        } else {
            // Show specific error from backend instead of generic message
            const errorMsg = data.message || 'Gagal memproses pembayaran.';
            const reason = data.reason || '';

            if (reason === 'payment_gateway_inactive') {
                showPaymentAlert('warning', errorMsg);
            } else if (reason === 'plan_already_active') {
                showPaymentAlert('info', '<i class="fas fa-check-circle me-1"></i>' + errorMsg);
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-lock me-2"></i>Paket Sudah Aktif';
                setTimeout(() => { location.reload(); }, 2500);
            } else if (reason === 'dev_error') {
                // Development mode: show debug info
                let debugInfo = errorMsg;
                if (data.debug) {
                    debugInfo += '<br><small class="text-muted">' +
                        '<strong>Error:</strong> ' + (data.debug.error || '') + '<br>' +
                        '<strong>Hint:</strong> ' + (data.debug.hint || '') + '<br>' +
                        '<strong>APP_URL:</strong> ' + (data.debug.app_url || '') +
                        '</small>';
                }
                showPaymentAlert('warning', debugInfo);
            } else {
                showPaymentAlert('danger', errorMsg);
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
        }
    } catch (error) {
        console.error('Subscription checkout error:', error);
        showPaymentAlert('danger', 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';
    }
}

/**
 * Server-side payment status verification.
 * Calls Midtrans API directly (no webhook needed).
 * Works in local MAMP environment.
 * 
 * | Mode       | Verification Method        |
 * |------------|----------------------------|
 * | Local      | This function (API check)  |
 * | Production | Webhook + this as fallback |
 */
async function verifyPaymentStatus(transactionCode, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Memverifikasi pembayaran...';

    try {
        const response = await fetch('/subscription/check-status/' + transactionCode, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (data.success && data.status === 'success') {
            showPaymentAlert('success', '<strong>Pembayaran berhasil!</strong> Paket Anda sudah aktif. Halaman akan dimuat ulang...');
            setTimeout(() => { location.reload(); }, 2000);
            return;
        }

        if (data.status === 'pending') {
            showPaymentAlert('info', 'Pembayaran masih menunggu konfirmasi. Halaman akan dimuat ulang...');
            setTimeout(() => { location.reload(); }, 3000);
            return;
        }

        // Payment not yet completed or failed
        showPaymentAlert('warning', data.message || 'Status pembayaran: ' + (data.status || 'tidak diketahui'));
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-credit-card me-2"></i>Bayar Sekarang';

    } catch (error) {
        console.error('Payment verification error:', error);
        // Don't show scary error — just reload to let user see current state
        showPaymentAlert('info', 'Memuat ulang halaman...');
        setTimeout(() => { location.reload(); }, 2000);
    }
}

/**
 * Show payment alert inside checkout modal instead of ugly alert()
 */
function showPaymentAlert(type, message) {
    // Remove existing alerts
    document.querySelectorAll('#subscriptionCheckoutModal .payment-alert').forEach(el => el.remove());

    const iconMap = {
        warning: 'exclamation-triangle',
        danger: 'times-circle',
        info: 'info-circle',
        success: 'check-circle'
    };

    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} payment-alert mt-3 mb-0 py-2`;
    alertDiv.innerHTML = '<i class="fas fa-' + (iconMap[type] || 'info-circle') + ' me-2"></i>' + message;

    const modalBody = document.querySelector('#subscriptionCheckoutModal .modal-body');
    modalBody.appendChild(alertDiv);
}
</script>
@endpush
