{{-- Center Panel - Chat Window --}}
<div class="inbox-chat" id="chatWindow">
    {{-- Chat Header --}}
    <div class="chat-header" id="chatHeader">
        {{-- Default empty header --}}
        <div class="chat-header-empty">
            <span class="text-muted">Pilih percakapan dari daftar</span>
        </div>
    </div>
    
    {{-- Chat Messages --}}
    <div class="chat-messages" id="chatMessages">
        {{-- Empty State Tengah --}}
        <div class="chat-empty-center" id="chatEmptyState">
            <div class="empty-icon-large">
                <i class="ni ni-chat-round"></i>
            </div>
            <h5 class="empty-title-large">Belum ada percakapan dipilih</h5>
            <p class="empty-subtitle-large">Pesan WhatsApp pelanggan akan muncul di sini</p>
            <div class="empty-actions-center">
                <button class="btn-empty-lg btn-refresh-lg" onclick="TalkabizInbox.loadConversations()">
                    <i class="ni ni-refresh-02"></i>
                    <span>Refresh</span>
                </button>
                <a href="{{ route('campaign') }}" class="btn-empty-lg btn-primary-lg">
                    <i class="ni ni-send"></i>
                    <span>Buka Campaign</span>
                </a>
            </div>
        </div>
    </div>
    
    {{-- Chat Composer - Disabled by default --}}
    <div class="chat-composer" id="chatComposer">
        <div id="chatComposerArea">
            {{-- Disabled Composer --}}
            <div class="composer-disabled" id="composerDisabled">
                <div class="composer-wrapper disabled">
                    <div class="composer-input">
                        <textarea disabled placeholder="Ambil percakapan untuk mulai chat..." rows="1"></textarea>
                    </div>
                    <button class="composer-btn btn-send" disabled title="Ambil percakapan untuk mulai chat">
                        <i class="ni ni-send"></i>
                    </button>
                </div>
                <p class="composer-hint">
                    <i class="ni ni-bulb-61"></i>
                    Pilih percakapan dari daftar untuk memulai chat
                </p>
            </div>
        </div>
    </div>
</div>
