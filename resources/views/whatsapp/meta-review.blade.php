<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Meta Review | WhatsApp Messaging Demo</title>
    <style>
        :root {
            --bg: #f3f5f7;
            --surface: #ffffff;
            --border: #e6e8ec;
            --text: #1f2937;
            --muted: #6b7280;
            --accent: #111111;
            --ok: #16a34a;
            --warn: #d97706;
            --danger: #dc2626;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .wrap {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        .tabs {
            display: flex;
            gap: 28px;
            font-size: 14px;
            color: var(--muted);
        }
        .tabs strong {
            color: var(--text);
        }
        h1 {
            margin: 6px 0 0;
            font-size: 36px;
            line-height: 1.1;
        }
        .eyebrow {
            font-size: 12px;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: var(--muted);
            font-weight: 700;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.25fr .95fr;
            gap: 22px;
        }
        .stack {
            display: grid;
            gap: 22px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 22px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
        }
        .card h2, .card h3 {
            margin: 0 0 10px;
            font-size: 16px;
        }
        .muted { color: var(--muted); }
        .account-row {
            display: flex;
            justify-content: space-between;
            align-items: start;
            gap: 18px;
            margin-bottom: 18px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 10px 16px;
            background: #121212;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        .stat {
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 14px;
        }
        .stat label {
            display: block;
            color: var(--muted);
            font-size: 11px;
            letter-spacing: .16em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .stat strong {
            font-size: 28px;
        }
        .form-block {
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 16px;
            margin-top: 14px;
        }
        .form-block + .form-block { margin-top: 16px; }
        .field {
            display: grid;
            gap: 8px;
            margin-top: 14px;
        }
        label {
            font-size: 13px;
            color: #374151;
            font-weight: 600;
        }
        input, textarea, select {
            width: 100%;
            border: 1px solid #d6d9df;
            border-radius: 16px;
            padding: 14px 15px;
            font: inherit;
            background: #fff;
        }
        textarea { min-height: 118px; resize: vertical; }
        button {
            border: 0;
            border-radius: 999px;
            padding: 14px 22px;
            background: #111111;
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            margin-top: 14px;
        }
        button:disabled { opacity: .6; cursor: wait; }
        .config-list {
            display: grid;
            gap: 12px;
        }
        .config-item input {
            background: #fbfbfc;
        }
        .placeholder-card {
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 16px;
            margin-top: 16px;
        }
        .full-width-button {
            width: 100%;
            justify-content: center;
        }
        .plain-input {
            background: #fff;
        }
        .notice {
            margin-bottom: 16px;
            border-radius: 16px;
            padding: 14px 16px;
            font-size: 14px;
        }
        .notice.success { background: #ecfdf3; color: #166534; }
        .notice.error { background: #fef2f2; color: #991b1b; }
        .log-list {
            display: grid;
            gap: 10px;
        }
        .log-item {
            border: 1px dashed var(--border);
            border-radius: 16px;
            padding: 12px 14px;
        }
        .log-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge.ok { background: #ecfdf3; color: var(--ok); }
        .badge.warn { background: #fff7ed; color: var(--warn); }
        .badge.fail { background: #fef2f2; color: var(--danger); }
        .empty {
            border: 1px dashed var(--border);
            border-radius: 18px;
            padding: 16px;
            color: var(--muted);
            font-size: 14px;
        }
        .linkbar {
            margin-top: 18px;
            font-size: 13px;
            color: var(--muted);
        }
        .linkbar a { color: inherit; }
        @media (max-width: 980px) {
            .grid { grid-template-columns: 1fr; }
            .stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <div class="eyebrow">WhatsApp Admin</div>
                <h1>Konfigurasi Cloud API, webhook, dan review Meta</h1>
            </div>
            <div class="tabs">
                <span>Instagram</span>
                <strong>WhatsApp</strong>
                <span>Review IG</span>
                <span>Review WA</span>
            </div>
        </div>

        <div id="feedback"></div>

        <div class="grid">
            <div class="stack">
                <div class="card">
                    <div class="eyebrow">Akun Terkoneksi</div>
                    <div class="account-row">
                        <div>
                            <h2 style="font-size: 18px; margin-top: 6px;">{{ $connection?->business_name ?: ($connection?->display_name ?: 'Belum ada koneksi aktif') }}</h2>
                            <div class="muted">{{ $metaConfig['business_number'] ?: '-' }}</div>
                        </div>
                        <div class="pill">{{ $connection?->isConnected() ? 'Validasi Koneksi WhatsApp' : 'Belum Terhubung' }}</div>
                    </div>
                    <div class="stats">
                        <div class="stat">
                            <label>Token Status</label>
                            <strong>{{ $connection?->isConnected() ? 'VALID' : '-' }}</strong>
                        </div>
                        <div class="stat">
                            <label>WABA ID</label>
                            <div>{{ $metaConfig['waba_id'] ?: '-' }}</div>
                        </div>
                        <div class="stat">
                            <label>Phone Number ID</label>
                            <div>{{ $metaConfig['phone_number_id'] ?: '-' }}</div>
                        </div>
                        <div class="stat">
                            <label>Webhook Token</label>
                            <div>{{ $metaConfig['verify_token'] ?: '-' }}</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="eyebrow">Kirim Pesan Uji</div>
                    <h2 style="font-size: 18px; margin-top: 6px;">Template hello_world dan text message</h2>

                    <form class="form-block" id="templateForm" action="{{ route('whatsapp.test-message') }}" method="post">
                        @csrf
                        <h3>Kirim template default</h3>
                        <div class="muted">Gunakan ini untuk test cepat sesuai onboarding Cloud API.</div>
                        <div class="field">
                            <label>Nomor tujuan</label>
                            <input type="text" name="phone_number" value="{{ $defaultRecipient }}" placeholder="62812xxxxxxx">
                        </div>
                        <input type="hidden" name="template_id" value="{{ optional($templates->first())->id }}">
                        <button type="submit" {{ !$connection?->isConnected() || $templates->isEmpty() ? 'disabled' : '' }}>Kirim Template Default</button>
                    </form>

                    <div class="placeholder-card">
                        <h3>Kirim pesan text</h3>
                        <div class="muted">Gunakan setelah nomor sudah berada dalam percakapan yang diizinkan.</div>
                    </div>

                    <form class="form-block" id="textForm" action="{{ route('whatsapp.review-text-message') }}" method="post">
                        @csrf
                        <h3>Kirim pesan text</h3>
                        <div class="muted">Gunakan setelah nomor sudah berada dalam percakapan yang diizinkan.</div>
                        <div class="field">
                            <label>Nomor tujuan</label>
                            <input type="text" name="phone_number" value="{{ $defaultRecipient }}" placeholder="62812xxxxxxx">
                        </div>
                        <div class="field">
                            <label>Isi pesan</label>
                            <textarea name="message" placeholder="Halo, ini pesan uji dari dashboard internal WhatsApp Business.">Halo, ini pesan uji dari dashboard internal WhatsApp Business.</textarea>
                        </div>
                        <button type="submit" {{ !$connection?->isConnected() ? 'disabled' : '' }}>Kirim Pesan Text</button>
                    </form>
                </div>

                <div class="card">
                    <div class="eyebrow">Template Tersedia</div>
                    @if($templates->isEmpty())
                        <div class="empty">Belum ada template yang tampil dari API. Pada mode sandbox biasanya template <strong>hello_world</strong> tetap bisa dipakai untuk uji awal.</div>
                    @else
                        <div class="log-list">
                            @foreach($templates as $template)
                                <div class="log-item">
                                    <div class="log-head">
                                        <strong>{{ $template->name }}</strong>
                                        <span class="badge ok">{{ $template->status ?? 'approved' }}</span>
                                    </div>
                                    <div class="muted">{{ \Illuminate\Support\Str::limit($template->sample_text ?: 'Template approved for Meta review demo.', 120) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="stack">
                <div class="card">
                    <div class="eyebrow">Konfigurasi Akun</div>
                    <div class="config-list">
                        <div class="config-item field"><label>App ID</label><input class="plain-input" value="{{ $metaConfig['app_id'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>App Secret</label><textarea class="plain-input" readonly></textarea></div>
                        <div class="config-item field"><label>Access Token</label><textarea class="plain-input" readonly></textarea></div>
                        <div class="config-item field"><label>WhatsApp Business Account ID</label><input class="plain-input" value="{{ $metaConfig['waba_id'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Business Manager ID</label><input class="plain-input" value="{{ $metaConfig['business_manager_id'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Phone Number ID</label><input class="plain-input" value="{{ $metaConfig['phone_number_id'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Nomor bisnis</label><input class="plain-input" value="{{ $metaConfig['business_number'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Nomor default tujuan uji</label><input class="plain-input" value="{{ $defaultRecipient ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Webhook verify token</label><input class="plain-input" value="{{ $metaConfig['verify_token'] ?: '' }}" readonly></div>
                        <div class="config-item field"><label>Webhook callback URL</label><input class="plain-input" value="{{ $metaConfig['callback_url'] }}" readonly></div>
                    </div>
                    <button type="button" class="full-width-button" disabled>Simpan Konfigurasi</button>
                </div>

                <div class="card">
                    <div class="eyebrow">Message Logs</div>
                    <div class="muted" style="margin-bottom: 14px;">Outbound dan inbound message disimpan untuk bukti end-to-end.</div>
                    @if($recentLogs->isEmpty())
                        <div class="empty">Belum ada log pesan. Kirim template atau text message dari panel ini untuk memulai uji API.</div>
                    @else
                        <div class="log-list">
                            @foreach($recentLogs as $log)
                                <div class="log-item">
                                    <div class="log-head">
                                        <strong>{{ $log->phone_number }}</strong>
                                        <span class="badge {{ in_array($log->status, ['sent', 'delivered', 'read']) ? 'ok' : ($log->status === 'failed' ? 'fail' : 'warn') }}">{{ $log->status }}</span>
                                    </div>
                                    <div class="muted">{{ $log->content ?: ($log->template_id ? 'Template: ' . $log->template_id : 'No content') }}</div>
                                    <div class="muted" style="margin-top: 6px; font-size: 12px;">{{ optional($log->created_at)->format('d M Y H:i:s') }} • {{ $log->direction }} • {{ $log->message_id ?: 'pending-id' }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="card">
                    <div class="eyebrow">Webhook Events</div>
                    @if($recentWebhookEvents->isEmpty())
                        <div class="empty">Belum ada event webhook yang masuk dari WhatsApp.</div>
                    @else
                        <div class="log-list">
                            @foreach($recentWebhookEvents as $event)
                                <div class="log-item">
                                    <div class="log-head">
                                        <strong>{{ $event->event_type ?: 'webhook_event' }}</strong>
                                        <span class="badge {{ $event->result === 'processed' || $event->result === 'updated' ? 'ok' : 'warn' }}">{{ $event->result ?: 'received' }}</span>
                                    </div>
                                    <div class="muted">{{ $event->phone_number ?: 'No phone number' }} @if($event->new_status) • status {{ $event->old_status ?: '-' }} -> {{ $event->new_status }} @endif</div>
                                    <div class="muted" style="margin-top: 6px; font-size: 12px;">{{ optional($event->created_at)->format('d M Y H:i:s') }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="linkbar">Link review: <a href="{{ route('whatsapp.meta-review') }}">{{ route('whatsapp.meta-review') }}</a></div>
    </div>

    <script>
        async function submitReviewForm(form) {
            const feedback = document.getElementById('feedback');
            const button = form.querySelector('button[type="submit"]');
            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'Mengirim...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                });

                const payload = await response.json();
                feedback.innerHTML = '';

                const notice = document.createElement('div');
                notice.className = 'notice ' + (response.ok && payload.success ? 'success' : 'error');
                notice.textContent = payload.message || payload.error || 'Permintaan selesai.';
                feedback.appendChild(notice);

                if (response.ok && payload.success) {
                    setTimeout(() => window.location.reload(), 1200);
                }
            } catch (error) {
                feedback.innerHTML = '<div class="notice error">Gagal memproses permintaan review Meta.</div>';
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        }

        document.getElementById('templateForm').addEventListener('submit', function (event) {
            event.preventDefault();
            submitReviewForm(this);
        });

        document.getElementById('textForm').addEventListener('submit', function (event) {
            event.preventDefault();
            submitReviewForm(this);
        });
    </script>
</body>
</html>