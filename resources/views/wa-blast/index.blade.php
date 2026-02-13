@extends('layouts.app', ['class' => 'g-sidenav-show bg-gray-100'])

@section('content')
@include('layouts.navbars.auth.topnav', ['title' => 'WA Blast'])

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0">
                    <h5 class="mb-0">WA Blast Campaign</h5>
                    <p class="text-sm mb-0">Kirim pesan WhatsApp ke banyak kontak sekaligus menggunakan template yang sudah disetujui Meta.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stepper -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body py-3">
                    <div class="stepper-wrapper d-flex justify-content-between">
                        <div class="stepper-item" :class="{ 'completed': step > 1, 'active': step === 1 }" data-step="1">
                            <div class="step-counter">1</div>
                            <div class="step-name">Pilih Template</div>
                        </div>
                        <div class="stepper-item" :class="{ 'completed': step > 2, 'active': step === 2 }" data-step="2">
                            <div class="step-counter">2</div>
                            <div class="step-name">Pilih Audience</div>
                        </div>
                        <div class="stepper-item" :class="{ 'completed': step > 3, 'active': step === 3 }" data-step="3">
                            <div class="step-counter">3</div>
                            <div class="step-name">Review & Quota</div>
                        </div>
                        <div class="stepper-item" :class="{ 'active': step === 4 }" data-step="4">
                            <div class="step-counter">4</div>
                            <div class="step-name">Kirim</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Step Content -->
    <div id="wa-blast-app">
        <!-- Loading State -->
        <div v-if="loading" class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-sm">Memuat data...</p>
        </div>

        <!-- Step 1: Select Template -->
        <div v-show="step === 1 && !loading" class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Pilih Template Pesan</h6>
                        <button class="btn btn-sm btn-outline-primary" @click="syncTemplates" :disabled="syncing">
                            <i class="fas fa-sync-alt" :class="{ 'fa-spin': syncing }"></i>
                            Sync Template
                        </button>
                    </div>
                    <div class="card-body">
                        <div v-if="templates.length === 0" class="text-center py-4">
                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Belum ada template yang disetujui.</p>
                            <p class="text-sm text-muted">Klik "Sync Template" untuk mengambil template dari Gupshup.</p>
                        </div>
                        
                        <div v-else class="template-list">
                            <div v-for="template in templates" :key="template.id" 
                                 class="template-item p-3 border rounded mb-2 cursor-pointer"
                                 :class="{ 'border-primary bg-light': selectedTemplate?.id === template.id }"
                                 @click="selectTemplate(template)">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h6 class="mb-1">@{{ template.name }}</h6>
                                        <span class="badge bg-success me-2">@{{ template.category }}</span>
                                        <span class="badge bg-secondary">@{{ template.language }}</span>
                                    </div>
                                    <div v-if="selectedTemplate?.id === template.id">
                                        <i class="fas fa-check-circle text-success fa-lg"></i>
                                    </div>
                                </div>
                                <p class="text-sm text-muted mt-2 mb-0">@{{ template.sample_text || 'Tidak ada preview' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Template Preview -->
            <div class="col-md-4">
                <div class="card position-sticky" style="top: 100px;">
                    <div class="card-header">
                        <h6 class="mb-0">Preview Template</h6>
                    </div>
                    <div class="card-body">
                        <div v-if="!selectedTemplate" class="text-center text-muted py-4">
                            <i class="fas fa-eye fa-2x mb-2"></i>
                            <p class="mb-0">Pilih template untuk melihat preview</p>
                        </div>
                        <div v-else class="wa-preview">
                            <div class="wa-bubble bg-light p-3 rounded">
                                <p class="mb-0" style="white-space: pre-wrap;">@{{ getTemplateBody(selectedTemplate) }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Select Audience -->
        <div v-show="step === 2 && !loading" class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Pilih Audience</h6>
                    </div>
                    <div class="card-body">
                        <!-- Tag Filters -->
                        <div class="mb-4">
                            <label class="form-label">Filter berdasarkan Tag</label>
                            <div class="d-flex flex-wrap gap-2">
                                <span v-for="tag in availableTags" :key="tag" 
                                      class="badge cursor-pointer"
                                      :class="selectedTags.includes(tag) ? 'bg-primary' : 'bg-secondary'"
                                      @click="toggleTag(tag)">
                                    @{{ tag }}
                                </span>
                            </div>
                            <small class="text-muted">Klik tag untuk memfilter audience</small>
                        </div>

                        <hr>

                        <!-- Audience Summary -->
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card bg-gradient-success text-white">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="mb-0 text-sm">Kontak Valid</p>
                                                <h3 class="mb-0">@{{ audience.valid || 0 }}</h3>
                                            </div>
                                            <div class="icon icon-shape bg-white rounded-circle shadow text-success">
                                                <i class="fas fa-users"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-gradient-warning text-white">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="mb-0 text-sm">Non Opt-In</p>
                                                <h3 class="mb-0">@{{ audience.invalid || 0 }}</h3>
                                            </div>
                                            <div class="icon icon-shape bg-white rounded-circle shadow text-warning">
                                                <i class="fas fa-user-slash"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-gradient-info text-white">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <p class="mb-0 text-sm">Estimasi Biaya</p>
                                                <h3 class="mb-0">@{{ formatCurrency((audience.valid || 0) * 350) }}</h3>
                                            </div>
                                            <div class="icon icon-shape bg-white rounded-circle shadow text-info">
                                                <i class="fas fa-money-bill-wave"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact List Preview -->
                        <div class="mt-4" v-if="audience.contacts?.length > 0">
                            <h6>Preview Penerima (10 pertama)</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Nama</th>
                                            <th>Nomor HP</th>
                                            <th>Tags</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="contact in audience.contacts.slice(0, 10)" :key="contact.id">
                                            <td>@{{ contact.name || '-' }}</td>
                                            <td>@{{ contact.phone_number }}</td>
                                            <td>
                                                <span v-for="tag in contact.tags" :key="tag" class="badge bg-secondary me-1">@{{ tag }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quota Info -->
            <div class="col-md-4">
                <div class="card position-sticky" style="top: 100px;">
                    <div class="card-header">
                        <h6 class="mb-0">Info Kuota</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between">
                                <span>Kuota Harian</span>
                                <span class="text-sm">@{{ quota.daily?.used || 0 }} / @{{ quota.daily?.limit || 0 }}</span>
                            </label>
                            <div class="progress">
                                <div class="progress-bar" 
                                     :class="quota.daily?.percentage > 80 ? 'bg-danger' : 'bg-success'"
                                     :style="{ width: (quota.daily?.percentage || 0) + '%' }"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label d-flex justify-content-between">
                                <span>Kuota Bulanan</span>
                                <span class="text-sm">@{{ quota.monthly?.used || 0 }} / @{{ quota.monthly?.limit || 0 }}</span>
                            </label>
                            <div class="progress">
                                <div class="progress-bar"
                                     :class="quota.monthly?.percentage > 80 ? 'bg-danger' : 'bg-success'"
                                     :style="{ width: (quota.monthly?.percentage || 0) + '%' }"></div>
                            </div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between text-sm">
                            <span>Tersedia:</span>
                            <strong>@{{ quota.available || 0 }} pesan</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Review & Quota Check -->
        <div v-show="step === 3 && !loading" class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">Review Campaign</h6>
                    </div>
                    <div class="card-body">
                        <!-- Campaign Name -->
                        <div class="mb-4">
                            <label class="form-label">Nama Campaign *</label>
                            <input type="text" class="form-control" v-model="campaignName" 
                                   placeholder="Contoh: Promo Lebaran 2026">
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Deskripsi (opsional)</label>
                            <textarea class="form-control" v-model="campaignDescription" rows="2"
                                      placeholder="Deskripsi singkat tentang campaign ini"></textarea>
                        </div>

                        <hr>

                        <!-- Summary -->
                        <h6>Ringkasan</h6>
                        <table class="table table-borderless">
                            <tr>
                                <td class="text-muted">Template:</td>
                                <td><strong>@{{ selectedTemplate?.name }}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Total Penerima:</td>
                                <td><strong>@{{ audience.valid || 0 }} kontak</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Estimasi Biaya:</td>
                                <td><strong>@{{ formatCurrency((audience.valid || 0) * 350) }}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">Kuota Tersedia:</td>
                                <td>
                                    <strong :class="quotaSufficient ? 'text-success' : 'text-danger'">
                                        @{{ quota.available || 0 }} pesan
                                    </strong>
                                </td>
                            </tr>
                        </table>

                        <!-- Quota Warning -->
                        <div v-if="!quotaSufficient" class="alert alert-warning border-0">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Kuota Tidak Mencukupi</strong>
                            <p class="mb-2 mt-1">Anda membutuhkan @{{ audience.valid }} pesan, tetapi tersisa @{{ quota.available }} pesan.</p>
                            <a href="/billing/plan" class="btn btn-sm btn-warning">
                                <i class="fas fa-arrow-up me-1"></i>Upgrade Paket
                            </a>
                        </div>

                        <!-- Quota Low Warning (<=30% remaining) -->
                        <div v-else-if="quotaLowWarning" class="alert alert-info border-0">
                            <i class="fas fa-lightbulb me-2"></i>
                            <strong>Tips</strong>
                            <p class="mb-0 mt-1">Setelah campaign ini, sisa kuota Anda akan menjadi <strong>@{{ quota.available - audience.valid }}</strong> pesan. 
                            <a href="/billing/plan" class="text-decoration-underline">Upgrade paket</a> untuk kuota lebih banyak.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card bg-gradient-dark text-white position-sticky" style="top: 100px;">
                    <div class="card-body">
                        <h6 class="text-white">Perhatian!</h6>
                        <ul class="text-sm mb-0">
                            <li>Pastikan template sudah benar</li>
                            <li>Hanya kontak opt-in yang akan menerima pesan</li>
                            <li>Kuota akan dipotong saat pengiriman</li>
                            <li>Campaign yang sudah dikirim tidak dapat dibatalkan</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 4: Send & Progress -->
        <div v-show="step === 4 && !loading" class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">Progress Pengiriman</h6>
                        <span class="badge" :class="getStatusBadgeClass(campaign?.status)">
                            @{{ campaign?.status_label || campaign?.status }}
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Progress Bar -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Progress</span>
                                <span><strong>@{{ progress.percentage || 0 }}%</strong></span>
                            </div>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-success" :style="{ width: progress.percentage + '%' }">
                                    @{{ progress.sent || 0 }} terkirim
                                </div>
                                <div class="progress-bar bg-danger" :style="{ width: getFailedPercentage() + '%' }">
                                    @{{ progress.failed || 0 }} gagal
                                </div>
                            </div>
                        </div>

                        <!-- Stats Cards -->
                        <div class="row">
                            <div class="col-md-3">
                                <div class="card border">
                                    <div class="card-body p-3 text-center">
                                        <h4 class="mb-0 text-primary">@{{ progress.total || 0 }}</h4>
                                        <small class="text-muted">Total</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border">
                                    <div class="card-body p-3 text-center">
                                        <h4 class="mb-0 text-success">@{{ progress.sent || 0 }}</h4>
                                        <small class="text-muted">Terkirim</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border">
                                    <div class="card-body p-3 text-center">
                                        <h4 class="mb-0 text-info">@{{ progress.delivered || 0 }}</h4>
                                        <small class="text-muted">Sampai</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card border">
                                    <div class="card-body p-3 text-center">
                                        <h4 class="mb-0 text-danger">@{{ progress.failed || 0 }}</h4>
                                        <small class="text-muted">Gagal</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Fail Reason -->
                        <div v-if="campaign?.fail_reason" class="alert alert-danger mt-4">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>Campaign dihentikan:</strong> @{{ formatFailReason(campaign.fail_reason) }}
                        </div>

                        <!-- Action Buttons -->
                        <div class="mt-4 d-flex gap-2">
                            <button v-if="campaign?.status === 'sending'" 
                                    class="btn btn-warning" 
                                    @click="pauseCampaign">
                                <i class="fas fa-pause me-2"></i>Jeda
                            </button>
                            <button v-if="campaign?.status === 'paused'" 
                                    class="btn btn-success" 
                                    @click="resumeCampaign">
                                <i class="fas fa-play me-2"></i>Lanjutkan
                            </button>
                            <button v-if="['ready', 'draft', 'paused'].includes(campaign?.status)" 
                                    class="btn btn-danger" 
                                    @click="cancelCampaign">
                                <i class="fas fa-times me-2"></i>Batalkan
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery Stats -->
            <div class="col-md-4">
                <div class="card position-sticky" style="top: 100px;">
                    <div class="card-header">
                        <h6 class="mb-0">Statistik Delivery</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Delivery Rate</span>
                                <strong>@{{ progress.delivery_rate || 0 }}%</strong>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-info" :style="{ width: (progress.delivery_rate || 0) + '%' }"></div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Read Rate</span>
                                <strong>@{{ progress.read_rate || 0 }}%</strong>
                            </div>
                            <div class="progress mt-1">
                                <div class="progress-bar bg-success" :style="{ width: (progress.read_rate || 0) + '%' }"></div>
                            </div>
                        </div>
                        <hr>
                        <div class="text-sm text-muted">
                            <p class="mb-1"><i class="fas fa-clock me-2"></i>Mulai: @{{ formatDate(campaign?.started_at) }}</p>
                            <p class="mb-0"><i class="fas fa-check-circle me-2"></i>Selesai: @{{ formatDate(campaign?.completed_at) || '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Buttons -->
        <div v-if="!loading" class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body py-3 d-flex justify-content-between">
                        <button class="btn btn-secondary" @click="prevStep" :disabled="step === 1 || step === 4">
                            <i class="fas fa-arrow-left me-2"></i>Kembali
                        </button>
                        
                        <button v-if="step < 3" class="btn btn-primary" @click="nextStep" :disabled="!canProceed">
                            Lanjut<i class="fas fa-arrow-right ms-2"></i>
                        </button>
                        
                        <button v-if="step === 3" class="btn btn-success" @click="createAndSend" :disabled="!canSend || sending">
                            <span v-if="sending">
                                <i class="fas fa-spinner fa-spin me-2"></i>Memproses...
                            </span>
                            <span v-else>
                                <i class="fas fa-paper-plane me-2"></i>Kirim Campaign
                            </span>
                        </button>

                        <a v-if="step === 4 && isCompleted" href="{{ route('wa-blast.index') }}" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Buat Campaign Baru
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
.stepper-wrapper {
    margin-top: auto;
}
.stepper-item {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}
.stepper-item::before {
    position: absolute;
    content: "";
    border-bottom: 2px solid #ccc;
    width: 100%;
    top: 15px;
    left: -50%;
    z-index: 2;
}
.stepper-item::after {
    position: absolute;
    content: "";
    border-bottom: 2px solid #ccc;
    width: 100%;
    top: 15px;
    left: 50%;
    z-index: 2;
}
.stepper-item .step-counter {
    position: relative;
    z-index: 5;
    display: flex;
    justify-content: center;
    align-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #ccc;
    margin-bottom: 6px;
    color: white;
    font-weight: bold;
}
.stepper-item.active .step-counter {
    background-color: #5e72e4;
}
.stepper-item.completed .step-counter {
    background-color: #2dce89;
}
.stepper-item.active::before,
.stepper-item.completed::before {
    border-bottom: 2px solid #5e72e4;
}
.stepper-item.completed::after {
    border-bottom: 2px solid #2dce89;
}
.stepper-item:first-child::before,
.stepper-item:last-child::after {
    content: none;
}
.step-name {
    color: #666;
    font-size: 12px;
}
.stepper-item.active .step-name {
    color: #5e72e4;
    font-weight: 600;
}
.template-item {
    transition: all 0.2s;
}
.template-item:hover {
    background-color: #f8f9fa;
}
.cursor-pointer {
    cursor: pointer;
}
.wa-bubble {
    background: #dcf8c6 !important;
    border-radius: 7.5px 7.5px 7.5px 0;
    max-width: 100%;
}
</style>
@endpush

@push('scripts')
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script>
const { createApp, ref, computed, onMounted, watch } = Vue;

createApp({
    setup() {
        // State
        const step = ref(1);
        const loading = ref(false);
        const sending = ref(false);
        const syncing = ref(false);
        
        // Data
        const templates = ref([]);
        const selectedTemplate = ref(null);
        const availableTags = ref([]);
        const selectedTags = ref([]);
        const audience = ref({ valid: 0, invalid: 0, contacts: [] });
        const quota = ref({});
        const campaign = ref(null);
        const progress = ref({});
        
        // Form
        const campaignName = ref('');
        const campaignDescription = ref('');
        
        // Polling
        let progressInterval = null;

        // Computed
        const quotaSufficient = computed(() => {
            return (quota.value.available || 0) >= (audience.value.valid || 0);
        });

        // Check if quota is low (<=30% remaining after this campaign)
        const quotaLowWarning = computed(() => {
            const available = quota.value.available || 0;
            const limit = quota.value.limit || available;
            const needed = audience.value.valid || 0;
            
            if (limit === 0 || limit === 'Unlimited') return false;
            
            const afterSend = available - needed;
            const percentRemaining = (afterSend / limit) * 100;
            
            // Show warning if <=30% will remain
            return afterSend > 0 && percentRemaining <= 30;
        });

        const canProceed = computed(() => {
            if (step.value === 1) return selectedTemplate.value !== null;
            if (step.value === 2) return audience.value.valid > 0;
            return true;
        });

        const canSend = computed(() => {
            return campaignName.value.trim() !== '' && 
                   quotaSufficient.value && 
                   audience.value.valid > 0;
        });

        const isCompleted = computed(() => {
            return ['completed', 'failed', 'cancelled'].includes(campaign.value?.status);
        });

        // Methods
        const fetchTemplates = async () => {
            try {
                const response = await fetch('{{ route('wa-blast.templates') }}');
                const data = await response.json();
                templates.value = data.templates || [];
            } catch (error) {
                console.error('Failed to fetch templates:', error);
            }
        };

        const syncTemplates = async () => {
            syncing.value = true;
            try {
                const response = await fetch('{{ route('wa-blast.templates.sync') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const data = await response.json();
                if (data.success) {
                    await fetchTemplates();
                    ClientPopup.actionSuccess(`${data.synced} template berhasil disinkronkan`);
                } else {
                    ClientPopup.actionFailed('Sinkronisasi template belum berhasil. Coba beberapa saat lagi.');
                }
            } catch (error) {
                ClientPopup.connectionError();
            } finally {
                syncing.value = false;
            }
        };

        const selectTemplate = (template) => {
            selectedTemplate.value = template;
        };

        const getTemplateBody = (template) => {
            if (!template?.components) return template?.sample_text || 'Tidak ada preview';
            const body = template.components.find(c => c.type === 'BODY');
            return body?.text || template.sample_text || 'Tidak ada preview';
        };

        const fetchTags = async () => {
            try {
                const response = await fetch('{{ route('wa-blast.audience.tags') }}');
                const data = await response.json();
                availableTags.value = data.tags || [];
            } catch (error) {
                console.error('Failed to fetch tags:', error);
            }
        };

        const toggleTag = (tag) => {
            const index = selectedTags.value.indexOf(tag);
            if (index > -1) {
                selectedTags.value.splice(index, 1);
            } else {
                selectedTags.value.push(tag);
            }
            fetchAudience();
        };

        const fetchAudience = async () => {
            try {
                let url = '{{ route('wa-blast.audience') }}';
                if (selectedTags.value.length > 0) {
                    url += '?tags=' + selectedTags.value.join(',');
                }
                const response = await fetch(url);
                const data = await response.json();
                audience.value = data;
            } catch (error) {
                console.error('Failed to fetch audience:', error);
            }
        };

        const fetchQuota = async () => {
            try {
                const response = await fetch('{{ route('wa-blast.quota') }}');
                const data = await response.json();
                quota.value = data.quota || {};
            } catch (error) {
                console.error('Failed to fetch quota:', error);
            }
        };

        const nextStep = () => {
            if (step.value === 1 && selectedTemplate.value) {
                step.value = 2;
                fetchTags();
                fetchAudience();
                fetchQuota();
            } else if (step.value === 2) {
                step.value = 3;
            }
        };

        const prevStep = () => {
            if (step.value > 1 && step.value < 4) {
                step.value--;
            }
        };

        const createAndSend = async () => {
            if (!canSend.value) return;
            
            // === LIMIT CHECK: Pre-check quota before creating campaign ===
            const recipientCount = audience.value.valid || 0;
            const limitCheck = await LimitMonitor.preCheckBatch(recipientCount);
            if (!limitCheck.canProceed) {
                return; // LimitMonitor already showed popup
            }
            
            sending.value = true;
            try {
                // Create campaign
                const createResponse = await fetch('{{ route('wa-blast.campaign.create') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        template_id: selectedTemplate.value.id,
                        name: campaignName.value,
                        description: campaignDescription.value,
                        audience_filter: { tags: selectedTags.value }
                    })
                });
                
                const createData = await createResponse.json();
                if (!createData.success) {
                    ClientPopup.actionFailed('Kampanye belum bisa dibuat. Pastikan semua data sudah lengkap dan coba lagi.');
                    return;
                }
                
                campaign.value = createData.campaign;

                // Preview
                const previewResponse = await fetch(`/wa-blast/campaign/${campaign.value.id}/preview`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                // Confirm
                const confirmResponse = await fetch(`/wa-blast/campaign/${campaign.value.id}/confirm`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const confirmData = await confirmResponse.json();
                if (!confirmData.success) {
                    ClientPopup.actionFailed('Kampanye belum bisa dikonfirmasi. Silakan periksa data penerima.');
                    return;
                }

                // Send
                const sendResponse = await fetch(`/wa-blast/campaign/${campaign.value.id}/send`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });

                const sendData = await sendResponse.json();
                if (sendData.success) {
                    campaign.value = sendData.campaign;
                    step.value = 4;
                    startProgressPolling();
                } else {
                    ClientPopup.waNotConnected();
                }
            } catch (error) {
                ClientPopup.connectionError();
            } finally {
                sending.value = false;
            }
        };

        const startProgressPolling = () => {
            if (progressInterval) clearInterval(progressInterval);
            fetchProgress();
            progressInterval = setInterval(fetchProgress, 3000);
        };

        const fetchProgress = async () => {
            if (!campaign.value?.id) return;
            
            try {
                const response = await fetch(`/wa-blast/campaign/${campaign.value.id}/progress`);
                const data = await response.json();
                progress.value = data.progress || {};
                campaign.value.status = data.progress?.status;
                campaign.value.fail_reason = data.progress?.fail_reason;

                // Stop polling if completed
                if (['completed', 'failed', 'cancelled'].includes(data.progress?.status)) {
                    if (progressInterval) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                    }
                }
            } catch (error) {
                console.error('Failed to fetch progress:', error);
            }
        };

        const pauseCampaign = async () => {
            try {
                const response = await fetch(`/wa-blast/campaign/${campaign.value.id}/pause`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const data = await response.json();
                if (data.success) {
                    campaign.value = data.campaign;
                    ClientPopup.actionSuccess('Kampanye dijeda sementara');
                }
            } catch (error) {
                ClientPopup.actionFailed('Kampanye belum bisa dijeda. Coba lagi dalam beberapa saat.');
            }
        };

        const resumeCampaign = async () => {
            try {
                const response = await fetch(`/wa-blast/campaign/${campaign.value.id}/resume`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const data = await response.json();
                if (data.success) {
                    campaign.value = data.campaign;
                    startProgressPolling();
                    ClientPopup.actionSuccess('Kampanye dilanjutkan');
                } else {
                    ClientPopup.actionFailed('Kampanye belum bisa dilanjutkan. Pastikan koneksi WhatsApp aktif.');
                }
            } catch (error) {
                ClientPopup.connectionError();
            }
        };

        const cancelCampaign = async () => {
            const confirmed = await ClientPopup.confirm({
                title: 'Batalkan Kampanye?',
                text: 'Kampanye akan dihentikan. Pesan yang sudah terkirim tetap aman.',
                confirmText: 'Ya, Batalkan',
                cancelText: 'Tidak Jadi'
            });
            if (!confirmed) return;
            
            try {
                const response = await fetch(`/wa-blast/campaign/${campaign.value.id}/cancel`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    }
                });
                const data = await response.json();
                if (data.success) {
                    campaign.value = data.campaign;
                    if (progressInterval) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                    }
                    ClientPopup.actionSuccess('Kampanye berhasil dibatalkan');
                }
            } catch (error) {
                ClientPopup.actionFailed('Kampanye belum bisa dibatalkan. Coba lagi dalam beberapa saat.');
            }
        };

        // Helpers
        const formatCurrency = (value) => {
            return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
        };

        const formatDate = (date) => {
            if (!date) return null;
            return new Date(date).toLocaleString('id-ID');
        };

        const getStatusBadgeClass = (status) => {
            const classes = {
                'draft': 'bg-secondary',
                'ready': 'bg-info',
                'scheduled': 'bg-warning',
                'sending': 'bg-primary',
                'paused': 'bg-warning',
                'completed': 'bg-success',
                'failed': 'bg-danger',
                'cancelled': 'bg-dark'
            };
            return classes[status] || 'bg-secondary';
        };

        const getFailedPercentage = () => {
            if (!progress.value.total) return 0;
            return ((progress.value.failed || 0) / progress.value.total) * 100;
        };

        const formatFailReason = (reason) => {
            const reasons = {
                'quota_exceeded': 'Kuota habis',
                'owner_action': 'Dihentikan oleh admin',
                'connection_disconnected': 'Koneksi WhatsApp terputus',
                'user_inactive': 'Akun tidak aktif'
            };
            if (reason?.includes(':')) {
                const [key, detail] = reason.split(':');
                return (reasons[key] || key) + ' - ' + detail;
            }
            return reasons[reason] || reason;
        };

        // Lifecycle
        onMounted(() => {
            loading.value = true;
            fetchTemplates().finally(() => {
                loading.value = false;
            });
        });

        return {
            // State
            step, loading, sending, syncing,
            // Data
            templates, selectedTemplate, availableTags, selectedTags,
            audience, quota, campaign, progress,
            // Form
            campaignName, campaignDescription,
            // Computed
            quotaSufficient, quotaLowWarning, canProceed, canSend, isCompleted,
            // Methods
            fetchTemplates, syncTemplates, selectTemplate, getTemplateBody,
            fetchTags, toggleTag, fetchAudience, fetchQuota,
            nextStep, prevStep, createAndSend,
            pauseCampaign, resumeCampaign, cancelCampaign,
            // Helpers
            formatCurrency, formatDate, getStatusBadgeClass,
            getFailedPercentage, formatFailReason
        };
    }
}).mount('#wa-blast-app');
</script>
@endpush

@section('auth')
    @include('layouts.navbars.auth.sidenav')
    <main class="main-content position-relative max-height-vh-100 h-100 mt-1 border-radius-lg">
        @include('components.subscription-banner')
        @yield('content')
    </main>
@endsection
