{{-- 
    Onboarding Card Component
    Tampil di dashboard untuk user UMKM Pilot yang belum complete onboarding
--}}

@php
    $user = auth()->user();
    $onboardingService = app(\App\Services\OnboardingService::class);
    $checklist = $onboardingService->getChecklist($user);
    $progress = $onboardingService->getProgress($user);
    $allComplete = $onboardingService->allStepsComplete($user);
@endphp

<div class="card mb-4 border-start border-4 border-primary" id="onboarding-card">
    <div class="card-body p-4">
        {{-- Header --}}
        <div class="d-flex align-items-start mb-3">
            <div class="icon icon-shape bg-gradient-primary shadow border-radius-md text-center me-3" style="min-width: 48px; height: 48px;">
                <i class="fas fa-hand-wave text-lg opacity-10 pt-2" aria-hidden="true" style="font-size: 1.5rem;"></i>
            </div>
            <div class="flex-grow-1">
                <h5 class="font-weight-bolder mb-1">Selamat datang di {{ $__brandName ?? 'Talkabiz' }} 👋</h5>
                <p class="text-sm text-secondary mb-0">
                    Sebelum mengirim campaign, ikuti langkah singkat ini agar WhatsApp Anda tetap aman dan efektif.
                </p>
            </div>
        </div>

        {{-- Progress Bar --}}
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-xs text-uppercase font-weight-bold text-secondary">Progress Onboarding</span>
                <span class="text-sm font-weight-bold" id="progress-text">{{ $progress }}%</span>
            </div>
            <div class="progress" style="height: 8px;">
                <div class="progress-bar bg-gradient-primary" role="progressbar" 
                     style="width: {{ $progress }}%;" 
                     aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100"
                     id="progress-bar"></div>
            </div>
        </div>

        {{-- Checklist --}}
        <div class="list-group list-group-flush" id="onboarding-checklist">
            @foreach($checklist as $item)
                <div class="list-group-item border-0 px-0 py-2 d-flex align-items-center onboarding-step" 
                     data-step="{{ $item['key'] }}"
                     data-completed="{{ $item['completed'] ? 'true' : 'false' }}">
                    {{-- Checkbox icon --}}
                    <div class="icon icon-shape icon-sm shadow border-radius-md text-center me-3 d-flex align-items-center justify-content-center step-icon
                        {{ $item['completed'] ? 'bg-gradient-success' : 'bg-gradient-secondary' }}">
                        @if($item['completed'])
                            <i class="fas fa-check text-white text-xs opacity-10"></i>
                        @else
                            <i class="{{ $item['icon'] }} text-white text-xs opacity-10"></i>
                        @endif
                    </div>
                    
                    {{-- Label & Description --}}
                    <div class="flex-grow-1">
                        <h6 class="mb-0 text-sm {{ $item['completed'] ? 'text-success' : '' }}">
                            {{ $item['label'] }}
                            @if($item['completed'])
                                <i class="fas fa-check-circle text-success ms-1"></i>
                            @endif
                        </h6>
                        <p class="text-xs text-secondary mb-0">{{ $item['description'] }}</p>
                    </div>

                    {{-- Action Button --}}
                    @if(!$item['completed'])
                        @if($item['url'])
                            <a href="{{ url($item['url']) }}" class="btn btn-sm btn-outline-primary mb-0">
                                Mulai
                            </a>
                        @elseif($item['key'] === 'ready_to_send')
                            <button type="button" class="btn btn-sm bg-gradient-primary mb-0" 
                                    id="btn-ready-to-send"
                                    onclick="activateCampaign()"
                                    @if(!$allComplete) disabled @endif>
                                <i class="fas fa-rocket me-1"></i> Saya Siap!
                            </button>
                        @endif
                    @else
                        <span class="badge bg-gradient-success">Selesai</span>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Info Box --}}
        <div class="alert alert-light border mt-3 mb-0 py-2 px-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-info-circle text-primary me-2"></i>
                <small class="text-secondary">
                    Fitur campaign akan aktif setelah Anda menyelesaikan semua langkah di atas.
                </small>
            </div>
        </div>
    </div>
</div>

{{-- JavaScript for Onboarding --}}
<script>
    async function activateCampaign() {
        const btn = document.getElementById('btn-ready-to-send');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Memproses...';

        try {
            const response = await fetch('/api/onboarding/activate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });

            const result = await response.json();

            if (result.success) {
                // Show success message
                btn.innerHTML = '<i class="fas fa-check me-1"></i> Berhasil!';
                btn.classList.remove('bg-gradient-primary');
                btn.classList.add('bg-gradient-success');
                
                // Reload page after 1.5s to show updated dashboard
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                // If WA not connected, use friendly waNotConnected popup
                if (result.reason === 'wa_not_connected') {
                    ClientPopup.waNotConnected('/whatsapp');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-rocket me-1"></i> Saya Siap!';
                    return;
                }
                
                // Show friendly warning - guide user to solution
                ClientPopup.info('Hampir Selesai!', 'Masih ada beberapa langkah yang perlu diselesaikan. Yuk selesaikan dulu!');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-rocket me-1"></i> Saya Siap!';
            }
        } catch (error) {
            console.error('Activation error:', error);
            ClientPopup.connectionError();
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-rocket me-1"></i> Saya Siap!';
        }
    }

    // Check all required steps before enabling button
    function checkReadyButton() {
        const steps = document.querySelectorAll('.onboarding-step');
        // PENTING: wa_connected HARUS complete juga!
        const requiredSteps = ['wa_connected', 'contact_added', 'template_viewed', 'guide_read'];
        let allRequired = true;

        steps.forEach(step => {
            const stepKey = step.dataset.step;
            const isCompleted = step.dataset.completed === 'true';
            
            if (requiredSteps.includes(stepKey) && !isCompleted) {
                allRequired = false;
            }
        });

        const btn = document.getElementById('btn-ready-to-send');
        if (btn) {
            btn.disabled = !allRequired;
            if (!allRequired) {
                btn.title = 'Selesaikan semua langkah termasuk menghubungkan WhatsApp';
            }
        }
    }

    // Run on page load
    document.addEventListener('DOMContentLoaded', checkReadyButton);
</script>
