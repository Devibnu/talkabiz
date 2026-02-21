@extends('layouts.user_type.auth')

@section('content')
<style>
.plan-checkout-card { transition: transform 0.2s, box-shadow 0.2s; }
.plan-checkout-card:hover { transform: translateY(-4px); box-shadow: 0 8px 26px rgba(0,0,0,.1) !important; }
.plan-checkout-card.current-plan { border: 2px solid #cb0c9f; }
.checkout-price { font-size: 1.5rem; font-weight: 800; color: #344767; }
.active-warning-banner { background: linear-gradient(310deg, #fbb140 0%, #f5365c 100%); border-radius: 12px; }

/* ── Prorate Modal ── */
.prorate-modal .modal-content { border: none; border-radius: 1rem; overflow: hidden; }
.prorate-modal .modal-header-gradient-up { background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%); }
.prorate-modal .modal-header-gradient-down { background: linear-gradient(310deg, #17ad37 0%, #98ec2d 100%); }
.prorate-modal .prorate-plan-box { background: #f8f9fa; border-radius: .75rem; padding: 1rem; text-align: center; min-width: 0; flex: 1; }
.prorate-modal .prorate-plan-box.current { border: 2px solid #e9ecef; }
.prorate-modal .prorate-plan-box.target { border: 2px solid #cb0c9f; }
.prorate-modal .prorate-plan-box.target-down { border: 2px solid #17ad37; }
.prorate-modal .prorate-arrow { display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #8392ab; padding: 0 .5rem; }
.prorate-modal .prorate-breakdown { background: #f8f9fa; border-radius: .75rem; padding: 1rem 1.25rem; }
.prorate-modal .prorate-breakdown .row-item { display: flex; justify-content: space-between; padding: .35rem 0; font-size: .8125rem; }
.prorate-modal .prorate-breakdown .row-item.total { border-top: 2px solid #344767; margin-top: .5rem; padding-top: .75rem; font-weight: 700; font-size: .9375rem; }
.prorate-modal .prorate-amount-highlight { font-size: 1.75rem; font-weight: 800; line-height: 1.2; }
.prorate-modal .prorate-amount-highlight.text-upgrade { color: #7928ca; }
.prorate-modal .prorate-amount-highlight.text-downgrade { color: #17ad37; }
.prorate-modal .anti-abuse-notice { background: #fff3cd; border-radius: .5rem; padding: .65rem .85rem; font-size: .75rem; color: #664d03; }
.prorate-modal .prorate-loading { min-height: 280px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.prorate-modal .prorate-error { min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
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
                            <button class="btn btn-sm bg-gradient-primary mb-0" id="payNowBtn"
                                    onclick="fastCheckout('{{ $currentPlan->code }}')">
                                <i class="fas fa-credit-card me-1"></i> Bayar Paket Ini Sekarang
                            </button>
                            <button class="btn btn-sm btn-outline-secondary mb-0" onclick="scrollToPlans()">
                                <i class="fas fa-exchange-alt me-1"></i> Ganti Paket
                            </button>

                        {{-- ACTIVE: sudah bayar, masih aktif --}}
                        @elseif($planStatus === 'active')
                            <button class="btn btn-sm bg-gradient-success mb-0"
                                    onclick="fastCheckout('{{ $currentPlan->code }}')">
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
                                    onclick="fastCheckout('{{ $currentPlan->code }}')">
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
                                onclick="fastCheckout('{{ $tx->plan?->code ?? '' }}')">
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
                                                onclick="fastCheckout('{{ $plan->code }}')">
                                            <i class="fas fa-arrow-right me-1"></i> Lanjutkan Pembayaran
                                        </button>
                                        <p class="text-xs text-warning text-center mt-1 mb-0">
                                            <i class="fas fa-info-circle me-1"></i>{{ $pendingTx?->transaction_code }}
                                        </p>

                                    @elseif($planStatus === 'trial_selected' && $currentPlan && $currentPlan->id === $plan->id)
                                        {{-- Paket ini dipilih tapi belum bayar --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="fastCheckout('{{ $plan->code }}')">
                                            <i class="fas fa-credit-card me-1"></i> Bayar Paket Ini
                                        </button>

                                    @elseif($planStatus === 'expired' && $currentPlan && $currentPlan->id === $plan->id)
                                        {{-- Paket ini expired, aktifkan kembali --}}
                                        <button class="btn btn-sm bg-gradient-danger w-100 mb-0"
                                                onclick="fastCheckout('{{ $plan->code }}')">
                                            <i class="fas fa-bolt me-1"></i> Aktifkan Kembali
                                        </button>

                                    @elseif($planStatus === 'active')
                                        {{-- Paket lain saat user punya paket aktif → konfirmasi dulu --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="confirmUpgradeToPlan('{{ $plan->code }}')">
                                            <i class="fas fa-arrow-circle-up me-1"></i> Upgrade ke Paket Ini
                                        </button>

                                    @else
                                        {{-- Default: trial_selected (paket lain), expired (paket lain), atau no plan --}}
                                        <button class="btn btn-sm bg-gradient-primary w-100 mb-0"
                                                onclick="fastCheckout('{{ $plan->code }}')">
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
{{-- PRORATE PLAN CHANGE MODAL (Enterprise)                        --}}
{{-- Shows prorate calculation before upgrade/downgrade            --}}
{{-- ============================================================ --}}
<div class="modal fade prorate-modal" id="planChangeModal" tabindex="-1" aria-labelledby="planChangeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg">

            {{-- Header (dynamic gradient via JS) --}}
            <div class="modal-header modal-header-gradient-up border-0 py-3 px-4" id="planChangeModalHeader">
                <h6 class="modal-title text-white mb-0" id="planChangeModalLabel">
                    <i class="fas fa-exchange-alt me-2"></i><span id="pcm-header-text">Konfirmasi Perubahan Paket</span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body px-4 pt-3 pb-4">

                {{-- ▸ Loading State --}}
                <div id="pcm-loading" class="prorate-loading">
                    <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="text-sm text-secondary mb-0">Menghitung prorate...</p>
                </div>

                {{-- ▸ Error State --}}
                <div id="pcm-error" class="prorate-error d-none">
                    <i class="fas fa-exclamation-circle text-danger fa-3x mb-3"></i>
                    <p class="text-sm text-secondary mb-1" id="pcm-error-msg">Terjadi kesalahan.</p>
                    <button class="btn btn-sm btn-outline-secondary mt-2" data-bs-dismiss="modal">Tutup</button>
                </div>

                {{-- ▸ Content (hidden until data loaded) --}}
                <div id="pcm-content" class="d-none">

                    {{-- Plan comparison row --}}
                    <div class="d-flex align-items-stretch gap-2 mb-3">
                        <div class="prorate-plan-box current">
                            <p class="text-xs text-secondary text-uppercase mb-1">Paket Saat Ini</p>
                            <h6 class="font-weight-bolder mb-1" id="pcm-current-name">—</h6>
                            <p class="text-sm mb-0" id="pcm-current-price">—</p>
                        </div>
                        <div class="prorate-arrow">
                            <i class="fas fa-arrow-right" id="pcm-arrow-icon"></i>
                        </div>
                        <div class="prorate-plan-box target" id="pcm-target-box">
                            <p class="text-xs text-secondary text-uppercase mb-1">Paket Baru</p>
                            <h6 class="font-weight-bolder mb-1" id="pcm-new-name">—</h6>
                            <p class="text-sm mb-0" id="pcm-new-price">—</p>
                        </div>
                    </div>

                    {{-- Summary badge --}}
                    <div class="text-center mb-3">
                        <span class="badge badge-lg px-3 py-2" id="pcm-summary-badge" style="font-size:.8125rem;">—</span>
                    </div>

                    {{-- Prorate Breakdown --}}
                    <div class="prorate-breakdown mb-3">
                        <p class="text-xs text-uppercase font-weight-bold text-secondary mb-2"><i class="fas fa-calculator me-1"></i>Rincian Prorate</p>
                        <div class="row-item">
                            <span>Sisa hari aktif</span>
                            <span id="pcm-remaining-days">—</span>
                        </div>
                        <div class="row-item">
                            <span>Nilai sisa paket lama</span>
                            <span id="pcm-current-remaining">—</span>
                        </div>
                        <div class="row-item">
                            <span>Biaya paket baru (prorata)</span>
                            <span id="pcm-new-cost">—</span>
                        </div>
                        <div class="row-item">
                            <span>Selisih harga</span>
                            <span id="pcm-price-diff">—</span>
                        </div>
                        <div class="row-item" id="pcm-tax-row">
                            <span>Pajak (<span id="pcm-tax-rate">0</span>%)</span>
                            <span id="pcm-tax-amount">—</span>
                        </div>
                        <div class="row-item total" id="pcm-total-row">
                            <span id="pcm-total-label">Total Bayar</span>
                            <span class="prorate-amount-highlight text-upgrade" id="pcm-total-amount">—</span>
                        </div>
                    </div>

                    {{-- Upgrade: payment note --}}
                    <div id="pcm-upgrade-note" class="d-none">
                        <div class="d-flex align-items-start p-3 rounded-lg mb-3" style="background:linear-gradient(310deg,#7928ca08,#ff008008);border:1px solid #cb0c9f20;">
                            <i class="fas fa-credit-card text-primary me-2 mt-1"></i>
                            <div>
                                <p class="text-xs font-weight-bold mb-0">Pembayaran via Midtrans</p>
                                <p class="text-xs text-secondary mb-0">Setelah klik tombol bayar, popup pembayaran akan terbuka. Paket baru aktif setelah pembayaran berhasil.</p>
                            </div>
                        </div>
                    </div>

                    {{-- Downgrade: wallet credit note --}}
                    <div id="pcm-downgrade-note" class="d-none">
                        <div class="d-flex align-items-start p-3 rounded-lg mb-3" style="background:linear-gradient(310deg,#17ad3708,#98ec2d08);border:1px solid #17ad3720;">
                            <i class="fas fa-wallet text-success me-2 mt-1"></i>
                            <div>
                                <p class="text-xs font-weight-bold mb-0">Refund ke Wallet</p>
                                <p class="text-xs text-secondary mb-0">Selisih sisa nilai paket akan dikreditkan ke wallet Anda. Paket langsung berubah.</p>
                            </div>
                        </div>
                    </div>

                    {{-- Anti-abuse notice --}}
                    <div class="anti-abuse-notice mb-3">
                        <i class="fas fa-shield-alt me-1"></i>
                        <strong>Batas perubahan:</strong> maks. 2× per siklus langganan, jeda min. 3 hari antar perubahan.
                    </div>

                    {{-- Action Buttons --}}
                    <div class="d-grid gap-2">
                        <button class="btn mb-0" id="pcm-btn-confirm" onclick="executePlanChange()">
                            <i class="fas fa-check-circle me-1"></i> <span id="pcm-btn-text">Konfirmasi</span>
                        </button>
                        <button class="btn btn-outline-secondary mb-0" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i> Batal
                        </button>
                    </div>
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
 * FAST PAYMENT PATH — Phase 4.1
 * 
 * Flow: 1 klik → fetch checkout → snap popup → selesai
 * Tidak redirect. Tidak reload. Tidak buat invoice double.
 */

// Store pending upgrade data
let pendingUpgrade = null;
let isCheckoutProcessing = false;

function scrollToPlans() {
    document.getElementById('available-plans').scrollIntoView({ behavior: 'smooth' });
}

// ==================== FAST CHECKOUT (1-CLICK) ====================

/**
 * Fast Checkout — langsung POST → Snap popup
 * Tidak buka modal konfirmasi lagi.
 * Button yang diklik otomatis jadi loading.
 */
async function fastCheckout(planCode) {
    if (isCheckoutProcessing) return;
    isCheckoutProcessing = true;

    // Find and disable the clicked button
    const btn = event?.target?.closest('button') || document.getElementById('payNowBtn');
    const originalHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';
    }

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

        // 409: Already active
        if (response.status === 409) {
            showToast('info', data.message || 'Paket sudah aktif.');
            setTimeout(() => location.reload(), 2000);
            return;
        }

        // Success: got snap_token
        const snapToken = data.snap_token || data.data?.snap_token;
        const transactionCode = data.transaction_code || data.data?.transaction_code;

        if (data.success && snapToken) {
            // Dev warning
            if (data.dev_warning || data.data?.dev_warning) {
                console.info('[DEV]', data.dev_warning || data.data.dev_warning);
            }

            // Open Midtrans Snap popup langsung
            snap.pay(snapToken, {
                onSuccess: function(result) {
                    showToast('success', '<strong>Pembayaran berhasil!</strong> Paket Anda akan segera aktif.');
                    setTimeout(() => location.reload(), 2000);
                },
                onPending: function(result) {
                    showToast('info', 'Pembayaran menunggu konfirmasi. Status akan diupdate otomatis.');
                    setTimeout(() => location.reload(), 3000);
                },
                onError: function(result) {
                    showToast('danger', 'Pembayaran gagal. Silakan coba lagi.');
                    resetButton(btn, originalHtml);
                },
                onClose: function() {
                    // User closed popup — webhook will handle if payment was completed
                    showToast('info', 'Popup ditutup. Jika sudah bayar, status akan diupdate otomatis.');
                    resetButton(btn, originalHtml);
                }
            });
        } else {
            // Error handling
            handleCheckoutError(data, response.status, btn, originalHtml);
        }

    } catch (error) {
        console.error('Fast checkout error:', error);
        showToast('danger', 'Tidak dapat terhubung ke server. Periksa koneksi internet.');
        resetButton(btn, originalHtml);
    } finally {
        isCheckoutProcessing = false;
    }
}

/**
 * Handle checkout error responses
 */
function handleCheckoutError(data, status, btn, originalHtml) {
    const reason = data.reason || '';
    const message = data.message || 'Gagal memproses pembayaran.';

    if (reason === 'payment_gateway_inactive') {
        showToast('warning', message);
    } else if (reason === 'plan_already_active') {
        showToast('info', message);
        setTimeout(() => location.reload(), 2500);
        return; // Don't reset button
    } else if (reason === 'dev_error') {
        let debugInfo = message;
        if (data.debug) {
            debugInfo += ' | ' + (data.debug.error || '') + ' | Hint: ' + (data.debug.hint || '');
        }
        showToast('warning', debugInfo);
    } else {
        showToast('danger', message);
    }

    resetButton(btn, originalHtml);
}

// ==================== UPGRADE FLOW (keeps confirmation) ====================

/**
 * Scroll to plans section (from status card "Upgrade / Ganti Paket")
 */
function confirmUpgrade() {
    scrollToPlans();
}

/**
 * PRORATE-AWARE: Show plan change modal with live prorate calculation.
 * Replaces old confirmUpgradeToPlan() — now fetches preview from API.
 */
async function confirmUpgradeToPlan(code) {
    pendingUpgrade = { code };

    // Reset modal states
    const modal = document.getElementById('planChangeModal');
    document.getElementById('pcm-loading').classList.remove('d-none');
    document.getElementById('pcm-content').classList.add('d-none');
    document.getElementById('pcm-error').classList.add('d-none');

    // Open modal immediately (shows loading spinner)
    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();

    try {
        const response = await fetch('{{ route("subscription.change-plan.preview") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ plan_code: code })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Gagal memuat perhitungan prorate.');
        }

        populateProrateModal(data.data);

    } catch (error) {
        console.error('Preview error:', error);
        document.getElementById('pcm-loading').classList.add('d-none');
        document.getElementById('pcm-error').classList.remove('d-none');
        document.getElementById('pcm-error-msg').textContent = error.message || 'Tidak dapat terhubung ke server.';
    }
}

/**
 * Populate modal with prorate preview data
 */
function populateProrateModal(data) {
    const { current_plan, new_plan, prorate, summary } = data;
    const isUpgrade = prorate.direction === 'upgrade';
    const isDowngrade = prorate.direction === 'downgrade';
    const fmt = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID');

    // Header gradient
    const header = document.getElementById('planChangeModalHeader');
    header.className = 'modal-header border-0 py-3 px-4 ' +
        (isUpgrade ? 'modal-header-gradient-up' : 'modal-header-gradient-down');
    document.getElementById('pcm-header-text').textContent =
        isUpgrade ? 'Upgrade Paket' : 'Downgrade Paket';

    // Plan names & prices
    document.getElementById('pcm-current-name').textContent = current_plan.name;
    document.getElementById('pcm-current-price').textContent = fmt(current_plan.price_monthly) + '/bln';
    document.getElementById('pcm-new-name').textContent = new_plan.name;
    document.getElementById('pcm-new-price').textContent = fmt(new_plan.price_monthly) + '/bln';

    // Target box border color
    const targetBox = document.getElementById('pcm-target-box');
    targetBox.classList.remove('target', 'target-down');
    targetBox.classList.add(isUpgrade ? 'target' : 'target-down');

    // Arrow icon
    const arrow = document.getElementById('pcm-arrow-icon');
    arrow.className = isUpgrade ? 'fas fa-arrow-up text-primary' : 'fas fa-arrow-down text-success';

    // Summary badge
    const badge = document.getElementById('pcm-summary-badge');
    badge.textContent = summary;
    badge.className = 'badge badge-lg px-3 py-2 ' +
        (isUpgrade ? 'bg-gradient-primary' : 'bg-gradient-success');
    badge.style.fontSize = '.8125rem';

    // Breakdown
    document.getElementById('pcm-remaining-days').textContent = prorate.remaining_days + ' hari';
    document.getElementById('pcm-current-remaining').textContent = fmt(prorate.current_remaining_value);
    document.getElementById('pcm-new-cost').textContent = fmt(prorate.new_remaining_cost);
    document.getElementById('pcm-price-diff').textContent = fmt(Math.abs(prorate.price_difference));
    document.getElementById('pcm-tax-rate').textContent = ((prorate.tax_rate || 0) * 100).toFixed(0);
    document.getElementById('pcm-tax-amount').textContent = fmt(prorate.tax_amount);

    // Tax row visibility
    document.getElementById('pcm-tax-row').style.display =
        (prorate.tax_amount && prorate.tax_amount > 0) ? 'flex' : 'none';

    // Total row
    const totalLabel = document.getElementById('pcm-total-label');
    const totalAmt = document.getElementById('pcm-total-amount');

    if (isUpgrade) {
        totalLabel.textContent = 'Total Bayar';
        totalAmt.textContent = fmt(prorate.charge_amount || prorate.total_with_tax);
        totalAmt.className = 'prorate-amount-highlight text-upgrade';
    } else {
        totalLabel.textContent = 'Refund ke Wallet';
        totalAmt.textContent = fmt(prorate.refund_amount);
        totalAmt.className = 'prorate-amount-highlight text-downgrade';
    }

    // Notes visibility
    document.getElementById('pcm-upgrade-note').classList.toggle('d-none', !isUpgrade);
    document.getElementById('pcm-downgrade-note').classList.toggle('d-none', isUpgrade);

    // Button style
    const btnConfirm = document.getElementById('pcm-btn-confirm');
    btnConfirm.className = 'btn mb-0 ' + (isUpgrade ? 'bg-gradient-primary' : 'bg-gradient-success');
    btnConfirm.disabled = false;
    document.getElementById('pcm-btn-text').textContent =
        isUpgrade ? 'Bayar & Upgrade Sekarang' : 'Konfirmasi Downgrade';

    // Reveal content, hide loading
    document.getElementById('pcm-loading').classList.add('d-none');
    document.getElementById('pcm-content').classList.remove('d-none');
}

/**
 * EXECUTE PLAN CHANGE — called from modal confirm button.
 * Handles upgrade (Snap popup) and downgrade (instant) flows.
 */
async function executePlanChange() {
    if (!pendingUpgrade || isCheckoutProcessing) return;
    isCheckoutProcessing = true;

    const btn = document.getElementById('pcm-btn-confirm');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';

    try {
        const response = await fetch('{{ route("subscription.change-plan") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ plan_code: pendingUpgrade.code })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Gagal memproses perubahan paket.');
        }

        // Close the modal
        const modal = bootstrap.Modal.getInstance(document.getElementById('planChangeModal'));
        modal?.hide();

        // Handle by type
        if (data.type === 'upgrade' && data.requires_payment && data.snap_token) {
            // Upgrade: open Midtrans Snap popup
            setTimeout(() => {
                snap.pay(data.snap_token, {
                    onSuccess: function(result) {
                        showToast('success', '<strong>Pembayaran berhasil!</strong> Paket upgrade Anda akan segera aktif.');
                        setTimeout(() => location.reload(), 2000);
                    },
                    onPending: function(result) {
                        showToast('info', 'Pembayaran menunggu konfirmasi. Status akan diupdate otomatis.');
                        setTimeout(() => location.reload(), 3000);
                    },
                    onError: function(result) {
                        showToast('danger', 'Pembayaran gagal. Silakan coba lagi.');
                        resetButton(btn, originalHtml);
                    },
                    onClose: function() {
                        showToast('info', 'Popup ditutup. Jika sudah bayar, status akan diupdate otomatis.');
                        resetButton(btn, originalHtml);
                    }
                });
            }, 400);

        } else if (data.type === 'downgrade') {
            // Downgrade: instant, wallet credit
            const refundAmt = data.prorate?.refund_amount
                ? 'Rp ' + Number(data.prorate.refund_amount).toLocaleString('id-ID')
                : '';
            showToast('success',
                '<strong>Paket berhasil diubah!</strong> ' +
                (refundAmt ? refundAmt + ' dikreditkan ke wallet Anda.' : data.message)
            );
            setTimeout(() => location.reload(), 2500);

        } else if (data.type === 'immediate') {
            // Immediate switch (e.g. same-price or free tier)
            showToast('success', '<strong>Paket berhasil diubah!</strong> ' + (data.message || ''));
            setTimeout(() => location.reload(), 2000);

        } else {
            showToast('info', data.message || 'Perubahan paket diproses.');
            setTimeout(() => location.reload(), 2500);
        }

        pendingUpgrade = null;

    } catch (error) {
        console.error('Plan change error:', error);
        showToast('danger', error.message || 'Tidak dapat terhubung ke server.');
        resetButton(btn, originalHtml);
    } finally {
        isCheckoutProcessing = false;
    }
}

// verifyPaymentStatus() REMOVED → Webhook-only architecture
// Status updates now come exclusively from Midtrans webhook callback

// ==================== UI HELPERS ====================

function resetButton(btn, originalHtml) {
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

/**
 * Show toast notification (top-right, auto-dismiss)
 */
function showToast(type, message) {
    // Remove existing toasts
    document.querySelectorAll('.fast-pay-toast').forEach(el => el.remove());

    const iconMap = {
        success: 'check-circle',
        danger: 'times-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };

    const toast = document.createElement('div');
    toast.className = `fast-pay-toast alert alert-${type} shadow-lg`;
    toast.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;max-width:400px;animation:slideIn 0.3s ease';
    toast.innerHTML = '<i class="fas fa-' + (iconMap[type] || 'info-circle') + ' me-2"></i>' + message;

    document.body.appendChild(toast);

    // Auto dismiss after 5s (except success which reloads)
    if (type !== 'success') {
        setTimeout(() => toast.remove(), 5000);
    }
}

// Toast animation
const style = document.createElement('style');
style.textContent = '@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }';
document.head.appendChild(style);
</script>
@endpush
