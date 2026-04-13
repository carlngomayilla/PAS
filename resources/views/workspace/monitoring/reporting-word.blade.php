@php
    $exportTemplate = $exportTemplate ?? null;
    $templateBlocks = $exportTemplate?->blocks_config ?? [];
    $templateLayout = $exportTemplate?->layout_config ?? [];
    $title = $exportTemplate?->documentTitle() ?? 'Reporting ANBG';
    $subtitle = $exportTemplate?->documentSubtitle();
    $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
    $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $global = $global ?? [];
    $alertes = $alertes ?? [];
    $details = $details ?? [];
    $directionServiceReport = collect($details['direction_service_report'] ?? []);
    $generatedAtValue = $generatedAt ?? now();
    $generatedAtLabel = $generatedAtValue instanceof \Carbon\CarbonInterface ? $generatedAtValue->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
    $generatedYear = $generatedAtValue instanceof \Carbon\CarbonInterface ? $generatedAtValue->format('Y') : now()->format('Y');
    $logoPath = public_path('images/logo-wordmark.png');
    $logoDataUri = is_file($logoPath) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath)) : null;
    $showWordToc = (bool) ($templateLayout['word_include_toc'] ?? true);

    $actionRows = $directionServiceReport
        ->flatMap(function (array $direction): array {
            $directionLabel = trim((($direction['code'] ?? '') !== '' ? ($direction['code'].' - ') : '').($direction['libelle'] ?? 'Direction'));

            return collect($direction['services'] ?? [])
                ->flatMap(function (array $service) use ($directionLabel): array {
                    $serviceLabel = trim((($service['code'] ?? '') !== '' ? ($service['code'].' - ') : '').($service['libelle'] ?? 'Service'));

                    return collect($service['actions'] ?? [])
                        ->map(fn (array $row): array => array_merge($row, [
                            'direction_label' => $directionLabel,
                            'service_label' => $serviceLabel,
                            'service_responsable' => (string) ($service['responsable'] ?? '-'),
                        ]))
                        ->all();
                })
                ->all();
        })
        ->values();
    $strategyRows = $actionRows
        ->map(fn (array $row): array => [
            'axe_numero' => (string) (($row['axe_numero'] ?? '') !== '' ? $row['axe_numero'] : '-'),
            'axe' => (string) ($row['axe_strategique'] ?? $row['axe'] ?? '-'),
            'objectif_numero' => (string) (($row['objectif_strategique_numero'] ?? '') !== '' ? $row['objectif_strategique_numero'] : '-'),
            'objectif' => (string) ($row['objectif_strategique'] ?? $row['objectif'] ?? '-'),
            'echeance' => (string) ($row['echeance_strategique'] ?? ''),
        ])
        ->unique(fn (array $row): string => implode('|', $row))
        ->values();
    $summaryRows = $directionServiceReport
        ->flatMap(function (array $direction): array {
            $directionLabel = trim((($direction['code'] ?? '') !== '' ? ($direction['code'].' - ') : '').($direction['libelle'] ?? 'Direction'));

            return collect($direction['services'] ?? [])
                ->map(fn (array $service): array => [
                    'direction' => $directionLabel,
                    'service' => trim((($service['code'] ?? '') !== '' ? ($service['code'].' - ') : '').($service['libelle'] ?? 'Service')),
                    'summary' => (array) ($service['summary'] ?? []),
                ])
                ->all();
        })
        ->values();
    $alertRows = collect($details['kpi_sous_seuil'] ?? [])
        ->map(fn ($mesure): array => [
            'action' => (string) ($mesure->kpi?->action?->libelle ?? '-'),
            'indicateur' => (string) ($mesure->kpi?->libelle ?? '-'),
            'valeur' => (float) ($mesure->valeur ?? 0),
            'seuil' => (float) ($mesure->kpi?->seuil_alerte ?? 0),
            'statut' => 'Alerte',
            'correctif' => 'Verifier la mesure, documenter l ecart et proposer une action corrective.',
        ])
        ->merge(collect($details['actions_retard'] ?? [])->map(fn ($action): array => [
            'action' => (string) ($action->libelle ?? '-'),
            'indicateur' => 'Retard action',
            'valeur' => (float) ($action->progression_reelle ?? 0),
            'seuil' => 100,
            'statut' => 'En retard',
            'correctif' => 'Replanifier, lever les blocages et mettre a jour la progression.',
        ]))
        ->values();
    $riskRows = $actionRows->filter(fn (array $row): bool => trim((string) ($row['risque_identifie'] ?? '')) !== '')->values();
    $rmoRows = $actionRows
        ->groupBy(fn (array $row): string => implode('|', [(string) ($row['direction_label'] ?? '-'), (string) ($row['service_label'] ?? '-'), (string) ($row['rmo'] ?? $row['responsable'] ?? 'Non renseigne')]))
        ->map(function ($rows, string $key): array {
            [$direction, $service, $rmo] = array_pad(explode('|', $key, 3), 3, 'Non renseigne');

            return ['direction' => $direction ?: '-', 'service' => $service ?: '-', 'rmo' => $rmo ?: 'Non renseigne', 'total' => $rows->count(), 'performance' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_performance_value'] ?? 0)), 2)];
        })
        ->sortBy(fn (array $row): string => $row['direction'].'|'.$row['service'].'|'.sprintf('%09.2f', 10000 - (float) $row['performance']))
        ->values();
    $justificatifRows = $actionRows
        ->flatMap(function (array $row): array {
            $justificatifs = (array) ($row['justificatifs'] ?? []);
            if ($justificatifs === []) {
                return [[
                    'direction' => (string) ($row['direction_label'] ?? '-'),
                    'service' => (string) ($row['service_label'] ?? '-'),
                    'action' => (string) ($row['action'] ?? '-'),
                    'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                    'justificatif' => '-',
                    'statut' => (string) ($row['statut_validation'] ?? '-'),
                    'date' => '',
                ]];
            }

            return collect($justificatifs)->map(fn (array $justificatif): array => [
                'direction' => (string) ($row['direction_label'] ?? '-'),
                'service' => (string) ($row['service_label'] ?? '-'),
                'action' => (string) ($row['action'] ?? '-'),
                'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'),
                'justificatif' => (string) ($justificatif['nom'] ?? '-'),
                'statut' => (string) ($row['statut_validation'] ?? '-'),
                'date' => (string) ($justificatif['date'] ?? ''),
            ])->all();
        })
        ->values();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4 landscape; margin: 24px 28px 34px; }
        body{font-family:"Cambria","Georgia",serif;color:#111827;font-size:10pt;line-height:1.38;margin:0;background:#fff}
        h1,h2,h3{color:#173f73;margin:0 0 10px} h1{font-size:25pt;letter-spacing:.02em} h2{font-size:15pt;margin-top:18px} h3{font-size:12pt;margin-top:14px}
        .logo{width:118px;height:auto;margin-bottom:22px}.cover-band{background:#5b9bd5;color:#173f73;text-align:center;padding:26px 28px;margin:28px 0 34px;font-size:28pt;font-weight:800;line-height:1.08;text-shadow:1px 1px 0 #d9e9f8}.cover-meta{text-align:center;font-size:13pt;color:#111827;margin:8px 0}.meta{font-size:9.5pt;color:#475569;margin-bottom:14px}.blue-band{background:#4f83bd;color:#fff;text-align:center;padding:10px 12px;font-size:16pt;font-weight:800;margin:18px 0 14px}
        table{width:100%;border-collapse:collapse;margin-top:10px;margin-bottom:16px} th,td{border:1px solid #4f8ed7;padding:5px 6px;vertical-align:top} th{background:#3B82F6;color:#fff;text-align:center;font-weight:700} tbody td{background:#f8fafc}.compact th,.compact td{font-size:8.6pt;padding:4px}.summary-grid td{width:25%;text-align:center;font-size:13pt;font-weight:800}.muted{color:#64748b}.page-break{page-break-after:always}.section-break{page-break-before:always}.signature-box{border:2px solid #4f8ed7;width:52%;padding:18px 12px;margin-top:60px;min-height:78px}.footer-note{font-size:9pt;color:#64748b;margin-top:18px;text-align:right}.nowrap{white-space:nowrap}
    </style>
</head>
<body>
    @if (($templateBlocks['include_cover'] ?? true) === true)
        @if ($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="ANBG">@else<p class="meta">ANBG</p>@endif
        @if (! empty($templateLayout['header_text'] ?? ''))<p class="meta">{{ $templateLayout['header_text'] }}</p>@endif
        <div class="cover-band">RAPPORT DE REPORTING</div>
        <p class="cover-meta"><strong>{{ $title }}</strong></p>
        @if ($subtitle)<p class="cover-meta"><strong>{{ $subtitle }}</strong></p>@endif
        <p class="cover-meta">Exercice : {{ $generatedYear }}</p>
        <p class="cover-meta">Périmètre : GLOBAL / Direction / Service selon droits utilisateur</p>
        <p class="cover-meta">Généré le {{ $generatedAtLabel }}</p>
        <p class="cover-meta">Base statistique : {{ $officialBaseLabel }}</p>
        @if (! empty($templateLayout['watermark_text'] ?? ''))<p class="cover-meta">{{ $templateLayout['watermark_text'] }}</p>@endif
        <div class="page-break"></div>
    @endif

    @if ($showWordToc)
        <h2>Sommaire</h2>
        <table><tr><th>Section</th><th>Contenu</th></tr><tr><td>01</td><td>Axes & Objectifs stratégiques</td></tr><tr><td>02</td><td>Objectifs opérationnels & Actions</td></tr><tr><td>03</td><td>Actions détaillées</td></tr><tr><td>04</td><td>KPI par action</td></tr><tr><td>05</td><td>Reporting synthétique, alertes, risques, RMO et justificatifs</td></tr><tr><td>06</td><td>Page de signature et page de fin</td></tr></table>
        <div class="page-break"></div>
    @endif

    @if (($templateBlocks['include_summary'] ?? true) === true)
        <div class="blue-band">Reporting synthétique</div>
        <table class="summary-grid"><tr><th>Actions</th><th>Terminées</th><th>En retard</th><th>Alertes actives</th></tr><tr><td>{{ $global['actions_total'] ?? 0 }}</td><td>{{ $global['actions_achevees'] ?? 0 }}</td><td>{{ $alertes['actions_en_retard'] ?? 0 }}</td><td>{{ ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0) }}</td></tr></table>
        <p class="meta">Base statistique : {{ $officialBaseLabel }}</p>
    @endif

    @include('workspace.monitoring.partials.reporting-direction-service-sections', [
        'variant' => 'word',
        'directionServiceReport' => $directionServiceReport,
        'strategyRows' => $strategyRows,
        'templateBlocks' => $templateBlocks,
        'alertRows' => $alertRows,
    ])

    <div class="section-break">
        @if ($logoDataUri)<img class="logo" src="{{ $logoDataUri }}" alt="ANBG">@endif
        <div class="blue-band">PAGE DE SIGNATURE</div>
        <p>Rapport généré automatiquement par le système PAS ANBG le {{ $generatedAtLabel }}.</p>
        @if (($templateBlocks['include_signatures'] ?? false) === true)
            <h2>Visa et signatures</h2><div class="signature-box"><p>{{ $templateLayout['footer_text'] ?? 'Document de diffusion interne' }}</p><p style="margin-top:38px;"><strong>Nom / Visa / Date</strong></p></div>
        @endif
        <p class="footer-note">ANBG - Exercice {{ $generatedYear }} - {{ $templateLayout['footer_text'] ?? 'Diffusion interne ANBG' }}</p>
    </div>
</body>
</html>
