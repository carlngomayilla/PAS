@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($hierarchy['summary'] ?? null) ? $hierarchy['summary'] : [];
        $axes = collect($hierarchy['axes'] ?? []);
        $anomalies = collect($hierarchy['anomalies'] ?? []);
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $statusClasses = static fn (string $status): string => match ($status) {
            'actif', 'valide', 'cloture' => 'anbg-badge anbg-badge-success',
            'en_cours', 'controle_sciq' => 'anbg-badge anbg-badge-info',
            'archive', 'verrouille' => 'anbg-badge anbg-badge-neutral',
            default => 'anbg-badge anbg-badge-warning',
        };
        $coverage = (int) ($summary['strategic_coverage'] ?? 0);
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            eyebrow="Pilotage institutionnel"
            :title="$row->titre"
            subtitle="Lecture progressive de la strategie jusqu'aux actions executees."
        >
            <x-slot:actions>
                <a class="btn btn-secondary" href="{{ route('workspace.pas.index') }}">Retour aux PAS</a>
                @if ($canWrite)
                    <a class="btn btn-primary" href="{{ route('workspace.pas.edit', $row) }}">Modifier le PAS</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <section class="grid gap-3 lg:grid-cols-[minmax(0,1.5fr)_minmax(280px,0.5fr)]">
            <div class="border-l-4 border-[#17324a] bg-white px-5 py-4 shadow-sm dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase text-slate-500">Plan d'actions stratégique</p>
                        <p class="mt-1 font-mono text-sm font-bold text-[#3996d3]">PAS-{{ $row->periode_debut }}-{{ $row->periode_fin }}</p>
                        <p class="mt-3 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            Periode {{ $row->periode_debut }} - {{ $row->periode_fin }}
                        </p>
                    </div>
                    <span class="{{ $statusClasses((string) $row->statut) }}">
                        {{ $workflowStatusLabel((string) $row->statut) }}
                    </span>
                </div>
            </div>

            <div class="bg-[#17324a] px-5 py-4 text-white shadow-sm">
                <p class="text-xs font-bold uppercase text-sky-200">Couverture stratégique</p>
                <div class="mt-2 flex items-end justify-between gap-3">
                    <strong class="text-3xl font-black">{{ $coverage }}%</strong>
                    <span class="text-xs font-semibold text-slate-200">
                        {{ (int) ($summary['strategic_objectives'] ?? 0) - (int) ($summary['strategic_objectives_without_operational'] ?? 0) }} / {{ $summary['strategic_objectives'] ?? 0 }} objectifs declines
                    </span>
                </div>
                <div class="mt-3 h-2 overflow-hidden bg-slate-700">
                    <div class="h-full bg-[#20c76b]" style="width: {{ $coverage }}%"></div>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6" aria-label="Synthese du PAS">
            @foreach ([
                ['label' => 'Axes', 'value' => $summary['axes'] ?? 0, 'tone' => 'border-[#3996d3]'],
                ['label' => 'Objectifs strategiques', 'value' => $summary['strategic_objectives'] ?? 0, 'tone' => 'border-[#17324a]'],
                ['label' => 'PAO', 'value' => $summary['paos'] ?? 0, 'tone' => 'border-[#20c76b]'],
                ['label' => 'Objectifs operationnels', 'value' => $summary['operational_objectives'] ?? 0, 'tone' => 'border-[#7656a8]'],
                ['label' => 'PTA', 'value' => $summary['ptas'] ?? 0, 'tone' => 'border-[#f9b13c]'],
                ['label' => 'Actions', 'value' => $summary['actions'] ?? 0, 'tone' => 'border-[#b42318]'],
            ] as $metric)
                <div class="border-t-4 {{ $metric['tone'] }} bg-white px-4 py-3 shadow-sm dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase text-slate-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-900 dark:text-white">{{ $metric['value'] }}</p>
                </div>
            @endforeach
        </section>

        @if ($anomalies->isNotEmpty())
            <section class="border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/40" aria-labelledby="pas-attention-title">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 id="pas-attention-title" class="text-sm font-black text-amber-950 dark:text-amber-100">Points d'attention</h2>
                    @foreach ($anomalies as $anomaly)
                        <span class="anbg-badge anbg-badge-warning">{{ $anomaly['count'] }} {{ $anomaly['label'] }}</span>
                    @endforeach
                </div>
            </section>
        @else
            <section class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                La chaine de declinaison est complete pour le périmètre consulte.
            </section>
        @endif

        <section class="app-screen-block" aria-labelledby="pas-hierarchy-title">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-700">
                <div>
                    <p class="text-xs font-bold uppercase text-[#3996d3]">Architecture d'execution</p>
                    <h2 id="pas-hierarchy-title" class="mt-1 text-xl font-black text-[#17324a] dark:text-white">Du PAS jusqu'aux actions</h2>
                </div>
                <p class="text-sm font-semibold text-slate-500">Ouvrez un axe puis un objectif pour examiner sa declinaison.</p>
            </div>

            <div class="space-y-3">
                @forelse ($axes as $axis)
                    <details class="overflow-hidden border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900" {{ $loop->first ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none px-4 py-4 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="anbg-badge anbg-badge-info">{{ $axis['code'] ?: 'AXE' }}</span>
                                        <h3 class="text-base font-black text-slate-900 dark:text-white">{{ $axis['label'] }}</h3>
                                    </div>
                                    <p class="mt-2 text-xs font-semibold text-slate-500">Periode {{ $axis['period'] }}</p>
                                </div>
                                <div class="grid grid-cols-4 gap-4 text-center">
                                    <div><strong class="block text-lg text-[#17324a] dark:text-white">{{ $axis['objectives_count'] }}</strong><span class="text-xs text-slate-500">Obj. strat.</span></div>
                                    <div><strong class="block text-lg text-[#7656a8]">{{ $axis['operational_objectives_count'] }}</strong><span class="text-xs text-slate-500">Obj. op.</span></div>
                                    <div><strong class="block text-lg text-[#f59e0b]">{{ $axis['ptas_count'] }}</strong><span class="text-xs text-slate-500">PTA</span></div>
                                    <div><strong class="block text-lg text-[#b42318]">{{ $axis['actions_count'] }}</strong><span class="text-xs text-slate-500">Actions</span></div>
                                </div>
                            </div>
                        </summary>

                        <div class="border-t border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-950/40">
                            <div class="space-y-3">
                                @forelse ($axis['objectives'] as $objective)
                                    <details class="border-l-4 {{ $objective['is_declined'] ? 'border-[#3996d3]' : 'border-amber-500' }} bg-white dark:bg-slate-900" {{ $loop->first ? 'open' : '' }}>
                                        <summary class="cursor-pointer list-none px-4 py-3">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-xs font-black uppercase text-slate-500">Objectif strategique {{ $objective['code'] ?: 'OS' }}</p>
                                                    <h4 class="mt-1 text-sm font-black text-slate-900 dark:text-white">{{ $objective['label'] }}</h4>
                                                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                                        <span>Echeance {{ $objective['deadline'] ?? '-' }}</span>
                                                        @if (filled($objective['indicator']))
                                                            <span>Indicateur {{ $objective['indicator'] }}</span>
                                                        @endif
                                                        @if (filled($objective['target']))
                                                            <span>Cible {{ $objective['target'] }}</span>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    @if ($objective['is_declined'])
                                                        <span class="anbg-badge anbg-badge-success">Decline</span>
                                                    @else
                                                        <span class="anbg-badge anbg-badge-warning">Non decline</span>
                                                    @endif
                                                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pao.index', ['pas_objectif_id' => $objective['id']]) }}" onclick="event.stopPropagation();">Voir les PAO</a>
                                                </div>
                                            </div>
                                        </summary>

                                        <div class="border-t border-slate-200 dark:border-slate-700">
                                            @forelse ($objective['paos'] as $pao)
                                                <div class="border-b border-slate-200 last:border-b-0 dark:border-slate-700">
                                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-[#17324a] px-4 py-3 text-white">
                                                        <div class="min-w-0">
                                                            <p class="text-xs font-bold uppercase text-sky-200">{{ $pao['code'] }} | {{ $pao['year'] }} | {{ $pao['direction'] }}</p>
                                                            <h5 class="mt-1 text-sm font-black">{{ $pao['label'] }}</h5>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="anbg-badge anbg-badge-info">{{ $workflowStatusLabel($pao['status']) }}</span>
                                                            <a class="btn btn-primary btn-sm" href="{{ route('workspace.pao.show', $pao['id']) }}">Ouvrir le PAO</a>
                                                            <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pta.index', ['pao_id' => $pao['id']]) }}">PTA du PAO</a>
                                                        </div>
                                                    </div>

                                                    @if (empty($pao['operational_objectives']))
                                                        <div class="bg-amber-50 px-4 py-4 text-sm font-bold text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                                            Ce PAO ne contient encore aucun objectif opérationnel visible dans votre périmètre.
                                                        </div>
                                                    @else
                                                        <div class="overflow-x-auto">
                                                            <table class="app-table data-table min-w-[900px]">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Objectif opérationnel</th>
                                                                        <th>Service</th>
                                                                        <th>Échéance</th>
                                                                        <th>PTA</th>
                                                                        <th>Actions</th>
                                                                        <th>Acces</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    @foreach ($pao['operational_objectives'] as $operationalObjective)
                                                                        @php $operationalPtas = collect($operationalObjective['ptas']); @endphp
                                                                        <tr>
                                                                            <td>
                                                                                <span class="font-mono text-xs font-bold text-[#7656a8]">{{ $operationalObjective['code'] }}</span>
                                                                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $operationalObjective['label'] }}</p>
                                                                                <span class="mt-1 inline-block {{ $statusClasses($operationalObjective['status']) }}">{{ $workflowStatusLabel($operationalObjective['status']) }}</span>
                                                                            </td>
                                                                            <td>{{ $operationalObjective['service'] }}</td>
                                                                            <td class="whitespace-nowrap">{{ $operationalObjective['deadline'] ?? '-' }}</td>
                                                                            <td>
                                                                                @forelse ($operationalPtas as $pta)
                                                                                    <div class="mb-2 border-l-2 border-[#f9b13c] pl-2 last:mb-0">
                                                                                        <p class="font-mono text-xs font-bold">{{ $pta['code'] }}</p>
                                                                                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $pta['label'] }}</p>
                                                                                    </div>
                                                                                @empty
                                                                                    <span class="anbg-badge anbg-badge-warning">PTA manquant</span>
                                                                                @endforelse
                                                                            </td>
                                                                            <td>
                                                                                @if ($operationalPtas->isEmpty())
                                                                                    <span class="text-slate-400">-</span>
                                                                                @else
                                                                                    @foreach ($operationalPtas as $pta)
                                                                                        <div class="mb-2 last:mb-0">
                                                                                            @if ((int) $pta['actions_count'] === 0)
                                                                                                <span class="anbg-badge anbg-badge-warning">Aucune action</span>
                                                                                            @else
                                                                                                <span class="anbg-badge anbg-badge-success">{{ $pta['actions_count'] }} action(s)</span>
                                                                                            @endif
                                                                                        </div>
                                                                                    @endforeach
                                                                                @endif
                                                                            </td>
                                                                            <td>
                                                                                <div class="flex flex-col items-start gap-2">
                                                                                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pta.index', ['objectif_operationnel_id' => $operationalObjective['id']]) }}">Voir le PTA</a>
                                                                                    @foreach ($operationalPtas as $pta)
                                                                                        @if ((int) $pta['actions_count'] > 0)
                                                                                            <a class="btn btn-primary btn-sm" href="{{ route('workspace.actions.index', ['pta_id' => $pta['id']]) }}">Voir les actions</a>
                                                                                        @endif
                                                                                    @endforeach
                                                                                </div>
                                                                            </td>
                                                                        </tr>
                                                                    @endforeach
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    @endif
                                                </div>
                                            @empty
                                                <div class="bg-amber-50 px-4 py-5 text-sm font-bold text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                                    Cet objectif stratégique n'est encore rattache a aucun objectif opérationnel dans votre périmètre.
                                                </div>
                                            @endforelse
                                        </div>
                                    </details>
                                @empty
                                    <x-ui.empty-state
                                        title="Aucun objectif strategique"
                                        message="Cet axe ne contient encore aucun objectif strategique."
                                        icon="file"
                                        tone="warning"
                                    />
                                @endforelse
                            </div>
                        </div>
                    </details>
                @empty
                    <x-ui.empty-state
                        title="Aucun axe strategique"
                        message="Ce PAS ne contient encore aucun axe a explorer."
                        icon="chart"
                        tone="warning"
                    />
                @endforelse
            </div>
        </section>
    </div>
@endsection
