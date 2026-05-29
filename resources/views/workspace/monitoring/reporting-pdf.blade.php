<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ ($exportTemplate ?? null)?->documentTitle() ?? 'Reporting ANBG' }}</title>
    <style>
        @page{size:A4 landscape;margin:36px 32px 56px} body{font-family:DejaVu Sans,sans-serif;color:#1F2937;font-size:10px;margin:0} h1,h2{margin:0 0 8px;color:#1F2937} .meta{margin-bottom:12px;color:#6B7280} table{width:100%;border-collapse:collapse;margin-bottom:14px;border:1px solid #BFDBFE} th,td{border:1px solid #BFDBFE;padding:5px 6px;vertical-align:top} th{background:#3996D3;color:#fff;text-align:left;font-weight:700} tbody tr:nth-child(even) td{background:#F8FBFF} .compact th,.compact td{font-size:8px;padding:4px}.section{margin-top:12px;page-break-before:always;page-break-after:always}.section:first-of-type{page-break-before:auto}.page-break-section{page-break-before:always;page-break-after:always}.report-logo{width:116px;height:auto;margin-bottom:18px;display:block;margin-left:auto;margin-right:auto}.cover{margin-bottom:16px;page-break-after:always}.cover-band{position:relative;margin:22px 0 24px;height:96px;overflow:hidden;background:#3996D3;border:1px solid rgba(28,32,61,.18)}.cover-band-image{width:100%;height:96px}.cover-band-title{position:absolute;top:0;left:0;right:0;padding:24px 28px;color:#FFFFFF;text-align:center;font-size:31px;line-height:1.12;font-weight:900;letter-spacing:.02em;text-shadow:0 2px 4px rgba(28,32,61,.42)}.cover-meta{text-align:center;font-size:13px;color:#1F2937;margin:7px 0}.toc{border:1px solid #BFDBFE;border-radius:14px;padding:14px 16px;background:#FFFFFF;page-break-after:always}.toc h2{padding:0;margin-bottom:10px;background:none;color:#1F2937}.toc-table{width:100%;border:none;margin:0}.toc-table td{border:none;padding:5px 0;font-size:11px}.toc-index{width:36px;color:#3996D3;font-weight:900}.section-kicker{display:inline-block;margin-bottom:6px;padding:4px 10px;border-radius:999px;background:#E8F3FB;color:#1C203D;font-size:10px;font-weight:800;letter-spacing:.04em}.section h2{padding:7px 10px;background:#1C203D;color:#FFFFFF}.cards-grid{width:100%;border-collapse:separate;border-spacing:10px 0;margin-bottom:14px}.cards-grid td{border:none;padding:0;vertical-align:top}.metric-card{border:1px solid #BFDBFE;border-radius:14px;overflow:hidden;background:#FFFFFF}.metric-label{padding:7px 10px;font-size:10px;font-weight:700;background:#3996D3;color:#fff}.metric-value{padding:12px 10px;font-size:22px;font-weight:900;text-align:center;background:#E8F3FB;color:#1C203D}.signature-banner{margin:18px 0 22px;padding:12px;background:#4F83BD;color:#fff;text-align:center;font-size:20px;font-weight:900}.signature-box{width:48%;min-height:74px;margin-top:60px;border:2px solid #3996D3;padding:14px;font-weight:700}.pdf-footer{position:fixed;bottom:-32px;left:0;right:0;font-size:10px;color:#6B7280;text-align:right}.pdf-footer .page-num:after{content:counter(page)}.pdf-footer .page-total:after{content:counter(pages)}.nowrap{white-space:nowrap}
    </style>
</head>
<body>
    @php
        $exportTemplate = $exportTemplate ?? null;
        $reportContext = (array) ($report_context ?? []);
        $reportType = (string) ($reportContext['type'] ?? 'consolide_dg');
        $isConsolidatedReport = $reportType === '' || $reportType === 'consolide_dg';
        $tocEntries = match ($reportType) {
            'pas' => ['Plan PAS', 'Axes et objectifs strategiques'],
            'pao' => ['Objectifs operationnels', 'Services concernes'],
            'pta' => ['Synthese PTA', 'Actions par service ou unite'],
            'actions' => ['Actions detaillees'],
            'kpi' => ['Indicateurs par action', 'Performance par RMO'],
            'anomalies' => ['Anomalies et blocages', 'Alertes sous seuil'],
            'financement' => ['Financements DAF / DG'],
            default => ['Axes et objectifs strategiques', 'Objectifs operationnels et actions', 'Actions detaillees', 'Indicateurs execution par action', 'Reporting synthetique, alertes, RMO et justificatifs', 'Page de signature et page de fin'],
        };
        $templateLayout = $exportTemplate?->layout_config ?? [];
        $templateBlocks = $exportTemplate?->blocks_config ?? [];
        $templateTitle = (string) ($reportContext['title'] ?? $exportTemplate?->documentTitle() ?? 'Reporting ANBG');
        $templateSubtitle = (string) ($reportContext['description'] ?? $exportTemplate?->documentSubtitle() ?? '');
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
        $rmoRows = $actionRows->groupBy(fn (array $row): string => implode('|', [(string) ($row['direction_label'] ?? '-'), (string) ($row['service_label'] ?? '-'), (string) ($row['rmo'] ?? $row['responsable'] ?? 'Non renseigné')]))->map(function ($rows, string $key): array { [$direction, $service, $rmo] = array_pad(explode('|', $key, 3), 3, 'Non renseigné'); return ['direction' => $direction ?: '-', 'service' => $service ?: '-', 'rmo' => $rmo ?: 'Non renseigné', 'total' => $rows->count(), 'performance' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_performance_value'] ?? 0)), 2)]; })->sortBy(fn (array $row): string => $row['direction'].'|'.$row['service'].'|'.sprintf('%09.2f', 10000 - (float) $row['performance']))->values();
        $justificatifRows = $actionRows->flatMap(function (array $row): array { $justificatifs = (array) ($row['justificatifs'] ?? []); if ($justificatifs === []) { return [['direction' => (string) ($row['direction_label'] ?? '-'), 'service' => (string) ($row['service_label'] ?? '-'), 'action' => (string) ($row['action'] ?? '-'), 'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'), 'justificatif' => '-', 'statut' => (string) ($row['statut_validation'] ?? '-'), 'date' => '']]; } return collect($justificatifs)->map(fn (array $justificatif): array => ['direction' => (string) ($row['direction_label'] ?? '-'), 'service' => (string) ($row['service_label'] ?? '-'), 'action' => (string) ($row['action'] ?? '-'), 'rmo' => (string) ($row['rmo'] ?? $row['responsable'] ?? '-'), 'justificatif' => (string) ($justificatif['nom'] ?? '-'), 'statut' => (string) ($row['statut_validation'] ?? '-'), 'date' => (string) ($justificatif['date'] ?? '')])->all(); })->values();
        $anomalyRows = $actionRows->flatMap(function (array $row): array { return collect($row['anomalies'] ?? [])->map(fn (array $anomaly): array => ['direction' => (string) ($row['direction_label'] ?? '-'), 'service' => (string) ($row['service_label'] ?? '-'), 'action' => (string) ($row['action'] ?? '-'), 'type' => (string) ($anomaly['type'] ?? '-'), 'niveau' => (string) ($anomaly['niveau'] ?? '-'), 'responsable' => (string) ($anomaly['responsable'] ?? '-'), 'blocage' => (string) ($anomaly['blocage'] ?? '-'), 'correction' => (string) ($anomaly['correction_attendue'] ?? '-'), 'message' => (string) ($anomaly['message'] ?? '-'), 'signale_par' => (string) ($anomaly['signale_par'] ?? '-'), 'date' => (string) ($anomaly['date'] ?? '')])->all(); })->values();
        $financingRows = $actionRows->filter(fn (array $row): bool => (bool) ($row['financement_requis'] ?? false))->values();
        $allActionDates = $actionRows->flatMap(fn (array $row): array => [$row['debut'] ?? '', $row['fin'] ?? '', $row['echeance_strategique'] ?? ''])->map(fn ($v): string => trim((string) $v))->filter(fn ($v): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1)->sort()->values();
        $planPeriodStart = $allActionDates->first() !== null ? substr((string) $allActionDates->first(), 0, 4) : $generatedYear;
        $planPeriodEnd = $allActionDates->last() !== null ? substr((string) $allActionDates->last(), 0, 4) : $generatedYear;
        $planPeriodLabel = $planPeriodStart === $planPeriodEnd ? $planPeriodStart : $planPeriodStart.' - '.$planPeriodEnd;
    @endphp

    <div class="pdf-footer">{{ $templateFooter !== '' ? $templateFooter : 'ANBG - Rapport de reporting' }} | Exercice {{ $generatedYear }} | Page <span class="page-num"></span> / <span class="page-total"></span></div>
    @if ($templateWatermark !== '')<div style="position:fixed;top:42%;left:18%;right:18%;text-align:center;font-size:72px;font-weight:900;color:rgba(28,32,61,.08);transform:rotate(-24deg);z-index:-1;">{{ $templateWatermark }}</div>@endif
    {{-- Logo unique : affiché uniquement sur la page de couverture (cf. .report-logo dans .cover). --}}
    @if ($templateHeader !== '')<div class="meta" style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#1C203D;">{{ $templateHeader }}</div>@endif

    @if (($templateBlocks['include_cover'] ?? true))
        <div class="cover">
            @if ($logoDataUri)<img class="report-logo" src="{{ $logoDataUri }}" alt="ANBG">@endif
            <div class="cover-band">
                @if ($coverGradientDataUri)<img class="cover-band-image" src="{{ $coverGradientDataUri }}" alt="">@endif
                <div class="cover-band-title">{{ $templateTitle }}</div>
            </div>
            @if ($templateSubtitle)<p class="cover-meta"><strong>{{ $templateSubtitle }}</strong></p>@endif
            <p class="cover-meta" style="font-size:16px;font-weight:900;color:#1C203D;">Période : {{ $planPeriodLabel }}</p>
            <p class="cover-meta">Exercice {{ $generatedYear }}</p>
            <p class="cover-meta">Périmètre : GLOBAL / Direction / Service selon droits utilisateur</p>
            <p class="cover-meta">Généré le {{ $generatedAtLabel }} | Rôle : {{ $scope['role'] ?? '-' }} | Direction: {{ $scope['direction_id'] ?? '-' }} | Service: {{ $scope['service_id'] ?? '-' }}</p>
            <p class="cover-meta" style="font-weight:700;color:#1C203D;">{{ $officialBaseText }}</p>
        </div>
    @endif

    @if (($templateBlocks['include_summary'] ?? true))
        <div class="toc">
            <h2>Sommaire</h2>
            <table class="toc-table">
                @foreach ($tocEntries as $index => $entry)
                    <tr><td class="toc-index">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</td><td>{{ $entry }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if (($templateBlocks['include_summary'] ?? true) && $isConsolidatedReport)
        <div class="section page-break-section"><span class="section-kicker">Synthèse</span><h2>Reporting synthétique</h2><table class="cards-grid"><tr><td><div class="metric-card"><div class="metric-label">Actions</div><div class="metric-value">{{ $global['actions_total'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">Terminées</div><div class="metric-value">{{ $global['actions_achevees'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">En retard</div><div class="metric-value">{{ $alertes['actions_en_retard'] ?? 0 }}</div></div></td><td><div class="metric-card"><div class="metric-label">Alertes</div><div class="metric-value">{{ ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0) }}</div></div></td></tr></table><p class="meta">{{ $officialBaseText }}</p></div>
    @endif

    @if ($isConsolidatedReport)
    <div class="section page-break-section">
        <span class="section-kicker">Plan d'actions</span>
        <h2>Tableau de pilotage consolidé</h2>
        <table class="compact">
            <thead>
                <tr>
                    <th style="width:17%">Axes stratégiques</th>
                    <th style="width:5%">N°</th>
                    <th style="width:17%">Objectifs stratégiques</th>
                    <th style="width:16%">Objectifs opérationnels</th>
                    <th style="width:21%">Actions détaillées</th>
                    <th style="width:8%">Responsable</th>
                    <th style="width:10%">Ressources</th>
                    <th style="width:6%">Échéances</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($actionRows as $row)
                    <tr>
                        <td>{{ $row['axe_strategique'] ?? $row['axe'] ?? '-' }}</td>
                        <td>{{ $row['objectif_strategique_numero'] ?? $row['axe_numero'] ?? '-' }}</td>
                        <td>{{ $row['objectif_strategique'] ?? $row['objectif'] ?? '-' }}</td>
                        <td>{{ $row['objectif_operationnel'] ?? '-' }}</td>
                        <td>{{ $row['description_action'] ?? $row['action'] ?? '-' }}</td>
                        <td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td>
                        <td>{{ $row['ressources_requises'] ?? '-' }}</td>
                        <td class="nowrap">{{ $row['echeance'] ?? $row['fin'] ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Aucune action disponible.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if ($isConsolidatedReport)
        @include('workspace.monitoring.partials.reporting-direction-service-sections', [
            'variant' => 'pdf',
            'directionServiceReport' => $directionServiceReport,
            'strategyRows' => $strategyRows,
            'templateBlocks' => $templateBlocks,
            'alertRows' => $alertRows,
        ])
    @endif

    @if ($reportType === 'pas')
        <div class="section page-break-section">
            <span class="section-kicker">PAS</span>
            <h2>Axes et objectifs stratégiques</h2>
            <table class="compact">
                <thead><tr><th>Axe</th><th>N° objectif</th><th>Objectif stratégique</th><th>Échéance</th></tr></thead>
                <tbody>
                    @forelse ($strategyRows as $row)
                        <tr><td>{{ $row['axe'] ?? '-' }}</td><td>{{ $row['objectif_numero'] ?? '-' }}</td><td>{{ $row['objectif'] ?? '-' }}</td><td class="nowrap">{{ $row['echeance'] ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="4">Aucun axe ou objectif stratégique disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'pao')
        <div class="section page-break-section">
            <span class="section-kicker">PAO</span>
            <h2>Objectifs opérationnels et services</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>Axe</th><th>Objectif stratégique</th><th>Objectif opérationnel</th><th>Action</th><th>Responsable</th><th>Échéance</th></tr></thead>
                <tbody>
                    @forelse ($actionRows as $row)
                        <tr><td>{{ $row['direction_label'] ?? '-' }}</td><td>{{ $row['service_label'] ?? '-' }}</td><td>{{ $row['axe_strategique'] ?? $row['axe'] ?? '-' }}</td><td>{{ $row['objectif_strategique'] ?? $row['objectif'] ?? '-' }}</td><td>{{ $row['objectif_operationnel'] ?? '-' }}</td><td>{{ $row['action'] ?? '-' }}</td><td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td><td class="nowrap">{{ $row['echeance'] ?? $row['fin'] ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="8">Aucun objectif opérationnel disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'pta')
        <div class="section page-break-section">
            <span class="section-kicker">PTA</span>
            <h2>Synthese PTA par service ou unite</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service / unite</th><th>Actions</th><th>Terminees</th><th>En cours</th><th>En retard</th><th>Taux realisation (%)</th></tr></thead>
                <tbody>
                    @forelse ($summaryRows as $row)
                        <tr><td>{{ $row['direction'] ?? '-' }}</td><td>{{ $row['service'] ?? '-' }}</td><td>{{ $row['summary']['actions_total'] ?? 0 }}</td><td>{{ $row['summary']['actions_terminees'] ?? 0 }}</td><td>{{ $row['summary']['actions_en_cours'] ?? 0 }}</td><td>{{ $row['summary']['actions_retard'] ?? 0 }}</td><td>{{ $row['summary']['taux_realisation'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="7">Aucun PTA disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="section page-break-section">
            <span class="section-kicker">PTA</span>
            <h2>Actions du PTA</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>Objectif operationnel</th><th>Action</th><th>RMO</th><th>Debut</th><th>Fin</th><th>Cible</th><th>Ressources</th><th>Risque</th><th>Statut</th><th>Avancement (%)</th></tr></thead>
                <tbody>
                    @forelse ($actionRows as $row)
                        <tr><td>{{ $row['direction_label'] ?? '-' }}</td><td>{{ $row['service_label'] ?? '-' }}</td><td>{{ $row['objectif_operationnel'] ?? '-' }}</td><td>{{ $row['description_action'] ?? $row['action'] ?? '-' }}</td><td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td><td>{{ $row['debut'] ?? '-' }}</td><td>{{ $row['fin'] ?? '-' }}</td><td>{{ $row['cible'] ?? '-' }}</td><td>{{ $row['ressources_requises'] ?? '-' }}</td><td>{{ $row['risque_resume'] ?? '-' }}</td><td>{{ $row['statut'] ?? '-' }}</td><td>{{ $row['progression'] ?? $row['progression_value'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="12">Aucune action PTA disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'actions')
        <div class="section page-break-section">
            <span class="section-kicker">Actions</span>
            <h2>Actions detaillees</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>Objectif operationnel</th><th>Description action</th><th>RMO</th><th>Debut</th><th>Fin</th><th>Mode execution</th><th>Cible</th><th>Avancement reel (%)</th><th>Financement</th><th>Risque</th><th>Ressources</th><th>KPI global (%)</th></tr></thead>
                <tbody>
                    @forelse ($actionRows as $row)
                        <tr><td>{{ $row['direction_label'] ?? '-' }}</td><td>{{ $row['service_label'] ?? '-' }}</td><td>{{ $row['objectif_operationnel'] ?? '-' }}</td><td>{{ $row['description_action'] ?? $row['action'] ?? '-' }}</td><td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td><td>{{ $row['debut'] ?? '-' }}</td><td>{{ $row['fin'] ?? '-' }}</td><td>{{ $row['mode_execution'] ?? '-' }}</td><td>{{ $row['cible'] ?? '-' }}</td><td>{{ $row['progression'] ?? $row['progression_value'] ?? 0 }}</td><td>{{ $row['financement_resume'] ?? '-' }}</td><td>{{ $row['risque_resume'] ?? '-' }}</td><td>{{ $row['ressources_requises'] ?? '-' }}</td><td>{{ $row['kpi_global'] ?? $row['kpi_global_value'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="14">Aucune action disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'kpi')
        <div class="section page-break-section">
            <span class="section-kicker">KPI</span>
            <h2>Indicateurs par action</h2>
            <table class="compact">
                {{-- Colonne Conformite retiree (2026-05-28) du reporting PDF KPI. --}}
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>RMO</th><th>Performance (%)</th><th>Delai (%)</th><th>Avancement reel (%)</th><th>KPI global (%)</th></tr></thead>
                <tbody>
                    @forelse ($actionRows as $row)
                        <tr><td>{{ $row['direction_label'] ?? '-' }}</td><td>{{ $row['service_label'] ?? '-' }}</td><td>{{ $row['action'] ?? '-' }}</td><td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td><td>{{ $row['kpi_performance'] ?? $row['kpi_performance_value'] ?? 0 }}</td><td>{{ $row['kpi_delai'] ?? $row['kpi_delai_value'] ?? 0 }}</td><td>{{ $row['progression'] ?? $row['progression_value'] ?? 0 }}</td><td>{{ $row['kpi_global'] ?? $row['kpi_global_value'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="8">Aucun KPI disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="section page-break-section">
            <span class="section-kicker">KPI</span>
            <h2>Performance par RMO</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>RMO</th><th>Actions</th><th>Performance moyenne (%)</th></tr></thead>
                <tbody>
                    @forelse ($rmoRows as $row)
                        <tr><td>{{ $row['direction'] ?? '-' }}</td><td>{{ $row['service'] ?? '-' }}</td><td>{{ $row['rmo'] ?? '-' }}</td><td>{{ $row['total'] ?? 0 }}</td><td>{{ $row['performance'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="5">Aucune performance RMO disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'anomalies')
        <div class="section page-break-section">
            <span class="section-kicker">Anomalies</span>
            <h2>Anomalies et blocages</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Type</th><th>Niveau</th><th>Responsable</th><th>Blocage</th><th>Correction attendue</th><th>Message</th><th>Signale par</th><th>Date</th></tr></thead>
                <tbody>
                    @forelse ($anomalyRows as $row)
                        <tr><td>{{ $row['direction'] ?? '-' }}</td><td>{{ $row['service'] ?? '-' }}</td><td>{{ $row['action'] ?? '-' }}</td><td>{{ $row['type'] ?? '-' }}</td><td>{{ $row['niveau'] ?? '-' }}</td><td>{{ $row['responsable'] ?? '-' }}</td><td>{{ $row['blocage'] ?? '-' }}</td><td>{{ $row['correction'] ?? '-' }}</td><td>{{ $row['message'] ?? '-' }}</td><td>{{ $row['signale_par'] ?? '-' }}</td><td>{{ $row['date'] ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="11">Aucune anomalie disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="section page-break-section">
            <span class="section-kicker">Alertes</span>
            <h2>Alertes sous seuil</h2>
            <table class="compact">
                <thead><tr><th>Action</th><th>Indicateur</th><th>Valeur</th><th>Seuil</th><th>Statut</th><th>Correctif attendu</th></tr></thead>
                <tbody>
                    @forelse ($alertRows as $row)
                        <tr><td>{{ $row['action'] ?? '-' }}</td><td>{{ $row['indicateur'] ?? '-' }}</td><td>{{ $row['valeur'] ?? 0 }}</td><td>{{ $row['seuil'] ?? 0 }}</td><td>{{ $row['statut'] ?? '-' }}</td><td>{{ $row['correctif'] ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="6">Aucune alerte disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if ($reportType === 'financement')
        <div class="section page-break-section">
            <span class="section-kicker">Financement</span>
            <h2>Financements DAF / DG</h2>
            <table class="compact">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>RMO</th><th>Nature</th><th>Montant estime</th><th>Source</th><th>Statut DAF / DG</th><th>Observation</th><th>Avancement (%)</th><th>KPI global (%)</th></tr></thead>
                <tbody>
                    @forelse ($financingRows as $row)
                        <tr><td>{{ $row['direction_label'] ?? '-' }}</td><td>{{ $row['service_label'] ?? '-' }}</td><td>{{ $row['action'] ?? '-' }}</td><td>{{ $row['rmo'] ?? $row['responsable'] ?? '-' }}</td><td>{{ $row['financement_nature'] ?? '-' }}</td><td>{{ number_format((float) ($row['financement_montant'] ?? 0), 0, ',', ' ') }}</td><td>{{ $row['financement_source'] ?? '-' }}</td><td>{{ $row['financement_statut_label'] ?? $row['financement_statut'] ?? '-' }}</td><td>{{ $row['financement_observation'] ?? '' }}</td><td>{{ $row['progression'] ?? $row['progression_value'] ?? 0 }}</td><td>{{ $row['kpi_global'] ?? $row['kpi_global_value'] ?? 0 }}</td></tr>
                    @empty
                        <tr><td colspan="11">Aucun financement disponible.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

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
