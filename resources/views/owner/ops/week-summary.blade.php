@extends('layouts.owner')

@section('title', 'Week-' . $week . ' Summary')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-dark shadow-lg">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h2 class="text-white mb-1">
                                📊 Week-{{ $week }} Post Go-Live Summary
                            </h2>
                            @if($summary)
                                <p class="text-white opacity-8 mb-0">
                                    {{ \Carbon\Carbon::parse($summary['week_start'])->format('d M Y') }} - 
                                    {{ \Carbon\Carbon::parse($summary['week_end'])->format('d M Y') }}
                                </p>
                            @endif
                        </div>
                        <div class="col-lg-4 text-end">
                            @if($week > 1)
                                <a href="{{ route('owner.ops.week-summary', ['week' => $week - 1]) }}" 
                                   class="btn btn-outline-white btn-sm me-2">
                                    <i class="fas fa-arrow-left"></i> Week {{ $week - 1 }}
                                </a>
                            @endif
                            <a href="{{ route('owner.ops.week-summary', ['week' => $week + 1]) }}" 
                               class="btn btn-white btn-sm">
                                Week {{ $week + 1 }} <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if($summary && isset($summary['summary']))
        @php
            $data = $summary['summary'];
            $decision = $data['decision'] ?? [];
        @endphp

        {{-- Decision Score Card --}}
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body text-center p-4">
                        <h6 class="text-muted mb-3">DECISION SCORE</h6>
                        <div class="display-1 fw-bold mb-3 
                            {{ ($decision['score'] ?? 0) >= 75 ? 'text-success' : (($decision['score'] ?? 0) >= 60 ? 'text-warning' : 'text-danger') }}">
                            {{ $decision['score'] ?? 0 }}
                        </div>
                        <div class="progress mb-3" style="height: 10px;">
                            <div class="progress-bar 
                                {{ ($decision['score'] ?? 0) >= 75 ? 'bg-success' : (($decision['score'] ?? 0) >= 60 ? 'bg-warning' : 'bg-danger') }}" 
                                style="width: {{ $decision['score'] ?? 0 }}%"></div>
                        </div>
                        <span class="badge badge-lg 
                            {{ ($decision['recommendation'] ?? '') === 'SCALE' ? 'bg-gradient-success' : 
                               (($decision['recommendation'] ?? '') === 'HOLD' ? 'bg-gradient-danger' : 'bg-gradient-warning') }}"
                            style="font-size: 1.5rem; padding: 15px 30px;">
                            🎯 {{ $decision['recommendation'] ?? 'REVIEW' }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body p-4">
                        {{-- Blockers --}}
                        @if(!empty($decision['blockers']))
                            <h6 class="text-danger mb-2">❌ BLOCKERS (Must Fix)</h6>
                            <ul class="list-unstyled mb-3">
                                @foreach($decision['blockers'] as $blocker)
                                    <li class="text-danger">• {{ $blocker }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Warnings --}}
                        @if(!empty($decision['warnings']))
                            <h6 class="text-warning mb-2">⚠️ WARNINGS</h6>
                            <ul class="list-unstyled mb-3">
                                @foreach($decision['warnings'] as $warning)
                                    <li class="text-warning">• {{ $warning }}</li>
                                @endforeach
                            </ul>
                        @endif

                        {{-- Achievements --}}
                        @if(!empty($decision['achievements']))
                            <h6 class="text-success mb-2">✅ ACHIEVEMENTS</h6>
                            <ul class="list-unstyled mb-0">
                                @foreach($decision['achievements'] as $achievement)
                                    <li class="text-success">• {{ $achievement }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if(empty($decision['blockers']) && empty($decision['warnings']) && empty($decision['achievements']))
                            <p class="text-muted text-center">No detailed analysis available</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Business Metrics --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">💰 Business Metrics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center mb-3">
                                <h4 class="text-success mb-0">
                                    Rp {{ number_format($data['business']['total_revenue'] ?? 0, 0, ',', '.') }}
                                </h4>
                                <small class="text-muted">Total Revenue</small>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <h4 class="text-danger mb-0">
                                    Rp {{ number_format($data['business']['total_cost'] ?? 0, 0, ',', '.') }}
                                </h4>
                                <small class="text-muted">Est. Meta Cost</small>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <h4 class="{{ ($data['business']['gross_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }} mb-0">
                                    Rp {{ number_format($data['business']['gross_profit'] ?? 0, 0, ',', '.') }}
                                </h4>
                                <small class="text-muted">Gross Profit</small>
                            </div>
                            <div class="col-md-3 text-center mb-3">
                                <h4 class="{{ ($data['business']['avg_margin_percent'] ?? 0) >= 25 ? 'text-success' : 'text-warning' }} mb-0">
                                    {{ $data['business']['avg_margin_percent'] ?? 0 }}%
                                </h4>
                                <small class="text-muted">Avg. Margin</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Activity & Health Metrics --}}
        <div class="row mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">📱 Activity Metrics</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td>Total Messages</td>
                                <td class="text-end fw-bold">{{ number_format($data['activity']['total_messages'] ?? 0) }}</td>
                            </tr>
                            <tr>
                                <td>Delivered</td>
                                <td class="text-end text-success">{{ number_format($data['activity']['messages_delivered'] ?? 0) }}</td>
                            </tr>
                            <tr>
                                <td>Failed</td>
                                <td class="text-end text-danger">{{ number_format($data['activity']['messages_failed'] ?? 0) }}</td>
                            </tr>
                            <tr>
                                <td>Delivery Rate</td>
                                <td class="text-end">
                                    <span class="badge bg-{{ ($data['activity']['delivery_rate'] ?? 0) >= 95 ? 'success' : 'warning' }}">
                                        {{ $data['activity']['delivery_rate'] ?? 0 }}%
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Active Clients</td>
                                <td class="text-end fw-bold">{{ $data['activity']['active_clients'] ?? 0 }}</td>
                            </tr>
                            <tr>
                                <td>New Clients</td>
                                <td class="text-end text-primary">{{ $data['activity']['new_clients'] ?? 0 }}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">🏥 Health Metrics</h6>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h3 class="{{ ($data['health']['avg_health_score'] ?? 0) >= 70 ? 'text-success' : 'text-warning' }}">
                                {{ $data['health']['avg_health_score'] ?? 0 }}/100
                            </h3>
                            <small class="text-muted">Average Health Score</small>
                        </div>
                        <div class="row text-center">
                            <div class="col-3">
                                <span class="badge bg-success d-block mb-1">A</span>
                                <small>{{ $data['health']['grade_a'] ?? 0 }}</small>
                            </div>
                            <div class="col-3">
                                <span class="badge bg-info d-block mb-1">B</span>
                                <small>{{ $data['health']['grade_b'] ?? 0 }}</small>
                            </div>
                            <div class="col-3">
                                <span class="badge bg-warning d-block mb-1">C</span>
                                <small>{{ $data['health']['grade_c'] ?? 0 }}</small>
                            </div>
                            <div class="col-3">
                                <span class="badge bg-danger d-block mb-1">D</span>
                                <small>{{ $data['health']['grade_d'] ?? 0 }}</small>
                            </div>
                        </div>
                        <hr>
                        <small class="text-muted d-block">Warmup States:</small>
                        <div class="d-flex justify-content-between mt-2 text-sm">
                            <span>STABLE: {{ $data['health']['warmup_stable'] ?? 0 }}</span>
                            <span>WARMING: {{ $data['health']['warmup_warming'] ?? 0 }}</span>
                            <span>COOLDOWN: {{ $data['health']['warmup_cooldown'] ?? 0 }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Week Recommendations --}}
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">📋 Week-{{ $week + 1 }} Recommendations</h6>
                    </div>
                    <div class="card-body">
                        @if(($decision['recommendation'] ?? '') === 'SCALE')
                            <div class="alert alert-success">
                                <strong>✅ SCALE APPROVED</strong> - System healthy for expansion
                            </div>
                            <ul class="mb-0">
                                <li>✓ Start marketing expansion</li>
                                <li>✓ Consider infrastructure scaling</li>
                                <li>✓ Enable more client onboarding</li>
                                <li>✓ Monitor margin closely</li>
                            </ul>
                        @elseif(($decision['recommendation'] ?? '') === 'HOLD')
                            <div class="alert alert-danger">
                                <strong>⚠️ HOLD REQUIRED</strong> - Fix issues before scaling
                            </div>
                            <ul class="mb-0">
                                <li>✓ Fix all blockers first</li>
                                <li>✓ Do NOT expand marketing</li>
                                <li>✓ Focus on stability</li>
                                <li>✓ Re-evaluate at end of Week-{{ $week + 1 }}</li>
                            </ul>
                        @else
                            <div class="alert alert-warning">
                                <strong>⏳ REVIEW NEEDED</strong> - Careful evaluation required
                            </div>
                            <ul class="mb-0">
                                <li>✓ Address warnings before scaling</li>
                                <li>✓ Cautious expansion only</li>
                                <li>✓ Daily monitoring required</li>
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No data available for Week-{{ $week }}. The week might not have started yet.
                </div>
            </div>
        </div>
    @endif

    {{-- Historical Summaries --}}
    @if(count($historicalSummaries) > 0)
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">📜 Historical Weekly Summaries</h6>
                    </div>
                    <div class="card-body px-0 pt-0 pb-2">
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-4">Week</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Period</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Revenue</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Profit</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Score</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Recommendation</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Decision</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($historicalSummaries as $hist)
                                        <tr>
                                            <td class="ps-4">
                                                <span class="badge bg-gradient-primary">Week-{{ $hist->week_number }}</span>
                                            </td>
                                            <td>
                                                <span class="text-xs">
                                                    {{ \Carbon\Carbon::parse($hist->week_start_date)->format('d/m') }} - 
                                                    {{ \Carbon\Carbon::parse($hist->week_end_date)->format('d/m') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-xs">Rp {{ number_format($hist->total_revenue, 0, ',', '.') }}</span>
                                            </td>
                                            <td>
                                                <span class="text-xs {{ $hist->gross_profit >= 0 ? 'text-success' : 'text-danger' }}">
                                                    Rp {{ number_format($hist->gross_profit, 0, ',', '.') }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $hist->decision_score >= 75 ? 'success' : ($hist->decision_score >= 60 ? 'warning' : 'danger') }}">
                                                    {{ $hist->decision_score }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $hist->recommendation === 'SCALE' ? 'success' : ($hist->recommendation === 'HOLD' ? 'danger' : 'warning') }}">
                                                    {{ $hist->recommendation }}
                                                </span>
                                            </td>
                                            <td>
                                                @if($hist->owner_decision === 'PENDING')
                                                    <a href="{{ route('owner.ops.decision', ['week' => $hist->week_number]) }}" 
                                                       class="btn btn-sm btn-primary">
                                                        Decide
                                                    </a>
                                                @else
                                                    <span class="badge bg-{{ $hist->owner_decision === 'SCALE' ? 'success' : 'danger' }}">
                                                        {{ $hist->owner_decision }}
                                                    </span>
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

    {{-- Back to Ops Dashboard --}}
    <div class="row mt-4">
        <div class="col-12">
            <a href="{{ route('owner.ops.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i> Back to Daily Ops
            </a>
        </div>
    </div>
</div>
@endsection
