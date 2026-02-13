@extends('owner.layouts.app')

@section('page-title', 'User Management')

@section('content')
<style>
.user-stats-card {
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    transition: transform 0.2s;
}
.user-stats-card:hover {
    transform: translateY(-2px);
}
.user-stats-card .stat-value {
    font-size: 2rem;
    font-weight: 700;
}
.user-stats-card .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    opacity: 0.8;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 0.7rem;
    font-weight: 600;
}
.status-active { background: #d1fae5; color: #059669; }
.status-banned { background: #fee2e2; color: #dc2626; }
.status-suspended { background: #fef3c7; color: #d97706; }
</style>

{{-- Page Header --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="fas fa-users me-2"></i>User Management</h4>
        <p class="text-muted mb-0">Kelola pengguna platform {{ $__brandName ?? 'Talkabiz' }}</p>
    </div>
</div>

{{-- Stats Cards --}}
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card user-stats-card bg-gradient-primary text-white">
            <div class="stat-value">{{ number_format($stats['total']) }}</div>
            <div class="stat-label">Total Users</div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card user-stats-card bg-gradient-success text-white">
            <div class="stat-value">{{ number_format($stats['active']) }}</div>
            <div class="stat-label">Active</div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card user-stats-card bg-gradient-warning text-white">
            <div class="stat-value">{{ number_format($stats['suspended']) }}</div>
            <div class="stat-label">Suspended</div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card user-stats-card bg-gradient-danger text-white">
            <div class="stat-value">{{ number_format($stats['banned']) }}</div>
            <div class="stat-label">Banned</div>
        </div>
    </div>
</div>

{{-- Filter & Search --}}
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control" 
                       placeholder="Cari nama atau email..." 
                       value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="">Semua Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                    <option value="banned" {{ request('status') === 'banned' ? 'selected' : '' }}>Banned</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Users Table --}}
<div class="card">
    <div class="card-header pb-0">
        <h6>Daftar User</h6>
    </div>
    <div class="card-body px-0 pt-0 pb-2">
        <div class="table-responsive">
            <table class="table align-items-center mb-0">
                <thead>
                    <tr>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">User</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Role</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                        <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Klien</th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Campaign</th>
                        <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Last Login</th>
                        <th class="text-secondary opacity-7"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td>
                                <div class="d-flex px-2 py-1">
                                    <div class="d-flex flex-column justify-content-center">
                                        <h6 class="mb-0 text-sm">{{ $user->name }}</h6>
                                        <p class="text-xs text-secondary mb-0">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-gradient-{{ $user->role === 'owner' ? 'dark' : ($user->role === 'admin' ? 'info' : 'secondary') }}">
                                    {{ $user->role ?? 'user' }}
                                </span>
                            </td>
                            <td>
                                @php
                                    // Status diambil dari klien.status, bukan users.status
                                    $klienStatus = $user->klien?->status ?? 'aktif';
                                    $statusClass = match($klienStatus) {
                                        'banned' => 'status-banned',
                                        'suspended' => 'status-suspended',
                                        default => 'status-active',
                                    };
                                    $statusLabel = match($klienStatus) {
                                        'banned' => 'Banned',
                                        'suspended' => 'Suspended',
                                        'aktif' => 'Active',
                                        default => ucfirst($klienStatus),
                                    };
                                @endphp
                                <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                            </td>
                            <td>
                                <span class="text-sm">{{ $user->klien?->nama_perusahaan ?? '-' }}</span>
                            </td>
                            <td class="text-center">
                                @if($user->campaign_send_enabled)
                                    <span class="badge bg-success">Enabled</span>
                                @else
                                    <span class="badge bg-secondary">Disabled</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="text-xs">{{ $user->last_login_at?->diffForHumans() ?? '-' }}</span>
                            </td>
                            <td class="align-middle">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                            type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="{{ route('owner.users.show', $user) }}">
                                                <i class="fas fa-eye me-2"></i> Detail
                                            </a>
                                        </li>
                                        @if($user->role !== 'owner' && $user->role !== 'super_admin')
                                            @if($user->status !== 'banned')
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item text-warning" href="#" 
                                                       onclick="suspendUser({{ $user->id }}, '{{ $user->email }}')">
                                                        <i class="fas fa-pause-circle me-2"></i> Suspend
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item text-danger" href="#" 
                                                       onclick="banUser({{ $user->id }}, '{{ $user->email }}')">
                                                        <i class="fas fa-ban me-2"></i> Ban User
                                                    </a>
                                                </li>
                                            @else
                                                <li>
                                                    <a class="dropdown-item text-success" href="#" 
                                                       onclick="unbanUser({{ $user->id }}, '{{ $user->email }}')">
                                                        <i class="fas fa-check-circle me-2"></i> Unban User
                                                    </a>
                                                </li>
                                            @endif
                                        @endif
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <span class="text-muted">Tidak ada user ditemukan</span>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        {{-- Pagination --}}
        <div class="px-3 pt-3">
            {{ $users->links() }}
        </div>
    </div>
</div>

{{-- Ban User Modal --}}
<div class="modal fade" id="banUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Ban User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Peringatan!</strong> Tindakan ini akan:
                    <ul class="mb-0 mt-2">
                        <li>Menonaktifkan akun user</li>
                        <li>Memutus semua koneksi WhatsApp</li>
                        <li>Menghentikan semua campaign aktif</li>
                    </ul>
                </div>
                <p>Anda yakin ingin ban user <strong id="banUserEmail"></strong>?</p>
                <div class="mb-3">
                    <label class="form-label">Alasan <span class="text-danger">*</span></label>
                    <textarea id="banUserReason" class="form-control" rows="3" 
                              placeholder="Masukkan alasan ban..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-danger" id="confirmBanUser">
                    <i class="fas fa-ban me-1"></i> Ban User
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Suspend User Modal --}}
<div class="modal fade" id="suspendUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-pause-circle me-2"></i>Suspend User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Anda yakin ingin suspend user <strong id="suspendUserEmail"></strong>?</p>
                <div class="mb-3">
                    <label class="form-label">Alasan <span class="text-danger">*</span></label>
                    <textarea id="suspendUserReason" class="form-control" rows="3" 
                              placeholder="Masukkan alasan suspend..." required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-warning" id="confirmSuspendUser">
                    <i class="fas fa-pause-circle me-1"></i> Suspend
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentUserId = null;

function banUser(userId, email) {
    currentUserId = userId;
    document.getElementById('banUserEmail').textContent = email;
    document.getElementById('banUserReason').value = '';
    new bootstrap.Modal(document.getElementById('banUserModal')).show();
}

function suspendUser(userId, email) {
    currentUserId = userId;
    document.getElementById('suspendUserEmail').textContent = email;
    document.getElementById('suspendUserReason').value = '';
    new bootstrap.Modal(document.getElementById('suspendUserModal')).show();
}

function unbanUser(userId, email) {
    Swal.fire({
        title: 'Unban User?',
        text: `Anda yakin ingin unban ${email}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'Ya, Unban!',
        cancelButtonText: 'Batal',
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`/owner/users/${userId}/unban`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Berhasil!', data.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Gagal!', data.message, 'error');
                }
            });
        }
    });
}

document.getElementById('confirmBanUser').addEventListener('click', function() {
    const reason = document.getElementById('banUserReason').value;
    if (!reason.trim()) {
        OwnerPopup.error('Alasan wajib diisi!', 'Validasi');
        return;
    }
    
    OwnerPopup.loading('Memproses...');
    
    fetch(`/owner/users/${currentUserId}/ban`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ reason: reason }),
    })
    .then(res => res.json())
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('banUserModal')).hide();
        if (data.success) {
            OwnerPopup.success(data.message).then(() => location.reload());
        } else {
            OwnerPopup.error(data.message);
        }
    });
});

document.getElementById('confirmSuspendUser').addEventListener('click', function() {
    const reason = document.getElementById('suspendUserReason').value;
    if (!reason.trim()) {
        OwnerPopup.error('Alasan wajib diisi!', 'Validasi');
        return;
    }
    
    OwnerPopup.loading('Memproses...');
    
    fetch(`/owner/users/${currentUserId}/suspend`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ reason: reason }),
    })
    .then(res => res.json())
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('suspendUserModal')).hide();
        if (data.success) {
            OwnerPopup.success(data.message).then(() => location.reload());
        } else {
            OwnerPopup.error(data.message);
        }
    });
});
</script>
@endpush
