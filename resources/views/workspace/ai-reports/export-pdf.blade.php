<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; color: #1c203d; }
        h1 { font-size: 22px; margin-bottom: 16px; }
        h2 { color: #17324a; font-size: 16px; margin: 18px 0 6px; }
        h3 { color: #334155; font-size: 13px; margin: 16px 0 8px; text-transform: uppercase; }
        h4 { color: #1c203d; font-size: 12px; margin: 0; }
        pre { white-space: pre-wrap; font-family: DejaVu Sans, sans-serif; }
        .content { margin-bottom: 18px; }
        .preview-heading { border: 1px solid #d8ecf8; background: #f4fbff; padding: 10px 12px; margin-bottom: 12px; }
        .preview-heading p { margin: 2px 0; color: #475569; }
        .preview-cards { width: 100%; margin: 10px 0 14px; }
        .preview-card { display: inline-block; width: 23%; min-height: 46px; margin-right: 1%; border: 1px solid #d8ecf8; background: #f8fafc; padding: 8px; vertical-align: top; }
        .preview-card p { margin: 0; }
        .preview-card p:first-child { color: #64748b; font-size: 9px; text-transform: uppercase; font-weight: bold; }
        .preview-card p:last-child { color: #1c203d; font-size: 15px; font-weight: bold; margin-top: 4px; }
        .preview-block { margin-top: 16px; page-break-inside: auto; }
        .preview-table-card { margin: 0 0 12px; page-break-inside: avoid; }
        .preview-table-title { background: #eef7fb; border: 1px solid #d8ecf8; border-bottom: 0; padding: 7px 8px; }
        .preview-table { width: 100%; border-collapse: collapse; font-size: 9px; table-layout: fixed; }
        .preview-table th { background: #0ea5d7; color: #fff; border: 1px solid #7dd3fc; padding: 5px; text-align: center; }
        .preview-table td { border: 1px solid #cbd5e1; padding: 5px; vertical-align: middle; word-wrap: break-word; }
        .preview-table tfoot td { background: #f4fbff; color: #1c203d; font-weight: bold; }
        .preview-charts, .preview-gaps { width: 100%; }
        .preview-chart, .preview-gap { display: inline-block; width: 31%; margin-right: 1.5%; vertical-align: top; border: 1px solid #d8ecf8; padding: 8px; page-break-inside: avoid; }
        .preview-bar-row { margin-top: 7px; }
        .preview-bar-label { font-size: 9px; color: #334155; }
        .preview-bar-label span:last-child { float: right; font-weight: bold; color: #0f5b66; }
        .preview-bar-track { height: 7px; background: #e2e8f0; margin-top: 3px; }
        .preview-bar-fill { height: 7px; background: #0ea5d7; }
        .preview-gap-item { background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px; margin-top: 6px; }
        .preview-gap-item p { margin: 0; }
        .preview-gap-item p:first-child { font-weight: bold; color: #1c203d; }
    </style>
</head>
<body>
    <h1>{{ $report->title }}</h1>
    <div class="content">
        <pre>{{ $report->contentForExport() }}</pre>
    </div>

    @if (($wordPreview ?? null) !== null)
        @include('workspace.ai-reports.partials.pta-quarterly-preview', ['wordPreview' => $wordPreview, 'pdfMode' => true])
    @endif
</body>
</html>
