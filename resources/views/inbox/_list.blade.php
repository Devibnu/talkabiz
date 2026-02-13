{{-- Left Panel - Conversation List --}}
<div class="inbox-list">
    {{-- Search Header --}}
    <div class="inbox-list-header">
        <div class="inbox-search">
            <i class="ni ni-zoom-split-in"></i>
            <input type="text" id="searchInput" placeholder="Cari nama atau nomor...">
        </div>
    </div>
    
    {{-- Filter Pills --}}
    <div class="inbox-filters">
        <button class="inbox-filter-btn active" data-filter="all">Semua</button>
        <button class="inbox-filter-btn" data-filter="unassigned">Belum Diambil</button>
        <button class="inbox-filter-btn" data-filter="mine">Milik Saya</button>
        <button class="inbox-filter-btn" data-filter="done">Selesai</button>
    </div>
    
    {{-- Conversation List --}}
    <div class="inbox-conversations" id="conversationList">
        {{-- Empty State - akan diganti via JS jika ada data --}}
        <div class="inbox-empty-state" id="conversationEmptyState">
            <div class="empty-icon-wrapper">
                <i class="ni ni-chat-round"></i>
            </div>
            <h6 class="empty-title">Belum ada percakapan</h6>
            <p class="empty-subtitle">Pesan WhatsApp pelanggan akan muncul di sini</p>
            <div class="empty-actions">
                <button class="btn-empty-action btn-refresh" onclick="TalkabizInbox.loadConversations()">
                    <i class="ni ni-refresh-02"></i>
                    <span>Refresh</span>
                </button>
                <a href="{{ route('campaign') }}" class="btn-empty-action btn-primary-action">
                    <i class="ni ni-send"></i>
                    <span>Buka Campaign</span>
                </a>
            </div>
        </div>
        
        {{-- Loading State --}}
        <div class="inbox-loading-state" id="conversationLoading" style="display: none;">
            <div class="loading-spinner"></div>
            <p class="mt-3">Memuat percakapan...</p>
        </div>
    </div>
</div>
