@props([
    'notificationCounts' => [],
    'unreadTotal' => 0,
])

@php
    $user = auth()->user();
    $moduleBadges = is_array($notificationCounts) ? $notificationCounts : [];
    $workspaceModules = collect($user?->workspaceModules() ?? [])->keyBy('code');
    $isDafFinanceReviewer = $user
        && $user->hasRole(\App\Models\User::ROLE_DIRECTION)
        && $user->direction_id !== null
        && (string) ($user->direction?->code ?? '') === 'DAF';
    $canSeeModule = static fn (string $code): bool => $workspaceModules->has($code);
    $moduleLabel = static fn (string $code, string $fallback): string => (string) (($workspaceModules->get($code)['label'] ?? null) ?: $fallback);
    $moduleOrder = static fn (string $code, int $fallback = 999): int => (int) ($workspaceModules->get($code)['display_order'] ?? $fallback);

    $isActive = static function (array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    };

    $sortItems = static function (array $items): array {
        usort($items, static fn (array $left, array $right): int => ((int) ($left['display_order'] ?? 999)) <=> ((int) ($right['display_order'] ?? 999)));

        return $items;
    };

    $sections = [];

    $menuItems = [[
        'code' => 'pilotage',
        'label' => $moduleLabel('pilotage', 'Pilotage'),
        'route' => 'dashboard',
        'icon' => 'dashboard',
        'patterns' => ['dashboard', 'admin.dashboard'],
        'badge' => (int) ($moduleBadges['pilotage'] ?? 0),
        'display_order' => -100 + $moduleOrder('pilotage', 20),
    ]];

    $sections[] = ['title' => 'Menu', 'items' => $sortItems($menuItems)];

    $planningItems = [];
    if ($canSeeModule('pas')) {
        $planningItems[] = [
            'code' => 'pas',
            'label' => $moduleLabel('pas', 'PAS'),
            'route' => 'workspace.pas.index',
            'icon' => 'pas',
            'patterns' => ['workspace.pas.*'],
            'display_order' => $moduleOrder('pas', 40),
        ];
    }
    if ($canSeeModule('pao')) {
        $planningItems[] = [
            'code' => 'pao',
            'label' => $moduleLabel('pao', 'PAO'),
            'route' => 'workspace.pao.index',
            'icon' => 'pao',
            'patterns' => ['workspace.pao.*'],
            'badge' => (int) ($moduleBadges['pao'] ?? 0),
            'display_order' => $moduleOrder('pao', 50),
        ];
    }
    if ($canSeeModule('pta')) {
        $planningItems[] = [
            'code' => 'pta',
            'label' => $moduleLabel('pta', 'PTA'),
            'route' => 'workspace.pta.index',
            'icon' => 'pta',
            'patterns' => ['workspace.pta.*'],
            'badge' => (int) ($moduleBadges['pta'] ?? 0),
            'display_order' => $moduleOrder('pta', 60),
        ];
    }
    if ($planningItems !== []) {
        $sections[] = ['title' => 'Planification', 'items' => $sortItems($planningItems)];
    }

    if ($canSeeModule('execution')) {
        $executionItems = [[
            'code' => 'actions',
            'module_code' => 'execution',
            'label' => $moduleLabel('execution', 'Actions'),
            'route' => 'workspace.actions.index',
            'icon' => 'actions',
            'patterns' => ['workspace.actions.*'],
            'active_when' => static fn (): bool => request()->routeIs('workspace.actions.*'),
            'badge' => (int) ($moduleBadges['actions'] ?? 0),
            'display_order' => $moduleOrder('execution', 70),
        ]];

        if ($isDafFinanceReviewer || ($user?->hasRole(\App\Models\User::ROLE_DG) ?? false) || ($user?->hasGlobalReadAccess() ?? false)) {
            $executionItems[] = [
                'code' => 'financements_daf',
                'module_code' => 'execution',
                'label' => 'Financements DAF',
                'route' => 'workspace.daf.financements.index',
                'icon' => 'financement',
                'patterns' => ['workspace.daf.financements.*'],
                'display_order' => $moduleOrder('execution', 71),
            ];
        }

        $sections[] = ['title' => 'Exécution', 'items' => $sortItems($executionItems)];
    }

    $pilotageItems = [];
    if ($canSeeModule('reporting')) {
        $pilotageItems[] = [
            'code' => 'reporting',
            'label' => $moduleLabel('reporting', 'Reporting'),
            'route' => 'workspace.reporting',
            'icon' => 'reporting',
            'patterns' => ['workspace.reporting', 'workspace.reporting.*'],
            'display_order' => $moduleOrder('reporting', 70),
        ];
    }
    if ($canSeeModule('alertes')) {
        $pilotageItems[] = [
            'code' => 'alertes',
            'label' => $moduleLabel('alertes', 'Alertes'),
            'route' => 'workspace.alertes',
            'icon' => 'alertes',
            'patterns' => ['workspace.alertes', 'workspace.alertes.*'],
            'badge' => (int) ($moduleBadges['alertes'] ?? 0),
            'display_order' => $moduleOrder('alertes', 75),
        ];
    }
    if ($pilotageItems !== []) {
        $sections[] = ['title' => 'Pilotage', 'items' => $sortItems($pilotageItems)];
    }

    $toolItems = [];
    if ($canSeeModule('referentiel')) {
        $toolItems[] = [
            'code' => 'referentiel',
            'label' => $moduleLabel('referentiel', 'Référentiels'),
            'route' => 'workspace.referentiel.directions.index',
            'icon' => 'referentiel',
            'patterns' => ['workspace.referentiel.*'],
            'display_order' => $moduleOrder('referentiel', 80),
        ];
    }
    if ($canSeeModule('delegations')) {
        $toolItems[] = [
            'code' => 'delegations',
            'label' => $moduleLabel('delegations', 'Délégations'),
            'route' => 'workspace.delegations.index',
            'icon' => 'delegations',
            'patterns' => ['workspace.delegations.*'],
            'display_order' => $moduleOrder('delegations', 90),
        ];
    }
    if ($canSeeModule('retention')) {
        $toolItems[] = [
            'code' => 'retention',
            'label' => $moduleLabel('retention', 'Rétention'),
            'route' => 'workspace.retention.index',
            'icon' => 'retention',
            'patterns' => ['workspace.retention.*'],
            'display_order' => $moduleOrder('retention', 100),
        ];
    }
    if ($canSeeModule('api_docs')) {
        $toolItems[] = [
            'code' => 'api_docs',
            'label' => $moduleLabel('api_docs', 'API Docs'),
            'route' => 'workspace.api-docs.index',
            'icon' => 'docs',
            'patterns' => ['workspace.api-docs.*'],
            'display_order' => $moduleOrder('api_docs', 110),
        ];
    }
    if ($canSeeModule('audit')) {
        $toolItems[] = [
            'code' => 'audit',
            'label' => $moduleLabel('audit', 'Audit'),
            'route' => 'workspace.audit.index',
            'icon' => 'audit',
            'patterns' => ['workspace.audit.*'],
            'display_order' => $moduleOrder('audit', 120),
        ];
    }
    if ($toolItems !== []) {
        $sections[] = ['title' => 'Administration', 'items' => $sortItems($toolItems)];
    }

    if ($canSeeModule('super_admin')) {
        $sections[] = ['title' => 'Plateforme', 'items' => [[
            'code' => 'super_admin',
            'label' => $moduleLabel('super_admin', 'Super Admin'),
            'route' => 'workspace.super-admin.index',
            'icon' => 'super_admin',
            'patterns' => ['workspace.super-admin.*'],
            'display_order' => $moduleOrder('super_admin', 130),
        ]]];
    }

    $icons = [
        'dashboard' => 'M3 12l9-7 9 7v8a2 2 0 01-2 2h-4v-7H9v7H5a2 2 0 01-2-2v-8z',
        'pas' => 'M12 3v4m0 10v4m9-9h-4M7 12H3m13.364-5.364l-2.828 2.828M9.464 14.536l-2.828 2.828m0-10.728l2.828 2.828m7.072 7.072l-2.828-2.828',
        'pao' => 'M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
        'pta' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
        'actions' => 'M13 10V3L4 14h7v7l9-11h-7z',
        'financement' => 'M12 8c-2.8 0-5 1.2-5 3s2.2 3 5 3 5-1.2 5-3-2.2-3-5-3zm0 6v4m-6-4v3m12-3v3M5 20h14M12 4v2',
        'reporting' => 'M4 19V5m0 14h16M8 16V9m4 7V7m4 9v-5',
        'alertes' => 'M12 9v4m0 4h.01M10.29 3.86 2.82 17a2 2 0 001.71 3h14.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z',
        'referentiel' => 'M10.325 4.317a1.724 1.724 0 013.35 0 1.724 1.724 0 002.573 1.066 1.724 1.724 0 012.37 2.37 1.724 1.724 0 001.065 2.572 1.724 1.724 0 010 3.35 1.724 1.724 0 00-1.066 2.573 1.724 1.724 0 01-2.37 2.37 1.724 1.724 0 00-2.572 1.065 1.724 1.724 0 01-3.35 0 1.724 1.724 0 00-2.573-1.066 1.724 1.724 0 01-2.37-2.37 1.724 1.724 0 00-1.065-2.572 1.724 1.724 0 010-3.35 1.724 1.724 0 001.066-2.573 1.724 1.724 0 012.37-2.37 1.724 1.724 0 002.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'delegations' => 'M12 4l7 4-7 4-7-4 7-4zm0 8l7 4-7 4-7-4 7-4z',
        'retention' => 'M7 3h8l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm8 1v5h5',
        'docs' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'audit' => 'M12 3l7 3v6c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V6l7-3z',
        'super_admin' => 'M12 2l2.4 5 5.6.8-4 3.9.95 5.6L12 15.8 7.05 18.3 8 12.7 4 8.8l5.6-.8L12 2z',
        'logout' => 'M17 16l4-4m0 0l-4-4m4 4H9m4 4v1a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h6a2 2 0 012 2v1',
    ];
