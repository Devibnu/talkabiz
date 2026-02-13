@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0">Manajemen User</h6>
                        <p class="text-sm mb-0 text-muted">Kelola semua user di platform</p>
                    </div>
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    {{-- Search & Filter --}}
                    <div class="px-4 py-3 border-bottom">
                        <form action="{{ route('admin.users.index') }}" method="GET" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" name="search" class="form-control form-control-sm" 
                                       placeholder="Cari nama atau email..." value="{{ request('search') }}">
                            </div>
                            <div class="col-md-3">
                                <select name="role" class="form-select form-select-sm">
                                    <option value="">Semua Role</option>
                                    <option value="user" {{ request('role') == 'user' ? 'selected' : '' }}>User</option>
                                    <option value="admin" {{ request('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                                    <option value="super_admin" {{ request('role') == 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-sm bg-gradient-primary mb-0">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    @if(session('success'))
                        <div class="alert alert-success text-white mx-4 mt-3" role="alert">
                            {{ session('success') }}
                        </div>
                    @endif

                    <div class="table-responsive p-0">
                        <table class="table align-items-center mb-0">
                            <thead>
                                <tr>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">User</th>
                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7 ps-2">Role</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                    <th class="text-center text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Last Login</th>
                                    <th class="text-secondary opacity-7">Aksi</th>
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
                                            <span class="badge badge-sm 
                                                @if(in_array($user->role, ['super_admin', 'superadmin'])) bg-gradient-danger
                                                @elseif($user->role == 'admin') bg-gradient-warning
                                                @else bg-gradient-secondary
                                                @endif">
                                                {{ ucfirst(str_replace('_', ' ', $user->role ?? 'user')) }}
                                            </span>
                                        </td>
                                        <td class="align-middle text-center text-sm">
                                            @if($user->force_password_change)
                                                <span class="badge badge-sm bg-gradient-warning">Force Change</span>
                                            @elseif($user->locked_until && $user->locked_until->isFuture())
                                                <span class="badge badge-sm bg-gradient-danger">Locked</span>
                                            @else
                                                <span class="badge badge-sm bg-gradient-success">Active</span>
                                            @endif
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-secondary text-xs font-weight-bold">
                                                {{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}
                                            </span>
                                        </td>
                                        <td class="align-middle">
                                            <div class="dropdown">
                                                <button class="btn btn-link text-secondary mb-0 dropdown-toggle" 
                                                        type="button" data-bs-toggle="dropdown">
                                                    <i class="fa fa-ellipsis-v text-xs"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('admin.users.show', $user) }}">
                                                            <i class="fas fa-eye me-2"></i> Detail
                                                        </a>
                                                    </li>
                                                    
                                                    @can('reset-user-password', $user)
                                                        <li>
                                                            <form action="{{ route('admin.users.reset-password', $user) }}" method="POST" class="d-inline">
                                                                @csrf
                                                                <input type="hidden" name="force_change" value="1">
                                                                <button type="submit" class="dropdown-item" 
                                                                        onclick="return confirm('Reset password user ini?')">
                                                                    <i class="fas fa-key me-2"></i> Reset Password
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <form action="{{ route('admin.users.invalidate-sessions', $user) }}" method="POST" class="d-inline">
                                                                @csrf
                                                                <button type="submit" class="dropdown-item"
                                                                        onclick="return confirm('Logout paksa user ini dari semua device?')">
                                                                    <i class="fas fa-sign-out-alt me-2"></i> Invalidate Sessions
                                                                </button>
                                                            </form>
                                                        </li>
                                                    @endcan
                                                    
                                                    @can('delete-user', $user)
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline">
                                                                @csrf
                                                                @method('DELETE')
                                                                <button type="submit" class="dropdown-item text-danger"
                                                                        onclick="return confirm('HAPUS user ini? Aksi tidak dapat dibatalkan!')">
                                                                    <i class="fas fa-trash me-2"></i> Hapus
                                                                </button>
                                                            </form>
                                                        </li>
                                                    @endcan
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-4">
                                            <p class="text-muted mb-0">Tidak ada user ditemukan</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination --}}
                    <div class="px-4 py-3">
                        {{ $users->withQueryString()->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
