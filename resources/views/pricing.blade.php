@extends('layouts.user_type.auth')

@section('content')
<style>
/* Pricing Page Styles - SSOT Version */
.pricing-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
}
.pricing-hero h3 {
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.pricing-hero p {
    opacity: 0.9;
    margin-bottom: 0;
}

.plan-card {
    border-radius: 16px;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
    height: 100%;
}
.plan-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 40px rgba(0,0,0,0.12);
}
.plan-card.current {
    border-color: #5e72e4;
    background: linear-gradient(180deg, rgba(94,114,228,0.05) 0%, rgba(255,255,255,1) 100%);
}
.plan-card.recommended {
    border-color: #2dce89;
    box-shadow: 0 8px 32px rgba(45,206,137,0.2);
}
.plan-card .plan-header {
    padding: 1.5rem;
    text-align: center;
    border-bottom: 1px solid #e9ecef;
}
.plan-card .plan-body {
    padding: 1.5rem;
}
.plan-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.plan-badge.current {
    background: #5e72e4;
    color: white;
}
.plan-badge.recommended {
    background: #2dce89;
    color: white;
}

.feature-list {
    list-style: none;
    padding: 0;
    margin: 0;
}
.feature-list li {
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f2f5;
    display: flex;
    align-items: center;
}
.feature-list li:last-child {
    border-bottom: none;
}
.feature-list .feature-icon {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 12px;
    font-size: 0.75rem;
    flex-shrink: 0;
}
.feature-list .feature-icon.included {
    background: rgba(45,206,137,0.15);
    color: #2dce89;
}

.topup-notice {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border: none;
    color: white;
    border-radius: 12px;
}
.topup-notice .card-body {
    padding: 1.5rem;
}
</style>

{{-- Hero Header --}}
<div class="row">
  <div class="col-12">
    <div class="pricing-hero">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h3><i class="fas fa-tags me-2"></i>Pilih Paket Berlangganan</h3>
          <p>Dapatkan akses fitur sesuai kebutuhan bisnis Anda</p>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-light btn-sm">
          <i class="fas fa-arrow-left me-1"></i>Kembali
        </a>
      </div>
    </div>
  </div>
</div>

{{-- Important Notice: Subscription vs Topup Model --}}
<div class="row mb-4">
  <div class="col-12">
    <div class="card topup-notice">
      <div class="card-body text-center">
        <h5 class="text-white mb-3">
          <i class="fas fa-info-circle me-2"></i>Model Berlangganan + Topup
        </h5>
        <p class="text-white mb-1" style="opacity: 0.95;">
          <strong>Paket Berlangganan</strong> memberikan akses fitur dan tools. 
          <strong>Pengiriman pesan WhatsApp</strong> menggunakan saldo (topup) terpisah.
        </p>
        <p class="text-white mb-0" style="opacity: 0.9; font-size: 0.9rem;">
          Saldo dipotong sesuai pemakaian berdasarkan tarif Meta WhatsApp Business API.
        </p>
      </div>
    </div>
  </div>
</div>

{{--
  All plan data rendered from database (SSOT).
  This is a READ-ONLY mirror of Owner Panel Plans.
--}}
@php
  // Fetch available plans from database (SSOT)
  $availablePlans = \App\Models\Plan::active()->selfServe()->visible()->ordered()->get();
  $currentPlan = auth()->user()->currentPlan ?? null;
@endphp

@if($availablePlans->isEmpty())
  <div class="row">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <i class="fas fa-box-open text-secondary" style="font-size: 3rem;"></i>
          <h5 class="mt-3 font-weight-bold">Belum ada paket tersedia</h5>
          <p class="text-secondary mb-0">
            Hubungi admin untuk informasi lebih lanjut tentang paket berlangganan.
          </p>
        </div>
      </div>
    </div>
  </div>
@else
  @php
    $totalCards = $availablePlans->count();
    $colSize = $totalCards <= 2 ? 6 : ($totalCards <= 3 ? 4 : 3);
  @endphp

  <div class="row">
    @foreach($availablePlans as $plan)
    <div class="col-lg-{{ $colSize }} mb-4" style="min-width: 300px;">
      <div class="card plan-card {{ $plan->is_recommended ? 'recommended' : '' }} {{ $currentPlan && $currentPlan->id === $plan->id ? 'current' : '' }}">
        <div class="plan-header">
          @if($currentPlan && $currentPlan->id === $plan->id)
            <span class="plan-badge current mb-2">Paket Aktif</span>
          @elseif($plan->badge_text)
            <span class="plan-badge recommended mb-2">
              <i class="fas fa-star me-1"></i>{{ $plan->badge_text }}
            </span>
          @endif
          <h4 class="font-weight-bolder mb-1">{{ $plan->name }}</h4>
          @if($plan->description)
            <p class="text-secondary text-sm mb-2">{{ $plan->description }}</p>
          @endif
          <h2 class="font-weight-bolder mb-0">
            @if($plan->discount_price && $plan->discount_price > 0 && $plan->discount_price < $plan->price)
              <small class="text-decoration-line-through text-secondary" style="font-size: 0.6em;">{{ $plan->formatted_original_price }}</small><br>
            @endif
            {{ $plan->formatted_price }}
            @if($plan->duration_days > 0)
              <span class="text-sm text-secondary font-weight-normal">/{{ $plan->duration_days }} hari</span>
            @endif
          </h2>
        </div>
        <div class="plan-body">
          <ul class="feature-list">
            {{-- Display features from database (SSOT - NO message quotas) --}}
            @if(is_array($plan->features) && count($plan->features) > 0)
              @foreach($plan->features as $feature)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ ucfirst(str_replace('_', ' ', $feature)) }}</span>
              </li>
              @endforeach
            @else
              {{-- Standard features (NO quotas/limits displayed) --}}
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>WhatsApp Broadcasting</span>
              </li>
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Campaign Management</span>
              </li>
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Contact Management</span>
              </li>
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Analytics & Reports</span>
              </li>
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Customer Support</span>
              </li>
              @if($plan->code !== 'umkm-starter')
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Advanced Features</span>
              </li>
              @endif
            @endif
          </ul>
          
          {{-- Important Topup Notice --}}
          <div class="alert alert-info mt-3 mb-3" style="font-size: 0.85rem; padding: 0.75rem;">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Info Penting:</strong> Pengiriman pesan WhatsApp menggunakan saldo (topup) terpisah. Saldo dipotong sesuai pemakaian.
          </div>
          
          @if($currentPlan && $currentPlan->id === $plan->id)
            <button class="btn btn-outline-secondary w-100 mt-3" disabled>
              <i class="fas fa-check me-1"></i>Paket Aktif Anda
            </button>
          @elseif($plan->is_enterprise || !$plan->is_self_serve)
            <button class="btn bg-gradient-dark w-100 mt-3" data-bs-toggle="modal" data-bs-target="#contactModal-{{ $plan->id }}">
              <i class="fas fa-headset me-1"></i>Hubungi Tim Sales
            </button>
          @else
            <button class="btn bg-gradient-success w-100 mt-3" data-bs-toggle="modal" data-bs-target="#subscribeModal-{{ $plan->id }}">
              <i class="fas fa-rocket me-1"></i>Mulai Berlangganan
            </button>
          @endif
        </div>
      </div>
    </div>
    @endforeach
  </div>
@endif

{{-- Subscription/Contact Modals --}}
@foreach($availablePlans as $plan)
  {{-- Self-serve Subscription Modal --}}
  @if($plan->is_self_serve && !$plan->is_enterprise && (!$currentPlan || $currentPlan->id !== $plan->id))
  <div class="modal fade" id="subscribeModal-{{ $plan->id }}" tabindex="-1" aria-labelledby="subscribeModalLabel-{{ $plan->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title font-weight-bolder" id="subscribeModalLabel-{{ $plan->id }}">
            <i class="fas fa-rocket text-success me-2"></i>Berlangganan {{ $plan->name }}
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center py-4">
          <p class="text-secondary mb-4">
            Investasi untuk <strong>{{ $plan->name }}</strong>: <strong>{{ $plan->formatted_price }}/{{ $plan->duration_days }} hari</strong>
          </p>
          <div class="alert alert-info text-start">
            <small>
              <i class="fas fa-info-circle me-2"></i>
              <strong>Berisi:</strong> Akses fitur dan tools sesuai paket. Pengiriman pesan tetap menggunakan saldo topup terpisah.
            </small>
          </div>

          <div class="d-grid gap-2">
            @if($__brandWhatsappSalesUrl ?? null)
              <a href="{{ $__brandWhatsappSalesUrl }}?text={{ urlencode('Halo, saya ingin berlangganan paket ' . $plan->name . ' (' . $plan->formatted_price . '/' . $plan->duration_days . ' hari)') }}"
                 target="_blank"
                 class="btn bg-gradient-success">
                <i class="fab fa-whatsapp me-2"></i>Berlangganan via WhatsApp
              </a>
            @else
              <p class="text-danger text-sm mb-0">
                <i class="fas fa-exclamation-circle me-1"></i>
                Nomor WhatsApp Sales belum dikonfigurasi. Hubungi admin.
              </p>
            @endif
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Tutup</button>
        </div>
      </div>
    </div>
  </div>
  @endif

  {{-- Enterprise/Contact Sales Modal --}}
  @if(!$plan->is_self_serve || $plan->is_enterprise)
  <div class="modal fade" id="contactModal-{{ $plan->id }}" tabindex="-1" aria-labelledby="contactModalLabel-{{ $plan->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title font-weight-bolder" id="contactModalLabel-{{ $plan->id }}">
            <i class="fas fa-headset text-primary me-2"></i>{{ $plan->name }} - Hubungi Sales
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center py-4">
          <p class="text-secondary mb-4">
            Paket <strong>{{ $plan->name }}</strong> memerlukan konsultasi khusus untuk kebutuhan enterprise.
          </p>

          <div class="d-grid gap-2">
            @if($__brandWhatsappSalesUrl ?? null)
              <a href="{{ $__brandWhatsappSalesUrl }}?text={{ urlencode('Halo, saya tertarik dengan paket ' . $plan->name . ' untuk kebutuhan enterprise. Mohon informasi lebih lanjut.') }}"
                 target="_blank"
                 class="btn bg-gradient-primary">
                <i class="fab fa-whatsapp me-2"></i>Konsultasi via WhatsApp
              </a>
            @else
              <p class="text-danger text-sm mb-0">
                <i class="fas fa-exclamation-circle me-1"></i>
                Nomor WhatsApp Sales belum dikonfigurasi. Hubungi admin.
              </p>
            @endif
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-link text-secondary" data-bs-dismiss="modal">Tutup</button>
        </div>
      </div>
    </div>
  </div>
  @endif
@endforeach

@endsection
