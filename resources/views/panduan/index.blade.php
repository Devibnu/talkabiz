@extends('layouts.user_type.auth')

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header pb-0">
                <div class="d-flex align-items-center">
                    <div class="icon icon-shape bg-gradient-primary shadow border-radius-md text-center me-3">
                        <i class="fas fa-book-open text-lg opacity-10" aria-hidden="true"></i>
                    </div>
                    <div>
                        <h5 class="mb-0">Panduan Kirim WhatsApp yang Aman</h5>
                        <p class="text-sm text-secondary mb-0">Pelajari cara mengirim campaign yang efektif dan tidak di-block</p>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                {{-- Alert Success --}}
                @php
                    $user = auth()->user();
                    $justCompleted = !$user->getOnboardingStep('guide_read');
                @endphp
                
                @if($justCompleted)
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Langkah selesai!</strong> Anda telah membaca panduan kirim aman.
                    <a href="{{ url('dashboard') }}" class="alert-link">Kembali ke Dashboard</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                @endif

                {{-- Section 1: Prinsip Dasar --}}
                <div class="mb-4">
                    <h6 class="font-weight-bolder text-primary">
                        <i class="fas fa-shield-alt me-2"></i>1. Prinsip Dasar Pengiriman WhatsApp
                    </h6>
                    <div class="ps-4">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-check text-success me-2"></i>
                                <strong>Izin Penerima:</strong> Hanya kirim ke kontak yang sudah memberikan izin (opt-in)
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success me-2"></i>
                                <strong>Relevansi:</strong> Pastikan pesan relevan dengan kebutuhan penerima
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success me-2"></i>
                                <strong>Frekuensi:</strong> Jangan terlalu sering mengirim pesan ke kontak yang sama
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-check text-success me-2"></i>
                                <strong>Jam Kirim:</strong> Hindari kirim di luar jam kerja (08:00 - 18:00)
                            </li>
                        </ul>
                    </div>
                </div>

                {{-- Section 2: Yang Dilarang --}}
                <div class="mb-4">
                    <h6 class="font-weight-bolder text-danger">
                        <i class="fas fa-ban me-2"></i>2. Yang Harus Dihindari
                    </h6>
                    <div class="ps-4">
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-times text-danger me-2"></i>
                                Mengirim spam atau pesan yang tidak diminta
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-times text-danger me-2"></i>
                                Menggunakan kontak yang dibeli atau scraped
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-times text-danger me-2"></i>
                                Mengirim konten menyesatkan, penipuan, atau ilegal
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-times text-danger me-2"></i>
                                Blast dalam volume besar tanpa warming up
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-times text-danger me-2"></i>
                                Mengabaikan laporan atau complaint dari penerima
                            </li>
                        </ul>
                    </div>
                </div>

                {{-- Section 3: Tips Sukses --}}
                <div class="mb-4">
                    <h6 class="font-weight-bolder text-success">
                        <i class="fas fa-lightbulb me-2"></i>3. Tips Campaign yang Sukses
                    </h6>
                    <div class="ps-4">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light mb-3">
                                    <div class="card-body p-3">
                                        <h6 class="text-sm font-weight-bold mb-2">
                                            <i class="fas fa-users text-primary me-1"></i> Segmentasi Kontak
                                        </h6>
                                        <p class="text-xs mb-0">Kelompokkan kontak berdasarkan minat atau kategori untuk pesan yang lebih personal.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light mb-3">
                                    <div class="card-body p-3">
                                        <h6 class="text-sm font-weight-bold mb-2">
                                            <i class="fas fa-clock text-warning me-1"></i> Waktu yang Tepat
                                        </h6>
                                        <p class="text-xs mb-0">Kirim di jam 09:00-11:00 atau 14:00-16:00 untuk open rate terbaik.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light mb-3">
                                    <div class="card-body p-3">
                                        <h6 class="text-sm font-weight-bold mb-2">
                                            <i class="fas fa-file-alt text-success me-1"></i> Template Berkualitas
                                        </h6>
                                        <p class="text-xs mb-0">Gunakan template yang sudah disetujui dan hindari kata-kata spam trigger.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light mb-3">
                                    <div class="card-body p-3">
                                        <h6 class="text-sm font-weight-bold mb-2">
                                            <i class="fas fa-chart-line text-info me-1"></i> Monitor Hasil
                                        </h6>
                                        <p class="text-xs mb-0">Pantau delivery rate dan feedback untuk terus mengoptimasi campaign.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Section 4: Batasan --}}
                <div class="mb-4">
                    <h6 class="font-weight-bolder text-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>4. Batasan & Kuota
                    </h6>
                    <div class="ps-4">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <tbody>
                                    <tr>
                                        <td><strong>Akun Baru (Pilot)</strong></td>
                                        <td>Maks 100 pesan/hari, 1 campaign aktif</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Akun Verified</strong></td>
                                        <td>Maks 1.000 pesan/hari, 5 campaign aktif</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Akun Premium</strong></td>
                                        <td>Maks 10.000 pesan/hari, unlimited campaign</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Action Button --}}
                <div class="text-center mt-5">
                    <a href="{{ url('dashboard') }}" class="btn bg-gradient-primary">
                        <i class="fas fa-arrow-left me-2"></i>Kembali ke Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
