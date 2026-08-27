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
            'state' => $validationCount > 0 ? 'attention' : 'clear',
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
            'state' => $actionableReports > 0 ? 'attention' : 'clear',
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
            'state' => $criticalCount > 0 ? 'critical' : 'clear',
            'icon' => '<path d="m10.3 3.9-8.5 14A2 2 0 0 0 3.5 21h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        ],
    ];
    $activeFlowCount = collect($flowItems)->sum('value');
@endphp

<section class="dashboard-command-center" data-dashboard-command-center>
    <header class="dashboard-command-hero">
        <div class="dashboard-command-hero-copy">
            <div class="dashboard-command-context">
                <span class="dashboard-command-mode">{{ $dashboardMode }}</span>
                <span class="dashboard-command-eyebrow">{{ $roleHero['eyebrow'] ?? 'Vue personnalisée' }}</span>
            </div>
            <h1>{{ $roleHero['title'] ?? 'Centre de pilotage' }}</h1>
            <p>{{ $roleHero['subtitle'] ?? 'Synthèse des résultats, des alertes et des décisions attendues sur votre périmètre.' }}</p>
        </div>

        <div class="dashboard-command-actions" aria-label="Raccourcis de pilotage">
            @if ($canOpenPtaSuivi)
                <a class="app-btn app-btn-secondary" href="{{ route('pta.suivi.index', $ptaSuiviQuery) }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4h16v16H4V4zm0 5h16M9 4v16" />
                    </svg>
                    Suivi PTA
                </a>
            @endif
            <a class="app-btn app-btn-primary" href="{{ route('workspace.reporting') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 19V9m6 10V5m6 14v-7m4 7H2" />
                </svg>
                Ouvrir les rapports
            </a>
        </div>
    </header>

    <div class="dashboard-command-kpi-grid" aria-label="Indicateurs essentiels">
        @forelse ($summaryStrip as $card)
            <a
                href="{{ $card['href'] }}"
                class="dashboard-primary-kpi-card group"
                style="--dashboard-card-accent: {{ $card['accent'] ?? '#3996d3' }}; --dashboard-card-bg: {{ $card['bg'] ?? '#f7fbfd' }};"
                data-dashboard-primary-kpi
            >
                <span class="dashboard-primary-kpi-glow" aria-hidden="true"></span>
                <span class="dashboard-primary-kpi-topline">
                    <span class="dashboard-primary-kpi-label">{{ $card['label'] }}</span>
                    <span class="dashboard-primary-kpi-icon">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            {!! $card['icon'] ?? '<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>' !!}
                        </svg>
                    </span>
                </span>
                <span class="dashboard-primary-kpi-bottomline">
                    <span class="min-w-0">
                        <strong class="dashboard-primary-kpi-value">{{ $card['value'] }}</strong>
                        <span class="dashboard-primary-kpi-meta">{{ $card['meta'] ?? 'Ouvrir le détail du périmètre' }}</span>
                    </span>
                    <svg class="dashboard-primary-kpi-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
                    </svg>
                </span>
            </a>
        @empty
            <div class="dashboard-command-empty">Les indicateurs seront affichés dès que le périmètre contiendra des données.</div>
        @endforelse
    </div>

    <section class="dashboard-priority-zone" aria-labelledby="dashboard-priority-title" data-dashboard-insight-zone>
        <div class="dashboard-priority-header">
            <div>
                <span class="dashboard-priority-kicker">À traiter aujourd’hui</span>
                <h2 id="dashboard-priority-title">Décisions et contrôles prioritaires</h2>
                <p>Les éléments qui demandent une intervention sur votre périmètre.</p>
            </div>
            <div class="dashboard-priority-summary">
                <strong>{{ $activeFlowCount }}</strong>
                <span>élément(s) à examiner</span>
                <a href="{{ route('workspace.tasks.index') }}">Voir mes tâches</a>
            </div>
        </div>

        <div class="dashboard-priority-grid">
            @foreach ($flowItems as $item)
                <a
                    href="{{ $item['href'] }}"
                    class="dashboard-priority-item"
                    data-dashboard-flow="{{ $item['key'] }}"
                    data-flow-count="{{ $item['value'] }}"
                    data-flow-state="{{ $item['state'] }}"
                >
                    <span class="dashboard-flow-tone dashboard-priority-icon" style="--flow-tone: {{ $item['tone'] }}; --flow-tone-dark: {{ $item['dark_tone'] }};">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item['icon'] !!}</svg>
                    </span>
                    <span class="dashboard-priority-copy">
                        <span class="dashboard-priority-label">{{ $item['label'] }}</span>
                        <span class="dashboard-priority-meta">{{ $item['meta'] }}</span>
                    </span>
                    <span class="dashboard-flow-value dashboard-priority-value" style="--flow-tone: {{ $item['tone'] }}; --flow-tone-dark: {{ $item['dark_tone'] }};">{{ $item['value'] }}</span>
                    <svg class="dashboard-priority-arrow" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 18 6-6-6-6" />
                    </svg>
                </a>
            @endforeach
        </div>
    </section>
</section>
