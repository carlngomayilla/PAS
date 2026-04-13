@props([
    'notificationCounts' => [],
    'unreadTotal' => 0,
])

@php
    $user = auth()->user();
    $moduleBadges = is_array($notificationCounts) ? $notificationCounts : [];
    $totalUnread = (int) $unreadTotal;
    $workspaceModules = collect($user?->workspaceModules() ?? [])->keyBy('code');
    $canSeeModule = static fn (string $code): bool => $workspaceModules->has($code);
    $moduleLabel = static fn (string $code, string $fallback): string => (string) (($workspaceModules->get($code)['label'] ?? null) ?: $fallback);
    $moduleOrder = static fn (string $code, int $fallback = 999): int => (int) ($workspaceModules->get($code)['display_order'] ?? $fallback);

    $sections = [];

    $pilotageItems = [
        [
            'code' => 'dashboard',
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'dashboard',
            'patterns' => ['dashboard', 'admin.dashboard'],
            'display_order' => -100,
        ],
    ];

    if ($canSeeModule('alertes')) {
        $pilotageItems[] = [
            'code' => 'alertes',
            'label' => $moduleLabel('alertes', 'Alertes'),
            'route' => 'workspace.alertes',
            'icon' => 'alert',
            'patterns' => ['workspace.alertes'],
            'badge' => (int) ($moduleBadges['alertes'] ?? 0),
            'display_order' => $moduleOrder('alertes', 20),
        ];
    }

    if ($canSeeModule('reporting')) {
        $pilotageItems[] = [
            'code' => 'reporting',
            'label' => $moduleLabel('reporting', 'Rapports'),
            'route' => 'workspace.reporting',
            'icon' => 'reporting',
            'patterns' => ['workspace.reporting'],
            'badge' => (int) ($moduleBadges['reporting'] ?? 0),
            'display_order' => $moduleOrder('reporting', 30),
        ];
    }

    if ($canSeeModule('pas') || $canSeeModule('pao') || $canSeeModule('pta') || $canSeeModule('execution')) {
        $pilotageItems[] = [
            'code' => 'pilotage',
            'label' => 'Pilotage',
            'route' => 'workspace.pilotage',
            'icon' => 'pilotage',
            'patterns' => ['workspace.pilotage'],
            'display_order' => 35,
        ];
    }

    usort($pilotageItems, static fn (array $left, array $right): int => ((int) ($left['display_order'] ?? 999)) <=> ((int) ($right['display_order'] ?? 999)));

    $sections[] = ['title' => 'Pilotage', 'items' => $pilotageItems];

    $planificationItems = [];

    if ($canSeeModule('pas')) {
        $planificationItems[] = [
            'code' => 'pas',
            'label' => $moduleLabel('pas', 'PAS'),
            'route' => 'workspace.pas.index',
            'icon' => 'pas',
            'patterns' => ['workspace.pas.*'],
            'display_order' => $moduleOrder('pas', 40),
        ];
    }

    if ($canSeeModule('pao')) {
        $planificationItems[] = [
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
        $planificationItems[] = [
            'code' => 'pta',
            'label' => $moduleLabel('pta', 'PTA'),
            'route' => 'workspace.pta.index',
            'icon' => 'pta',
            'patterns' => ['workspace.pta.*'],
            'badge' => (int) ($moduleBadges['pta'] ?? 0),
            'display_order' => $moduleOrder('pta', 60),
        ];
    }

    usort($planificationItems, static fn (array $left, array $right): int => ((int) ($left['display_order'] ?? 999)) <=> ((int) ($right['display_order'] ?? 999)));

    if ($planificationItems !== []) {
        $sections[] = ['title' => 'Planification', 'items' => $planificationItems];
    }

    $executionItems = [];

    if ($canSeeModule('execution')) {
        $executionItems[] = [
            'code' => 'actions',
            'module_code' => 'execution',
            'label' => $moduleLabel('execution', 'Actions'),
            'route' => 'workspace.actions.index',
            'icon' => 'actions',
            'patterns' => ['workspace.actions.*'],
            'active_when' => static fn (): bool => request()->routeIs('workspace.actions.*') && request('vue') !== 'mes_actions',
            'badge' => (int) ($moduleBadges['actions'] ?? 0),
            'display_order' => $moduleOrder('execution', 70),
        ];
        $executionItems[] = [
            'code' => 'mes_actions',
            'module_code' => 'execution',
            'label' => 'Mes actions',
            'route' => 'workspace.actions.index',
            'route_params' => ['vue' => 'mes_actions'],
            'icon' => 'user',
            'patterns' => ['workspace.actions.*'],
            'active_when' => static fn (): bool => request()->routeIs('workspace.actions.*') && request('vue') === 'mes_actions',
            'display_order' => $moduleOrder('execution', 70) + 1,
        ];
    }

    usort($executionItems, static fn (array $left, array $right): int => ((int) ($left['display_order'] ?? 999)) <=> ((int) ($right['display_order'] ?? 999)));

    if ($executionItems !== []) {
        $sections[] = ['title' => 'Execution', 'items' => $executionItems];
    }

    $gouvernanceItems = [];

    if ($canSeeModule('referentiel')) {
        $gouvernanceItems[] = [
            'code' => 'referentiel',
            'label' => $moduleLabel('referentiel', 'Refer.'),
            'route' => 'workspace.referentiel.directions.index',
            'icon' => 'referentiel',
            'patterns' => ['workspace.referentiel.*'],
            'display_order' => $moduleOrder('referentiel', 80),
        ];
    }

    if ($canSeeModule('delegations')) {
        $gouvernanceItems[] = [
            'code' => 'delegations',
            'label' => $moduleLabel('delegations', 'Deleg.'),
            'route' => 'workspace.delegations.index',
            'icon' => 'delegations',
            'patterns' => ['workspace.delegations.*'],
            'display_order' => $moduleOrder('delegations', 90),
        ];
    }

    if ($canSeeModule('retention')) {
        $gouvernanceItems[] = [
            'code' => 'retention',
            'label' => $moduleLabel('retention', 'Retention'),
            'route' => 'workspace.retention.index',
            'icon' => 'retention',
            'patterns' => ['workspace.retention.*'],
            'display_order' => $moduleOrder('retention', 100),
        ];
    }

    if ($canSeeModule('api_docs')) {
        $gouvernanceItems[] = [
            'code' => 'api_docs',
            'label' => $moduleLabel('api_docs', 'API Docs'),
            'route' => 'workspace.api-docs.index',
            'icon' => 'docs',
            'patterns' => ['workspace.api-docs.*'],
            'display_order' => $moduleOrder('api_docs', 110),
        ];
    }

    if ($canSeeModule('audit')) {
        $gouvernanceItems[] = [
            'code' => 'audit',
            'label' => $moduleLabel('audit', 'Audit'),
            'route' => 'workspace.audit.index',
            'icon' => 'audit',
            'patterns' => ['workspace.audit.*'],
            'display_order' => $moduleOrder('audit', 120),
        ];
    }

    usort($gouvernanceItems, static fn (array $left, array $right): int => ((int) ($left['display_order'] ?? 999)) <=> ((int) ($right['display_order'] ?? 999)));

    if ($gouvernanceItems !== []) {
        $sections[] = ['title' => 'Gouvernance', 'items' => $gouvernanceItems];
    }

    if ($canSeeModule('super_admin')) {
        $sections[] = [
            'title' => 'Plateforme',
            'items' => [
                [
                    'code' => 'super_admin',
                    'label' => $moduleLabel('super_admin', 'Super Admin'),
                    'route' => 'workspace.super-admin.index',
                    'icon' => 'super_admin',
                    'patterns' => ['workspace.super-admin.*'],
                    'display_order' => $moduleOrder('super_admin', 130),
                ],
            ],
        ];
    }

    $isActive = static function (array $patterns): bool {
        foreach ($patterns as $pattern) {
            if (request()->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    };

    $icons = [
        'dashboard' => 'M3 12l9-7 9 7v8a2 2 0 01-2 2h-4v-7H9v7H5a2 2 0 01-2-2v-8z',
        'user' => 'M5.121 17.804A9 9 0 1118.88 17.8M15 11a3 3 0 11-6 0 3 3 0 016 0z',
        'alert' => 'M12 9v4m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z',
        'reporting' => 'M9 17v-6m4 6V7m4 10v-3M5 19h14',
        'pilotage' => 'M3 12h4l2-4 4 8 2-4h6',
        'pas' => 'M12 3v4m0 10v4m9-9h-4M7 12H3m13.364-5.364l-2.828 2.828M9.464 14.536l-2.828 2.828m0-10.728l2.828 2.828m7.072 7.072l-2.828-2.828',
        'pao' => 'M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
        'pta' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
        'actions' => 'M13 10V3L4 14h7v7l9-11h-7z',
        'kpi' => 'M5 12h4m3-6h7m-7 6h7m-7 6h7M3 5a2 2 0 012-2h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V5z',
        'kpi_mesures' => 'M7 17l3-3 2 2 5-6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z',
        'referentiel' => 'M10.325 4.317a1.724 1.724 0 013.35 0 1.724 1.724 0 002.573 1.066 1.724 1.724 0 012.37 2.37 1.724 1.724 0 001.065 2.572 1.724 1.724 0 010 3.35 1.724 1.724 0 00-1.066 2.573 1.724 1.724 0 01-2.37 2.37 1.724 1.724 0 00-2.572 1.065 1.724 1.724 0 01-3.35 0 1.724 1.724 0 00-2.573-1.066 1.724 1.724 0 01-2.37-2.37 1.724 1.724 0 00-1.065-2.572 1.724 1.724 0 010-3.35 1.724 1.724 0 001.066-2.573 1.724 1.724 0 012.37-2.37 1.724 1.724 0 002.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
        'delegations' => 'M12 4l7 4-7 4-7-4 7-4zm0 8l7 4-7 4-7-4 7-4z',
        'retention' => 'M7 3h8l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm8 1v5h5',
        'docs' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'audit' => 'M12 3l7 3v6c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V6l7-3z',
        'super_admin' => 'M12 2l2.4 5 5.6.8-4 3.9.95 5.6L12 15.8 7.05 18.3 8 12.7 4 8.8l5.6-.8L12 2z',
    ];
@endphp

<aside id="admin-sidebar" class="sidebar-anbg gooey-sidebar fixed inset-y-0 left-0 z-50 flex h-screen -translate-x-full flex-col overflow-visible border-r border-white/6 shadow-2xl transition-transform duration-200 ease-out lg:translate-x-0" style="width: var(--app-sidebar-width);">
    <div class="relative shrink-0 flex min-h-[5.75rem] flex-col items-center justify-center px-3 py-4 text-center">
        <div class="flex w-full flex-col items-center justify-center">
            <x-brand.logo variant="wordmark" class="gooey-sidebar-wordmark block h-auto" />
            <p class="mt-2 w-full text-center text-[11px] font-medium tracking-[0.16em] text-white/55">{{ $platformSettings->get('sidebar_caption', 'PILOTAGE') }}</p>
        </div>

        <button
            type="button"
            id="admin-sidebar-close"
            class="absolute right-3 top-3 inline-flex h-10 w-10 items-center justify-center rounded-2xl text-white/80 transition hover:bg-white/10 hover:text-white lg:hidden"
            aria-label="Fermer le menu"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-3 py-3" aria-label="Navigation laterale" data-gooey-nav-scroll>
        <div class="gooey-nav" data-gooey-nav>
            @foreach ($sections as $section)
                <div class="gooey-nav-group">
                    @foreach ($section['items'] as $item)
                        @php
                            $active = is_callable($item['active_when'] ?? null)
                                ? (bool) $item['active_when']()
                                : $isActive($item['patterns']);
                            $badgeCount = (int) ($item['badge'] ?? 0);
                            $itemCode = (string) ($item['module_code'] ?? $item['code'] ?? $item['route']);
                            $iconPath = $icons[$item['icon']] ?? $icons['dashboard'];
                            $routeParams = is_array($item['route_params'] ?? null) ? $item['route_params'] : [];
                        @endphp

                        <div class="gooey-item" data-active="{{ $active ? '1' : '0' }}" data-label="{{ $item['label'] }}" data-sidebar-module="{{ $itemCode }}">
                            <a
                                href="{{ route($item['route'], $routeParams) }}"
                                class="gooey-link{{ $active ? ' gooey-link-active' : '' }}"
                                aria-label="{{ $item['label'] }}"
                                @if ($active) aria-current="page" @endif
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $iconPath }}" />
                                </svg>

                                @if ($badgeCount > 0)
                                    <span class="gooey-badge" data-sidebar-badge-for="{{ $itemCode }}">{{ $badgeCount > 99 ? '99+' : $badgeCount }}</span>
                                @endif
                            </a>

                        </div>
                    @endforeach
                </div>

                @unless ($loop->last)
                    <div class="gooey-nav-divider" aria-hidden="true"></div>
                @endunless
            @endforeach
        </div>
    </nav>

    <div class="gooey-label-layer" aria-hidden="true" data-gooey-label-layer>
        <span class="gooey-floating-label" data-gooey-floating-label></span>
    </div>

    <div class="shrink-0 px-3 py-4">
        <div class="gooey-item gooey-item-logout" data-label="Deconnexion">
            <form method="POST" action="{{ route('logout') }}" class="gooey-logout-form">
                @csrf
                <button type="submit" class="gooey-logout" aria-label="Deconnexion">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H9m4 4v1a2 2 0 01-2 2H6a2 2 0 01-2-2V7a2 2 0 012-2h5a2 2 0 012 2v1" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>
