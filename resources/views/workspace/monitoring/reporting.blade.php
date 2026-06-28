@extends('layouts.workspace')

@section('title', 'Reporting')

@push('head')
    <style>
        .reporting-pta-official .pta-suivi-table-wrap { width:100%; overflow-x:auto; }
        .reporting-pta-official .pta-suivi-table { width:100%; min-width:1620px; border-collapse:collapse; table-layout:fixed; font-size:12px; }
        .reporting-pta-official .pta-suivi-table th,
        .reporting-pta-official .pta-suivi-table td { border:1px solid #111; padding:6px; vertical-align:middle; overflow-wrap:anywhere; }
        .reporting-pta-official .pta-suivi-table th { background:#d9d9d9; color:#000; text-align:center; font-weight:900; }
        .reporting-pta-official .pta-pas-row td { background:#2f75b5; color:#fff; font-weight:900; text-align:center; }
        .reporting-pta-official .pta-strategy-row td { background:#5b9bd5; color:#000; font-weight:900; text-align:center; }
        .reporting-pta-official .pta-strategy-rate { background:#ddebf7 !important; }
        .reporting-pta-official .pta-objective-row td { background:#ddebf7; font-weight:900; text-align:center; }
        .reporting-pta-official .pta-objective-number { width:42px; background:#fff !important; }
        .reporting-pta-official .pta-center,
        .reporting-pta-official .pta-status-cell { text-align:center; }
        .reporting-pta-official .pta-status-cell { font-weight:900; line-height:1.15; }
        .reporting-pta-official .pta-action-link { display:inline; border:0; padding:0; background:transparent; color:#17324a; font:inherit; font-weight:800; text-decoration:underline; cursor:pointer; text-align:left; }
        .reporting-pta-official .pta-observation { font-size:11px; line-height:1.35; }
        .reporting-pta-official .pta-empty { padding:18px; text-align:center; font-weight:800; color:#64748b; }
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
        // Cartes synthèse PAS / PAO / Actions / Alertes retirées du module Reporting
        // (non alignées avec la nouvelle logique métier — accessibles via leur module dédié).
        $summaryCards = [];
        $scopeLabel = $roleProfile['role_label'] ?? strtoupper((string) ($scope['role'] ?? 'lecture'));
        $generatedLabel = isset($generatedAt) && $generatedAt instanceof \Illuminate\Support\Carbon ? $generatedAt->format('d/m/Y H:i') : now()->format('d/m/Y H:i');
        $managedKpis = collect($managedKpis ?? [])->take(6)->values();
        $analyticsFamilies = [
            'Cockpit indicateurs et tendances',
            'Entonnoir PAS / PAO / PTA / Actions',
            'Heatmap des retards',
            'Gantt critique et jauges de performance',
            'Tables de consolidation PAS et comparaisons interannuelles',
        ];
        $reportTypes = collect($reportTypes ?? []);
        $activeReportType = (string) ($activeReportType ?? request('report_type', 'consolide_dg'));
        $reportFilterOptions = (array) ($reportFilterOptions ?? []);
        $selectedReportPeriod = app(\App\Services\PtaSuiviService::class)->normalizePeriod(request('periode', request('trimestre', 'all')));
        $ptaSuiviPayload = is_array($ptaSuiviPayload ?? null) ? $ptaSuiviPayload : [];
        $ptaSuiviSummary = (array) ($ptaSuiviPayload['summary'] ?? []);
        $reportQuery = collect(request()->query())
            ->only(['report_type', 'exercice', 'periode', 'trimestre', 'direction_id', 'service_id', 'statut', 'type_action', 'responsable_id', 'criticite', 'periode_debut', 'periode_fin'])
            ->filter(fn ($value): bool => trim((string) $value) !== '' && trim((string) $value) !== 'all')
            ->all();
    @endphp

    <x-ui.page-title
        class="mb-4"
        :eyebrow="$roleProfile['eyebrow']"
        :title="$roleProfile['title']"
        :subtitle="$roleProfile['subtitle'] ?? null"
    >
        <x-slot:actions>
            <span class="showcase-chip"><span class="showcase-chip-dot bg-[#1C203D]"></span>{{ $scopeLabel }}</span>
            <span class="showcase-chip"><span class="showcase-chip-dot bg-[#8FC043]"></span>{{ $officialBaseText }}</span>
            <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ $dashboardAnalyticsUrl }}">Tableau de bord analytique</a>
            <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.excel', $reportQuery) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h11l5 5v11H4V4zm11 0v5h5M8 13h8M8 17h8M8 9h3" />
                </svg>
                Export Excel
            </a>
            <a class="btn btn-primary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting.export.pdf', $reportQuery) }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 3h7l5 5v13H7a2 2 0 01-2-2V5a2 2 0 012-2zm7 0v5h5M8 13h2a2 2 0 010 4H8v-4zm6 0h2m-2 4h2" />
                </svg>
                Export PDF
            </a>
        </x-slot:actions>
    </x-ui.page-title>

    <section class="showcase-panel mb-4">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="showcase-panel-title">Rapports métiers</h2>
            </div>
            <span class="anbg-badge anbg-badge-info px-3 py-1">PDF + Excel</span>
        </div>

        <div class="mb-4 grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            @foreach ($reportTypes as $code => $report)
                @php
                    $isActiveReport = $activeReportType === (string) $code;
                    $reportHref = route('workspace.reporting', array_merge($reportQuery, ['report_type' => (string) $code]));
                @endphp
                <a href="{{ $reportHref }}" class="rounded-[1.1rem] border px-4 py-3 text-sm transition {{ $isActiveReport ? 'border-[#3996d3] bg-[#e8f3fb] text-[#17324a]' : 'border-slate-200 bg-white text-slate-700 hover:border-[#3996d3]/60' }}">
                    <strong class="block text-[0.92rem]">{{ $report['label'] ?? $code }}</strong>
                    <span class="mt-1 block text-xs leading-relaxed text-slate-500">{{ $report['description'] ?? '' }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" action="{{ route('workspace.reporting') }}" class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
            <input type="hidden" name="report_type" value="{{ $activeReportType }}">
            <div>
                <label for="exercice">Exercice</label>
                <select id="exercice" name="exercice">
                    @foreach (($reportFilterOptions['exercices'] ?? []) as $option)
                        <option value="{{ $option['value'] }}" @selected((string) request('exercice', '') === (string) $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="periode">Periode</label>
                <select id="periode" name="periode">
                    @foreach (($reportFilterOptions['periodes'] ?? $reportFilterOptions['trimestres'] ?? []) as $option)
                        <option value="{{ $option['value'] }}" @selected((string) $selectedReportPeriod === (string) $option['value'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="direction_id">Direction</label>
                <select id="direction_id" name="direction_id">
                    <option value="all">Toutes</option>
                    @foreach (($reportFilterOptions['directions'] ?? []) as $direction)
                        <option value="{{ $direction['id'] }}" @selected((int) request('direction_id') === (int) $direction['id'])>{{ $direction['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="service_id">Service / unité</label>
                <select id="service_id" name="service_id">
                    <option value="all">Tous</option>
                    @foreach (($reportFilterOptions['services'] ?? []) as $service)
                        <option value="{{ $service['id'] }}" @selected((int) request('service_id') === (int) $service['id'])>{{ $service['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="statut">Statut</label>
                <select id="statut" name="statut">
                    <option value="all">Tous</option>
                    @foreach (($reportFilterOptions['statuses'] ?? []) as $value => $label)
                        <option value="{{ $value }}" @selected((string) request('statut') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type_action">Type d'action</label>
                <select id="type_action" name="type_action">
                    <option value="all">Tous</option>
                    @foreach (($reportFilterOptions['types_action'] ?? []) as $value => $label)
                        <option value="{{ $value }}" @selected((string) request('type_action') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="responsable_id">Responsable</label>
                <select id="responsable_id" name="responsable_id">
                    <option value="all">Tous</option>
                    @foreach (($reportFilterOptions['responsables'] ?? []) as $responsable)
                        <option value="{{ $responsable['id'] }}" @selected((int) request('responsable_id') === (int) $responsable['id'])>{{ $responsable['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="criticite">Criticité</label>
                <select id="criticite" name="criticite">
                    <option value="all">Toutes</option>
                    @foreach (($reportFilterOptions['criticites'] ?? []) as $value => $label)
                        <option value="{{ $value }}" @selected((string) request('criticite') === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="periode_debut">Début</label>
                <input id="periode_debut" name="periode_debut" type="date" value="{{ request('periode_debut') }}">
            </div>
            <div>
                <label for="periode_fin">Fin</label>
                <input id="periode_fin" name="periode_fin" type="date" value="{{ request('periode_fin') }}">
            </div>
            <div class="flex items-end gap-2">
                <button class="btn btn-primary w-full rounded-2xl px-4 py-2.5" type="submit">Filtrer</button>
                <a class="btn btn-secondary rounded-2xl px-4 py-2.5" href="{{ route('workspace.reporting', ['report_type' => $activeReportType]) }}">Réinitialiser</a>
            </div>
        </form>
    </section>

    {{-- Bandeau cartes synthèse PAS / PAO / Actions / Alertes retiré du Reporting. --}}
    {{-- Bloc « Performances d'exécution pilotes actives » retiré du Reporting. --}}
    {{-- Bloc « Analytique disponible » retiré du Reporting. --}}

    <div class="grid gap-4">

        @if ($activeReportType === 'pta')
            <article class="showcase-panel reporting-pta-official overflow-hidden p-0">
                <div class="border-b border-slate-200 bg-white px-4 py-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="showcase-panel-title">Suivi PTA officiel</h2>
                            <p class="mt-1 text-xs font-semibold text-slate-500">{{ $ptaSuiviPayload['scopeLabel'] ?? 'Perimetre PTA' }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span class="anbg-badge anbg-badge-neutral px-3">{{ $ptaSuiviSummary['actions'] ?? 0 }} actions</span>
                            <span class="anbg-badge anbg-badge-info px-3">{{ number_format((float) ($ptaSuiviSummary['performance'] ?? 0), 0) }}% performance</span>
                            <span class="anbg-badge anbg-badge-warning px-3">{{ $ptaSuiviSummary['en_retard'] ?? 0 }} retards</span>
                        </div>
                    </div>
                </div>
                <x-tables.pta-suivi-table :groups="$ptaSuiviPayload['groups'] ?? []" export-mode="readonly" />
            </article>
        @endif

        @if ($activeReportType !== 'pta')
        <article class="showcase-panel">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="showcase-panel-title">Direction / service</h2>
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
                                <span class="anbg-badge anbg-badge-success px-3">{{ number_format((float) ($directionSummary['taux_realisation'] ?? 0), 0) }}% réalisation</span>
                                <span class="anbg-badge anbg-badge-warning px-3">{{ number_format((float) ($directionSummary['taux_retard'] ?? 0), 0) }}% retard</span>
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
                                            <span class="anbg-badge anbg-badge-success px-3">{{ number_format((float) ($serviceSummary['taux_realisation'] ?? 0), 0) }}%</span>
                                            <span class="anbg-badge anbg-badge-info px-3">Performance d'exécution {{ number_format((float) ($serviceSummary['kpi_global'] ?? 0), 0) }}</span>
                                        </div>
                                    </div>
                                </div>
                            @empty
                                <x-ui.empty-state
                                    title="Aucun service actif rattaché"
                                    message="Aucun service actif n'est disponible pour cette direction."
                                    icon="users"
                                    tone="info"
                                    class="py-6"
                                />
                            @endforelse
                        </div>
                    </section>
                @empty
                    <x-ui.empty-state
                        title="Aucune donnée direction / service"
                        message="Aucune information n'est disponible pour le périmètre courant."
                        icon="filter"
                        tone="info"
                    />
                @endforelse
            </div>
        </article>
        @endif
    </div>
@endsection
