@extends('owner.layouts.app')

@section('page-title', 'Entity History: ' . class_basename($entityType) . ' #' . $entityId)

@section('content')
{{-- Breadcrumb --}}
<div class="row mb-4">
    <div class="col-12">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                <li class="breadcrumb-item">
                    <a href="{{ route('owner.audit-trail.index') }}" class="text-primary">
                        <i class="fas fa-shield-alt me-1"></i> Audit Trail
                    </a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">
                    {{ class_basename($entityType) }} #{{ $entityId }}
                </li>
            </ol>
        </nav>
    </div>
</div>

{{-- Entity Info Banner --}}
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-body p-3">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-gradient-primary shadow text-center border-radius-md me-3 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                        <i class="fas fa-history text-white text-lg"></i>
                    </div>
                    <div>
                        <h6 class="mb-0">Riwayat Lengkap Entity</h6>
                        <p class="text-sm text-muted mb-0">
                            <strong>{{ class_basename($entityType) }}</strong> 
                            dengan ID <strong>#{{ $entityId }}</strong>
                            — {{ $logs->total() }} total perubahan tercatat
                        </p>
                    </div>
                    <div class="ms-auto">
                        <a href="{{ route('owner.audit-trail.index', ['entity_type' => $entityType, 'entity_id' => $entityId]) }}" 
                           class="btn btn-sm btn-outline-primary mb-0">
                            <i class="fas fa-filter me-1"></i> Lihat di Filter
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Immutable Notice --}}
<div class="row mb-3">
    <div class="col-12">
        <div class="alert alert-info text-white d-flex align-items-center mb-0" style="background: linear-gradient(310deg, #2152ff 0%, #21d4fd 100%); border: none;">
            <i class="fas fa-lock me-2"></i>
            <span class="text-sm">
                <strong>Append-Only Timeline</strong> — Setiap baris adalah record permanen. 
                Koreksi hanya melalui entry baru (reversal / adjustment).
            </span>
        </div>
    </div>
</div>

{{-- Timeline Table --}}
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header pb-0">
                <h6 class="mb-0">
                    <i class="fas fa-stream me-2"></i>Audit Timeline
                    <span class="badge bg-gradient-dark ms-2">{{ $logs->total() }} records</span>
                </h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive p-0">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7" style="width: 40px;">#</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Waktu</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Actor</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Kategori</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Perubahan</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $index => $log)
                                <tr>
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center">
                                            <div class="me-2" style="width: 8px; height: 8px; border-radius: 50%; background: 
                                                @if($log->action === 'create' || $log->action === 'created') #17ad37
                                                @elseif($log->action === 'delete' || $log->action === 'deleted') #ea0606
                                                @elseif($log->action === 'reversal') #fb6340
                                                @else #5e72e4
                                                @endif;"></div>
                                            <span class="text-xs text-muted">{{ $logs->firstItem() + $index }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="text-xs font-weight-bold">
                                                {{ $log->occurred_at ? $log->occurred_at->format('d M Y') : '-' }}
                                            </span>
                                        </div>
                                        <div>
                                            <span class="text-xs text-muted">
                                                {{ $log->occurred_at ? $log->occurred_at->format('H:i:s') : '' }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm 
                                            @if($log->actor_type === 'user') bg-gradient-primary
                                            @elseif($log->actor_type === 'admin') bg-gradient-warning
                                            @elseif($log->actor_type === 'system') bg-gradient-dark
                                            @else bg-gradient-secondary
                                            @endif">
                                            {{ $log->actor_type ?? '-' }}
                                        </span>
                                        <div class="mt-1">
                                            <span class="text-xs text-muted">{{ $log->actor_email ?? '' }}</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="text-xs font-weight-bold">{{ $log->action ?? '-' }}</span>
                                        @if($log->description)
                                            <div class="mt-1">
                                                <span class="text-xs text-muted">{{ Str::limit($log->description, 35) }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($log->action_category)
                                            <span class="badge badge-sm
                                                @if($log->action_category === 'billing') bg-gradient-success
                                                @elseif($log->action_category === 'auth') bg-gradient-info
                                                @else bg-gradient-secondary
                                                @endif">
                                                {{ $log->action_category }}
                                            </span>
                                        @else
                                            <span class="text-xs text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($log->status === 'success')
                                            <span class="badge badge-sm bg-gradient-success">success</span>
                                        @elseif($log->status === 'failed')
                                            <span class="badge badge-sm bg-gradient-danger">failed</span>
                                        @else
                                            <span class="badge badge-sm bg-gradient-secondary">{{ $log->status ?? '-' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $hasOld = $log->old_values && (is_array($log->old_values) ? count($log->old_values) > 0 : !empty(json_decode($log->old_values, true)));
                                            $hasNew = $log->new_values && (is_array($log->new_values) ? count($log->new_values) > 0 : !empty(json_decode($log->new_values, true)));
                                        @endphp
                                        @if($hasOld && $hasNew)
                                            <span class="badge badge-sm bg-gradient-info">Update</span>
                                        @elseif($hasNew)
                                            <span class="badge badge-sm bg-gradient-success">Create</span>
                                        @elseif($hasOld)
                                            <span class="badge badge-sm bg-gradient-danger">Delete</span>
                                        @else
                                            <span class="text-xs text-muted">-</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('owner.audit-trail.show', $log->id) }}" 
                                           class="btn btn-sm btn-outline-primary mb-0 px-2" title="Detail">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="fas fa-history text-muted mb-3" style="font-size: 3rem;"></i>
                                        <p class="text-muted mb-0">Tidak ada riwayat untuk entity ini</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if($logs->hasPages())
                    <div class="d-flex justify-content-center mt-4 px-4 pb-3">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Back --}}
<div class="row mt-3">
    <div class="col-12">
        <a href="{{ route('owner.audit-trail.index') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Kembali ke Audit Trail
        </a>
    </div>
</div>
@endsection
