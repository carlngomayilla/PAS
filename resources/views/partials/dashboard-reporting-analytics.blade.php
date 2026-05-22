@php
    $displayMode = $displayMode ?? 'full';
    $showSummary = in_array($displayMode, ['full', 'overview'], true);
    $showChartBlocks = in_array($displayMode, ['full', 'charts'], true);
    $showTableBlocks = in_array($displayMode, ['full', 'tables'], true);
    $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
    $reporting = $reportingAnalytics ?? [];
    $reportingCharts = $reporting['charts'] ?? [];
    $reportingGlobal = $reporting['global'] ?? [];
    $reportingStatuses = $reporting['statuts'] ?? [];
    $reportingAlerts = $reporting['alertes'] ?? [];
    $pasConsolidation = $reporting['pasConsolidation'] ?? [];
    $interannualComparison = $reporting['interannualComparison'] ?? [];
    $reportingDetails = $reporting['details'] ?? ['structure_rapports' => collect()];
    $statisticalPolicy = is_array(($reporting['statisticalPolicy'] ?? null)) ? $reporting['statisticalPolicy'] : [];
    $officialPolicy = is_array(($reporting['officialPolicy'] ?? null)) ? $reporting['officialPolicy'] : [];
    $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
    $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $officialBaseLower = mb_strtolower($officialBaseLabel);
    $officialBaseText = 'Base statistique : '.$officialBaseLabel;

    $heatmap = $reportingCharts['retard_heatmap'] ?? ['weeks' => [], 'units' => [], 'matrix' => [], 'max' => 0];
    $criticalGantt = $reportingCharts['critical_gantt'] ?? ['min' => now()->subDays(14)->toDateString(), 'max' => now()->addDays(14)->toDateString(), 'items' => []];
    $resourceTreemap = $reportingCharts['resource_treemap'] ?? ['labels' => [], 'values' => [], 'total' => 0];
    $performanceGauge = $reportingCharts['performance_gauge'] ?? ['labels' => [], 'values' => []];
    $performanceGaugeScopeLabel = (string) ($performanceGauge['scope_label'] ?? 'Directions');
    $performanceGaugeEmptyLabel = (string) ($performanceGauge['empty_label'] ?? 'Aucune donnée disponible pour les jauges.');
    $structureHighlights = collect($reportingDetails['structure_rapports'] ?? collect())
        ->take(6)
        ->values();
    $managedKpis = collect($reporting['managedKpis'] ?? [])->take(6)->values();
    $reportingFallbackBars = static function (array $chart): array {
        $labels = collect($chart['labels'] ?? [])->values();
        $datasets = collect($chart['datasets'] ?? []);

        return $labels
            ->map(function ($label, int $index) use ($datasets): array {
                $value = $datasets->sum(fn ($dataset): float => (float) (($dataset['data'][$index] ?? 0)));

                return [
                    'label' => (string) $label,
                    'value' => min(100, max(0, $value)),
                ];
            })
            ->take(8)
            ->all();
    };
    $reportingFallbackPoints = static function (array $chart, string $key): string {
        $labels = collect($chart['labels'] ?? [])->values();
        $values = is_array($chart[$key] ?? null) ? $chart[$key] : [];
        $steps = max(1, $labels->count() - 1);

        return $labels
            ->map(function ($label, int $index) use ($values, $steps): string {
                $value = min(100, max(0, (float) ($values[$index] ?? 0)));
                $x = 20 + (($index * 320) / $steps);
                $y = 120 - ($value * 0.9);

                return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
            })
            ->implode(' ');
    };

    $heatMax = max(1, (int) ($heatmap['max'] ?? 0));
    $ganttMin = \Illuminate\Support\Carbon::parse($criticalGantt['min'] ?? now()->subDays(14)->toDateString());
    $ganttMax = \Illuminate\Support\Carbon::parse($criticalGantt['max'] ?? now()->addDays(14)->toDateString());
    $ganttRange = max(1, $ganttMin->diffInDays($ganttMax));
    $treemapTotal = max(0.01, (float) ($resourceTreemap['total'] ?? 0));
    $reportingSummaryCards = [
        ['label' => 'Périmètres PAS', 'value' => $reportingGlobal['pas_total'] ?? 0, 'tone' => 'navy', 'meta' => 'Stratégie couverte', 'href' => route('workspace.pas.index'), 'badge' => null, 'badge_tone' => 'info'],
        ['label' => 'Mesures d\'indicateur', 'value' => $reportingGlobal['kpi_mesures_total'] ?? 0, 'tone' => 'blue', 'meta' => 'Mesures suivies', 'href' => route('workspace.reporting'), 'badge' => null, 'badge_tone' => 'warning'],
        ['label' => 'Alertes retard', 'value' => $reportingAlerts['actions_en_retard'] ?? 0, 'tone' => 'amber', 'meta' => 'Suivi à traiter', 'href' => route('workspace.actions.index', ['statut' => 'en_retard']), 'badge' => null, 'badge_tone' => 'danger'],
        ['label' => 'Indicateurs sous seuil', 'value' => $reportingAlerts['mesures_kpi_sous_seuil'] ?? 0, 'tone' => 'green', 'meta' => 'Mesures critiques', 'href' => route('workspace.alertes', ['niveau' => 'warning', 'limit' => 100]), 'badge' => null, 'badge_tone' => 'success'],
    ];
    $statusCards = collect($reportingStatuses)
        ->map(function (array $rows, string $module): array {
            arsort($rows);
            $topStatus = array_key_first($rows) ?? 'aucun';

            return [
                'module' => strtoupper($module),
                'total' => array_sum($rows),
                'top_status' => $topStatus,
                'top_total' => (int) ($rows[$topStatus] ?? 0),
            ];
        })
        ->values();
