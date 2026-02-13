@extends('owner.layouts.app')

@section('page-title', 'Edit Section Landing')

@section('content')
<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header pb-0">
                <h5 class="mb-0">Edit Section: {{ $section->key }}</h5>
                <p class="text-sm text-muted mb-0">Update judul dan subtitle section. Key tidak bisa diubah.</p>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Info:</strong> Section ini digunakan di landing publik. Pastikan konten tetap relevan dan profesional.
                </div>
                <form action="{{ route('owner.landing.sections.update', $section) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="mb-3">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $section->title) }}" required>
                        <small class="text-muted">Judul utama section yang tampil di landing publik</small>
                        @error('title')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Subtitle</label>
                        <input type="text" name="subtitle" class="form-control @error('subtitle') is-invalid @enderror" value="{{ old('subtitle', $section->subtitle) }}">
                        <small class="text-muted">Keterangan tambahan di bawah judul (opsional)</small>
                        @error('subtitle')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Order</label>
                        <input type="number" name="order" class="form-control @error('order') is-invalid @enderror" value="{{ old('order', $section->order) }}" min="0" max="999">
                        @error('order')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', $section->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                        <br><small class="text-muted">⚠️ Jika dinonaktifkan, section ini tidak tampil di landing publik</small>
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
