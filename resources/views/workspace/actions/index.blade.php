@extends('layouts.workspace')

@section('content')
    @php
        $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
        $actionStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
        $validationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::validationStatus($status);
        $financingStatusOptions = is_array($financingStatusOptions ?? null) ? $financingStatusOptions : \App\Models\Action::financingStatusOptions();
        $currentViewMode = (string) ($filters['vue'] ?? '');
        $showDualActionTabs = (bool) ($showDualActionTabs ?? false);
        $viewModeLabel = match ($currentViewMode) {
            'pilotage' => 'Actions pilotées',
            'mes_actions' => 'Mes actions',
            default => 'Vue complète',
        };
        $paginationRange = $rows->total() > 0
            ? $rows->firstItem().' - '.$rows->lastItem()
            : '0';
        $createRouteParams = $currentViewMode === 'mes_actions' ? ['vue' => 'mes_actions'] : [];
        $listing = collect($rows->items());
        $summary = is_array($summary ?? null) ? $summary : [];
        $hasSummaryStatusCounts = is_array($summary['status_counts'] ?? null);
        $summaryStatusCounts = $hasSummaryStatusCounts ? $summary['status_counts'] : [];
        $summaryTotal = (int) ($summary['total'] ?? $rows->total());
        $avgProgression = (float) ($summary['avg_progression'] ?? ($listing->avg(fn ($item) => (float) ($item->progression_reelle ?? 0)) ?? 0));
        $avgKpi = (float) ($summary['avg_kpi_global'] ?? ($listing->avg(fn ($item) => (float) ($item->actionKpi?->kpi_global ?? 0)) ?? 0));
        $fundedCount = (int) ($summary['funded_count'] ?? $listing->where('financement_requis', true)->count());
        $sc = fn (string $key): int => $hasSummaryStatusCounts
            ? (int) ($summaryStatusCounts[$key] ?? 0)
            : $listing->where('statut_dynamique', $key)->count();
        $statusCounts = [
            'non_demarre'       => $sc('non_demarre'),
            'en_cours'          => $sc('en_cours'),
            'en_retard'         => $sc('en_retard'),
            'a_risque'          => $sc('a_risque'),
            'en_avance'         => $sc('en_avance'),
            'suspendu'          => $sc('suspendu'),
            'annule'            => $sc('annule'),
            'a_corriger'        => $sc('a_corriger'),
            'acheve_dans_delai' => $sc('acheve_dans_delai'),
            'acheve_hors_delai' => $sc('acheve_hors_delai'),
            'cloturee'          => $sc('cloturee'),
            'achevees'          => $hasSummaryStatusCounts
                ? (int) (($summaryStatusCounts['acheve_dans_delai'] ?? 0) + ($summaryStatusCounts['acheve_hors_delai'] ?? 0))
                : $listing->filter(fn ($item) => in_array($item->statut_dynamique, ['acheve_dans_delai', 'acheve_hors_delai'], true))->count(),
        ];
        $statusStyles = [
            'non_demarre'       => 'anbg-badge anbg-badge-neutral',
            'en_cours'          => 'anbg-badge anbg-badge-info',
            'a_risque'          => 'anbg-badge anbg-badge-warning',
            'en_avance'         => 'anbg-badge anbg-badge-success',
            'en_retard'         => 'anbg-badge anbg-badge-danger',
            'suspendu'          => 'anbg-badge anbg-badge-danger',
            'annule'            => 'anbg-badge anbg-badge-neutral',
            'a_corriger'        => 'anbg-badge anbg-badge-warning',
            'acheve_dans_delai' => 'anbg-badge anbg-badge-success',
            'acheve_hors_delai' => 'anbg-badge anbg-badge-warning',
            'cloturee'          => 'anbg-badge anbg-badge-success',
        ];
        $summaryCards = [
            ['label' => 'Total',                    'value' => $summaryTotal,                           'meta' => null, 'href' => route('workspace.actions.index'),                              'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'En cours',                 'value' => $statusCounts['en_cours'],               'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_cours']),    'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Non démarrées',            'value' => $statusCounts['non_demarre'],            'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'non_demarre']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'En retard',                'value' => $statusCounts['en_retard'],              'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_retard']),   'badge' => null, 'badge_tone' => $statusCounts['en_retard'] > 0 ? 'danger' : 'neutral'],
            ['label' => 'À risque',                 'value' => $statusCounts['a_risque'],               'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'a_risque']),    'badge' => null, 'badge_tone' => $statusCounts['a_risque'] > 0 ? 'warning' : 'neutral'],
            ['label' => 'À corriger',               'value' => $statusCounts['a_corriger'],             'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'a_corriger']),  'badge' => null, 'badge_tone' => $statusCounts['a_corriger'] > 0 ? 'warning' : 'neutral'],
            ['label' => 'Achevées',                 'value' => $statusCounts['achevees'],               'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'achevees']),    'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Clôturées',                'value' => $statusCounts['cloturee'],               'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'cloturee']),    'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Progression moyenne',      'value' => number_format($avgProgression, 1).'%',  'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'progression_desc']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => $metricLabel('global'),     'value' => number_format($avgKpi, 1),              'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc']),'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Financement requis',       'value' => $fundedCount,                           'meta' => null, 'href' => route('workspace.actions.index', ['financement_requis' => 1]),  'badge' => null, 'badge_tone' => 'neutral'],
        ];
    @endphp

    <div class="app-screen-flow">
    <section class="showcase-toolbar mb-4 app-screen-block">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="showcase-panel-title">Vue des actions</h2>
                @if ($showDualActionTabs)
                    <a class="btn btn-sm {{ $currentViewMode === 'pilotage' ? 'btn-primary' : 'btn-secondary' }} rounded-xl px-3 py-1.5" href="{{ route('workspace.actions.index', ['vue' => 'pilotage']) }}">Actions pilotées</a>
                    <a class="btn btn-sm {{ $currentViewMode === 'mes_actions' ? 'btn-primary' : 'btn-secondary' }} rounded-xl px-3 py-1.5" href="{{ route('workspace.actions.index', ['vue' => 'mes_actions']) }}">Mes actions</a>
                @endif
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4 app-screen-kpis">
        @foreach ($summaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
            />
        @endforeach
    </section>

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Filtres de navigation</h2>
            </div>
            <a class="btn btn-secondary rounded-2xl px-4 py-2" href="{{ route('workspace.actions.index') }}">
                Réinitialiser
            </a>
        </div>
        <form method="GET" action="{{ route('workspace.actions.index') }}">
            @if ($filters['vue'] !== '')
                <input type="hidden" name="vue" value="{{ $filters['vue'] }}">
            @endif
            @if ($filters['statut_validation_min'] !== '')
                <input type="hidden" name="statut_validation_min" value="{{ $filters['statut_validation_min'] }}">
            @endif
            <div class="showcase-filter-grid">
                <div>
                    <label for="q">Recherche</label>
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre, description, resultat">
                </div>
                <div>
                    <label for="pta_id">PTA</label>
                    <select id="pta_id" name="pta_id">
                        <option value="">Tous</option>
                        @foreach ($ptaOptions as $pta)
                            <option value="{{ $pta->id }}" @selected($filters['pta_id'] === $pta->id)>
                                #{{ $pta->id }} - {{ $pta->titre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut">Statut dynamique</label>
                    <select id="statut" name="statut">
                        <option value="">Tous</option>
                        @foreach ($statusOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $status === 'achevees' ? 'Acheve' : $actionStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="statut_validation">Validation</label>
                    <select id="statut_validation" name="statut_validation">
                        <option value="">Toutes</option>
                        @foreach ($validationOptions as $status)
                            <option value="{{ $status }}" @selected($filters['statut_validation'] === $status)>{{ $validationStatusLabel($status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="financement_requis">Financement requis</label>
                    <select id="financement_requis" name="financement_requis">
                        <option value="">Tous</option>
                        <option value="1" @selected($filters['financement_requis'] === 1)>Oui</option>
                        <option value="0" @selected($filters['financement_requis'] === 0)>Non</option>
                    </select>
                </div>
                <div>
                    <label for="financement_statut">Statut financement</label>
                    <select id="financement_statut" name="financement_statut">
                        <option value="">Tous</option>
                        @foreach ($financingStatusOptions as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['financement_statut'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="sort">Tri</label>
                    <select id="sort" name="sort">
                        @foreach ($sortOptions as $sortValue => $sortLabel)
                            <option value="{{ $sortValue }}" @selected($filters['sort'] === $sortValue)>{{ $sortLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="per_page">Lignes par page</label>
                    <select id="per_page" name="per_page">
                        @foreach ([15, 25, 50, 100] as $perPageOption)
                            <option value="{{ $perPageOption }}" @selected((int) ($filters['per_page'] ?? 15) === $perPageOption)>{{ $perPageOption }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @foreach (['direction_id', 'service_id', 'pas_objectif_id', 'annee', 'mois_demarrage', 'week_start', 'risque_label'] as $hiddenFilter)
                @if (!empty($filters[$hiddenFilter]))
                    <input type="hidden" name="{{ $hiddenFilter }}" value="{{ $filters[$hiddenFilter] }}">
                @endif
            @endforeach
            @if ($filters['without_kpi'])
                <input type="hidden" name="without_kpi" value="1">
            @endif
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn btn-primary rounded-2xl px-4 py-2.5" type="submit">
                    Appliquer les filtres
                </button>
            </div>
            @if ($filters['without_kpi'])
                <div class="mt-4 showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#F9B13C]"></span>
                        Actions sans indicateur
                    </span>
                </div>
            @endif
            @if ($filters['direction_id'] || $filters['service_id'] || $filters['pas_objectif_id'] || $filters['annee'] || $filters['mois_demarrage'] || $filters['week_start'] || $filters['risque_label'])
                <div class="mt-4 showcase-chip-row">
                    @if ($filters['direction_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3996D3]"></span>Direction #{{ $filters['direction_id'] }}</span>
                    @endif
                    @if ($filters['service_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1C203D]"></span>Service #{{ $filters['service_id'] }}</span>
                    @endif
                    @if ($filters['pas_objectif_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#8FC043]"></span>Objectif #{{ $filters['pas_objectif_id'] }}</span>
                    @endif
                    @if ($filters['annee'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F9B13C]"></span>Année {{ $filters['annee'] }}</span>
                    @endif
                    @if ($filters['mois_demarrage'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#6B7280]"></span>Demarrage {{ $filters['mois_demarrage'] }}</span>
                    @endif
                    @if ($filters['week_start'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#6B7280]"></span>Semaine du {{ $filters['week_start'] }}</span>
                    @endif
                    @if ($filters['risque_label'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#B42318]"></span>Risque {{ $filters['risque_label'] }}</span>
                    @endif
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des actions</h2>
            </div>
            <span class="showcase-chip">{{ $rows->total() }}</span>
        </div>

        <div class="overflow-auto eas-table-shell">
            <table class="dashboard-table min-w-full">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Action</th>
                        <th>PTA</th>
                        <th>Responsable</th>
                        <th>Progression</th>
                        <th>Cible</th>
                        <th>Statut</th>
                        <th>{{ $metricLabel('global') }}</th>
                        <th>Financement</th>
                        <th>Operations</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $semainesTotal = (int) ($row->semaines_total ?? 0);
                            $semainesRenseignees = (int) ($row->semaines_renseignees ?? 0);
                            $kpiGlobal = $row->actionKpi?->kpi_global;
                            $modeEvaluationLabel = $row->mode_evaluation_label ?? 'Par sous-actions';
                            $statusClass = $statusStyles[$row->statut_dynamique ?: 'non_demarre'] ?? $statusStyles['non_demarre'];
                            $progressValue = max(0, min(100, (float) ($row->progression_reelle ?? 0)));
                            $targetValue = max(0, (float) ($row->quantite_cible ?? 0));
                            $realizedValue = max(0, (float) ($row->quantite_realisee ?? 0));
                            $targetRate = $targetValue > 0 ? min(100, ($realizedValue / $targetValue) * 100) : (float) ($row->taux_atteinte_cible ?? 0);
                            $remainingValue = $targetValue > 0 ? max(0, $targetValue - $realizedValue) : 0;
                            $overachievementRate = (float) ($row->taux_depassement ?? ($targetValue > 0 && $realizedValue > $targetValue ? (($realizedValue - $targetValue) / $targetValue) * 100 : 0));
                            $progressColor = $progressValue >= 80 ? 'bg-[#8fc043]' : ($progressValue >= 50 ? 'bg-blue-500' : ($progressValue > 0 ? 'bg-[#f0e509]' : 'bg-slate-400'));
                            $kpiColor = $kpiGlobal !== null
                                ? ((float) $kpiGlobal >= 80 ? 'text-[#8fc043]' : ((float) $kpiGlobal >= 60 ? 'text-[#f9b13c]' : 'text-[#f9b13c]'))
                                : 'text-slate-400';
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-500">ACT-{{ str_pad((string) $row->id, 3, '0', STR_PAD_LEFT) }}</td>
                            <td class="min-w-[260px]">
                                <div class="font-semibold text-slate-900">{{ $row->libelle }}</div>
                                @if ($row->description)
                                    <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $row->description }}</p>
                                @endif
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @if ($row->date_echeance)
                                        <span class="anbg-badge anbg-badge-neutral">
                                            Échéance {{ \Illuminate\Support\Carbon::parse($row->date_echeance)->format('d/m/Y') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="min-w-[170px]">
                                <div class="font-medium text-slate-900">{{ $row->pta?->titre ?? '-' }}</div>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="font-medium text-slate-900">{{ $row->responsable?->name ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500">{{ $row->responsable?->agent_matricule ?? $row->responsable?->email ?? '-' }}</p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="mb-2 flex items-center justify-between gap-2 text-xs">
                                    <span class="font-semibold text-slate-700">{{ number_format($progressValue, 1) }}%</span>
                                    <span class="text-slate-500">Theo. {{ number_format((float) ($row->progression_theorique ?? 0), 1) }}%</span>
                                </div>
                                <div class="showcase-progress-track">
                                    <span class="showcase-progress-bar {{ $progressColor }}" style="width: {{ $progressValue }}%"></span>
                                </div>
                            </td>
                            <td class="min-w-[210px] text-sm text-slate-700">
                                <div class="font-semibold text-slate-900">{{ $modeEvaluationLabel }}</div>
                                @if ($row->usesQuantitativeProgress())
                                    <p class="mt-1 text-xs text-slate-500">
                                        Cible : {{ $row->quantite_cible !== null ? number_format((float) $row->quantite_cible, 2) : '0' }} {{ $row->unite_cible ?: '' }}
                                        | Realise : {{ number_format($realizedValue, 2) }}
                                        | Reste : {{ number_format($remainingValue, 2) }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Realisation : {{ number_format($targetRate, 1) }}%
                                        | Technique : {{ number_format((float) ($row->avancement_operationnel ?? 0), 1) }}%
                                        @if ($overachievementRate > 0)
                                            | Depassement : +{{ number_format($overachievementRate, 1) }}%
                                        @endif
                                    </p>
                                @else
                                    <p class="mt-1 text-xs text-slate-500">Sous-actions de suivi : {{ $semainesRenseignees }}/{{ $semainesTotal }}</p>
                                @endif
                            </td>
                            <td>
                                <span class="{{ $statusClass }} px-3">
                                    {{ $actionStatusLabel($row->statut_dynamique ?: 'non_demarre') }}
                                </span>
                            </td>
                            <td>
                                <div class="text-base font-semibold {{ $kpiColor }}">
                                    {{ $kpiGlobal !== null ? number_format((float) $kpiGlobal, 1) . '%' : '-' }}
                                </div>
                            </td>
                            <td>
                                <span class="{{ $row->financement_requis ? 'anbg-badge anbg-badge-warning' : 'anbg-badge anbg-badge-neutral' }} px-3">
                                    {{ $row->financement_requis ? ($financingStatusOptions[$row->financementStatus()] ?? 'A traiter DAF') : 'Non' }}
                                </span>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-follow btn-sm rounded-xl" href="{{ route('workspace.actions.suivi', $row) }}">Suivi</a>
                                    @if ($canWrite)
                                        <a class="btn btn-amber btn-sm rounded-xl" href="{{ route('workspace.actions.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.actions.destroy', $row) }}" data-confirm-message="Supprimer cette action ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-red btn-sm" type="submit">Supprimer</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-8 text-center text-slate-500">Aucune action trouvée pour les filtres courants.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pagination :paginator="$rows" label="actions filtrees" />
    </section>
    </div>
@endsection