@endphp

<div class="dashboard-advanced-shell">
    @if ($showSummary)
        <div class="flex flex-wrap gap-2">
            <span class="anbg-badge anbg-badge-success px-3 py-1">Actions validées</span>
            <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $officialBaseText }}</span>
        </div>

        <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
            @foreach ($reportingSummaryCards as $card)
                <x-stat-card-link
                    :href="$card['href']"
                    :label="$card['label']"
                    :value="$card['value']"
                    :meta="$card['meta']"
                    :badge="$card['badge']"
                    :badge-tone="$card['badge_tone']"
                    card-class="dashboard-advanced-kpi dashboard-advanced-kpi-{{ $card['tone'] }}"
                    label-class="dashboard-summary-label"
                    value-class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none"
                    meta-class="dashboard-summary-meta mt-2 text-xs"
                />
            @endforeach
        </div>

        @if ($managedKpis->isNotEmpty())
            <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                @foreach ($managedKpis as $metric)
                    <x-stat-card-link
                        :href="route('workspace.super-admin.kpis.edit')"
                        :label="$metric['label']"
                        :value="number_format((float) ($metric['value'] ?? 0), 1)"
                        :meta="collect([
                            ($metric['description'] ?? '') !== '' ? $metric['description'] : null,
                            $metric['formula_summary'] ?? null,
                            'Poids '.($metric['weight'] ?? 0),
                        ])->filter()->implode(' | ')"
                        badge="Actif"
                        :badge-tone="$metric['tone'] === 'success' ? 'success' : ($metric['tone'] === 'warning' ? 'warning' : 'danger')"
                        card-class="dashboard-advanced-kpi dashboard-advanced-kpi-navy"
                        label-class="dashboard-summary-label"
                        value-class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none"
                        meta-class="dashboard-summary-meta mt-2 text-xs"
                    />
                @endforeach
            </div>
        @endif
    @endif

    @if ($showChartBlocks)
    <div class="space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Statuts empiles par {{ strtolower($reportingCharts['status_by_unit']['unit_label'] ?? 'unite') }}</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg">
                <div id="dashboard-report-status-unit-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        <div class="dashboard-chart-fallback-bars">
                            @foreach ($reportingFallbackBars($reportingCharts['status_by_unit'] ?? []) as $row)
                                <div class="dashboard-chart-fallback-bar">
                                    <span class="truncate">{{ $row['label'] }}</span>
                                    <span class="dashboard-chart-fallback-track"><span class="dashboard-chart-fallback-fill" style="width: {{ $row['value'] }}%;"></span></span>
                                    <span class="text-right">{{ number_format($row['value'], 0, ',', ' ') }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Avancement réel vs théorique</h2>
                </div>
                <div class="chart-period-bar" data-period-chart="report-progress">
                    <button type="button" class="chart-period-btn" data-period="4">4S</button>
                    <button type="button" class="chart-period-btn" data-period="8">8S</button>
                    <button type="button" class="chart-period-btn" data-period="13">3M</button>
                    <button type="button" class="chart-period-btn active" data-period="0">Tout</button>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg">
                <div id="dashboard-report-progress-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        <svg viewBox="0 0 360 140" preserveAspectRatio="none">
                            <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                            <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                            <polyline points="{{ $reportingFallbackPoints($reportingCharts['progress_weekly'] ?? [], 'reel') }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="{{ $reportingFallbackPoints($reportingCharts['progress_weekly'] ?? [], 'theorique') }}" fill="none" stroke="#1C203D" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="7 5" />
                        </svg>
                    </div>
                </div>
            </div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Indicateurs suivis: valeur, cible, seuil</h2>
                </div>
                <div class="chart-period-bar" data-period-chart="report-kpi-trend">
                    <button type="button" class="chart-period-btn" data-period="3">3M</button>
                    <button type="button" class="chart-period-btn" data-period="6">6M</button>
                    <button type="button" class="chart-period-btn" data-period="12">12M</button>
                    <button type="button" class="chart-period-btn active" data-period="0">Tout</button>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg">
                <div id="dashboard-report-kpi-trend-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        <svg viewBox="0 0 360 140" preserveAspectRatio="none">
                            <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                            <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                            <polyline points="{{ $reportingFallbackPoints($reportingCharts['kpi_trend'] ?? [], 'valeurs') }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="{{ $reportingFallbackPoints($reportingCharts['kpi_trend'] ?? [], 'cibles') }}" fill="none" stroke="#8FC043" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="{{ $reportingFallbackPoints($reportingCharts['kpi_trend'] ?? [], 'seuils') }}" fill="none" stroke="#F9B13C" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="7 5" />
                        </svg>
                    </div>
                </div>
            </div>
        </article>

    </div>
    @endif

    @if ($showTableBlocks)
    <div class="mt-4 space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Consolidation PAS</h2>
                </div>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table dashboard-table-compact">
                    <thead>
                        <tr>
                            <th>PAS</th>
                            <th>Période</th>
                            <th>Axes</th>
                            <th>Objectifs</th>
                            <th>PAO</th>
                            <th>PTA</th>
                            <th>Actions</th>
                            <th>Validées</th>
                            <th>Taux</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pasConsolidation as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                <td class="font-semibold">{{ $row['titre'] }}</td>
                                <td>{{ $row['periode'] }}</td>
                                <td>{{ $row['axes_total'] }}</td>
                                <td>{{ $row['objectifs_total'] }}</td>
                                <td>{{ $row['paos_total'] }}</td>
                                <td>{{ $row['ptas_total'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['actions_validees'] }}</td>
                                <td>{{ number_format((float) $row['taux_realisation'], 1, ',', ' ') }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <x-ui.empty-state title="Aucune consolidation" message="Aucune consolidation disponible." icon="file" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Statuts suivis</h2>
                </div>
            </div>
            <div class="grid gap-3">
                @forelse ($statusCards as $card)
                    <div class="dashboard-status-block">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="dashboard-status-block-title mb-1">{{ $card['module'] }}</div>
                                <div class="text-sm font-semibold text-[#17324a]">{{ $card['total'] }} éléments suivis</div>
                            </div>
                            <span class="dashboard-pill">{{ $card['top_total'] }} x {{ $card['top_status'] }}</span>
                        </div>
                    </div>
                @empty
                    <x-ui.empty-state
                        title="Aucune vue statutaire"
                        message="Aucune vue statutaire disponible."
                        icon="chart"
                        tone="info"
                    />
                @endforelse
            </div>
        </article>
    </div>

    <div class="mt-4 space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Comparaison interannuelle détaillée</h2>
                </div>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table dashboard-table-compact">
                    <thead>
                        <tr>
                            <th>Année</th>
                            <th>PAO</th>
                            <th>PTA</th>
                            <th>Actions</th>
                            <th>Validées</th>
                            <th>Retard</th>
                            <th>Progression</th>
                            <th>Taux validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($interannualComparison as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                <td class="font-semibold">{{ $row['annee'] }}</td>
                                <td>{{ $row['paos_total'] }}</td>
                                <td>{{ $row['ptas_total'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['actions_validees'] }}</td>
                                <td>{{ $row['actions_retard'] }}</td>
                                <td>{{ number_format((float) $row['progression_moyenne'], 1, ',', ' ') }}%</td>
                                <td>{{ number_format((float) $row['taux_validation'], 1, ',', ' ') }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    <x-ui.empty-state title="Aucune comparaison" message="Aucune comparaison disponible." icon="chart" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Focus execution</h2>
                </div>
            </div>
            <div class="grid gap-3">
                @forelse ($structureHighlights as $row)
                    <article class="rounded-[1.05rem] border border-slate-200/85 bg-slate-50/90 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <strong class="text-[#17324a]">{{ $row['objectif_operationnel'] ?: '-' }}</strong>
                            <span class="dashboard-pill">{{ $row['etat_realisation'] ?: 'non renseigne' }}</span>
                        </div>
                        <p class="mt-2 text-sm text-[#667085]">{{ $row['description_actions_detaillees'] ?: 'Aucune description détaillée.' }}</p>
                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Ressources:</strong> {{ $row['ressources_requises'] ?: '-' }}</div>
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Indicateurs:</strong> {{ $row['indicateurs_performance'] ?: '-' }}</div>
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Cible:</strong> {{ $row['cible'] ?: '-' }}</div>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state
                        title="Aucune extraction"
                        message="Aucune extraction de structure disponible."
                        icon="file"
                        tone="info"
                    />
                @endforelse
            </div>
        </article>
    </div>
    @endif
</div>
