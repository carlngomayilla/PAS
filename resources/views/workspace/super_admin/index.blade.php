@extends('layouts.workspace')

@section('title', 'Super Administration')

@section('content')
    @php
        $stateClasses = match ($platformState['tone']) {
            'critical' => 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200',
            'warning' => 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950/35 dark:text-amber-200',
            'info' => 'border-cyan-300 bg-cyan-50 text-cyan-900 dark:border-cyan-800 dark:bg-cyan-950/35 dark:text-cyan-200',
            default => 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/35 dark:text-emerald-200',
        };
        $openDecisions = $summary['pending_deletion_requests'] + $summary['configuration_drafts'];
    @endphp

    <div class="space-y-5">
        <header class="border-b border-slate-200 pb-4 dark:border-slate-700">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase text-cyan-700 dark:text-cyan-300">Super Administration</p>
                    <h1 class="mt-1 text-2xl font-bold text-slate-950 dark:text-white sm:text-3xl">Centre de commandement</h1>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Actualisé le {{ now()->format('d/m/Y à H:i') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="rounded-lg border px-3 py-2 {{ $stateClasses }}" role="status">
                        <p class="text-sm font-semibold">{{ $platformState['label'] }}</p>
                        <p class="text-xs opacity-80">{{ $platformState['detail'] }}</p>
                    </div>
                    <a class="btn btn-primary" href="{{ route('workspace.super-admin.templates.create') }}">+ Nouveau template</a>
                    @include('workspace.super_admin.partials.menu', ['buttonLabel' => 'Accès'])
                </div>
            </div>
        </header>

        <section aria-label="Indicateurs de supervision" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="min-h-28 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex h-full flex-col justify-between gap-3">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Comptes opérationnels</p>
                    <div class="flex items-end justify-between gap-3">
                        <p class="text-3xl font-bold text-slate-950 dark:text-white">{{ $summary['active_users'] }}<span class="text-base font-medium text-slate-400"> / {{ $summary['total_users'] }}</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $summary['active_sessions'] }} session(s)</p>
                    </div>
                </div>
            </article>

            <article class="min-h-28 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex h-full flex-col justify-between gap-3">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Navigation publiée</p>
                    <div class="flex items-end justify-between gap-3">
                        <p class="text-3xl font-bold text-slate-950 dark:text-white">{{ $summary['modules_active'] }}<span class="text-base font-medium text-slate-400"> / {{ $summary['modules_total'] }}</span></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">modules actifs</p>
                    </div>
                </div>
            </article>

            <article class="min-h-28 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex h-full flex-col justify-between gap-3">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Contrôles qualité</p>
                    <div class="flex items-end justify-between gap-3">
                        <p class="text-3xl font-bold {{ $summary['diagnostic_warnings'] > 0 ? 'text-amber-600 dark:text-amber-300' : 'text-emerald-600 dark:text-emerald-300' }}">{{ $summary['diagnostic_warnings'] }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">sur {{ $summary['diagnostic_checks'] }} contrôle(s)</p>
                    </div>
                </div>
            </article>

            <article class="min-h-28 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                <div class="flex h-full flex-col justify-between gap-3">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Décisions ouvertes</p>
                    <div class="flex items-end justify-between gap-3">
                        <p class="text-3xl font-bold {{ $openDecisions > 0 ? 'text-cyan-700 dark:text-cyan-300' : 'text-emerald-600 dark:text-emerald-300' }}">{{ $openDecisions }}</p>
                        <p class="text-right text-xs text-slate-500 dark:text-slate-400">{{ $summary['pending_deletion_requests'] }} gouvernance<br>{{ $summary['configuration_drafts'] }} publication(s)</p>
                    </div>
                </div>
            </article>
        </section>

        <section class="grid gap-5 xl:grid-cols-[minmax(0,1.25fr)_minmax(320px,0.75fr)]">
            <div class="min-w-0">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Priorités</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">À traiter</h2>
                    </div>
                    <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $attentionItems->count() }}</span>
                </div>

                <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                    <div class="divide-y divide-slate-200 dark:divide-slate-700">
                        @foreach ($attentionItems as $item)
                            @php
                                $attentionMarker = match ($item['tone']) {
                                    'critical' => 'bg-red-500',
                                    'warning' => 'bg-amber-500',
                                    'info' => 'bg-cyan-500',
                                    default => 'bg-emerald-500',
                                };
                            @endphp
                            <a class="group flex min-h-16 items-center gap-3 px-4 py-3 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-cyan-500 dark:hover:bg-slate-800/70" href="{{ $item['route'] }}">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $attentionMarker }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $item['label'] }}</span>
                                    <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ $item['meta'] }}</span>
                                </span>
                                <span class="shrink-0 text-lg text-slate-400 transition group-hover:text-cyan-700 dark:group-hover:text-cyan-300" aria-hidden="true">&rarr;</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            <aside class="rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Référence active</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Configuration courante</h2>
                    </div>
                    <a class="text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300" href="{{ $isTechnicalAdministrator ? route('workspace.super-admin.settings.edit') : route('workspace.super-admin.appearance.edit') }}">Modifier</a>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Exercice</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['active_exercise'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Base officielle</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['official_base'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Clôture Actions</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['closure_threshold'] }} %</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Thème</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['default_theme'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Locale</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['locale'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Fuseau</dt>
                        <dd class="mt-1 break-words text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['timezone'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Événements actifs</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['notification_events'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">Règles temporelles</dt>
                        <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">{{ $configurationFacts['timeline_rules'] }}</dd>
                    </div>
                </dl>

                @if ($isTechnicalAdministrator)
                    <div class="mt-4 border-t border-slate-200 pt-3 dark:border-slate-700">
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="text-slate-500 dark:text-slate-400">Dernier snapshot</span>
                            <a class="font-semibold text-slate-900 hover:text-cyan-700 dark:text-white dark:hover:text-cyan-300" href="{{ route('workspace.super-admin.snapshots.index') }}">
                                {{ $latestSnapshot?->created_at?->format('d/m/Y H:i') ?? 'Absent' }}
                            </a>
                        </div>
                    </div>
                @endif
            </aside>
        </section>

        <section>
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Publication</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">État des brouillons</h2>
                </div>
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ $summary['configuration_drafts'] }} en attente</span>
            </div>

            <div class="grid gap-3 md:grid-cols-3">
                @foreach ($configurationDrafts as $draft)
                    <a class="group rounded-lg border border-slate-200 bg-white p-4 transition hover:border-cyan-400 hover:shadow-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-cyan-600" href="{{ $draft['route'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $draft['label'] }}</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    {{ $draft['updated_at']?->format('d/m/Y H:i') ?? 'Version publiée' }}
                                </p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-semibold {{ $draft['has_draft'] ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200' : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-200' }}">
                                {{ $draft['has_draft'] ? 'Brouillon' : 'Publié' }}
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>

        <section>
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Paramétrage</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Domaines d’administration</h2>
                </div>
                <span class="text-sm text-slate-500 dark:text-slate-400">{{ collect($areas)->sum(fn (array $area): int => count($area['items'])) }} accès</span>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($areas as $area)
                    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800/60">
                            <h3 class="text-sm font-bold text-slate-950 dark:text-white">{{ $area['label'] }}</h3>
                        </div>
                        <nav class="divide-y divide-slate-200 dark:divide-slate-700" aria-label="{{ $area['label'] }}">
                            @foreach ($area['items'] as $item)
                                <a class="group flex min-h-14 items-center justify-between gap-3 px-4 py-2.5 transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-cyan-500 dark:hover:bg-slate-800/70" href="{{ $item['route'] }}">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $item['label'] }}</span>
                                        <span class="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">{{ $item['meta'] }}</span>
                                    </span>
                                    <span class="shrink-0 text-lg text-slate-400 transition group-hover:text-cyan-700 dark:group-hover:text-cyan-300" aria-hidden="true">&rarr;</span>
                                </a>
                            @endforeach
                        </nav>
                    </section>
                @endforeach
            </div>
        </section>

        <section class="grid gap-5 xl:grid-cols-[minmax(300px,0.7fr)_minmax(0,1.3fr)]">
            <div class="min-w-0">
                <div class="mb-3">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">7 derniers jours</p>
                    <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Activité de configuration</h2>
                </div>

                <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                    <div class="grid min-w-[490px] grid-cols-7 gap-2">
                        @foreach ($activity as $day)
                            <div class="flex h-20 flex-col items-center justify-center rounded-md border {{ $day['count'] > 0 ? 'border-cyan-200 bg-cyan-50 dark:border-cyan-800 dark:bg-cyan-950/30' : 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800/50' }}">
                                <strong class="text-xl text-slate-950 dark:text-white">{{ $day['count'] }}</strong>
                                <span class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="min-w-0">
                <div class="mb-3 flex items-end justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Traçabilité</p>
                        <h2 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Dernières modifications</h2>
                    </div>
                    <a class="text-sm font-semibold text-cyan-700 hover:underline dark:text-cyan-300" href="{{ route('workspace.audit.index', ['operation_scope' => 'sensitive']) }}">Ouvrir l’audit</a>
                </div>

                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table min-w-[720px]">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left">Date</th>
                                <th class="px-3 py-2 text-left">Opérateur</th>
                                <th class="px-3 py-2 text-left">Domaine</th>
                                <th class="px-3 py-2 text-left">Opération</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentAudits as $audit)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-sm">{{ $audit['created_at']?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-2 text-sm font-medium">{{ $audit['actor'] }}</td>
                                    <td class="px-3 py-2 text-sm">{{ $audit['module_label'] }}</td>
                                    <td class="px-3 py-2 text-sm" title="{{ $audit['action'] }}">{{ $audit['action_label'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        <x-ui.empty-state
                                            title="Aucune modification journalisée"
                                            message="Le journal Super Admin est vide."
                                            icon="clock"
                                            tone="info"
                                            class="my-4"
                                        />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
@endsection
