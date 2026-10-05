@extends('layouts.workspace')

@section('title', 'Pilotage PAS/PAO/PTA')

@section('content')
    @php
        $actionRouteFilters = is_array($actionRouteFilters ?? null) ? $actionRouteFilters : [];
        $dashboardRouteFilters = is_array($dashboardRouteFilters ?? null) ? $dashboardRouteFilters : [];
        $directionContext = is_array($directionContext ?? null) ? $directionContext : [];
        $periodLabel = (string) ($periodLabel ?? 'Toutes périodes');
        $dashboardBackUrl = route('dashboard', ['dashboardTab' => 'overview'] + $dashboardRouteFilters);
        $actionsIndexUrl = route('workspace.actions.index', $actionRouteFilters);
        $contextCards = [
            ['label' => 'Exercice', 'value' => $actionRouteFilters['annee'] ?? 'Tous exercices'],
            ['label' => 'Période', 'value' => $periodLabel],
            ['label' => 'Direction', 'value' => $directionContext['selected_label'] ?? 'Pilotage global'],
            ['label' => 'Service', 'value' => $directionContext['service_selected_label'] ?? 'Tous les services'],
        ];
    @endphp

    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-[var(--dashboard-muted)]">Pilotage hierarchique</p>
                <h1 class="text-2xl font-semibold text-[var(--dashboard-text)]">PAS / PAO / PTA</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-dashboard.drilldown-button :href="$dashboardBackUrl" label="Dashboard filtré" />
                <x-dashboard.drilldown-button :href="$actionsIndexUrl" label="Voir les actions" />
            </div>
        </div>

        <nav class="flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--dashboard-muted)]" aria-label="Fil d'Ariane">
            <a class="text-[var(--dashboard-accent)] hover:underline" href="{{ $dashboardBackUrl }}">Dashboard</a>
            <span aria-hidden="true">/</span>
            <span>Pilotage PAS/PAO/PTA</span>
        </nav>

        <section class="rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface)] p-4 shadow-sm" aria-labelledby="pilotage-context-title">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-[var(--dashboard-accent)]">Contexte repris du dashboard</p>
                    <h2 id="pilotage-context-title" class="mt-1 text-lg font-semibold text-[var(--dashboard-text)]">Arborescence filtrée sur le même périmètre</h2>
                    <p class="mt-1 text-sm text-[var(--dashboard-muted)]">Les indicateurs ci-dessous utilisent les filtres sécurisés appliqués à la vue de pilotage.</p>
                </div>
                <a class="btn btn-secondary btn-sm" href="{{ $actionsIndexUrl }}">Ouvrir la liste filtrée des actions</a>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($contextCards as $card)
                    <div class="rounded-2xl border border-[var(--dashboard-border)] bg-[var(--dashboard-surface-muted)] px-3 py-2">
                        <p class="text-[10px] font-black uppercase tracking-[0.18em] text-[var(--dashboard-muted)]">{{ $card['label'] }}</p>
                        <p class="mt-1 truncate text-sm font-semibold text-[var(--dashboard-text)]" title="{{ $card['value'] }}">{{ $card['value'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-dashboard.kpi-card label="PAS" :value="$summary['pas_total']" tone="accent" />
            <x-dashboard.kpi-card label="PAO" :value="$summary['pao_total']" tone="info" />
            <x-dashboard.kpi-card label="PTA" :value="$summary['pta_total']" tone="info" />
            <x-dashboard.kpi-card label="Actions" :value="$summary['actions_total']" tone="accent" />
            <x-dashboard.kpi-card label="Execution moyenne" :value="number_format((float) $summary['average_progress'], 1).'%' " tone="success" />
        </div>

        <x-dashboard.section-card title="Arborescence">
            <div class="space-y-3" data-progressive-accordion-group>
                @forelse ($tree as $pasNode)
                    @php
                        $pas = $pasNode['pas'];
                    @endphp
                    <details class="rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface-muted)] p-3" data-progressive-accordion-item {{ $loop->first ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-[var(--dashboard-text)]">{{ $pas?->titre ?? 'PAS non rattache' }}</p>
                                    <p class="text-xs text-[var(--dashboard-muted)]">
                                        {{ $pasNode['paos']->count() }} PAO - {{ $pasNode['ptas_count'] }} PTA - {{ $pasNode['actions_count'] }} actions
                                    </p>
                                </div>
                                <x-dashboard.status-badge status="info" :label="number_format((float) $pasNode['average_progress'], 1).' %'" />
                            </div>
                        </summary>

                        <div class="mt-3 space-y-3">
                            @foreach ($pasNode['paos'] as $paoNode)
                                @php
                                    $pao = $paoNode['pao'];
                                    $axis = $pao?->pasObjectif?->pasAxe;
                                    $objective = $pao?->pasObjectif;
                                @endphp
                                <div class="rounded-[var(--dashboard-card-radius)] border border-[var(--dashboard-border)] bg-[var(--dashboard-surface)] p-3">
                                    <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-[var(--dashboard-text)]">{{ $pao?->titre ?? 'PAO non rattache' }}</p>
                                            <p class="text-xs text-[var(--dashboard-muted)]">
                                                {{ $axis?->code ?? '-' }} {{ $axis?->libelle ?? '' }}
                                                @if ($objective)
                                                    - {{ $objective->code ?? '' }} {{ $objective->libelle }}
                                                @endif
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <x-dashboard.status-badge status="info" :label="$pao?->statut ?? '-'" />
                                            <x-dashboard.status-badge status="success" :label="number_format((float) $paoNode['average_progress'], 1).' %'" />
                                        </div>
                                    </div>

                                    <div class="mt-3 grid gap-2">
                                        @foreach ($paoNode['ptas'] as $pta)
                                            @php
                                                $ptaActionUrl = route('workspace.actions.index', ['pta_id' => $pta->id] + $actionRouteFilters);
                                                $displayedActionsCount = $pta->actions->take(3)->count();
                                                $hiddenActionsCount = max(0, (int) $pta->actions_count - $displayedActionsCount);
                                            @endphp
                                            <div class="rounded-md border border-[var(--dashboard-border)] p-3">
                                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_220px_auto] md:items-center">
                                                    <div>
                                                        <p class="text-sm font-medium text-[var(--dashboard-text)]">{{ $pta->titre }}</p>
                                                        <p class="text-xs text-[var(--dashboard-muted)]">
                                                            {{ $pta->direction?->code ?? '-' }} / {{ $pta->service?->code ?? '-' }}
                                                            - {{ $pta->actions_count }} action(s)
                                                        </p>
                                                    </div>
                                                    <x-dashboard.progress-axis
                                                        label="Execution"
                                                        :value="$pta->actions_avg_progression_reelle ?? 0"
                                                        :target="100"
                                                    />
                                                    <x-dashboard.drilldown-button :href="route('workspace.pta.show', $pta)" label="Détail PTA" />
                                                </div>

                                                @if ($pta->actions->isNotEmpty())
                                                    <div class="mt-3 space-y-2 border-t border-[var(--dashboard-border)] pt-3">
                                                        <div class="flex flex-wrap items-center justify-between gap-2 text-xs font-semibold text-[var(--dashboard-muted)]">
                                                            <span>{{ $displayedActionsCount }} sur {{ $pta->actions_count }} action(s) affichée(s)</span>
                                                            <a class="text-[var(--dashboard-accent)] hover:underline" href="{{ $ptaActionUrl }}">Voir toutes les actions de ce PTA</a>
                                                        </div>
                                                        @foreach ($pta->actions->take(3) as $action)
                                                            <div class="grid gap-2 rounded-md bg-[var(--dashboard-surface-muted)] p-2 sm:grid-cols-[minmax(0,1fr)_120px_auto] sm:items-center">
                                                                <div class="min-w-0">
                                                                    <p class="truncate text-sm font-medium text-[var(--dashboard-text)]">{{ $action->libelle }}</p>
                                                                    <p class="text-xs text-[var(--dashboard-muted)]">
                                                                        {{ $action->statut ?? 'Statut non renseigne' }}
                                                                        @if ($action->date_echeance)
                                                                            - echeance {{ $action->date_echeance->format('d/m/Y') }}
                                                                        @endif
                                                                    </p>
                                                                </div>
                                                                <x-dashboard.status-badge status="success" :label="number_format((float) ($action->progression_reelle ?? 0), 1).' %'" />
                                                                <x-dashboard.drilldown-button :href="route('workspace.actions.suivi', $action)" label="Detail" class="px-2 py-1 text-xs" />
                                                            </div>
                                                        @endforeach
                                                        @if ($hiddenActionsCount > 0)
                                                            <p class="rounded-xl bg-[var(--dashboard-surface-muted)] px-3 py-2 text-xs font-semibold text-[var(--dashboard-muted)]">{{ $hiddenActionsCount }} action(s) supplémentaire(s) accessible(s) dans la liste filtrée.</p>
                                                        @endif

                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @empty
                    <x-dashboard.empty-state title="Aucune hierarchie disponible" message="Aucun PTA n'est accessible dans votre perimetre actuel." />
                @endforelse
            </div>
        </x-dashboard.section-card>
    </div>
@endsection
