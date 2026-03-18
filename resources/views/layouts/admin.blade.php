<!doctype html>
<html lang="fr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function () {
            var themeKey = 'anbg-theme';
            var root = document.documentElement;
            var theme = 'dark';

            try {
                var savedTheme = localStorage.getItem(themeKey);
                if (savedTheme === 'light' || savedTheme === 'dark') {
                    theme = savedTheme;
                }
            } catch (error) {
                theme = 'dark';
            }

            root.classList.toggle('dark', theme === 'dark');
            root.setAttribute('data-theme', theme);
        })();
    </script>
    <title>@yield('title', 'Dashboard') - ANBG</title>
    @include('partials.vite-assets')
    @stack('head')
</head>

@php
    $layoutUser = auth()->user();
    $headerNotifications = collect();
    $headerUnreadCount = 0;
    $headerUnreadByModule = [];

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
    }
@endphp

<body class="admin-theme-scope h-full bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="min-h-screen">
        <div id="admin-overlay" class="fixed inset-0 z-40 hidden bg-black/40 lg:hidden"></div>

        <x-admin.sidebar :notification-counts="$headerUnreadByModule" :unread-total="$headerUnreadCount" />

        <div class="lg:pl-72">
            <header class="sticky top-0 z-30 border-b border-sky-100/80 bg-[linear-gradient(135deg,rgba(255,255,255,0.95)_0%,rgba(242,250,255,0.92)_100%)] backdrop-blur dark:border-slate-800 dark:bg-slate-950/60">
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

                    <div class="flex-1">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Administration</p>
                        <h1 class="text-base font-semibold leading-tight sm:text-lg">
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
                                class="w-full rounded-xl border border-sky-100 bg-[linear-gradient(135deg,rgba(255,255,255,0.98)_0%,rgba(243,250,255,0.95)_100%)] px-9 py-2 text-sm outline-none focus:ring-2 focus:ring-sky-400/35 dark:border-slate-800 dark:bg-slate-900"
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
                            @if ($headerUnreadCount > 0)
                                <span class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold leading-none text-white">
                                    {{ $headerUnreadCount > 99 ? '99+' : $headerUnreadCount }}
                                </span>
                            @endif
                        </button>

                        <div
                            id="header-notifications-menu"
                            class="absolute right-0 z-40 mt-2 hidden w-[340px] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-950"
                        >
                            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                                <div>
                                    <p class="text-sm font-semibold">Notifications</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $headerUnreadCount }} non lue(s)</p>
                                </div>
                                <form method="POST" action="{{ route('workspace.notifications.read_all') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="rounded-lg px-2 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-slate-900"
                                    >
                                        Tout marquer lu
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
                                        class="block border-b border-slate-100 px-3 py-2 transition last:border-b-0 hover:bg-slate-50 dark:border-slate-900 dark:hover:bg-slate-900/60 {{ $isUnread ? 'bg-indigo-50/70 dark:bg-indigo-950/20' : '' }}"
                                    >
                                        <div class="mb-1 flex items-start justify-between gap-2">
                                            <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                                {{ $notification->data['title'] ?? 'Notification' }}
                                            </p>
                                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-700 dark:bg-slate-800 dark:text-slate-200">
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
                        <div class="hidden text-right sm:block">
                            <p class="text-sm font-medium">{{ auth()->user()?->name ?? 'Utilisateur' }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()?->roleLabel() ?? 'Compte' }}</p>
                        </div>
                        @if (auth()->user()?->profile_photo_url)
                            <img src="{{ auth()->user()->profile_photo_url }}" alt="Avatar" class="h-9 w-9 rounded-2xl object-cover">
                        @else
                            <div class="flex h-9 w-9 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 text-xs font-bold text-white">
                                {{ auth()->user()?->profile_initials ?? 'U' }}
                            </div>
                        @endif
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8">
                @if (session('success'))
                    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-green-700 shadow-sm">{{ session('success') }}</div>
                @endif
                @if ($errors->any())
                    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-red-700 shadow-sm">{{ $errors->first() }}</div>
                @endif
                @yield('content')
            </main>
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
            var notificationsWrapper = document.getElementById('header-notifications');
            var notificationsToggle = document.getElementById('header-notifications-toggle');
            var notificationsMenu = document.getElementById('header-notifications-menu');

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

            function openNotificationsMenu() {
                if (!notificationsMenu) {
                    return;
                }
                notificationsMenu.classList.remove('hidden');
            }

            function closeNotificationsMenu() {
                if (!notificationsMenu) {
                    return;
                }
                notificationsMenu.classList.add('hidden');
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

            document.addEventListener('click', function (event) {
                if (!notificationsWrapper || !notificationsMenu) {
                    return;
                }
                if (notificationsWrapper.contains(event.target)) {
                    return;
                }
                closeNotificationsMenu();
            });

            window.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSidebar();
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
            }

            syncSidebarForViewport();
            window.addEventListener('resize', syncSidebarForViewport);

        })();
    </script>
    @stack('scripts')
</body>
</html>
