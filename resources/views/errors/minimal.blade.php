<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $code ?? 'Erreur' }} — ANBG Pilotage</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; height: 100%; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .err-wrap {
            width: 100%;
            max-width: 520px;
            text-align: center;
        }
        .err-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 48px 32px 40px;
            box-shadow: 0 20px 50px -25px rgba(15, 23, 42, 0.18);
        }
        .err-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(57, 150, 211, 0.12);
            color: #3996d3;
            margin-bottom: 20px;
        }
        .err-badge svg { width: 36px; height: 36px; }
        .err-code {
            font-size: 64px;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: #3996d3;
            line-height: 1;
            margin: 0 0 10px;
        }
        .err-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 12px;
            color: #0f172a;
        }
        .err-message {
            font-size: 15px;
            line-height: 1.55;
            color: #475569;
            margin: 0 0 28px;
        }
        .err-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .err-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .err-btn-primary {
            background: #3996d3;
            color: #ffffff;
            box-shadow: 0 6px 16px -6px rgba(57, 150, 211, 0.55);
        }
        .err-btn-primary:hover { background: #2680c0; transform: translateY(-1px); }
        .err-btn-ghost {
            background: #ffffff;
            color: #334155;
            border-color: #cbd5e1;
        }
        .err-btn-ghost:hover { background: #f1f5f9; border-color: #94a3b8; }
        .err-footer {
            margin-top: 24px;
            font-size: 12px;
            color: #94a3b8;
        }
        .err-footer strong { color: #475569; font-weight: 600; }
        @media (prefers-reduced-motion: reduce) {
            .err-btn { transition: none; }
        }
    </style>
</head>
<body>
    <main class="err-wrap" role="main">
        <div class="err-card">
            <div class="err-badge" aria-hidden="true">
                {!! $icon ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' !!}
            </div>
            <p class="err-code">{{ $code ?? '—' }}</p>
            <h1 class="err-title">{{ $title ?? 'Une erreur est survenue' }}</h1>
            <p class="err-message">{{ $message ?? "Le service est momentanément indisponible. Merci de réessayer dans quelques instants." }}</p>
            <div class="err-actions">
                <a href="{{ url('/') }}" class="err-btn err-btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    Retour à l'accueil
                </a>
                <a href="javascript:history.back()" class="err-btn err-btn-ghost">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                    Page précédente
                </a>
            </div>
        </div>
        <p class="err-footer">
            <strong>ANBG Pilotage</strong> · Système institutionnel PAS / PAO / PTA
        </p>
    </main>
</body>
</html>
