<section class="dashboard-tab-panel active" data-dashboard-panel="advanced" data-preview-disabled="1">
    <div class="space-y-2">
        @if (false)
        @if ($showRoleOverview)
            @include('partials.dashboard-role-overview', [
                'roleDashboard' => $roleDashboard,
                'dashboardRole' => $dashboardRole,
                'statisticalPolicy' => $statisticalPolicy,
                'officialPolicy' => $officialPolicy,
                'displayMode' => 'tables',
            ])
        @endif

        @include('partials.dashboard-analytics._panel-synthesis-tables')

        <details class="showcase-panel overflow-hidden p-0" open>
            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 text-[#3996d3]">▸</span>
                    <h2 class="showcase-panel-title">Synthèse {{ strtolower($unitModeLabel) }}</h2>
                </div>
                <span class="showcase-chip">{{ count($unitRows) }} lignes</span>
            </summary>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead><tr><th>{{ $unitModeLabel }}</th><th>Actions</th><th>Progression</th><th>Indicateur moyen</th><th>Alertes</th><th>Validation</th></tr></thead>
                    <tbody>
                        @forelse ($unitRows as $row)
                            @php
                                $progress = (float) ($row['progression_moyenne'] ?? 0);
                                $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F9B13C' : '#94A3B8'));
                                $kpi = (float) ($row['kpi_global'] ?? 0);
                            @endphp
                            <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                <td class="font-semibold text-[#17324a]">{{ $row['label'] }}</td>
                                <td>{{ $row['actions_total'] }}</td>
                                <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpi)) }}">{{ number_format($kpi, 0) }}</span></td>
                                <td>@if (($row['alertes'] ?? 0) > 0)<span class="dashboard-pill" style="{{ $dashboardPillVars('danger') }}">{{ $row['alertes'] }}</span>@else<span class="dashboard-pill" style="{{ $dashboardPillVars('success') }}">0</span>@endif</td>
                                <td>{{ number_format((float) ($row['validation_pct'] ?? 0), 0) }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune donnée" message="Aucune donnée disponible." icon="file" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>
        @endif

        @php
            $ptaSummary = (array) ($ptaQuarterlyAnalysis['summary'] ?? []);
            $ptaAxes = collect($ptaQuarterlyAnalysis['axes'] ?? [])->values();
            $ptaServices = collect($ptaQuarterlyAnalysis['services'] ?? [])->values();
            $ptaComparison = collect($ptaQuarterlyAnalysis['indicator_comparison'] ?? [])->values();
            $ptaMonthly = collect($ptaQuarterlyAnalysis['monthly'] ?? [])->values();
            $ptaAxisMonthly = collect($ptaQuarterlyAnalysis['axis_monthly'] ?? [])->values();
            $ptaGaps = (array) ($ptaQuarterlyAnalysis['gaps'] ?? []);
            $ptaUnrealized = collect($ptaGaps['unrealized'] ?? [])->values();
            $ptaPartial = collect($ptaGaps['partial'] ?? [])->values();
            $ptaPostponed = collect($ptaGaps['postponed'] ?? [])->values();
            $ptaNotStarted = $ptaUnrealized
                ->filter(fn (array $row): bool => (float) ($row['progression'] ?? 0) <= 0)
                ->values();
            $ptaMeasures = collect($ptaQuarterlyAnalysis['corrective_measures'] ?? [])->values();
            $ptaFindings = (array) ($ptaQuarterlyAnalysis['findings'] ?? []);
            $showPtaQuarterlyTables = ((int) ($ptaSummary['planned_actions'] ?? 0)) > 0
                || $ptaAxes->isNotEmpty()
                || $ptaServices->isNotEmpty()
                || $ptaUnrealized->isNotEmpty()
                || $ptaPartial->isNotEmpty()
                || $ptaPostponed->isNotEmpty();
        @endphp

        @if ($showPtaQuarterlyTables)
            <details class="showcase-panel overflow-hidden p-0" open>
                <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-3 text-[#3996d3]">▸</span>
                        <h2 class="showcase-panel-title">PTA trimestriel</h2>
                    </div>
                    <span class="showcase-chip">{{ $ptaQuarterlyAnalysis['period']['label'] ?? 'Période courante' }}</span>
                </summary>

                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200/80 p-3">
                    <p class="max-w-3xl text-sm text-[#667085]">Analyse consolidée du périmètre filtré, avec indicateurs, écarts et mesures correctives.</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <a class="btn btn-secondary btn-sm" href="{{ request()->fullUrl() }}">Actualiser</a>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm"
                            data-dashboard-export-table="dashboard-pta-axes-table"
                            data-dashboard-export-name="pta-axes-{{ now()->format('Ymd-His') }}"
                        >Excel</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-dashboard-print>Imprimer</button>
                    </div>
                </div>

                <div class="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([
                        'Actions prévues' => $ptaSummary['planned_actions'] ?? 0,
                        'Actions réalisées' => $ptaSummary['completed_actions'] ?? 0,
                        'Actions échues' => $ptaSummary['due_actions'] ?? 0,
                        'En retard / non réalisées' => $ptaSummary['late_or_unrealized_actions'] ?? 0,
                        'Non démarrées' => $ptaSummary['not_started_actions'] ?? 0,
                        'Taux PTA' => number_format((float) ($ptaSummary['realization_rate'] ?? 0), 0, ',', ' ').'%',
                    ] as $label => $value)
                        <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                            <p class="text-xs font-bold uppercase text-[#667085]">{{ $label }}</p>
                            <p class="mt-1 text-xl font-black text-[#17324a]">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="app-table-wrapper overflow-x-auto border-t border-slate-200/80">
                    <table class="app-table data-table">
                        <caption class="px-3 py-2 text-left text-sm font-black text-[#17324a]">Comparaison des deux indicateurs</caption>
                        <thead><tr><th>Indicateur</th><th>Réalisé</th><th>Base</th><th>Taux</th><th>Formule</th><th>Interprétation</th></tr></thead>
                        <tbody>
                            @foreach ($ptaComparison as $row)
                                <tr><td class="font-semibold text-[#17324a]">{{ $row['indicateur'] ?? '-' }}</td><td>{{ $row['realisees'] ?? 0 }}</td><td>{{ $row['base'] ?? 0 }}</td><td>{{ number_format((float) ($row['taux'] ?? 0), 1, ',', ' ') }}%</td><td>{{ $row['formule'] ?? '-' }}</td><td>{{ $row['interpretation'] ?? '-' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Graphiques du PTA trimestriel deplaces vers l'onglet « Graphiques »
                     (partials/dashboard-analytics/_panel-charts.blade.php) : la Vue detaillee
                     ne conserve que les tableaux. --}}

                <div class="app-table-wrapper overflow-x-auto">
                    <table
                        id="dashboard-pta-axes-table"
                        class="app-table data-table"
                        data-table-enhanced
                        data-table-page-size="10"
                        data-table-name="Axes stratégiques"
                        data-table-label-singular="axe"
                        data-table-label-plural="axes"
                        data-mobile-cards
                    >
                        <thead>
                            <tr><th>Axe</th><th data-sort-type="number" data-num data-mobile-hidden>Prévues</th><th data-sort-type="number" data-num data-mobile-hidden>Réalisées</th><th data-sort-type="number" data-num data-mobile-hidden>En cours</th><th data-sort-type="number" data-num>En retard/non réalisées</th><th data-sort-type="number" data-num data-mobile-hidden>Non démarrées</th><th data-sort-type="number" data-num data-mobile-hidden>Échues</th><th data-sort-type="number" data-num>Avancement</th><th data-sort-type="number" data-num>Réalisation échues</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($ptaAxes as $row)
                                <tr>
                                    <td class="font-semibold text-[#17324a]">{{ trim(($row['code'] ?? '').' '.($row['libelle'] ?? '')) }}</td>
                                    <td data-num data-mobile-hidden>{{ $row['planned_actions'] ?? 0 }}</td>
                                    <td data-num data-mobile-hidden>{{ $row['completed_actions'] ?? 0 }}</td>
                                    <td data-num data-mobile-hidden>{{ $row['in_progress_actions'] ?? 0 }}</td>
                                    <td data-num>{{ $row['late_or_unrealized_actions'] ?? 0 }}</td>
                                    <td data-num data-mobile-hidden>{{ $row['not_started_actions'] ?? 0 }}</td>
                                    <td data-num data-mobile-hidden>{{ $row['due_actions'] ?? 0 }}</td>
                                    <td data-num data-sort-value="{{ (float) ($row['progress_rate'] ?? 0) }}"><x-pta.progress-bar :value="(float) ($row['progress_rate'] ?? 0)" :label="number_format((float) ($row['progress_rate'] ?? 0), 1, ',', ' ').'%'" /></td>
                                    <td data-num data-sort-value="{{ (float) ($row['realization_rate'] ?? 0) }}"><x-pta.progress-bar :value="(float) ($row['realization_rate'] ?? 0)" :label="number_format((float) ($row['realization_rate'] ?? 0), 1, ',', ' ').'%'" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="9"><x-ui.empty-state title="Aucun axe" message="Aucun axe PTA disponible sur ce périmètre." icon="chart" /></td></tr>
                            @endforelse
                        </tbody>
                        <tfoot><tr class="font-black"><td>Total général</td><td data-num>{{ $ptaSummary['planned_actions'] ?? 0 }}</td><td data-num>{{ $ptaSummary['completed_actions'] ?? 0 }}</td><td data-num>{{ $ptaSummary['in_progress_actions'] ?? 0 }}</td><td data-num>{{ $ptaSummary['late_or_unrealized_actions'] ?? 0 }}</td><td data-num>{{ $ptaSummary['not_started_actions'] ?? 0 }}</td><td data-num>{{ $ptaSummary['due_actions'] ?? 0 }}</td><td data-num>{{ number_format((float) ($ptaSummary['progress_rate'] ?? 0), 1, ',', ' ') }}%</td><td data-num>{{ number_format((float) ($ptaSummary['realization_rate'] ?? 0), 1, ',', ' ') }}%</td></tr></tfoot>
                    </table>
                </div>

                @if ($ptaServices->isNotEmpty())
                    <div class="app-table-wrapper overflow-x-auto border-t border-slate-200/80">
                        <table
                            class="app-table data-table"
                            data-table-enhanced
                            data-table-page-size="10"
                            data-table-name="Directions et services"
                            data-table-label-singular="service"
                            data-table-label-plural="services"
                            data-mobile-cards
                        >
                            <thead>
                                <tr><th>Service</th><th data-mobile-hidden>Direction</th><th data-mobile-hidden>Prévues</th><th data-mobile-hidden>Réalisées</th><th data-mobile-hidden>Échues</th><th>Taux PTA</th><th>Niveau</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($ptaServices as $row)
                                    <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                        <td class="font-semibold text-[#17324a]">{{ $row['libelle'] ?? '-' }}</td>
                                        <td data-mobile-hidden>{{ $row['direction'] ?? '-' }}</td>
                                        <td data-num data-mobile-hidden>{{ $row['planned_actions'] ?? 0 }}</td>
                                        <td data-num data-mobile-hidden>{{ $row['completed_actions'] ?? 0 }}</td>
                                        <td data-num data-mobile-hidden>{{ $row['due_actions'] ?? 0 }}</td>
                                        <td data-num data-sort-value="{{ (float) ($row['realization_rate'] ?? 0) }}"><x-pta.progress-bar :value="(float) ($row['realization_rate'] ?? 0)" :label="number_format((float) ($row['realization_rate'] ?? 0), 1, ',', ' ').'%'" /></td>
                                        <td>{{ $row['performance_level'] ?? 'Critique' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($ptaMonthly->isNotEmpty())
                    <div class="grid gap-3 border-t border-slate-200/80 p-3 xl:grid-cols-2">
                        <div class="app-table-wrapper overflow-x-auto">
                            <table class="app-table data-table">
                                <caption class="pb-2 text-left text-sm font-black text-[#17324a]">Évolution mensuelle globale cumulée</caption>
                                <thead><tr><th>Mois</th><th>Échues</th><th>Réalisées</th><th>Taux</th><th>Variation</th><th>Tendance</th></tr></thead>
                                <tbody>@foreach ($ptaMonthly as $row)<tr><td>{{ $row['label'] }}</td><td>{{ $row['due_actions'] }}</td><td>{{ $row['completed_actions'] }}</td><td>{{ number_format((float) $row['realization_rate'], 1, ',', ' ') }}%</td><td>{{ ($row['variation'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($row['variation'] ?? 0), 1, ',', ' ') }} pt</td><td>{{ $row['trend'] ?? 'Stagnation' }}</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                        <div class="app-table-wrapper overflow-x-auto">
                            <table class="app-table data-table">
                                <caption class="pb-2 text-left text-sm font-black text-[#17324a]">Évolution mensuelle par axe</caption>
                                <thead><tr><th>Axe</th>@foreach (($ptaAxisMonthly->first()['mois'] ?? []) as $month)<th>{{ $month['mois'] }}</th>@endforeach<th>Évolution</th></tr></thead>
                                <tbody>@foreach ($ptaAxisMonthly as $row)<tr><td>{{ trim(($row['code'] ?? '').' '.($row['axe'] ?? '')) }}</td>@foreach (($row['mois'] ?? []) as $month)<td>{{ number_format((float) ($month['taux'] ?? 0), 1, ',', ' ') }}%</td>@endforeach<td>{{ ($row['evolution'] ?? 0) > 0 ? '+' : '' }}{{ number_format((float) ($row['evolution'] ?? 0), 1, ',', ' ') }} pt</td></tr>@endforeach</tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @php
                    $ptaGapSections = collect([
                        ['title' => 'Actions en retard ou non réalisées', 'rows' => $ptaUnrealized, 'label' => 'En retard', 'tone' => 'danger'],
                        ['title' => 'Actions non démarrées', 'rows' => $ptaNotStarted, 'label' => 'Non démarrée', 'tone' => 'muted'],
                        ['title' => 'Actions partiellement réalisées', 'rows' => $ptaPartial, 'label' => 'Partielle', 'tone' => 'warning'],
                        ['title' => 'Actions reportées', 'rows' => $ptaPostponed, 'label' => 'Reportée', 'tone' => 'info'],
                    ])->filter(fn (array $section): bool => $section['rows']->isNotEmpty())->values();
                @endphp

                @if ($ptaGapSections->isNotEmpty() || $ptaMeasures->isNotEmpty())
                    <div class="grid gap-4 border-t border-slate-200/80 p-3">
                        @foreach ($ptaGapSections as $sectionIndex => $gapSection)
                            <section>
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <h3 class="text-base font-black text-[#17324a]">{{ $gapSection['title'] }}</h3>
                                        <p class="text-xs text-[#667085]">{{ $gapSection['rows']->count() }} action(s) dans le périmètre filtré.</p>
                                    </div>
                                </div>
                                <div class="app-table-wrapper overflow-x-auto">
                                    <table
                                        class="app-table data-table"
                                        data-table-enhanced
                                        data-table-page-size="10"
                                        data-table-name="{{ $gapSection['title'] }}"
                                        data-table-label-singular="action"
                                        data-table-label-plural="actions"
                                        data-mobile-cards
                                    >
                                        <thead><tr><th>Action</th><th data-mobile-hidden>Axe</th><th data-mobile-hidden>Responsable</th><th data-sort-type="date">Échéance</th><th data-sort-type="number" data-num>Progression</th><th>Statut</th><th class="dashboard-no-export" data-sortable="false">Détail</th></tr></thead>
                                        <tbody>
                                            @foreach ($gapSection['rows'] as $row)
                                                @php
                                                    $detailPayload = base64_encode(json_encode([
                                                        'title' => (string) ($row['libelle'] ?? 'Action PTA'),
                                                        'headers' => ['Axe', 'Direction', 'Service', 'Responsable', 'Échéance', 'Progression', 'Statut'],
                                                        'cells' => [
                                                            (string) ($row['axe'] ?? '-'),
                                                            (string) ($row['direction'] ?? '-'),
                                                            (string) ($row['service'] ?? '-'),
                                                            (string) ($row['responsable'] ?? '-'),
                                                            (string) ($row['date_fin'] ?? '-'),
                                                            number_format((float) ($row['progression'] ?? 0), 1, ',', ' ').'%',
                                                            (string) $gapSection['label'],
                                                        ],
                                                        'url' => '',
                                                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                                @endphp
                                                <tr>
                                                    <td><span class="table-text-truncate font-semibold text-[#17324a]" title="{{ $row['libelle'] ?? '-' }}">{{ $row['libelle'] ?? '-' }}</span></td>
                                                    <td data-mobile-hidden>{{ $row['axe'] ?? '-' }}</td>
                                                    <td data-mobile-hidden>{{ $row['responsable'] ?? '-' }}</td>
                                                    @php
                                                        $rawDeadline = trim((string) ($row['date_fin'] ?? ''));
                                                        try {
                                                            $deadlineLabel = $rawDeadline !== ''
                                                                ? \Illuminate\Support\Carbon::parse($rawDeadline)->format('d/m/Y')
                                                                : '-';
                                                        } catch (\Throwable) {
                                                            $deadlineLabel = $rawDeadline !== '' ? $rawDeadline : '-';
                                                        }
                                                    @endphp
                                                    <td data-sort-value="{{ $rawDeadline }}">{{ $deadlineLabel }}</td>
                                                    <td data-num data-sort-value="{{ (float) ($row['progression'] ?? 0) }}"><x-pta.progress-bar :value="(float) ($row['progression'] ?? 0)" :label="number_format((float) ($row['progression'] ?? 0), 1, ',', ' ').'%'" /></td>
                                                    <td><span class="dashboard-pill" style="{{ $dashboardPillVars($gapSection['tone']) }}">{{ $gapSection['label'] }}</span></td>
                                                    <td class="dashboard-no-export"><button type="button" class="btn btn-secondary btn-sm" data-dashboard-row-detail="{{ $detailPayload }}">Détails</button></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        @endforeach

                        @if ($ptaMeasures->isNotEmpty())
                            <section>
                                <h3 class="mb-2 text-base font-black text-[#17324a]">Mesures correctives</h3>
                                <div class="app-table-wrapper overflow-x-auto">
                                    <table class="app-table data-table" data-mobile-cards>
                                        <thead><tr><th data-num>N°</th><th>Mesure à mettre en œuvre</th><th>Statut</th></tr></thead>
                                        <tbody>
                                            @foreach ($ptaMeasures as $measureIndex => $measure)
                                                <tr><td data-num>{{ $measureIndex + 1 }}</td><td>{{ $measure }}</td><td><span class="dashboard-pill" style="{{ $dashboardPillVars('warning') }}">À planifier</span></td></tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        @endif
                    </div>
                @endif

                <div class="grid gap-3 border-t border-slate-200/80 p-3 lg:grid-cols-3">
                    @foreach (['points_forts' => 'Points forts', 'points_faibles' => 'Points faibles', 'priorites' => 'Priorités'] as $findingKey => $findingTitle)
                        <article class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                            <h3 class="mb-2 text-sm font-black text-[#17324a]">{{ $findingTitle }}</h3>
                            <ul class="space-y-2 text-sm text-[#17324a]">@forelse (($ptaFindings[$findingKey] ?? []) as $finding)<li>{{ $finding }}</li>@empty<li>Aucun constat automatique.</li>@endforelse</ul>
                        </article>
                    @endforeach
                </div>
            </details>

            <div id="dashboard-row-detail-modal" class="fixed inset-0 z-[1000] hidden items-stretch justify-end bg-slate-950/55" aria-hidden="true">
                <aside class="h-full w-full max-w-[42rem] overflow-hidden bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="dashboard-row-detail-title">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                            <div>
                                <p class="text-xs font-semibold text-[#3996d3]">Détail de l’action</p>
                                <h3 id="dashboard-row-detail-title" class="mt-1 text-lg font-black text-[#17324a]">Détail</h3>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" data-dashboard-row-detail-close>Fermer</button>
                        </div>
                        <div class="h-[calc(100vh-5rem)] overflow-y-auto p-5">
                            <dl id="dashboard-row-detail-body" class="grid gap-3 sm:grid-cols-2"></dl>
                            <a id="dashboard-row-detail-link" href="#" class="btn btn-primary mt-5 hidden">Ouvrir la page</a>
                        </div>
                </aside>
            </div>
        @else
            <div class="showcase-panel p-4">
                <x-ui.empty-state
                    title="Aucune donnée pour les tableaux"
                    message="Aucune analyse PTA ne correspond au périmètre et aux filtres sélectionnés."
                    icon="filter"
                />
            </div>
        @endif

        @if (false)
        <details class="showcase-panel overflow-hidden p-0">
            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 text-[#3996d3]">▸</span>
                    <h2 class="showcase-panel-title">Priorites</h2>
                </div>
                <span class="showcase-chip">{{ count($priorityActionRows) }} lignes</span>
            </summary>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    {{-- Colonne "Conformité" retiree (2026-05-28) : KPI conformite supprime de l'app. --}}
                    <thead><tr><th>Action</th><th>Direction</th><th>Statut</th><th>Avancement réel</th><th>Performance d'exécution</th><th>Statut délai</th></tr></thead>
                    <tbody>
                        @forelse ($priorityActionRows as $row)
                            @php
                                $statusColor = match ($row['statut']) {'acheve' => '#1C203D','en_avance' => '#8FC043','a_risque' => '#F9B13C','en_retard' => '#B42318','suspendu' => '#B42318','annule' => '#6B7280','non_demarre' => '#6B7280',default => '#3996D3'};
                                $progress = (float) ($row['progression'] ?? 0);
                                $progressColor = $progress >= 80 ? '#8FC043' : ($progress >= 60 ? '#3996D3' : ($progress > 0 ? '#F9B13C' : '#94A3B8'));
                            @endphp
                            <tr>
                                <td><a href="{{ $row['url'] }}" class="font-semibold text-[#17324a] hover:text-[#3996D3]">{{ $row['libelle'] }}</a><div class="mt-1 text-[11px] text-[#667085]">{{ $row['responsable'] }} | {{ $row['service'] }}</div></td>
                                <td>{{ $row['direction'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardStatusTone($row['statut'])) }}"><span class="h-2 w-2 rounded-full" style="background: {{ $statusColor }};"></span>{{ $actionStatusLabel($row['statut']) }}</span></td>
                                <td><div class="flex min-w-[120px] items-center gap-2"><div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200/90"><div class="h-full rounded-full" style="width: {{ min(100, max(0, $progress)) }}%; background: {{ $progressColor }};"></div></div><span class="text-[11px] font-black">{{ number_format($progress, 0) }}%</span></div></td>
                                @php $performanceValue = (float) ($row['kpi_performance'] ?? 0); @endphp
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($performanceValue)) }}">{{ number_format($performanceValue, 0) }}</span></td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($delayStatusTone((string) ($row['delay_status'] ?? ''))) }}">{{ $row['statut_delai'] ?? '-' }}</span></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-ui.empty-state title="Aucune action" message="Aucune action disponible sur ce périmètre." icon="filter" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>

        <details class="showcase-panel overflow-hidden p-0">
            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 text-[#3996d3]">▸</span>
                    <h2 class="showcase-panel-title">Alertes</h2>
                </div>
                <span class="showcase-chip">{{ count($alertRows) }} alerte(s)</span>
            </summary>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    {{-- Colonne "Conformité" retiree (2026-05-28) du tableau des alertes. --}}
                    <thead>
                        <tr><th>Alerte</th><th>Direction</th><th>Action</th><th>Niveau</th><th>Détail</th><th>{{ $metricLabel('global') }}</th><th>Accès</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($alertRows as $row)
                            <tr>
                                <td class="font-semibold text-[#17324a]">{{ $row['titre'] }}</td>
                                <td>{{ $row['direction'] }}</td>
                                <td>{{ $row['action'] }}</td>
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars(in_array($row['niveau'], ['Critique', 'Urgence'], true) ? 'danger' : 'warning') }}">{{ $row['niveau'] }}</span></td>
                                <td>{{ $row['details'] }}</td>
                                @php $kpiValue = (float) ($row['kpi'] ?? 0); @endphp
                                <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone($kpiValue)) }}">{{ number_format($kpiValue, 0) }}</span></td>
                                <td><a href="{{ $row['url'] }}" class="btn btn-primary btn-sm rounded-xl">Voir</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7">
                                    <x-ui.empty-state title="Aucune alerte" message="Aucune alerte active sur ce périmètre." icon="alert" />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>

        @if ($showDashboardAnalyticalTables)
            <section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="showcase-panel-title">Tables</h2></div>
                    <a href="{{ route('workspace.reporting') }}" class="dashboard-reporting-jump">Exports</a>
                </div>
                @include('partials.dashboard-reporting-analytics', [
                    'reportingAnalytics' => $reportingAnalytics ?? [],
                    'displayMode' => 'tables',
                ])
            </section>
        @endif
        @endif
    </div>
</section>
