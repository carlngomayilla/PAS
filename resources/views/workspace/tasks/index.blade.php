@extends('layouts.workspace')

@section('title', 'Mes tâches')

@section('content')
    @php
        $taskPaginator = $personalTasks['items'];
        $items = collect($taskPaginator->items());
        $summary = is_array($personalTasks['summary'] ?? null) ? $personalTasks['summary'] : [];
        $filteredSummary = is_array($personalTasks['filtered_summary'] ?? null) ? $personalTasks['filtered_summary'] : [];
        $components = collect($summary['components'] ?? []);
        $filters = is_array($taskFilters ?? null) ? $taskFilters : [];
        $familyLabels = [
            'execution' => 'Exécution',
            'corrections' => 'Corrections',
            'validations' => 'Validations',
            'financements' => 'Financements',
            'alertes' => 'Alertes',
            'decisions' => 'Décisions',
            'autres' => 'Autres',
        ];
        $familyOptions = collect($personalTasks['family_options'] ?? [])
            ->filter(static fn (array $option): bool => (int) ($option['count'] ?? 0) > 0);
        $summaryMetrics = collect([
            ['label' => 'À traiter', 'value' => (int) ($summary['total'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-300 dark:text-sky-200', 'always' => true],
            ['label' => 'En retard', 'value' => (int) ($summary['overdue'] ?? 0), 'tone' => 'border-[#B42318] text-[#B42318] dark:border-red-400 dark:text-red-300'],
            ['label' => 'Sous 24 h', 'value' => (int) ($summary['due_soon'] ?? 0), 'tone' => 'border-[#F9B13C] text-[#9A5B00] dark:border-amber-300 dark:text-amber-200'],
            ['label' => 'Critiques', 'value' => (int) ($summary['critical'] ?? 0), 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200'],
            ['label' => 'Sans échéance', 'value' => (int) ($summary['undated'] ?? 0), 'tone' => 'border-slate-400 text-slate-600 dark:border-slate-500 dark:text-slate-300'],
        ])->filter(static fn (array $metric): bool => (bool) ($metric['always'] ?? false) || (int) $metric['value'] > 0);
        $taskViews = [
            ['code' => 'toutes', 'label' => 'Toutes', 'count' => (int) ($summary['total'] ?? 0)],
            ['code' => 'retard', 'label' => 'En retard', 'count' => (int) ($summary['overdue'] ?? 0)],
            ['code' => 'a_24h', 'label' => 'Sous 24 h', 'count' => (int) ($summary['due_soon'] ?? 0)],
            ['code' => 'critiques', 'label' => 'Critiques', 'count' => (int) ($summary['critical'] ?? 0)],
            ['code' => 'sans_echeance', 'label' => 'Sans échéance', 'count' => (int) ($summary['undated'] ?? 0)],
        ];
        $familyBadge = static fn (array $task): string => match ((string) ($task['family'] ?? 'autres')) {
            'corrections' => 'anbg-badge anbg-badge-warning',
            'validations' => 'anbg-badge anbg-badge-success',
            'financements' => 'anbg-badge anbg-badge-info',
            'alertes' => 'anbg-badge anbg-badge-danger',
            'decisions' => 'anbg-badge border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-700 dark:bg-violet-950/60 dark:text-violet-200',
            default => 'anbg-badge border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
        };
        $priorityBadge = static fn (array $task): string => match ((string) ($task['criticality'] ?? 'normale')) {
            'critique' => 'anbg-badge anbg-badge-danger',
            'importante' => 'anbg-badge anbg-badge-warning',
            default => 'anbg-badge anbg-badge-info',
        };
        $priorityBar = static fn (array $task): string => match (true) {
            (bool) ($task['is_overdue'] ?? false) => 'bg-[#B42318]',
            (string) ($task['criticality'] ?? '') === 'critique' => 'bg-[#D92D20]',
            (string) ($task['criticality'] ?? '') === 'importante' => 'bg-[#F9B13C]',
            default => 'bg-[#3996D3]',
        };
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            class="mb-4 app-screen-block"
            eyebrow="File personnelle de traitement"
            title="Mes tâches"
        >
            <x-slot:actions>
                <span class="showcase-chip">
                    <span class="showcase-chip-dot {{ (int) ($summary['overdue'] ?? 0) > 0 ? 'bg-red-600' : 'bg-green-600' }}"></span>
                    {{ (int) ($summary['total'] ?? 0) }} ouverte(s)
                </span>
                <span class="showcase-chip" title="Score personnel calculé sur vos traitements déjà terminés (performance et respect des délais). Il ne mesure pas la charge restante ci-dessous.">
                    Traitements terminés {{ number_format((float) ($summary['score'] ?? 100), 0, ',', ' ') }} %
                </span>
                <span class="showcase-chip" title="Appréciation qualitative de vos traitements terminés, dérivée du score ci-contre.">
                    Qualité {{ $summary['quality_label'] ?? 'Excellent' }}
                </span>
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse personnelle">
            <p class="mb-3 text-xs font-semibold text-slate-500 dark:text-slate-400">
                Charge en attente — ces compteurs mesurent ce qu’il vous reste à traiter, indépendamment du score de vos traitements déjà terminés.
            </p>
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(135px,1fr))]">
                @php
                    $metricHints = [
                        'À traiter' => 'Nombre de tâches ouvertes qui vous sont assignées.',
                        'En retard' => 'Tâches ouvertes dont l’échéance est dépassée.',
                        'Sous 24 h' => 'Tâches ouvertes dont l’échéance arrive dans moins de 24 heures.',
                        'Critiques' => 'Tâches ouvertes de criticité « critique ».',
                        'Sans échéance' => 'Tâches ouvertes sans date d’échéance renseignée.',
                    ];
                @endphp
                @foreach ($summaryMetrics as $metric)
                    <div class="border-l-4 pl-3 {{ $metric['tone'] }}" title="{{ $metricHints[$metric['label']] ?? '' }}">
                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                        <strong class="mt-1 block text-2xl">{{ number_format((int) $metric['value'], 0, ',', ' ') }}</strong>
                    </div>
                @endforeach
            </div>

            @if ($components->isNotEmpty())
                <details class="mt-4 border-t border-slate-200 pt-3 dark:border-slate-700">
                    <summary class="cursor-pointer text-sm font-bold text-[#17324a] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3996D3] dark:text-slate-100">
                        Composantes du score personnel
                    </summary>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach ($components as $component)
                            <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center justify-between gap-3 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                                        <span>{{ $component['label'] ?? 'Composante' }}</span>
                                        <span>{{ (int) ($component['weight'] ?? 0) }} % du score</span>
                                    </div>
                                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                        <span class="block h-full rounded-full bg-[#3996D3]" style="width: {{ max(0, min(100, (float) ($component['score'] ?? 0))) }}%;"></span>
                                    </div>
                                </div>
                                <strong class="text-lg text-[#17324a] dark:text-sky-200">{{ number_format((float) ($component['score'] ?? 0), 0, ',', ' ') }} %</strong>
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>

        <section class="app-screen-block mt-4" aria-labelledby="task-queue-title">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Vues de la file de tâches">
                @foreach ($taskViews as $view)
                    @php
                        $isActiveView = (string) ($filters['vue'] ?? 'toutes') === $view['code'];
                        $viewUrl = route('workspace.tasks.index', array_merge(
                            request()->except(['page', 'vue']),
                            ['vue' => $view['code']]
                        ));
                    @endphp
                    <a
                        href="{{ $viewUrl }}"
                        @if ($isActiveView) aria-current="page" @endif
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition {{ $isActiveView ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}"
                    >
                        {{ $view['label'] }}
                        <span class="min-w-6 rounded-full bg-slate-100 px-1.5 py-0.5 text-center text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $view['count'] }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('workspace.tasks.index') }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(170px,.75fr)_minmax(155px,.65fr)_7rem_auto_auto] dark:border-slate-700 dark:bg-slate-900/70">
                <input type="hidden" name="vue" value="{{ $filters['vue'] ?? 'toutes' }}">

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Recherche
                    <input name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Tâche, action, responsable..." class="min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 placeholder:text-slate-400 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Famille
                    <select name="famille" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Toutes</option>
                        @foreach ($familyOptions as $option)
                            <option value="{{ $option['code'] }}" @selected(($filters['famille'] ?? '') === $option['code'])>
                                {{ $familyLabels[$option['code']] ?? ucfirst((string) $option['code']) }} ({{ (int) $option['count'] }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Tri
                    <select name="tri" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="priorite" @selected(($filters['tri'] ?? 'priorite') === 'priorite')>Priorité</option>
                        <option value="echeance" @selected(($filters['tri'] ?? '') === 'echeance')>Échéance</option>
                        <option value="reception" @selected(($filters['tri'] ?? '') === 'reception')>Réception récente</option>
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Par page
                    <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        @foreach ([15, 25, 50] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 15) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </label>

                <button type="submit" class="btn btn-primary min-h-10 self-end px-4">Filtrer</button>
                <a href="{{ route('workspace.tasks.index') }}" class="btn btn-secondary min-h-10 self-end px-4">Réinitialiser</a>
            </form>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="task-queue-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">File priorisée</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ number_format((int) ($filteredSummary['total'] ?? 0), 0, ',', ' ') }} résultat(s)
                        @if ((int) ($filteredSummary['overdue'] ?? 0) > 0)
                            · {{ (int) $filteredSummary['overdue'] }} en retard
                        @endif
                    </p>
                </div>
                @if ($taskPaginator->total() > 0)
                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">
                        {{ $taskPaginator->firstItem() }}-{{ $taskPaginator->lastItem() }} sur {{ $taskPaginator->total() }}
                    </span>
                @endif
            </div>

            <div class="mt-3 grid gap-3">
                @forelse ($items as $task)
                    <article class="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-4 pl-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" aria-labelledby="task-{{ $loop->index }}-subject">
                        <span class="absolute inset-y-0 left-0 w-1 {{ $priorityBar($task) }}" aria-hidden="true"></span>

                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_11rem_10rem_12rem] lg:items-start">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="{{ $familyBadge($task) }} px-2 py-0.5 text-xs">
                                        {{ $familyLabels[$task['family'] ?? 'autres'] ?? 'Autres' }}
                                    </span>
                                    <span class="{{ $priorityBadge($task) }} px-2 py-0.5 text-xs">{{ ucfirst((string) ($task['criticality'] ?? 'normale')) }}</span>
                                    @if ((bool) ($task['is_overdue'] ?? false))
                                        <span class="anbg-badge anbg-badge-danger px-2 py-0.5 text-xs">En retard</span>
                                    @endif
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $task['title'] ?? 'Tâche' }}</span>
                                </div>

                                <h3 id="task-{{ $loop->index }}-subject" class="mt-2 break-words text-base font-bold text-[#17324a] dark:text-slate-100">{{ $task['subject'] ?? '-' }}</h3>
                                <p class="mt-1 break-words text-sm text-slate-600 dark:text-slate-300">{{ $task['context'] ?? '-' }}</p>
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $task['score_impact'] ?? '-' }}</p>
                            </div>

                            <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Échéance</span>
                                <strong class="mt-1 block text-sm {{ (bool) ($task['is_overdue'] ?? false) ? 'text-[#B42318] dark:text-red-300' : 'text-[#17324a] dark:text-slate-100' }}">{{ $task['remaining_label'] ?? 'Délai non défini' }}</strong>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ optional($task['deadline_at'] ?? null)->format('d/m/Y H:i') ?: 'Non définie' }}</span>
                            </div>

                            <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Responsable</span>
                                <strong class="mt-1 block break-words text-sm text-[#17324a] dark:text-slate-100">{{ $task['responsible'] ?? '-' }}</strong>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Reçue {{ optional($task['received_at'] ?? null)->format('d/m/Y H:i') ?: '-' }}</span>
                            </div>

                            <div class="grid content-start gap-2 border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                @if (! empty($task['can_validate']) && ! empty($task['action_id']))
                                    <form
                                        method="POST"
                                        action="{{ route('workspace.actions.review', $task['action_id']) }}"
                                        data-confirm-message="Valider cette exécution et la transmettre à l'étape suivante ?"
                                        data-confirm-tone="info"
                                        data-confirm-label="Valider"
                                    >
                                        @csrf
                                        <input type="hidden" name="source" value="personal_tasks">
                                        <input type="hidden" name="decision" value="valider">
                                        @if (! empty($task['sous_action_id']))
                                            <input type="hidden" name="sous_action_id" value="{{ $task['sous_action_id'] }}">
                                        @endif
                                        <button type="submit" class="btn btn-primary min-h-10 w-full px-4">Valider</button>
                                    </form>

                                    <details class="group">
                                        <summary class="btn btn-secondary flex min-h-10 cursor-pointer list-none items-center justify-center px-4 text-center text-[#B42318] dark:text-red-300">Renvoyer</summary>
                                        <form method="POST" action="{{ route('workspace.actions.review', $task['action_id']) }}" class="mt-2 grid gap-2">
                                            @csrf
                                            <input type="hidden" name="source" value="personal_tasks">
                                            <input type="hidden" name="decision" value="rejeter">
                                            @if (! empty($task['sous_action_id']))
                                                <input type="hidden" name="sous_action_id" value="{{ $task['sous_action_id'] }}">
                                            @endif
                                            <label class="text-xs font-bold text-slate-600 dark:text-slate-300">
                                                Motif obligatoire
                                                <textarea name="motif" rows="3" required minlength="3" maxlength="1000" class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-2 text-sm font-normal text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"></textarea>
                                            </label>
                                            <button type="submit" class="btn min-h-10 w-full bg-[#B42318] px-4 text-white hover:bg-[#8F1D14]">Confirmer le renvoi</button>
                                        </form>
                                    </details>

                                    <a class="btn btn-secondary min-h-10 px-4" href="{{ $task['url'] ?? route('dashboard') }}">Ouvrir la fiche</a>
                                @else
                                    <a class="btn btn-primary min-h-10 px-4" href="{{ $task['url'] ?? route('dashboard') }}">Traiter</a>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state
                        title="Aucune tâche dans cette vue"
                        message="Modifiez les filtres ou revenez à la vue Toutes."
                        icon="check"
                        tone="success"
                    />
                @endforelse
            </div>

            @if ($taskPaginator->hasPages())
                <x-ui.pagination class="mt-5" :paginator="$taskPaginator" label="tâches" />
            @endif
        </section>
    </div>
@endsection
