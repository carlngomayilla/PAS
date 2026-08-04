@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($hierarchy['summary'] ?? null) ? $hierarchy['summary'] : [];
        $strategicGroups = collect($hierarchy['strategic_groups'] ?? []);
        $anomalies = collect($hierarchy['anomalies'] ?? []);
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $actionStatusLabel = static fn (string $status): string => \App\Support\UiLabel::actionStatus($status);
        $statusClasses = static fn (string $status): string => match ($status) {
            'actif', 'valide', 'realisee', 'validee', 'cloture', 'cloturee' => 'anbg-badge anbg-badge-success',
            'en_cours', 'controle_sciq', 'en_attente_validation' => 'anbg-badge anbg-badge-info',
            'archive', 'verrouille' => 'anbg-badge anbg-badge-neutral',
            'en_retard', 'rejetee' => 'anbg-badge anbg-badge-danger',
            default => 'anbg-badge anbg-badge-warning',
        };
        $reportStatusLabels = [
            'soumise' => 'Soumise au chef',
            'en_analyse' => 'Analyse du chef',
            'complement_demande' => 'Complement demande',
            'transmise_controle' => 'Controle en attente',
            'transmise_validation_finale' => 'Decision finale en attente',
            'transmise_dg' => 'Decision finale en attente',
            'approuvee' => 'Date approuvee a appliquer',
        ];
        $progress = (float) ($summary['progress'] ?? 0);
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            eyebrow="Declinaison operationnelle"
            :title="$row->titre ?: 'PAO '.$row->annee"
            subtitle="Lecture administrative des objectifs, des PTA et des actions de la direction."
        >
            <x-slot:actions>
                <a class="btn btn-secondary" href="{{ route('workspace.pao.index') }}">Retour aux PAO</a>
                @if ($row->pas_id)
                    <a class="btn btn-secondary" href="{{ route('workspace.pas.show', $row->pas_id) }}">Explorer le PAS</a>
                @endif
                @if ($canWrite)
                    <a class="btn btn-primary" href="{{ route('workspace.pao.edit', $row) }}">Modifier le PAO</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <section class="grid gap-3 lg:grid-cols-[minmax(0,1.4fr)_minmax(300px,0.6fr)]">
            <div class="border-l-4 border-[#7656a8] bg-white px-5 py-4 shadow-sm dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase text-slate-500">Plan d'actions opérationnel annuel</p>
                        <p class="mt-1 font-mono text-sm font-bold text-[#7656a8]">{{ $row->code ?: 'PAO-'.$row->id }}</p>
                        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            <span>{{ $row->direction?->code }} - {{ $row->direction?->libelle ?? 'Direction non renseignee' }}</span>
                            <span>Exercice {{ $row->annee }}</span>
                            <span>Echeance {{ $row->echeance?->format('d/m/Y') ?? '-' }}</span>
                        </div>
                    </div>
                    <span class="{{ $statusClasses((string) $row->statut) }}">{{ $workflowStatusLabel((string) $row->statut) }}</span>
                </div>
            </div>

            <div class="bg-[#17324a] px-5 py-4 text-white shadow-sm">
                <p class="text-xs font-bold uppercase text-sky-200">Avancement moyen visible</p>
                <div class="mt-2 flex items-end justify-between gap-3">
                    <strong class="text-3xl font-black">{{ number_format($progress, 1, ',', ' ') }}%</strong>
                    <span class="text-xs font-semibold text-slate-200">{{ $summary['actions'] ?? 0 }} action(s)</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden bg-slate-700">
                    <div class="h-full bg-[#20c76b]" style="width: {{ max(0, min(100, $progress)) }}%"></div>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6" aria-label="Synthese du PAO">
            @foreach ([
                ['label' => 'Obj. strategiques', 'value' => $summary['strategic_objectives'] ?? 0, 'tone' => 'border-[#3996d3]'],
                ['label' => 'Services', 'value' => $summary['services'] ?? 0, 'tone' => 'border-[#20c76b]'],
                ['label' => 'Obj. operationnels', 'value' => $summary['operational_objectives'] ?? 0, 'tone' => 'border-[#7656a8]'],
                ['label' => 'PTA', 'value' => $summary['ptas'] ?? 0, 'tone' => 'border-[#f9b13c]'],
                ['label' => 'Actions en retard', 'value' => $summary['late_actions'] ?? 0, 'tone' => 'border-[#b42318]'],
                ['label' => 'Reports actifs', 'value' => $summary['active_reports'] ?? 0, 'tone' => 'border-[#17324a]'],
            ] as $metric)
                <div class="border-t-4 {{ $metric['tone'] }} bg-white px-4 py-3 shadow-sm dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase text-slate-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-900 dark:text-white">{{ $metric['value'] }}</p>
                </div>
            @endforeach
        </section>

        @if ($anomalies->isNotEmpty())
            <section class="border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/40" aria-labelledby="pao-attention-title">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 id="pao-attention-title" class="text-sm font-black text-amber-950 dark:text-amber-100">Points d'attention</h2>
                    @foreach ($anomalies as $anomaly)
                        <span class="anbg-badge anbg-badge-warning">{{ $anomaly['count'] }} {{ $anomaly['label'] }}</span>
                    @endforeach
                </div>
            </section>
        @else
            <section class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                La declinaison operationnelle visible est complete et sans retard.
            </section>
        @endif

        <section class="app-screen-block" aria-labelledby="pao-hierarchy-title">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-700">
                <div>
                    <p class="text-xs font-bold uppercase text-[#7656a8]">Portefeuille opérationnel</p>
                    <h2 id="pao-hierarchy-title" class="mt-1 text-xl font-black text-[#17324a] dark:text-white">Objectifs, PTA et actions</h2>
                </div>
                <p class="text-sm font-semibold text-slate-500">Les actions conservent uniquement les acces Suivi et Report.</p>
            </div>

            <div class="space-y-3">
                @forelse ($strategicGroups as $strategicGroup)
                    <details class="overflow-hidden border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900" {{ $loop->first ? 'open' : '' }}>
                        <summary class="cursor-pointer list-none px-4 py-4 hover:bg-slate-50 dark:hover:bg-slate-800">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="anbg-badge {{ (int) $strategicGroup['id'] > 0 ? 'anbg-badge-info' : 'anbg-badge-danger' }}">{{ $strategicGroup['axis']['code'] }} / {{ $strategicGroup['code'] }}</span>
                                        <h3 class="text-base font-black text-slate-900 dark:text-white">{{ $strategicGroup['label'] }}</h3>
                                    </div>
                                    <p class="mt-2 text-xs font-semibold text-slate-500">{{ $strategicGroup['axis']['label'] }} | Echeance {{ $strategicGroup['deadline'] ?? '-' }}</p>
                                </div>
                                <div class="grid grid-cols-3 gap-5 text-center">
                                    <div><strong class="block text-lg text-[#7656a8]">{{ $strategicGroup['operational_objectives_count'] }}</strong><span class="text-xs text-slate-500">Objectifs</span></div>
                                    <div><strong class="block text-lg text-[#f59e0b]">{{ $strategicGroup['ptas_count'] }}</strong><span class="text-xs text-slate-500">PTA</span></div>
                                    <div><strong class="block text-lg text-[#b42318]">{{ $strategicGroup['actions_count'] }}</strong><span class="text-xs text-slate-500">Actions</span></div>
                                </div>
                            </div>
                        </summary>

                        <div class="border-t border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-950/40">
                            <div class="space-y-3">
                                @forelse ($strategicGroup['operational_objectives'] as $operationalObjective)
                                    <article class="border-l-4 border-[#7656a8] bg-white dark:bg-slate-900">
                                        <header class="flex flex-wrap items-start justify-between gap-3 px-4 py-3">
                                            <div class="min-w-0 flex-1">
                                                <p class="font-mono text-xs font-black uppercase text-[#7656a8]">{{ $operationalObjective['code'] }}</p>
                                                <h4 class="mt-1 text-sm font-black text-slate-900 dark:text-white">{{ $operationalObjective['label'] }}</h4>
                                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-slate-500">
                                                    <span>{{ $operationalObjective['service']['code'] }} - {{ $operationalObjective['service']['label'] }}</span>
                                                    <span>Echeance {{ $operationalObjective['deadline'] ?? '-' }}</span>
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="{{ $statusClasses($operationalObjective['status']) }}">{{ $workflowStatusLabel($operationalObjective['status']) }}</span>
                                                <span class="anbg-badge anbg-badge-neutral">{{ number_format((float) $operationalObjective['progress'], 1, ',', ' ') }}%</span>
                                                <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pta.index', ['objectif_operationnel_id' => $operationalObjective['id']]) }}">Voir le PTA</a>
                                            </div>
                                        </header>

                                        <div class="border-t border-slate-200 dark:border-slate-700">
                                            @forelse ($operationalObjective['ptas'] as $pta)
                                                <div class="border-b border-slate-200 last:border-b-0 dark:border-slate-700">
                                                    <div class="flex flex-wrap items-center justify-between gap-3 bg-[#17324a] px-4 py-3 text-white">
                                                        <div>
                                                            <p class="font-mono text-xs font-bold text-sky-200">{{ $pta['code'] }} | {{ $pta['service'] }}</p>
                                                            <h5 class="mt-1 text-sm font-black">{{ $pta['label'] }}</h5>
                                                        </div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="anbg-badge anbg-badge-info">{{ $workflowStatusLabel($pta['status']) }}</span>
                                                            <span class="anbg-badge anbg-badge-success">{{ number_format((float) $pta['progress'], 1, ',', ' ') }}%</span>
                                                            <a class="btn btn-secondary btn-sm" href="{{ route('workspace.pta.show', $pta['id']) }}">Ouvrir le PTA</a>
                                                        </div>
                                                    </div>

                                                    @if (empty($pta['actions']))
                                                        <div class="bg-amber-50 px-4 py-4 text-sm font-bold text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">Aucune action parametree dans ce PTA.</div>
                                                    @else
                                                        <div class="overflow-x-auto">
                                                            <table class="app-table data-table min-w-[1050px]">
                                                                <thead>
                                                                    <tr>
                                                                        <th>Action</th>
                                                                        <th>Responsable</th>
                                                                        <th>Échéance</th>
                                                                        <th>Avancement</th>
                                                                        <th>Statut</th>
                                                                        <th>Report</th>
                                                                        <th>Actions</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    @foreach ($pta['actions'] as $action)
                                                                        <tr>
                                                                            <td class="min-w-[260px]">
                                                                                <span class="font-mono text-xs font-bold text-[#3996d3]">{{ $action['code'] }}</span>
                                                                                <p class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $action['label'] }}</p>
                                                                                @if ((int) $action['sub_actions_count'] > 0)
                                                                                    <span class="mt-1 inline-block text-xs font-semibold text-slate-500">{{ $action['sub_actions_count'] }} sous-action(s)</span>
                                                                                @endif
                                                                            </td>
                                                                            <td>{{ $action['responsible'] }}</td>
                                                                            <td class="whitespace-nowrap">
                                                                                {{ $action['deadline'] ?? '-' }}
                                                                                @if ($action['is_late'])
                                                                                    <span class="mt-1 block anbg-badge anbg-badge-danger">En retard</span>
                                                                                @endif
                                                                            </td>
                                                                            <td class="min-w-[170px]">
                                                                                <div class="flex items-center gap-2">
                                                                                    <div class="h-2 min-w-[95px] flex-1 overflow-hidden bg-slate-200 dark:bg-slate-700">
                                                                                        <div class="h-full bg-[#20c76b]" style="width: {{ max(0, min(100, (float) $action['progress'])) }}%"></div>
                                                                                    </div>
                                                                                    <strong class="text-xs">{{ number_format((float) $action['progress'], 1, ',', ' ') }}%</strong>
                                                                                </div>
                                                                            </td>
                                                                            <td><span class="{{ $statusClasses($action['status']) }}">{{ $actionStatusLabel($action['status']) }}</span></td>
                                                                            <td>
                                                                                @if ($action['report'])
                                                                                    <a class="anbg-badge anbg-badge-warning" href="{{ route('workspace.deadline-extension.show', $action['report']['id']) }}">
                                                                                        {{ $reportStatusLabels[$action['report']['status']] ?? $action['report']['status'] }}
                                                                                    </a>
                                                                                @else
                                                                                    <span class="text-xs font-semibold text-slate-500">Aucun report actif</span>
                                                                                @endif
                                                                            </td>
                                                                            <td>
                                                                                <div class="flex flex-wrap gap-2">
                                                                                    <a class="btn btn-primary btn-sm" href="{{ route('workspace.actions.suivi', $action['id']) }}">Faire le suivi</a>
                                                                                    <a class="btn btn-secondary btn-sm" href="{{ route('workspace.actions.suivi', $action['id']) }}#action-echeances">Report d'échéance</a>
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
                                                <div class="bg-amber-50 px-4 py-4 text-sm font-bold text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">PTA manquant pour cet objectif opérationnel.</div>
                                            @endforelse
                                        </div>
                                    </article>
                                @empty
                                    <div class="border border-amber-200 bg-amber-50 px-4 py-5 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                        Aucun objectif opérationnel visible dans votre périmètre pour ce PAO.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </details>
                @empty
                    <x-ui.empty-state
                        title="Aucune declinaison strategique"
                        message="Ce PAO ne contient encore aucun objectif operationnel visible."
                        icon="chart"
                        tone="warning"
                    />
                @endforelse
            </div>
        </section>
    </div>
@endsection
