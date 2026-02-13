{{-- 
  Delivery Tips Component
  Menampilkan tips pengiriman untuk user education
  
  Usage: @include('components.delivery-tips', ['tier' => 'starter'])
--}}

@php
    $tier = $tier ?? 'starter';
    $tips = app(\App\Services\DeliveryOptimizationService::class)->getDeliveryTips($tier);
@endphp

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header pb-0 pt-3 bg-transparent">
        <div class="d-flex align-items-center">
            <div class="icon icon-shape bg-gradient-info shadow text-center border-radius-md me-2" style="width: 32px; height: 32px;">
                <i class="fas fa-lightbulb text-white text-sm"></i>
            </div>
            <h6 class="font-weight-bolder mb-0">Tips Pengiriman Aman</h6>
        </div>
    </div>
    <div class="card-body pt-2">
        <ul class="list-unstyled mb-0">
            @foreach($tips as $tip)
            <li class="d-flex align-items-start mb-2">
                <i class="fas fa-{{ $tip['icon'] }} text-info me-2 mt-1" style="width: 16px;"></i>
                <span class="text-sm text-secondary">{{ $tip['text'] }}</span>
            </li>
            @endforeach
        </ul>
    </div>
</div>
