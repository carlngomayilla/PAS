@extends('layouts.workspace')

@section('title', 'Reporting consolide')

@push('head')
    <style>
        .reporting-hub-kpi{border-radius:1.2rem;border:1px solid rgba(59,130,246,.16);padding:1rem;background:linear-gradient(180deg,rgba(255,255,255,.99) 0%,rgba(239,246,255,.96) 100%);box-shadow:0 18px 34px -30px rgba(31,41,55,.35)}
        .reporting-hub-kpi .dashboard-summary-label{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#6B7280}
        .reporting-hub-kpi .dashboard-summary-value{color:#1F2937}
        .reporting-hub-kpi .dashboard-summary-meta{color:#6B7280}
        .reporting-hub-kpi-blue .dashboard-summary-value{color:#3B82F6}
        .reporting-hub-kpi-green .dashboard-summary-value{color:#10B981}
        .reporting-hub-kpi-amber .dashboard-summary-value{color:#F59E0B}
        .reporting-hub-kpi-navy .dashboard-summary-value{color:#1E3A8A}
        .dark .reporting-hub-kpi{border-color:rgba(59,130,246,.24);background:linear-gradient(180deg,rgba(15,23,42,.96) 0%,rgba(17,24,39,.9) 100%)}
        .dark .reporting-hub-kpi .dashboard-summary-label,.dark .reporting-hub-kpi .dashboard-summary-meta{color:#94A3B8}
        .dark .reporting-hub-kpi-navy .dashboard-summary-value,.dark .reporting-hub-kpi-blue .dashboard-summary-value,.dark .reporting-hub-kpi-green .dashboard-summary-value,.dark .reporting-hub-kpi-amber .dashboard-summary-value{color:#F8FAFC}
    </style>
@endpush

@section('content')
    @php
        $roleProfile = $roleProfile ?? ['eyebrow' => 'Reporting consolide', 'title' => 'Centre d export et de diffusion', 'subtitle' => 'Les graphes et les tableaux de reporting ont ete centralises dans le dashboard analytique et servent ici a l export et a la diffusion.', 'role_label' => strtoupper((string) ($scope['role'] ?? 'lecture'))];
        $dashboardAnalyticsUrl = route('dashboard').'?dashboardTab=analytics';
        $summaryCards = [
            ['label' => 'PAS scopes', 'value' => $global['pas_total'] ?? 0, 'tone' => 'navy', 'meta' => 'Strategie couverte', 'href' => route('workspace.pas.index'), 'badge' => 'Provisoire', 'badge_tone' => 'info'],
            ['label' => 'PAO scopes', 'value' => $global['paos_total'] ?? 0, 'tone' => 'blue', 'meta' => 'Declinaisons consolidees', 'href' => route('workspace.pao.index'), 'badge' => 'Valide', 'badge_tone' => 'warning'],
            ['label' => 'Actions consolidees', 'value' => $global['actions_total'] ?? 0, 'tone' => 'green', 'meta' => 'Execution analysee', 'href' => route('workspace.actions.index'), 'badge' => 'Officiel', 'badge_tone' => 'success'],
            ['label' => 'Alertes actives', 'value' => ($alertes['actions_en_retard'] ?? 0) + ($alertes['mesures_kpi_sous_seuil'] ?? 0), 'tone' => 'amber', 'meta' => 'Retards et indicateurs sous seuil', 'href' => route('workspace.alertes', ['limit' => 100]), 'badge' => 'Provisoire', 'badge_tone' => 'danger'],
        ];
        $scopeLabel = $roleProfile['role_label'] ?? strtoupper((string) ($scope['role'] ?? 'lecture'));
        $generatedLabel = isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $exportModules = [
            'Synthese graphique XLSX',
            'Workbook multi-feuilles avec graphiques natifs',
            'PDF consolide pagine avec sommaire',
            'Structures strategiques et indicateurs detaillees',
        ];
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
                <p class="showcase-subtitle mt-1">{{ $roleProfile['subtitle'] }}</p>
                <div class="showcase-chip-row">
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1E3A8A]"></span>{{ $scopeLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#3B82F6]"></span>Genere le {{ $generatedLabel }}</span>
                    <span class="showcase-chip"><span class="showcase-chip-dot bg-[#F59E0B]"></span>{{ $global['actions_total'] ?? 0 }} actions analysees</span>
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

    <div class="mb-4 flex flex-wrap gap-2">
        <span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span>
        <span class="anbg-badge anbg-badge-warning px-3 py-1">Valide</span>
        <span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span>
    </div>

    @if (($roleProfile['role'] ?? null) === 'dg' && is_array($dgComparison ?? null))
        <section class="showcase-panel mb-4">
            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Lecture DG : operationnel vs officiel</h2>
                    <p class="showcase-panel-subtitle">Le reporting DG met en regard le portefeuille total et le socle officiel valide direction pour eviter les lectures artificiellement optimistes.</p>
                </div>
                <span class="showcase-chip">DG</span>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="showcase-panel-title !text-base">Statistiques operationnelles</h3>
                            <p class="showcase-panel-subtitle">Portefeuille total visible avant consolidation finale.</p>
                        </div>
                        <span class="anbg-badge anbg-badge-info px-3 py-1">Provisoire</span>
                    </div>
                    <div class="showcase-summary-grid">
                        <x-stat-card-link
                            :href="route('workspace.actions.index')"
                            label="Execution operationnelle"
                            :value="number_format((float) ($dgComparison['operational']['completion_rate'] ?? 0), 0).'%'" 
                            meta="Achevees sur tout le portefeuille"
                            badge="Provisoire"
                            badge-tone="info"
                        />
                        <x-stat-card-link
                            :href="route('workspace.reporting')"
                            label="Score operationnel"
                            :value="number_format((float) ($dgComparison['operational']['score'] ?? 0), 0)"
                            meta="Moyenne sur toutes les actions visibles"
                            badge="Provisoire"
                            badge-tone="info"
                        />
                    </div>
                </div>

                <div>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="showcase-panel-title !text-base">Statistiques officielles</h3>
                            <p class="showcase-panel-subtitle">Socle valide direction pret pour diffusion et arbitrage.</p>
                        </div>
                        <span class="anbg-badge anbg-badge-success px-3 py-1">Officiel</span>
                    </div>
                    <div class="showcase-summary-grid">
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, 'statut' => 'achevees'])"
                            label="Execution officielle"
                            :value="number_format((float) ($dgComparison['official']['completion_rate'] ?? 0), 0).'%'" 
                            meta="Achevees sur actions validees direction"
                            badge="Officiel"
                            badge-tone="success"
                        />
                        <x-stat-card-link
                            :href="route('workspace.actions.index', ['statut_validation' => \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION, 'sort' => 'kpi_global_desc'])"
                            label="Score officiel"
                            :value="number_format((float) ($dgComparison['official']['score'] ?? 0), 0)"
                            meta="Moyenne validee direction"
                            badge="Officiel"
                            badge-tone="success"
                        />
                    </div>
                </div>
            </div>
        </section>

        <section class="showcase-panel mb-4">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Directions : operationnel vs officiel</h2>
                    <p class="showcase-panel-subtitle">Comparer rapidement le niveau de couverture officielle par direction avant diffusion ou arbitrage.</p>
                </div>
                <span class="showcase-chip">{{ count($dgComparison['direction_rows'] ?? []) }} directions</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Direction</th>
                            <th>Actions</th>
                            <th>Officiel</th>
                            <th>Exec. op.</th>
                            <th>Exec. off.</th>
                            <th>Score op.</th>
                            <th>Score off.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($dgComparison['direction_rows'] ?? []) as $row)
                            <tr>
                                <td>{{ $row['direction'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td>{{ $row['actions_officielles'] }}</td>
                                <td>{{ number_format((float) ($row['taux_execution_operationnel'] ?? 0), 2) }}%</td>
                                <td>{{ number_format((float) ($row['taux_execution_officiel'] ?? 0), 2) }}%</td>
                                <td>{{ number_format((float) ($row['score_operationnel'] ?? 0), 2) }}</td>
                                <td>{{ number_format((float) ($row['score_officiel'] ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-slate-600">Aucune comparaison disponible.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

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
                <a href="{{ $dashboardAnalyticsUrl }}" class="btn btn-primary rounded-2xl px-4 py-2.5">Ouvrir le dashboard analytique</a>
                <a href="{{ route('workspace.alertes') }}" class="btn btn-secondary rounded-2xl px-4 py-2.5">Voir le centre d alertes</a>
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
                    <div class="rounded-[1.15rem] border border-[#3B82F6]/18 bg-[#EFF6FF]/80 px-4 py-3 text-sm font-medium text-slate-700 dark:border-[#3B82F6]/26 dark:bg-slate-900/70 dark:text-slate-200">
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
