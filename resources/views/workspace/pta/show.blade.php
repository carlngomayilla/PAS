@extends('layouts.workspace')

@section('content')
    @php
        $summary = is_array($hierarchy['summary'] ?? null) ? $hierarchy['summary'] : [];
        $path = is_array($hierarchy['hierarchy'] ?? null) ? $hierarchy['hierarchy'] : [];
        $actions = collect($hierarchy['actions'] ?? []);
        $anomalies = collect($hierarchy['anomalies'] ?? []);
        $workflowStatusLabel = static fn (string $status): string => \App\Support\UiLabel::workflowStatus($status);
        $validationStatusLabel = static fn (string $status): string => \App\Support\UiLabel::validationStatus($status);
        $statusClasses = static fn (string $status): string => match ($status) {
            'actif', 'valide', 'realise', 'realisee', 'validee', 'cloture', 'cloturee', 'parametre' => 'anbg-badge anbg-badge-success',
            'en_cours', 'controle_sciq', 'soumise', 'soumise_chef', 'validee_chef' => 'anbg-badge anbg-badge-info',
            'archive', 'verrouille', 'non_soumise' => 'anbg-badge anbg-badge-neutral',
            'en_retard', 'rejetee', 'rejetee_chef', 'a_corriger' => 'anbg-badge anbg-badge-danger',
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
            eyebrow="Execution annuelle du service"
            :title="$row->titre ?: 'PTA '.$row->id"
            subtitle="Fiche administrative PTA"
        >
            <x-slot:actions>
                <a class="btn btn-secondary" href="{{ route('workspace.pta.index') }}">Retour aux PTA</a>
                @if (($path['pao']['id'] ?? null) !== null)
                    <a class="btn btn-secondary" href="{{ route('workspace.pao.show', $path['pao']['id']) }}">Explorer le PAO</a>
                @endif
                @if ($canWrite)
                    <a class="btn btn-primary" href="{{ route('workspace.pta.edit', $row) }}">Paramétrer le PTA</a>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <section class="grid gap-3 lg:grid-cols-[minmax(0,1.45fr)_minmax(300px,0.55fr)]">
            <div class="border-l-4 border-[#20c76b] bg-white px-5 py-4 shadow-sm dark:bg-slate-900">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-mono text-sm font-black text-[#168c4c]">{{ $row->code ?: 'PTA-'.$row->id }}</p>
                            <span class="{{ $statusClasses((string) $row->statut) }}">{{ $workflowStatusLabel((string) $row->statut) }}</span>
                        </div>
                        <h2 class="mt-3 text-lg font-black text-[#17324a] dark:text-white">
                            {{ $row->service?->code }} - {{ $row->service?->libelle ?? 'Service non renseigne' }}
                        </h2>
                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm font-semibold text-slate-600 dark:text-slate-300">
                            <span>{{ $row->direction?->code }} - {{ $row->direction?->libelle ?? 'Direction non renseignee' }}</span>
                            <span>Exercice {{ $row->pao?->annee ?? '-' }}</span>
                            <span>Echeance de l'objectif {{ $row->objectifOperationnel?->echeance?->format('d/m/Y') ?? '-' }}</span>
                            <span>{{ $summary['proofs'] ?? 0 }} piece(s) justificative(s)</span>
                        </div>
                    </div>
                    @if ($row->modification_locked_at)
                        <span class="anbg-badge anbg-badge-warning">Modification verrouillee</span>
                    @endif
                </div>
            </div>

            <div class="bg-[#17324a] px-5 py-4 text-white shadow-sm">
                <p class="text-xs font-bold uppercase text-sky-200">Avancement moyen du PTA</p>
                <div class="mt-2 flex items-end justify-between gap-3">
                    <strong class="text-3xl font-black">{{ number_format($progress, 1, ',', ' ') }}%</strong>
                    <span class="text-xs font-semibold text-slate-200">{{ $summary['actions'] ?? 0 }} action(s)</span>
                </div>
                <div class="mt-3 h-2 overflow-hidden bg-slate-700">
                    <div class="h-full bg-[#20c76b]" style="width: {{ max(0, min(100, $progress)) }}%"></div>
                </div>
            </div>
        </section>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6" aria-label="Synthese du PTA">
            @foreach ([
                ['label' => 'Actions', 'value' => $summary['actions'] ?? 0, 'tone' => 'border-[#3996d3]'],
                ['label' => 'Sous-actions', 'value' => $summary['sub_actions'] ?? 0, 'tone' => 'border-[#7656a8]'],
                ['label' => 'À paramétrer', 'value' => $summary['unconfigured_actions'] ?? 0, 'tone' => 'border-[#f9b13c]'],
                ['label' => 'En retard', 'value' => $summary['late_actions'] ?? 0, 'tone' => 'border-[#b42318]'],
                ['label' => 'Reports actifs', 'value' => $summary['active_reports'] ?? 0, 'tone' => 'border-[#17324a]'],
                ['label' => 'Validations en attente', 'value' => $summary['pending_validations'] ?? 0, 'tone' => 'border-[#20c76b]'],
            ] as $metric)
                <div class="border-t-4 {{ $metric['tone'] }} bg-white px-4 py-3 shadow-sm dark:bg-slate-900">
                    <p class="text-xs font-bold uppercase text-slate-500">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-2xl font-black text-slate-900 dark:text-white">{{ $metric['value'] }}</p>
                </div>
            @endforeach
        </section>

        @if ($anomalies->isNotEmpty())
            <section class="border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900 dark:bg-amber-950/40" aria-labelledby="pta-attention-title">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 id="pta-attention-title" class="text-sm font-black text-amber-950 dark:text-amber-100">Points d'attention</h2>
                    @foreach ($anomalies as $anomaly)
                        <span class="anbg-badge anbg-badge-warning">{{ $anomaly['count'] }} {{ $anomaly['label'] }}</span>
                    @endforeach
                </div>
            </section>
        @else
            <section class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                Le PTA est complet, configure et sans retard actif.
            </section>
        @endif

        <section class="app-screen-block" aria-labelledby="pta-path-title">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-700">
                <div>
                    <p class="text-xs font-bold uppercase text-[#7656a8]">Rattachement administratif</p>
                    <h2 id="pta-path-title" class="mt-1 text-xl font-black text-[#17324a] dark:text-white">Du PAS au PTA</h2>
                </div>
            </div>

            <div class="grid border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900 md:grid-cols-5">
                @foreach ([
                    ['label' => 'PAS', 'node' => $path['pas'] ?? [], 'href' => ($path['pas']['id'] ?? null) ? route('workspace.pas.show', $path['pas']['id']) : null, 'tone' => 'text-[#3996d3]'],
                    ['label' => 'Axe strategique', 'node' => $path['axis'] ?? [], 'href' => null, 'tone' => 'text-[#7656a8]'],
                    ['label' => 'Objectif strategique', 'node' => $path['strategic_objective'] ?? [], 'href' => null, 'tone' => 'text-[#7656a8]'],
                    ['label' => 'PAO', 'node' => $path['pao'] ?? [], 'href' => ($path['pao']['id'] ?? null) ? route('workspace.pao.show', $path['pao']['id']) : null, 'tone' => 'text-[#17324a]'],
                    ['label' => 'Objectif opérationnel', 'node' => $path['operational_objective'] ?? [], 'href' => null, 'tone' => 'text-[#168c4c]'],
                ] as $step)
                    <div class="min-w-0 border-b border-slate-200 px-4 py-4 last:border-b-0 dark:border-slate-700 md:border-b-0 md:border-r md:last:border-r-0">
                        <p class="text-xs font-black uppercase {{ $step['tone'] }}">{{ $step['label'] }}</p>
                        <p class="mt-2 font-mono text-xs font-bold text-slate-500">{{ $step['node']['code'] ?? '-' }}</p>
                        @if ($step['href'])
                            <a class="mt-1 block text-sm font-bold text-slate-900 hover:text-[#3996d3] dark:text-white" href="{{ $step['href'] }}">{{ $step['node']['label'] ?? '-' }}</a>
                        @else
                            <p class="mt-1 text-sm font-bold text-slate-900 dark:text-white">{{ $step['node']['label'] ?? '-' }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-screen-block" aria-labelledby="pta-actions-title">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 pb-3 dark:border-slate-700">
                <div>
                    <p class="text-xs font-bold uppercase text-[#168c4c]">Execution et contrôle</p>
                    <h2 id="pta-actions-title" class="mt-1 text-xl font-black text-[#17324a] dark:text-white">Tableau des actions et sous-actions</h2>
                </div>
                <span class="text-sm font-semibold text-slate-500">{{ $actions->count() }} ligne(s)</span>
            </div>

            @if ($actions->isEmpty())
                <x-ui.empty-state
                    title="Aucune action parametree"
                    message="Ce PTA ne contient encore aucune action."
                    icon="chart"
                    tone="warning"
                />
            @else
                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table min-w-[1680px]">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Paramétrage</th>
                                <th>Indicateur et cible</th>
                                <th>RMO</th>
                                <th>Période</th>
                                <th>Avancement</th>
                                <th>Sous-actions / preuves</th>
                                <th>Validation</th>
                                <th>Report</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($actions as $action)
                                <tr>
                                    <td class="min-w-[290px] align-top">
                                        <span class="font-mono text-xs font-black text-[#3996d3]">{{ $action['code'] }}</span>
                                        <p class="mt-1 font-bold text-slate-900 dark:text-white">{{ $action['label'] }}</p>
                                        @if ($action['description'])
                                            <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{{ $action['description'] }}</p>
                                        @endif
                                        @if ($action['is_late'])
                                            <span class="mt-2 inline-flex anbg-badge anbg-badge-danger">En retard</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[210px] align-top">
                                        <span class="{{ $statusClasses($action['configuration_status']) }}">
                                            {{ $action['configuration_status'] === 'parametre' ? 'Parametree' : 'À paramétrer' }}
                                        </span>
                                        <p class="mt-2 text-xs font-bold text-slate-700 dark:text-slate-200">{{ $action['type'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $action['indicator_type'] }}</p>
                                    </td>
                                    <td class="min-w-[290px] align-top">
                                        <p class="text-xs font-bold uppercase text-slate-500">Indicateur</p>
                                        <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $action['indicator'] }}</p>
                                        <p class="mt-3 text-xs font-bold uppercase text-slate-500">Cible</p>
                                        <p class="mt-1 text-sm font-semibold text-[#168c4c]">{{ $action['target'] }}</p>
                                        <p class="mt-2 text-xs leading-5 text-slate-500">Resultat : {{ $action['expected_result'] }}</p>
                                    </td>
                                    <td class="min-w-[180px] align-top">
                                        <p class="font-semibold text-slate-900 dark:text-white">{{ $action['responsible'] }}</p>
                                        <p class="mt-2 text-xs text-slate-500">Financement : {{ $action['financing'] }}</p>
                                    </td>
                                    <td class="whitespace-nowrap align-top text-sm">
                                        <p><span class="text-xs font-bold text-slate-500">Début</span> {{ $action['start_date'] ?? '-' }}</p>
                                        <p class="mt-2"><span class="text-xs font-bold text-slate-500">Fin</span> {{ $action['deadline'] ?? '-' }}</p>
                                    </td>
                                    <td class="min-w-[180px] align-top">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 min-w-[95px] flex-1 overflow-hidden bg-slate-200 dark:bg-slate-700">
                                                <div class="h-full bg-[#20c76b]" style="width: {{ max(0, min(100, (float) $action['progress'])) }}%"></div>
                                            </div>
                                            <strong class="text-xs">{{ number_format((float) $action['progress'], 1, ',', ' ') }}%</strong>
                                        </div>
                                        <span class="mt-2 inline-flex {{ $statusClasses($action['status']) }}">{{ $action['status_label'] }}</span>
                                    </td>
                                    <td class="min-w-[180px] align-top">
                                        <p class="font-bold text-slate-900 dark:text-white">{{ $action['completed_sub_actions_count'] }} / {{ $action['sub_actions_count'] }} terminee(s)</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $action['planned_sub_actions_count'] }} prevue(s)</p>
                                        <p class="mt-2 text-xs font-semibold text-slate-700 dark:text-slate-200">{{ $action['proofs_count'] }} preuve(s)</p>
                                        @if ($action['requires_proof'])
                                            <span class="mt-1 inline-flex anbg-badge anbg-badge-warning">Preuve obligatoire</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[170px] align-top">
                                        <span class="{{ $statusClasses($action['validation_status']) }}">{{ $validationStatusLabel($action['validation_status']) }}</span>
                                    </td>
                                    <td class="min-w-[190px] align-top">
                                        @if ($action['report'])
                                            <a class="anbg-badge anbg-badge-warning" href="{{ route('workspace.deadline-extension.show', $action['report']['id']) }}">
                                                {{ $reportStatusLabels[$action['report']['status']] ?? $action['report']['status'] }}
                                            </a>
                                            <p class="mt-2 text-xs font-semibold text-slate-600 dark:text-slate-300">Demandee : {{ $action['report']['requested_deadline'] ?? '-' }}</p>
                                            @if ($action['report']['approved_deadline'])
                                                <p class="mt-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">Approuvee : {{ $action['report']['approved_deadline'] }}</p>
                                            @endif
                                        @else
                                            <span class="text-xs font-semibold text-slate-500">Aucun report actif</span>
                                        @endif
                                    </td>
                                    <td class="min-w-[225px] align-top">
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
        </section>
    </div>
@endsection
