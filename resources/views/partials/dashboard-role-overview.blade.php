@php
    $primaryRows = $roleDashboard['primary_rows'] ?? [];
    $secondaryRows = $roleDashboard['secondary_rows'] ?? [];
    $comparisonChart = $roleDashboard['comparison_chart'] ?? [];
    $statusChart = $roleDashboard['status_chart'] ?? [];
    $trendChart = $roleDashboard['trend_chart'] ?? [];
    $supportChart = $roleDashboard['support_chart'] ?? [];
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

<div class="showcase-panel mb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="showcase-panel-title">{{ $roleDashboard['hero']['title'] ?? 'Lecture par profil' }}</h2>
            <p class="showcase-panel-subtitle">{{ $roleDashboard['hero']['subtitle'] ?? 'Synthese metier specifique au profil connecte.' }}</p>
        </div>
        <span class="showcase-chip">{{ strtoupper((string) ($roleDashboard['role'] ?? $dashboardRole)) }}</span>
    </div>
</div>

@if (count($comparisonChart['labels'] ?? []) > 0)
    <article class="showcase-panel mb-4">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $comparisonChart['title'] ?? 'Operationnel vs officiel' }}</h2>
                <p class="showcase-panel-subtitle">{{ $comparisonChart['subtitle'] ?? 'Lecture comparee des deux niveaux de lecture.' }}</p>
            </div>
            <span class="showcase-chip">{{ count($comparisonChart['labels'] ?? []) }} indicateurs</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-role-comparison-chart" class="dashboard-chart-host"></div></div>
    </article>
@endif

<div class="grid gap-4 xl:grid-cols-[0.92fr_1.08fr]">
    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $statusChart['title'] ?? 'Repartition des statuts' }}</h2>
                <p class="showcase-panel-subtitle">{{ $statusChart['subtitle'] ?? 'Repartition des actions par statut.' }}</p>
            </div>
            <span class="showcase-chip">{{ count($statusChart['labels'] ?? []) }} statuts</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-role-status-chart" class="dashboard-chart-host"></div></div>
    </article>

    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $trendChart['title'] ?? 'Tendance' }}</h2>
                <p class="showcase-panel-subtitle">{{ $trendChart['subtitle'] ?? 'Evolution temporelle du perimetre courant.' }}</p>
            </div>
            <span class="showcase-chip">{{ count($trendChart['labels'] ?? []) }} points</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-role-trend-chart" class="dashboard-chart-host"></div></div>
    </article>
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-[0.95fr_1.05fr]">
    <article class="showcase-panel">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">{{ $supportChart['title'] ?? 'Lecture metier' }}</h2>
                <p class="showcase-panel-subtitle">{{ $supportChart['subtitle'] ?? 'Analyse specifique au profil.' }}</p>
            </div>
            <span class="showcase-chip">{{ count($supportChart['labels'] ?? []) }} lignes</span>
        </div>
        <div class="dashboard-canvas"><div id="dashboard-role-support-chart" class="dashboard-chart-host"></div></div>
    </article>

    <article class="showcase-panel">
        @if ($dashboardRole === 'agent')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Mes actions prioritaires</h2>
                    <p class="showcase-panel-subtitle">Actions a suivre en premier, avec lecture execution et validation.</p>
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
                    <p class="showcase-panel-subtitle">Soumissions en attente d evaluation chef de service.</p>
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
                    <p class="showcase-panel-subtitle">Comparaison directe des services de la direction.</p>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} services</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Service</th><th>Actions</th><th>Achevees</th><th>Retard</th><th>Validees direction</th><th>Score</th></tr></thead>
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
                            <tr><td colspan="6">Aucun service consolide pour cette direction.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @elseif ($dashboardRole === 'dg')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Directions : operationnel vs officiel</h2>
                    <p class="showcase-panel-subtitle">Comparer direction par direction le portefeuille total et le socle officiel valide direction.</p>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} directions</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Officiel</th><th>Exec. op.</th><th>Exec. off.</th><th>Score op.</th><th>Score off.</th></tr></thead>
                    <tbody>
                        @forelse ($primaryRows as $row)
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                                <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['actions_officielles'] }}</td>
                                <td>{{ number_format((float) ($row['taux_execution_operationnel'] ?? 0), 0) }}%</td>
                                <td>{{ number_format((float) ($row['taux_execution_officiel'] ?? 0), 0) }}%</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score_operationnel'] ?? 0))) }}">{{ number_format((float) ($row['score_operationnel'] ?? 0), 0) }}</span></td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score_officiel'] ?? 0))) }}">{{ number_format((float) ($row['score_officiel'] ?? 0), 0) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7">Aucune direction consolidee disponible.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'planification')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Classement des directions</h2>
                    <p class="showcase-panel-subtitle">Comparaison directe des directions sur execution, validation et score.</p>
                </div>
                <span class="showcase-chip">{{ count($primaryRows) }} directions</span>
            </div>
            <div class="overflow-x-auto">
                <table class="dashboard-table">
                    <thead><tr><th>Direction</th><th>Actions</th><th>Achevees</th><th>Retard</th><th>Validees direction</th><th>Score</th></tr></thead>
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
                            <tr><td colspan="6">Aucune direction consolidee disponible.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($dashboardRole === 'cabinet')
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Validations en attente</h2>
                    <p class="showcase-panel-subtitle">Actions soumises a suivre pour preparer l arbitrage et l accompagnement.</p>
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
</div>

<article class="showcase-panel mt-4">
    @if ($dashboardRole === 'agent')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Mes actions en retard</h2>
                <p class="showcase-panel-subtitle">Retards individuels a traiter en priorite.</p>
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
                <p class="showcase-panel-subtitle">Lecture simple de la charge et du taux d execution par agent.</p>
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
                <p class="showcase-panel-subtitle">Retards, validations et risques sur les actions les plus sensibles.</p>
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
                <p class="showcase-panel-subtitle">Directions avec retards ou ecart marque entre portefeuille operationnel et socle officiel.</p>
            </div>
            <span class="showcase-chip">{{ count($secondaryRows) }} lignes</span>
        </div>
        <div class="overflow-x-auto">
            <table class="dashboard-table">
                <thead><tr><th>Direction</th><th>Service critique</th><th>Retard</th><th>Validation off.</th><th>Score op.</th><th>Score off.</th></tr></thead>
                <tbody>
                    @forelse ($secondaryRows as $row)
                        <tr class="dashboard-row-link" data-row-link="{{ $row['url'] }}">
                            <td class="font-semibold text-slate-900 dark:text-slate-100">{{ $row['direction'] }}</td>
                            <td>{{ $row['service_critique'] }}</td>
                            <td>{{ $row['retards'] }}</td>
                            <td>{{ number_format((float) ($row['taux_validation'] ?? 0), 0) }}%</td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score_operationnel'] ?? 0))) }}">{{ number_format((float) ($row['score_operationnel'] ?? 0), 0) }}</span></td>
                            <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['score_officiel'] ?? 0))) }}">{{ number_format((float) ($row['score_officiel'] ?? 0), 0) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Aucune direction en difficulte actuellement.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'planification')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Actions critiques consolidees</h2>
                <p class="showcase-panel-subtitle">Actions les plus sensibles a l echelle transverse.</p>
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
                        <tr><td colspan="6">Aucune action critique consolidee.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($dashboardRole === 'cabinet')
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Alertes critiques transverses</h2>
                <p class="showcase-panel-subtitle">Points bloquants et actions sensibles a remonter rapidement.</p>
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
