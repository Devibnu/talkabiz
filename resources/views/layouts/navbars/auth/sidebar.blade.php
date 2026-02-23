<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3" id="sidenav-main">
    
    {{-- Brand Header --}}
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-secondary opacity-5 position-absolute end-0 top-0 d-none d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0 d-flex align-items-center" href="{{ route('dashboard') }}">
            @if($__brandLogoUrl)
                <img src="{{ $__brandLogoUrl }}" alt="{{ $__brandName }}" style="height: 32px; width: auto; object-fit: contain;">
            @else
                <div class="brand-icon">
                    <i class="ni ni-chat-round"></i>
                </div>
            @endif
            <span class="brand-text ms-2 font-weight-bold" style="font-size: 0.875rem; white-space: nowrap;">{{ $__brandName }}</span>
        </a>
    </div>
    
    <hr class="horizontal dark mt-0">
    
    {{-- Navigation Menu --}}
    <div class="collapse navbar-collapse w-auto h-auto" id="sidenav-collapse-main">
        <ul class="navbar-nav">
            
            {{-- Dashboard --}}
            <li class="nav-item">
                <a class="nav-link {{ Request::is('dashboard') ? 'active' : '' }}" href="{{ url('dashboard') }}">
                    <div class="icon-wrapper">
                        <i class="ni ni-tv-2"></i>
                    </div>
                    <span class="nav-link-text">Dashboard</span>
                </a>
            </li>
            
            {{-- Inbox --}}
            <li class="nav-item">
                <a class="nav-link {{ Request::is('inbox*') ? 'active' : '' }}" href="{{ url('inbox') }}">
                    <div class="icon-wrapper">
                        <i class="ni ni-email-83"></i>
                    </div>
                    <span class="nav-link-text">Inbox</span>
                </a>
            </li>
            
            @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin', 'umkm']))
            {{-- Campaign - Show lock if WA not connected --}}
            {{-- Visible to all authenticated roles including impersonating owners --}}
            @php
                $isImpersonating = auth()->user()->isImpersonating();
                $isOwnerRole = in_array(auth()->user()->role, ['super_admin', 'superadmin', 'owner']);
                $canAccessCampaign = $isOwnerRole || $isImpersonating || (auth()->user()->klien?->wa_terhubung ?? false);
            @endphp
            <li class="nav-item">
                <a class="nav-link {{ Request::is('campaign*') ? 'active' : '' }}" 
                   href="{{ $canAccessCampaign ? url('campaign') : url('whatsapp') }}"
                   @if(!$canAccessCampaign) title="Hubungkan WhatsApp terlebih dahulu" @endif>
                    <div class="icon-wrapper">
                        <i class="ni ni-send"></i>
                    </div>
                    <span class="nav-link-text">
                        Campaign
                        @if(!$canAccessCampaign)
                            <i class="fas fa-lock text-warning ms-1" title="Butuh koneksi WhatsApp"></i>
                        @endif
                    </span>
                </a>
            </li>
            
            {{-- Template Pesan --}}
            <li class="nav-item">
                <a class="nav-link {{ Request::is('template*') ? 'active' : '' }}" href="{{ url('template') }}">
                    <div class="icon-wrapper">
                        <i class="ni ni-single-copy-04"></i>
                    </div>
                    <span class="nav-link-text">Template Pesan</span>
                </a>
            </li>
            @endif
            
            {{-- Kontak / Audience --}}
            <li class="nav-item">
                <a class="nav-link {{ Request::is('kontak*') ? 'active' : '' }}" href="{{ url('kontak') }}">
                    <div class="icon-wrapper">
                        <i class="ni ni-circle-08"></i>
                    </div>
                    <span class="nav-link-text">Kontak / Audience</span>
                </a>
            </li>
            
            @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin', 'umkm']))
            {{-- WhatsApp Connection --}}
            @php
                $waConnected = $isOwnerRole || $isImpersonating || (auth()->user()->klien?->wa_terhubung ?? false);
            @endphp
            <li class="nav-item">
                <a class="nav-link {{ Request::is('whatsapp*') ? 'active' : '' }}" href="{{ url('whatsapp') }}">
                    <div class="icon-wrapper">
                        <i class="fab fa-whatsapp"></i>
                    </div>
                    <span class="nav-link-text">
                        Nomor WhatsApp
                        @if(!$waConnected)
                            <span class="badge bg-warning ms-1" title="Belum terhubung">!</span>
                        @endif
                    </span>
                </a>
            </li>
            @endif
            
            @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin', 'umkm']))
            {{-- Paket & Langganan --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('subscription.*') ? 'active' : '' }}" href="{{ route('subscription.index') }}">
                    <div class="icon-wrapper">
                        <i class="fas fa-crown"></i>
                    </div>
                    <span class="nav-link-text">Paket & Langganan</span>
                </a>
            </li>

            {{-- Saldo WhatsApp --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('billing') || request()->routeIs('billing.*') ? 'active' : '' }}" href="{{ route('billing') }}">
                    <div class="icon-wrapper">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <span class="nav-link-text">Saldo WhatsApp</span>
                </a>
            </li>
            
            @endif

            @if(in_array(auth()->user()->role ?? '', ['super_admin', 'owner', 'admin']))
            {{-- Activity Log --}}
            <li class="nav-item">
                <a class="nav-link {{ Request::is('activity-log*') ? 'active' : '' }}" href="{{ url('activity-log') }}">
                    <div class="icon-wrapper">
                        <i class="ni ni-bullet-list-67"></i>
                    </div>
                    <span class="nav-link-text">Activity Log</span>
                </a>
            </li>
            @endif
            
        </ul>
    </div>
    
    {{-- User Profile Footer --}}
    <div class="sidenav-footer">
        <hr class="horizontal dark">
        <div class="user-card">
            <div class="user-info">
                <div class="user-avatar">
                    <span>{{ strtoupper(substr(auth()->user()->nama ?? auth()->user()->email ?? 'U', 0, 1)) }}</span>
                </div>
                <div class="user-details">
                    <p class="user-name">{{ auth()->user()->nama ?? auth()->user()->email ?? 'User' }}</p>
                    <p class="user-role">{{ ucfirst(str_replace('_', ' ', auth()->user()->role ?? 'User')) }}</p>
                </div>
            </div>
            <x-logout-button class="btn-logout" icon="ni ni-button-power" text="Logout" />
        </div>
    </div>
</aside>
