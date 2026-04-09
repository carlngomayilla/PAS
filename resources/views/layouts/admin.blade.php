<!doctype html>
<html lang="{{ $platformSettings->htmlLang() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ $platformSettings->faviconUrl() }}">
    @php
        $defaultTheme = $appearanceSettings->get('default_theme', 'dark');
    @endphp
    <script>
        (function () {
            var themeKey = 'anbg-theme';
            var root = document.documentElement;
            var theme = @json($defaultTheme);

            try {
                var savedTheme = localStorage.getItem(themeKey);
                if (savedTheme === 'light' || savedTheme === 'dark') {
                    theme = savedTheme;
                }
            } catch (error) {
                theme = @json($defaultTheme);
            }

            root.classList.toggle('dark', theme === 'dark');
            root.setAttribute('data-theme', theme);
        })();
    </script>
    <title>@yield('title', 'Dashboard') - {{ $platformSettings->get('app_short_name', 'ANBG') }}</title>
    @include('partials.vite-assets')
    <style>
        body.admin-theme-scope {
            background: var(--app-body-bg-light);
        }

        html.dark body.admin-theme-scope {
            background: var(--app-body-bg-dark);
        }

        #admin-shell-header {
            background: var(--app-header-bg-light);
        }

        html.dark #admin-shell-header {
            background: var(--app-header-bg-dark);
        }

        #admin-sidebar {
            background: var(--app-sidebar-bg);
        }

        .admin-content-shell {
            padding-left: 0;
        }

        @media (min-width: 1024px) {
            .admin-content-shell {
                padding-left: var(--app-sidebar-width);
            }
        }
    </style>
    @stack('head')
</head>

@php
    $layoutUser = auth()->user();
    $headerNotifications = collect();
    $headerUnreadCount = 0;
    $headerUnreadByModule = [];
    $headerSidebarBadges = [];
    $headerMessages = collect();
    $headerMessageUnreadCount = 0;
    $headerAlertSummary = [
        'total' => 0,
        'unread' => 0,
        'urgence' => 0,
        'critical' => 0,
        'warning' => 0,
        'info' => 0,
    ];
    $headerAlertUnreadCount = 0;

    if ($layoutUser) {
        $headerNotifications = $layoutUser->notifications()
            ->latest()
            ->limit(12)
            ->get();

        $unreadNotifications = $layoutUser->unreadNotifications()
            ->latest()
            ->get();

        $headerUnreadCount = $unreadNotifications->count();
        $headerUnreadByModule = $unreadNotifications
            ->groupBy(static fn ($notification): string => strtolower((string) ($notification->data['module'] ?? 'autres')))
            ->map(static fn ($rows): int => $rows->count())
            ->toArray();
        $headerSidebarBadges = $headerUnreadByModule;

        if (collect($layoutUser->workspaceModules())->pluck('code')->contains('alertes')) {
            $alertReadService = app(\App\Services\Alerting\AlertReadService::class);
            $alertCenterService = app(\App\Services\Alerting\AlertCenterService::class);
            $headerAlertSummary = $alertCenterService->summaryForUser(
                $layoutUser,
                $alertReadService->readFingerprintsForUser($layoutUser)
            );
            $headerAlertUnreadCount = (int) ($headerAlertSummary['unread'] ?? 0);
            $headerSidebarBadges['alertes'] = $headerAlertUnreadCount;
        }

        if (
            \Illuminate\Support\Facades\Schema::hasTable('conversations')
            && \Illuminate\Support\Facades\Schema::hasTable('conversation_participants')
            && \Illuminate\Support\Facades\Schema::hasTable('messages')
        ) {
            $messagingService = app(\App\Services\Messaging\MessagingService::class);
            $headerMessages = $messagingService->recentConversations($layoutUser, 6);
            $headerMessageUnreadCount = $messagingService->unreadCount($layoutUser);
        }
    }
@endphp

