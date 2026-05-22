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
        $avgKpi = (float) ($summary['avg_kpi_global'] ?? ($listing->avg(fn ($item) => (float) ($item->actionKpi?->kpi_performance ?? 0)) ?? 0));
        $avgConformite = (float) ($summary['avg_conformite'] ?? ($listing->avg(fn ($item) => (float) ($item->actionKpi?->kpi_conformite ?? 0)) ?? 0));
        $validatedCount = (int) ($summary['validated_count'] ?? 0);
        $pendingValidationCount = (int) ($summary['pending_validation_count'] ?? 0);
        $pendingJustificatifCount = (int) ($summary['pending_justificatif_count'] ?? 0);
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
        $validationStyles = [
            'non_soumise'        => 'anbg-badge anbg-badge-neutral',
            'soumise_chef'       => 'anbg-badge anbg-badge-warning',
            'rejetee_chef'       => 'anbg-badge anbg-badge-danger',
            'correction_demandee'=> 'anbg-badge anbg-badge-warning',
            'validee_chef'       => 'anbg-badge anbg-badge-info',
            'rejetee_direction'  => 'anbg-badge anbg-badge-danger',
            'validee_direction'  => 'anbg-badge anbg-badge-success',
        ];
        $summaryCards = [
            ['label' => 'Total actions', 'value' => $summaryTotal, 'meta' => null, 'href' => route('workspace.actions.index'), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Actions en cours', 'value' => $statusCounts['en_cours'] + $statusCounts['a_risque'] + $statusCounts['en_avance'], 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_cours']), 'badge' => null, 'badge_tone' => 'neutral'],
            ['label' => 'Actions en retard', 'value' => $statusCounts['en_retard'], 'meta' => null, 'href' => route('workspace.actions.index', ['statut' => 'en_retard']), 'badge' => null, 'badge_tone' => $statusCounts['en_retard'] > 0 ? 'danger' : 'neutral'],
            ['label' => 'En attente validation', 'value' => $pendingValidationCount, 'meta' => null, 'href' => route('workspace.actions.index', ['statut_validation_min' => 'soumise_chef']), 'badge' => null, 'badge_tone' => $pendingValidationCount > 0 ? 'warning' : 'neutral'],
            ['label' => 'Performance moyenne', 'value' => number_format($avgKpi, 1).'%', 'meta' => null, 'href' => route('workspace.actions.index', ['sort' => 'kpi_performance_desc']), 'badge' => null, 'badge_tone' => 'neutral'],
        ];
        $layoutMode = request()->query('layout', 'list');
        $kanbanColumns = [
            'non_demarre'       => ['label' => 'Non démarrée',   'color' => '#94a3b8', 'tone' => 'neutral'],
            'en_cours'          => ['label' => 'En cours',        'color' => '#3996d3', 'tone' => 'info'],
            'a_risque'          => ['label' => 'À surveiller',    'color' => '#f59e0b', 'tone' => 'warning'],
            'en_avance'         => ['label' => 'En avance',       'color' => '#178f5f', 'tone' => 'success'],
            'en_retard'         => ['label' => 'En retard',       'color' => '#b42318', 'tone' => 'danger'],
            'suspendu'          => ['label' => 'Suspendu',        'color' => '#6b7280', 'tone' => 'neutral'],
            'acheve_dans_delai' => ['label' => 'Réalisée',        'color' => '#178f5f', 'tone' => 'success'],
            'acheve_hors_delai' => ['label' => 'Réalisée tardive','color' => '#f59e0b', 'tone' => 'warning'],
            'a_corriger'        => ['label' => 'À corriger',      'color' => '#f97316', 'tone' => 'warning'],
            'cloturee'          => ['label' => 'Clôturée',        'color' => '#178f5f', 'tone' => 'success'],
        ];
        $kanbanGroups = $listing->groupBy(fn ($row) => $row->statut_dynamique ?: 'non_demarre');
        $baseKanbanUrl   = request()->fullUrlWithQuery(['layout' => 'kanban']);
        $baseListUrl     = request()->fullUrlWithQuery(['layout' => 'list']);
        $baseCalendarUrl = request()->fullUrlWithQuery(['layout' => 'calendar']);
        $baseGanttUrl    = request()->fullUrlWithQuery(['layout' => 'gantt']);

        // Calendar: group actions by échéance month/day
        $today = \Carbon\Carbon::today();
        $calYear  = (int) request()->query('cal_year',  $today->year);
        $calMonth = (int) request()->query('cal_month', $today->month);
        $calStart = \Carbon\Carbon::createFromDate($calYear, $calMonth, 1);
        $calEnd   = $calStart->copy()->endOfMonth();
        $calGrid  = []; // day => [actions]
        foreach ($listing as $row) {
            if (!$row->date_fin_prevue) continue;
            $d = \Carbon\Carbon::parse($row->date_fin_prevue);
            if ($d->year === $calYear && $d->month === $calMonth) {
                $calGrid[$d->day][] = $row;
            }
        }
        $calPrev = $calStart->copy()->subMonth();
        $calNext = $calStart->copy()->addMonth();
        $statusBarColor = fn (string $s): string => match ($s) {
            'en_cours'          => '#3996d3',
            'en_avance'         => '#178f5f',
            'en_retard'         => '#b42318',
            'a_risque'          => '#f59e0b',
            'acheve_dans_delai' => '#178f5f',
            'acheve_hors_delai' => '#f59e0b',
            default             => '#94a3b8',
        };

        // Gantt: actions with date_debut_prevue and date_fin_prevue
        $ganttRows = $listing->filter(fn ($r) => $r->date_debut_prevue && $r->date_fin_prevue)
            ->sortBy('date_debut_prevue')->values();
        $ganttMin = $ganttRows->isNotEmpty() ? \Carbon\Carbon::parse($ganttRows->first()->date_debut_prevue) : $today->copy()->startOfMonth();
        $ganttMax = $ganttRows->isNotEmpty() ? \Carbon\Carbon::parse($ganttRows->max('date_fin_prevue')) : $today->copy()->addMonths(3);
        $ganttSpanDays = max(1, $ganttMin->diffInDays($ganttMax) + 1);
    @endphp

    <div class="app-screen-flow">
    <x-ui.page-title
        title="Suivi des actions"
        subtitle="Pilotage opérationnel des actions, validations, justificatifs et performances d'exécution."
    />

    <section class="showcase-toolbar mb-4 app-screen-block">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="showcase-panel-title">Vue des actions</h2>
                @if ($showDualActionTabs)
                    <a class="btn btn-sm {{ $currentViewMode === 'pilotage' ? 'btn-primary' : 'btn-secondary' }} rounded-xl px-3 py-1.5" href="{{ route('workspace.actions.index', ['vue' => 'pilotage']) }}">Actions pilotées</a>
                    <a class="btn btn-sm {{ $currentViewMode === 'mes_actions' ? 'btn-primary' : 'btn-secondary' }} rounded-xl px-3 py-1.5" href="{{ route('workspace.actions.index', ['vue' => 'mes_actions']) }}">Mes actions</a>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <div class="view-toggle" role="group" aria-label="Mode d'affichage">
                <a href="{{ $baseListUrl }}" class="view-toggle-btn {{ $layoutMode === 'list' ? 'active' : '' }}" title="Vue liste">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    Liste
                </a>
                <a href="{{ $baseKanbanUrl }}" class="view-toggle-btn {{ $layoutMode === 'kanban' ? 'active' : '' }}" title="Vue Kanban">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="12" rx="1"/><rect x="17" y="3" width="5" height="7" rx="1"/></svg>
                    Kanban
                </a>
                <a href="{{ $baseCalendarUrl }}" class="view-toggle-btn {{ $layoutMode === 'calendar' ? 'active' : '' }}" title="Calendrier des échéances">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Calendrier
                </a>
                <a href="{{ $baseGanttUrl }}" class="view-toggle-btn {{ $layoutMode === 'gantt' ? 'active' : '' }}" title="Diagramme Gantt">
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="15" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="12" y2="18"/></svg>
                    Gantt
                </a>
            </div>
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
                    <input id="q" name="q" type="text" value="{{ $filters['q'] }}" placeholder="Titre, description, résultat">
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
                            <option value="{{ $status }}" @selected($filters['statut'] === $status)>{{ $status === 'achevees' ? 'Achevée' : $actionStatusLabel($status) }}</option>
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
            @foreach (['direction_id', 'service_id', 'pas_objectif_id', 'annee', 'mois_demarrage', 'week_start'] as $hiddenFilter)
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
            @php
                $activeChips = array_filter([
                    $filters['without_kpi'] ? ['label' => 'Sans indicateur',       'color' => '#F9B13C', 'remove' => 'without_kpi'] : null,
                    $filters['direction_id'] ? ['label' => 'Direction #'.$filters['direction_id'], 'color' => '#3996D3', 'remove' => 'direction_id'] : null,
                    $filters['service_id']   ? ['label' => 'Service #'.$filters['service_id'],     'color' => '#1C203D', 'remove' => 'service_id'] : null,
                    $filters['pas_objectif_id'] ? ['label' => 'Objectif #'.$filters['pas_objectif_id'], 'color' => '#8FC043', 'remove' => 'pas_objectif_id'] : null,
                    $filters['annee']        ? ['label' => 'Année '.$filters['annee'],              'color' => '#F9B13C', 'remove' => 'annee'] : null,
                    $filters['mois_demarrage'] ? ['label' => 'Démarrage '.$filters['mois_demarrage'], 'color' => '#6B7280', 'remove' => 'mois_demarrage'] : null,
                    $filters['week_start']   ? ['label' => 'Semaine '.$filters['week_start'],       'color' => '#6B7280', 'remove' => 'week_start'] : null,
                    !empty($filters['statut']) ? ['label' => 'Statut : '.\App\Support\UiLabel::actionStatus($filters['statut']), 'color' => '#3996D3', 'remove' => 'statut'] : null,
                    !empty($filters['statut_validation_min']) ? ['label' => 'Validation : '.\App\Support\UiLabel::validationStatus($filters['statut_validation_min']), 'color' => '#8FC043', 'remove' => 'statut_validation_min'] : null,
                    !empty($filters['q']) ? ['label' => '"'.$filters['q'].'"', 'color' => '#6B7280', 'remove' => 'q'] : null,
                ]);
            @endphp
            @if (!empty($activeChips))
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-[#667085]">Filtres actifs :</span>
                    @foreach ($activeChips as $chip)
                        <a href="{{ request()->fullUrlWithQuery([$chip['remove'] => '']) }}" class="active-filter-chip" title="Retirer ce filtre">
                            <span class="active-filter-chip-dot" style="background: {{ $chip['color'] }};"></span>
                            {{ $chip['label'] }}
                            <span class="active-filter-chip-remove" aria-hidden="true">×</span>
                        </a>
                    @endforeach
                    <a href="{{ route('workspace.actions.index', array_filter(['vue' => $filters['vue'], 'layout' => $layoutMode !== 'list' ? $layoutMode : null])) }}" class="text-xs font-bold text-[#b42318] hover:underline ml-1">
                        Tout effacer
                    </a>
                </div>
            @endif
        </form>
    </section>

    @if ($layoutMode === 'kanban')
    <section class="mb-4 app-screen-block">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="showcase-panel-title">Kanban — {{ $viewModeLabel }}</h2>
            <span class="showcase-chip text-xs">{{ $listing->count() }} action(s) sur cette page</span>
        </div>
        <div class="kanban-board">
            @foreach ($kanbanColumns as $statusKey => $col)
                @php
                    $cards = $kanbanGroups->get($statusKey, collect());
                @endphp
                @if ($cards->isNotEmpty() || in_array($statusKey, ['non_demarre', 'en_cours', 'en_retard'], true))
                <div class="kanban-column">
                    <div class="kanban-column-header">
                        <span class="kanban-column-header-dot" style="background: {{ $col['color'] }};"></span>
                        <span style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $col['label'] }}</span>
                        <span class="kanban-column-count">{{ $cards->count() }}</span>
                    </div>
                    <div class="kanban-cards"
                         data-drop-zone="{{ $statusKey }}"
                         @if(in_array($statusKey, ['suspendu', 'annule'])) data-drop-allowed="1" @endif>
                        @forelse ($cards as $row)
                            @php
                                $pct = max(0, min(100, (float) ($row->progression_reelle ?? 0)));
                                $pctColor = $pct >= 80 ? '#178f5f' : ($pct >= 50 ? '#3996d3' : ($pct > 0 ? '#f59e0b' : '#94a3b8'));
                            @endphp
                            <a href="{{ route('workspace.actions.suivi', $row) }}"
                               class="kanban-card"
                               draggable="true"
                               data-action-id="{{ $row->id }}"
                               data-action-status="{{ $row->statut_dynamique }}"
                               data-patch-url="{{ route('workspace.actions.quick-status', $row) }}">
                                <div class="kanban-card-id">ACT-{{ str_pad((string) $row->id, 3, '0', STR_PAD_LEFT) }}</div>
                                <div class="kanban-card-title">{{ $row->libelle }}</div>
                                <div class="kanban-card-meta">{{ $row->responsable?->name ?? '-' }} · {{ $row->pta?->titre ?? '-' }}</div>
                                <div class="kanban-card-progress">
                                    <div class="kanban-card-progress-bar" style="width: {{ $pct }}%; background: {{ $pctColor }};"></div>
                                </div>
                                <div class="kanban-card-footer">
                                    <span class="kanban-card-pct" style="color: {{ $pctColor }};">{{ number_format($pct, 1, ',', ' ') }}%</span>
                                    @if ($row->date_fin_prevue)
                                        <span style="font-size:0.68rem; color: var(--app-muted);">{{ \Carbon\Carbon::parse($row->date_fin_prevue)->format('d/m/Y') }}</span>
                                    @endif
                                </div>
                            </a>
                        @empty
                            <div class="kanban-empty">
                                <x-ui.empty-state
                                    title="Aucune action"
                                    message="Aucune action ne correspond à ce statut sur le périmètre courant."
                                    icon="inbox"
                                    tone="neutral"
                                />
                            </div>
                        @endforelse
                    </div>
                </div>
                @endif
            @endforeach
        </div>
        <x-ui.pagination :paginator="$rows" label="actions" />
    </section>
    <script>
    (function () {
        var csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        var dragging = null;

        document.querySelectorAll('.kanban-card[draggable]').forEach(function (card) {
            card.addEventListener('dragstart', function (e) {
                dragging = card;
                card.style.opacity = '0.45';
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', card.dataset.actionId);
            });
            card.addEventListener('dragend', function () {
                card.style.opacity = '';
                dragging = null;
                document.querySelectorAll('.kanban-cards[data-drop-allowed]').forEach(function (z) {
                    z.classList.remove('kanban-drop-over');
                });
            });
        });

        document.querySelectorAll('.kanban-cards[data-drop-allowed]').forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                if (!dragging) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                zone.classList.add('kanban-drop-over');
            });
            zone.addEventListener('dragleave', function (e) {
                if (!zone.contains(e.relatedTarget)) zone.classList.remove('kanban-drop-over');
            });
            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                zone.classList.remove('kanban-drop-over');
                if (!dragging) return;
                var targetStatus = zone.dataset.dropZone;
                var currentStatus = dragging.dataset.actionStatus;
                if (currentStatus === targetStatus) return;

                var url = dragging.dataset.patchUrl;
                dragging.style.pointerEvents = 'none';

                fetch(url, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ statut: targetStatus }),
                })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.statut) {
                        zone.appendChild(dragging);
                        dragging.dataset.actionStatus = data.statut;
                        dragging.style.pointerEvents = '';
                        if (window.anbgToast) window.anbgToast('Statut mis à jour', 'success', 3000);
                    } else {
                        dragging.style.pointerEvents = '';
                        if (window.anbgToast) window.anbgToast(data.error || 'Erreur lors de la mise à jour', 'danger', 4000);
                    }
                })
                .catch(function () {
                    dragging.style.pointerEvents = '';
                    if (window.anbgToast) window.anbgToast('Erreur réseau lors de la mise à jour', 'danger', 4000);
                });
            });
        });
    })();
    </script>

    @elseif ($layoutMode === 'calendar')
    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="showcase-panel-title">
                Calendrier des échéances —
                {{ \Carbon\Carbon::createFromDate($calYear, $calMonth, 1)->locale('fr')->translatedFormat('F Y') }}
            </h2>
            <div class="flex items-center gap-2">
                <a href="{{ request()->fullUrlWithQuery(['layout' => 'calendar', 'cal_year' => $calPrev->year, 'cal_month' => $calPrev->month]) }}"
                   class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5">
                    &#8249; {{ $calPrev->locale('fr')->translatedFormat('M') }}
                </a>
                <a href="{{ request()->fullUrlWithQuery(['layout' => 'calendar', 'cal_year' => $today->year, 'cal_month' => $today->month]) }}"
                   class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5">
                    Aujourd'hui
                </a>
                <a href="{{ request()->fullUrlWithQuery(['layout' => 'calendar', 'cal_year' => $calNext->year, 'cal_month' => $calNext->month]) }}"
                   class="btn btn-secondary btn-sm rounded-xl px-3 py-1.5">
                    {{ $calNext->locale('fr')->translatedFormat('M') }} &#8250;
                </a>
            </div>
        </div>
        @php
            $calDow = (int) $calStart->dayOfWeek; // 0=Sun, 1=Mon … 6=Sat
            $calOffset = ($calDow === 0) ? 6 : $calDow - 1; // Monday-first offset
            $calDaysInMonth = (int) $calEnd->day;
            $calEventTotal = array_sum(array_map('count', $calGrid));
        @endphp
        <div class="cal-grid">
            @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $calDayHeader)
                <div class="cal-day-header">{{ $calDayHeader }}</div>
            @endforeach
            @for ($i = 0; $i < $calOffset; $i++)
                <div class="cal-day cal-day-empty"></div>
            @endfor
            @for ($day = 1; $day <= $calDaysInMonth; $day++)
                @php
                    $isCalToday = ($calYear === $today->year && $calMonth === $today->month && $day === $today->day);
                    $dayActions = $calGrid[$day] ?? [];
                @endphp
                <div class="cal-day {{ $isCalToday ? 'cal-day-today' : '' }}">
                    <span class="cal-day-num {{ $isCalToday ? 'cal-day-num-today' : '' }}">{{ $day }}</span>
                    @foreach (array_slice($dayActions, 0, 3) as $evt)
                        <a href="{{ route('workspace.actions.suivi', $evt) }}"
                           class="cal-event"
                           style="border-left-color: {{ $statusBarColor($evt->statut_dynamique ?: 'non_demarre') }};"
                           title="{{ $evt->libelle }}">
                            {{ \Illuminate\Support\Str::limit($evt->libelle, 20) }}
                        </a>
                    @endforeach
                    @if (count($dayActions) > 3)
                        <span class="cal-event-more">+{{ count($dayActions) - 3 }} autre(s)</span>
                    @endif
                </div>
            @endfor
        </div>
        <div class="mt-3 text-xs text-slate-400 text-right">
            {{ $calEventTotal }} action(s) avec échéance ce mois
        </div>
    </section>

    @elseif ($layoutMode === 'gantt')
    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="showcase-panel-title">Diagramme de Gantt — Planification des actions</h2>
            <span class="showcase-chip text-xs">{{ $ganttRows->count() }} action(s) planifiée(s)</span>
        </div>
        @if ($ganttRows->isEmpty())
            <x-ui.empty-state
                title="Aucune action planifiée"
                message="Les actions avec des dates de début et de fin s'afficheront ici sous forme de diagramme."
                icon="calendar"
            />
        @else
            @php
                $ganttMonths = [];
                $ganttCur = $ganttMin->copy()->startOfMonth();
                while ($ganttCur->lte($ganttMax)) {
                    $mStart = $ganttCur->copy();
                    $mEnd   = $ganttCur->copy()->endOfMonth();
                    $mClampStart = $mStart->lt($ganttMin) ? $ganttMin->copy() : $mStart;
                    $mClampEnd   = $mEnd->gt($ganttMax)   ? $ganttMax->copy() : $mEnd;
                    $mDays = max(1, (int) $mClampStart->diffInDays($mClampEnd) + 1);
                    $ganttMonths[] = [
                        'label' => $ganttCur->locale('fr')->translatedFormat('M Y'),
                        'width' => round(($mDays / $ganttSpanDays) * 100, 4),
                    ];
                    $ganttCur->addMonth();
                }
            @endphp
            <div class="gantt-wrapper">
                {{-- Labels sidebar --}}
                <div class="gantt-sidebar">
                    <div class="gantt-header-label">Action</div>
                    @foreach ($ganttRows as $ganttRow)
                        <a href="{{ route('workspace.actions.suivi', $ganttRow) }}"
                           class="gantt-row-label"
                           title="{{ $ganttRow->libelle }}">
                            <span class="gantt-row-id">ACT-{{ str_pad((string) $ganttRow->id, 3, '0', STR_PAD_LEFT) }}</span>
                            {{ \Illuminate\Support\Str::limit($ganttRow->libelle, 28) }}
                        </a>
                    @endforeach
                </div>
                {{-- Timeline chart --}}
                <div class="gantt-chart">
                    <div class="gantt-months">
                        @foreach ($ganttMonths as $gmon)
                            <div class="gantt-month-header" style="width: {{ $gmon['width'] }}%;">{{ $gmon['label'] }}</div>
                        @endforeach
                    </div>
                    <div class="gantt-rows">
                        @foreach ($ganttRows as $ganttRow)
                            @php
                                $gStart = \Carbon\Carbon::parse($ganttRow->date_debut_prevue);
                                $gEnd   = \Carbon\Carbon::parse($ganttRow->date_fin_prevue);
                                $gLeft  = round(($ganttMin->diffInDays($gStart) / $ganttSpanDays) * 100, 3);
                                $gDays  = max(1, (int) $gStart->diffInDays($gEnd) + 1);
                                $gWidth = round(($gDays / $ganttSpanDays) * 100, 3);
                                $gPct   = max(0, min(100, (float) ($ganttRow->progression_reelle ?? 0)));
                                $gColor = $statusBarColor($ganttRow->statut_dynamique ?: 'non_demarre');
                                $gFillW = round($gWidth * $gPct / 100, 3);
                            @endphp
                            <div class="gantt-row">
                                {{-- Background track --}}
                                <div style="position:absolute; left:{{ $gLeft }}%; width:{{ $gWidth }}%; top:50%; transform:translateY(-50%); height:1.25rem; border-radius:5px; background:{{ $gColor }}; opacity:0.16; pointer-events:none;"></div>
                                {{-- Progress fill --}}
                                <div style="position:absolute; left:{{ $gLeft }}%; width:{{ max(0.3, $gFillW) }}%; top:50%; transform:translateY(-50%); height:1.25rem; border-radius:5px; background:{{ $gColor }};"
                                     title="{{ $ganttRow->libelle }} — {{ number_format($gPct, 1) }}%">
                                    @if ($gWidth > 4)
                                        <span style="position:absolute; left:5px; top:50%; transform:translateY(-50%); font-size:0.6rem; color:#fff; font-weight:700; white-space:nowrap; text-shadow:0 1px 2px rgba(0,0,0,0.3); pointer-events:none;">{{ number_format($gPct, 0) }}%</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if ($today->gte($ganttMin) && $today->lte($ganttMax))
                            @php $gTodayLeft = round(($ganttMin->diffInDays($today) / $ganttSpanDays) * 100, 3); @endphp
                            <div class="gantt-today-line" style="left:{{ $gTodayLeft }}%;" title="Aujourd'hui"></div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="mt-3 text-xs text-slate-400">
                Période : {{ $ganttMin->format('d/m/Y') }} → {{ $ganttMax->format('d/m/Y') }}
                ({{ $ganttSpanDays }} jour{{ $ganttSpanDays > 1 ? 's' : '' }})
            </div>
        @endif
        <x-ui.pagination :paginator="$rows" label="actions" />
    </section>

    @else
    <section class="showcase-panel mb-4 app-screen-block">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Liste des actions</h2>
            </div>
            <span class="showcase-chip">{{ $rows->total() }}</span>
        </div>

        <div class="app-table-wrapper overflow-x-auto">
            <table class="app-table data-table min-w-[1200px] w-full text-sm">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Responsable</th>
                        <th>Échéance</th>
                        <th>Statut</th>
                        <th>Progression</th>
                        <th>Validation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $semainesTotal = (int) ($row->semaines_total ?? 0);
                            $semainesRenseignees = (int) ($row->semaines_renseignees ?? 0);
                            $kpiPerformance = $row->actionKpi?->kpi_performance;
                            $kpiDelay = $row->actionKpi?->kpi_delai;
                            $kpiConformite = $row->actionKpi?->kpi_conformite;
                            $justificatifsTotal = (int) ($row->justificatifs_total ?? 0);
                            $modeEvaluationLabel = $row->mode_evaluation_label ?? 'Par sous-actions';
                            $statusClass = $statusStyles[$row->statut_dynamique ?: 'non_demarre'] ?? $statusStyles['non_demarre'];
                            $progressValue = max(0, min(100, (float) ($row->progression_reelle ?? 0)));
                            $targetValue = max(0, (float) ($row->quantite_cible ?? 0));
                            $realizedValue = max(0, (float) ($row->quantite_realisee ?? 0));
                            $targetRate = $targetValue > 0 ? min(100, ($realizedValue / $targetValue) * 100) : (float) ($row->taux_atteinte_cible ?? 0);
                            $remainingValue = $targetValue > 0 ? max(0, $targetValue - $realizedValue) : 0;
                            $overachievementRate = (float) ($row->taux_depassement ?? ($targetValue > 0 && $realizedValue > $targetValue ? (($realizedValue - $targetValue) / $targetValue) * 100 : 0));
                            $progressColor = $progressValue >= 80 ? 'bg-[#8fc043]' : ($progressValue >= 50 ? 'bg-blue-500' : ($progressValue > 0 ? 'bg-[#f0e509]' : 'bg-slate-400'));
                            $kpiColor = $kpiPerformance !== null
                                ? ((float) $kpiPerformance >= 80 ? 'text-[#8fc043]' : ((float) $kpiPerformance >= 60 ? 'text-[#f9b13c]' : 'text-red-500'))
                                : 'text-slate-400';
                        @endphp
                        <tr>
                            <td class="min-w-[260px]">
                                <div class="font-semibold text-slate-900">{{ $row->libelle }}</div>
                                <p class="mt-1 text-xs font-medium text-slate-500">
                                    ACT-{{ str_pad((string) $row->id, 3, '0', STR_PAD_LEFT) }} · PTA : {{ $row->pta?->titre ?? '-' }}
                                </p>
                                @if ($row->description)
                                    <p class="mt-1 max-w-sm text-sm text-slate-500">{{ $row->description }}</p>
                                @endif
                            </td>
                            <td class="min-w-[180px]">
                                <div class="font-medium text-slate-900">{{ $row->responsable?->name ?? '-' }}</div>
                                <p class="mt-1 text-xs text-slate-500">{{ $row->responsable?->agent_matricule ?? $row->responsable?->email ?? '-' }}</p>
                            </td>
                            <td class="min-w-[140px] text-sm text-slate-700">
                                @if ($row->date_echeance)
                                    <span class="font-semibold text-slate-900">{{ \Illuminate\Support\Carbon::parse($row->date_echeance)->format('d/m/Y') }}</span>
                                @else
                                    <span class="text-slate-500">Non définie</span>
                                @endif
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $modeEvaluationLabel }}
                                </p>
                            </td>
                            <td>
                                <span class="{{ $statusClass }} px-3">
                                    {{ $actionStatusLabel($row->statut_dynamique ?: 'non_demarre') }}
                                </span>
                                <p class="mt-2 text-xs text-slate-500">
                                    Justificatif : {{ $justificatifsTotal > 0 ? $justificatifsTotal.' pièce(s)' : 'aucun' }}
                                </p>
                            </td>
                            <td class="min-w-[180px]">
                                <div class="mb-2 flex items-center justify-between gap-2 text-xs">
                                    <span class="font-semibold text-slate-700">{{ number_format($progressValue, 1) }}%</span>
                                    <span class="text-slate-500">Théo. {{ number_format((float) ($row->progression_theorique ?? 0), 1) }}%</span>
                                </div>
                                <div class="showcase-progress-track">
                                    <span class="showcase-progress-bar {{ $progressColor }}" style="width: {{ $progressValue }}%"></span>
                                </div>
                                @if ($row->usesQuantitativeProgress())
                                    <p class="mt-1 text-xs text-slate-500">
                                        Cible {{ $row->quantite_cible !== null ? number_format((float) $row->quantite_cible, 1, ',', ' ') : '0' }} {{ $row->unite_cible ?: '' }}
                                        · Réalisé {{ number_format($realizedValue, 1, ',', ' ') }}
                                    </p>
                                @else
                                    <p class="mt-1 text-xs text-slate-500">Sous-actions : {{ $semainesRenseignees }}/{{ $semainesTotal }}</p>
                                @endif
                            </td>
                            <td>
                                @php $rowValidationStatus = $row->statut_validation ?: 'non_soumise'; @endphp
                                <span class="{{ $validationStyles[$rowValidationStatus] ?? 'anbg-badge anbg-badge-neutral' }} px-3">
                                    {{ $validationStatusLabel($rowValidationStatus) }}
                                </span>
                                <p class="mt-1 text-xs text-slate-500">
                                    Performance {{ $kpiPerformance !== null ? number_format((float) $kpiPerformance, 1).'%' : '-' }}
                                </p>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-follow btn-sm rounded-xl" href="{{ route('workspace.actions.suivi', $row) }}">Suivi</a>
                                    @if ($canWrite)
                                        <a class="btn btn-warning btn-sm rounded-xl" href="{{ route('workspace.actions.edit', $row) }}">Modifier</a>
                                        <form method="POST" action="{{ route('workspace.actions.destroy', $row) }}" data-confirm-message="Supprimer cette action ?" data-confirm-tone="danger" data-confirm-label="Supprimer">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <x-ui.empty-state
                                    title="Aucune action trouvée"
                                    message="Aucune action ne correspond aux filtres courants."
                                    icon="filter"
                                    tone="info"
                                    class="my-4"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-ui.pagination :paginator="$rows" label="actions filtrées" />
    </section>
    @endif
    </div>
@endsection
