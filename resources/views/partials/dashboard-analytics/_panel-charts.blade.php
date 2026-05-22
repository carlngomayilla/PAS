<section class="dashboard-tab-panel active" data-dashboard-panel="charts">
    @if ($showRoleOverview)
        @include('partials.dashboard-role-overview', [
            'roleDashboard' => $roleDashboard,
            'dashboardRole' => $dashboardRole,
            'statisticalPolicy' => $statisticalPolicy,
            'officialPolicy' => $officialPolicy,
            'displayMode' => 'charts',
        ])
    @endif

    @if ($showDirectionSynthesisSelector)
        <section class="mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Graphiques de decision</h2>
                <span class="showcase-chip">Services, agents et evolution</span>
            </div>
            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ($decisionCharts as $chart)
                    <article class="showcase-panel dashboard-synthesis-card">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $chart['title'] }}</h3>
                        </div>
                        <div class="space-y-3">
                            @forelse (($chart['rows'] ?? []) as $row)
                                @php
                                    $barValue = min(100, max(0, (float) ($row['value'] ?? 0)));
                                    $barColor = (string) ($row['color'] ?? '#3996D3');
                                @endphp
                                <div>
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs font-semibold text-[#17324a]">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="whitespace-nowrap">{{ number_format($barValue, 1, ',', ' ') }}%</span>
                                    </div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-200/80">
                                        <div class="h-full rounded-full" style="width: {{ $barValue }}%; background: {{ $barColor }};"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] font-medium text-[#667085]">{{ $row['meta'] ?? '' }}</p>
                                </div>
                            @empty
                                <x-ui.empty-state
                                    title="Aucune donnée"
                                    message="Aucune donnée disponible."
                                    icon="chart"
                                    tone="info"
                                />
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>

            @php
                $curveRows = collect($decisionQuarterRows)->values();
                $curveSteps = max(1, $curveRows->count() - 1);
                $executionCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['taux_execution'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
                    })
                    ->implode(' ');
                $scoreCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['score'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 1, '.', '').','.number_format($y, 1, '.', '');
                    })
                    ->implode(' ');
            @endphp
            <article class="showcase-panel mt-3">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-black text-[#17324a]">Courbes d'evolution trimestrielle</h3>
                    <span class="showcase-chip">{{ $exerciseFilter['label'] ?? 'Exercice courant' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <svg class="min-w-[520px]" viewBox="0 0 360 150" role="img" aria-label="Courbes trimestrielles">
                        <line x1="24" y1="118" x2="336" y2="118" stroke="#d8ecf8" stroke-width="1" />
                        <line x1="24" y1="28" x2="336" y2="28" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                        <polyline points="{{ $executionCurvePoints }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="{{ $scoreCurvePoints }}" fill="none" stroke="#8FC043" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($curveRows as $row)
                            @php
                                $x = 24 + (($loop->index * 312) / $curveSteps);
                                $executionY = 118 - (min(100, max(0, (float) ($row['taux_execution'] ?? 0))) * 0.9);
                                $scoreY = 118 - (min(100, max(0, (float) ($row['score'] ?? 0))) * 0.9);
                            @endphp
                            <circle cx="{{ $x }}" cy="{{ $executionY }}" r="4" fill="#3996D3" />
                            <circle cx="{{ $x }}" cy="{{ $scoreY }}" r="4" fill="#8FC043" />
                            <text x="{{ $x }}" y="140" text-anchor="middle" font-size="10" font-weight="800" fill="#667085">{{ $row['trimestre'] ?? '-' }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold text-[#667085]">
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#3996D3]"></i>Taux d'exécution</span>
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#8FC043]"></i>Score</span>
                </div>
            </article>
        </section>
    @endif

    {{-- Rangée 1 : Jauges (compact 4-col) + Score global côte à côte --}}
    <div class="charts-row-2 mb-4">
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Indicateurs KPI</h2>
                <span class="showcase-chip">Survolez pour les détails</span>
            </div>
            <div class="dashboard-gauge-grid-4">
                @foreach ([['key' => 'delai', 'label' => $metricLabel('delai')],['key' => 'performance', 'label' => $metricLabel('performance')],['key' => 'conformite', 'label' => $metricLabel('conformite')]] as $gauge)
                    @php
                        $gaugeValue = min(100, max(0, (float) ($globalScores[$gauge['key']] ?? 0)));
                        $gaugeTone = $gaugeValue >= 80 ? '#8FC043' : ($gaugeValue >= 60 ? '#3996D3' : '#F9B13C');
                    @endphp
                    <div class="dashboard-gauge-item">
                        <div id="dashboard-kpi-gauge-{{ $gauge['key'] }}" class="dashboard-chart-host">
                            <div class="dashboard-gauge-fallback" style="--value-pct: {{ $gaugeValue }}%; --tone: {{ $gaugeTone }};" aria-hidden="true">
                                <span class="dashboard-gauge-fallback-ring">
                                    <span class="dashboard-gauge-fallback-value">{{ number_format($gaugeValue, 0, ',', ' ') }}%</span>
                                </span>
                                <span class="dashboard-gauge-fallback-label">{{ $gauge['label'] }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="showcase-panel charts-score-side">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Score global</h2>
                <span class="showcase-chip">Seuil 60</span>
            </div>
            <div class="charts-score-block">
                <p class="charts-score-value">{{ number_format((float) ($globalScores['global'] ?? 0), 1, ',', ' ') }}</p>
                <p class="charts-score-label">{{ $metricLabel('global') }}</p>
                <div class="charts-score-bar mt-3">
                    <div class="charts-score-fill" style="width: {{ min(100, max(0, (float) ($globalScores['global'] ?? 0))) }}%;"></div>
                </div>
                <p class="charts-score-meta mt-2">Progression moy. : {{ number_format((float) ($globalScores['progression'] ?? 0), 1, ',', ' ') }}%</p>
            </div>
            <div class="charts-status-list mt-3">
                @foreach ($statusCards as $card)
                    <div class="charts-status-row">
                        <span class="charts-status-dot" style="background: {{ $card['color'] }};"></span>
                        <span class="charts-status-name">{{ $card['label'] }}</span>
                        <span class="charts-status-count" style="color: {{ $card['color'] }};">{{ $card['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </article>
    </div>

    {{-- Rangée 2 : Courbe KPI mensuelle pleine largeur --}}
    <article class="showcase-panel mb-4">
        <div class="chart-panel-head mb-3">
            <h2 class="chart-title">Évolution mensuelle des indicateurs</h2>
            <div class="chart-period-bar" data-period-chart="kpi-line">
                <button type="button" class="chart-period-btn" data-period="3">3M</button>
                <button type="button" class="chart-period-btn" data-period="6">6M</button>
                <button type="button" class="chart-period-btn" data-period="12">12M</button>
                <button type="button" class="chart-period-btn active" data-period="0">Tout</button>
            </div>
        </div>
        <div class="dashboard-canvas">
            <div id="dashboard-kpi-line-chart" class="dashboard-chart-host">
                <div class="dashboard-chart-fallback" aria-hidden="true">
                    @if ($monthlyOfficial !== [])
                        <svg viewBox="0 0 360 140" preserveAspectRatio="none">
                            <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                            <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                            <polyline points="{{ $chartFallbackPoints($monthlyOfficial, 'global') }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            @foreach (collect($monthlyOfficial)->values() as $row)
                                @php
                                    $x = 20 + (($loop->index * 320) / max(1, count($monthlyOfficial) - 1));
                                    $y = 120 - (min(100, max(0, (float) ($row['global'] ?? 0))) * 0.9);
                                @endphp
                                <circle cx="{{ $x }}" cy="{{ $y }}" r="3.5" fill="#3996D3" />
                            @endforeach
                        </svg>
                    @else
                        <div class="dashboard-chart-empty">Aucune donnée disponible pour ce graphique.</div>
                    @endif
                </div>
            </div>
        </div>
    </article>

    {{-- Rangée 3 : Synthèse par unité + Classement Top 6 --}}
    <div class="charts-row-2 mb-4">
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Synthèse par {{ strtolower($unitModeLabel) }}</h2>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-canvas">
                <div id="dashboard-unit-summary-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($unitFallbackRows->isNotEmpty())
                            <div class="dashboard-chart-fallback-bars">
                                @foreach ($unitFallbackRows as $row)
                                    @php $unitValue = min(100, max(0, (float) ($row['kpi_global'] ?? $row['progression_moyenne'] ?? 0))); @endphp
                                    <div class="dashboard-chart-fallback-bar">
                                        <span class="truncate">{{ $row['label'] ?? '-' }}</span>
                                        <span class="dashboard-chart-fallback-track">
                                            <span class="dashboard-chart-fallback-fill" style="width: {{ $unitValue }}%;"></span>
                                        </span>
                                        <span class="text-right">{{ number_format($unitValue, 1, ',', ' ') }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="dashboard-chart-empty">Aucune donnée disponible pour ce graphique.</div>
                        @endif
                    </div>
                </div>
            </div>
        </article>

        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Classement des actions</h2>
                <span class="showcase-chip">Top {{ count($analytics['top_action_bars'] ?? []) }}</span>
            </div>
            @if ($analytics['top_action_bars'] ?? false)
                <div class="grid gap-2">
                    @foreach ($analytics['top_action_bars'] as $row)
                        <a href="{{ $row['url'] }}" class="dashboard-bullet rounded-xl px-2 py-1.5 transition hover:bg-[#E8F3FB]/70">
                            <span class="truncate text-xs font-semibold text-[#667085]">{{ $row['label'] }}</span>
                            <span class="dashboard-bullet-track">
                                <span class="dashboard-bullet-value" style="width: {{ min(100, max(0, (float) $row['value'])) }}%; background: {{ $row['color'] }};"></span>
                            </span>
                            <span class="text-right text-[11px] font-black" style="color: {{ $row['color'] }};">{{ number_format((float) $row['value'], 1, ',', ' ') }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-ui.empty-state
                    title="Aucune action classée"
                    message="Aucune action classée pour le moment."
                    icon="chart"
                    tone="info"
                />
            @endif
        </article>
    </div>

    @if ($showDashboardAdvancedReporting)
        <section class="mt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Analytique avancée</h2></div>
                <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports</a>
            </div>
            @include('partials.dashboard-reporting-analytics', [
                'reportingAnalytics' => $reportingAnalytics ?? [],
                'displayMode' => 'charts',
            ])
        </section>
    @endif
</section>
