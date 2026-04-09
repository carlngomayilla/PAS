@extends('layouts.workspace')

@section('content')
    @php
        $metricLabel = static fn (string $metric): string => \App\Support\UiLabel::metric($metric);
        $actionStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
        $validationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::validationStatus($status);
        $listing = collect($rows->items());
        $avgProgression = $listing->avg(fn ($item) => (float) ($item->progression_reelle ?? 0)) ?? 0;
        $avgKpi = $listing->avg(fn ($item) => (float) ($item->actionKpi?->kpi_global ?? 0)) ?? 0;
        $fundedCount = $listing->where('financement_requis', true)->count();
        $statusCounts = [
            'en_retard' => $listing->where('statut_dynamique', 'en_retard')->count(),
            'en_cours' => $listing->where('statut_dynamique', 'en_cours')->count(),
            'achevees' => $listing->filter(fn ($item) => in_array($item->statut_dynamique, ['acheve_dans_delai', 'acheve_hors_delai'], true))->count(),
        ];
        $statusStyles = [
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
        $summaryCards = [
            [
                'label' => 'Listing courant',
                'value' => $listing->count(),
                'meta' => 'Elements affiches sur cette page',
                'href' => route('workspace.actions.index'),
                'badge' => null,
                'badge_tone' => 'neutral',
            ],
            [
                'label' => 'Progression moyenne',
                'value' => number_format($avgProgression, 1).'%',
                'meta' => $statusCounts['en_cours'].' actions en cours',
                'href' => route('workspace.actions.index', ['statut' => 'en_cours']),
                'badge' => null,
                'badge_tone' => 'neutral',
            ],
            [
                'label' => $metricLabel('global'),
                'value' => number_format($avgKpi, 1),
                'meta' => 'Lecture directe de la performance courante',
                'href' => route('workspace.actions.index', ['sort' => 'kpi_global_desc']),
                'badge' => null,
                'badge_tone' => 'neutral',
            ],
            [
                'label' => 'Financement requis',
                'value' => $fundedCount,
                'meta' => 'Actions avec besoin financier sur cette page',
                'href' => route('workspace.actions.index', ['financement_requis' => 1]),
                'badge' => null,
                'badge_tone' => 'neutral',
            ],
        ];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">Execution operationnelle</span>
                <h1 class="showcase-title">Actions</h1>
                <div class="showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-blue-600"></span>
                        {{ $rows->total() }} actions dans le perimetre courant
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-red-500"></span>
                        {{ $statusCounts['en_retard'] }} en retard sur cette page
                    </span>
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#8fc043]"></span>
                        {{ $statusCounts['achevees'] }} achevees sur cette page
                    </span>
                </div>
            </div>
            <div class="showcase-action-row">
                @if ($canWrite)
                    <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.create') }}">
                        Nouvelle action
                    </a>
                @endif
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.pilotage') }}">
                    Voir le pilotage
                </a>
            </div>
        </div>
    </section>

    <section class="showcase-summary-grid mb-4">
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

    <section class="showcase-toolbar mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Filtres de navigation</h2>
            </div>
            <a class="btn btn-secondary rounded-2xl px-4 py-2" href="{{ route('workspace.actions.index') }}">
                Reinitialiser
            </a>
        </div>
        <form method="GET" action="{{ route('workspace.actions.index') }}">
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
                    <label for="sort">Tri</label>
                    <select id="sort" name="sort">
                        @foreach ($sortOptions as $sortValue => $sortLabel)
                            <option value="{{ $sortValue }}" @selected($filters['sort'] === $sortValue)>{{ $sortLabel }}</option>
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
                @if ($canWrite)
                    <a class="btn btn-green rounded-2xl px-4 py-2.5" href="{{ route('workspace.actions.create') }}">
                        Creer une action
                    </a>
                @endif
            </div>
            @if ($filters['without_kpi'])
                <div class="mt-4 showcase-chip-row">
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot bg-[#F59E0B]"></span>
                        Actions sans indicateur
                    </span>
                </div>
            @endif
            @if ($filters['direction_id'] || $filters['service_id'] || $filters['pas_objectif_id'] || $filters['annee'] || $filters['mois_demarrage'] || $filters['week_start'] || $filters['risque_label'])
                <div class="mt-4 showcase-chip-row">
                    @if ($filters['direction_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3B82F6]"></span>Direction #{{ $filters['direction_id'] }}</span>
                    @endif
                    @if ($filters['service_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1E3A8A]"></span>Service #{{ $filters['service_id'] }}</span>
                    @endif
                    @if ($filters['pas_objectif_id'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#10B981]"></span>Objectif #{{ $filters['pas_objectif_id'] }}</span>
                    @endif
                    @if ($filters['annee'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F59E0B]"></span>Annee {{ $filters['annee'] }}</span>
                    @endif
                    @if ($filters['mois_demarrage'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#6B7280]"></span>Demarrage {{ $filters['mois_demarrage'] }}</span>
                    @endif
                    @if ($filters['week_start'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#6B7280]"></span>Semaine du {{ $filters['week_start'] }}</span>
                    @endif
                    @if ($filters['risque_label'])
                        <span class="showcase-chip"><span class="showcase-chip-dot bg-[#EF4444]"></span>Risque {{ $filters['risque_label'] }}</span>
                    @endif
                </div>
            @endif
        </form>
    </section>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des actions</h2>
            </div>
            <span class="showcase-chip">{{ $rows->total() }} lignes au total</span>
        </div>

        <div class="overflow-auto">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Action</th>
                        <th>PTA</th>
                        <th>Responsable</th>
                        <th>Progression</th>
                        <th>Frequence</th>
                        <th>Periodes</th>
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
                            $statusClass = $statusStyles[$row->statut_dynamique ?: 'non_demarre'] ?? $statusStyles['non_demarre'];
                            $progressValue = max(0, min(100, (float) ($row->progression_reelle ?? 0)));
                            $progressColor = $progressValue >= 80 ? 'bg-[#8fc043]' : ($progressValue >= 50 ? 'bg-blue-500' : ($progressValue > 0 ? 'bg-[#f0e509]' : 'bg-slate-400'));
                            $kpiColor = $kpiGlobal !== null
                                ? ((float) $kpiGlobal >= 80 ? 'text-[#8fc043] dark:text-[#f8e932]' : ((float) $kpiGlobal >= 60 ? 'text-[#f9b13c] dark:text-[#f8e932]' : 'text-[#f9b13c] dark:text-[#f8e932]'))
                                : 'text-slate-400 dark:text-slate-500';
                        @endphp
                        <tr>
                            <td class="font-mono text-xs text-slate-500 dark:text-slate-400">ACT-{{ str_pad((string) $row->id, 3, '0', STR_PAD_LEFT) }}</td>
                            <td class="min-w-[260px]">
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $row->libelle }}</div>
                                <p class="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{{ $row->description ?: 'Aucune description renseignee.' }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="anbg-badge anbg-badge-neutral">Type {{ $row->type_cible }}</span>
                                    @if ($row->date_echeance)
                                        <span class="anbg-badge anbg-badge-neutral">
                                            Echeance {{ \Illuminate\Support\Carbon::parse($row->date_echeance)->format('d/m/Y') }}
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="min-w-[170px]">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->pta?->titre ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">PTA rattache a l'action</p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="font-medium text-slate-900 dark:text-slate-100">{{ $row->responsable?->name ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $row->responsable?->agent_matricule ?? $row->responsable?->email ?? '-' }}</p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="mb-2 flex items-center justify-between gap-2 text-xs">
                                    <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($progressValue, 1) }}%</span>
                                    <span class="text-slate-500 dark:text-slate-400">Theo {{ number_format((float) ($row->progression_theorique ?? 0), 1) }}%</span>
                                </div>
                                <div class="showcase-progress-track">
                                    <span class="showcase-progress-bar {{ $progressColor }}" style="width: {{ $progressValue }}%"></span>
                                </div>
                            </td>
                            <td class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ $row->frequence_execution ?: 'hebdomadaire' }}</td>
                            <td>
                                <div class="font-semibold text-slate-900 dark:text-slate-100">{{ $semainesRenseignees }}/{{ $semainesTotal }}</div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Periodes renseignees</p>
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
                                    {{ $row->financement_requis ? 'Oui' : 'Non' }}
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
                            <td colspan="11" class="py-8 text-center text-slate-500 dark:text-slate-400">Aucune action trouvee pour les filtres courants.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pagination">{{ $rows->links() }}</div>
    </section>
@endsection