<body class="admin-theme-scope h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="min-h-screen">
        <div id="admin-overlay" class="fixed inset-0 z-40 hidden bg-black/40 lg:hidden"></div>

        <x-admin.sidebar :notification-counts="$headerSidebarBadges" :unread-total="$headerUnreadCount" />

        <div class="admin-content-shell">
            <header id="admin-shell-header" class="sticky top-0 z-30 border-b border-[#3996d3]/18 backdrop-blur dark:border-white/10">
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <button
                        type="button"
                        id="admin-sidebar-open"
                        class="inline-flex items-center justify-center rounded-xl p-2 hover:bg-slate-100 dark:hover:bg-slate-900 lg:hidden"
                        aria-label="Ouvrir le menu"
                    >
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">{{ $platformSettings->get('admin_header_eyebrow', 'Administration') }}</p>
                        </div>
                        <h1 class="truncate text-base font-semibold leading-tight sm:text-lg">
                            @yield('title', 'Dashboard')
                        </h1>
                    </div>

                    <div class="hidden w-[280px] md:block">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-3 flex items-center text-slate-400">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M10 18a8 8 0 100-16 8 8 0 000 16z"/>
                                </svg>
                            </span>
                            <input
                                class="w-full rounded-xl border border-[#3996d3]/18 bg-[linear-gradient(135deg,rgba(255,255,255,0.99)_0%,rgba(245,249,252,0.96)_100%)] px-9 py-2 text-sm outline-none focus:ring-2 focus:ring-[#3996d3]/30 dark:border-white/10 dark:bg-none dark:bg-[linear-gradient(135deg,rgba(10,20,46,0.96)_0%,rgba(18,35,72,0.92)_100%)] dark:text-slate-100 dark:placeholder:text-slate-400"
                                placeholder="Rechercher..."
                            />
                        </div>
                    </div>

                    <button
                        type="button"
                        id="admin-theme-toggle"
                        class="inline-flex items-center justify-center rounded-xl p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-slate-100"
                        aria-label="Basculer mode sombre"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3a1 1 0 011 1v1a1 1 0 11-2 0V4a1 1 0 011-1zm0 14a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zm8-6a1 1 0 100 2h1a1 1 0 100-2h-1zM2 12a1 1 0 011-1h1a1 1 0 110 2H3a1 1 0 01-1-1zm14.95-6.364a1 1 0 011.414 0l.707.707a1 1 0 11-1.414 1.414l-.707-.707a1 1 0 010-1.414zM5.636 16.95a1 1 0 011.414 0l.707.707a1 1 0 01-1.414 1.414l-.707-.707a1 1 0 010-1.414zm12.728 1.414a1 1 0 01-1.414 0l-.707-.707a1 1 0 111.414-1.414l.707.707a1 1 0 010 1.414zM7.05 7.05a1 1 0 01-1.414 0L4.93 6.343a1 1 0 011.414-1.414l.707.707a1 1 0 010 1.414zM12 7a5 5 0 100 10 5 5 0 000-10z"/>
                        </svg>
                    </button>

                    <div class="relative" id="header-messaging">
                        <button
                            type="button"
                            id="header-messaging-toggle"
                            class="relative inline-flex items-center justify-center rounded-xl p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-slate-100"
                            aria-label="Messagerie"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8m-8 4h5m-9 5l1.2-3.6A8 8 0 014 12V7a3 3 0 013-3h10a3 3 0 013 3v5a3 3 0 01-3 3H9l-4 4z" />
                            </svg>
                            @if ($headerMessageUnreadCount > 0)
                                <span class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#f8e932] px-1 text-[10px] font-semibold leading-none text-[#1c203d]">
                                    {{ $headerMessageUnreadCount > 99 ? '99+' : $headerMessageUnreadCount }}
                                </span>
                            @endif
                        </button>

                        <div
                            id="header-messaging-menu"
                            class="admin-dropdown-panel absolute right-0 z-40 mt-2 hidden w-[360px] overflow-hidden rounded-2xl"
                        >
                            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                                <div>
                                    <p class="text-sm font-semibold">Messagerie</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $headerMessageUnreadCount }} message(s) non lus</p>
                                </div>
                                <a
                                    href="{{ route('workspace.messaging.index') }}"
                                    class="rounded-lg px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#e8f3fb] dark:text-[#8fc043] dark:hover:bg-slate-900"
                                >
                                    Ouvrir
                                </a>
                            </div>

                            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    <a href="{{ route('workspace.messaging.index') }}" class="rounded-xl border border-slate-200 px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-slate-900">
                                        Messages recents
                                    </a>
                                    <a href="{{ route('workspace.messaging.index') }}#messaging-orgchart" class="rounded-xl border border-slate-200 px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-slate-900">
                                        Organigramme
                                    </a>
                                </div>
                            </div>

                            <div class="max-h-96 overflow-y-auto">
                                @forelse ($headerMessages as $thread)
                                    @php
                                        $threadUser = $thread->getAttribute('other_user');
                                    @endphp
                                    <a
                                        href="{{ route('workspace.messaging.index', ['conversation' => $thread->id]) }}"
                                        class="block border-b border-slate-100 px-3 py-2 transition last:border-b-0 hover:bg-slate-50 dark:border-slate-900 dark:hover:bg-slate-900/60 {{ (int) $thread->getAttribute('unread_messages_count') > 0 ? 'bg-[#fff8d6]/70 dark:bg-[#f8e932]/10' : '' }}"
                                    >
                                        <div class="mb-1 flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">
                                                    {{ $thread->getAttribute('display_name') }}
                                                </p>
                                                <p class="truncate text-[11px] text-slate-500 dark:text-slate-400">
                                                    {{ $threadUser?->agent_fonction ?: $thread->getAttribute('display_scope') }}
                                                </p>
                                            </div>
                                            @if ((int) $thread->getAttribute('unread_messages_count') > 0)
                                                <span class="anbg-badge anbg-badge-warning px-2 py-0.5 text-[10px]">
                                                    {{ $thread->getAttribute('unread_messages_count') }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="truncate text-xs text-slate-600 dark:text-slate-300">
                                            {{ $thread->latestMessage?->body ?: ($thread->latestMessage?->attachment_original_name ?: 'Conversation ouverte.') }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                            {{ $thread->latestMessage?->sent_at?->diffForHumans() ?? 'Nouveau' }}
                                        </p>
                                    </a>
                                @empty
                                    <div class="px-3 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                        Aucune conversation recente.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="relative" id="header-notifications">
                        <button
                            type="button"
                            id="header-notifications-toggle"
                            class="relative inline-flex items-center justify-center rounded-xl p-2 text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-slate-100"
                            aria-label="Notifications"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .53-.21 1.04-.59 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span
                                id="header-notifications-badge"
                                class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#f9b13c] px-1 text-[10px] font-semibold leading-none text-[#1c203d] {{ $headerAlertUnreadCount > 0 ? '' : 'hidden' }}"
                            >
                                {{ $headerAlertUnreadCount > 99 ? '99+' : $headerAlertUnreadCount }}
                            </span>
                        </button>

                        <div
                            id="header-notifications-menu"
                            data-alerts-endpoint="{{ route('workspace.alertes.dropdown') }}"
                            class="admin-dropdown-panel absolute right-0 z-40 mt-2 hidden w-[340px] overflow-hidden rounded-2xl"
                        >
                            <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold">Alertes</p>
                                        <p id="header-alerts-summary" class="text-xs text-slate-500 dark:text-slate-400">
                                            {{ $headerAlertSummary['unread'] ?? 0 }} non lue(s) sur {{ $headerAlertSummary['total'] ?? 0 }} alerte(s).
                                        </p>
                                    </div>
                                    <a
                                        href="{{ route('workspace.alertes') }}"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#e8f3fb] dark:text-[#8fc043] dark:hover:bg-slate-900"
                                    >
                                        Centre
                                    </a>
                                </div>
                                <div id="header-alerts-kpi-summary" class="mt-2 hidden flex flex-wrap gap-2"></div>
                            </div>

                            <div id="header-alerts-items" class="border-b border-slate-200 dark:border-slate-800">
                                <div class="px-3 py-4 text-xs text-slate-500 dark:text-slate-400">
                                    Les alertes recentes s affichent ici.
                                </div>
                            </div>

                            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                                <div>
                                    <p class="text-sm font-semibold">Notifications</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $headerUnreadCount }} non lue(s)</p>
                                </div>
                                <form method="POST" action="{{ route('workspace.notifications.read_all') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#e8f3fb] dark:text-[#8fc043] dark:hover:bg-slate-900"
                                    >
                                        Tout marquer comme lu
                                    </button>
                                </form>
                            </div>

                            <div class="max-h-96 overflow-y-auto">
                                @forelse ($headerNotifications as $notification)
                                    @php
                                        $moduleCode = strtolower((string) ($notification->data['module'] ?? 'autres'));
                                        $moduleLabel = match ($moduleCode) {
                                            'pas' => 'PAS',
                                            'pao' => 'PAO',
                                            'pta' => 'PTA',
                                            'actions' => 'ACTIONS',
                                            'reporting' => 'REPORTING',
                                            'alertes' => 'ALERTES',
                                            'audit' => 'AUDIT',
                                            default => strtoupper($moduleCode),
                                        };
                                        $isUnread = $notification->read_at === null;
                                    @endphp
                                    <a
                                        href="{{ route('workspace.notifications.read', $notification->id) }}"
                                        class="block border-b border-slate-100 px-3 py-2 transition last:border-b-0 hover:bg-slate-50 dark:border-slate-900 dark:hover:bg-slate-900/60 {{ $isUnread ? 'bg-[#e8f3fb]/70 dark:bg-[#3996d3]/10' : '' }}"
                                    >
                                        <div class="mb-1 flex items-start justify-between gap-2">
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                                {{ $notification->data['title'] ?? 'Notification' }}
                                            </p>
                                            <span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px] uppercase tracking-wide leading-none">
                                                {{ $moduleLabel }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-slate-600 dark:text-slate-300">
                                            {{ $notification->data['message'] ?? '' }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                            {{ $notification->created_at?->diffForHumans() }}
                                        </p>
                                    </a>
                                @empty
                                    <div class="px-3 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                                        Aucune notification.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <a href="{{ route('workspace.profile.edit') }}" class="hidden text-right transition-opacity hover:opacity-75 sm:block">
                            <p class="text-sm font-medium dark:text-slate-100">{{ auth()->user()?->name ?? 'Utilisateur' }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()?->roleLabel() ?? 'Compte' }}</p>
                        </a>
                        <a href="{{ route('workspace.profile.edit') }}" class="inline-flex items-center justify-center rounded-2xl hover:opacity-75 transition-opacity">
                            @if (auth()->user()?->profile_photo_url)
                                <img src="{{ auth()->user()->profile_photo_url }}" alt="Avatar" class="h-9 w-9 rounded-2xl object-cover">
                            @else
                                <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-gradient-to-br from-[#3996d3] to-[#1c203d] text-xs font-bold text-white">
                                    {{ auth()->user()?->profile_initials ?? 'U' }}
                                </div>
                            @endif
                        </a>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
                @if (session('success'))
                    <div class="flash-success">{{ session('success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="flash-error">{{ $errors->first() }}</div>
                @endif
                @yield('content')
                <footer class="mt-8 border-t border-slate-200/80 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    {{ $platformSettings->get('footer_text', 'ANBG | Systeme institutionnel de pilotage PAS / PAO / PTA') }}
                </footer>
            </main>
        </div>
    </div>

    <div id="anbg-dialog" class="anbg-dialog hidden" aria-hidden="true">
        <div class="anbg-dialog-backdrop" data-dialog-dismiss></div>
        <div class="anbg-dialog-panel" role="dialog" aria-modal="true" aria-labelledby="anbg-dialog-title">
            <div class="anbg-dialog-header">
                <div>
                    <p id="anbg-dialog-eyebrow" class="anbg-dialog-eyebrow">Confirmation</p>
                    <h2 id="anbg-dialog-title" class="anbg-dialog-title">Confirmer l'action</h2>
                </div>
                <button type="button" id="anbg-dialog-close" class="anbg-dialog-close" aria-label="Fermer la boite de dialogue">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="anbg-dialog-body">
                <p id="anbg-dialog-message" class="anbg-dialog-message"></p>
                <div id="anbg-dialog-input-wrap" class="hidden">
                    <label id="anbg-dialog-input-label" for="anbg-dialog-input" class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">Valeur</label>
                    <input id="anbg-dialog-input" type="text" autocomplete="off">
                    <p id="anbg-dialog-error" class="field-error hidden"></p>
                </div>
            </div>
            <div class="anbg-dialog-actions">
                <button type="button" id="anbg-dialog-cancel" class="btn btn-secondary">Annuler</button>
                <button type="button" id="anbg-dialog-confirm" class="btn btn-primary">Confirmer</button>
            </div>
        </div>
    </div>

    <div id="analytics-explorer" class="analytics-explorer hidden" aria-hidden="true">
        <div class="analytics-explorer-backdrop" data-analytics-explorer-dismiss></div>
        <div class="analytics-explorer-panel" role="dialog" aria-modal="true" aria-labelledby="analytics-explorer-title">
            <div class="analytics-explorer-header">
                <div>
                    <p class="anbg-dialog-eyebrow">Analyse detaillee</p>
                    <h2 id="analytics-explorer-title" class="anbg-dialog-title">Visualisation</h2>
                    <p id="analytics-explorer-subtitle" class="anbg-dialog-message mt-1"></p>
                </div>
                <button type="button" id="analytics-explorer-close" class="anbg-dialog-close" aria-label="Fermer la visualisation">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div id="analytics-explorer-body" class="analytics-explorer-body"></div>
            <div class="analytics-explorer-actions">
                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Cliquez sur un graphique ou un tableau pour l ouvrir ici.</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="analytics-explorer-download" class="btn btn-primary hidden">Telecharger</button>
                    <button type="button" class="btn btn-secondary" data-analytics-explorer-dismiss>Fermer</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var root = document.documentElement;
            var themeKey = 'anbg-theme';
            var toggleThemeButton = document.getElementById('admin-theme-toggle');

            function applyTheme(theme) {
                var isDark = theme === 'dark';
                root.classList.toggle('dark', isDark);
                root.setAttribute('data-theme', isDark ? 'dark' : 'light');
                window.dispatchEvent(new CustomEvent('anbg:theme-changed', {
                    detail: { theme: isDark ? 'dark' : 'light' }
                }));
                return isDark;
            }

            if (toggleThemeButton) {
                toggleThemeButton.dataset.themeBound = '1';
                toggleThemeButton.addEventListener('click', function () {
                    var nextTheme = root.classList.contains('dark') ? 'light' : 'dark';
                    applyTheme(nextTheme);
                    try {
                        localStorage.setItem(themeKey, nextTheme);
                    } catch (error) {
                        // no-op: theme still changes in the current session
                    }
                });
            }

            var sidebar = document.getElementById('admin-sidebar');
            var openButton = document.getElementById('admin-sidebar-open');
            var closeButton = document.getElementById('admin-sidebar-close');
            var overlay = document.getElementById('admin-overlay');
            var sidebarLabelScroll = sidebar ? sidebar.querySelector('[data-gooey-nav-scroll]') : null;
            var sidebarLabelLayer = sidebar ? sidebar.querySelector('[data-gooey-label-layer]') : null;
            var sidebarFloatingLabel = sidebar ? sidebar.querySelector('[data-gooey-floating-label]') : null;
            var sidebarLabelItems = sidebar ? Array.prototype.slice.call(sidebar.querySelectorAll('.gooey-item[data-label]')) : [];
            var sidebarActiveLabelItem = sidebar ? sidebar.querySelector('.gooey-item[data-active="1"]') : null;
            var sidebarCurrentLabelItem = null;
            var sidebarScrollStorageKey = 'anbg:sidebar:scroll:{{ auth()->id() ?? 'guest' }}';
            var sidebarScrollSaveFrame = null;
            var messagingWrapper = document.getElementById('header-messaging');
            var messagingToggle = document.getElementById('header-messaging-toggle');
            var messagingMenu = document.getElementById('header-messaging-menu');
            var notificationsWrapper = document.getElementById('header-notifications');
            var notificationsToggle = document.getElementById('header-notifications-toggle');
            var notificationsMenu = document.getElementById('header-notifications-menu');
            var notificationsBadge = document.getElementById('header-notifications-badge');
            var notificationsAlertsEndpoint = notificationsMenu ? notificationsMenu.getAttribute('data-alerts-endpoint') : null;
            var notificationsAlertsSummary = document.getElementById('header-alerts-summary');
            var notificationsAlertsKpis = document.getElementById('header-alerts-kpi-summary');
            var notificationsAlertsItems = document.getElementById('header-alerts-items');
            var notificationsAlertsLoadedAt = 0;
            var notificationsAlertsPending = null;
            var dialogRoot = document.getElementById('anbg-dialog');
            var dialogBackdrop = dialogRoot ? dialogRoot.querySelector('[data-dialog-dismiss]') : null;
            var dialogClose = document.getElementById('anbg-dialog-close');
            var dialogTitle = document.getElementById('anbg-dialog-title');
            var dialogEyebrow = document.getElementById('anbg-dialog-eyebrow');
            var dialogMessage = document.getElementById('anbg-dialog-message');
            var dialogInputWrap = document.getElementById('anbg-dialog-input-wrap');
            var dialogInputLabel = document.getElementById('anbg-dialog-input-label');
            var dialogInput = document.getElementById('anbg-dialog-input');
            var dialogError = document.getElementById('anbg-dialog-error');
            var dialogCancel = document.getElementById('anbg-dialog-cancel');
            var dialogConfirm = document.getElementById('anbg-dialog-confirm');
            var dialogResolver = null;
            var dialogLastFocused = null;
            var dialogState = null;

            function openSidebar() {
                if (!sidebar || !overlay) {
                    return;
                }
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
            }

            function closeSidebar() {
                if (!sidebar || !overlay) {
                    return;
                }
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }

            if (openButton) {
                openButton.addEventListener('click', openSidebar);
            }
            if (closeButton) {
                closeButton.addEventListener('click', closeSidebar);
            }
            if (overlay) {
                overlay.addEventListener('click', closeSidebar);
            }

            function sidebarLabelsEnabled() {
                return !!(sidebar && sidebarLabelLayer && sidebarFloatingLabel) && window.matchMedia('(min-width: 1024px)').matches;
            }

            function hideSidebarFloatingLabel() {
                if (!sidebarFloatingLabel) {
                    return;
                }

                sidebarFloatingLabel.classList.remove('is-visible');
                sidebarCurrentLabelItem = null;
            }

            function showSidebarFloatingLabel(item) {
                if (!sidebarLabelsEnabled() || !item || !sidebarFloatingLabel || !sidebar) {
                    hideSidebarFloatingLabel();
                    return;
                }

                var trigger = item.querySelector('.gooey-link, .gooey-logout');
                var label = item.dataset.label;

                if (!trigger || !label) {
                    hideSidebarFloatingLabel();
                    return;
                }

                var sidebarRect = sidebar.getBoundingClientRect();
                var triggerRect = trigger.getBoundingClientRect();
                var top = triggerRect.top - sidebarRect.top + (triggerRect.height / 2);
                var left = triggerRect.right - sidebarRect.left + 10;

                sidebarFloatingLabel.textContent = label;
                sidebarFloatingLabel.style.top = top + 'px';
                sidebarFloatingLabel.style.left = left + 'px';
                sidebarFloatingLabel.classList.add('is-visible');
                sidebarCurrentLabelItem = item;
            }

            function restoreSidebarFloatingLabel() {
                if (!sidebarLabelsEnabled()) {
                    hideSidebarFloatingLabel();
                    return;
                }

                if (sidebarActiveLabelItem) {
                    showSidebarFloatingLabel(sidebarActiveLabelItem);
                    return;
                }

                hideSidebarFloatingLabel();
            }

            function persistSidebarScrollPosition() {
                if (!sidebarLabelScroll) {
                    return;
                }

                try {
                    window.localStorage.setItem(sidebarScrollStorageKey, String(sidebarLabelScroll.scrollTop));
                } catch (error) {
                    // no-op: sidebar scroll persistence is a progressive enhancement
                }
            }

            function queueSidebarScrollPositionSave() {
                if (!sidebarLabelScroll) {
                    return;
                }

                if (sidebarScrollSaveFrame) {
                    window.cancelAnimationFrame(sidebarScrollSaveFrame);
                }

                sidebarScrollSaveFrame = window.requestAnimationFrame(function () {
                    sidebarScrollSaveFrame = null;
                    persistSidebarScrollPosition();
                });
            }

            function restoreSidebarScrollPosition() {
                if (!sidebarLabelScroll) {
                    return;
                }

                var savedScrollTop = null;

                try {
                    savedScrollTop = window.localStorage.getItem(sidebarScrollStorageKey);
                } catch (error) {
                    savedScrollTop = null;
                }

                window.requestAnimationFrame(function () {
                    var parsedScrollTop = savedScrollTop === null ? Number.NaN : Number(savedScrollTop);

                    if (Number.isFinite(parsedScrollTop) && parsedScrollTop >= 0) {
                        sidebarLabelScroll.scrollTop = parsedScrollTop;
                    } else if (sidebarActiveLabelItem) {
                        var activeTrigger = sidebarActiveLabelItem.querySelector('.gooey-link, .gooey-logout');
                        if (activeTrigger && typeof activeTrigger.scrollIntoView === 'function') {
                            activeTrigger.scrollIntoView({
                                block: 'center',
                                inline: 'nearest',
                            });
                        }
                    }

                    persistSidebarScrollPosition();
                    restoreSidebarFloatingLabel();
                });
            }

            sidebarLabelItems.forEach(function (item) {
                var trigger = item.querySelector('.gooey-link, .gooey-logout');
                if (!trigger) {
                    return;
                }

                item.addEventListener('mouseenter', function () {
                    showSidebarFloatingLabel(item);
                });

                item.addEventListener('mouseleave', function () {
                    restoreSidebarFloatingLabel();
                });

                trigger.addEventListener('focus', function () {
                    showSidebarFloatingLabel(item);
                });

                trigger.addEventListener('blur', function () {
                    window.requestAnimationFrame(restoreSidebarFloatingLabel);
                });

                trigger.addEventListener('click', persistSidebarScrollPosition);
            });

            if (sidebarLabelScroll) {
                sidebarLabelScroll.addEventListener('scroll', function () {
                    if (sidebarCurrentLabelItem) {
                        showSidebarFloatingLabel(sidebarCurrentLabelItem);
                    }

                    queueSidebarScrollPositionSave();
                });
            }

            restoreSidebarScrollPosition();

            function escapeHtml(value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function notificationAlertTone(level) {
                if (level === 'urgence') {
                    return 'anbg-badge anbg-badge-danger';
                }
                if (level === 'critical') {
                    return 'anbg-badge anbg-badge-danger';
                }

                if (level === 'warning') {
                    return 'anbg-badge anbg-badge-warning';
                }

                return 'anbg-badge anbg-badge-info';
            }

            function renderNavbarAlertSummary(payload) {
                if (!notificationsAlertsSummary) {
                    return;
                }

                var summary = payload && payload.summary ? payload.summary : {};
                notificationsAlertsSummary.textContent = (summary.unread || 0) + ' non lue(s) sur ' + (summary.total || 0) + ' alerte(s).';

                if (notificationsBadge) {
                    var unread = Number(summary.unread || 0);
                    notificationsBadge.textContent = unread > 99 ? '99+' : String(unread);
                    notificationsBadge.classList.toggle('hidden', unread <= 0);
                }
            }

            function renderNavbarAlertKpis(payload) {
                if (!notificationsAlertsKpis) {
                    return;
                }

                var summary = payload && payload.kpi_summary ? payload.kpi_summary : {};
                var cards = [
                    ['Global', summary.global],
                    ['Qualite', summary.qualite],
                    ['Risque', summary.risque],
                    ['Progression', summary.progression],
                ].filter(function (row) {
                    return typeof row[1] !== 'undefined';
                });

                if (!cards.length) {
                    notificationsAlertsKpis.classList.add('hidden');
                    notificationsAlertsKpis.innerHTML = '';
                    return;
                }

                notificationsAlertsKpis.classList.remove('hidden');
                notificationsAlertsKpis.innerHTML = cards.map(function (row) {
                    var value = Number(row[1] || 0);
                    var tone = value >= 80 ? 'success' : (value >= 60 ? 'warning' : 'danger');

                    return '<span class="anbg-badge anbg-badge-' + tone + ' px-3 py-1 text-[11px]">' + escapeHtml(row[0]) + ' ' + escapeHtml(value.toFixed(0)) + '</span>';
                }).join('');
            }

            function renderNavbarAlertItems(payload) {
                if (!notificationsAlertsItems) {
                    return;
                }

                var items = payload && Array.isArray(payload.items) ? payload.items : [];
                if (!items.length) {
                    notificationsAlertsItems.innerHTML = '<div class="px-3 py-4 text-xs text-slate-500 dark:text-slate-400">Aucune alerte recente.</div>';
                    return;
                }

                notificationsAlertsItems.innerHTML = items.map(function (item) {
                    var metrics = item.metrics || {};
                    var metricChips = [
                        ['Global', metrics.kpi_global],
                        ['Qualite', metrics.kpi_qualite],
                        ['Risque', metrics.kpi_risque],
                    ].filter(function (row) {
                        return typeof row[1] !== 'undefined';
                    }).map(function (row) {
                        return '<span class="anbg-badge anbg-badge-neutral px-2 py-0.5 text-[10px]">' + escapeHtml(row[0]) + ' ' + escapeHtml(Number(row[1] || 0).toFixed(0)) + '</span>';
                    }).join('');

                    var escalation = item.escalation_label
                        ? '<span class="anbg-badge anbg-badge-info px-2 py-0.5 text-[10px]">Escalade ' + escapeHtml(item.escalation_label) + '</span>'
                        : '';

                    return '<a href="' + escapeHtml(item.read_url || item.target_url || '#') + '" class="block border-b border-slate-100 px-3 py-2 transition last:border-b-0 hover:bg-slate-50 dark:border-slate-900 dark:hover:bg-slate-900/60 ' + (item.is_unread ? 'bg-[#fff8d6]/70 dark:bg-[#f8e932]/10' : '') + '">' +
                        '<div class="mb-1 flex items-start justify-between gap-2">' +
                            '<p class="text-sm font-semibold text-slate-900 dark:text-slate-100">' + escapeHtml(item.titre || 'Alerte') + '</p>' +
                            '<span class="' + notificationAlertTone(String(item.niveau || 'info')) + ' px-2 py-0.5 text-[10px]">' + escapeHtml(item.niveau_label || item.niveau || 'Info') + '</span>' +
                        '</div>' +
                        '<p class="text-xs text-slate-600 dark:text-slate-300">' + escapeHtml(item.message || '') + '</p>' +
                        '<div class="mt-2 flex flex-wrap items-center gap-2">' + metricChips + escalation + '</div>' +
                        '<p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">' + escapeHtml(item.date_label || '') + '</p>' +
                    '</a>';
                }).join('');
            }

            function loadNavbarAlerts(forceRefresh) {
                if (!notificationsAlertsEndpoint || !notificationsAlertsItems) {
                    return Promise.resolve();
                }

                var isFresh = notificationsAlertsLoadedAt > 0 && (Date.now() - notificationsAlertsLoadedAt) < 60000;
                if (!forceRefresh && isFresh) {
                    return Promise.resolve();
                }

                if (notificationsAlertsPending) {
                    return notificationsAlertsPending;
                }

                notificationsAlertsPending = window.fetch(notificationsAlertsEndpoint, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('alerts_dropdown_failed');
                        }

                        return response.json();
                    })
                    .then(function (payload) {
                        notificationsAlertsLoadedAt = Date.now();
                        renderNavbarAlertSummary(payload);
                        renderNavbarAlertKpis(payload);
                        renderNavbarAlertItems(payload);
                    })
                    .catch(function () {
                        if (notificationsAlertsSummary) {
                            notificationsAlertsSummary.textContent = 'Impossible de charger les alertes pour le moment.';
                        }
                        if (notificationsAlertsKpis) {
                            notificationsAlertsKpis.classList.add('hidden');
                            notificationsAlertsKpis.innerHTML = '';
                        }
                        if (notificationsAlertsItems) {
                            notificationsAlertsItems.innerHTML = '<div class="px-3 py-4 text-xs text-slate-500 dark:text-slate-400">Ouvrir le centre d alertes pour consulter le detail.</div>';
                        }
                    })
                    .finally(function () {
                        notificationsAlertsPending = null;
                    });

                return notificationsAlertsPending;
            }

            function openNotificationsMenu() {
                if (!notificationsMenu) {
                    return;
                }
                closeMessagingMenu();
                notificationsMenu.classList.remove('hidden');
                loadNavbarAlerts(false);
            }

            function closeNotificationsMenu() {
                if (!notificationsMenu) {
                    return;
                }
                notificationsMenu.classList.add('hidden');
            }

            function openMessagingMenu() {
                if (!messagingMenu) {
                    return;
                }
                closeNotificationsMenu();
                messagingMenu.classList.remove('hidden');
            }

            function closeMessagingMenu() {
                if (!messagingMenu) {
                    return;
                }
                messagingMenu.classList.add('hidden');
            }

            function resetDialog() {
                if (!dialogRoot) {
                    return;
                }

                dialogRoot.classList.add('hidden');
                dialogRoot.setAttribute('aria-hidden', 'true');

                if (dialogInputWrap) {
                    dialogInputWrap.classList.add('hidden');
                }

                if (dialogInput) {
                    dialogInput.value = '';
                }

                if (dialogError) {
                    dialogError.textContent = '';
                    dialogError.classList.add('hidden');
                }

                dialogState = null;

                if (dialogLastFocused && typeof dialogLastFocused.focus === 'function') {
                    dialogLastFocused.focus();
                }
                dialogLastFocused = null;
            }

            function resolveDialog(payload) {
                var resolver = dialogResolver;
                dialogResolver = null;
                resetDialog();
                if (typeof resolver === 'function') {
                    resolver(payload);
                }
            }

            function openDialog(options) {
                if (!dialogRoot || !dialogTitle || !dialogMessage || !dialogCancel || !dialogConfirm) {
                    return Promise.resolve({ confirmed: false, value: null });
                }

                dialogLastFocused = document.activeElement;
                dialogState = options || {};

                dialogRoot.classList.remove('hidden');
                dialogRoot.setAttribute('aria-hidden', 'false');

                if (dialogEyebrow) {
                    dialogEyebrow.textContent = dialogState.eyebrow || 'Confirmation';
                }

                dialogTitle.textContent = dialogState.title || 'Confirmer l action';
                dialogMessage.textContent = dialogState.message || '';
                dialogCancel.textContent = dialogState.cancelLabel || 'Annuler';
                dialogConfirm.textContent = dialogState.confirmLabel || 'Confirmer';
                dialogConfirm.dataset.tone = dialogState.tone || 'primary';

                if (dialogInputWrap && dialogInput && dialogInputLabel) {
                    var hasPrompt = dialogState.mode === 'prompt';
                    dialogInputWrap.classList.toggle('hidden', !hasPrompt);
                    dialogInputLabel.textContent = dialogState.inputLabel || 'Valeur';
                    dialogInput.placeholder = dialogState.inputPlaceholder || '';
                    dialogInput.value = dialogState.initialValue || '';
                }

                if (dialogError) {
                    dialogError.textContent = '';
                    dialogError.classList.add('hidden');
                }

                window.requestAnimationFrame(function () {
                    if (dialogState && dialogState.mode === 'prompt' && dialogInput) {
                        dialogInput.focus();
                        dialogInput.select();
                    } else {
                        dialogConfirm.focus();
                    }
                });

                return new Promise(function (resolve) {
                    dialogResolver = resolve;
                });
            }

            function submitDialogConfirm() {
                if (!dialogState) {
                    resolveDialog({ confirmed: false, value: null });
                    return;
                }

                if (dialogState.mode === 'prompt') {
                    var value = dialogInput ? dialogInput.value.trim() : '';
                    var minLength = Number(dialogState.minLength || 0);

                    if (value.length < minLength) {
                        if (dialogError) {
                            dialogError.textContent = 'Veuillez saisir au moins ' + minLength + ' caracteres.';
                            dialogError.classList.remove('hidden');
                        }
                        if (dialogInput) {
                            dialogInput.focus();
                        }
                        return;
                    }

                    resolveDialog({ confirmed: true, value: value });
                    return;
                }

                resolveDialog({ confirmed: true, value: null });
            }

            if (notificationsToggle) {
                notificationsToggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!notificationsMenu) {
                        return;
                    }
                    if (notificationsMenu.classList.contains('hidden')) {
                        openNotificationsMenu();
                    } else {
                        closeNotificationsMenu();
                    }
                });
            }

            if (messagingToggle) {
                messagingToggle.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!messagingMenu) {
                        return;
                    }
                    if (messagingMenu.classList.contains('hidden')) {
                        openMessagingMenu();
                    } else {
                        closeMessagingMenu();
                    }
                });
            }

            document.addEventListener('click', function (event) {
                if (!notificationsWrapper || !notificationsMenu) {
                    if (!messagingWrapper || !messagingMenu) {
                        return;
                    }
                }

                if (messagingWrapper && messagingMenu && !messagingWrapper.contains(event.target)) {
                    closeMessagingMenu();
                }

                if (!notificationsWrapper || !notificationsMenu) {
                    return;
                }
                if (notificationsWrapper.contains(event.target)) {
                    return;
                }
                closeNotificationsMenu();
            });

            if (dialogBackdrop) {
                dialogBackdrop.addEventListener('click', function () {
                    resolveDialog({ confirmed: false, value: null });
                });
            }

            if (dialogClose) {
                dialogClose.addEventListener('click', function () {
                    resolveDialog({ confirmed: false, value: null });
                });
            }

            if (dialogCancel) {
                dialogCancel.addEventListener('click', function () {
                    resolveDialog({ confirmed: false, value: null });
                });
            }

            if (dialogConfirm) {
                dialogConfirm.addEventListener('click', submitDialogConfirm);
            }

            if (dialogInput) {
                dialogInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        submitDialogConfirm();
                    }
                });
            }

            document.addEventListener('submit', function (event) {
                var form = event.target;

                if (!(form instanceof HTMLFormElement)) {
                    return;
                }

                var confirmMessage = form.dataset.confirmMessage;
                var promptMessage = form.dataset.promptMessage;

                if (!confirmMessage && !promptMessage) {
                    return;
                }

                event.preventDefault();

                if (promptMessage) {
                    openDialog({
                        mode: 'prompt',
                        eyebrow: form.dataset.promptTitle || 'Retour brouillon',
                        title: form.dataset.promptTitle || 'Retour brouillon',
                        message: promptMessage,
                        tone: 'primary',
                        confirmLabel: form.dataset.promptConfirm || 'Confirmer',
                        cancelLabel: form.dataset.promptCancel || 'Annuler',
                        inputLabel: form.dataset.promptLabel || 'Motif',
                        inputPlaceholder: form.dataset.promptPlaceholder || '',
                        minLength: Number(form.dataset.promptMinlength || 0),
                    }).then(function (result) {
                        if (!result.confirmed) {
                            return;
                        }

                        var targetName = form.dataset.promptTarget || 'motif_retour';
                        var targetInput = form.querySelector('[name=\"' + targetName + '\"]');
                        if (targetInput) {
                            targetInput.value = result.value || '';
                        }

                        HTMLFormElement.prototype.submit.call(form);
                    });

                    return;
                }

                openDialog({
                    mode: 'confirm',
                    eyebrow: form.dataset.confirmTone === 'danger' ? 'Action sensible' : 'Confirmation',
                    title: form.dataset.confirmTitle || 'Confirmer l action',
                    message: confirmMessage,
                    tone: form.dataset.confirmTone || 'primary',
                    confirmLabel: form.dataset.confirmLabel || 'Confirmer',
                    cancelLabel: form.dataset.confirmCancel || 'Annuler',
                }).then(function (result) {
                    if (!result.confirmed) {
                        return;
                    }

                    HTMLFormElement.prototype.submit.call(form);
                });
            }, true);

            window.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    if (dialogResolver) {
                        resolveDialog({ confirmed: false, value: null });
                    }
                    closeSidebar();
                    closeMessagingMenu();
                    closeNotificationsMenu();
                }
            });

            function syncSidebarForViewport() {
                if (!sidebar || !overlay) {
                    return;
                }

                if (window.matchMedia('(min-width: 1024px)').matches) {
                    sidebar.classList.remove('-translate-x-full');
                    overlay.classList.add('hidden');
                    return;
                }

                if (!sidebar.classList.contains('-translate-x-full')) {
                    overlay.classList.remove('hidden');
                }

                restoreSidebarFloatingLabel();
            }

            syncSidebarForViewport();
            window.addEventListener('resize', syncSidebarForViewport);

        })();
    </script>
    @stack('scripts')
</body>
</html>
