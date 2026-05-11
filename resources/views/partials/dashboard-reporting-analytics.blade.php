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
            <span class="anbg-badge anbg-badge-success px-3 py-1">Actions validees</span>
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
                    <h2 class="showcase-panel-title">Entonnoir PAS - PAO - PTA - Actions</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-funnel-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Statuts empiles par {{ strtolower($reportingCharts['status_by_unit']['unit_label'] ?? 'unite') }}</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-status-unit-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Avancement réel vs théorique</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-progress-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Indicateurs suivis: valeur, cible, seuil</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-kpi-trend-chart" class="dashboard-chart-host"></div></div>
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Evolution interannuelle</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-interannual-chart" class="dashboard-chart-host"></div></div>
        </article>

    </div>

    <div class="mt-4 space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Heatmap des retards</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-heatmap-chart" class="dashboard-chart-host"></div></div>
        </article>

    </div>

    <div class="mt-4 space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Gantt des actions critiques</h2>
                </div>
            </div>
            @if (($criticalGantt['items'] ?? []) !== [])
                <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-critical-gantt-chart" class="dashboard-chart-host"></div></div>
            @else
                <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune action critique détectée.</div>
            @endif
        </article>

        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Treemap ressources et budget</h2>
                </div>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg"><div id="dashboard-report-treemap-chart" class="dashboard-chart-host"></div></div>
        </article>
    </div>

        <article class="dashboard-advanced-card mt-4">
        <div class="dashboard-advanced-head">
            <div>
                <h2 class="showcase-panel-title">Jauges de performance par {{ strtolower($performanceGaugeScopeLabel) }}</h2>
            </div>
        </div>
        <div class="dashboard-gauge-grid">
            @forelse ($performanceGauge['labels'] as $index => $label)
                <article class="dashboard-gauge-card">
                    <strong>{{ $label }}</strong>
                    <div class="dashboard-gauge-canvas">
                        <div id="dashboard-report-gauge-{{ $index }}" class="dashboard-chart-host"></div>
                    </div>
                    <p>{{ number_format((float) ($performanceGauge['values'][$index] ?? 0), 1, ',', ' ') }}%</p>
                </article>
            @empty
                <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">{{ $performanceGaugeEmptyLabel }}</div>
            @endforelse
        </div>
    </article>
    @endif

    @if ($showTableBlocks)
    <div class="mt-4 space-y-4">
        <article class="dashboard-advanced-card">
            <div class="dashboard-advanced-head">
                <div>
                    <h2 class="showcase-panel-title">Consolidation PAS</h2>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table dashboard-table-compact">
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
                            <tr><td colspan="9">Aucune consolidation disponible.</td></tr>
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
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune vue statutaire disponible.</div>
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
            <div class="overflow-x-auto">
                <table class="dashboard-table dashboard-table-compact">
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
                            <tr><td colspan="8">Aucune comparaison disponible.</td></tr>
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
                        <p class="mt-2 text-sm text-[#667085]">{{ $row['description_actions_detaillees'] ?: 'Aucune description detaillee.' }}</p>
                        <div class="mt-3 grid gap-2 md:grid-cols-2">
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Ressources:</strong> {{ $row['ressources_requises'] ?: '-' }}</div>
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Indicateurs:</strong> {{ $row['indicateurs_performance'] ?: '-' }}</div>
                            <div class="text-xs text-[#667085]"><strong class="text-[#17324a]">Cible:</strong> {{ $row['cible'] ?: '-' }}</div>
                        </div>
                    </article>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/90 bg-slate-50/80 px-4 py-12 text-center text-sm text-[#667085]">Aucune extraction de structure disponible.</div>
                @endforelse
            </div>
        </article>
    </div>
    @endif
</div>
