<nav class="navbar navbar-main navbar-expand-lg px-0 mx-4 shadow-none border-radius-xl" id="navbarBlur" navbar-scroll="true">
    <div class="container-fluid py-1 px-3">
        {{-- Mobile Toggle - Hamburger --}}
        <div class="d-xl-none">
            <a href="javascript:;" class="nav-link text-body p-0" id="iconNavbarSidenav">
                <div class="sidenav-toggler-inner">
                    <i class="sidenav-toggler-line"></i>
                    <i class="sidenav-toggler-line"></i>
                    <i class="sidenav-toggler-line"></i>
                </div>
            </a>
        </div>
        
        {{-- Breadcrumb --}}
        <nav aria-label="breadcrumb" class="d-none d-sm-block">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0 me-sm-6 me-5">
                <li class="breadcrumb-item text-sm">
                    <a class="opacity-5 text-dark" href="{{ route('owner.dashboard') }}">Owner</a>
                </li>
                <li class="breadcrumb-item text-sm text-dark active text-capitalize" aria-current="page">
                    @yield('page-title', str_replace('-', ' ', request()->segment(2) ?: 'Dashboard'))
                </li>
            </ol>
            <h6 class="font-weight-bolder mb-0 text-capitalize">
                @yield('page-title', str_replace('-', ' ', request()->segment(2) ?: 'Dashboard'))
            </h6>
        </nav>

        <div class="collapse navbar-collapse mt-sm-0 mt-2 me-md-0 me-sm-4 justify-content-end" id="navbar">
            <ul class="navbar-nav justify-content-end align-items-center">
                {{-- Owner Mode Badge - PERMANENT INDICATOR --}}
                <li class="nav-item d-flex align-items-center me-3">
                    <span class="badge bg-gradient-dark px-3 py-2">
                        <i class="fas fa-shield-alt me-1"></i>
                        <span class="d-none d-sm-inline">OWNER MODE</span>
                    </span>
                </li>

                {{-- Current Time --}}
                <li class="nav-item d-flex align-items-center d-none d-lg-flex">
                    <span class="text-sm text-muted me-3">
                        <i class="fas fa-clock me-1"></i>
                        <span id="current-time">{{ now()->format('H:i') }}</span>
                    </span>
                </li>

                {{-- User Dropdown --}}
                <li class="nav-item dropdown pe-2 d-flex align-items-center">
                    <a href="javascript:;" class="nav-link text-body p-0 dropdown-toggle" id="ownerDropdown" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="badge bg-gradient-warning text-white me-2 d-none d-sm-inline-block">
                            <i class="fas fa-crown me-1"></i>OWNER
                        </span>
                        <span class="d-none d-md-inline">{{ auth()->user()->name ?? 'Owner' }}</span>
                        <i class="fas fa-user-circle d-md-none fs-5"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end px-2 py-3 shadow-lg" aria-labelledby="ownerDropdown" style="min-width: 220px;">
                        {{-- Owner Dashboard --}}
                        <li>
                            <a class="dropdown-item border-radius-md {{ request()->routeIs('owner.dashboard*') ? 'active bg-gradient-primary text-white' : '' }}" 
                               href="{{ route('owner.dashboard') }}">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard Owner
                            </a>
                        </li>
                        {{-- User Management --}}
                        <li>
                            <a class="dropdown-item border-radius-md" href="{{ route('owner.users.index') }}">
                                <i class="fas fa-users-cog me-2"></i> User Management
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        {{-- Switch to Client View - WITH CONFIRMATION --}}
                        <li>
                            <a class="dropdown-item border-radius-md text-info" href="javascript:;" 
                               onclick="showSwitchConfirmModal()">
                                <i class="fas fa-exchange-alt me-2"></i> Switch to Client View
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        {{-- Logout --}}
                        <li>
                            <a class="dropdown-item border-radius-md text-danger" href="#"
                               onclick="event.preventDefault(); document.getElementById('owner-logout-form').submit();">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

{{-- Switch to Client View Confirmation Modal --}}
<div class="modal fade" id="switchViewModal" tabindex="-1" aria-labelledby="switchViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title" id="switchViewModalLabel">
                    <i class="fas fa-exchange-alt text-info me-2"></i>
                    Pindah ke Client View?
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-sm text-secondary mb-0">
                    <strong>Anda akan meninggalkan Owner Mode dan masuk ke Client Dashboard.</strong>
                </p>
                <p class="text-sm text-secondary mt-2 mb-0">
                    Lanjutkan?
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-sm btn-outline-secondary mb-0" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Batal
                </button>
                <a href="{{ route('dashboard') }}" class="btn btn-sm btn-info mb-0">
                    <i class="fas fa-check me-1"></i> Ya, Pindah
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Update time every minute
setInterval(function() {
    const timeEl = document.getElementById('current-time');
    if (timeEl) {
        const now = new Date();
        timeEl.textContent = now.toLocaleTimeString('id-ID', {hour: '2-digit', minute: '2-digit'});
    }
}, 60000);

// Show switch confirmation modal
function showSwitchConfirmModal() {
    const modal = new bootstrap.Modal(document.getElementById('switchViewModal'));
    modal.show();
}
</script>
