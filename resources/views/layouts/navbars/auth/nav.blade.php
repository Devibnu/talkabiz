<!-- Navbar -->
<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
    <div class="container-fluid py-1 px-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                <li class="breadcrumb-item text-sm"><a class="opacity-5 text-dark" href="{{ url('dashboard') }}">{{ $__brandName }}</a></li>
                <li class="breadcrumb-item text-sm text-dark active text-capitalize" aria-current="page">{{ str_replace('-', ' ', Request::segment(1) ?: 'Dashboard') }}</li>
            </ol>
            <h6 class="font-weight-bolder mb-0 text-capitalize">{{ str_replace('-', ' ', Request::segment(1) ?: 'Dashboard') }}</h6>
        </nav>
        <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4" id="navbar">
            <div class="ms-md-auto pe-md-3 d-flex align-items-center nav-wallet-wrap">
                {{-- Saldo Display & Topup CTA — hidden on /subscription (subscription = paket, bukan wallet) --}}
                @if(auth()->check() && !in_array(auth()->user()->role ?? '', ['super_admin', 'superadmin']) && !request()->routeIs('subscription.*') && !request()->is('subscription*'))
                    @php
                        $userWallet = auth()->user()->getWallet();
                        // DATABASE-DRIVEN PRICING (NO HARDCODE!)
                        $messageRateService = app(\App\Services\MessageRateService::class);
                        $pricePerMessage = $messageRateService->getRate('utility');
                    @endphp
                    @if($userWallet)
                        <div class="nav-item me-3 nav-wallet-item">
                            <div class="nav-wallet-chip d-flex align-items-center bg-white rounded-pill px-3 py-2 shadow-sm border">
                                <div class="me-2 nav-wallet-icon">
                                    @if($userWallet->status_saldo === 'habis')
                                        <i class="fas fa-exclamation-triangle text-danger"></i>
                                    @elseif($userWallet->status_saldo === 'kritis')
                                        <i class="fas fa-exclamation text-warning"></i>
                                    @else
                                        <i class="fas fa-wallet text-success"></i>
                                    @endif
                                </div>
                                <div class="flex-grow-1 nav-wallet-copy">
                                    <div class="nav-wallet-mobile-inline {{ $userWallet->status_saldo === 'habis' ? 'is-danger' : '' }}">
                                        <span class="nav-wallet-mobile-label">Saldo WA</span>
                                        <span class="nav-wallet-mobile-amount">
                                            Rp {{ number_format($userWallet->saldo_tersedia, 0, ',', '.') }}
                                        </span>
                                    </div>
                                    <div class="nav-wallet-desktop-copy">
                                        <div class="text-xs text-secondary mb-0 lh-1 nav-wallet-label">
                                            <i class="fab fa-whatsapp nav-wallet-label-icon"></i>
                                            <span class="nav-wallet-label-text">Saldo WA</span>
                                        </div>
                                        <div class="font-weight-bold text-sm lh-1 nav-wallet-amount {{ $userWallet->status_saldo === 'habis' ? 'text-danger' : 'text-dark' }}">
                                            Rp {{ number_format($userWallet->saldo_tersedia, 0, ',', '.') }}
                                        </div>
                                    </div>
                                </div>
                                @if(request()->routeIs('billing') || request()->is('billing*'))
                                    <button class="btn btn-sm bg-gradient-success ms-2 mb-0 px-2 py-1 nav-wallet-action" 
                                            onclick="showTopupModal()" 
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="bottom"
                                            title="Topup Saldo WhatsApp">
                                        <i class="fas fa-plus text-xs"></i>
                                    </button>
                                @else
                                    <a href="{{ route('billing') }}" 
                                       class="btn btn-sm bg-gradient-success ms-2 mb-0 px-2 py-1 nav-wallet-action" 
                                       data-bs-toggle="tooltip"
                                       data-bs-placement="bottom"
                                       title="Topup Saldo WhatsApp">
                                        <i class="fas fa-plus text-xs"></i>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                @endif
            </div>
            <ul class="navbar-nav justify-content-end nav-mobile-actions">
                {{-- Mobile Sidenav Toggle --}}
                <li class="nav-item d-xl-none ps-3 d-flex align-items-center">
                    <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                        <div class="sidenav-toggler-inner">
                            <i class="sidenav-toggler-line"></i>
                            <i class="sidenav-toggler-line"></i>
                            <i class="sidenav-toggler-line"></i>
                        </div>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<!-- End Navbar -->