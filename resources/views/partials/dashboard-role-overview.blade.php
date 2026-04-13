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
        <div class="dashboard-canvas"><div id="dashboard-role-comparison-chart" class="dashboard-chart-host"></div></div>
    </article>
@endif

@if ($showChartBlocks && ($showStatusChart || $showTrendChart))
    <div class="space-y-4">
        @if ($showStatusChart)
            <article class="showcase-panel">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="showcase-panel-title">{{ $statusChart['title'] ?? 'Repartition des statuts' }}</h2>
                    </div>
                    <span class="showcase-chip">{{ count($statusChart['labels'] ?? []) }} statuts</span>
                </div>
                <div class="dashboard-canvas"><div id="dashboard-role-status-chart" class="dashboard-chart-host"></div></div>
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
                <div class="dashboard-canvas"><div id="dashboard-role-trend-chart" class="dashboard-chart-host"></div></div>
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
                <h2 class="showcase-panel-title">{{ $supportChart['title'] ?? 'Lecture metier' }}</h2>
            </div>
            <span class="showcase-chip">{{ count($supportChart['labels'] ?? []) }} lignes</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-role-support-chart" class="dashboard-chart-host"></div></div>
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
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Action</th><th>PTA</th><th>Echeance</th><th>Statut</th><th>Progression</th><th>Validation</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['libelle'] }}</td>
                                <td>{{ $row['pta'] }}</td>
                                <td>{{ $row['echeance'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}">{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td>{{ number_format((float) ($row['progression'] ?? 0), 0) }}%</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucune action prioritaire disponible.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'service')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Actions a valider</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} lignes</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Action</th><th>Agent</th><th>Date soumission</th><th>Statut</th><th>Progression</th><th>Retard</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['libelle'] }}</td>
                                <td>{{ $row['agent'] }}</td>
                                <td>{{ $row['soumise_le'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}">{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td>{{ number_format((float) ($row['progression'] ?? 0), 0) }}%</td>
                                <td>{{ (int) ($row['retard_jours'] ?? 0) }}j</td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucune action a valider pour le moment.</td></tr>
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
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Service</th><th>Actions</th><th>Achevees</th><th>Retard</th><th>Validees</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['service'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['achevees'] }}</td>
                                <td>{{ $row['retards'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 0) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucun service disponible pour cette direction.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @elseif ($dashboardRole === 'dg')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Directions : suivi strategique</h2>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} directions</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Validees</th><th>Exec.</th><th>Taux validation</th><th>Retards</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td>{{ number_format((float) ($row['taux_execution'] ?? 0), 0) }}%</td>
                                <td>{{ number_format((float) ($row['taux_validation'] ?? 0), 0) }}%</td>
                                <td>{{ $row['retards'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 0) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7">Aucune direction disponible.</td></tr>
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
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Achevees</th><th>Retard</th><th>Validees</th><th>Score</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['achevees'] }}</td>
                                <td>{{ $row['retards'] }}</td>
                                <td>{{ $row['validees_direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 0) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucune direction disponible.</td></tr>
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
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Responsable</th><th>Validation</th><th>Soumise le</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                                <td>{{ $row['service'] }}</td>
                                <td>{{ $row['libelle'] }}</td>
                                <td>{{ $row['responsable'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                                <td>{{ $row['soumise_le'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Aucune validation en attente sur ce perimetre.</td></tr>
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
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Action</th><th>Echeance</th><th>Retard</th><th>Progression</th><th>Validation</th><th>Acces</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['libelle'] }}</td>
                            <td>{{ $row['echeance'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td>{{ number_format((float) ($row['progression'] ?? 0), 0) }}%</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Aucune action en retard sur le perimetre courant.</td></tr>
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
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Agent</th><th>Actions</th><th>Achevees</th><th>Retard</th><th>Taux execution</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['agent'] }}</td>
                            <td>{{ $row['actions_total'] }}</td>
                            <td>{{ $row['achevees'] }}</td>
                            <td>{{ $row['retards'] }}</td>
                            <td>{{ number_format((float) ($row['taux_execution'] ?? 0), 0) }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Aucune performance agent disponible.</td></tr>
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
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Action</th><th>Service</th><th>Responsable</th><th>Retard</th><th>Validation</th><th>Risque</th><th>Acces</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['libelle'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['responsable'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['niveau_risque'] ?? 0))) }}">{{ number_format((float) ($row['niveau_risque'] ?? 0), 0) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Aucune action critique sur le perimetre courant.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'dg')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Directions en difficulte</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Direction</th><th>Service critique</th><th>Retard</th><th>Taux validation</th><th>Score</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                            <td>{{ $row['service_critique'] }}</td>
                            <td>{{ $row['retards'] }}</td>
                            <td>{{ number_format((float) ($row['taux_validation'] ?? 0), 0) }}%</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score'] ?? 0))) }}">{{ number_format((float) ($row['score'] ?? 0), 0) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Aucune direction en difficulte actuellement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'planification')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Actions critiques validees</h2>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Retard</th><th>Validation</th><th>Risque</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['libelle'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['niveau_risque'] ?? 0))) }}">{{ number_format((float) ($row['niveau_risque'] ?? 0), 0) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Aucune action critique validee.</td></tr>
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
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Direction</th><th>Service</th><th>Action</th><th>Retard</th><th>Validation</th><th>Risque</th><th>Acces</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr>
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                            <td>{{ $row['service'] }}</td>
                            <td>{{ $row['libelle'] }}</td>
                            <td>{{ $row['retard_jours'] }}j</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($validationTone($row['validation_status'])) }}">{{ $validationStatusLabel($row['validation_status']) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['niveau_risque'] ?? 0))) }}">{{ number_format((float) ($row['niveau_risque'] ?? 0), 0) }}</span></td>
                            <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7">Aucune alerte critique transverse.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</article>
@endif

