<section class="dashboard-tab-panel active" data-dashboard-panel="charts">
    @if ($showRoleOverview)
        <div class="charts-role-overview">
            @include('partials.dashboard-role-overview', [
                'roleDashboard' => $roleDashboard,
                'dashboardRole' => $dashboardRole,
                'statisticalPolicy' => $statisticalPolicy,
                'officialPolicy' => $officialPolicy,
                'displayMode' => 'charts',
                'hideRepeatedSupportChart' => true,
            ])
        </div>
    @endif

    @if ($showDirectionSynthesisSelector)
        <section class="charts-decision-section mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Graphiques</h2>
                <span class="showcase-chip">Services, agents, evolution</span>
            </div>
            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ($decisionCharts as $chart)
                    <article class="showcase-panel dashboard-synthesis-card">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $chart['title'] }}</h3>
                        </div>
                        <div class="charts-scroll-list charts-scroll-list-sm space-y-3">
                            @forelse (($chart['rows'] ?? []) as $row)
                                @php
                                    $barValue = min(100, max(0, (float) ($row['value'] ?? 0)));
                                    $barColor = (string) ($row['color'] ?? '#0F5B66');
                                @endphp
                                <div>
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs font-semibold text-[#17324a]">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="whitespace-nowrap">{{ number_format($barValue, 0, ',', ' ') }}%</span>
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
                $curveLeft = 44;
                $curveRight = 344;
                $curveTop = 20;
                $curveBottom = 130;
                $curveHeight = $curveBottom - $curveTop;
                $curveWidth = $curveRight - $curveLeft;
                $curveThresholdY = $curveBottom - (($qualityThreshold / 100) * $curveHeight);
                $executionCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps, $curveLeft, $curveWidth, $curveBottom, $curveHeight): string {
                        $value = min(100, max(0, (float) ($row['taux_execution'] ?? 0)));
                        $x = $curveLeft + (($index * $curveWidth) / $curveSteps);
                        $y = $curveBottom - (($value / 100) * $curveHeight);

                        return number_format($x, 0, '.', '').','.number_format($y, 0, '.', '');
                    })
                    ->implode(' ');
                $scoreCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps, $curveLeft, $curveWidth, $curveBottom, $curveHeight): string {
                        $value = min(100, max(0, (float) ($row['score'] ?? 0)));
                        $x = $curveLeft + (($index * $curveWidth) / $curveSteps);
                        $y = $curveBottom - (($value / 100) * $curveHeight);

                        return number_format($x, 0, '.', '').','.number_format($y, 0, '.', '');
                    })
                    ->implode(' ');
            @endphp
            <article class="showcase-panel mt-3">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-black text-[#17324a]">Evolution trimestrielle</h3>
                    <span class="showcase-chip">{{ $exerciseFilter['label'] ?? 'Exercice courant' }}</span>
                </div>
                <div class="overflow-x-auto rounded-2xl border border-[#d8ecf8] bg-white/92 px-2 py-3">
                    <svg class="w-full min-w-[640px]" style="height: 18rem;" viewBox="0 0 380 170" preserveAspectRatio="none" role="img" aria-label="Courbes trimestrielles">
                        <defs>
                            <linearGradient id="quarter-score-area" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#F26522" stop-opacity="0.20" />
                                <stop offset="100%" stop-color="#F26522" stop-opacity="0.02" />
                            </linearGradient>
                        </defs>
                        @foreach ([0, 25, 50, 75, 100] as $tick)
                            @php $tickY = $curveBottom - (($tick / 100) * $curveHeight); @endphp
                            <line x1="{{ $curveLeft }}" y1="{{ $tickY }}" x2="{{ $curveRight }}" y2="{{ $tickY }}" stroke="#e5eef7" stroke-width="1" @if ($tick > 0) stroke-dasharray="4 6" @endif />
                            <text x="20" y="{{ $tickY + 3 }}" text-anchor="end" font-size="9" font-weight="800" fill="#64748B">{{ $tick }}%</text>
                        @endforeach
                        <line x1="{{ $curveLeft }}" y1="{{ $curveThresholdY }}" x2="{{ $curveRight }}" y2="{{ $curveThresholdY }}" stroke="#F4B400" stroke-width="1.5" stroke-dasharray="6 5" />
                        <text x="{{ $curveRight - 2 }}" y="{{ $curveThresholdY - 5 }}" text-anchor="end" font-size="8.5" font-weight="900" fill="#9A5B00">Seuil moyen {{ number_format($qualityThreshold, 0, ',', ' ') }}%</text>
                        <path d="M {{ $smoothPath($scoreCurvePoints) }} L {{ $curveRight }},{{ $curveBottom }} L {{ $curveLeft }},{{ $curveBottom }} Z" fill="url(#quarter-score-area)" />
                        <path d="M {{ $smoothPath($executionCurvePoints) }}" fill="none" stroke="#F26522" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M {{ $smoothPath($scoreCurvePoints) }}" fill="none" stroke="#0F5B66" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        @foreach ($curveRows as $row)
                            @php
                                $x = $curveLeft + (($loop->index * $curveWidth) / $curveSteps);
                                $executionY = $curveBottom - ((min(100, max(0, (float) ($row['taux_execution'] ?? 0))) / 100) * $curveHeight);
                                $scoreY = $curveBottom - ((min(100, max(0, (float) ($row['score'] ?? 0))) / 100) * $curveHeight);
                            @endphp
                            <circle cx="{{ $x }}" cy="{{ $executionY }}" r="4.5" fill="#ffffff" stroke="#F26522" stroke-width="2.5" />
                            <circle cx="{{ $x }}" cy="{{ $scoreY }}" r="4.5" fill="#ffffff" stroke="#0F5B66" stroke-width="2.5" />
                            <text x="{{ $x }}" y="154" text-anchor="middle" font-size="10" font-weight="900" fill="#475569">{{ $row['trimestre'] ?? '-' }}</text>
                        @endforeach
                    </svg>
                </div>
                <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold text-[#667085]">
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#F26522]"></i>Taux d'exécution</span>
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#0F5B66]"></i>Score</span>
                    <span><i class="mr-1 inline-block h-2.5 w-2.5 rounded-full bg-[#F4B400]"></i>Seuil moyen {{ number_format($qualityThreshold, 0, ',', ' ') }}%</span>
                </div>
            </article>
        </section>
    @endif

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
    <div class="charts-bento charts-bento-row-trend mb-4">
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Evolution mensuelle</h2>
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
                                <defs>
                                    <linearGradient id="charts-area-grad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#F26522" stop-opacity="0.32" />
                                        <stop offset="100%" stop-color="#F26522" stop-opacity="0.02" />
                                    </linearGradient>
                                </defs>
                                <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                                <line x1="20" y1="84" x2="340" y2="84" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="3 4" opacity="0.6" />
                                <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                                <path d="M 20,120 L {{ $smoothPath($chartFallbackPoints($monthlyOfficial, 'global')) }} L 340,120 Z" fill="url(#charts-area-grad)" />
                                <path d="M {{ $smoothPath($chartFallbackPoints($monthlyOfficial, 'global')) }}" fill="none" stroke="#F26522" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                @foreach (collect($monthlyOfficial)->values() as $row)
                                    @php
                                        $x = 20 + (($loop->index * 320) / max(1, count($monthlyOfficial) - 1));
                                        $y = 120 - (min(100, max(0, (float) ($row['global'] ?? 0))) * 0.9);
                                    @endphp
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#ffffff" stroke="#F26522" stroke-width="2.5" />
                                @endforeach
                            </svg>
                        @else
                            <x-ui.empty-state title="Aucune donnée" message="Les données apparaîtront dès que des actions seront enregistrées." icon="chart" tone="info" />
                        @endif
                    </div>
                </div>
            </div>
        </article>

        <article class="showcase-panel">
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

    {{-- ─── RANGEE 3 : PERFORMANCE PAR UNITE + TOP ACTIONS ─────────── --}}
    <div class="charts-bento charts-bento-row-rank charts-long-row mb-4">
        @if ($showDashboardMacroCharts || $showUnitSummaryChart)
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Directions</h2>
                <span class="showcase-chip">{{ count($directionPerformanceRows) }} directions</span>
            </div>
            <div class="dashboard-chart-scroll-frame">
            <div class="dashboard-canvas dashboard-canvas-lg" style="height: {{ number_format($directionPerformanceChartHeight, 2, '.', '') }}rem;">
                <div id="dashboard-direction-performance-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($directionPerformanceFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($directionPerformanceFallbackRows as $row)
                                    @php
                                        $directionValue = min(100, max(0, (float) ($row['score'] ?? $row['taux_execution'] ?? 0)));
                                        $directionTone = $directionValue >= 80 ? '#20C76B' : ($directionValue >= $qualityThreshold ? '#0F5B66' : ($directionValue > 0 ? '#F26522' : '#94A3B8'));
                                    @endphp
                                    <div class="charts-unit-bar">
                                        <span class="charts-unit-bar-label">{{ $row['direction'] ?? '-' }}</span>
                                        <span class="charts-unit-bar-track">
                                            <span class="charts-unit-bar-fill" style="width: {{ $directionValue }}%; background: linear-gradient(90deg, {{ $directionTone }}, {{ $directionTone }}cc);"></span>
                                        </span>
                                        <span class="charts-unit-bar-value" style="color: {{ $directionTone }};">{{ number_format($directionValue, 0, ',', ' ') }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <x-ui.empty-state title="Aucune direction" message="Les performances apparaîtront dès que des actions seront rattachées aux directions." icon="chart" tone="info" />
                        @endif
                    </div>
                </div>
            </div>
            </div>
        </article>
        @endif

        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Services</h2>
                <span class="showcase-chip">{{ count($synthesisServiceRows) }} services</span>
            </div>
            <div class="dashboard-chart-scroll-frame">
            <div class="dashboard-canvas dashboard-canvas-lg" style="height: {{ number_format($servicePerformanceChartHeight, 2, '.', '') }}rem;">
                <div id="dashboard-service-performance-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($servicePerformanceFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($servicePerformanceFallbackRows as $row)
                                    @php
                                        $serviceValue = min(100, max(0, (float) ($row['kpi_global'] ?? $row['progression_moyenne'] ?? 0)));
                                        $serviceTone = $serviceValue >= 80 ? '#20C76B' : ($serviceValue >= $qualityThreshold ? '#0F5B66' : ($serviceValue > 0 ? '#F26522' : '#94A3B8'));
                                    @endphp
                                    <div class="charts-unit-bar">
                                        <span class="charts-unit-bar-label">{{ $row['label'] ?? '-' }}</span>
                                        <span class="charts-unit-bar-track">
                                            <span class="charts-unit-bar-fill" style="width: {{ $serviceValue }}%; background: linear-gradient(90deg, {{ $serviceTone }}, {{ $serviceTone }}cc);"></span>
                                        </span>
                                        <span class="charts-unit-bar-value" style="color: {{ $serviceTone }};">{{ number_format($serviceValue, 0, ',', ' ') }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <x-ui.empty-state title="Aucun service" message="Les performances apparaîtront dès que des actions seront rattachées aux services." icon="chart" tone="info" />
                        @endif
                    </div>
                </div>
            </div>
            </div>
        </article>
    </div>

    <div class="charts-bento charts-bento-row-rank charts-long-row mb-4">
        @if ($showUnitSummaryChart)
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">{{ $unitModeLabel }}</h2>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-chart-scroll-frame">
            <div class="dashboard-canvas" style="height: {{ number_format($unitSummaryChartHeight, 2, '.', '') }}rem;">
                <div id="dashboard-unit-summary-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($unitFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($unitFallbackRows as $row)
                                    @php
                                        $unitValue = min(100, max(0, (float) ($row['kpi_global'] ?? $row['progression_moyenne'] ?? 0)));
                                        $unitTone = $unitValue >= 80 ? '#20C76B' : ($unitValue >= $qualityThreshold ? '#0F5B66' : ($unitValue > 0 ? '#F26522' : '#94A3B8'));
                                    @endphp
                                    <div class="charts-unit-bar">
                                        <span class="charts-unit-bar-label">{{ $row['label'] ?? '-' }}</span>
                                        <span class="charts-unit-bar-track">
                                            <span class="charts-unit-bar-fill" style="width: {{ $unitValue }}%; background: linear-gradient(90deg, {{ $unitTone }}, {{ $unitTone }}cc);"></span>
                                        </span>
                                        <span class="charts-unit-bar-value" style="color: {{ $unitTone }};">{{ number_format($unitValue, 0, ',', ' ') }}%</span>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <x-ui.empty-state title="Aucune donnée" message="Les données apparaîtront dès que des actions seront enregistrées." icon="chart" tone="info" />
                        @endif
                    </div>
                </div>
            </div>
            </div>
        </article>
        @endif

        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Meilleures actions</h2>
                <span class="showcase-chip">{{ count($analytics['top_action_bars'] ?? []) }} actions</span>
            </div>
            @if ($analytics['top_action_bars'] ?? false)
                <div class="charts-top-actions charts-scroll-list">
                    @foreach ($analytics['top_action_bars'] as $row)
                        <a href="{{ $row['url'] }}" class="charts-top-action-row">
                            <span class="charts-top-action-rank">{{ $loop->iteration }}</span>
                            <span class="charts-top-action-body">
                                <span class="charts-top-action-label">{{ $row['label'] }}</span>
                                <span class="charts-top-action-track">
                                    <span class="charts-top-action-fill" style="width: {{ min(100, max(0, (float) $row['value'])) }}%; background: linear-gradient(90deg, {{ $row['color'] }}, {{ $row['color'] }}cc);"></span>
                                </span>
                            </span>
                            <span class="charts-top-action-value" style="color: {{ $row['color'] }};">{{ number_format((float) $row['value'], 0, ',', ' ') }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-ui.empty-state
                    title="Aucune action classée"
                    message="Le classement apparaîtra quand des actions seront en cours."
                    icon="chart"
                    tone="info"
                />
            @endif
        </article>
    </div>

    @php
        $agentAverageScore = min(100, max(0, (float) ($agentPerformanceSummary['average_score'] ?? 0)));
        $agentExecutionRate = min(100, max(0, (float) ($agentPerformanceSummary['execution_rate'] ?? 0)));
        $agentTone = $agentAverageScore >= $agentPerformanceThreshold
            ? '#20C76B'
            : ($agentAverageScore >= 55 ? '#F26522' : ($agentAverageScore > 0 ? '#D92D20' : '#94A3B8'));
        $agentTopRows = collect($agentPerformanceTopRows)->values();
        $agentAllRows = collect($agentPerformanceRows)->values();
    @endphp

    <section class="charts-agent-performance-section">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Agents</h2>
            </div>
            <span class="showcase-chip">Seuil {{ number_format($agentPerformanceThreshold, 0, ',', ' ') }}%</span>
        </div>

        <div class="charts-agent-grid">
            <article class="showcase-panel dashboard-agent-card dashboard-agent-card-gauge">
                <div class="chart-panel-head mb-3">
                    <h3 class="chart-title">Moyenne</h3>
                    <span class="showcase-chip">{{ (int) ($agentPerformanceSummary['agents_total'] ?? 0) }} agents</span>
                </div>
                <div class="dashboard-canvas dashboard-agent-gauge-canvas">
                    <div id="dashboard-agent-gauge" class="dashboard-chart-host dashboard-plotly-host" data-plotly-chart="agent_gauge">
                        <div class="dashboard-gauge-fallback" style="--value-pct: {{ $agentAverageScore }}%; --tone: {{ $agentTone }};" aria-hidden="true">
                            <span class="dashboard-gauge-fallback-ring">
                                <span class="dashboard-gauge-fallback-value">{{ number_format($agentAverageScore, 0, ',', ' ') }}%</span>
                            </span>
                            <span class="dashboard-gauge-fallback-label">Seuil {{ number_format($agentPerformanceThreshold, 0, ',', ' ') }}%</span>
                        </div>
                    </div>
                </div>
                <div class="dashboard-agent-summary-grid">
                    <span><strong>{{ number_format((float) ($agentPerformanceSummary['actions_assigned'] ?? 0), 0, ',', ' ') }}</strong> assignees</span>
                    <span><strong>{{ number_format((float) ($agentPerformanceSummary['actions_closed'] ?? 0), 0, ',', ' ') }}</strong> cloturees</span>
                    <span><strong>{{ number_format($agentExecutionRate, 0, ',', ' ') }}%</strong> execution</span>
                </div>
            </article>

            <article class="showcase-panel dashboard-agent-card dashboard-agent-card-top">
                <div class="chart-panel-head mb-3">
                    <h3 class="chart-title">Top agents</h3>
                    <span class="showcase-chip">Score global</span>
                </div>
                <div class="dashboard-canvas dashboard-canvas-lg">
                    <div id="dashboard-agent-top" class="dashboard-chart-host dashboard-plotly-host" data-plotly-chart="agent_top">
                        <div class="dashboard-chart-fallback dashboard-agent-bars" aria-hidden="true">
                            @forelse ($agentTopRows as $row)
                                @php
                                    $agentScore = min(100, max(0, (float) ($row['score_global'] ?? 0)));
                                    $agentRowTone = $agentScore >= $agentPerformanceThreshold ? '#20C76B' : ($agentScore >= 55 ? '#F26522' : '#D92D20');
                                @endphp
                                <a href="{{ $row['url'] ?? route('workspace.actions.index') }}" class="dashboard-agent-bar-row">
                                    <span class="dashboard-agent-rank">{{ $loop->iteration }}</span>
                                    <span class="dashboard-agent-bar-label">{{ $row['agent'] ?? 'Agent' }}</span>
                                    <span class="dashboard-agent-bar-track">
                                        <span class="dashboard-agent-bar-fill" style="width: {{ $agentScore }}%; background: {{ $agentRowTone }};"></span>
                                    </span>
                                    <span class="dashboard-agent-bar-value" style="color: {{ $agentRowTone }};">{{ number_format($agentScore, 0, ',', ' ') }}%</span>
                                </a>
                            @empty
                                <x-ui.empty-state title="Aucun agent" message="Les performances apparaîtront dès que les actions seront affectées." icon="chart" tone="info" />
                            @endforelse
                        </div>
                    </div>
                </div>
            </article>

            <article class="showcase-panel dashboard-agent-card dashboard-agent-card-3d">
                <div class="chart-panel-head mb-3">
                    <h3 class="chart-title">3D</h3>
                    <span class="showcase-chip">Charge, cloture, score</span>
                </div>
                <div class="dashboard-canvas dashboard-agent-plotly-lg">
                    <div id="dashboard-agent-3d" class="dashboard-chart-host dashboard-plotly-host" data-plotly-chart="agent_3d">
                        <div class="dashboard-chart-fallback dashboard-agent-scatter-fallback" aria-hidden="true">
                            @forelse ($agentAllRows->take(12) as $row)
                                @php
                                    $x = min(92, 8 + ((int) ($row['actions_assigned'] ?? 0) * 7));
                                    $y = max(8, 92 - min(84, (float) ($row['score_global'] ?? 0) * 0.84));
                                    $bubble = min(2.2, 0.75 + ((int) ($row['actions_late'] ?? 0) * 0.24));
                                    $score = min(100, max(0, (float) ($row['score_global'] ?? 0)));
                                    $bubbleTone = $score >= $agentPerformanceThreshold ? '#20C76B' : ($score >= 55 ? '#F26522' : '#D92D20');
                                @endphp
                                <span class="dashboard-agent-bubble" style="left: {{ $x }}%; top: {{ $y }}%; width: {{ $bubble }}rem; height: {{ $bubble }}rem; background: {{ $bubbleTone }};" title="{{ $row['agent'] ?? 'Agent' }}"></span>
                            @empty
                                <x-ui.empty-state title="Aucune donnée" message="Le nuage 3D apparaîtra avec les actions affectées." icon="chart" tone="info" />
                            @endforelse
                        </div>
                    </div>
                </div>
            </article>

            <article class="showcase-panel dashboard-agent-card dashboard-agent-card-heatmap">
                <div class="chart-panel-head mb-3">
                    <h3 class="chart-title">Carte agents</h3>
                    <span class="showcase-chip">Carte thermique</span>
                </div>
                <div class="dashboard-canvas dashboard-agent-plotly-lg">
                    <div id="dashboard-agent-heatmap" class="dashboard-chart-host dashboard-plotly-host" data-plotly-chart="agent_heatmap">
                        <div class="dashboard-chart-fallback dashboard-agent-heatmap-fallback" aria-hidden="true">
                            @forelse ($agentTopRows as $row)
                                @php
                                    $heatScore = min(100, max(0, (float) ($row['score_global'] ?? 0)));
                                    $heatTone = $heatScore >= $agentPerformanceThreshold ? '#20C76B' : ($heatScore >= 55 ? '#F26522' : '#D92D20');
                                @endphp
                                <div class="dashboard-agent-heat-cell" style="--heat: {{ $heatScore }}%; --tone: {{ $heatTone }};">
                                    <span>{{ $row['agent'] ?? 'Agent' }}</span>
                                    <strong>{{ number_format($heatScore, 0, ',', ' ') }}%</strong>
                                </div>
                            @empty
                                <x-ui.empty-state title="Aucune donnée" message="La carte thermique apparaîtra dès que des agents seront affectés." icon="chart" tone="info" />
                            @endforelse
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <article class="showcase-panel dashboard-agent-alert-panel">
            <div class="chart-panel-head mb-3">
                <h3 class="chart-title">Alertes</h3>
                <span class="showcase-chip">{{ count($agentPerformanceAlerts) }} signalements</span>
            </div>
            <div class="dashboard-agent-alert-list">
                @forelse ($agentPerformanceAlerts as $alert)
                    @php
                        $alertLevel = (string) ($alert['level'] ?? 'warning');
                        $alertTone = match ($alertLevel) {
                            'danger' => '#D92D20',
                            'success' => '#20C76B',
                            default => '#F26522',
                        };
                    @endphp
                    <a href="{{ $alert['url'] ?? route('workspace.actions.index') }}" class="dashboard-agent-alert" style="--tone: {{ $alertTone }};">
                        <span class="dashboard-agent-alert-dot"></span>
                        <span class="dashboard-agent-alert-body">
                            <strong>{{ $alert['title'] ?? 'Alerte agent' }}</strong>
                            <span>{{ $alert['message'] ?? '' }}</span>
                        </span>
                    </a>
                @empty
                    <x-ui.empty-state title="Aucune alerte agent" message="Les agents sont au-dessus des seuils de surveillance." icon="check" tone="success" />
                @endforelse
            </div>
        </article>
    </section>

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
