{{--
    Activation Progress Banner (Growth Engine — Step 1)
    
    Only shown when: plan_status = trial_selected && user is NOT admin/owner
    
    Displays a prominent checklist guiding the user to complete activation:
    [✓] Registrasi akun
    [✓] Pilih paket
    [ ] Bayar paket
    [ ] Kirim campaign pertama
    
    Uses $subscriptionIsActive, $subscriptionPlanStatus from ShareSubscriptionStatus middleware.
    KPI: logs 'viewed_subscription' when banner is rendered.
--}}

@php
    $isTrialSelected = (isset($subscriptionPlanStatus) && $subscriptionPlanStatus === 'trial_selected');
    $isAdminOwner = in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin', 'owner']);
    $showActivationBanner = $isTrialSelected && !$isAdminOwner && !($subscriptionIsActive ?? false);
@endphp

@if($showActivationBanner)
<div class="card border border-primary shadow-lg mb-4" id="activation-progress-banner">
    <div class="card-body p-4">
        {{-- Header --}}
        <div class="d-flex align-items-center mb-3">
            <div class="icon icon-shape icon-md bg-gradient-primary shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center">
                <i class="fas fa-rocket text-white text-lg"></i>
            </div>
            <div>
                <h5 class="font-weight-bolder mb-0" style="color: #344767;">
                    🚀 Satu Langkah Lagi untuk Mengaktifkan Akun
                </h5>
                <p class="text-sm text-secondary mb-0">Selesaikan langkah di bawah untuk mulai mengirim WhatsApp Campaign</p>
            </div>
        </div>

        {{-- Checklist --}}
        <div class="row">
            <div class="col-lg-7">
                <div class="activation-checklist">
                    {{-- Step 1: Registrasi — DONE --}}
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon icon-shape icon-xs bg-gradient-success shadow text-center border-radius-md me-2 d-flex align-items-center justify-content-center" style="min-width: 28px; height: 28px;">
                            <i class="fas fa-check text-white" style="font-size: 0.7rem;"></i>
                        </div>
                        <span class="text-sm" style="text-decoration: line-through; color: #8392ab;">Registrasi akun</span>
                    </div>

                    {{-- Step 2: Pilih Paket — DONE --}}
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon icon-shape icon-xs bg-gradient-success shadow text-center border-radius-md me-2 d-flex align-items-center justify-content-center" style="min-width: 28px; height: 28px;">
                            <i class="fas fa-check text-white" style="font-size: 0.7rem;"></i>
                        </div>
                        <span class="text-sm" style="text-decoration: line-through; color: #8392ab;">Pilih paket</span>
                    </div>

                    {{-- Step 3: Bayar Paket — PENDING --}}
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon icon-shape icon-xs bg-gradient-warning shadow text-center border-radius-md me-2 d-flex align-items-center justify-content-center" style="min-width: 28px; height: 28px; animation: pulse 2s infinite;">
                            <i class="fas fa-arrow-right text-white" style="font-size: 0.7rem;"></i>
                        </div>
                        <span class="text-sm font-weight-bold" style="color: #344767;">Bayar paket</span>
                        <span class="badge bg-gradient-warning ms-2" style="font-size: 0.65rem;">Langkah saat ini</span>
                    </div>

                    {{-- Step 4: Kirim Campaign — LOCKED --}}
                    <div class="d-flex align-items-center mb-2">
                        <div class="icon icon-shape icon-xs bg-gradient-secondary shadow text-center border-radius-md me-2 d-flex align-items-center justify-content-center" style="min-width: 28px; height: 28px; opacity: 0.5;">
                            <i class="fas fa-lock text-white" style="font-size: 0.6rem;"></i>
                        </div>
                        <span class="text-sm" style="color: #c0c6cc;">Kirim campaign pertama</span>
                    </div>
                </div>
            </div>

            {{-- CTA Column --}}
            <div class="col-lg-5 d-flex align-items-center justify-content-center mt-3 mt-lg-0">
                <div class="text-center">
                    <a href="{{ route('subscription.index') }}" 
                       class="btn bg-gradient-primary btn-lg px-5 mb-2 activation-cta-btn"
                       id="btnActivationCta"
                       onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('clicked_pay', {source: 'progress_banner'});">
                        <i class="fas fa-bolt me-2"></i>Aktifkan Sekarang
                    </a>
                    <p class="text-xs text-secondary mb-0">Proses aktivasi kurang dari 2 menit</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.15); }
    }
    .activation-cta-btn {
        font-size: 1rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.3px;
        box-shadow: 0 4px 12px rgba(94, 114, 228, 0.4) !important;
        transition: all 0.3s ease;
    }
    .activation-cta-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 114, 228, 0.5) !important;
    }
    #activation-progress-banner {
        border-width: 2px !important;
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fe 100%);
    }
</style>
@endif
