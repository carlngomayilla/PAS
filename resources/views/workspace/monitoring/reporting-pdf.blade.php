<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Reporting consolide ANBG</title>
    <style>
        @page {
            margin: 36px 32px 56px;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1c203d;
            font-size: 12px;
            margin: 0;
        }
        h1, h2 {
            margin: 0 0 8px;
            color: #1c203d;
        }
        .meta {
            margin-bottom: 14px;
            color: #475569;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            border: 1px solid #d9e4ee;
        }
        th, td {
            border: 1px solid #d9e4ee;
            padding: 6px 7px;
            vertical-align: top;
        }
        th {
            background: #e8f3fb;
            text-align: left;
            color: #1c203d;
            font-weight: 700;
        }
        .section {
            margin-top: 12px;
        }
        .section h2 {
            padding: 6px 10px;
            background: linear-gradient(90deg, #1c203d 0%, #3996d3 100%);
            color: #ffffff;
        }
        tbody tr:nth-child(even) td {
            background: #f8fafc;
        }
        .compact th,
        .compact td {
            font-size: 9px;
            padding: 4px;
        }
        .cards-grid,
        .visual-grid,
        .bar-list {
            width: 100%;
            border-collapse: separate;
        }
        .cards-grid {
            border-spacing: 10px 0;
            margin-bottom: 14px;
        }
        .cards-grid td,
        .visual-grid td {
            border: none;
            padding: 0;
            vertical-align: top;
        }
        .visual-grid {
            border-spacing: 10px 10px;
            margin-bottom: 12px;
        }
        .metric-card {
            border: 1px solid #d9e4ee;
            border-radius: 14px;
            overflow: hidden;
            background: #ffffff;
        }
        .metric-label {
            padding: 7px 10px;
            font-size: 10px;
            font-weight: 700;
        }
        .metric-value {
            padding: 12px 10px;
            font-size: 22px;
            font-weight: 900;
            text-align: center;
        }
        .card-blue .metric-label {
            background: #3996d3;
            color: #ffffff;
        }
        .card-blue .metric-value {
            background: #e8f3fb;
            color: #1c203d;
        }
        .card-green .metric-label {
            background: #8fc043;
            color: #1c203d;
        }
        .card-green .metric-value {
            background: #eef6e1;
            color: #1c203d;
        }
        .card-orange .metric-label {
            background: #f9b13c;
            color: #1c203d;
        }
        .card-orange .metric-value {
            background: #fff0df;
            color: #1c203d;
        }
        .card-navy .metric-label {
            background: #1c203d;
            color: #ffffff;
        }
        .card-navy .metric-value {
            background: #eef1f8;
            color: #1c203d;
        }
        .mini-panel {
            border: 1px solid #d9e4ee;
            border-radius: 14px;
            padding: 10px 12px;
            background: #ffffff;
        }
        .mini-panel h3 {
            margin: 0 0 10px;
            color: #1c203d;
            font-size: 12px;
        }
        .bar-list {
            border-spacing: 0;
        }
        .bar-list td {
            border: none;
            padding: 4px 0;
            vertical-align: middle;
        }
        .bar-label {
            width: 42%;
            font-size: 10px;
            color: #334155;
        }
        .bar-value {
            width: 14%;
            font-size: 10px;
            color: #1c203d;
            font-weight: 700;
            text-align: right;
            padding-right: 8px;
        }
        .bar-track {
            width: 100%;
            height: 8px;
            background: #eef2f6;
            border-radius: 999px;
            overflow: hidden;
        }
        .bar-fill {
            height: 8px;
            border-radius: 999px;
        }
        .bar-fill-blue {
            background: linear-gradient(90deg, #3996d3 0%, #1c203d 100%);
        }
        .bar-fill-green {
            background: linear-gradient(90deg, #8fc043 0%, #f0e509 100%);
        }
        .bar-fill-orange {
            background: linear-gradient(90deg, #f9b13c 0%, #f0e509 100%);
        }
        .bar-fill-navy {
            background: linear-gradient(90deg, #1c203d 0%, #3996d3 100%);
        }
        .cover {
            margin-bottom: 16px;
        }
        .toc {
            border: 1px solid #d9e4ee;
            border-radius: 14px;
            padding: 14px 16px;
            background: #ffffff;
            page-break-after: always;
        }
        .toc h2 {
            padding: 0;
            margin-bottom: 10px;
            background: none;
            color: #1c203d;
        }
        .toc-table {
            width: 100%;
            border: none;
            margin: 0;
        }
        .toc-table td {
            border: none;
            padding: 5px 0;
            font-size: 11px;
        }
        .toc-index {
            width: 36px;
            color: #3996d3;
            font-weight: 900;
        }
        .page-break-section {
            page-break-before: always;
        }
        .section-kicker {
            display: inline-block;
            margin-bottom: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #eef1f8;
            color: #1c203d;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.04em;
        }
        .pdf-footer {
            position: fixed;
            bottom: -32px;
            left: 0;
            right: 0;
            font-size: 10px;
            color: #64748b;
            text-align: right;
        }
        .pdf-footer .page-num:after {
            content: counter(page);
        }
        .pdf-footer .page-total:after {
            content: counter(pages);
        }
    </style>
</head>
<body>
    @php
        $summaryCards = [
            ['label' => 'PAS', 'value' => $global['pas_total'] ?? 0, 'class' => 'blue'],
            ['label' => 'PAO', 'value' => $global['paos_total'] ?? 0, 'class' => 'green'],
            ['label' => 'PTA', 'value' => $global['ptas_total'] ?? 0, 'class' => 'orange'],
            ['label' => 'Actions', 'value' => $global['actions_total'] ?? 0, 'class' => 'navy'],
        ];

        $funnelLabels = $charts['funnel']['labels'] ?? [];
        $funnelValues = $charts['funnel']['values'] ?? [];
        $funnelMax = max(1, count($funnelValues) > 0 ? max($funnelValues) : 1);

        $alertRows = collect($alertes)->map(fn ($count, $label) => [
            'label' => str_replace('_', ' ', ucfirst((string) $label)),
            'count' => (int) $count,
        ])->values();
        $alertMax = max(1, $alertRows->max('count') ?? 1);

        $performanceRows = collect($charts['performance_gauge']['labels'] ?? [])
            ->map(fn ($label, $index) => [
                'label' => (string) $label,
                'value' => (float) (($charts['performance_gauge']['values'][$index] ?? 0)),
            ])
            ->take(5)
            ->values();

        $interannualRows = collect($interannualComparison)
            ->map(fn ($row) => [
                'label' => (string) ($row['annee'] ?? '-'),
                'actions' => (int) ($row['actions_total'] ?? 0),
                'validation' => (float) ($row['taux_validation'] ?? 0),
                'progression' => (float) ($row['progression_moyenne'] ?? 0),
            ])
            ->take(5)
            ->values();

        $pdfSections = [
            '01' => 'Synthese graphique',
            '02' => 'Indicateurs globaux',
            '03' => 'Statuts',
            '04' => 'Alertes de synthese',
            '05' => 'Vue consolidee du PAS',
            '06' => 'Comparaison interannuelle',
            '07' => 'Details actions en retard',
            '08' => 'Details KPI sous seuil',
            '09' => 'Structure des rapports strategiques',
        ];
    @endphp

    <div class="pdf-footer">
        Reporting ANBG | Page <span class="page-num"></span> / <span class="page-total"></span>
    </div>

    <div class="cover">
        <h1>Reporting consolide ANBG</h1>
        <p class="meta">
            Genere le {{ $generatedAt->format('Y-m-d H:i:s') }} |
            Role: {{ $scope['role'] }} |
            Direction: {{ $scope['direction_id'] ?? '-' }} |
            Service: {{ $scope['service_id'] ?? '-' }}
        </p>
    </div>

    <div class="toc">
        <h2>Sommaire</h2>
        <table class="toc-table">
            @foreach ($pdfSections as $number => $title)
                <tr>
                    <td class="toc-index">{{ $number }}</td>
                    <td>{{ $title }}</td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 01</span>
        <h2>Synthese graphique</h2>

        <table class="cards-grid">
            <tr>
                @foreach ($summaryCards as $card)
                    <td>
                        <div class="metric-card card-{{ $card['class'] }}">
                            <div class="metric-label">{{ $card['label'] }}</div>
                            <div class="metric-value">{{ $card['value'] }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
        </table>

        <table class="visual-grid">
            <tr>
                <td>
                    <div class="mini-panel">
                        <h3>Funnel de pilotage</h3>
                        <table class="bar-list">
                            @forelse ($funnelLabels as $index => $label)
                                @php
                                    $value = (int) ($funnelValues[$index] ?? 0);
                                    $pct = $funnelMax > 0 ? ($value / $funnelMax) * 100 : 0;
                                @endphp
                                <tr>
                                    <td class="bar-label">{{ $label }}</td>
                                    <td class="bar-value">{{ $value }}</td>
                                    <td>
                                        <div class="bar-track">
                                            <div class="bar-fill bar-fill-blue" style="width: {{ max(0, min(100, $pct)) }}%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="bar-label">Aucune donnee</td>
                                    <td class="bar-value">0</td>
                                    <td><div class="bar-track"></div></td>
                                </tr>
                            @endforelse
                        </table>
                    </div>
                </td>
                <td>
                    <div class="mini-panel">
                        <h3>Alertes</h3>
                        <table class="bar-list">
                            @forelse ($alertRows as $row)
                                @php $pct = $alertMax > 0 ? ($row['count'] / $alertMax) * 100 : 0; @endphp
                                <tr>
                                    <td class="bar-label">{{ $row['label'] }}</td>
                                    <td class="bar-value">{{ $row['count'] }}</td>
                                    <td>
                                        <div class="bar-track">
                                            <div class="bar-fill bar-fill-orange" style="width: {{ max(0, min(100, $pct)) }}%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="bar-label">Aucune alerte</td>
                                    <td class="bar-value">0</td>
                                    <td><div class="bar-track"></div></td>
                                </tr>
                            @endforelse
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table class="visual-grid">
            <tr>
                <td>
                    <div class="mini-panel">
                        <h3>Performance moyenne</h3>
                        <table class="bar-list">
                            @forelse ($performanceRows as $row)
                                <tr>
                                    <td class="bar-label">{{ $row['label'] }}</td>
                                    <td class="bar-value">{{ number_format($row['value'], 1) }}%</td>
                                    <td>
                                        <div class="bar-track">
                                            <div class="bar-fill bar-fill-green" style="width: {{ max(0, min(100, $row['value'])) }}%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="bar-label">Aucune donnee</td>
                                    <td class="bar-value">0%</td>
                                    <td><div class="bar-track"></div></td>
                                </tr>
                            @endforelse
                        </table>
                    </div>
                </td>
                <td>
                    <div class="mini-panel">
                        <h3>Interannuel</h3>
                        <table class="bar-list">
                            @forelse ($interannualRows as $row)
                                <tr>
                                    <td class="bar-label">{{ $row['label'] }}</td>
                                    <td class="bar-value">{{ number_format($row['validation'], 1) }}%</td>
                                    <td>
                                        <div class="bar-track">
                                            <div class="bar-fill bar-fill-navy" style="width: {{ max(0, min(100, $row['validation'])) }}%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="bar-label">Aucune comparaison</td>
                                    <td class="bar-value">0%</td>
                                    <td><div class="bar-track"></div></td>
                                </tr>
                            @endforelse
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 02</span>
        <h2>Indicateurs globaux</h2>
        <table>
            <thead>
                <tr>
                    <th>Indicateur</th>
                    <th>Valeur</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($global as $key => $value)
                    <tr>
                        <td>{{ $key }}</td>
                        <td>{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 03</span>
        <h2>Statuts</h2>
        <table>
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Statut</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statuts as $module => $rows)
                    @foreach ($rows as $status => $total)
                        <tr>
                            <td>{{ strtoupper($module) }}</td>
                            <td>{{ $status }}</td>
                            <td>{{ $total }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 04</span>
        <h2>Alertes de synthese</h2>
        <table>
            <thead>
                <tr>
                    <th>Alerte</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($alertes as $label => $count)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>{{ $count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 05</span>
        <h2>Vue consolidee du PAS</h2>
        <table>
            <thead>
                <tr>
                    <th>PAS</th>
                    <th>Periode</th>
                    <th>Axes</th>
                    <th>Objectifs</th>
                    <th>PAO</th>
                    <th>PTA</th>
                    <th>Actions</th>
                    <th>Validees</th>
                    <th>Taux</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pasConsolidation as $row)
                    <tr>
                        <td>{{ $row['titre'] }}</td>
                        <td>{{ $row['periode'] }}</td>
                        <td>{{ $row['axes_total'] }}</td>
                        <td>{{ $row['objectifs_total'] }}</td>
                        <td>{{ $row['paos_total'] }}</td>
                        <td>{{ $row['ptas_total'] }}</td>
                        <td>{{ $row['actions_total'] }}</td>
                        <td>{{ $row['actions_validees'] }}</td>
                        <td>{{ number_format((float) $row['taux_realisation'], 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">Aucune consolidation disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 06</span>
        <h2>Comparaison interannuelle</h2>
        <table>
            <thead>
                <tr>
                    <th>Annee</th>
                    <th>PAO</th>
                    <th>PTA</th>
                    <th>Actions</th>
                    <th>Validees</th>
                    <th>Retard</th>
                    <th>Progression</th>
                    <th>Taux validation</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($interannualComparison as $row)
                    <tr>
                        <td>{{ $row['annee'] }}</td>
                        <td>{{ $row['paos_total'] }}</td>
                        <td>{{ $row['ptas_total'] }}</td>
                        <td>{{ $row['actions_total'] }}</td>
                        <td>{{ $row['actions_validees'] }}</td>
                        <td>{{ $row['actions_retard'] }}</td>
                        <td>{{ number_format((float) $row['progression_moyenne'], 2) }}%</td>
                        <td>{{ number_format((float) $row['taux_validation'], 2) }}%</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">Aucune comparaison disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 07</span>
        <h2>Details actions en retard</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Action</th>
                    <th>Echeance</th>
                    <th>Statut</th>
                    <th>PTA</th>
                    <th>Responsable</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['actions_retard'] as $action)
                    <tr>
                        <td>{{ $action->id }}</td>
                        <td>{{ $action->libelle }}</td>
                        <td>{{ optional($action->date_echeance)->format('Y-m-d') ?? '-' }}</td>
                        <td>{{ $action->statut_dynamique }}</td>
                        <td>{{ $action->pta?->titre ?? '-' }}</td>
                        <td>{{ $action->responsable?->name ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Aucune action en retard.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 08</span>
        <h2>Details KPI sous seuil</h2>
        <table>
            <thead>
                <tr>
                    <th>ID mesure</th>
                    <th>KPI</th>
                    <th>Periode</th>
                    <th>Valeur</th>
                    <th>Seuil</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['kpi_sous_seuil'] as $mesure)
                    <tr>
                        <td>{{ $mesure->id }}</td>
                        <td>{{ $mesure->kpi?->libelle ?? '-' }}</td>
                        <td>{{ $mesure->periode }}</td>
                        <td>{{ $mesure->valeur }}</td>
                        <td>{{ $mesure->kpi?->seuil_alerte ?? '-' }}</td>
                        <td>{{ $mesure->kpi?->action?->libelle ?? '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">Aucune mesure sous seuil.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="section page-break-section">
        <span class="section-kicker">Section 09</span>
        <h2>Structure des rapports strategiques</h2>
        <table class="compact">
            <thead>
                <tr>
                    <th>Axe strategique</th>
                    <th>Objectif strategique</th>
                    <th>Objectif operationnel</th>
                    <th>Description actions detaillees</th>
                    <th>RMO</th>
                    <th>Cible</th>
                    <th>Debut</th>
                    <th>Fin</th>
                    <th>Etat</th>
                    <th>Prog.</th>
                    <th>Ressources</th>
                    <th>Indicateurs</th>
                    <th>Risques</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details['structure_rapports'] as $row)
                    <tr>
                        <td>{{ $row['axe_strategique'] ?: '-' }}</td>
                        <td>{{ $row['objectif_strategique'] ?: '-' }}</td>
                        <td>{{ $row['objectif_operationnel'] ?: '-' }}</td>
                        <td>{{ $row['description_actions_detaillees'] ?: '-' }}</td>
                        <td>{{ $row['rmo'] ?: '-' }}</td>
                        <td>{{ $row['cible'] ?: '-' }}</td>
                        <td>{{ $row['debut'] ?: '-' }}</td>
                        <td>{{ $row['fin'] ?: '-' }}</td>
                        <td>{{ $row['etat_realisation'] ?: '-' }}</td>
                        <td>{{ $row['progression'] ?: '-' }}</td>
                        <td>{{ $row['ressources_requises'] ?: '-' }}</td>
                        <td>{{ $row['indicateurs_performance'] ?: '-' }}</td>
                        <td>{{ $row['risques_potentiels'] ?: '-' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="13">Aucune ligne de structure disponible.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
