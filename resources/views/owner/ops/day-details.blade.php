@extends('layouts.owner')

@section('title', 'H+' . $day . ' - ' . $theme['name'])

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-gradient-{{ $theme['color'] }} shadow-lg">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-lg-8">
                            <h2 class="text-white mb-1">
                                {{ $theme['icon'] }} H+{{ $day }} — {{ $theme['name'] }}
                            </h2>
                            <p class="text-white opacity-8 mb-0">
                                {{ $theme['focus'] }}
                            </p>
                            <small class="text-white opacity-6">
                                Date: {{ $dayDate->format('d M Y') }}
                            </small>
                        </div>
                        <div class="col-lg-4 text-end">
                            {{-- Navigation --}}
                            @if($day > 1)
                                <a href="{{ route('owner.ops.day-details', $day - 1) }}" 
                                   class="btn btn-outline-white btn-sm me-2">
                                    <i class="fas fa-arrow-left"></i> H+{{ $day - 1 }}
                                </a>
                            @endif
                            @if($day < 7)
                                <a href="{{ route('owner.ops.day-details', $day + 1) }}" 
                                   class="btn btn-white btn-sm">
                                    H+{{ $day + 1 }} <i class="fas fa-arrow-right"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Day Checklist --}}
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0">📋 Day Checklist</h6>
                </div>
                <div class="card-body">
                    @if(count($checklist) > 0)
                        <div class="list-group">
                            @foreach($checklist as $index => $check)
                                <div class="list-group-item border-0 px-0">
                                    <div class="d-flex align-items-start">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" 
                                                   id="check{{ $index }}" 
                                                   onchange="saveCheckStatus({{ $index }}, this.checked)">
                                        </div>
                                        <div class="ms-3">
                                            <label class="form-check-label fw-bold mb-0" for="check{{ $index }}">
                                                {{ $check['item'] }}
                                            </label>
                                            <p class="text-muted text-sm mb-0">
                                                <i class="fas fa-arrow-right me-1"></i> {{ $check['action'] }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted mb-0">No checklist items for this day</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            {{-- Last Check Result --}}
            <div class="card h-100">
                <div class="card-header pb-0">
                    <h6 class="mb-0">📊 Last Check Result</h6>
                </div>
                <div class="card-body">
                    @if($dayCheck)
                        <div class="text-center mb-3">
                            <span class="badge bg-{{ $dayCheck->status === 'completed' ? 'success' : 'warning' }} badge-lg">
                                {{ strtoupper($dayCheck->status) }}
                            </span>
                        </div>
                        <p class="text-sm text-muted mb-2">
                            <strong>Date:</strong> {{ \Carbon\Carbon::parse($dayCheck->check_date)->format('d M Y H:i') }}
                        </p>
                        @if($dayCheck->alerts_count > 0)
                            <div class="alert alert-danger py-2">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                {{ $dayCheck->alerts_count }} alert(s) found
                            </div>
                        @else
                            <div class="alert alert-success py-2">
                                <i class="fas fa-check-circle me-2"></i>
                                No critical alerts
                            </div>
                        @endif

                        {{-- Show alerts if any --}}
                        @if(count($dayCheck->alerts ?? []) > 0)
                            <h6 class="mt-3 mb-2">Alerts:</h6>
                            <ul class="list-unstyled text-sm">
                                @foreach($dayCheck->alerts as $alert)
                                    <li class="text-danger mb-1">
                                        • {{ $alert['type'] ?? 'Unknown' }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @else
                        <div class="text-center py-4">
                            <i class="fas fa-clock fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0">No check recorded for this day</p>
                            <a href="{{ route('owner.ops.index') }}" class="btn btn-primary btn-sm mt-3">
                                Run Check
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Day-specific Metrics --}}
    @if(count($metrics) > 0)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">📈 Day Metrics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            @foreach($metrics as $name => $value)
                                <div class="col-md-4 col-lg-3 mb-3">
                                    <div class="card bg-light">
                                        <div class="card-body py-3 text-center">
                                            <h4 class="mb-0">
                                                @if(is_numeric($value) && $value > 1000)
                                                    {{ number_format($value) }}
                                                @elseif(str_contains(strtolower($name), 'rate'))
                                                    {{ $value }}%
                                                @else
                                                    {{ $value }}
                                                @endif
                                            </h4>
                                            <small class="text-muted text-capitalize">
                                                {{ str_replace('_', ' ', $name) }}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Day-specific Guidelines --}}
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">📖 Day {{ $day }} Guidelines</h6>
                </div>
                <div class="card-body">
                    @switch($day)
                        @case(1)
                            <div class="alert alert-primary">
                                <strong>🔧 STABILITY DAY</strong>
                                <p class="mb-0 mt-2">
                                    Fokus hari ini adalah memastikan semua komponen sistem berjalan normal.
                                    <br><strong>RULE:</strong> Patch hanya BUG KRITIS. Tidak boleh ada fitur baru!
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Monitor error log setiap 2 jam</li>
                                <li>Pastikan queue worker dan scheduler aktif</li>
                                <li>Cek webhook events dan payment gateway</li>
                                <li>Catat semua error untuk analisis</li>
                            </ul>
                            @break

                        @case(2)
                            <div class="alert alert-success">
                                <strong>📱 DELIVERABILITY DAY</strong>
                                <p class="mb-0 mt-2">
                                    Fokus pada kesehatan nomor WhatsApp dan deliverability.
                                    <br><strong>RULE:</strong> JANGAN bypass warmup! Paksa COOLDOWN jika Grade C/D.
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Review Health Score setiap nomor</li>
                                <li>Cek warmup state dan limit progression</li>
                                <li>Monitor delivery rate dan failed messages</li>
                                <li>Edukasi client tentang warmup via banner/tooltip</li>
                            </ul>
                            @break

                        @case(3)
                            <div class="alert alert-warning">
                                <strong>💰 BILLING & PROFIT DAY</strong>
                                <p class="mb-0 mt-2">
                                    Fokus memastikan profit margin dan billing system berjalan benar.
                                    <br><strong>RULE:</strong> JANGAN ubah harga client secara manual!
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Cek Revenue vs Cost - HARUS PROFIT!</li>
                                <li>Pastikan margin sesuai target (minimal 25%)</li>
                                <li>Review anomali top-up (>10jt)</li>
                                <li>Cek tidak ada negative balance</li>
                            </ul>
                            @break

                        @case(4)
                            <div class="alert alert-info">
                                <strong>👤 UX & BEHAVIOR DAY</strong>
                                <p class="mb-0 mt-2">
                                    Fokus pada user experience dan behavior analysis.
                                    <br><strong>RULE:</strong> JANGAN buka limit secara manual!
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Analisis user funnel: Login → Campaign → Send</li>
                                <li>Cek common UI errors dan complaints</li>
                                <li>Tambah micro-copy jika user bingung</li>
                                <li>Perjelas banner edukasi warmup/limit</li>
                            </ul>
                            @break

                        @case(5)
                            <div class="alert alert-danger">
                                <strong>🔒 SECURITY & ABUSE DAY</strong>
                                <p class="mb-0 mt-2">
                                    Fokus pada keamanan dan deteksi abuse.
                                    <br><strong>RULE:</strong> Suspend langsung nomor/akun yang abuse!
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Deteksi spam activity dan high-volume senders</li>
                                <li>Cek burst message patterns</li>
                                <li>Review suspicious IPs dan activities</li>
                                <li>Catat semua incidents di audit log</li>
                            </ul>
                            @break

                        @case(6)
                            <div class="alert alert-secondary">
                                <strong>📊 OWNER REVIEW DAY</strong>
                                <p class="mb-0 mt-2">
                                    Hari untuk owner review dan persiapan keputusan.
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Review semua business metrics minggu ini</li>
                                <li>Analisis client risk distribution</li>
                                <li>Siapkan data untuk keputusan SCALE/HOLD</li>
                                <li>Plan roadmap Week-2</li>
                            </ul>
                            @break

                        @case(7)
                            <div class="alert alert-dark">
                                <strong>🎯 DECISION DAY</strong>
                                <p class="mb-0 mt-2">
                                    Hari pengambilan keputusan: SCALE atau HOLD?
                                </p>
                            </div>
                            <ul class="mb-0">
                                <li>Review comprehensive Week-1 summary</li>
                                <li>Identify all blockers dan warnings</li>
                                <li>Make final SCALE/HOLD decision</li>
                                <li>Communicate decision to all stakeholders</li>
                                <li>Document Week-1 learnings</li>
                            </ul>
                            @break
                    @endswitch
                </div>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <div class="row mt-4">
        <div class="col-12">
            <div class="d-flex justify-content-between">
                <a href="{{ route('owner.ops.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Daily Ops
                </a>
                @if($day === 7)
                    <a href="{{ route('owner.ops.decision') }}" class="btn btn-primary">
                        <i class="fas fa-gavel me-2"></i> Make Decision
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

@push('js')
<script>
// Simple local storage for checklist state
function saveCheckStatus(index, checked) {
    const key = 'ops_day{{ $day }}_check_' + index;
    localStorage.setItem(key, checked ? '1' : '0');
}

// Load saved states on page load
document.addEventListener('DOMContentLoaded', function() {
    for (let i = 0; i < {{ count($checklist) }}; i++) {
        const key = 'ops_day{{ $day }}_check_' + i;
        const saved = localStorage.getItem(key);
        if (saved === '1') {
            document.getElementById('check' + i).checked = true;
        }
    }
});
</script>
@endpush
@endsection
