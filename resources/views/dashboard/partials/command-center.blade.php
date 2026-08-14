@php
    $dashboardMode = match ($dashboardRole) {
        'dg', 'cabinet' => 'Pilotage exécutif',
        'planification', 'suivi_evaluation', 'global', 'admin', 'super_admin' => 'Pilotage administratif',
        'direction' => 'Pilotage directionnel',
        'service' => 'Pilotage du service',
        default => 'Pilotage opérationnel',
    };
    $validationCount = (int) ($workflowCounts['validation_chef'] ?? 0)
        + (int) ($workflowCounts['validation_controleur'] ?? 0);
    $actionableReports = (int) ($deadlineExtensionSummary['actionable_count'] ?? 0);
    $myReports = (int) ($deadlineExtensionSummary['mine_count'] ?? 0);
    $criticalCount = (int) ($alertCounts['critique'] ?? 0)
        + (int) ($alertCounts['en_retard'] ?? 0);
    $flowItems = [
        [
            'key' => 'validations',
            'label' => 'Validations en attente',
            'value' => $validationCount,
            'meta' => 'Actions aux étapes chef et contrôle',
            'href' => route('workspace.actions.index', ['vue' => 'validations']),
            'tone' => $validationCount > 0 ? '#a16207' : '#0e7450',
            'dark_tone' => $validationCount > 0 ? '#fbbf24' : '#58d3a4',
            'icon' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        ],
        [
            'key' => 'deadline-extensions',
            'label' => "Reports d'échéance",
            'value' => $actionableReports,
            'meta' => $myReports.' demande(s) déposée(s)',
            'href' => route('workspace.deadline-extension.index'),
            'tone' => $actionableReports > 0 ? '#b45309' : '#0f5f99',
            'dark_tone' => $actionableReports > 0 ? '#fdba74' : '#65caef',
            'icon' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        ],
        [
            'key' => 'critical-points',
            'label' => 'Points critiques',
            'value' => $criticalCount,
            'meta' => 'Alertes critiques et actions en retard',
            'href' => route('workspace.notifications.index', ['tab' => 'alertes']),
            'tone' => $criticalCount > 0 ? '#b42318' : '#0e7450',
            'dark_tone' => $criticalCount > 0 ? '#fca5a5' : '#58d3a4',
            'icon' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        ],
    ];
@endphp

<section class="mb-4 overflow-hidden rounded-lg border border-[#cfe3ef] bg-white shadow-[0_16px_38px_-32px_rgba(15,23,42,0.5)]" data-dashboard-command-center>
    <div class="border-b border-[#dcecf5] bg-[#f7fbfd] px-4 py-4 sm:px-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div class="min-w-0">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-md bg-[#17324a] px-2 py-1 text-[10px] font-bold uppercase text-white">{{ $dashboardMode }}</span>
                    <span class="text-xs font-semibold text-[#667085]">{{ $roleHero['eyebrow'] ?? 'Vue personnalisée' }}</span>
                </div>
                <h1 class="text-xl font-bold text-[#17324a] sm:text-2xl">{{ $roleHero['title'] ?? 'Centre de pilotage' }}</h1>
                <p class="mt-1 max-w-4xl text-sm leading-6 text-[#667085]">{{ $roleHero['subtitle'] ?? 'Synthèse des résultats, des alertes et des décisions attendues sur votre périmètre.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canOpenPtaSuivi)
                    <a class="btn btn-secondary btn-sm rounded-lg" href="{{ route('pta.suivi.index', $ptaSuiviQuery) }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16v16H4V4zm0 5h16M9 4v16" /></svg>
                        Suivi PTA
                    </a>
                @endif
                <a class="btn btn-primary btn-sm rounded-lg" href="{{ route('workspace.reporting') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 19V9m6 10V5m6 14v-7m4 7H2" /></svg>
                    Rapports
                </a>
            </div>
        </div>
    </div>

    <div class="dashboard-command-kpi-grid grid gap-px border-b border-[#dcecf5] bg-[#dcecf5] sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        @forelse ($summaryStrip as $card)
            <a
                href="{{ $card['href'] }}"
                class="dashboard-primary-kpi-card group relative min-h-32 overflow-hidden bg-white px-4 py-4 transition"
                style="--dashboard-card-accent: {{ $card['accent'] ?? '#3996d3' }}; --dashboard-card-bg: {{ $card['bg'] ?? '#f7fbfd' }};"
                data-dashboard-primary-kpi
            >
                <span class="dashboard-primary-kpi-glow" aria-hidden="true"></span>
                <div class="relative z-10 flex h-full flex-col justify-between gap-4">
                    <div class="flex items-start justify-between gap-3">
                        <p class="max-w-[12rem] text-xs font-bold leading-5 text-[#526174]">{{ $card['label'] }}</p>
                        <span class="dashboard-primary-kpi-icon flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                {!! $card['icon'] ?? '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>' !!}
                            </svg>
                        </span>
                    </div>
                    <div class="flex items-end justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-3xl font-black tracking-tight text-[#17324a]">{{ $card['value'] }}</p>
                            <p class="mt-1 line-clamp-2 text-[11px] font-medium leading-4 text-[#718096]">{{ $card['meta'] ?? 'Ouvrir le détail du périmètre' }}</p>
                        </div>
                        <svg class="mb-1 h-4 w-4 shrink-0 text-[#9aa8b6] transition group-hover:translate-x-1 group-hover:text-[var(--dashboard-card-accent)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
                        </svg>
                    </div>
                </div>
            </a>
        @empty
            <div class="col-span-full px-5 py-6 text-sm text-[#667085]">Les indicateurs seront affichés dès que le périmètre contiendra des données.</div>
        @endforelse
    </div>

    <div class="px-4 py-4 sm:px-5">
        <div class="mb-3 flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-bold text-[#17324a]">Flux à traiter</h2>
                <p class="mt-0.5 text-xs text-[#667085]">Décisions et contrôles qui demandent une intervention.</p>
            </div>
            <a href="{{ route('workspace.tasks.index') }}" class="text-xs font-bold text-[#0f5f99] hover:text-[#17324a] dark:text-[#65caef] dark:hover:text-white">Mes tâches</a>
        </div>
        <div class="grid gap-2 lg:grid-cols-3">
            @foreach ($flowItems as $item)
                <a href="{{ $item['href'] }}" class="flex min-h-20 items-center gap-3 rounded-lg border border-[#dce8ef] bg-white px-3 py-3 transition hover:border-[#3996d3] hover:bg-[#f7fbfd]" data-dashboard-flow="{{ $item['key'] }}" data-flow-count="{{ $item['value'] }}">
                    <span class="dashboard-flow-tone flex h-10 w-10 shrink-0 items-center justify-center rounded-lg" style="--flow-tone: {{ $item['tone'] }}; --flow-tone-dark: {{ $item['dark_tone'] }};">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-bold text-[#17324a]">{{ $item['label'] }}</span>
                            <span class="dashboard-flow-value text-lg font-bold" style="--flow-tone: {{ $item['tone'] }}; --flow-tone-dark: {{ $item['dark_tone'] }};">{{ $item['value'] }}</span>
                        </span>
                        <span class="mt-1 block truncate text-xs text-[#667085]">{{ $item['meta'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</section>
