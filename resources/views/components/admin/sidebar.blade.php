@props([
    'notificationCounts' => [],
    'unreadTotal' => 0,
])

@php
    $user = auth()->user();
    $delegatedModules = collect($user?->workspaceModules() ?? [])->keyBy('code');
    $canSeeModule = static fn (string $code): bool => $delegatedModules->has($code);
    $canReadWorkspace = ($user?->hasGlobalReadAccess() ?? false) || ($user?->hasRole('direction', 'service') ?? false);
    $moduleBadges = is_array($notificationCounts) ? $notificationCounts : [];

    $sections = [];

    $pilotageItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'patterns' => ['dashboard', 'admin.dashboard']],
        ['label' => 'Mon profil', 'route' => 'workspace.profile.edit', 'icon' => 'user', 'patterns' => ['workspace.profile.*']],
    ];
    if ($canReadWorkspace) {
        $pilotageItems[] = ['label' => 'Pilotage', 'route' => 'workspace.pilotage', 'icon' => 'pulse', 'patterns' => ['workspace.pilotage'], 'module' => 'pilotage'];
    }
    if ($canSeeModule('reporting')) {
        $pilotageItems[] = ['label' => 'Reporting', 'route' => 'workspace.reporting', 'icon' => 'chart', 'patterns' => ['workspace.reporting'], 'module' => 'reporting'];
    }
    if ($canSeeModule('alertes')) {
        $pilotageItems[] = ['label' => 'Alertes', 'route' => 'workspace.alertes', 'icon' => 'alert', 'patterns' => ['workspace.alertes'], 'module' => 'alertes'];
    }
    $sections[] = ['title' => 'Pilotage', 'items' => $pilotageItems];

    $planificationItems = [];
    if ($canSeeModule('pas')) {
        $planificationItems[] = [
            'label' => 'PAS',
            'route' => 'workspace.pas.index',
            'icon' => 'target',
            'patterns' => ['workspace.pas.*'],
            'module' => 'pas',
        ];
    }
    if ($canSeeModule('pao')) {
        $planificationItems[] = [
            'label' => 'PAO',
            'route' => 'workspace.pao.index',
            'icon' => 'folder',
            'patterns' => ['workspace.pao.*'],
            'module' => 'pao',
        ];
    }
    if ($canSeeModule('pta')) {
        $planificationItems[] = ['label' => 'PTA', 'route' => 'workspace.pta.index', 'icon' => 'calendar', 'patterns' => ['workspace.pta.*'], 'module' => 'pta'];
    }
    if ($planificationItems !== []) {
        $sections[] = ['title' => 'Planification', 'items' => $planificationItems];
    }

    $executionItems = [];
    if ($canSeeModule('execution')) {
        $executionItems[] = ['label' => 'Actions', 'route' => 'workspace.actions.index', 'icon' => 'bolt', 'patterns' => ['workspace.actions.*'], 'module' => 'actions'];
    }
    if ($executionItems !== []) {
        $sections[] = ['title' => 'Execution', 'items' => $executionItems];
    }

    if ($canSeeModule('referentiel')) {
        $gouvernanceItems = [
            ['label' => 'Referentiel', 'route' => 'workspace.referentiel.directions.index', 'icon' => 'cog', 'patterns' => ['workspace.referentiel.directions.*', 'workspace.referentiel.services.*', 'workspace.referentiel.utilisateurs.*'], 'module' => 'referentiel'],
        ];
        if ($canSeeModule('delegations')) {
            $gouvernanceItems[] = ['label' => 'Delegations', 'route' => 'workspace.delegations.index', 'icon' => 'layers', 'patterns' => ['workspace.delegations.*'], 'module' => 'delegations'];
        }
        if ($canSeeModule('retention')) {
            $gouvernanceItems[] = ['label' => 'Retention', 'route' => 'workspace.retention.index', 'icon' => 'doc', 'patterns' => ['workspace.retention.*'], 'module' => 'retention'];
        }
        $sections[] = [
            'title' => 'Gouvernance',
            'items' => $gouvernanceItems,
        ];
    }

    if ($canSeeModule('audit') || $canSeeModule('api_docs')) {
        $controleItems = [];
        if ($canSeeModule('api_docs')) {
            $controleItems[] = ['label' => 'API Docs', 'route' => 'workspace.api-docs.index', 'icon' => 'doc', 'patterns' => ['workspace.api-docs.*'], 'module' => 'api_docs'];
        }
        if ($canSeeModule('audit')) {
            $controleItems[] = ['label' => 'Journal Audit', 'route' => 'workspace.audit.index', 'icon' => 'shield', 'patterns' => ['workspace.audit.*'], 'module' => 'audit'];
        }
        $sections[] = [
            'title' => 'Controle',
            'items' => $controleItems,
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
@endphp

<!-- Sidebar Principal -->
<aside id="admin-sidebar" class="fixed inset-y-0 left-0 z-50 w-80 -translate-x-full transform transition-transform duration-300 ease-out lg:translate-x-0">
    <!-- Fond avec gradient subtle -->
    <div class="absolute inset-0 bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950"></div>
    
    <!-- Contenu avec z-index -->
    <div class="relative flex h-full flex-col overflow-hidden">
        <!-- Header Sticky -->
        <div class="shrink-0 border-b border-slate-800/50 bg-slate-900/40 backdrop-blur-sm px-6 py-5">
            <div class="mb-4 flex items-center justify-between">
                <x-brand.logo variant="wordmark" class="h-8 w-auto text-white" />
                <button
                    type="button"
                    id="admin-sidebar-close"
                    class="hidden rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-slate-200 lg:hidden"
                    aria-label="Close menu"
                >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            
            <!-- Subtitle + Badge -->
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs font-medium text-slate-400">Espace de pilotage</p>
                @if ((int) $unreadTotal > 0)
                    <span class="inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-gradient-to-r from-rose-500 to-rose-600 px-1.5 text-[10px] font-bold text-white shadow-lg shadow-rose-500/50">
                        {{ (int) $unreadTotal > 99 ? '99+' : (int) $unreadTotal }}
                    </span>
                @endif
            </div>
        </div>

        <!-- Navigation Scrollable -->
        <nav class="flex-1 overflow-y-auto px-4 py-6">
            @foreach ($sections as $index => $section)
                <div @class([ 'mb-8' => !$loop->last, 'mb-0' => $loop->last ])>
                    <!-- Section Header -->
                    <div class="mb-3 px-3">
                        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-500">
                            {{ $section['title'] }}
                        </h3>
                        <div class="mt-2 h-0.5 w-8 bg-gradient-to-r from-blue-500 to-transparent rounded-full"></div>
                    </div>

                    <!-- Menu Items -->
                    <div class="space-y-1.5">
                        @foreach ($section['items'] as $item)
                            @php
                                $active = $isActive($item['patterns']);
                                $moduleKey = strtolower((string) ($item['module'] ?? ''));
                                $badgeCount = $moduleKey !== '' ? (int) ($moduleBadges[$moduleKey] ?? 0) : 0;
                            @endphp

                            <a
                                href="{{ route($item['route']) }}"
                                @class([
                                    'group relative flex items-center gap-3.5 rounded-xl px-4 py-3 text-sm font-medium transition-all duration-200',
                                    'bg-gradient-to-r from-blue-600/80 to-blue-700/80 text-white shadow-lg shadow-blue-500/25 hover:shadow-xl hover:shadow-blue-500/40' => $active,
                                    'text-slate-300 hover:text-white hover:bg-slate-800/50' => !$active,
                                ])
                            >
                                <!-- Icon Background -->
                                @if (!$active)
                                    <div class="absolute inset-0 rounded-xl bg-slate-800/0 transition-all duration-200 group-hover:bg-slate-800/50"></div>
                                @endif

                                <!-- Icon -->
                                <span class="relative z-10 flex h-5 w-5 items-center justify-center flex-shrink-0">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        @if ($item['icon'] === 'home')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10l9-7 9 7v10a2 2 0 01-2 2h-4a2 2 0 01-2-2V12H9v8a2 2 0 01-2 2H5a2 2 0 01-2-2V10z"/>
                                        @elseif ($item['icon'] === 'user')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.88 17.8M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        @elseif ($item['icon'] === 'target')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2v4m0 12v4m10-10h-4M6 12H2m15.07-7.07l-2.83 2.83M9.76 14.24l-2.83 2.83m0-10.14l2.83 2.83m7.48 7.48l-2.83-2.83M12 8a4 4 0 100 8 4 4 0 000-8z"/>
                                        @elseif ($item['icon'] === 'folder')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h5l2 2h9a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                                        @elseif ($item['icon'] === 'layers')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l9 4.5-9 4.5-9-4.5L12 3zm0 9l9 4.5-9 4.5-9-4.5 9-4.5z"/>
                                        @elseif ($item['icon'] === 'calendar')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        @elseif ($item['icon'] === 'bolt')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                        @elseif ($item['icon'] === 'doc')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 3h8l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1zm8 1v5h5"/>
                                        @elseif ($item['icon'] === 'pulse')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12h4l2-4 4 8 2-4h6"/>
                                        @elseif ($item['icon'] === 'alert')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v4m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.33 16a2 2 0 001.74 3z"/>
                                        @elseif ($item['icon'] === 'chart')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3v18m4-14v14m4-10v10M7 7v14M3 21h18"/>
                                        @elseif ($item['icon'] === 'shield')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l7 3v6c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V6l7-3z"/>
                                        @elseif ($item['icon'] === 'cog')
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        @else
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                        @endif
                                    </svg>
                                </span>

                                <!-- Label -->
                                <span class="relative z-10 flex-1">{{ $item['label'] }}</span>

                                <!-- Badge -->
                                @if ($badgeCount > 0)
                                    <span class="relative z-10 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-rose-500/90 px-1.5 text-[10px] font-bold text-white">
                                        {{ $badgeCount > 99 ? '99+' : $badgeCount }}
                                    </span>
                                @elseif (!$active)
                                    <div class="relative z-10 h-1.5 w-1.5 rounded-full bg-slate-600 opacity-0 transition-opacity duration-200 group-hover:opacity-100"></div>
                                @endif

                                <!-- Active Line -->
                                @if ($active)
                                    <div class="absolute -right-4 top-1/2 h-8 w-1 -translate-y-1/2 rounded-l-full bg-blue-400 shadow-lg shadow-blue-400/50"></div>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </nav>

        <!-- Footer Sticky -->
        <div class="shrink-0 border-t border-slate-800/50 bg-slate-900/40 backdrop-blur-sm px-4 py-4">
            <!-- User Info Card -->
            <div class="mb-4 rounded-xl bg-gradient-to-br from-slate-800/50 to-slate-900/50 p-4 border border-slate-700/30">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-blue-500 to-blue-600">
                        <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A9 9 0 1118.88 17.8M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-white truncate">ANBG</p>
                        <p class="text-xs text-slate-400 mt-0.5">Admin System</p>
                    </div>
                </div>
            </div>

            <!-- Logout Button with Gradient -->
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <button 
                    type="submit"
                    class="w-full rounded-lg bg-gradient-to-r from-blue-600 to-blue-700 px-4 py-2.5 text-sm font-semibold text-white transition-all duration-200 shadow-lg shadow-blue-500/25 hover:shadow-xl hover:shadow-blue-500/40 hover:brightness-110 active:scale-95"
                >
                    Déconnexion
                </button>
            </form>
        </div>
    </div>
</aside>

<!-- Mobile Overlay -->
<div id="admin-sidebar-overlay" class="fixed inset-0 z-40 hidden bg-black/50 transition-opacity duration-300 lg:hidden" aria-hidden="true"></div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('admin-sidebar');
        const overlay = document.getElementById('admin-sidebar-overlay');
        const openButton = document.getElementById('admin-sidebar-open');
        const closeButton = document.getElementById('admin-sidebar-close');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.remove('hidden');
        }

        function closeSidebar() {
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
    });
</script>
