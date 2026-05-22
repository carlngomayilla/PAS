@php
    $displayMode = $displayMode ?? 'full';
    $showHeroBlock = in_array($displayMode, ['full', 'overview'], true);
    $showChartBlocks = in_array($displayMode, ['full', 'charts'], true);
    $showTableBlocks = in_array($displayMode, ['full', 'tables'], true);
    $primaryRows = $roleDashboard['primary_rows'] ?? [];
    $secondaryRows = $roleDashboard['secondary_rows'] ?? [];
    $comparisonChart = $roleDashboard['comparison_chart'] ?? [];
    $statusChart = $roleDashboard['status_chart'] ?? [];
    $trendChart = $roleDashboard['trend_chart'] ?? [];
    $supportChart = $roleDashboard['support_chart'] ?? [];
    $showOverview = (bool) ($roleDashboard['overview_enabled'] ?? true);
    $showComparisonChart = (bool) ($roleDashboard['comparison_chart_enabled'] ?? true);
    $showStatusChart = (bool) ($roleDashboard['status_chart_enabled'] ?? true);
    $showTrendChart = (bool) ($roleDashboard['trend_chart_enabled'] ?? true);
    $showSupportChart = (bool) ($roleDashboard['support_chart_enabled'] ?? true);
    $statisticalPolicy = is_array(($statisticalPolicy ?? null)) ? $statisticalPolicy : [];
    $officialPolicy = is_array(($officialPolicy ?? null)) ? $officialPolicy : [];
    $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
    $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
    $officialBaseLower = mb_strtolower($officialBaseLabel);
    $officialBaseText = 'Base statistique : '.$officialBaseLabel;
    $roleFallbackBars = static function (array $chart): array {
        $labels = collect($chart['labels'] ?? [])->values();
        $values = is_array($chart['values'] ?? null)
            ? $chart['values']
            : (is_array(($chart['datasets'][0]['data'] ?? null)) ? $chart['datasets'][0]['data'] : []);

        return $labels
            ->map(fn ($label, int $index): array => [
                'label' => (string) $label,
                'value' => min(100, max(0, (float) ($values[$index] ?? 0))),
            ])
            ->take(6)
            ->all();
    };
    $roleFallbackPoints = static function (array $chart): string {
        $labels = collect($chart['labels'] ?? [])->values();
        $values = is_array(($chart['datasets'][0]['data'] ?? null)) ? $chart['datasets'][0]['data'] : [];
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
    $validationTone = static function (string $status): string {
        return match ($status) {
            \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_CHEF => 'success',
            \App\Services\Actions\ActionTrackingService::VALIDATION_SOUMISE_CHEF => 'warning',
            \App\Services\Actions\ActionTrackingService::VALIDATION_REJETEE_CHEF,
            \App\Services\Actions\ActionTrackingService::VALIDATION_REJETEE_DIRECTION => 'danger',
            default => 'neutral',
        };
    };
@endphp

@if ($showHeroBlock)
    <div class="showcase-panel mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $roleDashboard['hero']['title'] ?? 'Lecture par profil' }}</h2>
            </div>
            <span class="showcase-chip">{{ strtoupper((string) ($roleDashboard['role'] ?? $dashboardRole)) }}</span>
        </div>
    </div>
@endif

@if ($showChartBlocks && $showComparisonChart && count($comparisonChart['labels'] ?? []) > 0)
    <article class="showcase-panel mb-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $comparisonChart['title'] ?? 'Comparaison des indicateurs' }}</h2>
            </div>
            <span class="showcase-chip">{{ count($comparisonChart['labels'] ?? []) }} indicateurs</span>
        </div>
        <div class="dashboard-canvas">
            <div id="dashboard-role-comparison-chart" class="dashboard-chart-host">
                <div class="dashboard-chart-fallback" aria-hidden="true">
                    <div class="dashboard-chart-fallback-bars">
                        @foreach ($roleFallbackBars($comparisonChart) as $row)
                            <div class="dashboard-chart-fallback-bar">
                                <span class="truncate">{{ $row['label'] }}</span>
                                <span class="dashboard-chart-fallback-track"><span class="dashboard-chart-fallback-fill" style="width: {{ $row['value'] }}%;"></span></span>
                                <span class="text-right">{{ number_format($row['value'], 1, ',', ' ') }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </article>
@endif

@if ($showChartBlocks && ($showStatusChart || $showTrendChart))
    <div class="space-y-4">
        @if ($showStatusChart)
            <article class="showcase-panel">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="showcase-panel-title">{{ $statusChart['title'] ?? 'Répartition des statuts' }}</h2>
                    </div>
                    <span class="showcase-chip">{{ count($statusChart['labels'] ?? []) }} statuts</span>
                </div>
                <div class="dashboard-canvas">
                    <div id="dashboard-role-status-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <div class="dashboard-chart-fallback-bars">
                                @foreach ($roleFallbackBars($statusChart) as $row)
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
        @endif

        @if ($showTrendChart)
            <article class="showcase-panel">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="showcase-panel-title">{{ $trendChart['title'] ?? 'Tendance' }}</h2>
                    </div>
                    <span class="showcase-chip">{{ count($trendChart['labels'] ?? []) }} points</span>
                </div>
                <div class="dashboard-canvas">
                    <div id="dashboard-role-trend-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <svg viewBox="0 0 360 140" preserveAspectRatio="none">
                                <line x1="20" y1="120" x2="340" y2="120" stroke="#d8ecf8" stroke-width="1" />
                                <line x1="20" y1="48" x2="340" y2="48" stroke="#d8ecf8" stroke-width="1" stroke-dasharray="4 4" />
                                <polyline points="{{ $roleFallbackPoints($trendChart) }}" fill="none" stroke="#3996D3" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                    </div>
                </div>
            </article>
        @endif
    </div>
@endif

@if (($showChartBlocks && $showSupportChart) || ($showTableBlocks && $showOverview))
<div class="mt-4 space-y-4">
    @if ($showChartBlocks && $showSupportChart)
    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $supportChart['title'] ?? 'Lecture métier' }}</h2>
            </div>
            <span class="showcase-chip">{{ count($supportChart['labels'] ?? []) }} lignes</span>
        </div>
        <div class="dashboard-canvas">
            <div id="dashboard-role-support-chart" class="dashboard-chart-host">
                <div class="dashboard-chart-fallback" aria-hidden="true">
                    <div class="dashboard-chart-fallback-bars">
                        @foreach ($roleFallbackBars($supportChart) as $row)
                            <div class="dashboard-chart-fallback-bar">
                                <span class="truncate">{{ $row['label'] }}</span>
                                <span class="dashboard-chart-fallback-track"><span class="dashboard-chart-fallback-fill" style="width: {{ $row['value'] }}%;"></span></span>
                                <span class="text-right">{{ number_format($row['value'], 1, ',', ' ') }}%</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </article>
    @endif

    @if ($showTableBlocks && $showOverview)
    <article class="showcase-panel">
        @if ($dashboardRole === 'agent')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Mes actions prioritaires</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} lignes</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Action</th><th>PTA</th><th>Échéance</th><th>Statut</th><th>Progression</th><th>Validation</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['libelle'] }}</td>
                                <td>{{ $row['pta'] }}</td>
                                <td>{{ $row['echeance'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}">{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td>{{ number_format((float) ($row['progression'] ?? 0), 1) }}%</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune action prioritaire" message="Aucune action prioritaire disponible." icon="filter" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'service')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Actions à valider</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} lignes</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Action</th><th>Agent</th><th>Date soumission</th><th>Statut</th><th>Progression</th><th>Retard</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['libelle'] }}</td>
                                <td>{{ $row['agent'] }}</td>
                                <td>{{ $row['soumise_le'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}">{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td>{{ number_format((float) ($row['progression'] ?? 0), 1) }}%</td>
                                <td>{{ (int) ($row['retard_jours'] ?? 0) }}j</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune validation" message="Aucune action à valider pour le moment." icon="check" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'direction')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Performance par service</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} services</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Service</th><th>Actions</th><th>Achevées</th><th>Retard</th><th>Validées</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['service'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['achevees'] }}</td>
                                <td>{{ $row['retards'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 1) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucun service" message="Aucun service disponible pour cette direction." icon="users" />
                                </td>
                            </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @elseif ($dashboardRole === 'dg')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Directions : suivi stratégique</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} directions</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Validées</th><th>Exéc.</th><th>Taux validation</th><th>Retards</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td>{{ number_format((float) ($row['taux_execution'] ?? 0), 1) }}%</td>
                                <td>{{ number_format((float) ($row['taux_validation'] ?? 0), 1) }}%</td>
                                <td>{{ $row['retards'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 1) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state title="Aucune direction" message="Aucune direction disponible." icon="users" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'planification')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Classement des directions</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} directions</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Achevées</th><th>Retard</th><th>Validées</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['achevees'] }}</td>
                                <td>{{ $row['retards'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 1) }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune direction" message="Aucune direction disponible." icon="users" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'cabinet')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Validations en attente</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} lignes</span>
            </div>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Responsable</th><th>Validation</th><th>Soumise le</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                                <td>{{ $row['service'] }}</td>
                                <td>{{ $row['libelle'] }}</td>
                                <td>{{ $row['responsable'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                                <td>{{ $row['soumise_le'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune validation" message="Aucune validation en attente sur ce périmètre." icon="check" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </article>
    @endif
</div>
@endif

@if ($showTableBlocks && $showOverview)
<article class="showcase-panel mt-4">
    @if ($dashboardRole === 'agent')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Mes actions en retard</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Action</th><th>Échéance</th><th>Retard</th><th>Progression</th><th>Validation</th><th>Accès</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-[#17324a]">{{ $row['libelle'] }}</td>
                            <td>{{ $row['echeance'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td>{{ number_format((float) ($row['progression'] ?? 0), 1) }}%</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state title="Aucun retard" message="Aucune action en retard sur le périmètre courant." icon="clock" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'service')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Performance des agents</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} agents</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Agent</th><th>Actions</th><th>Achevées</th><th>Retard</th><th>Taux exécution</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-[#17324a]">{{ $row['agent'] }}</td>
                            <td>{{ $row['actions_total'] }}</td>
                            <td>{{ $row['achevees'] }}</td>
                            <td>{{ $row['retards'] }}</td>
                            <td>{{ number_format((float) ($row['taux_execution'] ?? 0), 1) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state title="Aucune performance" message="Aucune performance agent disponible." icon="chart" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'direction')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Actions critiques de la direction</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Action</th><th>Service</th><th>Responsable</th><th>Retard</th><th>Validation</th><th>Performance d'exécution</th><th>Accès</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-[#17324a]">{{ $row['libelle'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['responsable'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['performance_execution'] ?? 0))) }}">{{ number_format((float) ($row['performance_execution'] ?? 0), 1) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state title="Aucune action critique" message="Aucune action critique sur le périmètre courant." icon="alert" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'dg')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Directions en difficulté</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Direction</th><th>Service critique</th><th>Retard</th><th>Taux validation</th><th>Score</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                            <td>{{ $row['service_critique'] }}</td>
                            <td>{{ $row['retards'] }}</td>
                            <td>{{ number_format((float) ($row['taux_validation'] ?? 0), 1) }}%</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 1) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty-state title="Aucune difficulté" message="Aucune direction en difficulté actuellement." icon="chart" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'planification')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Actions critiques validées</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Retard</th><th>Validation</th><th>Performance d'exécution</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['libelle'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['performance_execution'] ?? 0))) }}">{{ number_format((float) ($row['performance_execution'] ?? 0), 1) }}</span></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-ui.empty-state title="Aucune action validée" message="Aucune action critique validée." icon="check" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'cabinet')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Alertes critiques transverses</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Retard</th><th>Validation</th><th>Performance d'exécution</th><th>Accès</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-[#17324a]">{{ $row['direction'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['libelle'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['performance_execution'] ?? 0))) }}">{{ number_format((float) ($row['performance_execution'] ?? 0), 1) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state title="Aucune alerte critique" message="Aucune alerte critique transverse." icon="alert" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</article>
@endif
