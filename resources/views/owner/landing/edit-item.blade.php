@extends('owner.layouts.app')

@section('page-title', 'Edit Item Landing')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header pb-0">
                <h5 class="mb-0">Edit Item: {{ $item->section->key }}</h5>
                <p class="text-sm text-muted mb-0">Section: {{ $item->section->title }} | Item Key: {{ $item->key ?? '(no key)' }}</p>
            </div>
            <div class="card-body">
                <div class="alert alert-light border mb-3">
                    <i class="fas fa-lightbulb me-2 text-warning"></i>
                    <strong>Tips:</strong> Isi sesuai kebutuhan. Title/description kosong = tidak ditampilkan. Key tidak bisa diubah.
                </div>
                <form action="{{ route('owner.landing.items.update', $item) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $item->title) }}">
                        <small class="text-muted">Judul item (bisa kosongkan jika hanya ada description/CTA)</small>
                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3">{{ old('description', $item->description) }}</textarea>
                        <small class="text-muted">Deskripsi detail item (opsional)</small>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Icon (class)</label>
                        <input type="text" name="icon" class="form-control @error('icon') is-invalid @enderror" value="{{ old('icon', $item->icon) }}" placeholder="contoh: fas fa-store">
                        <small class="text-muted">Class FontAwesome (cek <a href="https://fontawesome.com/icons" target="_blank">fontawesome.com/icons</a>)</small>
                        @error('icon')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Bullets (maks 6, satu per baris)</label>
                        <textarea name="bullets_text" class="form-control @error('bullets_text') is-invalid @enderror" rows="6">{{ old('bullets_text', $bulletsText) }}</textarea>
                        <small class="text-muted">Untuk Solutions/Features: maks 6 poin, 80 karakter per baris</small>
                        @error('bullets_text')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CTA Label</label>
                        <input type="text" name="cta_label" class="form-control @error('cta_label') is-invalid @enderror" value="{{ old('cta_label', $item->cta_label) }}">
                        <small class="text-muted">Teks tombol/link (contoh: "Daftar Sekarang", "Hubungi Sales")</small>
                        @error('cta_label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CTA URL</label>
                        <input type="text" name="cta_url" class="form-control @error('cta_url') is-invalid @enderror" value="{{ old('cta_url', $item->cta_url) }}" placeholder="https://... atau /register">
                        <small class="text-muted">URL lengkap atau relative path (contoh: /register, https://wa.me/...)</small>
                        @error('cta_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="order" class="form-control @error('order') is-invalid @enderror" value="{{ old('order', $item->order) }}" min="0" max="999">
                        @error('order')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $item->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="{{ route('owner.landing.index') }}" class="btn btn-outline-secondary">Kembali</a>
                        <button type="submit" class="btn bg-gradient-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
