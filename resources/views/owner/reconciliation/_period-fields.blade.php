{{-- Reusable period selector fields --}}
<div class="row g-2 mb-2">
    <div class="col-6">
        <label class="form-label text-xs font-weight-bold text-uppercase">Bulan</label>
        <select name="month" class="form-select form-select-sm">
            @php
                $bulanList = [
                    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
                    5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
                    9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
                ];
            @endphp
            @foreach($bulanList as $num => $nama)
                <option value="{{ $num }}" {{ $num == now()->month ? 'selected' : '' }}>
                    {{ $nama }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-6">
        <label class="form-label text-xs font-weight-bold text-uppercase">Tahun</label>
        <select name="year" class="form-select form-select-sm">
            @for($y = now()->year; $y >= 2024; $y--)
                <option value="{{ $y }}">{{ $y }}</option>
            @endfor
        </select>
    </div>
</div>
