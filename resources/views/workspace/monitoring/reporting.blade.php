@extends('layouts.workspace')

@section('title', 'Reporting')

@push('head')
    <style>
        .reporting-hub-kpi{border-radius:1.2rem;border:1px solid rgba(203,213,225,.82);padding:1rem;background:linear-gradient(180deg,rgba(255,255,255,.99) 0%,rgba(248,250,252,.95) 100%);box-shadow:0 18px 28px -28px rgba(15,23,42,.12)}
        .reporting-hub-kpi .dashboard-summary-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6B7280}
        .reporting-hub-kpi .dashboard-summary-value{color:#1F2937}
        .reporting-hub-kpi .dashboard-summary-meta{color:#6B7280}
        .reporting-hub-kpi-blue .dashboard-summary-value{color:#3996D3}
        .reporting-hub-kpi-green .dashboard-summary-value{color:#8FC043}
        .reporting-hub-kpi-amber .dashboard-summary-value{color:#F9B13C}
        .reporting-hub-kpi-navy .dashboard-summary-value{color:#1C203D}


        .dark .reporting-hub-kpi-navy .dashboard-summary-value,.dark .reporting-hub-kpi-blue .dashboard-summary-value,.dark .reporting-hub-kpi-green .dashboard-summary-value,.dark .reporting-hub-kpi-amber .dashboard-summary-value{color:#F8FAFC}
    </style>
@endpush

@section('content')
    @php
        $roleProfile = $roleProfile ?? ['eyebrow' => 'Reporting institutionnel', 'title' => "Centre d'export et de diffusion", 'subtitle' => 'Exports et diffusion du reporting.', 'role_label' => strtoupper((string) ($scope['role'] ?? 'lecture'))];
        $dashboardAnalyticsUrl = route('dashboard').'?dashboardTab=charts';
        $statisticalPolicy = is_array($statisticalPolicy ?? null) ? $statisticalPolicy : [];
        $officialPolicy = is_array($officialPolicy ?? null) ? $officialPolicy : [];
        $basePolicy = $statisticalPolicy !== [] ? $statisticalPolicy : $officialPolicy;
        $officialBaseLabel = (string) ($basePolicy['scope_label'] ?? $basePolicy['threshold_label'] ?? 'Toutes les actions visibles');
        $officialBaseText = 'Base statistique : '.$officialBaseLabel;
        $officialAverageText = 'Moyenne sur '.$officialBaseLabel;
        $officialCompletedText = 'Achevées sur '.$officialBaseLabel;
        $officialFilters = (array) ($basePolicy['route_filters'] ?? []);
        $directionServiceReport = collect($details['direction_service_report'] ?? []);
        $summaryCards = [
            ['label' => 'PAS', 'value' => $global['pas_total'] ?? 0, 'tone' => 'navy', 'meta' => null, 'href' => route('workspace.pas.index'), 'badge' => null, 'badge_tone' => 'info'],
            ['label' => 'PAO', 'value' => $global['paos_total'] ?? 0, 'tone' => 'blue', 'meta' => null, 'href' => route('workspace.pao.index'), 'badge' => null, 'badge_tone' => 'warning'],
            ['label' => 'Actions suivies', 'value' => $global['actions_total'] ?? 0, 'tone' => 'green', 'meta' => null, 'href' => route('workspace.actions.index', $officialFilters), 'badge' => null, 'badge_tone' => 'success'],
            ['label' => 'Alertes', 'value' => ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0), 'tone' => 'amber', 'meta' => null, 'href' => route('workspace.alertes', ['limit' => 100]), 'badge' => null, 'badge_tone' => 'danger'],
        ];
        $scopeLabel = $roleProfile['role_label'] ?? strtoupper((string) ($scope['role'] ?? 'lecture'));
        $generatedLabel = isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
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
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1C203D]"></span>{{ $scopeLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#8FC043]"></span>{{ $officialBaseText }}</span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ $dashboardAnalyticsUrl }}">Dashboard analytique</a>
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.excel') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h11l5 5v11H4V4zm11 0v5h5M8 13h8M8 17h8M8 9h3" />
                    </svg>
                    Export Excel
                </a>
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.pdf') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2zm7 0v5h5M8 13h2a2 2 0 010 4H8v-4zm6 0h2m-2 4h2" />
                    </svg>
                    Export PDF
                </a>
            </div>
        </div>
    </section>

    <div class="mb-4 flex flex-wrap justify-center gap-3">
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
                    <h2 class="showcase-panel-title">Indicateur de performance pilotes actifs</h2>
                </div>
            </div>
            <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                @foreach ($managedKpis as $metric)
                    <x-stat-card-link
                        :href="route('workspace.super-admin.kpis.edit')"
                        :label="$metric['label']"
                        :value="number_format((float) ($metric['value'] ?? 0), 1)"
                        :meta="collect([
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
                    <div class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm text-slate-700">
                        {{ $family }}
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ $dashboardAnalyticsUrl }}" class="btn btn-primary rounded-2xl px-4 py-2.5">Dashboard analytique</a>
                <a href="{{ route('workspace.alertes') }}" class="btn btn-secondary rounded-2xl px-4 py-2.5">Alertes</a>
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Direction -> service</h2>
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
                    <section class="rounded-[1.2rem] border border-slate-200/85 bg-slate-50/90 p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900">{{ $directionLabel }}</h3>
                                <p class="mt-1 text-xs text-slate-500">Responsable : {{ $direction['responsable'] ?? '-' }}</p>
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
                                <div class="rounded-[1rem] border border-white/80 bg-white/90 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="font-semibold text-slate-900">{{ $serviceLabel }}</p>
                                            <p class="text-xs text-slate-500">Responsable : {{ $service['responsable'] ?? '-' }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2 text-xs">
                                            <span class="anbg-badge anbg-badge-neutral px-3">{{ $serviceSummary['actions_total'] ?? 0 }} actions</span>
                                            <span class="anbg-badge anbg-badge-success px-3">{{ number_format((float) ($serviceSummary['taux_realisation'] ?? 0), 1) }}%</span>
                                            <span class="anbg-badge anbg-badge-info px-3">Indicateur de performance {{ number_format((float) ($serviceSummary['kpi_global'] ?? 0), 1) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-[1rem] border border-dashed border-slate-300/80 px-4 py-6 text-center text-sm text-slate-500">Aucun service actif rattaché.</div>
                            @endforelse
                        </div>
                    </section>
                @empty
                    <div class="rounded-[1.15rem] border border-dashed border-slate-300/80 px-4 py-8 text-center text-sm text-slate-500">Aucune donnée direction -> service disponible pour le périmètre courant.</div>
                @endforelse
            </div>
        </article>
    </div>
@endsection

