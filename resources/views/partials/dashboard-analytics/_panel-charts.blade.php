<section class="dashboard-tab-panel active" data-dashboard-panel="charts">
    {{-- ╔═══════════════════════════════════════════════════════════════╗
         ║ NOUVELLE DISPOSITION PREMIUM (2026-05-30) — layout Bento     ║
         ║ Rangee 1 : HERO SCORE (large) + 2 jauges KPI (a droite)     ║
         ║ Rangee 2 : Tendance 12 mois (large) + Repartition statuts   ║
         ║ Rangee 3 : Performance par unite + Top actions              ║
         ╚═══════════════════════════════════════════════════════════════╝ --}}

    @php
        $globalScore = (float) ($globalScores['global'] ?? 0);
        $progressionScore = (float) ($globalScores['progression'] ?? 0);
        $scoreTone = $globalScore >= 80 ? '#20C76B' : ($globalScore >= $qualityThreshold ? '#0F5B66' : ($globalScore > 0 ? '#F26522' : '#94A3B8'));
        $scoreToneLabel = $globalScore >= 80 ? 'Excellent' : ($globalScore >= $qualityThreshold ? 'Bon' : ($globalScore > 0 ? 'A surveiller' : 'Non evalue'));
        $directionPerformanceFallbackRows = collect($directionPerformanceRows);
        $servicePerformanceFallbackRows = collect($synthesisServiceRows);
        $directionPerformanceChartHeight = max(20, ($directionPerformanceFallbackRows->count() * 2.25) + 4);
        $servicePerformanceChartHeight = max(20, ($servicePerformanceFallbackRows->count() * 2.25) + 4);
        $unitSummaryChartHeight = max(15, (count($unitRows) * 2) + 4);
        $unitModeKey = \Illuminate\Support\Str::ascii(mb_strtolower((string) $unitModeLabel));
        $showUnitSummaryChart = ! in_array($unitModeKey, ['services', 'directions'], true);
        $showAgentPerformanceCharts = ! in_array((string) $dashboardRole, ['agent'], true);
    @endphp

    {{-- ─── RANGEE 1 : HERO SCORE + JAUGES KPI ─────────────────────── --}}
    <div class="charts-bento charts-bento-row-hero mb-4">
        <article class="showcase-panel charts-hero-panel" style="--tone: {{ $scoreTone }};">
            <div class="charts-hero-head">
                <span class="charts-hero-eyebrow">Score global pondéré</span>
                <span class="charts-hero-tone-badge" style="background: {{ $scoreTone }};">{{ $scoreToneLabel }}</span>
            </div>
            <div class="charts-hero-value-block">
                <span class="charts-hero-value">{{ number_format($globalScore, 0, ',', ' ') }}</span>
                <span class="charts-hero-unit">/100</span>
            </div>
            <div class="charts-hero-progress-track">
                <div class="charts-hero-progress-fill" style="width: {{ min(100, max(0, $globalScore)) }}%; background: linear-gradient(90deg, {{ $scoreTone }}, {{ $scoreTone }}cc);"></div>
                <div class="charts-hero-progress-threshold" style="left: {{ $qualityThreshold }}%;" title="Seuil moyen de qualité : {{ number_format($qualityThreshold, 0, ',', ' ') }}"></div>
            </div>
            <div class="charts-hero-meta">
                <div class="charts-hero-meta-cell">
                    <span class="charts-hero-meta-label">Progression moy.</span>
                    <span class="charts-hero-meta-value">{{ number_format($progressionScore, 0, ',', ' ') }}%</span>
                </div>
                <div class="charts-hero-meta-cell">
                    <span class="charts-hero-meta-label">Seuil qualité</span>
                    <span class="charts-hero-meta-value">{{ number_format($qualityThreshold, 0, ',', ' ') }}</span>
                </div>
            </div>
            {{-- Mini-sparkline si on a des donnees mensuelles --}}
            @if ($monthlyOfficial !== [])
                <div class="charts-hero-sparkline" aria-hidden="true">
                    <svg viewBox="0 0 200 40" preserveAspectRatio="none">
                        <path d="M {{ $smoothPath($chartFallbackPoints($monthlyOfficial, 'global')) }}" fill="none" stroke="{{ $scoreTone }}" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" opacity="0.85" />
                    </svg>
                </div>
            @endif
        </article>

        <article class="showcase-panel charts-gauges-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">KPI</h2>
                <span class="showcase-chip">Délai · Performance</span>
            </div>
            <div class="charts-gauges-grid">
                @foreach ([['key' => 'delai', 'label' => $metricLabel('delai')],['key' => 'performance', 'label' => $metricLabel('performance')]] as $gauge)
                    @php
                        $gaugeValue = min(100, max(0, (float) ($globalScores[$gauge['key']] ?? 0)));
                        $gaugeTone = $gaugeValue >= 80 ? '#20C76B' : ($gaugeValue >= $qualityThreshold ? '#0F5B66' : ($gaugeValue > 0 ? '#F26522' : '#94A3B8'));
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
    </div>

    {{-- ─── RANGEE 2 : TENDANCE MENSUELLE + REPARTITION STATUTS ─── --}}
    <div class="charts-status-section mb-4">
        <article class="showcase-panel charts-status-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Statuts</h2>
                <span class="showcase-chip">{{ collect($statusCards)->sum('count') }} actions</span>
            </div>
            @if (! empty($statusCards) && collect($statusCards)->sum('count') > 0)
                <div class="dashboard-canvas">
                    <div id="dashboard-status-mix-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true"></div>
                    </div>
                </div>
            @else
                <x-ui.empty-state title="Aucun statut à afficher" message="Importez des actions pour voir la répartition." icon="chart" tone="info" />
            @endif
        </article>
    </div>

    @if ($showAgentPerformanceCharts)
        <section class="charts-advanced-section mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Performance agents</h2>
                <span class="showcase-chip">{{ (int) ($agentPerformanceSummary['agents_total'] ?? 0) }} agents</span>
            </div>
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <article class="showcase-panel">
                    <div class="chart-panel-head mb-3">
                        <h3 class="chart-title">Synthese</h3>
                    </div>
                    <div id="dashboard-agent-gauge" class="dashboard-chart-host dashboard-chart-host-compact">
                        <div class="dashboard-chart-fallback">
                            <div class="charts-unit-bars">
                                <div class="charts-unit-bar">
                                    <span class="charts-unit-bar-label">Agents</span>
                                    <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ min(100, max(0, (float) ($agentPerformanceSummary['agents_total'] ?? 0) * 10)) }}%; background:#0F5B66;"></span></span>
                                    <span class="charts-unit-bar-value">{{ (int) ($agentPerformanceSummary['agents_total'] ?? 0) }}</span>
                                </div>
                                <div class="charts-unit-bar">
                                    <span class="charts-unit-bar-label">Actions</span>
                                    <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ min(100, max(0, (float) ($agentPerformanceSummary['actions_assigned'] ?? 0) * 5)) }}%; background:#3996D3;"></span></span>
                                    <span class="charts-unit-bar-value">{{ (int) ($agentPerformanceSummary['actions_assigned'] ?? 0) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="showcase-panel">
                    <div class="chart-panel-head mb-3">
                        <h3 class="chart-title">Top agents</h3>
                    </div>
                    <div id="dashboard-agent-top" class="dashboard-chart-host dashboard-chart-host-compact">
                        <div class="dashboard-chart-fallback">
                            <div class="charts-unit-bars">
                                @forelse (array_slice($agentPerformanceTopRows, 0, 4) as $row)
                                    <a class="charts-unit-bar" href="{{ $row['url'] ?? route('workspace.actions.index') }}">
                                        <span class="charts-unit-bar-label">{{ $row['agent'] ?? 'Agent' }}</span>
                                        <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ min(100, max(0, (float) ($row['execution_rate'] ?? 0))) }}%; background:#20C76B;"></span></span>
                                        <span class="charts-unit-bar-value">{{ (int) ($row['actions_assigned'] ?? 0) }}</span>
                                    </a>
                                @empty
                                    <x-ui.empty-state title="Aucun agent" message="Les donnees apparaitront apres consolidation." icon="user" tone="info" />
                                @endforelse
                            </div>
                        </div>
                    </div>
                </article>

                <article class="showcase-panel">
                    <div class="chart-panel-head mb-3">
                        <h3 class="chart-title">Charge</h3>
                    </div>
                    <div id="dashboard-agent-3d" class="dashboard-chart-host dashboard-chart-host-compact">
                        <div class="dashboard-chart-fallback">
                            <div class="charts-unit-bars">
                                <div class="charts-unit-bar">
                                    <span class="charts-unit-bar-label">Cloturees</span>
                                    <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ min(100, max(0, (float) ($agentPerformanceSummary['actions_closed'] ?? 0) * 5)) }}%; background:#20C76B;"></span></span>
                                    <span class="charts-unit-bar-value">{{ (int) ($agentPerformanceSummary['actions_closed'] ?? 0) }}</span>
                                </div>
                                <div class="charts-unit-bar">
                                    <span class="charts-unit-bar-label">En retard</span>
                                    <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ min(100, max(0, (float) ($agentPerformanceSummary['actions_late'] ?? 0) * 5)) }}%; background:#B42318;"></span></span>
                                    <span class="charts-unit-bar-value">{{ (int) ($agentPerformanceSummary['actions_late'] ?? 0) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="showcase-panel">
                    <div class="chart-panel-head mb-3">
                        <h3 class="chart-title">Alertes</h3>
                    </div>
                    <div id="dashboard-agent-heatmap" class="dashboard-chart-host dashboard-chart-host-compact">
                        <div class="dashboard-chart-fallback">
                            @if ($agentPerformanceAlerts !== [])
                                <div class="charts-unit-bars">
                                    @foreach (array_slice($agentPerformanceAlerts, 0, 4) as $alert)
                                        <a class="charts-unit-bar" href="{{ $alert['url'] ?? route('workspace.actions.index') }}">
                                            <span class="charts-unit-bar-label">{{ $alert['title'] ?? 'Alerte agent' }}</span>
                                            <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: 100%; background:#F26522;"></span></span>
                                            <span class="charts-unit-bar-value">!</span>
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <x-ui.empty-state title="Aucune alerte agent" message="Aucun signal critique detecte." icon="check" tone="success" />
                            @endif
                        </div>
                    </div>
                </article>
            </div>
        </section>
    @endif

    @php
        $ptaQuarterlyCharts = (array) ($ptaQuarterlyAnalysis['charts'] ?? []);
        $ptaAxisRates = (array) ($ptaQuarterlyCharts['axis_rates'] ?? ['labels' => [], 'values' => []]);
        $ptaMonthlyRates = (array) ($ptaQuarterlyCharts['monthly_rates'] ?? ['labels' => [], 'values' => []]);
        $ptaChartSets = collect([$ptaAxisRates, $ptaMonthlyRates]);
        $showPtaQuarterlyCharts = $ptaChartSets->contains(
            fn (array $chart): bool => count((array) ($chart['labels'] ?? [])) > 0
                && collect((array) ($chart['values'] ?? []))->sum() > 0
        );
        $ptaFallbackBars = static function (array $chart): array {
            $labels = collect($chart['labels'] ?? [])->values();
            $values = collect($chart['values'] ?? [])->values();

            return $labels
                ->map(fn ($label, int $index): array => [
                    'label' => (string) $label,
                    'value' => min(100, max(0, (float) ($values[$index] ?? 0))),
                ])
                ->all();
        };
    @endphp

    @if ($showPtaQuarterlyCharts)
        <section class="charts-advanced-section mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">PTA trimestriel</h2>
                <span class="showcase-chip">{{ $ptaQuarterlyAnalysis['period']['label'] ?? 'Période courante' }}</span>
            </div>
            <div class="charts-evolution-stack">
                @if (count((array) ($ptaAxisRates['labels'] ?? [])) > 0)
                    <article class="showcase-panel charts-evolution-panel">
                        <div class="chart-panel-head mb-3">
                            <h3 class="chart-title">Evolution des axes PTA</h3>
                            <span class="showcase-chip">{{ count((array) ($ptaAxisRates['labels'] ?? [])) }} axes</span>
                        </div>
                        <div class="dashboard-chart-scroll-frame">
                            <div class="dashboard-canvas dashboard-canvas-lg dashboard-canvas-evolution">
                                <div id="dashboard-pta-axis-rate-chart" class="dashboard-chart-host">
                                    <div class="dashboard-chart-fallback" aria-hidden="true">
                                        <div class="charts-unit-bars">
                                            @foreach ($ptaFallbackBars($ptaAxisRates) as $row)
                                                @php
                                                    $value = (float) $row['value'];
                                                    $tone = $value >= 80 ? '#20C76B' : ($value >= $qualityThreshold ? '#0F5B66' : ($value > 0 ? '#F26522' : '#94A3B8'));
                                                @endphp
                                                <div class="charts-unit-bar">
                                                    <span class="charts-unit-bar-label">{{ $row['label'] }}</span>
                                                    <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ $value }}%; background: linear-gradient(90deg, {{ $tone }}, {{ $tone }}cc);"></span></span>
                                                    <span class="charts-unit-bar-value" style="color: {{ $tone }};">{{ number_format($value, 0, ',', ' ') }}%</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                @endif

                @if (count((array) ($ptaMonthlyRates['labels'] ?? [])) > 0)
                    <article class="showcase-panel charts-evolution-panel">
                        <div class="chart-panel-head mb-3">
                            <h3 class="chart-title">Evolution PTA</h3>
                            <span class="showcase-chip">{{ count((array) ($ptaMonthlyRates['labels'] ?? [])) }} points</span>
                        </div>
                        <div class="dashboard-canvas dashboard-canvas-lg dashboard-canvas-evolution">
                            <div id="dashboard-pta-monthly-rate-chart" class="dashboard-chart-host">
                                <div class="dashboard-chart-fallback" aria-hidden="true">
                                    <div class="charts-unit-bars">
                                        @foreach ($ptaFallbackBars($ptaMonthlyRates) as $row)
                                            @php
                                                $value = (float) $row['value'];
                                                $tone = $value >= 80 ? '#20C76B' : ($value >= $qualityThreshold ? '#0F5B66' : ($value > 0 ? '#F26522' : '#94A3B8'));
                                            @endphp
                                            <div class="charts-unit-bar">
                                                <span class="charts-unit-bar-label">{{ $row['label'] }}</span>
                                                <span class="charts-unit-bar-track"><span class="charts-unit-bar-fill" style="width: {{ $value }}%; background: linear-gradient(90deg, {{ $tone }}, {{ $tone }}cc);"></span></span>
                                                <span class="charts-unit-bar-value" style="color: {{ $tone }};">{{ number_format($value, 0, ',', ' ') }}%</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                @endif
            </div>
        </section>
    @endif
    @if ($showDashboardAdvancedReporting)
        <section class="charts-advanced-section mt-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div><h2 class="showcase-panel-title">Analyse</h2></div>
                <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports</a>
            </div>
            @include('partials.dashboard-reporting-analytics', [
                'reportingAnalytics' => $reportingAnalytics ?? [],
                'displayMode' => 'charts',
            ])
        </section>
    @endif
</section>
