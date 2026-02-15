@extends('layouts.user_type.auth')

@section('content')
{{-- Growth Engine: Activation Progress Banner (trial_selected only) --}}
@include('components.activation-progress-banner')

{{-- Growth Engine: Soft Scarcity Timer (trial_selected, within 24h of registration) --}}
@include('components.scarcity-timer')

{{-- Stat Cards --}}
<div class="row">
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Total Campaign</p>
              <h5 class="font-weight-bolder mb-0">0</h5>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md">
              <i class="fas fa-rocket text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Pesan Terkirim</p>
              <h5 class="font-weight-bolder mb-0">{{ number_format($jumlahPesanBulanIni ?? 0, 0) }}</h5>
              <p class="text-xs text-secondary mb-0">Bulan ini</p>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
              <i class="fas fa-check text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6 mb-xl-0 mb-4">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Pemakaian</p>
              <h5 class="font-weight-bolder mb-0">Rp {{ number_format($pemakaianBulanIni ?? 0, 0, ',', '.') }}</h5>
              <p class="text-xs text-secondary mb-0">Bulan ini</p>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
              <i class="fas fa-chart-line text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-xl-3 col-sm-6">
    <div class="card">
      <div class="card-body p-3">
        <div class="row">
          <div class="col-8">
            <div class="numbers">
              <p class="text-sm mb-0 text-capitalize font-weight-bold">Saldo WhatsApp</p>
              <h5 class="font-weight-bolder mb-0 {{ ($saldo ?? 0) <= 0 ? 'text-danger' : '' }}">Rp {{ number_format($saldo ?? 0, 0, ',', '.') }}</h5>
              <p class="text-xs text-secondary mb-0">≈ {{ number_format($estimasiPesanTersisa ?? 0, 0) }} pesan</p>
            </div>
          </div>
          <div class="col-4 text-end">
            <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
              <i class="fas fa-wallet text-lg opacity-10" aria-hidden="true"></i>
            </div>
          </div>
        </div>
        <div class="mt-2">
          <button class="btn bg-gradient-success btn-sm w-100" onclick="showTopupModal()">
            <i class="fas fa-plus me-1"></i>Topup Saldo
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Plan Info Card - REDESIGNED FOR BETTER UX --}}
<div class="row mt-4">
  <div class="col-lg-8 mb-lg-0 mb-4">
    <div class="card h-100">
      <div class="card-header pb-0 pt-3 bg-transparent">
        <div class="d-flex justify-content-between align-items-center">
          <div class="d-flex align-items-center">
            <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md me-3">
              <i class="fas fa-crown text-white text-lg opacity-10" aria-hidden="true"></i>
            </div>
            <div>
              <h6 class="font-weight-bolder mb-0">
                Paket Anda: 
                @if($currentPlan)
                  <span class="badge bg-gradient-info ms-1" style="font-size: 0.85em;">{{ $currentPlan->name }}</span>
                @else
                  <span class="badge bg-gradient-secondary ms-1" style="font-size: 0.85em;">Belum Aktif</span>
                @endif
              </h6>
              @if($currentPlan)
                <p class="text-xs text-secondary mb-0">
                  @if(auth()->user()->plan_expires_at)
                    @if($daysRemaining > 0)
                      Berlaku hingga {{ auth()->user()->plan_expires_at->format('d M Y') }} ({{ $daysRemaining }} hari lagi)
                    @else
                      <span class="text-danger fw-bold">Paket sudah berakhir</span>
                    @endif
                  @else
                    Tidak ada batas waktu
                  @endif
                </p>
              @endif
            </div>
          </div>
          {{-- 4. CTA UPGRADE BUTTON --}}
          @if($currentPlan && auth()->user()->isOnStarterPlan())
            <a href="{{ route('subscription.index') }}" class="btn btn-sm bg-gradient-success mb-0">
              <i class="fas fa-arrow-up me-1"></i>Upgrade Paket
            </a>
          @endif
        </div>
      </div>
      
      <div class="card-body pt-3">
        @if($currentPlan)
          {{-- KONSEP FINAL: FEATURE-BASED, NO MORE QUOTAS! --}}
          @if($currentPlan->isFeatureBased && $currentPlan->isFeatureBased())
            {{-- Feature-Based Plan: Show features, NOT quotas --}}
            <div class="alert alert-success d-flex align-items-center mb-3" role="alert">
              <i class="fas fa-check-circle me-2"></i>
              <div class="text-sm">
                <strong>Paket Feature-Based Aktif!</strong><br>
                Pengiriman pesan menggunakan <strong>saldo topup</strong> terpisah.
              </div>
            </div>
            
            {{-- Show current features instead of quotas --}}
            <div class="row">
              <div class="col-md-6">
                <h6 class="font-weight-bold text-sm mb-2">Fitur yang Tersedia:</h6>
                <div class="d-flex flex-wrap gap-1">
                  @if($currentPlan->api_access)
                    <span class="badge bg-gradient-success text-xs"><i class="fas fa-code me-1"></i>API</span>
                  @endif
                  @if($currentPlan->webhook_support)
                    <span class="badge bg-gradient-success text-xs"><i class="fas fa-link me-1"></i>Webhook</span>
                  @endif
                  @if($currentPlan->multi_agent_chat)
                    <span class="badge bg-gradient-success text-xs"><i class="fas fa-users me-1"></i>Multi-Agent</span>
                  @endif
                  @if($currentPlan->advanced_analytics)
                    <span class="badge bg-gradient-success text-xs"><i class="fas fa-chart-bar me-1"></i>Analytics</span>
                  @endif
                  @if($currentPlan->custom_branding)
                    <span class="badge bg-gradient-success text-xs"><i class="fas fa-palette me-1"></i>Branding</span>
                  @endif
                </div>
              </div>
              <div class="col-md-6">
                <h6 class="font-weight-bold text-sm mb-2">Kapasitas:</h6>
                <div class="text-sm text-muted">
                  <i class="fas fa-phone me-1"></i>Nomor WA: {{ $currentPlan->max_wa_numbers ?? 'Unlimited' }}<br>
                  <i class="fas fa-rocket me-1"></i>Campaign: {{ $currentPlan->max_campaigns ?? 'Unlimited' }}
                </div>
              </div>
            </div>
          @else  
            {{-- Legacy Plan: Show deprecation warning --}}
            <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
              <i class="fas fa-exclamation-triangle me-2"></i>
              <div class="text-sm">
                <strong>Paket Lama Terdeteksi</strong><br>
                Upgrade ke paket baru untuk pengalaman terbaik dengan sistem feature-based.
              </div>
              <a href="{{ route('billing') }}" class="btn btn-sm btn-primary ms-auto">Upgrade</a>
            </div>
          @endif
          
          {{-- 3. ALERT SALDO RENDAH - UX PINTAR --}}
          @if(isset($saldoStatus['message']) && $saldoStatus['message'])
            <div class="alert alert-{{ $saldoStatus['level'] === 'danger' ? 'danger' : ($saldoStatus['level'] === 'warning' ? 'warning' : 'info') }} d-flex align-items-center mb-3" role="alert">
              <i class="fas fa-{{ $saldoStatus['icon'] }} me-2"></i>
              <span class="text-sm">{{ $saldoStatus['message'] }}</span>
              @if($saldoStatus['action'] === 'topup')
                <a href="{{ route('billing') }}" class="btn btn-sm btn-primary ms-auto">Topup Sekarang</a>
              @endif
            </div>
          @endif
          
        @else
          {{-- No Plan State --}}
          <div class="text-center py-4">
            <i class="fas fa-box-open text-secondary mb-3" style="font-size: 3rem;"></i>
            <h6 class="text-secondary">Belum Ada Paket Aktif</h6>
            <p class="text-sm text-secondary mb-3">Hubungi admin untuk mengaktifkan paket Anda dan mulai mengirim pesan.</p>
          </div>
        @endif
      </div>
    </div>
  </div>
  
  {{-- Quick Stats Card (Right Side) --}}
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header pb-0 pt-3 bg-transparent">
        <h6 class="font-weight-bolder mb-0">Quick Stats</h6>
      </div>
      <div class="card-body p-3">
        <ul class="list-group">
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm bg-gradient-primary shadow border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                <i class="fas fa-address-book text-white text-xs opacity-10"></i>
              </div>
              <span class="text-sm">Total Kontak</span>
            </div>
            <span class="text-dark font-weight-bold">0</span>
          </li>
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm bg-gradient-success shadow border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                <i class="fas fa-file-alt text-white text-xs opacity-10"></i>
              </div>
              <span class="text-sm">Template Aktif</span>
            </div>
            <span class="text-dark font-weight-bold">0</span>
          </li>
          <li class="list-group-item border-0 d-flex justify-content-between ps-0 border-radius-lg">
            <div class="d-flex align-items-center">
              <div class="icon icon-shape icon-sm bg-gradient-warning shadow border-radius-md text-center me-2 d-flex align-items-center justify-content-center">
                <i class="fas fa-comment-dots text-white text-xs opacity-10"></i>
              </div>
              <span class="text-sm">Chat Aktif</span>
            </div>
            <span class="text-dark font-weight-bold">0</span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

