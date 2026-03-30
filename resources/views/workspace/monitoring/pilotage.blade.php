@extends('layouts.workspace')

@section('title', 'Pilotage global')

@section('content')
    @php
        $pipelineGapLabels = [
            'pas_sans_pao' => 'Couverture PAO incomplete',
            'pao_sans_pta' => 'PAO sans PTA',
            'pta_sans_action' => 'PTA sans action',
            'action_sans_kpi' => 'Actions sans KPI',
            'kpi_sans_mesure' => 'KPI sans mesure',
        ];
        $pilotageStatusBadges = [
            'non_demarre' => 'anbg-badge anbg-badge-neutral',
            'en_cours' => 'anbg-badge anbg-badge-info',
            'a_risque' => 'anbg-badge anbg-badge-warning',
            'en_avance' => 'anbg-badge anbg-badge-success',
            'en_retard' => 'anbg-badge anbg-badge-danger',
            'suspendu' => 'anbg-badge anbg-badge-danger',
            'annule' => 'anbg-badge anbg-badge-neutral',
            'acheve_dans_delai' => 'anbg-badge anbg-badge-success',
            'acheve_hors_delai' => 'anbg-badge anbg-badge-warning',
        ];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Pilotage global</span>
                <h1 class="showcase-title">PAS / PAO / PTA</h1>
                <p class="showcase-subtitle">
                    Vue consolidee des volumes, ruptures de chaine, retards et realisation par annee.
                    Les chiffres affiches sont filtres selon le perimetre courant.
                </p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Genere le {{ $generatedAt }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        Role {{ $scope['role'] }}
                    </span>
                    @if ($scope['direction_id'])
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-[#8fc043]"></span>
                            Direction #{{ $scope['direction_id'] }}
                        </span>
                    @endif
                    @if ($scope['service_id'])
                        <span class="showcase-chip">
                            <span class="showcase-chip-dot bg-[#f0e509]"></span>
                            Service #{{ $scope['service_id'] }}
                        </span>
                    @endif
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('dashboard') }}">
                    Retour dashboard
                </a>
                <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting') }}">
                    Ouvrir le reporting
                </a>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.alertes') }}">
                    Detail des alertes
                </a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
        @foreach ($totals as $key => $value)
            <article class="showcase-kpi-card">
                <p class="showcase-kpi-label">{{ str_replace('_', ' ', ucfirst($key)) }}</p>
                <p class="showcase-kpi-number">{{ $value }}</p>
                <p class="showcase-kpi-meta">Indicateur de volume</p>
            </article>
        @endforeach
    </section>

    <section class="mb-4 grid gap-4 xl:grid-cols-2">
        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Taux d'avancement</h2>
            <p class="showcase-panel-subtitle">Lecture synthese des realisations par niveau de planification.</p>
            <div class="mt-4 showcase-summary-grid">
                @foreach ($completion as $key => $value)
                    <article class="showcase-inline-stat">
                        <p class="showcase-data-key">{{ str_replace('_', ' ', ucfirst($key)) }}</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-slate-100">{{ number_format($value, 2) }}%</p>
                    </article>
                @endforeach
            </div>
        </article>

        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Ruptures de chaine</h2>
            <p class="showcase-panel-subtitle">Points de rupture entre PAS, PAO, PTA, actions et KPI.</p>
            <div class="mt-4 showcase-summary-grid">
                @foreach ($pipelineGaps as $key => $value)
                    <article class="showcase-inline-stat">
                        <p class="showcase-data-key">{{ $pipelineGapLabels[$key] ?? str_replace('_', ' ', ucfirst($key)) }}</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950 dark:text-slate-100">{{ $value }}</p>
                    </article>
                @endforeach
            </div>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Alertes critiques</h2>
                <p class="showcase-panel-subtitle">Retards, sous-seuils KPI et alertes de structure du perimetre courant.</p>
            </div>
            <a class="btn btn-blue rounded-2xl px-4 py-2" href="{{ route('workspace.alertes') }}">
                Acceder aux alertes
            </a>
        </div>
        <div class="showcase-summary-grid">
            @foreach ($alertes as $key => $value)
                <article class="showcase-kpi-card">
                    <p class="showcase-kpi-label">{{ str_replace('_', ' ', ucfirst($key)) }}</p>
                    <p class="showcase-kpi-number">{{ $value }}</p>
                    <p class="showcase-kpi-meta">Suivi prioritaire</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Statuts par module</h2>
        <p class="showcase-panel-subtitle">Repartition consolidee des statuts sur les modules accessibles.</p>
        <div class="mt-4 grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
            @foreach ($statusBreakdown as $module => $rows)
                <article class="rounded-[1.2rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70">
                    <strong class="text-slate-900 dark:text-slate-100">{{ strtoupper($module) }}</strong>
                    <div class="mt-3 overflow-auto">
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
                                    <tr>
                                        <td colspan="2" class="text-slate-600">Aucune donnee</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Vue consolidee du PAS</h2>
        <p class="showcase-panel-subtitle">Capacite de transformation du PAS vers les niveaux operationnels et executifs.</p>
        <div class="mt-4 table-wrap">
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
                        <th>Progression moy.</th>
                        <th>Taux realisation</th>
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
                            <td>{{ number_format((float) $row['progression_moyenne'], 2) }}%</td>
                            <td>{{ number_format((float) $row['taux_realisation'], 2) }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="text-slate-600">Aucun PAS consolide dans le perimetre courant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Comparaison interannuelle</h2>
        <p class="showcase-panel-subtitle">Analyse d'evolution entre les annees disponibles pour le meme perimetre.</p>
        <div class="mt-4 table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Annee</th>
                        <th>PAO</th>
                        <th>PTA</th>
                        <th>Actions</th>
                        <th>Actions validees</th>
                        <th>Actions en retard</th>
                        <th>Progression moyenne</th>
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
                            <td colspan="8" class="text-slate-600">Aucune comparaison interannuelle disponible.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Actions proches de l'echeance</h2>
        <p class="showcase-panel-subtitle">Liste courte des actions actives a surveiller dans l immediat.</p>
        <div class="mt-4 overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Action</th>
                        <th>PTA</th>
                        <th>Responsable</th>
                        <th>Echeance</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($actionsProches as $row)
                        <tr>
                            <td>{{ $row->id }}</td>
                            <td>{{ $row->libelle }}</td>
                            <td>{{ $row->pta?->titre ?? '-' }}</td>
                            <td>{{ $row->responsable?->name ?? '-' }}</td>
                            <td>{{ $row->date_echeance }}</td>
                            <td>
                                <span class="{{ $pilotageStatusBadges[$row->statut_dynamique] ?? 'anbg-badge anbg-badge-neutral' }} px-3">
                                    {{ $row->statut_dynamique }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-slate-600">Aucune action active avec echeance a venir.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

@endsection
