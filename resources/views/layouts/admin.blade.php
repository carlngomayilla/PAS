<!doctype html>
<html lang="{{ $platformSettings->htmlLang() }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1c203d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ANBG PAS">
    <link rel="manifest" href="/site.webmanifest">
    @include('partials.app-icons')
    <script @cspNonce>
        (function () {
            var root = document.documentElement;
            var savedTheme = 'light';
            try { savedTheme = window.localStorage.getItem('theme') || 'light'; } catch (e) {}
            if (savedTheme === 'dark') {
                root.classList.add('dark');
            } else {
                root.classList.remove('dark');
            }
            root.setAttribute('data-theme', savedTheme);
            try {
                if (window.localStorage.getItem('anbg:sidebar:collapsed') === '1') {
                    document.addEventListener('DOMContentLoaded', function () {
                        document.body.classList.add('sidebar-collapsed');
                    }, { once: true });
                }
            } catch (error) {
                // no-op: sidebar preference is optional
            }
        })();
    </script>
    <title>@yield('title', 'Dashboard') - {{ $platformSettings->get('app_short_name', 'ANBG') }}</title>
    @stack('head')
    @include('partials.vite-assets')
    <style>
        :root { --app-sidebar-width: 232px; --app-sidebar-collapsed-width: 72px; }

        /* ── Fond de page ── */
        .admin-page-root { background: var(--app-bg, #f6fbff); }

        /* ── Shell frame — carte flottante ── */
        .admin-shell-frame { background: transparent; border-radius: 0; }

        /* ── Header ── */
        .admin-page-header {
            background: var(--app-surface, #ffffff);
            border-bottom: 1px solid var(--app-border, #d8ecf8);
            backdrop-filter: none;
        }

        /* ── Contenu principal ── */
        .admin-content-shell {
            padding-left: 0;
            min-height: 100vh;
        }
        @media (min-width: 1024px) {
            .admin-content-shell {
                padding-left: var(--app-sidebar-width);
                transition: padding-left 220ms ease;
            }

            body.sidebar-collapsed .admin-content-shell {
                padding-left: var(--app-sidebar-collapsed-width);
            }
        }

        body.admin-theme-scope::before { display: none !important; }
    </style>
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
    $exerciseContext = app(\App\Services\ExerciceContext::class);
    $exerciseOptions = $exerciseContext->options();
    $quarterOptions = $exerciseContext->quarterOptions();
    $selectedExercise = $exerciseContext->selectedYear();
    $selectedQuarter = $exerciseContext->selectedQuarter();
    $exerciseHiddenQuery = collect(request()->query())->except(['exercice', 'trimestre', 'page']);

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

        if ($layoutUser->hasPermission('alerts.read')) {
            $alertReadService = app(\App\Services\Alerting\AlertReadService::class);
            $alertCenterService = app(\App\Services\Alerting\AlertCenterService::class);
            $headerAlertSummary = $alertCenterService->summaryForUser(
                $layoutUser,
                $alertReadService->readFingerprintsForUser($layoutUser)
            );
            $headerAlertUnreadCount = (int) ($headerAlertSummary['unread'] ?? 0);
            $headerSidebarBadges['alertes'] = $headerAlertUnreadCount;
        }

        // Count actions pending validation (for managers) and returned actions (for agents)
        $validationBadgeCount = 0;
        if (\Illuminate\Support\Facades\Schema::hasTable('actions')) {
            $isGlobalReader = $layoutUser->hasGlobalReadAccess()
                || $layoutUser->hasRole(\App\Models\User::ROLE_SUPER_ADMIN)
                || $layoutUser->hasRole(\App\Models\User::ROLE_DG)
                || $layoutUser->hasRole(\App\Models\User::ROLE_PLANIFICATION)
                || $layoutUser->hasRole(\App\Models\User::ROLE_CABINET);
            $pendingQ = \App\Models\Action::query()
                ->where('statut_validation', \App\Services\Actions\ActionTrackingService::VALIDATION_SOUMISE_CHEF);
            if (! $isGlobalReader) {
                if ($layoutUser->hasRole(\App\Models\User::ROLE_DIRECTION) && $layoutUser->direction_id) {
                    $pendingQ->whereHas('pta', fn ($q) => $q->where('direction_id', $layoutUser->direction_id));
                } elseif ($layoutUser->hasRole(\App\Models\User::ROLE_SERVICE) && $layoutUser->service_id) {
                    $pendingQ->whereHas('pta', fn ($q) => $q->where('service_id', $layoutUser->service_id));
                } else {
                    $pendingQ->whereRaw('0 = 1');
                }
            }
            $validationBadgeCount += (int) $pendingQ->count();
            $returnedCount = \App\Models\Action::query()
                ->whereIn('statut_validation', [
                    \App\Services\Actions\ActionTrackingService::VALIDATION_REJETEE_CHEF,
                    \App\Services\Actions\ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
                ])
                ->where(static fn ($q) => $q
                    ->where('responsable_id', $layoutUser->id)
                    ->orWhereHas('responsables', fn ($q2) => $q2->where('users.id', $layoutUser->id))
                )
                ->count();
            $validationBadgeCount += (int) $returnedCount;
            if ($validationBadgeCount > 0) {
                $headerSidebarBadges['actions'] = (int) ($headerSidebarBadges['actions'] ?? 0) + $validationBadgeCount;
            }
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

<body class="admin-theme-scope h-full" data-auto-refresh="20" data-alert-unread="{{ (int) $headerAlertUnreadCount }}" data-message-unread="{{ (int) $headerMessageUnreadCount }}">
    <a href="#admin-main-content" class="skip-to-content">Aller au contenu principal</a>
    <div class="admin-page-root app-shell min-h-screen">
        <div id="admin-overlay" class="fixed inset-0 z-40 hidden bg-white/70 backdrop-blur-sm lg:hidden"></div>

        <x-admin.sidebar :notification-counts="$headerSidebarBadges" :unread-total="$headerUnreadCount" />

        <div class="admin-content-shell app-main min-h-screen">
            <div class="admin-shell-frame flex min-h-screen flex-col overflow-hidden">
                <header id="admin-shell-header" class="admin-page-header app-navbar sticky top-0 z-30">
                    <div class="admin-navbar-inner flex min-h-[5rem] items-center gap-3 px-5 py-4 sm:px-6 lg:px-8">
                        <button
                            type="button"
                            id="admin-sidebar-open"
                            class="admin-navbar-icon-button inline-flex items-center justify-center lg:hidden"
                            aria-label="Ouvrir le menu"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>

                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <h1 class="admin-navbar-title truncate text-xl font-bold leading-tight text-[#17324a]">
                                    @yield('title', 'Dashboard')
                                </h1>
                                <p class="starline-greeting">{{ $platformSettings->get('admin_header_eyebrow', 'Pilotage institutionnel ANBG') }}</p>
                            </div>
                        </div>

<form method="GET" action="{{ url()->current() }}" class="admin-exercise-filter flex shrink-0 items-center gap-1 px-2 py-1.5 text-[11px]">
                            @foreach ($exerciseHiddenQuery as $queryKey => $queryValue)
                                @if (is_array($queryValue))
                                    @foreach ($queryValue as $itemValue)
                                        <input type="hidden" name="{{ $queryKey }}[]" value="{{ $itemValue }}">
                                    @endforeach
                                @elseif (! is_null($queryValue))
                                    <input type="hidden" name="{{ $queryKey }}" value="{{ $queryValue }}">
                                @endif
                            @endforeach
                            <select
                                name="exercice"
                                class="w-[86px] border-0 bg-transparent px-1 py-1 font-semibold text-[#17324a] outline-none ring-0"
                                onchange="this.form.submit()"
                                aria-label="Filtrer par exercice"
                            >
                                @foreach ($exerciseOptions as $exerciseOption)
                                    <option value="{{ $exerciseOption['value'] }}" @selected((string) ($selectedExercise ?? 'all') === $exerciseOption['value'])>
                                        {{ $exerciseOption['value'] === 'all' ? 'Tous' : $exerciseOption['value'] }}
                                    </option>
                                @endforeach
                            </select>
                            <select
                                name="trimestre"
                                class="w-[74px] border-0 bg-transparent px-1 py-1 font-semibold text-[#17324a] outline-none ring-0"
                                onchange="this.form.submit()"
                                aria-label="Filtrer par trimestre"
                            >
                                @foreach ($quarterOptions as $quarterOption)
                                    <option value="{{ $quarterOption['value'] }}" @selected((string) ($selectedQuarter ?? 'all') === $quarterOption['value'])>
                                        {{ $quarterOption['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </form>

                        <div class="admin-local-clock hidden lg:inline-flex" id="admin-local-clock" aria-label="Heure locale">
                            --:--:--
                        </div>

                        <button
                            type="button"
                            id="admin-notif-toggle"
                            class="admin-navbar-icon-button inline-flex items-center justify-center"
                            title="Activer les notifications navigateur"
                            aria-label="Notifications navigateur"
                        >
                            {{-- Bell (default / granted) --}}
                            <svg id="notif-icon-bell" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            {{-- Bell-slash (denied) --}}
                            <svg id="notif-icon-denied" class="hidden h-5 w-5 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.172 9.172a4 4 0 015.656 0M3 3l18 18M10.343 10.343A4 4 0 006 14v3.159c0 .538-.214 1.055-.595 1.436L4 20h12.586M15 17v1a3 3 0 01-5.196 1.755M15 17H9" />
                            </svg>
                        </button>

                        <button
                            type="button"
                            id="admin-theme-toggle"
                            class="admin-navbar-icon-button inline-flex items-center justify-center"
                            title="Changer le thème"
                            aria-label="Changer le thème"
                        >
                            <svg class="h-5 w-5 dark:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21.752 15.002A9.718 9.718 0 0 1 18 15.75 9.75 9.75 0 0 1 8.25 6c0-1.33.266-2.597.748-3.752A9.753 9.753 0 1 0 21.752 15.002Z" />
                            </svg>
                            <svg class="hidden h-5 w-5 dark:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v2.25m0 13.5V21m9-9h-2.25M5.25 12H3m15.364-6.364-1.591 1.591M7.227 16.773l-1.591 1.591m12.728 0-1.591-1.591M7.227 7.227 5.636 5.636M12 8.25A3.75 3.75 0 1 1 12 15.75a3.75 3.75 0 0 1 0-7.5Z" />
                            </svg>
                        </button>

                        <div class="relative" id="header-messaging">
                        <button
                            type="button"
                            id="header-messaging-toggle"
                            class="admin-navbar-icon-button relative inline-flex items-center justify-center"
                            aria-label="Messagerie"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8m-8 4h5m-9 5l1.2-3.6A8 8 0 014 12V7a3 3 0 013-3h10a3 3 0 013 3v5a3 3 0 01-3 3H9l-4 4z" />
                            </svg>
                            <span
                                id="header-messaging-badge"
                                class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#3996d3] px-1 text-[10px] font-semibold leading-none text-white {{ $headerMessageUnreadCount > 0 ? '' : 'hidden' }}"
                            >
                                {{ $headerMessageUnreadCount > 99 ? '99+' : $headerMessageUnreadCount }}
                            </span>
                        </button>

                        <div
                            id="header-messaging-menu"
                            data-messages-endpoint="{{ route('workspace.messaging.dropdown') }}"
                            class="admin-dropdown-panel admin-navbar-dropdown admin-navbar-messaging-dropdown absolute right-0 z-40 mt-2 hidden w-[360px] overflow-hidden border border-[#d8ecf8] bg-white"
                        >
                            <div class="admin-dropdown-head flex items-center justify-between border-b border-[#d8ecf8] px-3 py-2">
                                <div>
                                    <p class="text-sm font-semibold">Messagerie</p>
                                    <p class="text-xs text-[#667085]">{{ $headerMessageUnreadCount }}</p>
                                </div>
                                <a
                                    href="{{ route('workspace.messaging.index') }}"
                                    class="admin-dropdown-link border border-[#d8ecf8] px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#eaf6fd]"
                                >
                                    Ouvrir
                                </a>
                            </div>

                            <div class="admin-dropdown-shortcuts border-b border-[#d8ecf8] px-3 py-2">
                                <div class="grid grid-cols-2 gap-2 text-xs">
                                    <a href="{{ route('workspace.messaging.index') }}" class="admin-dropdown-shortcut border border-[#d8ecf8] px-3 py-2 font-medium text-[#17324a] transition hover:bg-[#eaf6fd]">
                                        Messages récents
                                    </a>
                                    <a href="{{ route('workspace.messaging.index') }}#messaging-orgchart" class="admin-dropdown-shortcut border border-[#d8ecf8] px-3 py-2 font-medium text-[#17324a] transition hover:bg-[#eaf6fd]">
                                        Organigramme
                                    </a>
                                </div>
                            </div>

                            <div class="admin-dropdown-scroll max-h-96 overflow-y-auto">
                                @forelse ($headerMessages as $thread)
                                    @php
                                        $threadUser = $thread->getAttribute('other_user');
                                    @endphp
                                    <a
                                        href="{{ route('workspace.messaging.index', ['conversation' => $thread->id]) }}"
                                        class="admin-dropdown-item admin-message-item block border-b border-[#d8ecf8] px-3 py-2 transition last:border-b-0 hover:bg-[#eaf6fd] {{ (int) $thread->getAttribute('unread_messages_count') > 0 ? 'bg-[#eaf6fd]' : '' }}"
                                    >
                                        <div class="mb-1 flex items-start justify-between gap-2">
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-[#17324a]">
                                                    {{ $thread->getAttribute('display_name') }}
                                                </p>
                                                <p class="truncate text-[11px] text-[#667085]">
                                                    {{ $threadUser?->agent_fonction ?: $thread->getAttribute('display_scope') }}
                                                </p>
                                            </div>
                                            @if ((int) $thread->getAttribute('unread_messages_count') > 0)
                                                <span class="app-badge app-badge-warning px-2 py-0.5 text-[10px]">
                                                    {{ $thread->getAttribute('unread_messages_count') }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="truncate text-xs text-[#667085]">
                                            {{ $thread->latestMessage?->body ?: ($thread->latestMessage?->attachment_original_name ?: 'Conversation ouverte.') }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-[#667085]">
                                            {{ $thread->latestMessage?->sent_at?->diffForHumans() ?? 'Nouveau' }}
                                        </p>
                                    </a>
                                @empty
                                    <div class="admin-dropdown-empty px-3 py-6 text-center text-sm text-[#667085]">
                                        Aucune conversation récente.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                        <div class="relative" id="header-notifications">
                        <button
                            type="button"
                            id="header-notifications-toggle"
                            class="admin-navbar-icon-button relative inline-flex items-center justify-center"
                            aria-label="Notifications"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .53-.21 1.04-.59 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span
                                id="header-notifications-badge"
                                class="absolute -right-0.5 -top-0.5 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-[#3996d3] px-1 text-[10px] font-semibold leading-none text-white {{ $headerAlertUnreadCount > 0 ? '' : 'hidden' }}"
                            >
                                {{ $headerAlertUnreadCount > 99 ? '99+' : $headerAlertUnreadCount }}
                            </span>
                        </button>

                        <div
                            id="header-notifications-menu"
                            data-alerts-endpoint="{{ route('workspace.alertes.dropdown') }}"
                            class="admin-dropdown-panel admin-navbar-dropdown admin-navbar-notifications-dropdown absolute right-0 z-40 mt-2 hidden w-[340px] overflow-hidden border border-[#d8ecf8] bg-white"
                        >
                            <div class="admin-dropdown-head admin-dropdown-alert-head border-b border-[#d8ecf8] px-3 py-2">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <p class="text-sm font-semibold">Alertes</p>
                                        <p id="header-alerts-summary" class="text-xs text-[#667085]">
                                            {{ $headerAlertSummary['unread'] ?? 0 }} non lue(s) sur {{ $headerAlertSummary['total'] ?? 0 }} alerte(s).
                                        </p>
                                    </div>
                                    <a
                                        href="{{ route('workspace.alertes') }}"
                                        class="admin-dropdown-link border border-[#d8ecf8] px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#eaf6fd]"
                                    >
                                        Centre
                                    </a>
                                </div>
                                <div id="header-alerts-kpi-summary" class="admin-dropdown-kpi-summary mt-2 hidden flex flex-wrap gap-2"></div>
                            </div>

                            <div id="header-alerts-items" class="admin-dropdown-alerts border-b border-[#d8ecf8]">
                                <div class="admin-dropdown-empty px-3 py-4 text-xs text-[#667085]">
                                    Les alertes récentes s'affichent ici.
                                </div>
                            </div>

                            <div class="admin-dropdown-head flex items-center justify-between border-b border-[#d8ecf8] px-3 py-2">
                                <div>
                                    <p class="text-sm font-semibold">Notifications</p>
                                    <p class="text-xs text-[#667085]">{{ $headerUnreadCount }} non lue(s)</p>
                                </div>
                                <form method="POST" action="{{ route('workspace.notifications.read_all') }}">
                                    @csrf
                                    <button
                                        type="submit"
                                        class="admin-dropdown-link border border-[#d8ecf8] px-2 py-1 text-xs font-medium text-[#3996d3] hover:bg-[#eaf6fd]"
                                    >
                                        Tout marquer comme lu
                                    </button>
                                </form>
                            </div>

                            <div class="admin-dropdown-scroll max-h-96 overflow-y-auto">
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
                                        class="admin-dropdown-item admin-notification-item block border-b border-[#d8ecf8] px-3 py-2 transition last:border-b-0 hover:bg-[#eaf6fd] {{ $isUnread ? 'bg-[#eaf6fd]' : '' }}"
                                    >
                                        <div class="mb-1 flex items-start justify-between gap-2">
                                            <p class="text-sm font-semibold text-[#17324a]">
                                                {{ $notification->data['title'] ?? 'Notification' }}
                                            </p>
                                            <span class="app-badge px-2 py-0.5 text-[10px] uppercase tracking-wide leading-none">
                                                {{ $moduleLabel }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-[#667085]">
                                            {{ $notification->data['message'] ?? '' }}
                                        </p>
                                        <p class="mt-1 text-[11px] text-[#667085]">
                                            {{ $notification->created_at?->diffForHumans() }}
                                        </p>
                                    </a>
                                @empty
                                    <div class="admin-dropdown-empty px-3 py-6 text-center text-sm text-[#667085]">
                                        Aucune notification.
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                        <div class="admin-navbar-user flex items-center gap-3 px-3 py-2">
                            <a href="{{ route('workspace.profile.edit') }}" class="admin-navbar-user-copy text-right transition-opacity hover:opacity-75">
                                <p class="text-sm font-semibold text-[#17324a]">{{ auth()->user()?->name ?? 'Utilisateur' }}</p>
                                <p class="text-xs text-[#667085]">{{ auth()->user()?->roleLabel() ?? 'Compte' }}</p>
                            </a>
                            <a href="{{ route('workspace.profile.edit') }}" class="admin-navbar-avatar inline-flex items-center justify-center transition-opacity hover:opacity-75">
                                @if (auth()->user()?->profile_photo_url)
                                    <img src="{{ auth()->user()->profile_photo_url }}" alt="Avatar" class="admin-navbar-avatar-media h-10 w-10 rounded-[1rem] object-cover">
                                @else
                                    <div class="admin-navbar-avatar-media flex h-10 w-10 items-center justify-center rounded-[1rem] bg-[#3996d3] text-xs font-bold text-white">
                                        {{ auth()->user()?->profile_initials ?? 'U' }}
                                    </div>
                                @endif
                            </a>
                        </div>
                    </div>
                </header>

                <main id="admin-main-content" data-auto-refresh-region class="app-content min-h-[calc(100vh-5rem)] w-full flex-1">
                    @if (session('success'))
                        <div class="flash-success">
                            {{ session('success') }}
                            <button type="button" class="flash-dismiss" aria-label="Fermer">x</button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div class="flash-error">
                            {{ $errors->first() }}
                            <button type="button" class="flash-dismiss" aria-label="Fermer">x</button>
                        </div>
                    @endif
                    @stack('breadcrumb')
                    @yield('content')
                    <footer class="mt-8 border-t border-[#d8ecf8] pt-4 text-xs text-[#667085]">
                        {{ $platformSettings->get('footer_text', 'ANBG | Système institutionnel de pilotage PAS / PAO / PTA') }}
                    </footer>
                </main>
            </div>
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
                    <label id="anbg-dialog-input-label" for="anbg-dialog-input" class="app-label mb-1 block">Valeur</label>
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
                    <p class="anbg-dialog-eyebrow">Analyse détaillée</p>
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
                <span class="text-xs font-medium text-[#667085]">Selection active</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="analytics-explorer-download" class="app-btn app-btn-primary hidden">Telecharger</button>
                    <button type="button" class="app-btn app-btn-secondary" data-analytics-explorer-dismiss>Fermer</button>
                </div>
            </div>
        </div>
    </div>

    {{-- admin-shell.js is bundled via app.js (resources/js/admin-shell.js) --}}
    @stack('scripts')
    <script @cspNonce>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js', { scope: '/' });
        }
    </script>

    {{-- ── SPOTLIGHT SEARCH MODAL ────────────────────────────────────────── --}}
    <div id="spotlight-backdrop" class="spotlight-backdrop" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Recherche globale">
        <div class="spotlight-panel" role="search">
            <div class="spotlight-input-row">
                <svg class="spotlight-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input id="spotlight-input" type="search" class="spotlight-input" placeholder="Rechercher actions, PTA, utilisateurs…" autocomplete="off" spellcheck="false" aria-label="Recherche globale">
                <kbd class="spotlight-esc-hint" title="Fermer">Échap</kbd>
            </div>
            <div id="spotlight-results" class="spotlight-results" role="listbox" aria-label="Résultats de recherche"></div>
            <div class="spotlight-footer">
                <span><kbd>↑</kbd><kbd>↓</kbd> naviguer</span>
                <span><kbd>↵</kbd> ouvrir</span>
                <span><kbd>Échap</kbd> fermer</span>
                <a href="{{ route('workspace.search') }}" class="spotlight-full-search">Recherche complète →</a>
            </div>
        </div>
    </div>
</body>
</html>
