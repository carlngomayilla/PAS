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
                $executionCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['taux_execution'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 0, '.', '').','.number_format($y, 0, '.', '');
                    })
                    ->implode(' ');
                $scoreCurvePoints = $curveRows
                    ->map(function (array $row, int $index) use ($curveSteps): string {
                        $value = min(100, max(0, (float) ($row['score'] ?? 0)));
                        $x = 24 + (($index * 312) / $curveSteps);
                        $y = 118 - ($value * 0.9);

                        return number_format($x, 0, '.', '').','.number_format($y, 0, '.', '');
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
                        <path d="M {{ $smoothPath($executionCurvePoints) }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M {{ $smoothPath($scoreCurvePoints) }}" fill="none" stroke="#8FC043" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
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

    {{-- ╔═══════════════════════════════════════════════════════════════╗
         ║ NOUVELLE DISPOSITION PREMIUM (2026-05-30) — layout Bento     ║
         ║ Rangee 1 : HERO SCORE (large) + 2 jauges KPI (a droite)     ║
         ║ Rangee 2 : Tendance 12 mois (large) + Repartition statuts   ║
         ║ Rangee 3 : Performance par unite + Top actions              ║
         ╚═══════════════════════════════════════════════════════════════╝ --}}

    @php
        $globalScore = (float) ($globalScores['global'] ?? 0);
        $progressionScore = (float) ($globalScores['progression'] ?? 0);
        $scoreTone = $globalScore >= 80 ? '#8FC043' : ($globalScore >= 60 ? '#3996D3' : ($globalScore >= 40 ? '#F9B13C' : '#ef4444'));
        $scoreToneLabel = $globalScore >= 80 ? 'Excellent' : ($globalScore >= 60 ? 'Bon' : ($globalScore >= 40 ? 'A surveiller' : 'Critique'));
        $directionPerformanceFallbackRows = collect($directionPerformanceRows)->take(8);
        $servicePerformanceFallbackRows = collect($synthesisServiceRows)->take(8);
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
                <div class="charts-hero-progress-threshold" style="left: 60%;" title="Seuil de qualité : 60"></div>
            </div>
            <div class="charts-hero-meta">
                <div class="charts-hero-meta-cell">
                    <span class="charts-hero-meta-label">Progression moy.</span>
                    <span class="charts-hero-meta-value">{{ number_format($progressionScore, 0, ',', ' ') }}%</span>
                </div>
                <div class="charts-hero-meta-cell">
                    <span class="charts-hero-meta-label">Seuil qualité</span>
                    <span class="charts-hero-meta-value">60</span>
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
                <h2 class="chart-title">Indicateurs KPI</h2>
                <span class="showcase-chip">Délai · Performance</span>
            </div>
            <div class="charts-gauges-grid">
                @foreach ([['key' => 'delai', 'label' => $metricLabel('delai')],['key' => 'performance', 'label' => $metricLabel('performance')]] as $gauge)
                    @php
                        $gaugeValue = min(100, max(0, (float) ($globalScores[$gauge['key']] ?? 0)));
                        $gaugeTone = $gaugeValue >= 80 ? '#8FC043' : ($gaugeValue >= 60 ? '#3996D3' : ($gaugeValue >= 40 ? '#F9B13C' : '#ef4444'));
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
                                <defs>
                                    <linearGradient id="charts-area-grad" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stop-color="#3996D3" stop-opacity="0.32" />
                                        <stop offset="100%" stop-color="#3996D3" stop-opacity="0.02" />
                                    </linearGradient>
                                </defs>
                                <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                                <line x1="20" y1="84" x2="340" y2="84" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="3 4" opacity="0.6" />
                                <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                                <path d="M 20,120 L {{ $smoothPath($chartFallbackPoints($monthlyOfficial, 'global')) }} L 340,120 Z" fill="url(#charts-area-grad)" />
                                <path d="M {{ $smoothPath($chartFallbackPoints($monthlyOfficial, 'global')) }}" fill="none" stroke="#3996D3" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
                                @foreach (collect($monthlyOfficial)->values() as $row)
                                    @php
                                        $x = 20 + (($loop->index * 320) / max(1, count($monthlyOfficial) - 1));
                                        $y = 120 - (min(100, max(0, (float) ($row['global'] ?? 0))) * 0.9);
                                    @endphp
                                    <circle cx="{{ $x }}" cy="{{ $y }}" r="4" fill="#ffffff" stroke="#3996D3" stroke-width="2.5" />
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
                <h2 class="chart-title">Répartition des statuts</h2>
                <span class="showcase-chip">{{ collect($statusCards)->sum('count') }} actions</span>
            </div>
            @if (! empty($statusCards) && collect($statusCards)->sum('count') > 0)
                @php $statusCardsTotal = collect($statusCards)->sum('count'); @endphp
                <div class="dashboard-canvas">
                    <div id="dashboard-status-mix-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Graphique en cours de chargement" message="La liste complète des statuts reste disponible ci-dessous." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <div class="mt-4 charts-status-grid" aria-label="Liste complète des statuts">
                    @foreach ($statusCards as $card)
                        @php $cardPct = $statusCardsTotal > 0 ? round(((float) $card['count'] / $statusCardsTotal) * 100, 1) : 0; @endphp
                        <a class="charts-status-item" href="{{ $card['href'] ?? '#' }}" style="--tone: {{ $card['color'] }};">
                            <div class="charts-status-item-head">
                                <span class="charts-status-dot" style="background: {{ $card['color'] }};"></span>
                                <span class="charts-status-item-name">{{ $card['label'] }}</span>
                                <span class="charts-status-item-count" style="color: {{ $card['color'] }};">{{ $card['count'] }}</span>
                            </div>
                            <div class="charts-status-item-track">
                                <div class="charts-status-item-fill" style="width: {{ $cardPct }}%; background: {{ $card['color'] }};"></div>
                            </div>
                            <span class="charts-status-item-pct">{{ number_format($cardPct, 0, ',', ' ') }}%</span>
                        </a>
                    @endforeach
                </div>
            @else
                <x-ui.empty-state title="Aucun statut à afficher" message="Importez des actions pour voir la répartition." icon="chart" tone="info" />
            @endif
        </article>
    </div>

    {{-- ─── RANGEE 3 : PERFORMANCE PAR UNITE + TOP ACTIONS ─────────── --}}
    <div class="charts-bento charts-bento-row-rank mb-4">
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Performance des directions</h2>
                <span class="showcase-chip">{{ count($directionPerformanceRows) }} directions</span>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg">
                <div id="dashboard-direction-performance-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($directionPerformanceFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($directionPerformanceFallbackRows as $row)
                                    @php
                                        $directionValue = min(100, max(0, (float) ($row['score'] ?? $row['taux_execution'] ?? 0)));
                                        $directionTone = $directionValue >= 80 ? '#8FC043' : ($directionValue >= 60 ? '#3996D3' : ($directionValue >= 40 ? '#F9B13C' : '#ef4444'));
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
        </article>

        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Performance des services</h2>
                <span class="showcase-chip">{{ count($synthesisServiceRows) }} services</span>
            </div>
            <div class="dashboard-canvas dashboard-canvas-lg">
                <div id="dashboard-service-performance-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($servicePerformanceFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($servicePerformanceFallbackRows as $row)
                                    @php
                                        $serviceValue = min(100, max(0, (float) ($row['kpi_global'] ?? $row['progression_moyenne'] ?? 0)));
                                        $serviceTone = $serviceValue >= 80 ? '#8FC043' : ($serviceValue >= 60 ? '#3996D3' : ($serviceValue >= 40 ? '#F9B13C' : '#ef4444'));
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
        </article>
    </div>

    <div class="charts-bento charts-bento-row-rank mb-4">
        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Performance par {{ strtolower($unitModeLabel) }}</h2>
                <span class="showcase-chip">{{ count($unitRows) }} {{ strtolower($unitModeLabel) }}</span>
            </div>
            <div class="dashboard-canvas">
                <div id="dashboard-unit-summary-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        @if ($unitFallbackRows->isNotEmpty())
                            <div class="charts-unit-bars">
                                @foreach ($unitFallbackRows as $row)
                                    @php
                                        $unitValue = min(100, max(0, (float) ($row['kpi_global'] ?? $row['progression_moyenne'] ?? 0)));
                                        $unitTone = $unitValue >= 80 ? '#8FC043' : ($unitValue >= 60 ? '#3996D3' : ($unitValue >= 40 ? '#F9B13C' : '#ef4444'));
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
        </article>

        <article class="showcase-panel">
            <div class="chart-panel-head mb-3">
                <h2 class="chart-title">Top actions (meilleur score)</h2>
                <span class="showcase-chip">Top {{ count($analytics['top_action_bars'] ?? []) }}</span>
            </div>
            @if ($analytics['top_action_bars'] ?? false)
                <div class="charts-top-actions">
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
