@extends('layouts.workspace')

@section('title', 'Pilotage PAS/PAO/PTA')

@section('content')
    <div class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-[var(--dashboard-muted)]">Pilotage hierarchique</p>
                <h1 class="text-2xl font-semibold text-[var(--dashboard-text)]">PAS / PAO / PTA</h1>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-dashboard.drilldown-button :href="route('dashboard')" label="Dashboard" />
                <x-dashboard.drilldown-button :href="route('workspace.actions.index')" label="Voir les actions" />
            </div>
        </div>

        <nav class="flex flex-wrap items-center gap-2 text-xs font-semibold text-[var(--dashboard-muted)]" aria-label="Fil d'Ariane">
            <a class="text-[var(--dashboard-accent)] hover:underline" href="{{ route('dashboard') }}">Dashboard</a>
            <span aria-hidden="true">/</span>
            <span>Pilotage PAS/PAO/PTA</span>
        </nav>

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
                                                    <x-dashboard.drilldown-button :href="route('workspace.pta.edit', $pta)" label="Fiche PTA" />
                                                </div>

                                                @if ($pta->actions->isNotEmpty())
                                                    <div class="mt-3 space-y-2 border-t border-[var(--dashboard-border)] pt-3">
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
