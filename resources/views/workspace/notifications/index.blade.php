@extends('layouts.workspace')

@section('title', 'Notifications et alertes')

@section('content')
    @php
        $activeTab = $activeTab ?? 'notifications';
        $canReadAlerts = (bool) ($canReadAlerts ?? false);
        $notificationSummary = is_array($notificationSummary ?? null) ? $notificationSummary : [];
        $notificationFilteredSummary = is_array($notificationFilteredSummary ?? null) ? $notificationFilteredSummary : [];
        $notificationFilters = is_array($notificationFilters ?? null) ? $notificationFilters : [];
        $notificationModuleOptions = collect($notificationModuleOptions ?? []);
        $notificationItems = collect($notifications->items());
        $alertSummary = is_array($alertSummary ?? null) ? $alertSummary : [];
        $alertFilteredSummary = is_array($alertFilteredSummary ?? null) ? $alertFilteredSummary : [];
        $alertFilters = is_array($alertFilters ?? null) ? $alertFilters : [];
        $alertTypeOptions = collect($alertTypeOptions ?? []);
        $alertItems = collect($alertPaginator->items());
        $alertView = (string) ($alertFilters['vue'] ?? 'actives');
        $alertUnreadCount = (int) ($alertSummary['unread'] ?? 0);
        $levelLabels = [
            'urgence' => 'Urgence',
            'critical' => 'Critique',
            'warning' => 'Vigilance',
            'conforme' => 'Conforme',
            'info' => 'Information',
        ];
        $levelBadges = [
            'urgence' => 'anbg-badge anbg-badge-danger',
            'critical' => 'anbg-badge border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-800 dark:bg-orange-950/60 dark:text-orange-200',
            'warning' => 'anbg-badge anbg-badge-warning',
            'conforme' => 'anbg-badge anbg-badge-success',
            'info' => 'anbg-badge anbg-badge-info',
        ];
        $levelBars = [
            'urgence' => 'bg-[#B42318]',
            'critical' => 'bg-[#E66B19]',
            'warning' => 'bg-[#F9B13C]',
            'conforme' => 'bg-[#5D8E25]',
            'info' => 'bg-[#3996D3]',
        ];
        $normalizeNotificationLevel = static function (array $data): string {
            $level = strtolower(trim((string) ($data['level'] ?? $data['niveau'] ?? 'info')));

            return match ($level) {
                'urgent', 'urgente', 'urgence' => 'urgence',
                'critical', 'critique', 'danger', 'error', 'erreur' => 'critical',
                'warning', 'avertissement', 'vigilance' => 'warning',
                'conforme', 'success', 'succes', 'validée', 'validee' => 'conforme',
                default => 'info',
            };
        };
        $notificationMetrics = collect([
            ['label' => 'Total', 'value' => (int) ($notificationSummary['total'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-300 dark:text-sky-200', 'always' => true],
            ['label' => 'Non lues', 'value' => (int) ($notificationSummary['unread'] ?? 0), 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200', 'always' => true],
            ['label' => 'Lues', 'value' => (int) ($notificationSummary['read'] ?? 0), 'tone' => 'border-[#5D8E25] text-[#4B741E] dark:border-lime-400 dark:text-lime-200'],
            ['label' => 'Prioritaires', 'value' => (int) ($notificationSummary['critical'] ?? 0), 'tone' => 'border-[#B42318] text-[#B42318] dark:border-red-400 dark:text-red-300'],
        ])->filter(static fn (array $metric): bool => (bool) ($metric['always'] ?? false) || (int) $metric['value'] > 0);
        $alertMetrics = collect([
            ['label' => 'Actives', 'value' => (int) ($alertSummary['total'] ?? 0), 'tone' => 'border-[#17324a] text-[#17324a] dark:border-sky-300 dark:text-sky-200', 'always' => true],
            ['label' => 'Non lues', 'value' => $alertUnreadCount, 'tone' => 'border-[#3996D3] text-[#176A9D] dark:border-cyan-300 dark:text-cyan-200', 'always' => true],
            ['label' => 'Urgences', 'value' => (int) ($alertSummary['urgence'] ?? 0), 'tone' => 'border-[#B42318] text-[#B42318] dark:border-red-400 dark:text-red-300'],
            ['label' => 'Critiques', 'value' => (int) ($alertSummary['critical'] ?? 0), 'tone' => 'border-[#E66B19] text-[#B54708] dark:border-orange-400 dark:text-orange-200'],
            ['label' => 'Historique', 'value' => (int) ($alertHistoryTotal ?? 0), 'tone' => 'border-[#5D8E25] text-[#4B741E] dark:border-lime-400 dark:text-lime-200'],
        ])->filter(static fn (array $metric): bool => (bool) ($metric['always'] ?? false) || (int) $metric['value'] > 0);
    @endphp

    <div class="app-screen-flow">
        <x-ui.page-title
            class="mb-4 app-screen-block"
            eyebrow="Centre personnel de traitement"
            title="Notifications et alertes"
        >
            <x-slot:actions>
                <span class="showcase-chip">
                    <span class="showcase-chip-dot {{ $unreadCount > 0 ? 'bg-[#3996D3]' : 'bg-green-600' }}"></span>
                    {{ $unreadCount }} notification(s) non lue(s)
                </span>
                @if ($canReadAlerts)
                    <span class="showcase-chip">
                        <span class="showcase-chip-dot {{ $alertUnreadCount > 0 ? 'bg-[#F9B13C]' : 'bg-green-600' }}"></span>
                        {{ $alertUnreadCount }} alerte(s) non lue(s)
                    </span>
                @endif

                @if ($activeTab === 'notifications' && $unreadCount > 0)
                    <form method="POST" action="{{ route('workspace.notifications.read_all') }}" data-confirm-message="Marquer toutes les notifications comme lues ?" data-confirm-tone="info" data-confirm-label="Tout marquer">
                        @csrf
                        <button class="btn btn-primary min-h-10 px-4" type="submit">Tout marquer comme lu</button>
                    </form>
                @elseif ($activeTab === 'alertes' && $alertUnreadCount > 0)
                    <form method="POST" action="{{ route('workspace.alertes.read_all') }}" data-confirm-message="Marquer toutes les alertes actives comme lues ?" data-confirm-tone="info" data-confirm-label="Tout marquer">
                        @csrf
                        <button class="btn btn-primary min-h-10 px-4" type="submit">Tout marquer comme lu</button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-title>

        <nav class="app-screen-block flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Sections du centre de notifications">
            @foreach ([
                ['code' => 'notifications', 'label' => 'Notifications', 'count' => $unreadCount],
                ['code' => 'alertes', 'label' => 'Alertes', 'count' => $alertUnreadCount],
            ] as $tab)
                @continue($tab['code'] === 'alertes' && ! $canReadAlerts)
                @php
                    $isActiveTab = $activeTab === $tab['code'];
                    $tabUrl = route('workspace.notifications.index', $tab['code'] === 'alertes' ? ['tab' => 'alertes'] : []);
                @endphp
                <a
                    href="{{ $tabUrl }}"
                    @if ($isActiveTab) aria-current="page" @endif
                    class="inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 py-2 text-sm font-bold transition {{ $isActiveTab ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}"
                >
                    {{ $tab['label'] }}
                    <span class="min-w-6 rounded-full bg-slate-100 px-1.5 py-0.5 text-center text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $tab['count'] }}</span>
                </a>
            @endforeach
        </nav>

        @if ($activeTab === 'alertes')
            <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse des alertes">
                <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))]">
                    @foreach ($alertMetrics as $metric)
                        <div class="border-l-4 pl-3 {{ $metric['tone'] }}">
                            <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                            <strong class="mt-1 block text-2xl">{{ number_format((int) $metric['value'], 0, ',', ' ') }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="app-screen-block mt-4" aria-labelledby="alert-center-title">
                <nav class="flex gap-1 overflow-x-auto border-b border-slate-200 pb-px dark:border-slate-700" aria-label="Vues des alertes">
                    @foreach ([
                        ['code' => 'actives', 'label' => 'Alertes actives', 'count' => (int) ($alertSummary['total'] ?? 0)],
                        ['code' => 'historique', 'label' => 'Historique', 'count' => (int) ($alertHistoryTotal ?? 0)],
                    ] as $view)
                        @php
                            $isActiveView = $alertView === $view['code'];
                            $viewUrl = route('workspace.notifications.index', array_merge(
                                request()->except(['page', 'vue', 'etat']),
                                ['tab' => 'alertes', 'vue' => $view['code']]
                            ));
                        @endphp
                        <a href="{{ $viewUrl }}" @if ($isActiveView) aria-current="page" @endif class="inline-flex min-h-10 shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-semibold transition {{ $isActiveView ? 'border-[#3996D3] text-[#176A9D] dark:text-sky-200' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-100' }}">
                            {{ $view['label'] }}
                            <span class="min-w-6 rounded-full bg-slate-100 px-1.5 py-0.5 text-center text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $view['count'] }}</span>
                        </a>
                    @endforeach
                </nav>

                <form method="GET" action="{{ route('workspace.notifications.index') }}" class="mt-4 grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(150px,.65fr)_minmax(180px,.8fr)_minmax(140px,.6fr)_7rem_auto_auto] dark:border-slate-700 dark:bg-slate-900/70">
                    <input type="hidden" name="tab" value="alertes">
                    <input type="hidden" name="vue" value="{{ $alertView }}">

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Recherche
                        <input name="q" type="search" maxlength="100" value="{{ $alertFilters['q'] ?? '' }}" placeholder="Alerte, action, direction..." class="min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 placeholder:text-slate-400 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Niveau
                        <select name="niveau" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">Tous</option>
                            @foreach ($levelLabels as $code => $label)
                                <option value="{{ $code }}" @selected(($alertFilters['niveau'] ?? null) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Origine
                        <select name="type" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">Toutes</option>
                            @foreach ($alertTypeOptions as $option)
                                <option value="{{ $option['code'] }}" @selected(($alertFilters['type'] ?? null) === $option['code'])>{{ $option['label'] }} ({{ (int) $option['count'] }})</option>
                            @endforeach
                        </select>
                    </label>

                    @if ($alertView === 'actives')
                        <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                            État
                            <select name="etat" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                                <option value="">Toutes</option>
                                <option value="unread" @selected(($alertFilters['etat'] ?? null) === 'unread')>Non lues</option>
                                <option value="read" @selected(($alertFilters['etat'] ?? null) === 'read')>Lues</option>
                            </select>
                        </label>
                    @else
                        <input type="hidden" name="etat" value="">
                    @endif

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Par page
                        <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            @foreach ([15, 25, 50] as $perPage)
                                <option value="{{ $perPage }}" @selected((int) ($alertFilters['per_page'] ?? 15) === $perPage)>{{ $perPage }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="btn btn-primary min-h-10 self-end px-4">Filtrer</button>
                    <a href="{{ route('workspace.notifications.index', ['tab' => 'alertes', 'vue' => $alertView]) }}" class="btn btn-secondary min-h-10 self-end px-4">Réinitialiser</a>
                </form>

                <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="alert-center-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">{{ $alertView === 'historique' ? 'Historique des alertes' : 'File des alertes actives' }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ number_format((int) ($alertFilteredSummary['total'] ?? 0), 0, ',', ' ') }} résultat(s) dans cette vue</p>
                    </div>
                    @if ($alertPaginator->total() > 0)
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $alertPaginator->firstItem() }}-{{ $alertPaginator->lastItem() }} sur {{ $alertPaginator->total() }}</span>
                    @endif
                </div>

                <div class="mt-3 grid gap-3">
                    @forelse ($alertItems as $alert)
                        @php
                            $level = (string) ($alert['niveau'] ?? 'info');
                            $isUnread = $alertView === 'actives' && (bool) ($alert['is_unread'] ?? false);
                            $targetUrl = $alertView === 'actives'
                                ? ($alert['read_url'] ?? $alert['target_url'] ?? route('workspace.notifications.index', ['tab' => 'alertes']))
                                : ($alert['target_url'] ?? route('workspace.notifications.index', ['tab' => 'alertes', 'vue' => 'historique']));
                        @endphp
                        <article class="relative overflow-hidden rounded-lg border p-4 pl-5 shadow-sm {{ $isUnread ? 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900' : 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-900/70' }}">
                            <span class="absolute inset-y-0 left-0 w-1 {{ $levelBars[$level] ?? $levelBars['info'] }}" aria-hidden="true"></span>

                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_12rem_10rem_8rem] lg:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="{{ $levelBadges[$level] ?? $levelBadges['info'] }} px-2 py-0.5 text-xs">{{ $alert['niveau_label'] ?? ($levelLabels[$level] ?? ucfirst($level)) }}</span>
                                        <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">{{ $alert['type_label'] ?? 'Alerte' }}</span>
                                        @if ($isUnread)
                                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">Non lue</span>
                                        @elseif ($alertView === 'historique')
                                            <span class="anbg-badge anbg-badge-success px-2 py-0.5 text-xs">Lue le {{ $alert['read_at_label'] ?? '-' }}</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 break-words text-base font-bold text-[#17324a] dark:text-slate-100">{{ $alert['titre'] ?? 'Alerte' }}</h3>
                                    <p class="mt-1 break-words text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $alert['message'] ?? '' }}</p>
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Périmètre</span>
                                    <strong class="mt-1 block break-words text-sm text-[#17324a] dark:text-slate-100">{{ $alert['direction'] ?? '-' }}</strong>
                                    <span class="mt-1 block break-words text-xs text-slate-500 dark:text-slate-400">{{ $alert['service'] ?? '-' }}</span>
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Signalement</span>
                                    <strong class="mt-1 block text-sm text-[#17324a] dark:text-slate-100">{{ $alert['date_label'] ?? '-' }}</strong>
                                    @if (! empty($alert['section_label']))
                                        <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ $alert['section_label'] }}</span>
                                    @endif
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <a class="btn btn-primary min-h-10 w-full px-4" href="{{ $targetUrl }}">Ouvrir</a>
                                </div>
                            </div>
                        </article>
                    @empty
                        <x-ui.empty-state
                            title="Aucune alerte dans cette vue"
                            message="Modifiez les filtres ou consultez l’autre vue."
                            icon="bell"
                            tone="info"
                        />
                    @endforelse
                </div>

                @if ($alertPaginator->hasPages())
                    <x-ui.pagination class="mt-5" :paginator="$alertPaginator" label="alertes" />
                @endif
            </section>
        @else
            <section class="app-screen-block border-y border-slate-200 bg-white/70 py-4 dark:border-slate-700 dark:bg-slate-900/55" aria-label="Synthèse des notifications">
                <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(145px,1fr))]">
                    @foreach ($notificationMetrics as $metric)
                        <div class="border-l-4 pl-3 {{ $metric['tone'] }}">
                            <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">{{ $metric['label'] }}</span>
                            <strong class="mt-1 block text-2xl">{{ number_format((int) $metric['value'], 0, ',', ' ') }}</strong>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="app-screen-block mt-4" aria-labelledby="notification-center-title">
                <form method="GET" action="{{ route('workspace.notifications.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/85 p-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_minmax(150px,.65fr)_minmax(170px,.75fr)_minmax(140px,.6fr)_7rem_auto_auto] dark:border-slate-700 dark:bg-slate-900/70">
                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Recherche
                        <input name="q" type="search" maxlength="100" value="{{ $notificationFilters['q'] ?? '' }}" placeholder="Titre, message, module..." class="min-h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 placeholder:text-slate-400 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        État
                        <select name="etat" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">Toutes</option>
                            <option value="unread" @selected(($notificationFilters['etat'] ?? null) === 'unread')>Non lues</option>
                            <option value="read" @selected(($notificationFilters['etat'] ?? null) === 'read')>Lues</option>
                        </select>
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Module
                        <select name="module" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">Tous</option>
                            @foreach ($notificationModuleOptions as $option)
                                <option value="{{ $option['code'] }}" @selected(($notificationFilters['module'] ?? '') === $option['code'])>{{ $option['label'] }} ({{ (int) $option['count'] }})</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Niveau
                        <select name="niveau" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            <option value="">Tous</option>
                            @foreach ($levelLabels as $code => $label)
                                <option value="{{ $code }}" @selected(($notificationFilters['niveau'] ?? null) === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="grid gap-1 text-xs font-bold uppercase text-slate-500 dark:text-slate-400">
                        Par page
                        <select name="per_page" class="min-h-10 rounded-lg border border-slate-300 bg-white px-3 text-sm font-normal normal-case text-slate-900 focus:border-[#3996D3] focus:outline-none focus:ring-2 focus:ring-[#3996D3]/20 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100">
                            @foreach ([15, 25, 50] as $perPage)
                                <option value="{{ $perPage }}" @selected((int) ($notificationFilters['per_page'] ?? 15) === $perPage)>{{ $perPage }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="submit" class="btn btn-primary min-h-10 self-end px-4">Filtrer</button>
                    <a href="{{ route('workspace.notifications.index') }}" class="btn btn-secondary min-h-10 self-end px-4">Réinitialiser</a>
                </form>

                <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 id="notification-center-title" class="text-lg font-bold text-[#17324a] dark:text-slate-100">Boîte de réception</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ number_format((int) ($notificationFilteredSummary['total'] ?? 0), 0, ',', ' ') }} résultat(s)
                            @if ((int) ($notificationFilteredSummary['unread'] ?? 0) > 0)
                                · {{ (int) $notificationFilteredSummary['unread'] }} non lu(s)
                            @endif
                        </p>
                    </div>
                    @if ($notifications->total() > 0)
                        <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $notifications->firstItem() }}-{{ $notifications->lastItem() }} sur {{ $notifications->total() }}</span>
                    @endif
                </div>

                <div class="mt-3 grid gap-3">
                    @forelse ($notificationItems as $notification)
                        @php
                            $data = is_array($notification->data ?? null) ? $notification->data : [];
                            $title = (string) ($data['title'] ?? $data['titre'] ?? 'Notification');
                            $message = (string) ($data['message'] ?? $data['body'] ?? '');
                            $module = trim((string) ($data['module'] ?? 'Général')) ?: 'Général';
                            $level = $normalizeNotificationLevel($data);
                            $isUnread = $notification->read_at === null;
                        @endphp
                        <article class="relative overflow-hidden rounded-lg border p-4 pl-5 shadow-sm {{ $isUnread ? 'border-slate-300 bg-white dark:border-slate-600 dark:bg-slate-900' : 'border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-900/70' }}">
                            <span class="absolute inset-y-0 left-0 w-1 {{ $levelBars[$level] ?? $levelBars['info'] }}" aria-hidden="true"></span>

                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_10rem_10rem_8rem] lg:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="{{ $levelBadges[$level] ?? $levelBadges['info'] }} px-2 py-0.5 text-xs">{{ $levelLabels[$level] ?? 'Information' }}</span>
                                        @if ($isUnread)
                                            <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-xs">Non lue</span>
                                        @else
                                            <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-xs">Lue</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 break-words text-base font-bold text-[#17324a] dark:text-slate-100">{{ $title }}</h3>
                                    @if ($message !== '')
                                        <p class="mt-1 break-words text-sm leading-6 text-slate-600 dark:text-slate-300">{{ $message }}</p>
                                    @endif
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Module</span>
                                    <strong class="mt-1 block break-words text-sm text-[#17324a] dark:text-slate-100">{{ Illuminate\Support\Str::headline($module) }}</strong>
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <span class="block text-xs font-bold uppercase text-slate-500 dark:text-slate-400">Reçue le</span>
                                    <strong class="mt-1 block text-sm text-[#17324a] dark:text-slate-100">{{ optional($notification->created_at)->format('d/m/Y') ?: '-' }}</strong>
                                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">{{ optional($notification->created_at)->format('H:i') }}</span>
                                </div>

                                <div class="border-t border-slate-100 pt-3 lg:border-l lg:border-t-0 lg:pl-4 lg:pt-0 dark:border-slate-700">
                                    <a class="btn btn-primary min-h-10 w-full px-4" href="{{ route('workspace.notifications.read', $notification->id) }}">Ouvrir</a>
                                </div>
                            </div>
                        </article>
                    @empty
                        <x-ui.empty-state
                            title="Aucune notification dans cette vue"
                            message="Modifiez les filtres ou revenez à la vue complète."
                            icon="bell"
                            tone="info"
                        />
                    @endforelse
                </div>

                @if ($notifications->hasPages())
                    <x-ui.pagination class="mt-5" :paginator="$notifications" label="notifications" />
                @endif
            </section>
        @endif
    </div>
@endsection
