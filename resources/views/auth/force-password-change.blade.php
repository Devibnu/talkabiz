@extends('layouts.user_type.auth')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">
            <div class="card">
                <div class="card-header pb-0 text-center">
                    <div class="icon icon-shape icon-lg bg-gradient-warning shadow text-center border-radius-lg mb-3">
                        <i class="ni ni-lock-circle-open text-white"></i>
                    </div>
                    <h4 class="font-weight-bolder">Ganti Password</h4>
                    <p class="text-sm text-muted mb-0">
                        Anda harus mengubah password sebelum melanjutkan
                    </p>
                </div>
                <div class="card-body">
                    @if(session('warning'))
                        <div class="alert alert-warning text-white text-sm" role="alert">
                            {{ session('warning') }}
                        </div>
                    @endif

                    @if($errors->any())
                        <div class="alert alert-danger text-white text-sm" role="alert">
                            <ul class="mb-0">
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form action="{{ route('password.force-change.update') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label class="form-label">Password Lama</label>
                            <input type="password" 
                                   name="current_password" 
                                   class="form-control @error('current_password') is-invalid @enderror"
                                   placeholder="Masukkan password saat ini"
                                   required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Password Baru</label>
                            <input type="password" 
                                   name="password" 
                                   class="form-control @error('password') is-invalid @enderror"
                                   placeholder="Minimal 8 karakter, huruf besar/kecil, angka, simbol"
                                   required>
                            <small class="text-muted">
                                Password harus mengandung: huruf besar, huruf kecil, angka, dan simbol
                            </small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Konfirmasi Password Baru</label>
                            <input type="password" 
                                   name="password_confirmation" 
                                   class="form-control"
                                   placeholder="Ulangi password baru"
                                   required>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn bg-gradient-primary">
                                <i class="ni ni-check-bold me-2"></i>
                                Simpan Password Baru
                            </button>
                        </div>
                    </form>

                    <hr class="horizontal dark my-4">

                    <div class="text-center">
                        <x-logout-button class="btn btn-link text-danger" icon="" text="Logout" />
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
