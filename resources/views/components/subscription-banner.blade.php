{{--
    Subscription Banner (Force Activation Gate + Renewal)
    Auto-injected via ShareSubscriptionStatus middleware.
    
    Shows (priority order):
    1. Red BLOCKED banner when subscription NOT active (trial_selected, no plan, or no subscription)
    2. Red expired banner when plan has expired
    3. Yellow warning when plan expires within 7 days
    
    Uses $subscriptionIsActive (SSOT from Subscription model via middleware)
    Does NOT hardcode status strings — uses boolean from isActive()
--}}

@if(!($subscriptionIsActive ?? false) && !in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']))
    {{-- FORCE ACTIVATION GATE BANNER --}}
    @if(isset($subscriptionPlanStatus) && $subscriptionPlanStatus === 'expired')
        {{-- EXPIRED BANNER --}}
        <div class="alert alert-danger d-flex align-items-center mb-3 mx-0 border-radius-lg shadow-sm" role="alert" id="subscription-expired-banner">
            <div class="d-flex align-items-center">
                <div class="icon icon-shape icon-sm bg-gradient-danger shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                    <i class="fas fa-exclamation-circle text-white text-sm"></i>
                </div>
                <div>
                    <span class="text-sm font-weight-bold">Paket {{ $subscriptionPlanName ?? '' }} Anda telah berakhir.</span>
                    <span class="text-sm d-block d-md-inline ms-md-1">Fitur pengiriman pesan tidak tersedia. Perpanjang sekarang untuk melanjutkan.</span>
                </div>
            </div>
            <a href="{{ route('subscription.index') }}" class="btn btn-sm btn-white text-danger ms-auto mb-0 flex-shrink-0">
                <i class="fas fa-arrow-circle-up me-1"></i> Perpanjang Sekarang
            </a>
        </div>
    @else
        {{-- NOT ACTIVE BANNER (trial_selected / no plan) --}}
        <div class="alert alert-danger d-flex align-items-center mb-3 mx-0 border-radius-lg shadow-sm" role="alert" id="subscription-inactive-banner">
            <div class="d-flex align-items-center">
                <div class="icon icon-shape icon-sm bg-gradient-danger shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                    <i class="fas fa-lock text-white text-sm"></i>
                </div>
                <div>
                    <span class="text-sm font-weight-bold">⚠️ Paket belum aktif.</span>
                    <span class="text-sm d-block d-md-inline ms-md-1">Silakan lakukan pembayaran untuk mulai menggunakan {{ $__brandName ?? 'Talkabiz' }}.</span>
                </div>
            </div>
            <a href="{{ route('subscription.index') }}" class="btn btn-sm btn-white text-danger ms-auto mb-0 flex-shrink-0">
                <i class="fas fa-credit-card me-1"></i> Bayar Sekarang
            </a>
        </div>
    @endif

@elseif(($subscriptionIsGrace ?? false) && !in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']))
    {{-- GRACE PERIOD WARNING BANNER --}}
    @php
        $graceDays = $subscriptionGraceDaysRemaining ?? 0;
        $graceUrgency = $graceDays <= 1 ? 'danger' : 'warning';
        $graceIcon = $graceDays <= 1 ? 'exclamation-triangle' : 'hourglass-half';
        $graceGradient = $graceDays <= 1 ? 'danger' : 'warning';
        $graceDayLabel = $graceDays <= 0 ? 'HARI INI' : ($graceDays === 1 ? 'BESOK' : "dalam {$graceDays} hari");
    @endphp
    <div class="alert alert-{{ $graceUrgency }} d-flex align-items-center mb-3 mx-0 border-radius-lg shadow-sm" role="alert" id="subscription-grace-banner">
        <div class="d-flex align-items-center">
            <div class="icon icon-shape icon-sm bg-gradient-{{ $graceGradient }} shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                <i class="fas fa-{{ $graceIcon }} text-white text-sm"></i>
            </div>
            <div>
                <span class="text-sm font-weight-bold">⏳ Masa tenggang paket {{ $subscriptionPlanName ?? '' }}.</span>
                <span class="text-sm d-block d-md-inline ms-md-1">Paket berakhir {{ $graceDayLabel }}. Perpanjang sekarang agar layanan tidak terhenti.</span>
            </div>
        </div>
        <a href="{{ route('subscription.index') }}" class="btn btn-sm btn-white text-{{ $graceUrgency }} ms-auto mb-0 flex-shrink-0">
            <i class="fas fa-arrow-circle-up me-1"></i> Perpanjang Sekarang
        </a>
    </div>

@elseif(isset($subscriptionExpiresInDays) && $subscriptionExpiresInDays !== null && $subscriptionExpiresInDays <= 7 && $subscriptionExpiresInDays > 0)
    {{-- EXPIRING SOON WARNING --}}
    @php
        $urgency = $subscriptionExpiresInDays <= 1 ? 'danger' : ($subscriptionExpiresInDays <= 3 ? 'warning' : 'info');
        $icon = $subscriptionExpiresInDays <= 1 ? 'exclamation-triangle' : ($subscriptionExpiresInDays <= 3 ? 'clock' : 'info-circle');
        $gradient = $subscriptionExpiresInDays <= 1 ? 'danger' : ($subscriptionExpiresInDays <= 3 ? 'warning' : 'info');
        $dayLabel = $subscriptionExpiresInDays === 1 ? 'BESOK' : "dalam {$subscriptionExpiresInDays} hari";
    @endphp
    <div class="alert alert-{{ $urgency }} d-flex align-items-center mb-3 mx-0 border-radius-lg shadow-sm" role="alert" id="subscription-expiry-banner">
        <div class="d-flex align-items-center">
            <div class="icon icon-shape icon-sm bg-gradient-{{ $gradient }} shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                <i class="fas fa-{{ $icon }} text-white text-sm"></i>
            </div>
            <div>
                <span class="text-sm font-weight-bold">Paket {{ $subscriptionPlanName ?? '' }} berakhir {{ $dayLabel }}.</span>
                <span class="text-sm d-block d-md-inline ms-md-1">Perpanjang untuk menghindari gangguan layanan.</span>
            </div>
        </div>
        <a href="{{ route('subscription.index') }}" class="btn btn-sm btn-white text-{{ $urgency }} ms-auto mb-0 flex-shrink-0">
            <i class="fas fa-arrow-circle-up me-1"></i> Perpanjang
        </a>
    </div>
@endif
