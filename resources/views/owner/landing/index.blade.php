@extends('owner.layouts.app')

@section('page-title', 'Landing Page')

@section('content')
<div class="row">
    <div class="col-12">
        <!-- WARNING BOX -->
        <div class="alert alert-warning" role="alert">
            <div class="d-flex align-items-start">
                <i class="fas fa-exclamation-triangle me-3 mt-1"></i>
                <div>
                    <h6 class="alert-heading mb-2">⚠️ ATURAN OWNER LANDING CMS</h6>
                    <p class="mb-2"><strong>Owner Landing = KONTEN SAJA.</strong> Halaman ini hanya mengelola teks, deskripsi, dan gambar landing publik.</p>
                    <p class="mb-2"><strong class="text-danger">DILARANG KERAS:</strong></p>
                    <ul class="mb-2" style="list-style: none; padding-left: 0;">
                        <li>❌ Mengubah atau menghapus section PRICING/PAKET (dikelola di <a href="{{ route('owner.plans.index') }}" class="alert-link">Plans</a>)</li>
                        <li>❌ Mengatur quota atau limit pesan (dikelola di <a href="{{ route('owner.plans.index') }}" class="alert-link">Plans</a>)</li>
                        <li>❌ Membuat section baru tanpa mapping di landing publik</li>
                        <li>❌ Menonaktifkan section yang wajib (hero, features, solutions)</li>
                    </ul>
                    <p class="mb-0"><strong class="text-success">BOLEH:</strong> Edit judul, deskripsi, icon, bullet points, CTA label/link untuk section yang ada.</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header pb-0">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">Landing Page Content ({{ $sections->count() }} Sections)</h5>
                        <p class="text-sm text-muted mb-0">Edit konten landing tanpa menyentuh pricing dari Plans.</p>
                    </div>
                </div>
            </div>
            <div class="card-body">
                @if(session('success'))
                    <div class="alert alert-success" role="alert">
                        {{ session('success') }}
                    </div>
                @endif

                @if($sections->isEmpty())
                    <div class="text-center py-4">
                        <p class="text-muted mb-0">Belum ada section landing.</p>
                    </div>
                @else
                    @foreach($sections as $section)
                        <div class="card mb-4">
                            <div class="card-header pb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0">{{ $section->title }}</h6>
                                        <p class="text-xs text-muted mb-0">Key: {{ $section->key }} | Order: {{ $section->order }}</p>
                                    </div>
                                    <div>
                                        <span class="badge {{ $section->is_active ? 'bg-success' : 'bg-secondary' }}">
                                            {{ $section->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                        <a href="{{ route('owner.landing.sections.edit', $section) }}" class="btn btn-sm btn-outline-primary ms-2">
                                            Edit Section
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                @if($section->subtitle)
                                    <p class="text-sm text-muted">{{ $section->subtitle }}</p>
                                @endif

                                @if($section->items->isEmpty())
                                    <p class="text-muted mb-0">Belum ada item untuk section ini.</p>
                                @else
                                    <div class="table-responsive">
                                        <table class="table align-items-center mb-0">
                                            <thead>
                                                <tr>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Title</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Key</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Order</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Status</th>
                                                    <th class="text-uppercase text-secondary text-xxs font-weight-bolder opacity-7">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($section->items as $item)
                                                    <tr>
                                                        <td class="text-sm">
                                                            {{ $item->title }}
                                                        </td>
                                                        <td class="text-sm">
                                                            {{ $item->key ?? '-' }}
                                                        </td>
                                                        <td class="text-sm">
                                                            {{ $item->order }}
                                                        </td>
                                                        <td>
                                                            <span class="badge {{ $item->is_active ? 'bg-success' : 'bg-secondary' }}">
                                                                {{ $item->is_active ? 'Active' : 'Inactive' }}
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <a href="{{ route('owner.landing.items.edit', $item) }}" class="btn btn-sm btn-outline-primary">
                                                                Edit Item
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
