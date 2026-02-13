@extends('layouts.app')

@section('title', 'Buat Kampanye WA Blast')

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb bg-transparent mb-0 pb-0 pt-1 px-0">
                    <li class="breadcrumb-item"><a href="{{ route('whatsapp.index') }}">WhatsApp</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('whatsapp.campaigns.index') }}">Kampanye</a></li>
                    <li class="breadcrumb-item active">Buat Baru</li>
                </ol>
            </nav>
            <h4 class="mb-0">Buat Kampanye WA Blast</h4>
        </div>
    </div>

    <form action="{{ route('whatsapp.campaigns.store') }}" method="POST" id="campaignForm">
        @csrf
        
        <div class="row">
            {{-- Main Form --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Detail Kampanye</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nama Kampanye <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" 
                                       required placeholder="Contoh: Promo Akhir Tahun 2026" value="{{ old('name') }}">
                                @error('name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-12 mb-3">
                                <label class="form-label">Deskripsi</label>
                                <textarea name="description" class="form-control" rows="2" 
                                          placeholder="Deskripsi singkat kampanye (opsional)">{{ old('description') }}</textarea>
                            </div>

                            <div class="col-md-12 mb-3">
                                <label class="form-label">Template Pesan <span class="text-danger">*</span></label>
                                <select name="template_id" class="form-select @error('template_id') is-invalid @enderror" 
                                        required id="templateSelect">
                                    <option value="">Pilih Template</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}" 
                                                data-variables="{{ $template->getVariableCount() }}"
                                                data-sample="{{ $template->sample_text }}"
                                                {{ (old('template_id') ?? request('template')) == $template->id ? 'selected' : '' }}>
                                            {{ $template->name }} ({{ $template->category }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('template_id')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            {{-- Template Preview --}}
                            <div class="col-md-12 mb-3" id="templatePreview" style="display: none;">
                                <label class="form-label">Preview Template</label>
                                <div class="alert alert-light border" id="templateSample"></div>
                            </div>

                            {{-- Template Variables --}}
                            <div class="col-md-12 mb-3" id="variablesSection" style="display: none;">
                                <label class="form-label">Variabel Template</label>
                                <p class="text-xs text-muted">Pilih field kontak untuk mengisi variabel template</p>
                                <div id="variableFields"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Audience --}}
                <div class="card mt-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Target Penerima</h6>
                        <p class="text-xs text-muted mb-0">Hanya kontak yang sudah opt-in yang akan menerima pesan</p>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info d-flex align-items-center">
                            <i class="fas fa-users me-3" style="font-size: 1.5rem;"></i>
                            <div>
                                <strong>{{ $contactsCount }}</strong> kontak tersedia (opt-in)
                            </div>
                        </div>

                        @if($tags->count() > 0)
                        <div class="mb-3">
                            <label class="form-label">Filter berdasarkan Tag (Opsional)</label>
                            <div class="row">
                                @foreach($tags as $tag)
                                <div class="col-md-4 mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                               name="audience_filter[tags][]" value="{{ $tag }}" id="tag_{{ $loop->index }}">
                                        <label class="form-check-label" for="tag_{{ $loop->index }}">
                                            {{ $tag }}
                                        </label>
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            <small class="text-muted">Kosongkan untuk mengirim ke semua kontak opt-in</small>
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Sidebar --}}
            <div class="col-lg-4">
                {{-- Schedule --}}
                <div class="card">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Jadwal Pengiriman</h6>
                    </div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="schedule_type" 
                                   value="now" id="scheduleNow" checked>
                            <label class="form-check-label" for="scheduleNow">
                                Simpan sebagai Draft
                            </label>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="schedule_type" 
                                   value="scheduled" id="scheduleScheduled">
                            <label class="form-check-label" for="scheduleScheduled">
                                Jadwalkan
                            </label>
                        </div>
                        <div id="scheduleDatetime" style="display: none;">
                            <input type="datetime-local" name="scheduled_at" class="form-control" 
                                   min="{{ now()->addMinutes(5)->format('Y-m-d\TH:i') }}">
                        </div>
                    </div>
                </div>

                {{-- Settings --}}
                <div class="card mt-4">
                    <div class="card-header pb-0">
                        <h6 class="mb-0">Pengaturan</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Rate Limit (pesan/detik)</label>
                            <select name="rate_limit_per_second" class="form-select">
                                <option value="5">5 (Lambat - Aman)</option>
                                <option value="10" selected>10 (Default)</option>
                                <option value="20">20 (Cepat)</option>
                                <option value="30">30 (Sangat Cepat)</option>
                            </select>
                            <small class="text-muted">Rate limit lebih tinggi = pengiriman lebih cepat, tapi risiko throttling</small>
                        </div>
                    </div>
                </div>

                {{-- Estimated Cost --}}
                <div class="card mt-4 bg-gradient-success">
                    <div class="card-body">
                        <div class="text-white">
                            <p class="text-sm mb-1 opacity-8">Estimasi Biaya</p>
                            <h4 class="text-white mb-0">
                                Rp <span id="estimatedCost">{{ number_format($contactsCount * 350, 0, ',', '.') }}</span>
                            </h4>
                            <p class="text-xs opacity-8 mb-0">
                                <span id="recipientCount">{{ $contactsCount }}</span> penerima × Rp 350
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Submit --}}
                <div class="mt-4">
                    <button type="submit" class="btn btn-success w-100 mb-2">
                        <i class="fas fa-save me-2"></i>Buat Kampanye
                    </button>
                    <a href="{{ route('whatsapp.campaigns.index') }}" class="btn btn-outline-secondary w-100">
                        Batal
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const templateSelect = document.getElementById('templateSelect');
    const templatePreview = document.getElementById('templatePreview');
    const templateSample = document.getElementById('templateSample');
    const variablesSection = document.getElementById('variablesSection');
    const variableFields = document.getElementById('variableFields');
    const scheduleRadios = document.querySelectorAll('input[name="schedule_type"]');
    const scheduleDatetime = document.getElementById('scheduleDatetime');

    // Template selection
    templateSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        const sample = selected.dataset.sample;
        const variables = parseInt(selected.dataset.variables) || 0;

        if (sample) {
            templateSample.textContent = sample;
            templatePreview.style.display = 'block';
        } else {
            templatePreview.style.display = 'none';
        }

        // Generate variable fields
        if (variables > 0) {
            variablesSection.style.display = 'block';
            let html = '';
            for (let i = 1; i <= variables; i++) {
                html += `
                    <div class="row mb-2">
                        <div class="col-4">
                            <span class="text-sm">{{${i}}}</span>
                        </div>
                        <div class="col-8">
                            <select name="template_variables[${i-1}]" class="form-select form-select-sm">
                                <option value="name">Nama Kontak</option>
                                <option value="phone">Nomor HP</option>
                                <option value="email">Email</option>
                            </select>
                        </div>
                    </div>
                `;
            }
            variableFields.innerHTML = html;
        } else {
            variablesSection.style.display = 'none';
        }
    });

    // Trigger on page load if template is pre-selected
    if (templateSelect.value) {
        templateSelect.dispatchEvent(new Event('change'));
    }

    // Schedule type toggle
    scheduleRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            scheduleDatetime.style.display = this.value === 'scheduled' ? 'block' : 'none';
        });
    });
});
</script>
@endpush
