@extends('layouts.app')

@section('auth')

{{-- OWNER BANNER - Only shown to owners in Client Mode --}}
@if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner']))
    @include('components.owner-banner')
@endif

    @if(\Request::is('static-sign-up')) 
        @include('layouts.navbars.guest.nav')
        @yield('content')
        @include('layouts.footers.guest.footer')
    
    @elseif (\Request::is('static-sign-in')) 
        @include('layouts.navbars.guest.nav')
            @yield('content')
        @include('layouts.footers.guest.footer')
    
    @else
        @if (\Request::is('rtl'))  
            @include('layouts.navbars.auth.sidebar-rtl')
            <main class="main-content position-relative max-height-vh-100 h-100 mt-1 border-radius-lg overflow-hidden">
                @include('layouts.navbars.auth.nav-rtl')
                <div class="container-fluid py-4">
                    @yield('content')
                    @include('layouts.footers.auth.footer')
                </div>
            </main>

        @elseif (\Request::is('profile'))  
            @include('layouts.navbars.auth.sidebar')
            <div class="main-content position-relative bg-gray-100 max-height-vh-100 h-100">
                @include('layouts.navbars.auth.nav')
                @yield('content')
            </div>

        @elseif (\Request::is('virtual-reality')) 
            @include('layouts.navbars.auth.nav')
            <div class="border-radius-xl mt-3 mx-3 position-relative" style="background-image: url('../assets/img/vr-bg.jpg') ; background-size: cover;">
                @include('layouts.navbars.auth.sidebar')
                <main class="main-content mt-1 border-radius-lg">
                    @yield('content')
                </main>
            </div>
            @include('layouts.footers.auth.footer')

        @else
            @include('layouts.navbars.auth.sidebar')
            <main class="main-content position-relative max-height-vh-100 h-100 mt-1 border-radius-lg {{ (Request::is('rtl') ? 'overflow-hidden' : '') }}">
                @include('layouts.navbars.auth.nav')
                <div class="container-fluid py-4">
                    @include('components.subscription-banner')
                    @yield('content')
                    @include('layouts.footers.auth.footer')
                </div>
            </main>
        @endif

        @include('components.fixed-plugin')
    @endif

    {{-- Topup Modal Integration — ONLY on billing pages (not subscription) --}}
    @if(auth()->check() && !in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin']) && (request()->routeIs('billing') || request()->is('billing*')))
        <div id="topupModalContainer"></div>
    @endif

@endsection

{{-- Topup JavaScript — ONLY on billing pages --}}
@if(auth()->check() && !in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin']) && (request()->routeIs('billing') || request()->is('billing*')))
@push('scripts')
<script>
/**
 * Global Topup Modal Handler
 * Provides consistent topup UX across all pages
 */
window.topupModalLoaded = false;

function showTopupModal(redirectAfter = '') {
    if (window.topupModalLoaded) {
        // Modal already loaded, just show it
        const modalEl = document.getElementById('topupModal');
        if (modalEl) {
            modalEl.dataset.redirectAfter = redirectAfter;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        }
        return;
    }
    
    // Load modal content via AJAX
    fetch('{{ route("topup.modal") }}', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('topupModalContainer').innerHTML = data.html;
            window.topupModalLoaded = true;
            
            // Set redirect after if provided
            const modalEl = document.getElementById('topupModal');
            if (modalEl && redirectAfter) {
                modalEl.dataset.redirectAfter = redirectAfter;
            }
            
            // Show the modal
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        } else {
            alert('Gagal memuat modal topup: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Topup modal error:', error);
        alert('Gagal memuat modal topup. Silakan refresh halaman dan coba lagi.');
    });
}

/**
 * Saldo Guard Helper
 * Check if user has sufficient balance for an action
 */
function checkSaldoAndProceed(requiredAmount, actionCallback, actionName = 'aksi ini') {
    const currentSaldo = {{ auth()->user()->getWallet()->saldo_tersedia ?? 0 }};
    
    if (currentSaldo >= requiredAmount) {
        // Saldo cukup, lanjutkan aksi
        if (typeof actionCallback === 'function') {
            actionCallback();
        }
    } else {
        // Saldo tidak cukup, tampilkan konfirmasi topup
        const shortage = requiredAmount - currentSaldo;
        const message = `Saldo tidak mencukupi untuk ${actionName}.\n\n` +
                       `Dibutuhkan: Rp ${requiredAmount.toLocaleString('id-ID')}\n` +
                       `Saldo saat ini: Rp ${currentSaldo.toLocaleString('id-ID')}\n` +
                       `Kekurangan: Rp ${shortage.toLocaleString('id-ID')}\n\n` +
                       `Apakah Anda ingin topup sekarang?`;
        
        if (confirm(message)) {
            showTopupModal(window.location.href);
        }
    }
}

/**
 * Real-time saldo update after successful topup
 * Called by payment success page/callback
 */
function updateSaldoDisplay(newBalance) {
    // Update navbar saldo
    const navbarSaldo = document.querySelector('.navbar .font-weight-bold');
    if (navbarSaldo) {
        navbarSaldo.textContent = 'Rp ' + newBalance.toLocaleString('id-ID');
    }
    
    // Update dashboard card saldo
    const dashboardSaldo = document.querySelector('.card .font-weight-bolder');
    if (dashboardSaldo && dashboardSaldo.textContent.includes('Rp')) {
        dashboardSaldo.textContent = 'Rp ' + newBalance.toLocaleString('id-ID');
    }
    
    // Update estimated messages (price from backend)
    const pricePerMessage = {{ $hargaPerPesan ?? app(\App\Services\MessageRateService::class)->getRate('utility') }};
    const estimatedMessages = Math.floor(newBalance / pricePerMessage);
    
    const messageEstimate = document.querySelector('.text-xs.text-secondary');
    if (messageEstimate && messageEstimate.textContent.includes('pesan')) {
        messageEstimate.textContent = '≈ ' + estimatedMessages.toLocaleString('id-ID') + ' pesan';
    }

    // Update any saldo guards on the page
    window.topupModalLoaded = false; // Force reload to get updated saldo
}
</script>
@endpush
@endif