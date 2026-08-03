@extends('layouts.workspace')

@section('title', 'Journal d’audit')

@section('content')
    @php
        $items = collect($logs->items());
        $summary = is_array($summary ?? null) ? $summary : [];
        $filters = is_array($filters ?? null) ? $filters : [];
        $options = is_array($options ?? null) ? $options : [];
        $scopeCounts = is_array($summary['scope_counts'] ?? null) ? $summary['scope_counts'] : [];
        $activeScope = (string) ($filters['operation_scope'] ?? '');
        $scopeViews = [
            ['code' => '', 'label' => 'Tous les événements', 'count' => (int) ($scopeCounts['all'] ?? 0)],
            ['code' => 'recent', 'label' => 'Dernières 24 h', 'count' => (int) ($scopeCounts['recent'] ?? 0)],
            ['code' => 'execution', 'label' => 'Exécution & procédures', 'count' => (int) ($scopeCounts['execution'] ?? 0)],
            ['code' => 'reports', 'label' => 'Rapports institutionnels', 'count' => (int) ($scopeCounts['reports'] ?? 0)],
            ['code' => 'interventions', 'label' => 'Interventions', 'count' => (int) ($scopeCounts['interventions'] ?? 0)],
            ['code' => 'sensitive', 'label' => 'Sensibles', 'count' => (int) ($scopeCounts['sensitive'] ?? 0)],
            ['code' => 'organization', 'label' => 'Organisation', 'count' => (int) ($scopeCounts['organization'] ?? 0)],
        ];
        $summaryMetrics = collect([
            ['label' => 'Entrées filtrées', 'value' => (int) ($summary['total'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-300 dark:text-sky-200', 'always' => true],
            ['label' => 'Acteurs', 'value' => (int) ($summary['distinct_users'] ?? 0), 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200', 'always' => true],
            ['label' => 'Avec modifications', 'value' => (int) ($summary['with_changes'] ?? 0), 'tone' => 'border-[#5D8E25] text-[#4B741E] dark:border-lime-400 dark:text-lime-200'],
            ['label' => 'Événements système', 'value' => (int) ($summary['anonymous'] ?? 0), 'tone' => 'border-[#F9B13C] text-[#9A5B00] dark:border-amber-300 dark:text-amber-200'],
            ['label' => 'Modules touchés', 'value' => (int) ($summary['modules_touched'] ?? 0), 'tone' => 'border-violet-400 text-violet-700 dark:border-violet-400 dark:text-violet-200'],
        ])->filter(static fn (array $metric): bool => (bool) ($metric['always'] ?? false) || (int) $metric['value'] > 0);
        $categoryStyles = [
            'intervention' => ['badge' => 'anbg-badge anbg-badge-warning', 'bar' => 'bg-[#F9B13C]'],
            'sensitive' => ['badge' => 'anbg-badge anbg-badge-danger', 'bar' => 'bg-[#B42318]'],
            'organization' => ['badge' => 'anbg-badge border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-700 dark:bg-violet-950/60 dark:text-violet-200', 'bar' => 'bg-violet-500'],
            'system' => ['badge' => 'anbg-badge border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200', 'bar' => 'bg-slate-500'],
            'standard' => ['badge' => 'anbg-badge anbg-badge-info', 'bar' => 'bg-[#3996D3]'],
        ];
        $exportFilters = collect($filters)
            ->except('page')
            ->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->all();
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            class="mb-4 app-screen-block"
            eyebrow="Audit et traçabilité administrative"
            title="Journal d'audit"
        >
            <x-slot:actions>
                <span class="showcase-chip">
                    <span class="showcase-chip-dot {{ (int) ($summary['anonymous'] ?? 0) > 0 ? 'bg-[#F9B13C]' : 'bg-green-600' }}"></span>
                    {{ number_format((int) ($summary['total'] ?? 0), 0, ',', ' ') }} événement(s)
                </span>
                <a class="btn btn-secondary min-h-10 px-4" href="{{ route('workspace.audit.export.csv', $exportFilters) }}">Exporter en CSV</a>
            </x-slot:actions>
        </x-ui.page-title>

        <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse du journal filtré">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(145px,1fr))]">
                @foreach ($summaryMetrics as $metric)
                    <div class="border-l-4 pl-3 {{ $metric['tone'] }}">
                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                        <strong class="mt-1 block text-2xl">{{ number_format((int) $metric['value'], 0, ',', ' ') }}</strong>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="app-screen-block mt-4" aria-labelledby="audit-history-title">
            <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Vues du journal d’audit">
                @foreach ($scopeViews as $view)
                    @php
                        $isActiveView = $activeScope === $view['code'];
                        $viewUrl = route('workspace.audit.index', array_merge(
                            request()->except(['page', 'operation_scope']),
                            $view['code'] !== '' ? ['operation_scope' => $view['code']] : []
                        ));
                    @endphp
                    <a href="{{ $viewUrl }}" @if ($isActiveView) aria-current="page" @endif class="inline-flex min-h-10 shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition {{ $isActiveView ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}">
                        {{ $view['label'] }}
                        <span class="min-w-6 rounded-full bg-slate-100 px-1.5 py-0.5 text-center text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ number_format($view['count'], 0, ',', ' ') }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('workspace.audit.index') }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-4 dark:border-slate-700 dark:bg-slate-900/70">
                @if ($activeScope !== '')
                    <input type="hidden" name="operation_scope" value="{{ $activeScope }}">
                @endif

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 md:col-span-2 dark:text-slate-400">
                    Recherche
                    <input name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Auteur, email, module, action, entité ou IP..." class="min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 placeholder:text-slate-400 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Module
                    <select name="module" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous</option>
                        @foreach (($options['modules'] ?? []) as $option)
                            <option value="{{ $option['code'] }}" @selected(($filters['module'] ?? '') === $option['code'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Auteur
                    <select name="user_id" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous</option>
                        @foreach (($options['users'] ?? []) as $option)
                            <option value="{{ $option['id'] }}" @selected((int) ($filters['user_id'] ?? 0) === (int) $option['id'])>{{ $option['name'] }} · {{ $option['email'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Action
                    <select name="action" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Toutes</option>
                        @foreach (($options['actions'] ?? []) as $option)
                            <option value="{{ $option['code'] }}" @selected(($filters['action'] ?? '') === $option['code'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Type d’entité
                    <select name="entite_type" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="">Tous</option>
                        @foreach (($options['entity_types'] ?? []) as $option)
                            <option value="{{ $option['code'] }}" @selected(($filters['entite_type'] ?? '') === $option['code'])>{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    ID entité
                    <input name="entite_id" type="number" min="1" value="{{ $filters['entite_id'] ?? '' }}" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Date début
                    <input name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Date fin
                    <input name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Tri
                    <select name="sort" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        <option value="recent" @selected(($filters['sort'] ?? 'recent') === 'recent')>Plus récents</option>
                        <option value="oldest" @selected(($filters['sort'] ?? '') === 'oldest')>Plus anciens</option>
                    </select>
                </label>

                <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                    Par page
                    <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                        @foreach ([15, 30, 50, 100] as $perPage)
                            <option value="{{ $perPage }}" @selected((int) ($filters['per_page'] ?? 30) === $perPage)>{{ $perPage }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex flex-wrap items-end gap-2 md:col-span-2">
                    <button type="submit" class="btn btn-primary min-h-10 px-4">Filtrer</button>
                    <a href="{{ route('workspace.audit.index', $activeScope !== '' ? ['operation_scope' => $activeScope] : []) }}" class="btn btn-secondary min-h-10 px-4">Réinitialiser</a>
                </div>
            </form>

            <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="audit-history-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">Historique traçable</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ number_format((int) ($summary['total'] ?? 0), 0, ',', ' ') }} résultat(s) · tri {{ ($filters['sort'] ?? 'recent') === 'oldest' ? 'du plus ancien' : 'du plus récent' }}</p>
                </div>
                @if ($logs->total() > 0)
                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $logs->firstItem() }}-{{ $logs->lastItem() }} sur {{ $logs->total() }}</span>
                @endif
            </div>

            <div class="mt-3 grid gap-3">
                @forelse ($items as $log)
                    @php
                        $category = (string) ($log['category'] ?? 'standard');
                        $style = $categoryStyles[$category] ?? $categoryStyles['standard'];
                        $hasValues = ($log['before_json'] ?? '') !== '' || ($log['after_json'] ?? '') !== '';
                    @endphp
                    <article class="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-4 pl-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" aria-labelledby="audit-entry-{{ $log['id'] }}">
                        <span class="absolute inset-y-0 left-0 w-1 {{ $style['bar'] }}" aria-hidden="true"></span>

                        <div class="grid gap-4 lg:grid-cols-[10rem_13rem_minmax(0,1fr)_13rem] lg:items-start">
                            <div>
                                <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Horodatage</span>
                                <strong class="mt-1 block text-sm text-[#17324a] dark:text-slate-100">{{ optional($log['created_at'])->format('d/m/Y') ?: '-' }}</strong>
                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ optional($log['created_at'])->format('H:i:s') }} · #{{ $log['id'] }}</span>
                            </div>

                            <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Auteur</span>
                                <strong class="mt-1 block break-words text-sm text-[#17324a] dark:text-slate-100">{{ $log['user']?->name ?? 'Système' }}</strong>
                                <span class="mt-1 block break-all text-xs text-slate-500 dark:text-slate-400">{{ $log['user']?->email ?? ($log['adresse_ip'] ?: 'Sans auteur identifié') }}</span>
                            </div>

                            <div class="min-w-0 border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="{{ $style['badge'] }} px-2 py-0.5 text-xs">{{ $log['category_label'] }}</span>
                                    <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">{{ $log['module_label'] }}</span>
                                </div>
                                <h3 id="audit-entry-{{ $log['id'] }}" class="mt-2 break-words text-base font-bold text-[#17324a] dark:text-slate-100">{{ $log['action_label'] }}</h3>
                                <p class="mt-1 break-words text-sm text-slate-600 dark:text-slate-300">{{ $log['action'] }}</p>
                                @if (! empty($log['changed_fields']))
                                    <p class="mt-2 line-clamp-2 text-xs text-slate-500 dark:text-slate-400">Champs : {{ implode(', ', $log['changed_fields']) }}</p>
                                @endif
                            </div>

                            <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Entité</span>
                                <strong class="mt-1 block break-words text-sm text-[#17324a] dark:text-slate-100">{{ $log['entite_label'] }} #{{ $log['entite_id'] }}</strong>
                                @if ($log['entity_url'])
                                    <a href="{{ $log['entity_url'] }}" class="btn btn-secondary mt-2 min-h-9 w-full px-3 text-xs">Ouvrir le dossier</a>
                                @endif
                            </div>
                        </div>

                        <details class="mt-4 border-t border-slate-200 pt-3 dark:border-slate-700">
                            <summary class="cursor-pointer text-sm font-bold text-[#176A9D] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3996D3] dark:text-sky-200">
                                Détail avant / après{{ $log['change_count'] > 0 ? ' · '.$log['change_count'].' champ(s)' : '' }}
                            </summary>

                            @if ($hasValues)
                                <div class="mt-3 grid gap-4 xl:grid-cols-2">
                                    <div class="min-w-0">
                                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Avant</span>
                                        <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">{{ $log['before_json'] !== '' ? $log['before_json'] : 'Aucune valeur antérieure' }}</pre>
                                    </div>
                                    <div class="min-w-0">
                                        <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Après</span>
                                        <pre class="mt-2 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">{{ $log['after_json'] !== '' ? $log['after_json'] : 'Aucune nouvelle valeur' }}</pre>
                                    </div>
                                </div>
                            @else
                                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Aucune valeur structurée n’a été enregistrée pour cet événement.</p>
                            @endif

                            <dl class="mt-3 grid gap-3 border-t border-slate-200 pt-3 text-xs sm:grid-cols-2 dark:border-slate-700">
                                <div>
                                    <dt class="font-bold uppercase text-slate-500 dark:text-slate-400">Adresse IP</dt>
                                    <dd class="mt-1 break-all text-slate-700 dark:text-slate-200">{{ $log['adresse_ip'] ?: 'Non enregistrée' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-bold uppercase text-slate-500 dark:text-slate-400">Environnement client</dt>
                                    <dd class="mt-1 break-all text-slate-700 dark:text-slate-200">{{ $log['user_agent'] ?: 'Non enregistré' }}</dd>
                                </div>
                            </dl>
                        </details>
                    </article>
                @empty
                    <x-ui.empty-state
                        title="Aucune entrée d’audit"
                        message="Modifiez les filtres ou revenez à la vue complète."
                        icon="filter"
                        tone="info"
                    />
                @endforelse
            </div>

            @if ($logs->hasPages())
                <x-ui.pagination class="mt-5" :paginator="$logs" label="événements" />
            @endif
        </section>
    </div>
@endsection
