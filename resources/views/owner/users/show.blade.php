@extends('owner.layouts.app')

@section('page-title', 'Detail User - ' . $user->name)

@section('content')
{{-- Breadcrumb --}}
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
        <li class="breadcrumb-item"><a href="{{ route('owner.users.index') }}">User Management</a></li>
        <li class="breadcrumb-item active">{{ $user->name }}</li>
    </ol>
</nav>

<div class="row">
    {{-- User Info Card --}}
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header pb-0 text-center">
                <div class="avatar avatar-xl bg-gradient-primary rounded-circle mx-auto mb-3">
                    <span class="text-white text-lg">{{ strtoupper(substr($user->name, 0, 2)) }}</span>
                </div>
                <h5 class="mb-1">{{ $user->name }}</h5>
                <p class="text-sm text-muted mb-2">{{ $user->email }}</p>
                
                @php
                    $statusClass = match($user->status) {
                        'banned' => 'bg-danger',
                        'suspended' => 'bg-warning',
                        default => 'bg-success',
                    };
                    $statusLabel = match($user->status) {
                        'banned' => 'BANNED',
                        'suspended' => 'SUSPENDED',
                        default => 'ACTIVE',
                    };
                @endphp
                <span class="badge {{ $statusClass }} mb-3">{{ $statusLabel }}</span>
            </div>
            <div class="card-body pt-0">
                <hr class="horizontal dark">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Role</span>
                        <span class="badge bg-gradient-dark">{{ $user->role ?? 'user' }}</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Klien</span>
                        <span class="text-sm font-weight-bold">{{ $user->klien?->nama_perusahaan ?? '-' }}</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Plan</span>
                        <span class="text-sm font-weight-bold">{{ $user->currentPlan?->nama ?? '-' }}</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Campaign</span>
                        @if($user->campaign_send_enabled)
                            <span class="badge bg-success">Enabled</span>
                        @else
                            <span class="badge bg-secondary">Disabled</span>
                        @endif
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Last Login</span>
                        <span class="text-sm">{{ $user->last_login_at?->format('d M Y H:i') ?? '-' }}</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between">
                        <span class="text-sm text-muted">Created</span>
                        <span class="text-sm">{{ $user->created_at->format('d M Y') }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    {{-- Actions & WhatsApp --}}
    <div class="col-lg-8">
        {{-- Actions Card --}}
        @if($user->role !== 'owner' && $user->role !== 'super_admin')
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6><i class="fas fa-cogs me-2"></i>Owner Actions</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    @if($user->status !== 'banned')
                        <div class="col-md-4 mb-3">
                            <button class="btn btn-warning w-100" onclick="suspendUser({{ $user->id }}, '{{ $user->email }}')">
                                <i class="fas fa-pause-circle me-2"></i> Suspend User
                            </button>
                        </div>
                        <div class="col-md-4 mb-3">
                            <button class="btn btn-danger w-100" onclick="banUser({{ $user->id }}, '{{ $user->email }}')">
                                <i class="fas fa-ban me-2"></i> Ban User
                            </button>
                        </div>
                    @else
                        <div class="col-md-4 mb-3">
                            <button class="btn btn-success w-100" onclick="unbanUser({{ $user->id }}, '{{ $user->email }}')">
                                <i class="fas fa-check-circle me-2"></i> Unban User
                            </button>
                        </div>
                    @endif
                </div>
                
                <div class="alert alert-info mt-3 mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    <small>Semua aksi owner akan dicatat ke audit log dan tidak dapat dibatalkan oleh user.</small>
                </div>
            </div>
        </div>
        @endif

        {{-- WhatsApp Connections --}}
        <div class="card mb-4">
            <div class="card-header pb-0">
                <h6><i class="fab fa-whatsapp me-2"></i>WhatsApp Connections</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Phone</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Status</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Connected</th>
                                <th class="text-secondary opacity-7"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($whatsappConnections as $wa)
                                <tr>
                                    <td class="ps-4">
                                        <span class="text-sm font-weight-bold">{{ $wa->phone_number }}</span>
                                    </td>
                                    <td>
                                        @php
                                            $waStatusClass = match($wa->status) {
                                                'connected' => 'success',
                                                'pending' => 'warning',
                                                'banned' => 'danger',
                                                default => 'secondary',
                                            };
                                        @endphp
                                        <span class="badge bg-gradient-{{ $waStatusClass }}">{{ $wa->status }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $wa->connected_at?->format('d M Y H:i') ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <a href="{{ route('owner.whatsapp.show', $wa) }}" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center py-3">
                                        <span class="text-muted">Tidak ada koneksi WhatsApp</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Audit Logs --}}
        <div class="card">
            <div class="card-header pb-0">
                <h6><i class="fas fa-history me-2"></i>Audit Log</h6>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                <div class="table-responsive">
                    <table class="table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Actor</th>
                                <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($auditLogs as $log)
                                <tr>
                                    <td class="ps-4">
                                        <span class="badge bg-gradient-dark">{{ $log->action }}</span>
                                        @if($log->description)
                                            <br><small class="text-muted">{{ Str::limit($log->description, 50) }}</small>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="text-sm">{{ $log->actor_email ?? 'system' }}</span>
                                    </td>
                                    <td>
                                        <span class="text-xs">{{ $log->created_at->diffForHumans() }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center py-3">
                                        <span class="text-muted">Belum ada audit log</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Include same modals as index --}}
@include('owner.users._modals')
@endsection

@push('scripts')
<script>
let currentUserId = {{ $user->id }};

function banUser(userId, email) {
    Swal.fire({
        title: 'Ban User?',
        html: `<div class="text-start">
            <p class="text-danger"><strong>Peringatan!</strong> Tindakan ini akan:</p>
            <ul>
                <li>Menonaktifkan akun user</li>
                <li>Memutus semua koneksi WhatsApp</li>
                <li>Menghentikan semua campaign aktif</li>
            </ul>
        </div>`,
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Alasan (wajib)',
        inputPlaceholder: 'Masukkan alasan ban...',
        inputValidator: (value) => {
            if (!value) return 'Alasan wajib diisi!';
        },
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Ya, Ban User!',
        cancelButtonText: 'Batal',
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`/owner/users/${userId}/ban`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ reason: result.value }),
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

function suspendUser(userId, email) {
    Swal.fire({
        title: 'Suspend User?',
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Alasan (wajib)',
        inputPlaceholder: 'Masukkan alasan suspend...',
        inputValidator: (value) => {
            if (!value) return 'Alasan wajib diisi!';
        },
        showCancelButton: true,
        confirmButtonColor: '#ffc107',
        confirmButtonText: 'Ya, Suspend!',
        cancelButtonText: 'Batal',
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(`/owner/users/${userId}/suspend`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ reason: result.value }),
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

function unbanUser(userId, email) {
    Swal.fire({
        title: 'Unban User?',
        text: 'User akan bisa login kembali.',
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
</script>
@endpush
