@php
    $template = is_array($template ?? null) ? $template : [];
    $accent = (string) ($template['accent'] ?? '#3996d3');
    $eyebrow = (string) ($template['eyebrow'] ?? 'Notification PAS');
    $headline = (string) (($template['headline'] ?? '') ?: ($title !== '' ? $title : 'Nouvelle notification'));
    $intro = (string) ($template['intro'] ?? 'Une mise a jour vous concerne dans le systeme de pilotage strategique.');
    $bodyMessage = (string) (($template['message'] ?? '') ?: ($notificationMessage ?? ''));
    $ctaLabel = (string) ($template['cta_label'] ?? 'Ouvrir l application');
    $badgeLabel = (string) ($template['badge_label'] ?? 'Notification');
    $tone = (string) ($template['tone'] ?? 'Information');
    $details = is_array($template['details'] ?? null) ? $template['details'] : [];
    $footerNote = (string) ($template['footer_note'] ?? '');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background: #eef4f9;
            font-family: Arial, Helvetica, sans-serif;
            color: #1c203d;
            line-height: 1.55;
        }
        .email-wrap {
            width: 100%;
            padding: 32px 14px;
        }
        .email-card {
            max-width: 620px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #d8ecf8;
            border-radius: 12px;
            overflow: hidden;
        }
        .topbar {
            height: 7px;
            background: {{ $accent }};
        }
        .email-header {
            padding: 24px 28px 18px;
            background: #ffffff;
            border-bottom: 1px solid #eef4f9;
        }
        .brand {
            margin: 0 0 14px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #667085;
        }
        .badge {
            display: inline-block;
            margin: 0 0 12px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #f4f9fc;
            color: {{ $accent }};
            font-size: 12px;
            font-weight: 700;
        }
        h1 {
            margin: 0;
            color: #1c203d;
            font-size: 24px;
            line-height: 1.25;
            font-weight: 800;
        }
        .tone {
            margin: 10px 0 0;
            color: #667085;
            font-size: 13px;
        }
        .email-body {
            padding: 24px 28px 10px;
        }
        .greeting {
            margin: 0 0 14px;
            font-size: 15px;
            color: #1c203d;
        }
        .intro {
            margin: 0 0 14px;
            font-size: 15px;
            color: #2c3257;
        }
        .message {
            margin: 0 0 20px;
            padding: 14px 16px;
            border-left: 4px solid {{ $accent }};
            background: #f8fbfd;
            color: #1c203d;
            font-size: 15px;
            white-space: pre-line;
        }
        .details {
            width: 100%;
            margin: 8px 0 22px;
            border-collapse: collapse;
            border: 1px solid #e4eef5;
            border-radius: 10px;
            overflow: hidden;
        }
        .details td {
            padding: 10px 12px;
            border-bottom: 1px solid #e4eef5;
            font-size: 13px;
        }
        .details tr:last-child td {
            border-bottom: 0;
        }
        .details .label {
            width: 36%;
            color: #667085;
            background: #f8fbfd;
            font-weight: 700;
        }
        .details .value {
            color: #1c203d;
            font-weight: 600;
        }
        .cta-row {
            margin: 18px 0 10px;
            text-align: center;
        }
        .cta-button {
            display: inline-block;
            padding: 12px 24px;
            background: {{ $accent }};
            color: #ffffff !important;
            font-weight: 800;
            font-size: 14px;
            text-decoration: none;
            border-radius: 8px;
        }
        .fallback-url {
            margin: 12px 0 0;
            color: #667085;
            font-size: 11px;
            word-break: break-all;
        }
        .email-footer {
            padding: 18px 28px 24px;
            font-size: 12px;
            color: #667085;
            border-top: 1px solid #eef4f9;
            text-align: center;
        }
        .email-footer strong {
            color: #1c203d;
        }
        .footer-note {
            margin: 0 0 10px;
            color: #2c3257;
        }
        .meta-line {
            margin-top: 7px;
            color: #94a3b8;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <div class="email-wrap">
        <div class="email-card">
            <div class="topbar"></div>
            <div class="email-header">
                <p class="brand">{{ $appName }} - e-Pilotage PAS</p>
                <span class="badge">{{ $badgeLabel }}</span>
                <h1>{{ $headline }}</h1>
                <p class="tone">{{ $tone }}</p>
            </div>

            <div class="email-body">
                <p class="greeting">
                    Bonjour{{ $recipientName !== '' ? ' '.$recipientName : '' }},
                </p>

                <p class="intro">{{ $intro }}</p>

                <div class="message">
                    {{ $bodyMessage !== '' ? $bodyMessage : 'Une mise a jour vous concerne dans le systeme de pilotage strategique.' }}
                </div>

                @if ($details !== [])
                    <table class="details" role="presentation" cellpadding="0" cellspacing="0">
                        @foreach ($details as $detail)
                            @if (is_array($detail) && ($detail['value'] ?? '') !== '')
                                <tr>
                                    <td class="label">{{ $detail['label'] ?? 'Detail' }}</td>
                                    <td class="value">{{ $detail['value'] }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                @endif

                @if ($ctaUrl !== '')
                    <div class="cta-row">
                        <a href="{{ $ctaUrl }}" class="cta-button">{{ $ctaLabel }}</a>
                        <p class="fallback-url">{{ $ctaUrl }}</p>
                    </div>
                @endif
            </div>

            <div class="email-footer">
                @if ($footerNote !== '')
                    <p class="footer-note">{{ $footerNote }}</p>
                @endif
                <strong>{{ $appName }}</strong> - Systeme institutionnel de pilotage PAS / PAO / PTA.<br>
                Ce message a ete envoye automatiquement. Merci de ne pas y repondre directement.
                <div class="meta-line">Reference evenement : {{ $event }}</div>
            </div>
        </div>
    </div>
</body>
</html>
