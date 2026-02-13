@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-4">
            {{-- User Info Card --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Informasi User</h6>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="avatar avatar-xl bg-gradient-primary rounded-circle mb-3">
                            <span class="text-white text-lg">
                                {{ strtoupper(substr($user->name ?? $user->email, 0, 2)) }}
                            </span>
                        </div>
                        <h5 class="mb-1">{{ $user->name }}</h5>
                        <p class="text-sm text-muted mb-0">{{ $user->email }}</p>
                        <span class="badge badge-sm 
                            @if(in_array($user->role, ['super_admin', 'superadmin'])) bg-gradient-danger
                            @elseif($user->role == 'admin') bg-gradient-warning
                            @else bg-gradient-secondary
                            @endif mt-2">
                            {{ ucfirst(str_replace('_', ' ', $user->role ?? 'user')) }}
                        </span>
                    </div>

                    <hr class="horizontal dark">

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">ID</span>
                            <span class="text-sm font-weight-bold">#{{ $user->id }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">Klien ID</span>
                            <span class="text-sm font-weight-bold">{{ $user->klien_id ?? '-' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">Registered</span>
                            <span class="text-sm">{{ $user->created_at->format('d M Y') }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">Last Login</span>
                            <span class="text-sm">{{ $user->last_login_at ? $user->last_login_at->format('d M Y H:i') : 'Never' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">Last IP</span>
                            <span class="text-sm font-weight-bold">{{ $user->last_login_ip ?? '-' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-sm">Failed Attempts</span>
                            <span class="text-sm font-weight-bold text-{{ $user->failed_login_attempts > 0 ? 'danger' : 'success' }}">
                                {{ $user->failed_login_attempts }}
                            </span>
                        </li>
                    </ul>
                </div>
            </div>

            {{-- Actions Card --}}
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Aksi</h6>
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success text-white text-sm" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    @can('reset-user-password', $user)
                        <form action="{{ route('admin.users.reset-password', $user) }}" method="POST" class="mb-3">
                            @csrf
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="force_change" value="1" id="forceChange" checked>
                                <label class="form-check-label text-sm" for="forceChange">
                                    Wajibkan ganti password saat login
                                </label>
                            </div>
                            <button type="submit" class="btn bg-gradient-warning btn-sm w-100"
                                    onclick="return confirm('Reset password user ini?')">
                                <i class="fas fa-key me-2"></i> Reset Password
                            </button>
                        </form>

                        <form action="{{ route('admin.users.toggle-force-password', $user) }}" method="POST" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-outline-{{ $user->force_password_change ? 'success' : 'warning' }} btn-sm w-100">
                                <i class="fas fa-{{ $user->force_password_change ? 'unlock' : 'lock' }} me-2"></i>
                                {{ $user->force_password_change ? 'Hapus Force Password' : 'Set Force Password' }}
                            </button>
                        </form>

                        <form action="{{ route('admin.users.invalidate-sessions', $user) }}" method="POST" class="mb-3">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100"
                                    onclick="return confirm('Logout paksa dari semua device?')">
                                <i class="fas fa-sign-out-alt me-2"></i> Invalidate Sessions
                            </button>
                        </form>
                    @endcan

                    @can('change-user-role', [$user, null])
                        @if(!in_array($user->role, ['super_admin', 'superadmin']))
                            <hr class="horizontal dark">
                            <form action="{{ route('admin.users.update-role', $user) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <label class="form-label text-sm">Ubah Role</label>
                                <select name="role" class="form-select form-select-sm mb-2">
                                    <option value="user" {{ $user->role == 'user' ? 'selected' : '' }}>User</option>
                                    @can('super-admin')
                                        <option value="admin" {{ $user->role == 'admin' ? 'selected' : '' }}>Admin</option>
                                    @endcan
                                </select>
                                <button type="submit" class="btn btn-outline-primary btn-sm w-100"
                                        onclick="return confirm('Ubah role user ini?')">
                                    <i class="fas fa-user-shield me-2"></i> Update Role
                                </button>
                            </form>
                        @endif
                    @endcan

                    @can('delete-user', $user)
                        <hr class="horizontal dark">
                        <form action="{{ route('admin.users.destroy', $user) }}" method="POST">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn bg-gradient-danger btn-sm w-100"
                                    onclick="return confirm('HAPUS PERMANEN user ini? Tidak dapat dikembalikan!')">
                                <i class="fas fa-trash me-2"></i> Hapus User
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            {{-- Activity Log --}}
            <div class="card">
                <div class="card-header pb-0">
                    <h6 class="mb-0">Activity Log</h6>
                    <p class="text-sm text-muted mb-0">50 aktivitas terakhir</p>
                </div>
                <div class="card-body px-0 pb-2">
                    <div class="table-responsive">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Waktu</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Aksi</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Deskripsi</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($activityLogs as $log)
                                    <tr>
                                        <td>
                                            <span class="text-xs text-secondary">
                                                {{ $log->created_at->format('d/m/Y H:i') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-sm 
                                                @if(str_contains($log->action, 'failed')) bg-gradient-danger
                                                @elseif(str_contains($log->action, 'reset') || str_contains($log->action, 'force')) bg-gradient-warning
                                                @elseif($log->action == 'login') bg-gradient-success
                                                @else bg-gradient-info
                                                @endif">
                                                {{ $log->action }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-xs">{{ Str::limit($log->description, 50) }}</span>
                                        </td>
                                        <td>
                                            <span class="text-xs text-secondary">{{ $log->ip_address }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center py-4">
                                            <p class="text-muted mb-0">Belum ada aktivitas</p>
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
</div>

<style>
.avatar {
    width: 64px;
    height: 64px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.avatar-xl {
    width: 80px;
    height: 80px;
}
</style>
@endsection
