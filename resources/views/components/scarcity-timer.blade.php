{{--
    Soft Scarcity Timer (Growth Engine — Step 3)
    
    Shows urgency text when plan_status = trial_selected:
    "Diskon setup berlaku hingga 24 jam setelah pendaftaran."
    
    Uses auth()->user()->created_at to calculate time remaining.
    Does NOT hardcode any discount — only displays urgency text.
    
    KPI: logs 'scarcity_timer_shown'.
--}}

@php
    $isTrialSelected = (isset($subscriptionPlanStatus) && $subscriptionPlanStatus === 'trial_selected');
    $isAdminOwner = in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']);
    $showTimer = $isTrialSelected && !$isAdminOwner && !($subscriptionIsActive ?? false);
    
    $registeredAt = auth()->user()->created_at ?? now();
    $deadlineAt = $registeredAt->copy()->addHours(24);
    $isExpired = now()->greaterThanOrEqualTo($deadlineAt);
    $hoursLeft = $isExpired ? 0 : (int) now()->diffInHours($deadlineAt, false);
    $minutesLeft = $isExpired ? 0 : (int) now()->diffInMinutes($deadlineAt, false) % 60;
@endphp

@if($showTimer && !$isExpired)
<div class="card border-0 mb-4" id="scarcity-timer-card" style="background: linear-gradient(135deg, #fef3cd 0%, #fff8e1 100%); border-left: 4px solid #f5a623 !important;">
    <div class="card-body py-3 px-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <i class="fas fa-clock text-warning" style="font-size: 1.5rem;"></i>
                </div>
                <div>
                    <p class="text-sm font-weight-bold mb-0" style="color: #856404;">
                        ⏰ Diskon setup berlaku hingga 24 jam setelah pendaftaran
                    </p>
                    <p class="text-xs mb-0" style="color: #856404;">
                        Sisa waktu: <span id="scarcityCountdown" class="font-weight-bolder">{{ $hoursLeft }} jam {{ $minutesLeft }} menit</span>
                    </p>
                </div>
            </div>
            <a href="{{ route('subscription.index') }}" 
               class="btn btn-sm btn-warning mb-0 mt-2 mt-md-0 flex-shrink-0"
               onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('clicked_pay', {source: 'scarcity_timer'});">
                <i class="fas fa-bolt me-1"></i>Aktifkan Sekarang
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    var deadline = new Date('{{ $deadlineAt->toIso8601String() }}');
    var countdownEl = document.getElementById('scarcityCountdown');
    var timerCard = document.getElementById('scarcity-timer-card');
    
    function updateCountdown() {
        var now = new Date();
        var diff = deadline - now;
        
        if (diff <= 0) {
            if (timerCard) timerCard.style.display = 'none';
            return;
        }
        
        var hours = Math.floor(diff / (1000 * 60 * 60));
        var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        if (countdownEl) {
            countdownEl.textContent = hours + ' jam ' + minutes + ' menit ' + seconds + ' detik';
        }
    }
    
    updateCountdown();
    setInterval(updateCountdown, 1000);
})();
</script>
@endif
