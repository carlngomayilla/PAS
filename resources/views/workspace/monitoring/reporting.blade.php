@extends('layouts.workspace')

@section('content')
    @php
        $chartsData = $charts ?? [];
        $funnel = $chartsData['funnel'] ?? ['labels' => [], 'values' => []];
        $statusByUnit = $chartsData['status_by_unit'] ?? ['unit_label' => 'Unite', 'labels' => [], 'datasets' => []];
        $progressWeekly = $chartsData['progress_weekly'] ?? ['labels' => [], 'reel' => [], 'theorique' => []];
        $kpiTrend = $chartsData['kpi_trend'] ?? ['labels' => [], 'valeurs' => [], 'cibles' => [], 'seuils' => []];
        $retardHeatmap = $chartsData['retard_heatmap'] ?? ['weeks' => [], 'units' => [], 'matrix' => [], 'max' => 0];
        $criticalGantt = $chartsData['critical_gantt'] ?? ['min' => now()->subDays(14)->toDateString(), 'max' => now()->addDays(14)->toDateString(), 'items' => []];
        $resourceTreemap = $chartsData['resource_treemap'] ?? ['labels' => [], 'values' => [], 'total' => 0];
        $riskPareto = $chartsData['risk_pareto'] ?? ['labels' => [], 'counts' => [], 'cumulative_pct' => []];
        $topRisks = $chartsData['top_risks'] ?? ['labels' => [], 'scores' => [], 'rows' => []];
        $performanceGauge = $chartsData['performance_gauge'] ?? ['labels' => [], 'values' => []];
        $interannualOverview = $chartsData['interannual_overview'] ?? ['labels' => [], 'actions_total' => [], 'actions_validees' => [], 'progression_moyenne' => []];

        $heatMax = max(1, (int) ($retardHeatmap['max'] ?? 0));
        $ganttMin = \Illuminate\Support\Carbon::parse($criticalGantt['min'] ?? now()->subDays(14)->toDateString());
        $ganttMax = \Illuminate\Support\Carbon::parse($criticalGantt['max'] ?? now()->addDays(14)->toDateString());
        $ganttRange = max(1, $ganttMin->diffInDays($ganttMax));
        $treemapTotal = max(0.01, (float) ($resourceTreemap['total'] ?? 0));
    @endphp

    <style>
        .chart-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        }

        .chart-panel {
            border: 1px solid rgba(226, 232, 240, 0.82);
            border-radius: 1rem;
            padding: 0.95rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.96) 100%);
            position: relative;
        }

        .chart-panel h3 {
            margin: 0 0 0.2rem;
            font-size: 0.98rem;
            font-weight: 600;
        }

        .chart-panel p {
            margin: 0 0 0.7rem;
            font-size: 0.75rem;
            color: rgb(100 116 139);
        }

        .chart-actions {
            position: absolute;
            top: 0.62rem;
            right: 0.62rem;
            display: flex;
            gap: 0.35rem;
            z-index: 10;
        }

        .chart-action-btn {
            border: 1px solid rgba(148, 163, 184, 0.55);
            background: rgba(255, 255, 255, 0.92);
            color: rgb(30 41 59);
            border-radius: 0.55rem;
            font-size: 0.68rem;
            line-height: 1;
            padding: 0.36rem 0.5rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .chart-action-btn:hover {
            background: rgba(14, 116, 144, 0.12);
            border-color: rgba(14, 116, 144, 0.5);
            color: rgb(14 116 144);
        }

        .chart-action-btn:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.2);
        }

        .chart-zoom-target {
            cursor: zoom-in;
        }

        .chart-panel:fullscreen {
            border-radius: 0;
            padding: 1.1rem;
            overflow: auto;
            background: rgb(248 250 252);
        }

        .chart-panel:fullscreen .chart-canvas {
            min-height: 72vh;
        }

        .chart-panel:fullscreen .heatmap-wrap,
        .chart-panel:fullscreen .gantt-list,
        .chart-panel:fullscreen .treemap-wrap,
        .chart-panel:fullscreen .gauge-grid,
        .chart-panel:fullscreen .top-risk-table {
            max-height: 75vh;
            overflow: auto;
        }

        .chart-canvas {
            position: relative;
            min-height: 260px;
        }

        .heatmap-wrap {
            overflow: auto;
        }

        .heatmap-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0.35rem;
        }

        .heatmap-table th {
            font-size: 0.66rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgb(100 116 139);
            border: none;
            background: transparent;
            padding: 0.2rem 0.15rem;
        }

        .heatmap-table td {
            border: none;
            text-align: center;
            border-radius: 0.6rem;
            padding: 0.45rem 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .gantt-list {
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
        }

        .gantt-row {
            display: grid;
            grid-template-columns: minmax(170px, 210px) 1fr;
            gap: 0.6rem;
            align-items: center;
        }

        .gantt-label {
            font-size: 0.74rem;
            color: rgb(51 65 85);
        }

        .gantt-track {
            position: relative;
            height: 14px;
            border-radius: 999px;
            background: rgba(203, 213, 225, 0.34);
        }

        .gantt-bar {
            position: absolute;
            top: 0;
            height: 14px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(14, 116, 144, 0.85) 0%, rgba(6, 182, 212, 0.92) 100%);
        }

        .gantt-progress {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.32);
        }

        .treemap-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 0.55rem;
            min-height: 230px;
        }

        .treemap-item {
            min-width: 150px;
            border-radius: 0.9rem;
            padding: 0.65rem;
            background: linear-gradient(145deg, rgba(14, 116, 144, 0.16) 0%, rgba(6, 182, 212, 0.28) 100%);
            border: 1px solid rgba(14, 116, 144, 0.18);
        }

        .treemap-item strong {
            display: block;
            font-size: 0.73rem;
            line-height: 1.1rem;
            color: rgb(15 23 42);
        }

        .treemap-item span {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.72rem;
            color: rgb(51 65 85);
        }

        .top-risk-table {
            margin-top: 0.7rem;
            font-size: 0.75rem;
        }

        .top-risk-table table th,
        .top-risk-table table td {
            padding: 0.45rem;
        }

        .gauge-grid {
            display: grid;
            gap: 0.7rem;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .gauge-card {
            border-radius: 0.95rem;
            border: 1px solid rgba(203, 213, 225, 0.85);
            padding: 0.7rem;
            background: rgba(255, 255, 255, 0.9);
            text-align: center;
        }

        .gauge-card strong {
            font-size: 0.73rem;
            display: block;
            min-height: 2rem;
        }

        .dark .chart-panel {
            border-color: rgba(51, 65, 85, 0.85);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.94) 0%, rgba(15, 23, 42, 0.84) 100%);
        }

        .dark .chart-action-btn {
            border-color: rgba(71, 85, 105, 0.85);
            background: rgba(15, 23, 42, 0.9);
            color: rgb(226 232 240);
        }

        .dark .chart-action-btn:hover {
            background: rgba(14, 116, 144, 0.28);
            border-color: rgba(56, 189, 248, 0.45);
            color: rgb(186 230 253);
        }

        .dark .chart-panel:fullscreen {
            background: rgb(15 23 42);
        }

        .dark .chart-panel p,
        .dark .gantt-label {
            color: rgb(148 163 184);
        }

        .dark .treemap-item {
            background: linear-gradient(145deg, rgba(14, 116, 144, 0.28) 0%, rgba(6, 182, 212, 0.34) 100%);
            border-color: rgba(56, 189, 248, 0.25);
        }

        .dark .treemap-item strong {
            color: rgb(226 232 240);
        }

        .dark .treemap-item span {
            color: rgb(203 213 225);
        }

        .dark .gauge-card {
            border-color: rgba(51, 65, 85, 0.85);
            background: rgba(15, 23, 42, 0.78);
        }
    </style>

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Reporting consolide</span>
                <h1 class="showcase-title">Analyse graphique et export</h1>
                <p class="showcase-subtitle">
                    Vue consolidee des indicateurs de pilotage, des risques, de la performance et de la trajectoire interannuelle.
                    Genere le {{ $generatedAt }} pour le role {{ $scope['role'] }}.
                </p>
                <div class="showcase-chip-row">
                    @if ($scope['direction_id'])
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-emerald-500"></span>
                            Direction #{{ $scope['direction_id'] }}
                        </span>
                    @endif
                    @if ($scope['service_id'])
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-amber-500"></span>
                            Service #{{ $scope['service_id'] }}
                        </span>
                    @endif
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        11 graphiques consolides
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-green rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.excel') }}">Exporter Excel (CSV)</a>
                <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.pdf') }}">Exporter PDF</a>
            </div>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Tableau de bord graphique (11)</h2>
        <p class="showcase-panel-subtitle">Vue complete: entonnoir, statuts, avancement, KPI, comparaison interannuelle, heatmap retards, gantt, treemap ressources, pareto risques, top risques et jauges par direction.</p>

        <div class="overflow-auto mb-5">
            <h3 class="mb-2 text-base font-semibold">Consolidation PAS</h3>
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
                            <td colspan="9" class="text-slate-600">Aucune consolidation disponible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-auto mb-5">
            <h3 class="mb-2 text-base font-semibold">Comparaison interannuelle</h3>
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
                            <td colspan="8" class="text-slate-600">Aucune comparaison disponible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="chart-grid">
            <article class="chart-panel">
                <h3>1) Entonnoir PAS -> PAO -> PTA -> Actions</h3>
                <p>Mesure de la conversion entre les niveaux de planification.</p>
                <div class="chart-canvas"><canvas id="chart-funnel"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>2) Statuts Empiles Par {{ $statusByUnit['unit_label'] }}</h3>
                <p>Distribution des statuts d execution par unite organisationnelle.</p>
                <div class="chart-canvas"><canvas id="chart-status-by-unit"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>3) Avancement Reel vs Theorique</h3>
                <p>Tendance hebdomadaire de progression moyenne.</p>
                <div class="chart-canvas"><canvas id="chart-progress-weekly"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>4) KPI: Valeur vs Cible vs Seuil</h3>
                <p>Evolution consolidee des mesures KPI sur les periodes renseignees.</p>
                <div class="chart-canvas"><canvas id="chart-kpi-trend"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>5) Evolution interannuelle</h3>
                <p>Comparaison annuelle des actions ouvertes, validees et de la progression moyenne.</p>
                <div class="chart-canvas"><canvas id="chart-interannual"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>6) Heatmap Des Retards</h3>
                <p>Concentration des actions en retard par semaine et direction.</p>
                <div class="heatmap-wrap">
                    <table class="heatmap-table">
                        <thead>
                            <tr>
                                <th>Direction</th>
                                @foreach ($retardHeatmap['weeks'] as $weekLabel)
                                    <th>{{ $weekLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($retardHeatmap['units'] as $unitIndex => $unitLabel)
                                <tr>
                                    <th>{{ $unitLabel }}</th>
                                    @foreach ($retardHeatmap['weeks'] as $weekIndex => $unused)
                                        @php
                                            $value = (int) ($retardHeatmap['matrix'][$unitIndex][$weekIndex] ?? 0);
                                            $opacity = $value > 0 ? 0.14 + (0.78 * ($value / $heatMax)) : 0.06;
                                        @endphp
                                        <td style="background: rgba(239, 68, 68, {{ number_format($opacity, 3, '.', '') }});" title="{{ $value }} action(s) en retard">
                                            {{ $value }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ max(1, count($retardHeatmap['weeks']) + 1) }}" class="text-slate-600">Aucune donnee de retard sur la fenetre analysee.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="chart-panel">
                <h3>7) Gantt Des Actions Critiques</h3>
                <p>Top des actions les plus risquees selon retard, ecart de progression et risques declares.</p>
                <div class="gantt-list">
                    @forelse ($criticalGantt['items'] as $item)
                        @php
                            $start = \Illuminate\Support\Carbon::parse($item['start']);
                            $end = \Illuminate\Support\Carbon::parse($item['end']);
                            $left = max(0, min(100, (($ganttMin->diffInDays($start, false)) / $ganttRange) * 100));
                            $durationDays = max(1, $start->diffInDays($end));
                            $width = max(2.5, min(100 - $left, ($durationDays / $ganttRange) * 100));
                            $progress = max(0, min(100, (float) ($item['progress'] ?? 0)));
                        @endphp
                        <div class="gantt-row">
                            <div class="gantt-label">
                                <strong>{{ $item['label'] }}</strong><br>
                                <small>{{ $item['start'] }} -> {{ $item['end'] }} | Score {{ number_format((float) $item['score'], 1) }}</small>
                            </div>
                            <div class="gantt-track">
                                <div class="gantt-bar" style="left: {{ number_format($left, 2, '.', '') }}%; width: {{ number_format($width, 2, '.', '') }}%;">
                                    <div class="gantt-progress" style="width: {{ number_format($progress, 2, '.', '') }}%;"></div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-slate-600">Aucune action critique detectee.</p>
                    @endforelse
                </div>
            </article>

            <article class="chart-panel">
                <h3>8) Treemap Ressources / Budget Par Axe</h3>
                <p>Ponderation selon ressources mobilisees et besoin de financement estime.</p>
                <div class="treemap-wrap">
                    @forelse ($resourceTreemap['labels'] as $index => $label)
                        @php
                            $value = (float) ($resourceTreemap['values'][$index] ?? 0);
                            $basis = max(20, min(65, ($value / $treemapTotal) * 100));
                        @endphp
                        <article class="treemap-item" style="flex-basis: {{ number_format($basis, 2, '.', '') }}%;">
                            <strong>{{ $label }}</strong>
                            <span>Poids: {{ number_format($value, 2) }}</span>
                        </article>
                    @empty
                        <p class="text-slate-600">Aucune donnee de ressources disponible.</p>
                    @endforelse
                </div>
            </article>

            <article class="chart-panel">
                <h3>9) Pareto Des Risques</h3>
                <p>Principales causes de blocage et cumul en pourcentage.</p>
                <div class="chart-canvas"><canvas id="chart-risk-pareto"></canvas></div>
            </article>

            <article class="chart-panel">
                <h3>10) Top 10 Actions A Risque</h3>
                <p>Classement par score de risque consolide.</p>
                <div class="chart-canvas"><canvas id="chart-top-risks"></canvas></div>
                <div class="top-risk-table overflow-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Score</th>
                                <th>Statut</th>
                                <th>Echeance</th>
                                <th>Responsable</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($topRisks['rows'] as $row)
                                <tr>
                                    <td>{{ $row['action'] }}</td>
                                    <td>{{ number_format((float) $row['score'], 1) }}</td>
                                    <td>{{ $row['statut'] }}</td>
                                    <td>{{ $row['echeance'] }}</td>
                                    <td>{{ $row['responsable'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-slate-600">Aucune action a risque detectee.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="chart-panel">
                <h3>11) Jauges De Performance Par Direction</h3>
                <p>Score de performance global calcule sur la progression moyenne des actions.</p>
                <div class="gauge-grid">
                    @forelse ($performanceGauge['labels'] as $index => $label)
                        <article class="gauge-card">
                            <strong>{{ $label }}</strong>
                            <div style="height: 100px;">
                                <canvas id="chart-gauge-{{ $index }}"></canvas>
                            </div>
                            <p>{{ number_format((float) ($performanceGauge['values'][$index] ?? 0), 2) }}%</p>
                        </article>
                    @empty
                        <p class="text-slate-600">Aucune direction disponible pour la jauge.</p>
                    @endforelse
                </div>
            </article>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Indicateurs globaux</h2>
        <div class="showcase-summary-grid mt-4">
            @foreach ($global as $key => $value)
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">{{ str_replace('_', ' ', ucfirst($key)) }}</p>
                    <p class="showcase-kpi-number">{{ $value }}</p>
                    <p class="showcase-kpi-meta">Vue de synthese</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Statuts</h2>
        <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @foreach ($statuts as $module => $rows)
                <article class="rounded-[1.2rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70">
                    <strong>{{ strtoupper($module) }}</strong>
                    <div class="overflow-auto mt-2">
                        <table>
                            <thead>
                                <tr>
                                    <th>Statut</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $status => $total)
                                    <tr>
                                        <td>{{ $status }}</td>
                                        <td>{{ $total }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="2" class="text-slate-600">Aucune donnee</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Alertes de synthese</h2>
        <div class="showcase-summary-grid mt-4">
            @foreach ($alertes as $label => $count)
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">{{ str_replace('_', ' ', ucfirst($label)) }}</p>
                    <p class="showcase-kpi-number">{{ $count }}</p>
                    <p class="showcase-kpi-meta">Alerte consolidee</p>
                </article>
            @endforeach
        </div>
        <p class="mt-2.5">
            <a class="btn btn-blue rounded-2xl px-4 py-2" href="{{ route('workspace.alertes') }}">Voir le detail des alertes</a>
        </p>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Structure des rapports strategiques</h2>
        <p class="showcase-panel-subtitle">Trame alignee au tableau du plan d action strategique (description, RMO, cible, etat, ressources, indicateurs, risques).</p>
        <div class="overflow-auto mt-2">
            <table>
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
                        <th>Etat de realisation</th>
                        <th>Progression</th>
                        <th>Ressources requises</th>
                        <th>Indicateurs de performance</th>
                        <th>Risques potentiels</th>
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
                            <td colspan="13" class="text-slate-600">Aucune ligne de structure disponible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (function () {
            var charts = @json($chartsData);
            var isDark = document.documentElement.classList.contains('dark');
            var palette = ['#0f766e', '#0284c7', '#2563eb', '#7c3aed', '#db2777', '#ea580c', '#ca8a04', '#16a34a'];
            var textColor = isDark ? '#cbd5e1' : '#334155';
            var gridColor = isDark ? 'rgba(100,116,139,0.35)' : 'rgba(148,163,184,0.28)';

            function baseOptions() {
                return {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: textColor,
                                boxWidth: 12,
                            },
                        },
                    },
                    scales: {
                        x: {
                            ticks: { color: textColor },
                            grid: { color: gridColor },
                        },
                        y: {
                            ticks: { color: textColor },
                            grid: { color: gridColor },
                        },
                    },
                };
            }

            function resizeAllCharts() {
                if (typeof Chart === 'undefined' || !Chart.instances) {
                    return;
                }

                var instances = Chart.instances;
                if (typeof instances.forEach === 'function') {
                    instances.forEach(function (chart) {
                        if (chart && typeof chart.resize === 'function') {
                            chart.resize();
                        }
                    });
                    return;
                }

                Object.keys(instances).forEach(function (key) {
                    var chart = instances[key];
                    if (chart && typeof chart.resize === 'function') {
                        chart.resize();
                    }
                });
            }

            function chartPanelTitle(panel) {
                var title = panel.getAttribute('data-chart-title');
                if (title) {
                    return title;
                }
                var titleNode = panel.querySelector('h3');
                return titleNode ? titleNode.textContent.trim() : 'graphique';
            }

            function slugifyFileName(value) {
                return (value || 'graphique')
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 80) || 'graphique';
            }

            function downloadDataUrl(dataUrl, fileName) {
                var link = document.createElement('a');
                link.href = dataUrl;
                link.download = fileName;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }

            function toggleFullscreen(panel) {
                if (!panel || typeof panel.requestFullscreen !== 'function') {
                    return;
                }

                if (document.fullscreenElement === panel) {
                    if (document.exitFullscreen) {
                        document.exitFullscreen();
                    }
                    return;
                }

                var request = function () {
                    panel.requestFullscreen().catch(function () {
                    });
                };

                if (document.fullscreenElement && document.exitFullscreen) {
                    var exitResult = document.exitFullscreen();
                    if (exitResult && typeof exitResult.then === 'function') {
                        exitResult.then(request).catch(request);
                    } else {
                        request();
                    }
                    return;
                }

                request();
            }

            function updateFullscreenButtons() {
                var panels = document.querySelectorAll('.chart-panel');
                panels.forEach(function (panel) {
                    var expandButton = panel.querySelector('[data-chart-action="expand"]');
                    if (!expandButton) {
                        return;
                    }
                    var expanded = document.fullscreenElement === panel;
                    expandButton.textContent = expanded ? 'Reduire' : 'Plein ecran';
                });
            }

            async function downloadPanelAsPng(panel) {
                if (!panel) {
                    return;
                }

                var fileName = slugifyFileName(chartPanelTitle(panel)) + '.png';
                var background = isDark ? '#0f172a' : '#ffffff';

                if (typeof window.html2canvas === 'function') {
                    try {
                        var captureCanvas = await window.html2canvas(panel, {
                            backgroundColor: background,
                            scale: Math.max(2, Math.ceil(window.devicePixelRatio || 1)),
                            useCORS: true,
                        });
                        downloadDataUrl(captureCanvas.toDataURL('image/png'), fileName);
                        return;
                    } catch (error) {
                    }
                }

                var canvas = panel.querySelector('canvas');
                if (canvas && typeof canvas.toDataURL === 'function') {
                    downloadDataUrl(canvas.toDataURL('image/png'), fileName);
                }
            }

            function installChartPanelActions() {
                var selectors = '.chart-canvas, .heatmap-wrap, .gantt-list, .treemap-wrap, .gauge-grid';
                var panels = document.querySelectorAll('.chart-panel');

                panels.forEach(function (panel) {
                    if (panel.querySelector('.chart-actions')) {
                        return;
                    }

                    panel.setAttribute('data-chart-title', chartPanelTitle(panel));
                    panel.setAttribute('tabindex', '0');

                    var actions = document.createElement('div');
                    actions.className = 'chart-actions';

                    var expandButton = document.createElement('button');
                    expandButton.type = 'button';
                    expandButton.className = 'chart-action-btn';
                    expandButton.setAttribute('data-chart-action', 'expand');
                    expandButton.textContent = 'Plein ecran';

                    var downloadButton = document.createElement('button');
                    downloadButton.type = 'button';
                    downloadButton.className = 'chart-action-btn';
                    downloadButton.setAttribute('data-chart-action', 'download');
                    downloadButton.textContent = 'Telecharger';

                    expandButton.addEventListener('click', function (event) {
                        event.stopPropagation();
                        toggleFullscreen(panel);
                    });

                    downloadButton.addEventListener('click', function (event) {
                        event.stopPropagation();
                        downloadPanelAsPng(panel);
                    });

                    actions.appendChild(expandButton);
                    actions.appendChild(downloadButton);
                    panel.insertBefore(actions, panel.firstChild);

                    var clickArea = panel.querySelector(selectors);
                    if (clickArea) {
                        clickArea.classList.add('chart-zoom-target');
                        clickArea.addEventListener('click', function (event) {
                            if (event.target && event.target.closest('a,button,input,select,textarea,label')) {
                                return;
                            }
                            toggleFullscreen(panel);
                        });
                    }

                    panel.addEventListener('keydown', function (event) {
                        if (event.key === 'Enter') {
                            toggleFullscreen(panel);
                        }
                    });
                });

                updateFullscreenButtons();
            }

            function renderReporting() {
                if (typeof window.Chart === 'undefined') {
                    return;
                }

                var funnelCtx = document.getElementById('chart-funnel');
                if (funnelCtx) {
                    new Chart(funnelCtx, {
                        type: 'bar',
                        data: {
                            labels: charts.funnel.labels,
                            datasets: [{
                                label: 'Volumes',
                                data: charts.funnel.values,
                                backgroundColor: ['#0ea5e9', '#0284c7', '#2563eb', '#312e81'],
                                borderRadius: 8,
                            }],
                        },
                        options: baseOptions(),
                    });
                }

                var statusCtx = document.getElementById('chart-status-by-unit');
                if (statusCtx) {
                    var statusDatasets = (charts.status_by_unit.datasets || []).map(function (set, idx) {
                        return {
                            label: set.label,
                            data: set.data,
                            backgroundColor: palette[idx % palette.length],
                            borderRadius: 6,
                            stack: 'status',
                        };
                    });

                    var statusOptions = baseOptions();
                    statusOptions.scales.x.stacked = true;
                    statusOptions.scales.y.stacked = true;

                    new Chart(statusCtx, {
                        type: 'bar',
                        data: {
                            labels: charts.status_by_unit.labels,
                            datasets: statusDatasets,
                        },
                        options: statusOptions,
                    });
                }

                var progressCtx = document.getElementById('chart-progress-weekly');
                if (progressCtx) {
                    new Chart(progressCtx, {
                        type: 'line',
                        data: {
                            labels: charts.progress_weekly.labels,
                            datasets: [{
                                label: 'Progression reelle',
                                data: charts.progress_weekly.reel,
                                borderColor: '#0ea5e9',
                                backgroundColor: 'rgba(14,165,233,0.20)',
                                tension: 0.3,
                                fill: true,
                            }, {
                                label: 'Progression theorique',
                                data: charts.progress_weekly.theorique,
                                borderColor: '#9333ea',
                                backgroundColor: 'rgba(147,51,234,0.15)',
                                tension: 0.3,
                                fill: true,
                            }],
                        },
                        options: baseOptions(),
                    });
                }

                var kpiCtx = document.getElementById('chart-kpi-trend');
                if (kpiCtx) {
                    new Chart(kpiCtx, {
                        type: 'line',
                        data: {
                            labels: charts.kpi_trend.labels,
                            datasets: [{
                                label: 'Valeur mesuree',
                                data: charts.kpi_trend.valeurs,
                                borderColor: '#0f766e',
                                backgroundColor: 'rgba(15,118,110,0.18)',
                                fill: true,
                                tension: 0.25,
                            }, {
                                label: 'Cible',
                                data: charts.kpi_trend.cibles,
                                borderColor: '#2563eb',
                                borderDash: [6, 4],
                                tension: 0.2,
                            }, {
                                label: 'Seuil alerte',
                                data: charts.kpi_trend.seuils,
                                borderColor: '#dc2626',
                                borderDash: [3, 4],
                                tension: 0.2,
                            }],
                        },
                        options: baseOptions(),
                    });
                }

                var interannualCtx = document.getElementById('chart-interannual');
                if (interannualCtx) {
                    var interannualOptions = baseOptions();
                    interannualOptions.scales.y1 = {
                        position: 'right',
                        min: 0,
                        max: 100,
                        ticks: { color: textColor },
                        grid: { drawOnChartArea: false, color: gridColor },
                    };

                    new Chart(interannualCtx, {
                        data: {
                            labels: charts.interannual_overview.labels,
                            datasets: [{
                                type: 'bar',
                                label: 'Actions total',
                                data: charts.interannual_overview.actions_total,
                                backgroundColor: '#0ea5e9',
                                borderRadius: 6,
                                yAxisID: 'y',
                            }, {
                                type: 'bar',
                                label: 'Actions validees',
                                data: charts.interannual_overview.actions_validees,
                                backgroundColor: '#22c55e',
                                borderRadius: 6,
                                yAxisID: 'y',
                            }, {
                                type: 'line',
                                label: 'Progression moyenne (%)',
                                data: charts.interannual_overview.progression_moyenne,
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245,158,11,0.18)',
                                tension: 0.3,
                                yAxisID: 'y1',
                            }],
                        },
                        options: interannualOptions,
                    });
                }

                var paretoCtx = document.getElementById('chart-risk-pareto');
                if (paretoCtx) {
                    var paretoOptions = baseOptions();
                    paretoOptions.scales.y1 = {
                        position: 'right',
                        min: 0,
                        max: 100,
                        ticks: { color: textColor },
                        grid: { drawOnChartArea: false, color: gridColor },
                    };

                    new Chart(paretoCtx, {
                        data: {
                            labels: charts.risk_pareto.labels,
                            datasets: [{
                                type: 'bar',
                                label: 'Occurrences',
                                data: charts.risk_pareto.counts,
                                backgroundColor: '#0ea5e9',
                                borderRadius: 6,
                                yAxisID: 'y',
                            }, {
                                type: 'line',
                                label: 'Cumul (%)',
                                data: charts.risk_pareto.cumulative_pct,
                                borderColor: '#dc2626',
                                backgroundColor: 'rgba(220,38,38,0.20)',
                                tension: 0.3,
                                yAxisID: 'y1',
                            }],
                        },
                        options: paretoOptions,
                    });
                }

                var topRiskCtx = document.getElementById('chart-top-risks');
                if (topRiskCtx) {
                    var topRiskOptions = baseOptions();
                    topRiskOptions.indexAxis = 'y';

                    new Chart(topRiskCtx, {
                        type: 'bar',
                        data: {
                            labels: charts.top_risks.labels,
                            datasets: [{
                                label: 'Score de risque',
                                data: charts.top_risks.scores,
                                backgroundColor: '#ef4444',
                                borderRadius: 8,
                            }],
                        },
                        options: topRiskOptions,
                    });
                }

                (charts.performance_gauge.labels || []).forEach(function (label, index) {
                    var gaugeId = 'chart-gauge-' + index;
                    var gaugeCanvas = document.getElementById(gaugeId);
                    if (!gaugeCanvas) {
                        return;
                    }
                    var value = Number(charts.performance_gauge.values[index] || 0);
                    value = Math.max(0, Math.min(100, value));
                    new Chart(gaugeCanvas, {
                        type: 'doughnut',
                        data: {
                            labels: ['Performance', 'Reste'],
                            datasets: [{
                                data: [value, 100 - value],
                                backgroundColor: ['#0ea5e9', 'rgba(148,163,184,0.22)'],
                                borderWidth: 0,
                            }],
                        },
                        options: {
                            maintainAspectRatio: false,
                            cutout: '76%',
                            rotation: -90,
                            circumference: 180,
                            plugins: {
                                legend: { display: false },
                                tooltip: { enabled: false },
                            },
                        },
                    });
                });

                installChartPanelActions();
                document.addEventListener('fullscreenchange', function () {
                    updateFullscreenButtons();
                    setTimeout(resizeAllCharts, 120);
                });
                window.addEventListener('resize', function () {
                    setTimeout(resizeAllCharts, 60);
                });
            }

            document.addEventListener('anbg:reporting-assets-ready', renderReporting, { once: true });

            if (typeof window.Chart !== 'undefined') {
                renderReporting();
            }
        })();
    </script>
@endpush
