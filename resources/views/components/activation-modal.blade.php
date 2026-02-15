{{--
    Activation Modal (Growth Engine — Step 2)
    
    Auto-shown when ALL conditions met:
    1. plan_status = trial_selected
    2. No successful payment transaction exists
    3. First login after registration (via session flag)
    
    Uses $subscriptionPlanStatus from ShareSubscriptionStatus middleware.
    KPI: logs 'activation_modal_shown' and 'activation_modal_cta_clicked'.
--}}

@php
    $isTrialSelected = (isset($subscriptionPlanStatus) && $subscriptionPlanStatus === 'trial_selected');
    $isAdminOwner = in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']);
    $shouldShowModal = $isTrialSelected && !$isAdminOwner && !($subscriptionIsActive ?? false);
    
    // Check if first visit this session (prevent re-showing on every page load)
    $modalShownThisSession = session('activation_modal_shown', false);
@endphp

@if($shouldShowModal && !$modalShownThisSession)
{{-- Modal --}}
<div class="modal fade" id="activationModal" tabindex="-1" aria-labelledby="activationModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none;">
            {{-- Header with gradient --}}
            <div class="text-center pt-4 pb-2 px-4" style="background: linear-gradient(135deg, #5e72e4 0%, #825ee4 100%);">
                <div class="mb-3">
                    <div class="icon icon-shape icon-lg bg-white shadow text-center border-radius-xl mx-auto d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                        <i class="fas fa-rocket text-primary" style="font-size: 1.5rem;"></i>
                    </div>
                </div>
                <h5 class="text-white font-weight-bolder mb-1" id="activationModalLabel">
                    Akun Anda Belum Aktif
                </h5>
                <p class="text-white text-sm opacity-8 mb-3">
                    Untuk mulai mengirim WhatsApp Campaign, silakan aktifkan paket Anda.
                </p>
            </div>

            {{-- Body --}}
            <div class="modal-body px-4 pt-4 pb-2">
                {{-- Benefits list --}}
                <div class="mb-3">
                    <div class="d-flex align-items-start mb-2">
                        <i class="fas fa-check-circle text-success me-2 mt-1" style="font-size: 0.9rem;"></i>
                        <span class="text-sm">Kirim WhatsApp Campaign ke ribuan kontak</span>
                    </div>
                    <div class="d-flex align-items-start mb-2">
                        <i class="fas fa-check-circle text-success me-2 mt-1" style="font-size: 0.9rem;"></i>
                        <span class="text-sm">Inbox multi-agent untuk customer support</span>
                    </div>
                    <div class="d-flex align-items-start mb-2">
                        <i class="fas fa-check-circle text-success me-2 mt-1" style="font-size: 0.9rem;"></i>
                        <span class="text-sm">Template pesan siap pakai</span>
                    </div>
                    <div class="d-flex align-items-start mb-2">
                        <i class="fas fa-check-circle text-success me-2 mt-1" style="font-size: 0.9rem;"></i>
                        <span class="text-sm">Laporan & analytics real-time</span>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="modal-footer flex-column border-0 px-4 pb-4 pt-0">
                <a href="{{ route('subscription.index') }}" 
                   class="btn bg-gradient-primary w-100 mb-2"
                   id="btnModalPayNow"
                   onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('activation_modal_cta_clicked', {action: 'pay_now'});">
                    <i class="fas fa-credit-card me-2"></i>Bayar Sekarang
                </a>
                <a href="{{ route('subscription.index') }}" 
                   class="btn btn-outline-primary w-100 mb-2"
                   onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('activation_modal_cta_clicked', {action: 'view_plans'});">
                    <i class="fas fa-th-list me-2"></i>Lihat Paket Lain
                </a>
                <button type="button" class="btn btn-link text-secondary text-sm w-100 mb-0" data-bs-dismiss="modal" id="btnModalDismiss">
                    Nanti saja
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-show modal after a short delay for better UX
    setTimeout(function() {
        var modal = document.getElementById('activationModal');
        if (modal && typeof bootstrap !== 'undefined') {
            var bsModal = new bootstrap.Modal(modal);
            bsModal.show();
            
            // Log KPI event
            if (typeof ActivationKpi !== 'undefined') {
                ActivationKpi.track('activation_modal_shown');
            }

            // Mark as shown in session via AJAX (prevent re-showing on page refresh)
            fetch('{{ url("/api/activation/modal-shown") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            }).catch(function() {}); // fire & forget
        }
    }, 800);
});
</script>
@endif
