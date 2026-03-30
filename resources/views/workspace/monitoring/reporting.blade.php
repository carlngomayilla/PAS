@extends('layouts.workspace')

@section('title', 'Reporting consolide')

@push('head')
    <style>
        .reporting-hub-kpi{border-radius:1.2rem;border:1px solid rgba(57,150,211,.14);padding:1rem;background:linear-gradient(180deg,rgba(255,255,255,.98) 0%,rgba(248,250,252,.94) 100%);box-shadow:0 18px 34px -30px rgba(15,23,42,.45)}
        .reporting-hub-kpi .dashboard-summary-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#64748B}
        .reporting-hub-kpi .dashboard-summary-value{color:#1C203D}
        .reporting-hub-kpi .dashboard-summary-meta{color:#64748B}
        .reporting-hub-kpi-blue .dashboard-summary-value{color:#3996D3}
        .reporting-hub-kpi-green .dashboard-summary-value{color:#8FC043}
        .reporting-hub-kpi-amber .dashboard-summary-value{color:#F9B13C}
        .reporting-hub-kpi-navy .dashboard-summary-value{color:#1C203D}
        .dark .reporting-hub-kpi{border-color:rgba(57,150,211,.18);background:linear-gradient(180deg,rgba(15,23,42,.96) 0%,rgba(15,23,42,.88) 100%)}
        .dark .reporting-hub-kpi .dashboard-summary-label,.dark .reporting-hub-kpi .dashboard-summary-meta{color:#94A3B8}
        .dark .reporting-hub-kpi-navy .dashboard-summary-value,.dark .reporting-hub-kpi-blue .dashboard-summary-value,.dark .reporting-hub-kpi-green .dashboard-summary-value,.dark .reporting-hub-kpi-amber .dashboard-summary-value{color:#F8FAFC}
    </style>
@endpush

@section('content')
    @php
        $dashboardAnalyticsUrl = route('dashboard').'?dashboardTab=analytics';
        $summaryCards = [
            ['label' => 'PAS scopes', 'value' => $global['pas_total'] ?? 0, 'tone' => 'navy', 'meta' => 'Strategie couverte'],
            ['label' => 'PAO scopes', 'value' => $global['paos_total'] ?? 0, 'tone' => 'blue', 'meta' => 'Declinaisons consolidees'],
            ['label' => 'Actions consolidees', 'value' => $global['actions_total'] ?? 0, 'tone' => 'green', 'meta' => 'Execution analysee'],
            ['label' => 'Alertes actives', 'value' => ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0), 'tone' => 'amber', 'meta' => 'Retards et KPI sous seuil'],
        ];
        $scopeLabel = strtoupper((string) ($scope['role'] ?? 'lecture'));
        $generatedLabel = isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $exportModules = [
            'Synthese graphique XLSX',
            'Workbook multi-feuilles avec graphiques natifs',
            'PDF consolide pagine avec sommaire',
            'Structures strategiques et indicateurs detaillees',
        ];
        $analyticsFamilies = [
            'Cockpit KPI et tendances',
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
                <span class="showcase-eyebrow">Reporting consolide</span>
                <h1 class="showcase-title">Centre d export et de diffusion</h1>
                <p class="showcase-subtitle mt-1">Les graphes et les tableaux de reporting ont ete centralises dans l onglet analytique avance du dashboard. Cet ecran sert maintenant a lancer les exports et a distribuer les vues consolidees.</p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3996D3]"></span>{{ $scopeLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#8FC043]"></span>Genere le {{ $generatedLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F0E509]"></span>{{ $global['actions_total'] ?? 0 }} actions analysees</span>
                </div>
            </div>
            <div class="showcase-action-row">
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.pilotage') }}">Pilotage global</a>
                <a class="btn btn-blue rounded-2xl px-4 py-2.5" href="{{ $dashboardAnalyticsUrl }}">Dashboard analytique</a>
                <a class="btn btn-green rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.excel') }}">Exporter Excel (.xlsx)</a>
                <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.pdf') }}">Exporter PDF</a>
            </div>
        </div>
    </section>

    <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
        @foreach ($summaryCards as $card)
            <article class="reporting-hub-kpi reporting-hub-kpi-{{ $card['tone'] }}">
                <p class="dashboard-summary-label">{{ $card['label'] }}</p>
                <p class="dashboard-summary-value mt-3 text-[2rem] font-black leading-none">{{ $card['value'] }}</p>
                <p class="dashboard-summary-meta mt-2 text-xs">{{ $card['meta'] }}</p>
            </article>
        @endforeach
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.1fr_0.9fr]">
        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Le reporting detaille est dans le dashboard</h2>
                    <p class="showcase-panel-subtitle">Le dashboard devient le point unique de lecture, d arbitrage et d analyse avancee.</p>
                </div>
                <span class="showcase-chip">Onglet analytique avancee</span>
            </div>

            <div class="grid gap-3">
                @foreach ($analyticsFamilies as $family)
                    <div class="rounded-[1.15rem] border border-slate-200/85 bg-slate-50/90 px-4 py-3 text-sm text-slate-700 dark:border-slate-800 dark:bg-slate-900/70 dark:text-slate-200">
                        {{ $family }}
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ $dashboardAnalyticsUrl }}" class="inline-flex items-center justify-center rounded-2xl bg-[#1C203D] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#3996D3]">Ouvrir le dashboard analytique</a>
                <a href="{{ route('workspace.alertes') }}" class="inline-flex items-center justify-center rounded-2xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-slate-900 ring-1 ring-slate-200 transition hover:bg-white dark:bg-slate-900/70 dark:text-slate-100 dark:ring-slate-700">Voir le centre d alertes</a>
            </div>
        </article>

        <article class="showcase-panel">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Paquet d export disponible</h2>
                    <p class="showcase-panel-subtitle">Chaque export reprend la meme base analytique que le dashboard avance.</p>
                </div>
                <span class="showcase-chip">2 formats</span>
            </div>

            <div class="grid gap-3">
                @foreach ($exportModules as $module)
                    <div class="rounded-[1.15rem] border border-[#3996D3]/16 bg-[#E8F3FB]/60 px-4 py-3 text-sm font-medium text-slate-700 dark:border-[#3996D3]/24 dark:bg-slate-900/70 dark:text-slate-200">
                        {{ $module }}
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-[1.25rem] border border-dashed border-slate-300/80 bg-slate-50/80 px-4 py-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900/70 dark:text-slate-300">
                Le XLSX embarque plusieurs feuilles et des graphiques Excel natifs. Le PDF embarque un sommaire, une pagination par section et la synthese graphique.
            </div>
        </article>
    </div>
@endsection
