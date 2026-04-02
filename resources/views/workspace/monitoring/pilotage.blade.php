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
        $roleProfile = $roleProfile ?? ['eyebrow' => 'Pilotage global', 'title' => 'PAS / PAO / PTA', 'subtitle' => 'Vue consolidee des volumes, ruptures de chaine, retards et realisation par annee.', 'role_label' => strtoupper((string) ($scope['role'] ?? 'lecture'))];
        $totalLinks = [
            'pas_total' => route('workspace.pas.index'),
            'paos_total' => route('workspace.pao.index'),
            'ptas_total' => route('workspace.pta.index'),
            'actions_total' => route('workspace.actions.index'),
            'actions_validees' => route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION]),
            'kpis_total' => route('workspace.reporting'),
            'kpi_mesures_total' => route('workspace.reporting'),
            'objectifs_operationnels_total' => route('workspace.reporting'),
        ];
        $completionLinks = [
            'paos_valides_pct' => route('workspace.pao.index', ['statut' => 'valide_ou_verrouille']),
            'ptas_valides_pct' => route('workspace.pta.index', ['statut' => 'valide_ou_verrouille']),
            'actions_terminees_pct' => route('workspace.actions.index', ['statut' => 'achevees']),
            'actions_validees_pct' => route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION]),
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
            'actions_validees' => ['label' => 'Officiel', 'tone' => 'success'],
            'default' => ['label' => 'Provisoire', 'tone' => 'info'],
        ];
        $completionBadges = [
            'actions_validees_pct' => ['label' => 'Officiel', 'tone' => 'success'],
            'paos_valides_pct' => ['label' => 'Valide', 'tone' => 'warning'],
            'ptas_valides_pct' => ['label' => 'Valide', 'tone' => 'warning'],
            'default' => ['label' => 'Provisoire', 'tone' => 'info'],
        ];
        $pipelineBadge = ['label' => 'Operationnel', 'tone' => 'neutral'];
        $alertBadge = ['label' => 'Operationnel', 'tone' => 'danger'];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">{{ $roleProfile['eyebrow'] }}</span>
                <h1 class="showcase-title">{{ $roleProfile['title'] }}</h1>
                <p class="showcase-subtitle">{{ $roleProfile['subtitle'] }} Les chiffres affiches sont filtres selon le perimetre courant.</p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        Genere le {{ $generatedAt }}
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#3996d3]"></span>
                        {{ $roleProfile['role_label'] }}
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

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span>
        <span class="anbg-badge anbg-badge-warning px-3 py-1">Valide</span>
        <span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span>
    </div>

    @if (($roleProfile['role'] ?? null) === 'dg' && is_array($dgComparison ?? null))
        <section class="showcase-panel mb-4">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Lecture DG : operationnel vs officiel</h2>
                    <p class="showcase-panel-subtitle">Le pilotage DG distingue ici le portefeuille total et le socle officiel valide direction.</p>
                </div>
                <span class="showcase-chip">DG</span>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="showcase-panel-title !text-base">Statistiques operationnelles</h3>
                            <p class="showcase-panel-subtitle">Portefeuille total visible par la DG.</p>
                        </div>
                        <span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span>
                    </div>
                    <div class="showcase-summary-grid">
                        <x-stat-card-link
                            :href="route('workspace.actions.index')"
                            label="Execution operationnelle"
                            :value="number_format((float) ($dgComparison['operational']['completion_rate'] ?? 0), 0).'%'" 
                            meta="Achevees sur tout le portefeuille"
                            badge="Provisoire"
                            badge-tone="info"
                        />
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut' => 'en_retard'])"
                            label="Delais operationnels"
                            :value="number_format((float) ($dgComparison['operational']['delay_rate'] ?? 0), 0).'%'" 
                            meta="Actions hors retard"
                            badge="Provisoire"
                            badge-tone="info"
                        />
                        <x-stat-card-link
                            :href="route('workspace.reporting')"
                            label="Score operationnel"
                            :value="number_format((float) ($dgComparison['operational']['score'] ?? 0), 0)"
                            meta="Moyenne sur toutes les actions visibles"
                            badge="Provisoire"
                            badge-tone="info"
                        />
                    </div>
                </div>

                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="showcase-panel-title !text-base">Statistiques officielles</h3>
                            <p class="showcase-panel-subtitle">Socle valide direction retenu pour la lecture institutionnelle.</p>
                        </div>
                        <span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span>
                    </div>
                    <div class="showcase-summary-grid">
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, 'statut' => 'achevees'])"
                            label="Execution officielle"
                            :value="number_format((float) ($dgComparison['official']['completion_rate'] ?? 0), 0).'%'" 
                            meta="Achevees sur actions validees direction"
                            badge="Officiel"
                            badge-tone="success"
                        />
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION])"
                            label="Delais officiels"
                            :value="number_format((float) ($dgComparison['official']['delay_rate'] ?? 0), 0).'%'" 
                            meta="Socle officiel hors retard"
                            badge="Officiel"
                            badge-tone="success"
                        />
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, 'sort' => 'kpi_global_desc'])"
                            label="Score officiel"
                            :value="number_format((float) ($dgComparison['official']['score'] ?? 0), 0)"
                            meta="Moyenne validee direction"
                            badge="Officiel"
                            badge-tone="success"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section class="showcase-panel mb-4">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Directions : operationnel vs officiel</h2>
                    <p class="showcase-panel-subtitle">Comparer rapidement les directions sur le portefeuille total et le socle officiel deja remonte.</p>
                </div>
                <span class="showcase-chip">{{ count($dgComparison['direction_rows'] ?? []) }} directions</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Direction</th>
                            <th>Actions</th>
                            <th>Officiel</th>
                            <th>Exec. op.</th>
                            <th>Exec. off.</th>
                            <th>Score op.</th>
                            <th>Score off.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($dgComparison['direction_rows'] ?? []) as $row)
                            <tr>
                                <td>{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['actions_officielles'] }}</td>
                                <td>{{ number_format((float) ($row['taux_execution_operationnel'] ?? 0), 2) }}%</td>
                                <td>{{ number_format((float) ($row['taux_execution_officiel'] ?? 0), 2) }}%</td>
                                <td>{{ number_format((float) ($row['score_operationnel'] ?? 0), 2) }}</td>
                                <td>{{ number_format((float) ($row['score_officiel'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-slate-600">Aucune comparaison disponible.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

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

    <section class="mb-4 grid gap-4 xl:grid-cols-2">
        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Taux d'avancement</h2>
            <p class="showcase-panel-subtitle">Lecture synthese des realisations par niveau de planification.</p>
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
                        hint="Voir la liste cible"
                        :badge="$badge['label']"
                        :badge-tone="$badge['tone']"
                    />
                @endforeach
            </div>
        </article>

        <article class="showcase-panel">
            <h2 class="showcase-panel-title">Ruptures de chaine</h2>
            <p class="showcase-panel-subtitle">Points de rupture entre PAS, PAO, PTA, actions et indicateurs.</p>
            <div class="mt-4 showcase-summary-grid">
                @foreach ($pipelineGaps as $key => $value)
                    <x-stat-card-link
                        :href="$pipelineLinks[$key] ?? route('workspace.pilotage')"
                        :label="$pipelineGapLabels[$key] ?? str_replace('_', ' ', ucfirst($key))"
                        :value="$value"
                        card-class="showcase-inline-stat"
                        label-class="showcase-data-key"
                        value-class="mt-2 text-2xl font-semibold text-slate-950 dark:text-slate-100"
                        hint="Voir les elements concernes"
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
                <p class="showcase-panel-subtitle">Retards, sous-seuils indicateur et alertes de structure du perimetre courant.</p>
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
        <p class="showcase-panel-subtitle">Repartition consolidee des statuts sur les modules accessibles.</p>
        <div class="mt-2"><span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span></div>
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
        <div class="mt-2"><span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span></div>
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
        <div class="mt-2"><span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span></div>
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
        <div class="mt-2"><span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span></div>
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
