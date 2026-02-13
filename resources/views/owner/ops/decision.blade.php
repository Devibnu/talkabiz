@extends('layouts.owner')

@section('title', 'Decision - Week-' . $week)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-dark shadow-lg">
                <div class="card-body p-4">
                    <h2 class="text-white mb-1">
                        🎯 Week-{{ $week }} Final Decision
                    </h2>
                    <p class="text-white opacity-8 mb-0">
                        Tentukan apakah sistem siap untuk SCALE atau perlu HOLD
                    </p>
                </div>
            </div>
        </div>
    </div>

    @if($summary)
        {{-- Summary Overview --}}
        <div class="row mb-4">
            <div class="col-lg-4">
                <div class="card h-100 text-center">
                    <div class="card-body p-4">
                        <h6 class="text-muted">System Recommendation</h6>
                        <span class="badge badge-lg 
                            {{ $summary->recommendation === 'SCALE' ? 'bg-gradient-success' : 
                               ($summary->recommendation === 'HOLD' ? 'bg-gradient-danger' : 'bg-gradient-warning') }}"
                            style="font-size: 1.5rem; padding: 15px 30px;">
                            {{ $summary->recommendation }}
                        </span>
                        <p class="text-muted mt-3 mb-0">Score: {{ $summary->decision_score }}/100</p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <h6 class="text-muted mb-3">Key Metrics</h6>
                        <table class="table table-sm mb-0">
                            <tr>
                                <td>Gross Profit</td>
                                <td class="text-end {{ $summary->gross_profit >= 0 ? 'text-success' : 'text-danger' }}">
                                    Rp {{ number_format($summary->gross_profit, 0, ',', '.') }}
                                </td>
                            </tr>
                            <tr>
                                <td>Avg. Margin</td>
                                <td class="text-end">{{ $summary->avg_margin_percent }}%</td>
                            </tr>
                            <tr>
                                <td>Health Score</td>
                                <td class="text-end">{{ $summary->avg_health_score }}/100</td>
                            </tr>
                            <tr>
                                <td>Delivery Rate</td>
                                <td class="text-end">{{ $summary->delivery_rate }}%</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-body p-4">
                        <h6 class="text-muted mb-3">Issues</h6>
                        @php
                            $blockers = json_decode($summary->blockers ?? '[]', true);
                            $warnings = json_decode($summary->warnings ?? '[]', true);
                        @endphp
                        
                        @if(count($blockers) > 0)
                            <p class="text-danger mb-1 small"><strong>Blockers:</strong></p>
                            <ul class="list-unstyled mb-2 small">
                                @foreach($blockers as $b)
                                    <li class="text-danger">• {{ $b }}</li>
                                @endforeach
                            </ul>
                        @endif
                        
                        @if(count($warnings) > 0)
                            <p class="text-warning mb-1 small"><strong>Warnings:</strong></p>
                            <ul class="list-unstyled mb-0 small">
                                @foreach($warnings as $w)
                                    <li class="text-warning">• {{ $w }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if(count($blockers) === 0 && count($warnings) === 0)
                            <p class="text-success mb-0">✅ No critical issues</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Daily Checks Summary --}}
        @if($dailyChecks && count($dailyChecks) > 0)
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header pb-0">
                            <h6 class="mb-0">📋 Daily Checks Summary</h6>
                        </div>
                        <div class="card-body px-0 pt-0 pb-2">
                            <div class="table-responsive">
                                <table class="table align-items-center mb-0">
                                    <thead>
                                        <tr>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Day</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Date</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                            <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Alerts</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dailyChecks as $check)
                                            <tr>
                                                <td class="ps-4">
                                                    <span class="badge bg-gradient-primary">H+{{ $check->check_day }}</span>
                                                </td>
                                                <td>{{ \Carbon\Carbon::parse($check->check_date)->format('d M Y') }}</td>
                                                <td>
                                                    <span class="badge bg-{{ $check->status === 'completed' ? 'success' : 'warning' }}">
                                                        {{ $check->status }}
                                                    </span>
                                                </td>
                                                <td>
                                                    @if($check->alerts_count > 0)
                                                        <span class="badge bg-danger">{{ $check->alerts_count }}</span>
                                                    @else
                                                        <span class="text-success">✓</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Decision Form --}}
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">🎯 Owner Decision</h6>
                    </div>
                    <div class="card-body">
                        @if($summary->owner_decision !== 'PENDING')
                            <div class="alert alert-{{ $summary->owner_decision === 'SCALE' ? 'success' : 'warning' }}">
                                <strong>Decision Already Made:</strong> {{ $summary->owner_decision }}
                                @if($summary->decision_at)
                                    <br>
                                    <small>on {{ \Carbon\Carbon::parse($summary->decision_at)->format('d M Y H:i') }}</small>
                                @endif
                                @if($summary->owner_notes)
                                    <br><br>
                                    <strong>Notes:</strong> {{ $summary->owner_notes }}
                                @endif
                            </div>
                        @else
                            <form action="{{ route('owner.ops.submit-decision') }}" method="POST">
                                @csrf
                                <input type="hidden" name="week" value="{{ $week }}">

                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <div class="card border cursor-pointer decision-card" data-decision="SCALE">
                                            <div class="card-body text-center p-4">
                                                <h1 class="text-success">🚀</h1>
                                                <h4 class="text-success">SCALE</h4>
                                                <p class="text-muted mb-0">
                                                    Sistem stabil, siap untuk ekspansi.
                                                    Buka marketing & onboarding lebih luas.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card border cursor-pointer decision-card" data-decision="HOLD">
                                            <div class="card-body text-center p-4">
                                                <h1 class="text-warning">⏸️</h1>
                                                <h4 class="text-warning">HOLD</h4>
                                                <p class="text-muted mb-0">
                                                    Ada masalah yang harus diperbaiki dulu.
                                                    Fokus stabilitas, jangan ekspansi.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <input type="hidden" name="decision" id="decisionInput" required>

                                <div class="mb-3">
                                    <label class="form-label">Notes (Optional)</label>
                                    <textarea name="notes" class="form-control" rows="3" 
                                              placeholder="Alasan keputusan, catatan untuk tim, dll."></textarea>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="{{ route('owner.ops.week-summary', ['week' => $week]) }}" 
                                       class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i> Back to Summary
                                    </a>
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitBtn" disabled>
                                        <i class="fas fa-gavel me-2"></i> Submit Decision
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-12">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No summary data available for Week-{{ $week }}. 
                    Please run the weekly summary first.
                </div>
                <a href="{{ route('owner.ops.week-summary', ['week' => $week]) }}" class="btn btn-primary">
                    Generate Week Summary
                </a>
            </div>
        </div>
    @endif
</div>

@push('css')
<style>
.decision-card {
    transition: all 0.3s ease;
}
.decision-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}
.decision-card.selected {
    border-color: #5e72e4 !important;
    border-width: 3px;
    background: rgba(94, 114, 228, 0.05);
}
.cursor-pointer {
    cursor: pointer;
}
</style>
@endpush

@push('js')
<script>
document.querySelectorAll('.decision-card').forEach(card => {
    card.addEventListener('click', function() {
        // Remove selected from all
        document.querySelectorAll('.decision-card').forEach(c => c.classList.remove('selected'));
        
        // Add selected to clicked
        this.classList.add('selected');
        
        // Set hidden input
        document.getElementById('decisionInput').value = this.dataset.decision;
        
        // Enable submit
        document.getElementById('submitBtn').disabled = false;
    });
});
</script>
@endpush
@endsection
