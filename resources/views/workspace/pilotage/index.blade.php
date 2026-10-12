@extends('layouts.workspace')

@section('title', 'Pilotage PAS/PAO/PTA')

@section('content')
    @php
        $actionRouteFilters = is_array($actionRouteFilters ?? null) ? $actionRouteFilters : [];
        $dashboardRouteFilters = is_array($dashboardRouteFilters ?? null) ? $dashboardRouteFilters : [];
        $dashboardBackUrl = route('dashboard', ['dashboardTab' => 'overview'] + $dashboardRouteFilters);
        $actionsIndexUrl = route('workspace.actions.index', $actionRouteFilters);
    @endphp

    <x-ui.page-stack gap="space-y-4">
        <x-ui.page-title
            eyebrow="Pilotage hiérarchique"
            title="Pilotage PAS / PAO / PTA"
            subtitle="Explorez la déclinaison des plans et les actions accessibles dans votre périmètre."
        >
            <x-slot:breadcrumbs>
                <a class="text-[var(--ui-brand)] hover:underline" href="{{ $dashboardBackUrl }}">Dashboard</a>
                <span aria-hidden="true">/</span>
                <span>Pilotage PAS / PAO / PTA</span>
            </x-slot:breadcrumbs>

            <x-slot:actions>
                <a class="btn btn-secondary" href="{{ $dashboardBackUrl }}">Dashboard filtré</a>
                <a class="btn btn-primary" href="{{ $actionsIndexUrl }}">Voir les actions</a>
            </x-slot:actions>
        </x-ui.page-title>

        <x-ui.kpi-grid min="150px" aria-label="Synthèse du pilotage">
            <x-ui.stat-card title="PAS" :value="$summary['pas_total']" tone="navy" />
            <x-ui.stat-card title="PAO" :value="$summary['pao_total']" tone="blue" />
            <x-ui.stat-card title="PTA" :value="$summary['pta_total']" tone="gold" />
            <x-ui.stat-card title="Actions" :value="$summary['actions_total']" tone="orange" />
            <x-ui.stat-card title="Exécution moyenne" :value="number_format((float) $summary['average_progress'], 1).'%'" tone="green" />
        </x-ui.kpi-grid>

        <x-ui.section-card
            title="Arborescence"
            subtitle="Ouvrez un PAS puis un PAO pour consulter les PTA et les actions de votre périmètre."
        >
            <div class="space-y-3" data-progressive-accordion-group>
                @forelse ($tree as $pasNode)
                    @php
                        $pas = $pasNode['pas'];
                    @endphp
                    <details class="app-card overflow-hidden p-0" data-progressive-accordion-item {{ $loop->first ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-[var(--ui-text)]">{{ $pas?->titre ?? 'PAS non rattache' }}</p>
                                    <p class="text-xs text-[var(--ui-muted)]">
                                        {{ $pasNode['paos']->count() }} PAO - {{ $pasNode['ptas_count'] }} PTA - {{ $pasNode['actions_count'] }} actions
                                    </p>
                                </div>
                                <x-ui.badge tone="info">{{ number_format((float) $pasNode['average_progress'], 1).' %' }}</x-ui.badge>
                            </div>
                        </summary>

                        <div class="space-y-3 border-t border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] p-3">
                            @foreach ($pasNode['paos'] as $paoNode)
                                @php
                                    $pao = $paoNode['pao'];
                                    $axis = $pao?->pasObjectif?->pasAxe;
                                    $objective = $pao?->pasObjectif;
                                @endphp
                                <article class="app-card p-3">
                                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-[var(--ui-text)]">{{ $pao?->titre ?? 'PAO non rattache' }}</p>
                                            <p class="text-xs text-[var(--ui-muted)]">
                                                {{ $axis?->code ?? '-' }} {{ $axis?->libelle ?? '' }}
                                                @if ($objective)
                                                    - {{ $objective->code ?? '' }} {{ $objective->libelle }}
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.badge tone="info">{{ $pao?->statut ?? '-' }}</x-ui.badge>
                                            <x-ui.badge tone="success">{{ number_format((float) $paoNode['average_progress'], 1).' %' }}</x-ui.badge>
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-2">
                                        @foreach ($paoNode['ptas'] as $pta)
                                            @php
                                                $ptaActionUrl = route('workspace.actions.index', ['pta_id' => $pta->id] + $actionRouteFilters);
                                                $displayedActionsCount = $pta->actions->take(3)->count();
                                                $hiddenActionsCount = max(0, (int) $pta->actions_count - $displayedActionsCount);
                                            @endphp
                                            <div class="rounded-[var(--ui-radius-sm)] border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] p-3">
                                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto] md:items-center">
                                                    <div class="min-w-0">
                                                        <p class="text-sm font-medium text-[var(--ui-text)]">{{ $pta->titre }}</p>
                                                        <p class="text-xs text-[var(--ui-muted)]">
                                                            {{ $pta->direction?->code ?? '-' }} / {{ $pta->service?->code ?? '-' }}
                                                            - {{ $pta->actions_count }} action(s)
                                                        </p>
                                                    </div>
                                                    @php $ptaProgress = max(0, min(100, (float) ($pta->actions_avg_progression_reelle ?? 0))); @endphp
                                                    <div class="min-w-[150px]">
                                                        <div class="flex items-center justify-between gap-3 text-xs font-semibold text-[var(--ui-text-soft)]">
                                                            <span>Exécution</span>
                                                            <span>{{ number_format($ptaProgress, 0) }}%</span>
                                                        </div>
                                                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-[var(--ui-surface-muted)]">
                                                            <span class="block h-full rounded-full bg-[var(--ui-green)]" style="width: {{ $ptaProgress }}%"></span>
                                                        </div>
                                                    </div>
                                                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pta.show', $pta) }}">Détail PTA</a>
                                                </div>

                                                @if ($pta->actions->isNotEmpty())
                                                    <div class="mt-3 space-y-2 border-t border-[var(--ui-border)] pt-3">
                                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-[var(--ui-muted)]">
                                                            <span>{{ $displayedActionsCount }} sur {{ $pta->actions_count }} action(s) affichée(s)</span>
                                                            <a class="text-[var(--ui-brand)] hover:underline" href="{{ $ptaActionUrl }}">Voir toutes les actions de ce PTA</a>
                                                        </div>
                                                        @foreach ($pta->actions->take(3) as $action)
                                                            <div class="grid gap-2 rounded-[var(--ui-radius-sm)] border border-[var(--ui-border)] bg-[var(--ui-surface)] p-2 sm:grid-cols-[minmax(0,1fr)_120px_auto] sm:items-center">
                                                                <div class="min-w-0">
                                                                    <p class="truncate text-sm font-medium text-[var(--ui-text)]">{{ $action->libelle }}</p>
                                                                    <p class="text-xs text-[var(--ui-muted)]">
                                                                        {{ $action->statut ?? 'Statut non renseigne' }}
                                                                        @if ($action->date_echeance)
                                                                            - echeance {{ $action->date_echeance->format('d/m/Y') }}
                                                                        @endif
                                                                    </p>
                                                                </div>
                                                                <x-ui.badge tone="success">{{ number_format((float) ($action->progression_reelle ?? 0), 1).' %' }}</x-ui.badge>
                                                                <a class="btn btn-secondary btn-sm" href="{{ route('workspace.actions.suivi', $action) }}">Détail</a>
                                                            </div>
                                                        @endforeach
                                                        @if ($hiddenActionsCount > 0)
                                                            <p class="rounded-[var(--ui-radius-sm)] bg-[var(--ui-surface)] px-3 py-2 text-xs font-semibold text-[var(--ui-muted)]">{{ $hiddenActionsCount }} action(s) supplémentaire(s) accessible(s) dans la liste filtrée.</p>
                                                        @endif

                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    </details>
                @empty
                    <x-ui.empty-state title="Aucune hiérarchie disponible" message="Aucun PTA n'est accessible dans votre périmètre actuel." icon="chart" tone="info" />
                @endforelse
            </div>
        </x-ui.section-card>
    </x-ui.page-stack>
@endsection
