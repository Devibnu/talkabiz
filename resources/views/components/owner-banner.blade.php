{{-- 
    OWNER BANNER COMPONENT (CLIENT VIEW)
    Displayed on Client Dashboard for users with owner/super_admin role
    Shows warning that they are in CLIENT VIEW mode
    One-click access to Owner Panel
--}}

<div class="client-view-banner">
    <div class="banner-content">
        <div class="banner-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <span class="d-none d-md-inline">Anda sedang berada di <strong>CLIENT VIEW</strong></span>
            <span class="d-md-none"><strong>CLIENT VIEW</strong></span>
        </div>
        <a href="{{ route('owner.dashboard') }}" class="banner-btn">
            <i class="fas fa-crown me-1"></i>
            <span class="d-none d-sm-inline">Kembali ke Owner Panel</span>
            <span class="d-sm-none">Owner Panel</span>
        </a>
    </div>
</div>

<style>
    .client-view-banner {
        background: linear-gradient(90deg, #f59e0b, #d97706);
        color: white;
        padding: 0 16px;
        text-align: center;
        font-size: 13px;
        font-weight: 500;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1100;
        height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 10px rgba(245, 158, 11, 0.3);
    }
    
    .client-view-banner .banner-content {
        display: flex;
        align-items: center;
        gap: 16px;
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .client-view-banner .banner-warning {
        color: white;
        display: flex;
        align-items: center;
    }
    
    .client-view-banner .banner-btn {
        background: rgba(255, 255, 255, 0.25);
        color: white;
        padding: 5px 14px;
        border-radius: 20px;
        font-weight: 600;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 1px solid rgba(255, 255, 255, 0.4);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .client-view-banner .banner-btn:hover {
        background: rgba(255, 255, 255, 0.4);
        color: white;
        transform: scale(1.02);
    }
    
    /* Offset body content for the banner */
    body.g-sidenav-show {
        padding-top: 40px;
    }
    
    body.g-sidenav-show .sidenav {
        top: 40px;
        height: calc(100vh - 40px);
    }
    
    @media (max-width: 575.98px) {
        .client-view-banner {
            font-size: 11px;
            padding: 6px 10px;
            height: 36px;
        }
        
        .client-view-banner .banner-btn {
            padding: 3px 10px;
            font-size: 11px;
        }
        
        body.g-sidenav-show {
            padding-top: 36px;
        }
        
        body.g-sidenav-show .sidenav {
            top: 36px;
            height: calc(100vh - 36px);
        }
    }
</style>
