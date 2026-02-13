@extends('layouts.user_type.auth')

@section('content')

<div class="container-fluid py-4">
    {{-- Page Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <h3 class="mb-0">
                <i class="fas fa-shield-alt text-primary me-2"></i>
                Risk Approval Panel
            </h3>
            <p class="text-sm text-secondary mb-0">Kelola persetujuan akun high-risk business</p>
        </div>
    </div>

    {{-- Statistics Cards --}}
    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Pending</p>
                            <h4 class="font-weight-bolder mb-0 text-warning">{{ $stats['pending'] }}</h4>
                            <p class="text-xs text-secondary mb-0">Menunggu Review</p>
                        </div>
                        <div class="icon icon-shape bg-gradient-warning shadow text-center border-radius-md">
                            <i class="fas fa-clock text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Approved</p>
                            <h4 class="font-weight-bolder mb-0 text-success">{{ $stats['approved'] }}</h4>
                            <p class="text-xs text-secondary mb-0">Telah Disetujui</p>
                        </div>
                        <div class="icon icon-shape bg-gradient-success shadow text-center border-radius-md">
                            <i class="fas fa-check text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Rejected</p>
                            <h4 class="font-weight-bolder mb-0 text-danger">{{ $stats['rejected'] }}</h4>
                            <p class="text-xs text-secondary mb-0">Ditolak</p>
                        </div>
                        <div class="icon icon-shape bg-gradient-danger shadow text-center border-radius-md">
                            <i class="fas fa-times text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6 mb-3">
            <div class="card">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div>
                            <p class="text-sm mb-0 text-capitalize font-weight-bold">Suspended</p>
                            <h4 class="font-weight-bolder mb-0 text-dark">{{ $stats['suspended'] }}</h4>
                            <p class="text-xs text-secondary mb-0">Disuspend</p>
                        </div>
                        <div class="icon icon-shape bg-gradient-dark shadow text-center border-radius-md">
                            <i class="fas fa-ban text-lg opacity-10" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Content Card --}}
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    {{-- Filter Tabs --}}
                    <ul class="nav nav-tabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link {{ $status === 'pending' ? 'active' : '' }}" 
                               href="{{ route('risk-approval.index', ['status' => 'pending']) }}">
                                <i class="fas fa-clock me-1"></i> Pending ({{ $stats['pending'] }})
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $status === 'approved' ? 'active' : '' }}" 
                               href="{{ route('risk-approval.index', ['status' => 'approved']) }}">
                                <i class="fas fa-check me-1"></i> Approved ({{ $stats['approved'] }})
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $status === 'rejected' ? 'active' : '' }}" 
                               href="{{ route('risk-approval.index', ['status' => 'rejected']) }}">
                                <i class="fas fa-times me-1"></i> Rejected ({{ $stats['rejected'] }})
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $status === 'suspended' ? 'active' : '' }}" 
                               href="{{ route('risk-approval.index', ['status' => 'suspended']) }}">
                                <i class="fas fa-ban me-1"></i> Suspended ({{ $stats['suspended'] }})
                            </a>
                        </li>
                    </ul>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    @if($klienList->isEmpty())
                        <div class="text-center py-5">
                            <i class="fas fa-inbox text-secondary" style="font-size: 3rem;"></i>
                            <p class="text-secondary mt-3">Tidak ada data untuk status: <strong>{{ ucfirst($status) }}</strong></p>
                        </div>
                    @else
                        <div class="table-responsive p-0">
                            <table class="table align-items-center mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Business Profile</th>
                                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Business Type</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Risk Level</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Tgl Daftar</th>
                                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($klienList as $klien)
                                    <tr>
                                        <td>
                                            <div class="d-flex px-3 py-1">
                                                <div class="d-flex flex-column justify-content-center">
                                                    <h6 class="mb-0 text-sm">{{ $klien->nama_perusahaan }}</h6>
                                                    <p class="text-xs text-secondary mb-0">
                                                        <i class="fas fa-user me-1"></i>{{ $klien->user->name ?? '-' }}
                                                    </p>
                                                    <p class="text-xs text-secondary mb-0">
                                                        <i class="fas fa-envelope me-1"></i>{{ $klien->user->email ?? '-' }}
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <p class="text-xs font-weight-bold mb-0">{{ $klien->businessType->nama ?? '-' }}</p>
                                            <p class="text-xs text-secondary mb-0">{{ $klien->businessType->kode ?? '-' }}</p>
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            @php
                                                $riskLevel = $klien->businessType->risk_level ?? 'UNKNOWN';
                                                $riskColors = [
                                                    'LOW' => 'success',
                                                    'MEDIUM' => 'warning',
                                                    'HIGH' => 'danger'
                                                ];
                                                $riskColor = $riskColors[$riskLevel] ?? 'secondary';
                                            @endphp
                                            <span class="badge badge-sm bg-gradient-{{ $riskColor }}">
                                                {{ $riskLevel }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            <span class="badge badge-sm bg-gradient-{{ $klien->getApprovalBadgeColor() }}">
                                                {{ $klien->getApprovalStatusLabel() }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-secondary text-xs">
                                                {{ $klien->created_at->format('d M Y') }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center">
                                            <button type="button" 
                                                    class="btn btn-sm btn-info mb-0 me-1 btn-view-detail"
                                                    data-klien-id="{{ $klien->id }}"
                                                    title="Lihat Detail">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            
                                            @if($klien->isPendingApproval())
                                                <button type="button" 
                                                        class="btn btn-sm btn-success mb-0 me-1 btn-approve"
                                                        data-klien-id="{{ $klien->id }}"
                                                        data-klien-name="{{ $klien->nama_perusahaan }}"
                                                        title="Approve">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger mb-0 btn-reject"
                                                        data-klien-id="{{ $klien->id }}"
                                                        data-klien-name="{{ $klien->nama_perusahaan }}"
                                                        title="Reject">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            @elseif($klien->isApproved())
                                                <button type="button" 
                                                        class="btn btn-sm btn-dark mb-0 btn-suspend"
                                                        data-klien-id="{{ $klien->id }}"
                                                        data-klien-name="{{ $klien->nama_perusahaan }}"
                                                        title="Suspend">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            @elseif($klien->isSuspended())
                                                <button type="button" 
                                                        class="btn btn-sm btn-success mb-0 btn-reactivate"
                                                        data-klien-id="{{ $klien->id }}"
                                                        data-klien-name="{{ $klien->nama_perusahaan }}"
                                                        title="Reactivate">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Pagination --}}
                        <div class="px-3 py-3">
                            {{ $klienList->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Actions --}}
    @if($recentActions->isNotEmpty())
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header pb-0">
                    <h6>Recent Approval Actions</h6>
                </div>
                <div class="card-body p-3">
                    <div class="timeline timeline-one-side">
                        @foreach($recentActions as $action)
                        <div class="timeline-block mb-3">
                            <span class="timeline-step">
                                <i class="fas fa-{{ $action->action === 'approve' ? 'check text-success' : ($action->action === 'reject' ? 'times text-danger' : 'ban text-dark') }}"></i>
                            </span>
                            <div class="timeline-content">
                                <h6 class="text-dark text-sm font-weight-bold mb-0">
                                    {{ ucfirst($action->action) }}: {{ $action->nama_perusahaan }}
                                </h6>
                                <p class="text-secondary font-weight-normal text-xs mt-1 mb-0">
                                    By: {{ $action->actor_name }} • {{ \Carbon\Carbon::parse($action->created_at)->diffForHumans() }}
                                </p>
                                @if($action->reason)
                                    <p class="text-xs text-secondary mb-0 mt-1">
                                        <i class="fas fa-comment me-1"></i>{{ $action->reason }}
                                    </p>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

{{-- Modal: Detail Business Profile --}}
<div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Detail Business Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="detailContent">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Approval Action (Approve/Reject/Suspend/Reactivate) --}}
<div class="modal fade" id="actionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="actionModalTitle">Konfirmasi Aksi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="actionKlienId">
                <input type="hidden" id="actionType">
                
                <div class="alert alert-warning" id="actionWarning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span id="actionWarningText"></span>
                </div>

                <div class="mb-3">
                    <label class="form-label">
                        Catatan <span class="text-danger" id="notesRequired">*</span>
                    </label>
                    <textarea class="form-control" id="actionNotes" rows="4" 
                              placeholder="Masukkan alasan / catatan untuk aksi ini"></textarea>
                    <div class="invalid-feedback" id="notesError"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" id="btnConfirmAction">Konfirmasi</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(document).ready(function() {
    const csrfToken = $('meta[name="csrf-token"]').attr('content');

    // View Detail
    $('.btn-view-detail').on('click', function() {
        const klienId = $(this).data('klien-id');
        
        $('#detailContent').html(`
            <div class="text-center py-3">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `);
        
        $('#detailModal').modal('show');
        
        $.ajax({
            url: `/owner/risk-approval/${klienId}`,
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    let historyHtml = '';
                    
                    if (data.approval_history && data.approval_history.length > 0) {
                        historyHtml = '<div class="mt-3"><h6>Approval History</h6><div class="timeline timeline-one-side">';
                        data.approval_history.forEach(function(log) {
                            historyHtml += `
                                <div class="timeline-block mb-2">
                                    <span class="timeline-step">
                                        <i class="fas fa-circle text-primary"></i>
                                    </span>
                                    <div class="timeline-content">
                                        <h6 class="text-dark text-sm font-weight-bold mb-0">
                                            ${log.action}: ${log.status_from} → ${log.status_to}
                                        </h6>
                                        <p class="text-secondary text-xs mb-0">
                                            ${log.actor} • ${log.created_at}
                                        </p>
                                        ${log.reason ? `<p class="text-xs mb-0 mt-1">${log.reason}</p>` : ''}
                                    </div>
                                </div>
                            `;
                        });
                        historyHtml += '</div></div>';
                    }
                    
                    $('#detailContent').html(`
                        <div class="row">
                            <div class="col-md-6">
                                <p class="text-sm mb-2"><strong>Nama Perusahaan:</strong><br>${data.nama_perusahaan}</p>
                                <p class="text-sm mb-2"><strong>Email:</strong><br>${data.email}</p>
                                <p class="text-sm mb-2"><strong>Phone:</strong><br>${data.phone}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-sm mb-2"><strong>Business Type:</strong><br>${data.business_type}</p>
                                <p class="text-sm mb-2"><strong>Risk Level:</strong><br>
                                    <span class="badge badge-sm bg-gradient-${data.risk_level === 'HIGH' ? 'danger' : (data.risk_level === 'MEDIUM' ? 'warning' : 'success')}">${data.risk_level}</span>
                                </p>
                                <p class="text-sm mb-2"><strong>Approval Status:</strong><br>
                                    <span class="badge badge-sm bg-gradient-${data.approval_badge_color}">${data.approval_status_label}</span>
                                </p>
                            </div>
                        </div>
                        <hr>
                        <div class="row">
                            <div class="col-12">
                                <p class="text-sm mb-2"><strong>Tgl Daftar:</strong> ${data.created_at}</p>
                                ${data.approved_at ? `<p class="text-sm mb-2"><strong>Approved At:</strong> ${data.approved_at}</p>` : ''}
                                ${data.approval_notes ? `<p class="text-sm mb-2"><strong>Notes:</strong><br>${data.approval_notes}</p>` : ''}
                            </div>
                        </div>
                        ${historyHtml}
                    `);
                }
            },
            error: function() {
                $('#detailContent').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Gagal memuat detail klien
                    </div>
                `);
            }
        });
    });

    // Approve
    $('.btn-approve').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Approve Klien');
        $('#actionType').val('approve');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Anda akan menyetujui klien: ${klienName}`);
        $('#actionWarning').removeClass('alert-danger').addClass('alert-warning');
        $('#notesRequired').hide();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Reject
    $('.btn-reject').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Reject Klien');
        $('#actionType').val('reject');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Anda akan menolak klien: ${klienName}. Aksi ini akan menonaktifkan akun klien.`);
        $('#actionWarning').removeClass('alert-warning').addClass('alert-danger');
        $('#notesRequired').show();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Suspend
    $('.btn-suspend').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Suspend Klien');
        $('#actionType').val('suspend');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Anda akan suspend klien: ${klienName}. Klien tidak dapat mengirim pesan sampai diaktifkan kembali.`);
        $('#actionWarning').removeClass('alert-warning').addClass('alert-danger');
        $('#notesRequired').show();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Reactivate
    $('.btn-reactivate').on('click', function() {
        const klienId = $(this).data('klien-id');
        const klienName = $(this).data('klien-name');
        
        $('#actionModalTitle').text('Reactivate Klien');
        $('#actionType').val('reactivate');
        $('#actionKlienId').val(klienId);
        $('#actionWarningText').text(`Anda akan mengaktifkan kembali klien: ${klienName}`);
        $('#actionWarning').removeClass('alert-danger').addClass('alert-warning');
        $('#notesRequired').hide();
        $('#actionNotes').val('').removeClass('is-invalid');
        $('#actionModal').modal('show');
    });

    // Confirm Action
    $('#btnConfirmAction').on('click', function() {
        const actionType = $('#actionType').val();
        const klienId = $('#actionKlienId').val();
        const notes = $('#actionNotes').val().trim();
        
        // Validate required notes for reject/suspend
        if ((actionType === 'reject' || actionType === 'suspend') && !notes) {
            $('#actionNotes').addClass('is-invalid');
            $('#notesError').text('Catatan wajib diisi untuk aksi ini');
            return;
        }
        
        $('#actionNotes').removeClass('is-invalid');
        $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Processing...');
        
        $.ajax({
            url: `/owner/risk-approval/${klienId}/${actionType}`,
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            },
            data: {
                notes: notes
            },
            success: function(response) {
                if (response.success) {
                    $('#actionModal').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: response.message,
                        confirmButtonText: 'OK'
                    }).then(() => {
                        window.location.reload();
                    });
                }
            },
            error: function(xhr) {
                let errorMsg = 'Terjadi kesalahan';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMsg = xhr.responseJSON.message;
                }
                
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: errorMsg,
                    confirmButtonText: 'OK'
                });
            },
            complete: function() {
                $('#btnConfirmAction').prop('disabled', false).text('Konfirmasi');
            }
        });
    });
});
</script>
@endpush

@endsection
