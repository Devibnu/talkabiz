<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7fb;
            --surface: #ffffff;
            --text: #162033;
            --muted: #5b6475;
            --accent: #2d5baf;
            --border: #dbe1ea;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #eef3ff 0%, var(--bg) 100%);
            color: var(--text);
        }

        .wrap {
            max-width: 880px;
            margin: 0 auto;
            padding: 40px 20px 80px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 18px 50px rgba(21, 39, 74, 0.08);
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(28px, 4vw, 40px);
            line-height: 1.1;
        }

        .meta {
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 24px;
        }

        .content {
            color: var(--text);
            line-height: 1.7;
            font-size: 16px;
        }

        .content h2,
        .content h3 {
            margin-top: 28px;
            margin-bottom: 10px;
            font-size: 22px;
        }

        .content p,
        .content li {
            color: #304058;
        }

        .content a {
            color: var(--accent);
        }
    </style>
</head>
<body>
    <main class="wrap">
        <section class="card">
            <h1>{{ $title }}</h1>
            <div class="meta">
                @if($document)
                    Versi {{ $document->version }}
                    @if($document->published_at)
                        • Dipublikasikan {{ $document->published_at->format('d M Y H:i') }}
                    @endif
                @else
                    Dokumen publik Talkabiz
                @endif
            </div>

            <div class="content">
                @if($contentFormat === \App\Models\LegalDocument::FORMAT_HTML)
                    {!! $content !!}
                @else
                    {!! nl2br(e($content)) !!}
                @endif
            </div>
        </section>
    </main>
</body>
</html>