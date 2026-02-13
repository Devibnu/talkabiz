@extends('layouts.user_type.auth')

@section('content')
<style>
.kontak-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #8392ab;
    font-weight: 600;
    padding: 12px 16px;
    border-bottom: 1px solid #e9ecef;
}
.kontak-table td {
    padding: 12px 16px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}
.tag-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    background: linear-gradient(310deg, #627594 0%, #a8b8d8 100%);
    color: #fff;
    margin-right: 4px;
    margin-bottom: 4px;
}
.avatar-contact {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 0.875rem;
    background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%);
    color: #fff;
}
.empty-state {
    padding: 80px 20px;
    text-align: center;
}
.empty-state i {
    font-size: 4rem;
    background: linear-gradient(310deg, #7928ca 0%, #ff0080 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    margin-bottom: 1.5rem;
}
</style>

<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1">Kontak / Audience</h6>
                    <p class="text-sm text-secondary mb-0">Kelola daftar kontak untuk campaign WhatsApp</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                        <i class="ni ni-cloud-upload-96 me-2"></i>Import CSV
                    </button>
                    <button class="btn bg-gradient-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addKontakModal">
                        <i class="ni ni-fat-add me-2"></i>Tambah Kontak
                    </button>
                </div>
            </div>
            <div class="card-body px-0 pt-0 pb-2">
                @if(isset($kontaks) && $kontaks->count() > 0)
                <div class="table-responsive p-0">
                    <table class="table kontak-table align-items-center mb-0">
                        <thead>
                            <tr>
                                <th>Nama</th>
                                <th>No. Telepon</th>
                                <th>Email</th>
                                <th>Tags</th>
                                <th>Sumber</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($kontaks as $kontak)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-contact me-3">{{ $kontak->inisial }}</div>
                                        <span class="font-weight-bold">{{ $kontak->nama }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-sm">{{ $kontak->no_telepon }}</span>
                                </td>
                                <td>
                                    <span class="text-sm text-secondary">{{ $kontak->email ?? '-' }}</span>
                                </td>
                                <td>
                                    @if($kontak->tags)
                                        @foreach($kontak->tags as $tag)
                                        <span class="tag-badge">{{ $tag }}</span>
                                        @endforeach
                                    @else
                                        <span class="text-sm text-secondary">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge badge-sm bg-gradient-secondary">{{ ucfirst($kontak->source) }}</span>
                                </td>
                                <td>
                                    <button class="btn btn-link text-danger p-0" onclick="hapusKontak({{ $kontak->id }})" title="Hapus">
                                        <i class="ni ni-fat-remove text-lg"></i>
                                    </button>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                <div class="empty-state">
                    <i class="ni ni-single-02"></i>
                    <h5 class="font-weight-bold">Belum Ada Kontak</h5>
                    <p class="text-secondary mb-4">Tambahkan kontak pertama Anda untuk mulai mengirim campaign WhatsApp</p>
                    <button class="btn bg-gradient-primary" data-bs-toggle="modal" data-bs-target="#addKontakModal">
                        <i class="ni ni-fat-add me-2"></i>Tambah Kontak Pertama
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Modal Tambah Kontak -->
<div class="modal fade" id="addKontakModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('kontak.store') }}" method="POST" id="formAddKontak">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Tambah Kontak Baru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nama <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nama" required placeholder="Nama lengkap">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">No. Telepon <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="no_telepon" required placeholder="08xxxxxxxxxx">
                        <small class="text-muted">Format: 08xxxxxxxxxx atau 628xxxxxxxxxx</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" placeholder="email@contoh.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tags</label>
                        <input type="text" class="form-control" name="tags" placeholder="vip, customer, prospect">
                        <small class="text-muted">Pisahkan dengan koma</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea class="form-control" name="catatan" rows="2" placeholder="Catatan tambahan..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn bg-gradient-primary">Simpan Kontak</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Import CSV -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('kontak.import') }}" method="POST" enctype="multipart/form-data" id="formImport">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Import Kontak dari CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Format CSV:</strong><br>
                        Kolom: nama, no_telepon, email, tags<br>
                        Baris pertama = header (diabaikan)
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pilih File CSV</label>
                        <input type="file" class="form-control" name="file" accept=".csv,.txt" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn bg-gradient-primary">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function hapusKontak(id) {
    if (confirm('Yakin ingin menghapus kontak ini?')) {
        fetch(`/kontak/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Gagal menghapus kontak');
            }
        });
    }
}

// Import form handler
document.getElementById('formImport').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    
    fetch(this.action, {
        method: 'POST',
        body: formData,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json'
        }
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Gagal import: ' + JSON.stringify(data.errors));
        }
    });
});
</script>
@endsection