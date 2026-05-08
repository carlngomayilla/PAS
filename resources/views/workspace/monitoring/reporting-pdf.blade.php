<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ ($exportTemplate ?? null)?->documentTitle() ?? 'Reporting ANBG' }}</title>
    <style>
        @page{size:A4 landscape;margin:36px 32px 56px} body{font-family:DejaVu Sans,sans-serif;color:#1F2937;font-size:10px;margin:0} h1,h2{margin:0 0 8px;color:#1F2937} .meta{margin-bottom:12px;color:#6B7280} table{width:100%;border-collapse:collapse;margin-bottom:14px;border:1px solid #BFDBFE} th,td{border:1px solid #BFDBFE;padding:5px 6px;vertical-align:top} th{background:#3996D3;color:#fff;text-align:left;font-weight:700} tbody tr:nth-child(even) td{background:#F8FBFF} .compact th,.compact td{font-size:8px;padding:4px}.section{margin-top:12px}.page-break-section{page-break-before:always}.report-logo{width:116px;height:auto;margin-bottom:18px}.pdf-header-logo{position:fixed;top:-24px;left:0;width:84px;height:auto}.cover{margin-bottom:16px}.cover-band{position:relative;margin:22px 0 24px;height:96px;overflow:hidden;background:#3996D3;border:1px solid rgba(28,32,61,.18)}.cover-band-image{width:100%;height:96px}.cover-band-title{position:absolute;top:0;left:0;right:0;padding:24px 28px;color:#FFFFFF;text-align:center;font-size:31px;line-height:1.12;font-weight:900;letter-spacing:.02em;text-shadow:0 2px 4px rgba(28,32,61,.42)}.cover-meta{text-align:center;font-size:13px;color:#1F2937;margin:7px 0}.toc{border:1px solid #BFDBFE;border-radius:14px;padding:14px 16px;background:#FFFFFF;page-break-after:always}.toc h2{padding:0;margin-bottom:10px;background:none;color:#1F2937}.toc-table{width:100%;border:none;margin:0}.toc-table td{border:none;padding:5px 0;font-size:11px}.toc-index{width:36px;color:#3996D3;font-weight:900}.section-kicker{display:inline-block;margin-bottom:6px;padding:4px 10px;border-radius:999px;background:#E8F3FB;color:#1C203D;font-size:10px;font-weight:800;letter-spacing:.04em}.section h2{padding:7px 10px;background:#1C203D;color:#FFFFFF}.cards-grid{width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:14px}.cards-grid td{border:none;padding:0;vertical-align:top}.metric-card{border:1px solid #BFDBFE;border-radius:14px;overflow:hidden;background:#FFFFFF}.metric-label{padding:7px 10px;font-size:10px;font-weight:700;background:#3996D3;color:#fff}.metric-value{padding:12px 10px;font-size:22px;font-weight:900;text-align:center;background:#E8F3FB;color:#1C203D}.signature-banner{margin:18px 0 22px;padding:12px;background:#4F83BD;color:#fff;text-align:center;font-size:20px;font-weight:900}.signature-box{width:48%;min-height:74px;margin-top:60px;border:2px solid #3996D3;padding:14px;font-weight:700}.pdf-footer{position:fixed;bottom:-32px;left:0;right:0;font-size:10px;color:#6B7280;text-align:right}.pdf-footer .page-num:after{content:counter(page)}.pdf-footer .page-total:after{content:counter(pages)}.nowrap{white-space:nowrap}
    </style>
</head>
<body>
    @php
        $exportTemplate = $exportTemplate ?? null;
        $templateLayout = $exportTemplate?->layout_config ?? [];
        $templateBlocks = $exportTemplate?->blocks_config ?? [];
        $templateTitle = $exportTemplate?->documentTitle() ?? 'Reporting ANBG';
        $templateSubtitle = $exportTemplate?->documentSubtitle();
        $templateHeader = trim((string) ($templateLayout['header_text'] ?? ''));
        $templateFooter = trim((string) ($templateLayout['footer_text'] ?? ''));
        $templateWatermark = trim((string) ($templateLayout['watermark_text'] ?? ''));
        $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
        $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $generatedAtValue = $generatedAt ?? now();
        $generatedAtLabel = $generatedAtValue instanceof \Carbon\CarbonInterface ? $generatedAtValue->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s');
        $generatedYear = $generatedAtValue instanceof \Carbon\CarbonInterface ? $generatedAtValue->format('Y') : now()->format('Y');
        $directionServiceReport = collect($details['direction_service_report'] ?? []);
        $logoPath = public_path('images/logo-wordmark.png');
        $logoDataUri = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
        $coverGradientPath = public_path('images/report-cover-blue-gradient.png');
        $coverGradientDataUri = is_file($coverGradientPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($coverGradientPath)) : null;
        $actionRows = $directionServiceReport->flatMap(function (array $direction): array { $directionLabel = trim((($direction['code'] ?? '') !== '' ? ($direction['code'].' - ') : '').($direction['libelle'] ?? 'Direction')); return collect($direction['services'] ?? [])->flatMap(function (array $service) use ($directionLabel): array { $serviceLabel = trim((($service['code'] ?? '') !== '' ? ($service['code'].' - ') : '').($service['libelle'] ?? 'Service')); return collect($service['actions'] ?? [])->map(fn (array $row): array => array_merge($row, ['direction_label' => $directionLabel, 'service_label' => $serviceLabel, 'service_responsable' => (string) ($service['responsable'] ?? '-')]))->all(); })->all(); })->values();
        $strategyRows = $actionRows->map(fn (array $row): array => ['axe_numero' => (string) (($row['axe_numero'] ?? '') !== '' ? $row['axe_numero'] : '-'), 'axe' => (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'), 'objectif_numero' => (string) (($row['objectif_strategique_numero'] ?? '') !== '' ? $row['objectif_strategique_numero'] : '-'), 'objectif' => (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'), 'echeance' => (string) ($row['echeance_strategique'] ?? '')])->unique(fn (array $row): string => implode('|', $row))->values();
        $summaryRows = $directionServiceReport->flatMap(function (array $direction): array { $directionLabel = trim((($direction['code'] ?? '') !== '' ? ($direction['code'].' - ') : '').($direction['libelle'] ?? 'Direction')); return collect($direction['services'] ?? [])->map(fn (array $service): array => ['direction' => $directionLabel, 'service' => trim((($service['code'] ?? '') !== '' ? ($service['code'].' - ') : '').($service['libelle'] ?? 'Service')), 'summary' => (array) ($service['summary'] ?? [])])->all(); })->values();
        $alertRows = collect($details['kpi_sous_seuil'] ?? [])->map(fn ($mesure): array => ['action' => (string) ($mesure->kpi?->action?->libelle ?? '-'), 'indicateur' => trim(str_ireplace('KPI', 'Indicateur', (string) ($mesure->kpi?->libelle ?? '-'))) ?: '-', 'valeur' => (float) ($mesure->valeur ?? 0), 'seuil' => (float) ($mesure->kpi?->seuil_alerte ?? 0), 'statut' => 'Alerte', 'correctif' => "Vérifier la mesure, documenter l'écart et proposer une action corrective."])->merge(collect($details['actions_retard'] ?? [])->map(fn ($action): array => ['action' => (string) ($action->libelle ?? '-'), 'indicateur' => 'Retard action', 'valeur' => (float) ($action->progression_reelle ?? 0), 'seuil' => 100, 'statut' => 'En retard', 'correctif' => 'Replanifier, lever les blocages et mettre à jour la progression.']))->values();
        $riskRows = $actionRows->filter(fn (array $row): bool => trim((string) ($row['risque_identifie'] ?? '')) !== '')->values();
        $rmoRows = $actionRows->groupBy(fn (array $row): string => implode('|', [(string) ($row['direction_label'] ?? '-'), (string) ($row['service_label'] ?? '-'), (string) ($row['rmo'] ?? $row['responsable'] ?? 'Non renseigne')]))->map(function ($rows, string $key): array { [$direction, $service, $rmo] = array_pad(explode('|', $key, 3), 3, 'Non renseigne'); return ['direction' => $direction ?: '-', 'service' => $service ?: '-', 'rmo' => $rmo ?: 'Non renseigne', 'total' => $rows->count(), 'performance' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_performance_value'] ?? 0)), 2)]; })->sortBy(fn (array $row): string => $row['direction'].'|'.$row['service'].'|'.sprintf('%09.2f', 10000 - (float) $row['performance']))->values();
        $justificatifRows = $actionRows->flatMap(function (array $row): array { $justificatifs = (array) ($row['justificatifs'] ?? []); if ($justificatifs === []) { return [['direction' => (string) ($row['direction_label'] ?? '-'), 'service' => (string) ($row['service_label'] ?? '-'), 'action' => (string) ($row['action'] ?? '-'), 'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'), 'justificatif' => '-', 'statut' => (string) ($row['statut_validation'] ?? '-'), 'date' => '']]; } return collect($justificatifs)->map(fn (array $justificatif): array => ['direction' => (string) ($row['direction_label'] ?? '-'), 'service' => (string) ($row['service_label'] ?? '-'), 'action' => (string) ($row['action'] ?? '-'), 'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'), 'justificatif' => (string) ($justificatif['nom'] ?? '-'), 'statut' => (string) ($row['statut_validation'] ?? '-'), 'date' => (string) ($justificatif['date'] ?? '')])->all(); })->values();
    @endphp

    <div class="pdf-footer">{{ $templateFooter !== '' ? $templateFooter : 'ANBG - Rapport de reporting' }} | Exercice {{ $generatedYear }} | Page <span class="page-num"></span> / <span class="page-total"></span></div>
    @if ($templateWatermark !== '')<div style="position:fixed;top:42%;left:18%;right:18%;text-align:center;font-size:72px;font-weight:900;color:rgba(28,32,61,.08);transform:rotate(-24deg);z-index:-1;">{{ $templateWatermark }}</div>@endif
    @if ($logoDataUri)<img class="pdf-header-logo" src="{{ $logoDataUri }}" alt="ANBG">@endif
    @if ($templateHeader !== '')<div class="meta" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1C203D;">{{ $templateHeader }}</div>@endif

    @if (($templateBlocks['include_cover'] ?? true))
        <div class="cover">
            @if ($logoDataUri)<img class="report-logo" src="{{ $logoDataUri }}" alt="ANBG">@endif
            <div class="cover-band">
                @if ($coverGradientDataUri)<img class="cover-band-image" src="{{ $coverGradientDataUri }}" alt="">@endif
                <div class="cover-band-title">RAPPORT DE REPORTING</div>
            </div>
            <p class="cover-meta"><strong>{{ $templateTitle }}</strong></p>
            @if ($templateSubtitle)<p class="cover-meta"><strong>{{ $templateSubtitle }}</strong></p>@endif
            <p class="cover-meta">Exercice : {{ $generatedYear }}</p>
            <p class="cover-meta">Périmètre : GLOBAL / Direction / Service selon droits utilisateur</p>
            <p class="cover-meta">Généré le {{ $generatedAtLabel }} | Rôle : {{ $scope['role'] ?? '-' }} | Direction: {{ $scope['direction_id'] ?? '-' }} | Service: {{ $scope['service_id'] ?? '-' }}</p>
            <p class="cover-meta" style="font-weight:700;color:#1C203D;">{{ $officialBaseText }}</p>
        </div>
    @endif

    @if (($templateBlocks['include_summary'] ?? true))
        <div class="toc"><h2>Sommaire</h2><table class="toc-table"><tr><td class="toc-index">01</td><td>Axes & Objectifs stratégiques</td></tr><tr><td class="toc-index">02</td><td>Objectifs opérationnels & Actions</td></tr><tr><td class="toc-index">03</td><td>Actions détaillées</td></tr><tr><td class="toc-index">04</td><td>Indicateur de performance par action</td></tr><tr><td class="toc-index">05</td><td>Reporting synthétique, alertes, risques, RMO et justificatifs</td></tr><tr><td class="toc-index">06</td><td>Page de signature et page de fin</td></tr></table></div>
    @endif

    @if (($templateBlocks['include_summary'] ?? true))
        <div class="section page-break-section"><span class="section-kicker">Synthèse</span><h2>Reporting synthétique</h2><table class="cards-grid"><tr><td><div class="metric-card"><div class="metric-label">Actions</div><div class="metric-value">{{ $global['actions_total'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">Terminées</div><div class="metric-value">{{ $global['actions_achevees'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">En retard</div><div class="metric-value">{{ $alertes['actions_en_retard'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">Alertes</div><div class="metric-value">{{ ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0) }}</div></div></td></tr></table><p class="meta">{{ $officialBaseText }}</p></div>
    @endif

    @include('workspace.monitoring.partials.reporting-direction-service-sections', [
        'variant' => 'pdf',
        'directionServiceReport' => $directionServiceReport,
        'strategyRows' => $strategyRows,
        'templateBlocks' => $templateBlocks,
        'alertRows' => $alertRows,
    ])

            <p class="cover-meta">Généré le {{ $generatedAtLabel }} | Rôle : {{ $scope['role'] ?? '-' }} | Direction: {{ $scope['direction_id'] ?? '-' }} | Service: {{ $scope['service_id'] ?? '-' }}</p>
    @if (($templateBlocks['include_signatures'] ?? false) === true)
        <div class="section page-break-section">
            <span class="section-kicker">Validation</span>
            <h2>Visa et signatures</h2>
            <div class="signature-banner">Visa et signatures</div>
            <div class="signature-box">
                <p>{{ $templateFooter !== '' ? $templateFooter : 'Document de diffusion interne' }}</p>
                <p style="margin-top:38px;"><strong>Nom / Visa / Date</strong></p>
            </div>
        </div>
    @endif
</body>
</html>
