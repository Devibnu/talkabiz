{{--
    Subscription Renewal Banner
    Auto-injected via ShareSubscriptionStatus middleware.
    
    Shows:
    - Yellow warning when plan expires within 7 days
    - Red expired banner when plan has expired
--}}

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
