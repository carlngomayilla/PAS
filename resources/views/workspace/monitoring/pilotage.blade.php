@extends('layouts.workspace')

@section('title', 'Pilotage global')

@section('content')
    @php
        $pipelineGapLabels = [
            'pas_sans_pao' => 'Couverture PAO incomplete',
            'pao_sans_pta' => 'PAO sans PTA',
            'pta_sans_action' => 'PTA sans action',
            'action_sans_kpi' => 'Actions sans indicateur',
            'kpi_sans_mesure' => 'Indicateur sans mesure',
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

    @php
        $roleProfile = $roleProfile ?? ['eyebrow' => 'Pilotage global', 'title' => 'PAS / PAO / PTA', 'subtitle' => 'Vue de pilotage annuelle.', 'role_label' => strtoupper((string) ($scope['role'] ?? 'lecture'))];
        $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
        $officialBaseLabel = (string) ($officialPolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $officialAverageText = 'Moyenne sur '.$officialBaseLabel;
        $officialCompletedText = 'Achevees sur '.$officialBaseLabel;
        $officialFilters = (array) ($officialPolicy['route_filters'] ?? []);
        $totalLinks = [
            'pas_total' => route('workspace.pas.index'),
            'paos_total' => route('workspace.pao.index'),
            'ptas_total' => route('workspace.pta.index'),
            'actions_total' => route('workspace.actions.index'),
            'actions_validees' => route('workspace.actions.index', $officialFilters),
            'kpis_total' => route('workspace.reporting'),
            'kpi_mesures_total' => route('workspace.reporting'),
            'objectifs_operationnels_total' => route('workspace.reporting'),
        ];
        $completionLinks = [
            'paos_valides_pct' => route('workspace.pao.index', ['statut' => 'valide_ou_verrouille']),
            'ptas_valides_pct' => route('workspace.pta.index', ['statut' => 'valide_ou_verrouille']),
            'actions_terminees_pct' => route('workspace.actions.index', ['statut' => 'achevees']),
            'actions_validees_pct' => route('workspace.actions.index', $officialFilters),
            'obj_ops_termines_pct' => route('workspace.reporting'),
            'kpis_couverts_pct' => route('workspace.reporting'),
            'financement_documente_pct' => route('workspace.actions.index', ['financement_requis' => 1]),
        ];
        $pipelineLinks = [
            'pas_sans_pao' => route('workspace.pas.index', ['without_pao' => 1]),
            'pao_sans_pta' => route('workspace.pao.index', ['without_pta' => 1]),
            'pta_sans_action' => route('workspace.pta.index', ['without_action' => 1]),
            'action_sans_kpi' => route('workspace.actions.index', ['without_kpi' => 1]),
            'kpi_sans_mesure' => route('workspace.reporting'),
        ];
        $alertLinks = [
            'actions_en_retard' => route('workspace.actions.index', ['statut' => 'en_retard']),
            'mesures_kpi_sous_seuil' => route('workspace.alertes', ['niveau' => 'warning', 'limit' => 100]),
        ];
        $totalBadges = [
            'actions_validees' => ['label' => null, 'tone' => 'neutral'],
            'default' => ['label' => null, 'tone' => 'neutral'],
        ];
        $completionBadges = [
            'actions_validees_pct' => ['label' => null, 'tone' => 'neutral'],
            'paos_valides_pct' => ['label' => null, 'tone' => 'neutral'],
            'ptas_valides_pct' => ['label' => null, 'tone' => 'neutral'],
            'default' => ['label' => null, 'tone' => 'neutral'],
        ];
        $pipelineBadge = ['label' => null, 'tone' => 'neutral'];
        $alertBadge = ['label' => null, 'tone' => 'neutral'];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">{{ $roleProfile['eyebrow'] }}</span>
                <h1 class="showcase-title">{{ $roleProfile['title'] }}</h1>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        {{ $roleProfile['role_label'] }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#10B981]"></span>
                        {{ $officialBaseText }}
                    </span>
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

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="anbg-badge anbg-badge-success px-3 py-1">Actions validees</span>
        <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $officialBaseText }}</span>
    </div>

    <section class="showcase-summary-grid mb-4">
        @foreach ($totals as $key => $value)
            @php $badge = $totalBadges[$key] ?? $totalBadges['default']; @endphp
            <x-stat-card-link
                :href="$totalLinks[$key] ?? route('workspace.pilotage')"
                :label="str_replace('_', ' ', ucfirst($key))"
                :value="$value"
                meta="Indicateur de volume"
                :badge="$badge['label']"
                :badge-tone="$badge['tone']"
            />
        @endforeach
    </section>

    <section class="mb-4 space-y-4">
        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Taux d'avancement</h2>
            <div class="mt-4 showcase-summary-grid">
                @foreach ($completion as $key => $value)
                    @php $badge = $completionBadges[$key] ?? $completionBadges['default']; @endphp
                    <x-stat-card-link
                        :href="$completionLinks[$key] ?? route('workspace.pilotage')"
                        :label="str_replace('_', ' ', ucfirst($key))"
                        :value="number_format($value, 2).'%'" 
                        card-class="showcase-inline-stat"
                        label-class="showcase-data-key"
                        value-class="mt-2 text-2xl font-semibold text-slate-950 dark:text-slate-100"
                        :badge="$badge['label']"
                        :badge-tone="$badge['tone']"
                    />
                @endforeach
            </div>
        </article>

        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Ruptures de chaine</h2>
            <div class="mt-4 showcase-summary-grid">
                @foreach ($pipelineGaps as $key => $value)
                    <x-stat-card-link
                        :href="$pipelineLinks[$key] ?? route('workspace.pilotage')"
                        :label="$pipelineGapLabels[$key] ?? str_replace('_', ' ', ucfirst($key))"
                        :value="$value"
                        card-class="showcase-inline-stat"
                        label-class="showcase-data-key"
                        value-class="mt-2 text-2xl font-semibold text-slate-950 dark:text-slate-100"
                        :badge="$pipelineBadge['label']"
                        :badge-tone="$pipelineBadge['tone']"
                    />
                @endforeach
            </div>
        </article>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Alertes critiques</h2>
            </div>
            <a class="btn btn-blue rounded-2xl px-4 py-2" href="{{ route('workspace.alertes') }}">
                Acceder aux alertes
            </a>
        </div>
        <div class="showcase-summary-grid">
            @foreach ($alertes as $key => $value)
                <x-stat-card-link
                    :href="$alertLinks[$key] ?? route('workspace.alertes')"
                    :label="str_replace('_', ' ', ucfirst($key))"
                    :value="$value"
                    meta="Suivi prioritaire"
                    :badge="$alertBadge['label']"
                    :badge-tone="$alertBadge['tone']"
                />
            @endforeach
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Statuts par module</h2>
        <div class="mt-4 space-y-4">
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
        <h2 class="showcase-panel-title">Vue du PAS</h2>
        <div class="mt-2 flex flex-wrap gap-2">
            <span class="anbg-badge anbg-badge-success px-3 py-1">Actions validees</span>
            <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $officialBaseText }}</span>
        </div>
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
                            <td colspan="10" class="text-slate-600">Aucun PAS dans le perimetre courant.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="showcase-panel mb-4">
        <h2 class="showcase-panel-title">Comparaison interannuelle</h2>
        <div class="mt-2 flex flex-wrap gap-2">
            <span class="anbg-badge anbg-badge-success px-3 py-1">Actions validees</span>
            <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $officialBaseText }}</span>
        </div>
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

