{{-- 
SaldoGuard Component - Prevents actions when balance is insufficient
Usage: @include('components.saldo-guard', ['action' => 'send-campaign', 'estimatedCost' => 50000])
--}}

@php
    $user = auth()->user();
    $userWallet = $user ? $user->getWallet() : null;
    
    // DATABASE-DRIVEN PRICING (NO HARDCODE!)
    $messageRateService = app(\App\Services\MessageRateService::class);
    $pricePerMessage = $messageRateService->getRate('utility');
    
    // Accept both legacy (button*) and new (cta*) prop names
    $action = $actionText ?? $action ?? 'unknown-action';
    $buttonId = $buttonId ?? 'saldo-guard-btn';
    $buttonText = $ctaText ?? $buttonText ?? 'Lanjutkan';
    $buttonClass = $ctaClass ?? $buttonClass ?? 'btn btn-primary';
    $buttonIcon = $ctaIcon ?? null;
    $extraAttributes = $ctaAttributes ?? '';
    $redirectAfter = $redirectAfter ?? '';
    
    // Calculate estimated cost — accept either direct estimatedCost or requiredMessages × rate
    if (isset($requiredMessages) && $requiredMessages > 0) {
        $estimatedCost = $requiredMessages * $pricePerMessage;
    } else {
        $estimatedCost = $estimatedCost ?? 0;
    }
    
    // Calculate balance status
    $currentBalance = $userWallet ? $userWallet->saldo_tersedia : 0;
    $isBalanceSufficient = $currentBalance >= $estimatedCost;
    $shortage = $isBalanceSufficient ? 0 : ($estimatedCost - $currentBalance);
    
    // Owner/admin always passes saldo guard (they don't have wallets)
    $isOwnerRole = in_array($user->role ?? '', ['super_admin', 'superadmin', 'owner'], true);
    if ($isOwnerRole) {
        $isBalanceSufficient = true;
    }
    
    // Generate unique IDs for this component instance
    $guardId = 'saldo-guard-' . Str::random(8);
    $modalId = 'saldo-guard-modal-' . Str::random(8);
@endphp

{{-- Protected Action Button --}}
<div class="saldo-guard-container" data-guard-id="{{ $guardId }}">
    @if($isBalanceSufficient)
        {{-- Balance sufficient - show normal button --}}
        <button type="button" 
                class="{{ $buttonClass }}"
                {!! $extraAttributes !!}>
            @if($buttonIcon)<i class="{{ $buttonIcon }} me-1"></i>@endif
            {{ $buttonText }}
        </button>
    @else
        {{-- Balance insufficient - show warning button --}}
        <button type="button" 
                id="{{ $buttonId }}" 
                class="btn btn-warning"
                data-bs-toggle="modal" 
                data-bs-target="#{{ $modalId }}">
            <i class="fas fa-exclamation-triangle me-1"></i>Saldo Tidak Cukup
        </button>
    @endif
</div>

{{-- Insufficient Balance Modal --}}
@if(!$isBalanceSufficient)
<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0">
                <h5 class="modal-title font-weight-bolder text-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>Saldo Tidak Mencukupi
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body text-center">
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="alert alert-warning border-warning">
                            <h6 class="text-warning font-weight-bolder mb-2">
                                <i class="fas fa-calculator me-2"></i>Ringkasan Biaya
                            </h6>
                            <div class="d-flex justify-content-between text-sm">
                                <span>Estimasi biaya {{ $action }}:</span>
                                <strong>Rp {{ number_format($estimatedCost, 0, ',', '.') }}</strong>
                            </div>
                            <div class="d-flex justify-content-between text-sm">
                                <span>Saldo saat ini:</span>
                                <strong>Rp {{ number_format($currentBalance, 0, ',', '.') }}</strong>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between text-sm text-danger">
                                <span>Kekurangan saldo:</span>
                                <strong>Rp {{ number_format($shortage, 0, ',', '.') }}</strong>
                            </div>
                        </div>
                    </div>
                </div>
                
                <p class="text-secondary mb-4">
                    Anda perlu menambah saldo sebesar <strong>Rp {{ number_format($shortage, 0, ',', '.') }}</strong> 
                    untuk melanjutkan {{ $action }}.
                </p>
                
                <div class="d-grid gap-2">
                    <button type="button" 
                            class="btn bg-gradient-success"
                            onclick="showTopupModal('{{ $redirectAfter }}')"
                            data-bs-dismiss="modal">
                        <i class="fas fa-wallet me-1"></i>Topup Saldo Sekarang
                    </button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">
                        Batal
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Component JavaScript --}}
<script>
// Guard-specific functionality
document.addEventListener('DOMContentLoaded', function() {
    const guardContainer = document.querySelector('[data-guard-id="{{ $guardId }}"]');
    
    if (guardContainer) {
        // Add any guard-specific event listeners here
        guardContainer.addEventListener('click', function(e) {
            @if(!$isBalanceSufficient)
                // Prevent any action if balance insufficient
                e.preventDefault();
                e.stopPropagation();
            @endif
        });
    }
});
</script>