@endphp

<aside id="admin-sidebar" class="app-sidebar -translate-x-full transition-transform duration-300 ease-out lg:translate-x-0">
    <div class="app-sidebar-panel flex h-full min-h-0 overflow-hidden">
        <div class="flex min-w-0 flex-1 flex-col">
            <div class="app-sidebar-header">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('dashboard') }}" class="app-sidebar-logo flex-1" aria-label="Retour au dashboard">
                        <img
                            src="{{ asset('images/logo-anbg.png') }}"
                            alt="Logo ANBG"
                            class="app-sidebar-logo-image app-sidebar-logo-full"
                        >
                        <img
                            src="{{ asset('images/logo-anbg-flamme.png') }}"
                            alt="Logo ANBG"
                            class="app-sidebar-logo-image app-sidebar-logo-flame"
                        >
                    </a>

                    <button
                        type="button"
                        class="app-sidebar-collapse-toggle"
                        data-sidebar-collapse-toggle
                        aria-label="Réduire ou agrandir le menu"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>

                    <button
                        type="button"
                        id="admin-sidebar-close"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/30 text-white transition hover:bg-white/15 lg:hidden"
                        aria-label="Fermer le menu"
                    >
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <nav class="app-sidebar-menu" aria-label="Navigation principale">
                @foreach ($sections as $section)
                    <section class="mb-5 last:mb-0">
                        <p class="app-sidebar-section-title">{{ $section['title'] }}</p>

                        <div class="space-y-1.5">
                            @foreach ($section['items'] as $item)
                                @php
                                    $active = is_callable($item['active_when'] ?? null)
                                        ? (bool) $item['active_when']()
                                        : $isActive($item['patterns'] ?? []);
                                    $badgeCount = (int) ($item['badge'] ?? 0);
                                    $moduleCode = (string) ($item['module_code'] ?? $item['code'] ?? '');
                                    $iconPath = $icons[$item['icon']] ?? $icons['dashboard'];
                                    $routeParams = is_array($item['route_params'] ?? null) ? $item['route_params'] : [];
                                @endphp

                                <a
                                    href="{{ route($item['route'], $routeParams) }}"
                                    class="app-sidebar-link {{ $active ? 'active is-active' : '' }}"
                                    data-sidebar-module="{{ $moduleCode }}"
                                    @if ($active) aria-current="page" @endif
                                >
                                    <span class="app-sidebar-link-icon">
                                        <svg class="h-[1.1rem] w-[1.1rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $iconPath }}" />
                                        </svg>
                                    </span>
                                    <span class="min-w-0 flex-1 truncate" data-sidebar-label>{{ $item['label'] }}</span>
                                    @if ($badgeCount > 0)
                                        <span class="app-sidebar-notification-dot" aria-hidden="true"></span>
                                        <span class="app-sidebar-badge inline-flex min-w-[1.55rem] items-center justify-center px-1.5 py-1 text-[10px] leading-none" data-sidebar-badge-for="{{ $moduleCode }}">{{ $badgeCount > 99 ? '99+' : $badgeCount }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </nav>

            <div class="app-sidebar-footer px-4 py-4">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="app-sidebar-logout text-sm transition">
                        <svg class="h-4.5 w-4.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $icons['logout'] }}" />
                        </svg>
                        <span data-sidebar-label>Déconnexion</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