{{-- Welcome & Quick Actions --}}
<div class="row mt-4">
  <div class="col-12">
    <div class="card">
      <div class="card-body p-4">
        <div class="d-flex align-items-center">
          <div class="icon icon-shape bg-gradient-success shadow border-radius-md text-center me-3">
            <i class="fab fa-whatsapp text-lg opacity-10" aria-hidden="true"></i>
          </div>
          <div>
            <h5 class="font-weight-bolder mb-0">Selamat Datang di {{ $__brandName ?? 'Talkabiz' }}</h5>
            <p class="text-sm text-secondary mb-0">Platform WhatsApp Campaign & Inbox untuk bisnis Anda.</p>
          </div>
        </div>
        <hr class="horizontal dark my-4">
        <div class="d-flex flex-wrap gap-2">
          @if($subscriptionIsActive ?? false)
            {{-- Campaign & Inbox buttons — only when subscription is active --}}
            <a href="{{ url('campaign') }}" class="btn bg-gradient-primary btn-sm mb-0">
              <i class="fas fa-rocket me-2"></i>Buat Campaign
            </a>
            <a href="{{ url('inbox') }}" class="btn btn-outline-dark btn-sm mb-0">
              <i class="fas fa-inbox me-2"></i>Buka Inbox
            </a>
          @else
            {{-- HIDDEN — user focus goes to Activation Banner CTA --}}
            <a href="{{ route('subscription.index') }}" class="btn bg-gradient-primary btn-sm mb-0"
               onclick="if(typeof ActivationKpi !== 'undefined') ActivationKpi.track('clicked_pay', {source: 'quick_actions'});">
              <i class="fas fa-bolt me-2"></i>Aktifkan Paket
            </a>
          @endif
          <a href="{{ url('kontak') }}" class="btn btn-outline-dark btn-sm mb-0">
            <i class="fas fa-address-book me-2"></i>Kelola Kontak
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection