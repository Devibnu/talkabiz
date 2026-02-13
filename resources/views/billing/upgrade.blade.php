@extends('layouts.user_type.auth')

@section('content')
<style>
/* Upgrade Page Styles */
.upgrade-hero {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
}
.upgrade-hero h3 {
    font-weight: 700;
    margin-bottom: 0.5rem;
}
.upgrade-hero p {
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
.feature-list .feature-icon.info {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
}
.comparison-table {
    width: 100%;
    border-collapse: collapse;
}
.comparison-table th,
.comparison-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid #e9ecef;
}
.comparison-table th {
    background: #f8f9fa;
    font-weight: 600;
    color: #344767;
}
.comparison-table td.col-current {
    background: rgba(94,114,228,0.05);
}
.comparison-table td.col-upgrade {
    background: rgba(45,206,137,0.05);
}
.comparison-table .value-upgrade {
    color: #2dce89;
    font-weight: 600;
}
</style>

{{-- Hero Header --}}
<div class="row">
  <div class="col-12">
    <div class="upgrade-hero">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h3><i class="fas fa-rocket me-2"></i>Upgrade Paket</h3>
        </div>
        <a href="{{ route('dashboard') }}" class="btn btn-outline-light btn-sm">
          <i class="fas fa-arrow-left me-1"></i>Kembali
        </a>
      </div>
    </div>
  </div>
</div>

{{-- Current Plan Info --}}
<div class="row mb-4">
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <div class="d-flex align-items-center">
          <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md me-3">
            <i class="fas fa-crown text-white text-lg"></i>
          </div>
          <div>
            <h6 class="mb-0 font-weight-bold">Paket Aktif Anda:
              @if($currentPlan)
                <span class="badge bg-gradient-info">{{ $currentPlan->name }}</span>
              @else
                <span class="badge bg-gradient-secondary">—</span>
              @endif
            </h6>
            <p class="text-sm text-secondary mb-0">
              @if($currentPlan)
                Paket: <strong>{{ $currentPlan->name }}</strong> 
                • Pesan: <strong>{{ number_format($estimasiPesanTersisa, 0, ',', '.') }}</strong> tersisa 
                (Rp{{ number_format($saldo, 0, ',', '.') }} saldo)
              @else
                Belum berlangganan paket. Topup saldo: <strong>Rp{{ number_format($saldo, 0, ',', '.') }}</strong>
              @endif
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{--
  All plan data rendered from $availablePlans (active + self-serve + visible + ordered).
  Source: plans table via Owner Panel.
--}}

@if($availablePlans->isEmpty())
  <div class="row">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <i class="fas fa-box-open text-secondary" style="font-size: 3rem;"></i>
          <h5 class="mt-3 font-weight-bold">Tidak ada paket aktif tersedia</h5>
        </div>
      </div>
    </div>
  </div>
@else
  @php
    // Count cards to render (current plan + upgrades)
    $upgradePlans = $availablePlans->filter(fn($p) => !$currentPlan || $p->id !== $currentPlan->id);
    
    // Starter should NOT be displayed as pricing card - only as status info above
    $shouldShowCurrentPlanCard = $currentPlan && $currentPlan->code !== 'umkm-starter';
    $totalCards = ($shouldShowCurrentPlanCard ? 1 : 0) + $upgradePlans->count();
    $colSize = max(4, (int) floor(12 / $totalCards));
  @endphp

  <div class="row">
    {{-- Current Plan Card (SKIP Starter - already shown as status above) --}}
    @if($shouldShowCurrentPlanCard)
    <div class="col-lg-{{ $colSize }} mb-4" style="min-width: 320px;">
      <div class="card plan-card current">
        <div class="plan-header">
          <span class="plan-badge current mb-2">Paket Saat Ini</span>
          <h4 class="font-weight-bolder mb-1">{{ $currentPlan->name }}</h4>
          @if($currentPlan->description)
            <p class="text-secondary text-sm mb-2">{{ $currentPlan->description }}</p>
          @endif
          <h2 class="font-weight-bolder mb-0">
            {{ $currentPlan->formatted_price }}
            @if($currentPlan->duration_days > 0)
              <span class="text-sm text-secondary font-weight-normal">/{{ $currentPlan->duration_days }} hari</span>
            @endif
          </h2>
        </div>
        <div class="plan-body">
          <ul class="feature-list">
            {{-- Feature-based plan display (NEW CONCEPT) --}}
            @if($currentPlan->isFeatureBased && $currentPlan->isFeatureBased())
              {{-- WhatsApp & Campaign Features --}}
              @if($currentPlan->max_wa_numbers)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->max_wa_numbers === 0 ? 'Unlimited' : $currentPlan->max_wa_numbers }} nomor WhatsApp</span>
              </li>
              @endif
              @if($currentPlan->max_campaigns)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->max_campaigns === 0 ? 'Unlimited' : $currentPlan->max_campaigns }} campaign aktif</span>
              </li>
              @endif
              @if($currentPlan->max_campaign_recipients)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->max_campaign_recipients === 0 ? 'Unlimited' : number_format($currentPlan->max_campaign_recipients, 0, ',', '.') }} penerima/campaign</span>
              </li>
              @endif
              @if($currentPlan->max_team_members > 1)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->max_team_members }} anggota tim</span>
              </li>
              @endif
              
              {{-- API & Automation Features --}}
              @if($currentPlan->api_access)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>API Access 
                  @if($currentPlan->api_rate_limit && $currentPlan->api_rate_limit > 0)
                    ({{ number_format($currentPlan->api_rate_limit) }}/jam)
                  @endif
                </span>
              </li>
              @endif
              @if($currentPlan->webhook_support)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Webhook Support</span>
              </li>
              @endif
              @if($currentPlan->max_automation_flows)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->max_automation_flows === 0 ? 'Unlimited' : $currentPlan->max_automation_flows }} automation flows</span>
              </li>
              @endif
              @if($currentPlan->has_advanced_automation)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Advanced Automation</span>
              </li>
              @endif
              
              {{-- Team & Analytics Features --}}
              @if($currentPlan->multi_agent_chat)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Multi-Agent Chat</span>
              </li>
              @endif
              @if($currentPlan->advanced_analytics)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Advanced Analytics</span>
              </li>
              @endif
              @if($currentPlan->export_data)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Data Export</span>
              </li>
              @endif
              
              {{-- Support & Premium Features --}}
              @if($currentPlan->support_level && $currentPlan->support_level !== 'basic')
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ ucfirst($currentPlan->support_level) }} Support</span>
              </li>
              @endif
              @if($currentPlan->custom_branding)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>Custom Branding</span>
              </li>
              @endif
              
              {{-- NEW: Message cost separation notice --}}
              <li class="mt-3 pt-3" style="border-top: 1px solid #e9ecef;">
                <span class="feature-icon info text-info"><i class="fas fa-info-circle"></i></span>
                <small class="text-info"><strong>Pengiriman pesan:</strong> Menggunakan <strong>saldo topup</strong> terpisah dari paket</small>
              </li>
              
            @else
              {{-- Legacy quota display (untuk plan lama) --}}
              @if($currentPlan->limit_messages_monthly)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ number_format($currentPlan->limit_messages_monthly, 0, ',', '.') }} pesan/bulan</span>
              </li>
              @endif
              @if($currentPlan->limit_messages_daily)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ number_format($currentPlan->limit_messages_daily, 0, ',', '.') }} pesan/hari</span>
              </li>
              @endif
              @if($currentPlan->limit_wa_numbers)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->limit_wa_numbers }} nomor WhatsApp</span>
              </li>
              @endif
              @if($currentPlan->limit_active_campaigns)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ $currentPlan->limit_active_campaigns }} campaign aktif</span>
              </li>
              @endif
              @if($currentPlan->limit_recipients_per_campaign)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ number_format($currentPlan->limit_recipients_per_campaign, 0, ',', '.') }} penerima/campaign</span>
              </li>
              @endif
            @endif
            
            {{-- Legacy features array (for backward compatibility) --}}
            @if(is_array($currentPlan->features))
              @foreach($currentPlan->features as $feature)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ ucfirst(str_replace('_', ' ', $feature)) }}</span>
              </li>
              @endforeach
            @endif
          </ul>
          <button class="btn btn-outline-secondary w-100 mt-3" disabled>
            <i class="fas fa-check me-1"></i>Paket Aktif Anda
          </button>
        </div>
      </div>
    </div>
    @endif

    {{-- Available Upgrade Plans (SSOT from DB) --}}
    @foreach($upgradePlans as $plan)
    <div class="col-lg-{{ $colSize }} mb-4" style="min-width: 320px;">
      <div class="card plan-card {{ $plan->is_recommended ? 'recommended' : '' }}">
        <div class="plan-header">
          @if($plan->badge_text)
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
            @if($plan->limit_messages_monthly)
            <li>
              <span class="feature-icon included"><i class="fas fa-check"></i></span>
              <span>{{ number_format($plan->limit_messages_monthly, 0, ',', '.') }} pesan/bulan</span>
            </li>
            @endif
            @if($plan->limit_messages_daily)
            <li>
              <span class="feature-icon included"><i class="fas fa-check"></i></span>
              <span>{{ number_format($plan->limit_messages_daily, 0, ',', '.') }} pesan/hari</span>
            </li>
            @endif
            @if($plan->limit_wa_numbers)
            <li>
              <span class="feature-icon included"><i class="fas fa-check"></i></span>
              <span>{{ $plan->limit_wa_numbers }} nomor WhatsApp</span>
            </li>
            @endif
            @if($plan->limit_active_campaigns)
            <li>
              <span class="feature-icon included"><i class="fas fa-check"></i></span>
              <span>{{ $plan->limit_active_campaigns }} campaign aktif</span>
            </li>
            @endif
            @if($plan->limit_recipients_per_campaign)
            <li>
              <span class="feature-icon included"><i class="fas fa-check"></i></span>
              <span>{{ number_format($plan->limit_recipients_per_campaign, 0, ',', '.') }} penerima/campaign</span>
            </li>
            @endif
            @if(is_array($plan->features))
              @foreach($plan->features as $feature)
              <li>
                <span class="feature-icon included"><i class="fas fa-check"></i></span>
                <span>{{ ucfirst(str_replace('_', ' ', $feature)) }}</span>
              </li>
              @endforeach
            @endif
          </ul>
          <button class="btn bg-gradient-success w-100 mt-3" data-bs-toggle="modal" data-bs-target="#upgradeModal-{{ $plan->id }}">
            <i class="fas fa-arrow-up me-1"></i>Upgrade ke {{ $plan->name }}
          </button>
        </div>
      </div>
    </div>
    @endforeach
  </div>

  {{-- Comparison Table (exclude Starter - internal plan) --}}
  @php
    $comparePlans = collect();
    // Only include current plan in comparison if it's NOT Starter  
    if ($currentPlan && $currentPlan->code !== 'umkm-starter') {
        $comparePlans->push($currentPlan);
    }
    foreach ($upgradePlans as $plan) {
        $comparePlans->push($plan);
    }
  @endphp

  @if($comparePlans->count() >= 2)
  <div class="row mt-2">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header pb-0">
          <h5 class="font-weight-bolder mb-1">Perbandingan</h5>
        </div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="comparison-table">
              <thead>
                <tr>
                  <th style="width: 30%">Fitur</th>
                  @foreach($comparePlans as $cplan)
                    <th class="text-center" style="width: {{ 70 / $comparePlans->count() }}%">
                      {{ $cplan->name }}
                      @if($currentPlan && $cplan->id === $currentPlan->id)
                        <br><small class="text-muted">(saat ini)</small>
                      @endif
                    </th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                {{-- Feature-based comparison (NEW CONCEPT) --}}
                <tr>
                  <td><i class="fab fa-whatsapp text-success me-2"></i>Nomor WhatsApp</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        {{ $cplan->max_wa_numbers === 0 ? 'Unlimited' : ($cplan->max_wa_numbers ?? '-') }}
                      @else
                        {{ $cplan->limit_wa_numbers ?? '-' }}
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-rocket text-warning me-2"></i>Campaign Aktif</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        {{ $cplan->max_campaigns === 0 ? 'Unlimited' : ($cplan->max_campaigns ?? '-') }}
                      @else
                        {{ $cplan->limit_active_campaigns ?? '-' }}
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-users text-danger me-2"></i>Penerima/Campaign</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        {{ $cplan->max_campaign_recipients === 0 ? 'Unlimited' : ($cplan->max_campaign_recipients ? number_format($cplan->max_campaign_recipients, 0, ',', '.') : '-') }}
                      @else
                        {{ $cplan->limit_recipients_per_campaign ? number_format($cplan->limit_recipients_per_campaign, 0, ',', '.') : '-' }}
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-users-cog text-info me-2"></i>Anggota Tim</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        {{ $cplan->max_team_members ?? '1' }}
                      @else
                        -
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-code text-primary me-2"></i>API Access</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        @if($cplan->api_access)
                          <i class="fas fa-check text-success"></i>
                          @if($cplan->api_rate_limit && $cplan->api_rate_limit > 0)
                            <br><small>{{ number_format($cplan->api_rate_limit) }}/jam</small>
                          @endif
                        @else
                          <i class="fas fa-times text-danger"></i>
                        @endif
                      @else
                        @if(is_array($cplan->features) && in_array('api', $cplan->features))
                          <i class="fas fa-check text-success"></i>
                        @else
                          <i class="fas fa-times text-danger"></i>
                        @endif
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-chart-bar text-success me-2"></i>Advanced Analytics</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        @if($cplan->advanced_analytics)
                          <i class="fas fa-check text-success"></i>
                        @else
                          <i class="fas fa-times text-danger"></i>
                        @endif
                      @else
                        @if(is_array($cplan->features) && in_array('analytics', $cplan->features))
                          <i class="fas fa-check text-success"></i>
                        @else
                          <i class="fas fa-times text-danger"></i>
                        @endif
                      @endif
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-headset text-warning me-2"></i>Support Level</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center {{ $currentPlan && $cplan->id !== $currentPlan->id ? 'value-upgrade' : '' }}">
                      @if($cplan->isFeatureBased && $cplan->isFeatureBased())
                        {{ ucfirst($cplan->support_level ?? 'basic') }}
                      @else
                        Basic
                      @endif
                    </td>
                  @endforeach
                </tr>
                {{-- Important: Message cost separation notice --}}
                <tr class="bg-light">
                  <td><i class="fas fa-info-circle text-info me-2"></i><strong>Pengiriman Pesan</strong></td>
                  @foreach($comparePlans as $cplan)
                    <td class="text-center">
                      <small class="text-info"><strong>Saldo Topup</strong><br>(Terpisah dari paket)</small>
                    </td>
                  @endforeach
                </tr>
                <tr>
                  <td><i class="fas fa-tag text-success me-2"></i>Harga</td>
                  @foreach($comparePlans as $cplan)
                    <td class="{{ $currentPlan && $cplan->id === $currentPlan->id ? 'col-current' : 'col-upgrade' }} text-center">
                      <strong>{{ $cplan->formatted_price }}</strong>
                      @if($cplan->duration_days > 0)
                        <br><small class="text-muted">/{{ $cplan->duration_days }} hari</small>
                      @endif
                    </td>
                  @endforeach
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  @endif
@endif

{{-- Upgrade Modals — one per available plan (SSOT) --}}
@foreach($availablePlans as $plan)
  @if(!$currentPlan || $plan->id !== $currentPlan->id)
  <div class="modal fade" id="upgradeModal-{{ $plan->id }}" tabindex="-1" aria-labelledby="upgradeModalLabel-{{ $plan->id }}" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title font-weight-bolder" id="upgradeModalLabel-{{ $plan->id }}">
            <i class="fas fa-rocket text-success me-2"></i>Upgrade ke {{ $plan->name }}
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center py-4">
          <p class="text-secondary mb-4">
            Untuk upgrade ke <strong>{{ $plan->name }}</strong> ({{ $plan->formatted_price }}/{{ $plan->duration_days }} hari), silakan hubungi tim kami:
          </p>

          <div class="d-grid gap-2">
            @if($__brandWhatsappSalesUrl ?? null)
              <a href="{{ $__brandWhatsappSalesUrl }}?text={{ urlencode('Halo, saya ingin upgrade ke paket ' . $plan->name) }}"
                 target="_blank"
                 class="btn bg-gradient-success">
                <i class="fab fa-whatsapp me-2"></i>Hubungi via WhatsApp
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
