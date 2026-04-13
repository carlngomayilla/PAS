@extends('layouts.workspace')

@section('title', 'Reporting institutionnel')

@push('head')
    <style>
        .reporting-hub-kpi{border-radius:1.2rem;border:1px solid rgba(203,213,225,.82);padding:1rem;background:linear-gradient(180deg,rgba(255,255,255,.99) 0%,rgba(248,250,252,.95) 100%);box-shadow:0 18px 28px -28px rgba(15,23,42,.12)}
        .reporting-hub-kpi .dashboard-summary-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6B7280}
        .reporting-hub-kpi .dashboard-summary-value{color:#1F2937}
        .reporting-hub-kpi .dashboard-summary-meta{color:#6B7280}
        .reporting-hub-kpi-blue .dashboard-summary-value{color:#3B82F6}
        .reporting-hub-kpi-green .dashboard-summary-value{color:#10B981}
        .reporting-hub-kpi-amber .dashboard-summary-value{color:#F59E0B}
        .reporting-hub-kpi-navy .dashboard-summary-value{color:#1E3A8A}
        .dark .reporting-hub-kpi{border-color:rgba(71,85,105,.72);background:linear-gradient(180deg,rgba(15,23,42,.96) 0%,rgba(17,24,39,.9) 100%)}
        .dark .reporting-hub-kpi .dashboard-summary-label,.dark .reporting-hub-kpi .dashboard-summary-meta{color:#94A3B8}
        .dark .reporting-hub-kpi-navy .dashboard-summary-value,.dark .reporting-hub-kpi-blue .dashboard-summary-value,.dark .reporting-hub-kpi-green .dashboard-summary-value,.dark .reporting-hub-kpi-amber .dashboard-summary-value{color:#F8FAFC}
    </style>
@endpush

@section('content')
    @php
        $roleProfile = $roleProfile ?? ['eyebrow' => 'Reporting institutionnel', 'title' => 'Centre d export et de diffusion', 'subtitle' => 'Exports et diffusion du reporting.', 'role_label' => strtoupper((string) ($scope['role'] ?? 'lecture'))];
        $dashboardAnalyticsUrl = route('dashboard').'?dashboardTab=charts';
        $statisticalPolicy = is_array($statisticalPolicy ?? null) ? $statisticalPolicy : [];
        $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
        $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
        $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $officialAverageText = 'Moyenne sur '.$officialBaseLabel;
        $officialCompletedText = 'Achevees sur '.$officialBaseLabel;
        $officialFilters = (array) ($basePolicy['route_filters'] ?? []);
        $directionServiceReport = collect($details['direction_service_report'] ?? []);
        $summaryCards = [
            ['label' => 'PAS scopes', 'value' => $global['pas_total'] ?? 0, 'tone' => 'navy', 'meta' => 'Strategie couverte', 'href' => route('workspace.pas.index'), 'badge' => null, 'badge_tone' => 'info'],
            ['label' => 'PAO scopes', 'value' => $global['paos_total'] ?? 0, 'tone' => 'blue', 'meta' => 'Declinaisons valides', 'href' => route('workspace.pao.index'), 'badge' => null, 'badge_tone' => 'warning'],
            ['label' => 'Actions suivies', 'value' => $global['actions_validees'] ?? 0, 'tone' => 'green', 'meta' => $officialBaseText, 'href' => route('workspace.actions.index', $officialFilters), 'badge' => null, 'badge_tone' => 'success'],
            ['label' => 'Alertes actives', 'value' => ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0), 'tone' => 'amber', 'meta' => 'Retards et indicateurs sous seuil', 'href' => route('workspace.alertes', ['limit' => 100]), 'badge' => null, 'badge_tone' => 'danger'],
        ];
        $scopeLabel = $roleProfile['role_label'] ?? strtoupper((string) ($scope['role'] ?? 'lecture'));
        $generatedLabel = isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $exportModules = [
            'Classeur XLSX normalise en 9 feuilles ANBG',
            'Feuilles STRATEGIE / PAO / ACTIONS / KPI',
            'Synthese, alertes, risques, RMO et justificatifs',
            'Document Word institutionnel configurable',
            'PDF pagine avec sommaire',
            'Pack CSV normalise en 9 fichiers metiers',
            'Rapports hierarchises direction -> service',
        ];
        $activeTemplateLabels = array_filter([
            ! empty($activeExportTemplates['excel'] ?? null) ? 'Excel: '.$activeExportTemplates['excel'] : null,
            ! empty($activeExportTemplates['word'] ?? null) ? 'Word: '.$activeExportTemplates['word'] : null,
            ! empty($activeExportTemplates['pdf'] ?? null) ? 'PDF: '.$activeExportTemplates['pdf'] : null,
        ]);
        $managedKpis = collect($managedKpis ?? [])->take(6)->values();
        $analyticsFamilies = [
            'Cockpit indicateurs et tendances',
            'Entonnoir PAS / PAO / PTA / Actions',
            'Heatmap des retards',
            'Pareto des risques',
            'Gantt critique et jauges de performance',
            'Tables de consolidation PAS et comparaisons interannuelles',
        ];
    @endphp

    <section class="showcase-hero mb-4">
        <div class="showcase-hero-body">
            <div class="max-w-3xl">
                <span class="showcase-eyebrow">{{ $roleProfile['eyebrow'] }}</span>
                <h1 class="showcase-title">{{ $roleProfile['title'] }}</h1>
                <div class="showcase-chip-row">
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1E3A8A]"></span>{{ $scopeLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#10B981]"></span>{{ $officialBaseText }}</span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.pilotage') }}">Pilotage global</a>
                <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ $dashboardAnalyticsUrl }}">Dashboard analytique</a>
                <a class="btn btn-green rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.excel') }}">Exporter Excel (.xlsx)</a>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.csv') }}">Exporter CSV (.zip)</a>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.word') }}">Exporter Word (.doc)</a>
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.pdf') }}">Exporter PDF</a>
            </div>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="anbg-badge anbg-badge-success px-3 py-1">Actions suivies</span>
        <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $officialBaseText }}</span>
    </div>

    <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        @foreach ($summaryCards as $card)
            <x-stat-card-link
                :href="$card['href']"
                :label="$card['label']"
                :value="$card['value']"
                :meta="$card['meta']"
                :badge="$card['badge']"
                :badge-tone="$card['badge_tone']"
                card-class="reporting-hub-kpi reporting-hub-kpi-{{ $card['tone'] }}"
                label-class="dashboard-summary-label"
                value-class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none"
                meta-class="dashboard-summary-meta mt-2 text-xs"
            />
        @endforeach
    </div>

    @if ($managedKpis->isNotEmpty())
        <section class="showcase-panel mb-4">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">KPI pilotes actifs</h2>
                </div>
            </div>
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                @foreach ($managedKpis as $metric)
                    <x-stat-card-link
                        :href="route('workspace.super-admin.kpis.edit')"
                        :label="$metric['label']"
                        :value="number_format((float) ($metric['value'] ?? 0), 1)"
                        :meta="collect([
                            ($metric['description'] ?? '') !== '' ? $metric['description'] : null,
                            $metric['formula_summary'] ?? null,
                            'Poids '.($metric['weight'] ?? 0),
                            'Seuil vert '.number_format((float) ($metric['green_threshold'] ?? 0), 0),
                        ])->filter()->implode(' | ')"
                        badge="Actif"
                        :badge-tone="$metric['tone'] === 'success' ? 'success' : ($metric['tone'] === 'warning' ? 'warning' : 'danger')"
                    />
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-4">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Analytique disponible</h2>
                </div>
            </div>

            <div class="grid gap-3">
                @foreach ($analyticsFamilies as $family)
                    <div class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-200">
                        {{ $family }}
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ $dashboardAnalyticsUrl }}" class="btn btn-primary rounded-2xl px-4 py-2.5">Ouvrir le dashboard analytique</a>
                <a href="{{ route('workspace.alertes') }}" class="btn btn-secondary rounded-2xl px-4 py-2.5">Voir le centre d alertes</a>
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Exports disponibles</h2>
                </div>
            </div>

            <div class="grid gap-3">
                @foreach ($exportModules as $module)
                    <div class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm font-medium text-slate-700 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-200">
                        {{ $module }}
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-[1.25rem] border border-dashed border-slate-300/80 bg-slate-50/80 px-4 py-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300">
                Les exports reprennent la meme base statistique.
            </div>
            @if ($activeTemplateLabels !== [])
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($activeTemplateLabels as $label)
                        <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $label }}</span>
                    @endforeach
                </div>
            @endif
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Apercu direction -> service</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-300">Les exports institutionnels sont structures par direction puis par service, sans tableau global melange.</p>
                </div>
                <span class="anbg-badge anbg-badge-info px-3 py-1">{{ $directionServiceReport->count() }} directions</span>
            </div>

            <div class="space-y-4">
                @forelse ($directionServiceReport->take(5) as $direction)
                    @php
                        $directionSummary = (array) ($direction['summary'] ?? []);
                        $services = collect($direction['services'] ?? []);
                        $directionLabel = trim(((string) ($direction['code'] ?? '') !== '' ? ($direction['code'].' - ') : '').(string) ($direction['libelle'] ?? 'Direction'));
                    @endphp
                    <section class="rounded-[1.2rem] border border-slate-200/85 bg-slate-50/90 p-4 dark:border-slate-800 dark:bg-slate-900/70">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-slate-100">{{ $directionLabel }}</h3>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Responsable : {{ $direction['responsable'] ?? '-' }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="anbg-badge anbg-badge-neutral px-3">{{ $directionSummary['actions_total'] ?? 0 }} actions</span>
                                <span class="anbg-badge anbg-badge-success px-3">{{ number_format((float) ($directionSummary['taux_realisation'] ?? 0), 1) }}% realisation</span>
                                <span class="anbg-badge anbg-badge-warning px-3">{{ number_format((float) ($directionSummary['taux_retard'] ?? 0), 1) }}% retard</span>
                            </div>
                        </div>

                        <div class="mt-4 space-y-3">
                            @forelse ($services as $service)
                                @php
                                    $serviceSummary = (array) ($service['summary'] ?? []);
                                    $serviceLabel = trim(((string) ($service['code'] ?? '') !== '' ? ($service['code'].' - ') : '').(string) ($service['libelle'] ?? 'Service'));
                                @endphp
                                <div class="rounded-[1rem] border border-white/80 bg-white/90 p-3 dark:border-slate-800 dark:bg-slate-950/60">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-slate-900 dark:text-slate-100">{{ $serviceLabel }}</p>
                                            <p class="text-xs text-slate-500 dark:text-slate-400">Responsable : {{ $service['responsable'] ?? '-' }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 text-xs">
                                            <span class="anbg-badge anbg-badge-neutral px-3">{{ $serviceSummary['actions_total'] ?? 0 }} actions</span>
                                            <span class="anbg-badge anbg-badge-success px-3">{{ number_format((float) ($serviceSummary['taux_realisation'] ?? 0), 1) }}%</span>
                                            <span class="anbg-badge anbg-badge-info px-3">KPI {{ number_format((float) ($serviceSummary['kpi_global'] ?? 0), 1) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-[1rem] border border-dashed border-slate-300/80 px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucun service actif rattache.</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/80 px-4 py-8 text-center text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">Aucune donnee direction -> service disponible pour le perimetre courant.</div>
                @endforelse
            </div>
        </article>
    </div>
@endsection

