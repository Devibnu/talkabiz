{{--
    OWNER SIDEBAR - CLEAN & CONSISTENT (7 items + Kembali)
    Following Soft UI Dashboard conventions
--}}

<aside class="sidenav navbar navbar-vertical navbar-expand-xs border-0 border-radius-xl my-3 fixed-start ms-3 owner-sidenav" id="sidenav-main">
    <div class="sidenav-header">
        <i class="fas fa-times p-3 cursor-pointer text-white opacity-5 position-absolute end-0 top-0 d-xl-none" aria-hidden="true" id="iconSidenav"></i>
        <a class="navbar-brand m-0 d-flex align-items-center" href="{{ route('owner.dashboard') }}">
            <div class="icon icon-shape icon-sm bg-gradient-warning shadow text-center border-radius-md me-2 d-flex align-items-center justify-content-center">
                <i class="fas fa-crown text-white text-sm"></i>
            </div>
            <span class="font-weight-bold text-white">Owner Panel</span>
        </a>
    </div>
    
    <hr class="horizontal light mt-0 mb-2">
    
    <div class="collapse navbar-collapse w-auto" id="sidenav-collapse-main" style="height: calc(100vh - 200px); overflow-y: auto;">
        <ul class="navbar-nav">
            
            {{-- 1. Dashboard --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.dashboard*') ? 'active' : '' }}" 
                   href="{{ route('owner.dashboard') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-chart-pie text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Dashboard</span>
                </a>
            </li>

            {{-- 2. Klien UMKM --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.clients*') ? 'active' : '' }}" 
                   href="{{ route('owner.clients.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-users text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Klien UMKM</span>
                </a>
            </li>

            {{-- === MASTER DATA === --}}
            <li class="nav-item mt-2">
                <h6 class="ps-4 ms-2 text-uppercase text-xs font-weight-bolder text-white opacity-6">MASTER DATA</h6>
            </li>

            {{-- 2b. Master Tipe Bisnis --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.master.business-types*') ? 'active' : '' }}" 
                   href="{{ route('owner.master.business-types.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-briefcase text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Master Tipe Bisnis</span>
                </a>
            </li>

            {{-- 3. WhatsApp Control --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.whatsapp*') || request()->routeIs('owner.wa-connections*') ? 'active' : '' }}" 
                   href="{{ route('owner.whatsapp.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fab fa-whatsapp text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">WhatsApp Control</span>
                </a>
            </li>

            {{-- 4. Billing & Revenue --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.billing*') ? 'active' : '' }}" 
                   href="{{ route('owner.billing.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-money-bill-wave text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Billing & Revenue</span>
                </a>
            </li>

            {{-- 4b. CFO Dashboard --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.cfo*') ? 'active' : '' }}" 
                   href="{{ route('owner.cfo.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-chart-line text-primary text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">CFO Dashboard</span>
                </a>
            </li>

            {{-- 5. Payment Gateway --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.payment-gateway*') ? 'active' : '' }}" 
                   href="{{ route('owner.payment-gateway.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-credit-card text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Payment Gateway</span>
                </a>
            </li>

            {{-- 5b. Laporan Pajak (PPN) --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.tax-report*') ? 'active' : '' }}" 
                   href="{{ route('owner.tax-report.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-file-invoice-dollar text-warning text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Laporan Pajak</span>
                </a>
            </li>

            {{-- 5c. Monthly Closing --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.closing*') ? 'active' : '' }}" 
                   href="{{ route('owner.closing.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-calendar-check text-success text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Monthly Closing</span>
                </a>
            </li>

            {{-- 5d. Rekonsiliasi Bank & Gateway --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.reconciliation*') ? 'active' : '' }}" 
                   href="{{ route('owner.reconciliation.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-balance-scale text-info text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Rekonsiliasi</span>
                </a>
            </li>

            {{-- 6. Logs & Audit --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.logs*') ? 'active' : '' }}" 
                   href="{{ route('owner.logs.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-history text-dark text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Logs & Audit</span>
                </a>
            </li>

            {{-- 6b. Audit Trail & Immutable Ledger --}}
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('owner.audit-trail*') ? 'active' : '' }}" 
                   href="{{ route('owner.audit-trail.index') }}">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-shield-alt text-danger text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">Audit Trail</span>
                </a>
            </li>

            <hr class="horizontal light my-3">

            {{-- 7. Kembali ke Client Dashboard - WITH CONFIRMATION --}}
            <li class="nav-item">
                <a class="nav-link" href="javascript:;" onclick="showSwitchConfirmModal()">
                    <div class="icon icon-shape icon-sm shadow border-radius-md bg-white text-center me-2 d-flex align-items-center justify-content-center">
                        <i class="fas fa-arrow-left text-info text-sm opacity-10"></i>
                    </div>
                    <span class="nav-link-text ms-1">⬅ Kembali ke Client</span>
                </a>
            </li>
        </ul>
    </div>

    {{-- Owner Info Footer --}}
    <div class="sidenav-footer mx-3 mt-auto mb-3">
        <div class="card card-plain shadow-none" style="background: rgba(255,255,255,0.08) !important; border-radius: 0.75rem;">
            <div class="card-body text-center p-3">
                <div class="icon icon-shape icon-sm bg-gradient-warning shadow text-center border-radius-md mb-2 mx-auto d-flex align-items-center justify-content-center">
                    <span class="text-white text-xs font-weight-bold">{{ strtoupper(substr(auth()->user()->name ?? 'O', 0, 1)) }}</span>
                </div>
                <p class="text-white text-sm mb-0 font-weight-bold">{{ auth()->user()->name ?? 'Owner' }}</p>
                <p class="text-white text-xs opacity-6 mb-2">{{ ucfirst(str_replace('_', ' ', auth()->user()->role ?? 'Owner')) }}</p>
                <form action="{{ route('logout') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-light mb-0 w-100">
                        <i class="fas fa-sign-out-alt me-1"></i> Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
