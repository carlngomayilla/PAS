<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size:A4 landscape; margin:8mm; }
        body { font-family:DejaVu Sans, sans-serif; color:#000; font-size:8px; margin:0; }
        .pta-suivi-page { background:#fff; }
        .pta-suivi-top { display:table; width:100%; border-collapse:collapse; margin-bottom:8px; }
        .pta-suivi-logo, .pta-suivi-title, .pta-suivi-legend { display:table-cell; vertical-align:middle; border:1px solid #999; }
        .pta-suivi-logo { width:17%; padding:5px; }
        .pta-suivi-logo img { width:90px; }
        .pta-suivi-title { width:42%; text-align:center; background:#bdd7ee; font-size:14px; font-weight:900; }
        .pta-suivi-legend { width:41%; padding:0; }
        .legend-table { width:100%; border-collapse:collapse; font-size:7px; }
        .legend-table td { border:1px solid #111; padding:2px 3px; font-weight:700; }
        .legend-title { background:#d0cece; font-size:11px; text-align:center; font-weight:900; }
        .pta-suivi-meta { margin:4px 0 8px; color:#ff6600; font-weight:700; }
        .pta-suivi-table { width:100%; border-collapse:collapse; table-layout:fixed; font-size:5.8px; }
        .pta-suivi-table th, .pta-suivi-table td { border:1px solid #111; padding:2px; vertical-align:middle; overflow-wrap:anywhere; }
        .pta-suivi-table th { background:#d9d9d9; text-align:center; font-weight:900; }
        .pta-pas-row td { background:#2f75b5; color:#fff; font-weight:900; text-align:center; }
        .pta-level-axis td { background:#0f2f57; color:#fff; font-weight:900; text-align:center; }
        .pta-level-strategic-objective td { background:#1e5fa8; color:#fff; font-weight:900; text-align:center; }
        .pta-level-operational-objective td { background:#d8ecff; color:#0f2f57; font-weight:900; text-align:center; }
        .pta-level-action td { background:#f8fafc; color:#111827; }
        .pta-level-sub-action td { background:#f1f5f9; color:#334155; }
        .pta-sub-action-row td { background:#f1f5f9; color:#334155; }
        .pta-sub-action-row .pta-action-index-cell, .pta-sub-action-row .pta-action-parent-cell { background:#f8fafc; color:#111827; }
        .pta-strategy-row td { background:#5b9bd5; color:#000; font-weight:900; text-align:center; }
        .pta-strategy-rate { background:#ddebf7 !important; }
        .pta-objective-row td { background:#ddebf7; font-weight:900; text-align:center; }
        .pta-objective-number { width:20px; }
        .pta-center, .pta-status-cell { text-align:center; }
        .pta-status-cell { font-weight:900; }
        .pta-status-badge { display:inline-block; border-radius:3px; padding:2px 3px; font-size:5.8px; font-weight:900; line-height:1.1; }
        .pta-progress-track { height:4px; background:#e5e7eb; }
        .pta-progress-fill { display:block; height:4px; background:#3996d3; }
        .pta-progress-label { display:block; font-size:5.6px; font-weight:900; }
        .pta-proof-button { display:inline-block; border:1px solid #1e5fa8; border-radius:3px; background:#eef6fc; color:#0f2f57; padding:2px 3px; font-size:5.8px; font-weight:900; }
        .pta-proof-button-empty { border-color:#cbd5e1; background:#f1f5f9; color:#64748b; }
        .pta-action-cell { font-weight:700; }
        .pta-sub-action-cell { font-weight:800; color:#334155; }
        .pta-sub-action-number { font-weight:900; color:#0f2f57; }
        .pta-empty { text-align:center; color:#666; }
    </style>
</head>
<body>
    @php
        $logoPath = public_path('images/logo-wordmark.png');
        $logoData = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
    @endphp
    <section class="pta-suivi-page">
        <div class="pta-suivi-top">
            <div class="pta-suivi-logo">
                @if ($logoData)<img src="{{ $logoData }}" alt="ANBG">@endif
            </div>
            <div class="pta-suivi-title">{{ $title }}</div>
            <div class="pta-suivi-legend">
                <table class="legend-table">
                    <tr><td class="legend-title" colspan="2">Legende</td></tr>
                    @foreach ($legends as $legendTitle => $items)
                        <tr><td colspan="2"><strong>{{ $legendTitle }}</strong></td></tr>
                        @foreach ($items as $item)
                            <tr>
                                <td style="width:44px;background:{{ $item['color'] }};"></td>
                                <td style="color:{{ $item['text'] ?? '#111827' }};">{{ $item['label'] }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </table>
            </div>
        </div>
        <div class="pta-suivi-meta">
            {{ $scopeLabel }} | Total actions : {{ $summary['actions'] ?? 0 }} | Performance consolidee : {{ number_format((float) ($summary['performance'] ?? 0), 2) }}% | A parametrer : {{ $summary['a_parametrer'] ?? 0 }}
        </div>
        @include('workspace.pta-suivi.partials.table', ['groups' => $groups, 'exportMode' => 'pdf'])
    </section>
</body>
</html>
