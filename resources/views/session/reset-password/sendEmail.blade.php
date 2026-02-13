@extends('layouts.user_type.guest')

@section('content')

  <section class="min-vh-100 mb-8">
    <div class="page-header align-items-start min-vh-50 pt-5 pb-11 mx-3 border-radius-lg" style="background-image: url('../assets/img/curved-images/curved14.jpg');">
      <span class="mask bg-gradient-dark opacity-6"></span>
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-lg-5 text-center mx-auto">
            <h1 class="text-white mb-2 mt-5">Lupa Password?</h1>
            <p class="text-lead text-white">Masukkan email Anda untuk menerima link reset password.</p>
          </div>
        </div>
      </div>
    </div>
    <div class="container">
      <div class="row mt-lg-n10 mt-md-n11 mt-n10">
        <div class="col-xl-4 col-lg-5 col-md-7 mx-auto">
          <div class="card z-index-0">
            <div class="card-header text-center pt-4">
              <h5>Reset Password</h5>
            </div>
            <div class="card-body">
              @if(session('success'))
                <div class="alert alert-success text-white text-center mb-4" role="alert">
                  <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                </div>
              @endif
              @if($errors->any())
                <div class="alert alert-danger text-white text-center mb-4" role="alert">
                  <i class="fas fa-exclamation-circle me-2"></i>{{ $errors->first() }}
                </div>
              @endif
              <form action="/forgot-password" method="POST" role="form">
                @csrf
                <div class="mb-3">
                  <input type="email" class="form-control" placeholder="Email Bisnis" name="email" id="email" aria-label="Email" aria-describedby="email-addon" value="{{ old('email') }}" required autofocus>
                  @error('email')
                    <p class="text-danger text-xs mt-2">{{ $message }}</p>
                  @enderror
                </div>
                <div class="text-center">
                  <button type="submit" class="btn bg-gradient-dark w-100 my-4 mb-2">Kirim Link Reset Password</button>
                </div>
                <p class="text-sm mt-3 mb-0 text-center">Sudah ingat password? <a href="/login" class="text-dark font-weight-bolder">Masuk</a></p>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

@endsection