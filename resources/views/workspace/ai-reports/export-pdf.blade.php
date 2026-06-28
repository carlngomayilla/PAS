<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; color: #1c203d; }
        h1 { font-size: 22px; margin-bottom: 16px; }
        pre { white-space: pre-wrap; font-family: DejaVu Sans, sans-serif; }
    </style>
</head>
<body>
    <h1>{{ $report->title }}</h1>
    <pre>{{ $report->contentForExport() }}</pre>
</body>
</html>
