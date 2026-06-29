<section class="dashboard-tab-panel active" data-dashboard-panel="advanced">
    <div class="space-y-2">
        @if ($showRoleOverview)
            @include('partials.dashboard-role-overview', [
                'roleDashboard' => $roleDashboard,
                'dashboardRole' => $dashboardRole,
                'statisticalPolicy' => $statisticalPolicy,
                'officialPolicy' => $officialPolicy,
                'displayMode' => 'tables',
            ])
        @endif

        <details class="showcase-panel overflow-hidden p-0" open>
            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 text-[#3996d3]">▸</span>
                    <h2 class="showcase-panel-title">Synthese {{ strtolower($unitModeLabel) }}</h2>
                </div>
                <span class="showcase-chip">{{ count($unitRows) }} lignes</span>
            </summary>
            <div class="app-table-wrapper overflow-x-auto">
                <table class="app-table data-table">
                    <thead class="sticky top-0 z-10 bg-white"><tr><th>{{ $unitModeLabel }}</th><th>Actions</th><th>Progression</th><th>Indicateur moyen</th><th>Alertes</th><th>Validation</th></tr></thead>
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

        @php
            $ptaSummary = (array) ($ptaQuarterlyAnalysis['summary'] ?? []);
            $ptaAxes = collect($ptaQuarterlyAnalysis['axes'] ?? [])->values();
            $ptaServices = collect($ptaQuarterlyAnalysis['services'] ?? [])->values();
            $ptaGaps = (array) ($ptaQuarterlyAnalysis['gaps'] ?? []);
            $ptaUnrealized = collect($ptaGaps['unrealized'] ?? [])->values();
            $ptaMeasures = collect($ptaQuarterlyAnalysis['corrective_measures'] ?? [])->values();
            $showPtaQuarterlyTables = ((int) ($ptaSummary['planned_actions'] ?? 0)) > 0
                || $ptaAxes->isNotEmpty()
                || $ptaServices->isNotEmpty()
                || $ptaUnrealized->isNotEmpty();
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

                <div class="grid gap-3 p-3 md:grid-cols-4">
                    @foreach ([
                        'Actions prévues' => $ptaSummary['planned_actions'] ?? 0,
                        'Actions réalisées' => $ptaSummary['completed_actions'] ?? 0,
                        'Actions échues' => $ptaSummary['due_actions'] ?? 0,
                        'Taux PTA' => number_format((float) ($ptaSummary['realization_rate'] ?? 0), 0, ',', ' ').'%',
                    ] as $label => $value)
                        <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                            <p class="text-xs font-bold uppercase text-[#667085]">{{ $label }}</p>
                            <p class="mt-1 text-xl font-black text-[#17324a]">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="app-table-wrapper overflow-x-auto">
                    <table class="app-table data-table">
                        <thead class="sticky top-0 z-10 bg-white">
                            <tr><th>Axe</th><th>Prévues</th><th>Réalisées</th><th>En retard/non réalisées</th><th>Non démarrées</th><th>Échues</th><th>Taux PTA</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($ptaAxes as $row)
                                <tr>
                                    <td class="font-semibold text-[#17324a]">{{ trim(($row['code'] ?? '').' '.($row['libelle'] ?? '')) }}</td>
                                    <td>{{ $row['planned_actions'] ?? 0 }}</td>
                                    <td>{{ $row['completed_actions'] ?? 0 }}</td>
                                    <td>{{ $row['late_or_unrealized_actions'] ?? 0 }}</td>
                                    <td>{{ $row['not_started_actions'] ?? 0 }}</td>
                                    <td>{{ $row['due_actions'] ?? 0 }}</td>
                                    <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['realization_rate'] ?? 0))) }}">{{ number_format((float) ($row['realization_rate'] ?? 0), 0, ',', ' ') }}%</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><x-ui.empty-state title="Aucun axe" message="Aucun axe PTA disponible sur ce périmètre." icon="chart" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($ptaServices->isNotEmpty())
                    <div class="app-table-wrapper overflow-x-auto border-t border-slate-200/80">
                        <table class="app-table data-table">
                            <thead class="sticky top-0 z-10 bg-white">
                                <tr><th>Service</th><th>Direction</th><th>Prévues</th><th>Réalisées</th><th>Échues</th><th>Taux PTA</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($ptaServices as $row)
                                    <tr class="dashboard-row-link" data-row-link="{{ $row['url'] ?? '' }}">
                                        <td class="font-semibold text-[#17324a]">{{ $row['libelle'] ?? '-' }}</td>
                                        <td>{{ $row['direction'] ?? '-' }}</td>
                                        <td>{{ $row['planned_actions'] ?? 0 }}</td>
                                        <td>{{ $row['completed_actions'] ?? 0 }}</td>
                                        <td>{{ $row['due_actions'] ?? 0 }}</td>
                                        <td><span class="dashboard-pill" style="{{ $dashboardPillVars($dashboardKpiTone((float) ($row['realization_rate'] ?? 0))) }}">{{ number_format((float) ($row['realization_rate'] ?? 0), 0, ',', ' ') }}%</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($ptaUnrealized->isNotEmpty() || $ptaMeasures->isNotEmpty())
                    <div class="grid gap-3 border-t border-slate-200/80 p-3 xl:grid-cols-2">
                        <div class="overflow-x-auto">
                            <h3 class="mb-2 text-sm font-black text-[#17324a]">Écarts</h3>
                            <table class="app-table data-table">
                                <thead><tr><th>Action</th><th>RMO</th><th>Échéance</th><th>Progression</th></tr></thead>
                                <tbody>
                                    @forelse ($ptaUnrealized as $row)
                                        <tr>
                                            <td><a href="{{ $row['url'] ?? '#' }}" class="font-semibold text-[#17324a] hover:text-[#3996D3]">{{ $row['libelle'] ?? '-' }}</a><div class="mt-1 text-[11px] text-[#667085]">{{ $row['axe'] ?? '-' }}</div></td>
                                            <td>{{ $row['responsable'] ?? '-' }}</td>
                                            <td>{{ $row['date_fin'] ?? '-' }}</td>
                                            <td>{{ number_format((float) ($row['progression'] ?? 0), 0, ',', ' ') }}%</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4">Aucun écart sur la période.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h3 class="mb-2 text-sm font-black text-[#17324a]">Mesures correctives</h3>
                            <ul class="space-y-2 text-sm font-semibold text-[#17324a]">
                                @foreach ($ptaMeasures as $measure)
                                    <li class="rounded-lg border border-[#d8ecf8] bg-white p-3">{{ $measure }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
            </details>
        @endif

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
                    <thead class="sticky top-0 z-10 bg-white"><tr><th>Action</th><th>Direction</th><th>Statut</th><th>Avancement réel</th><th>Performance d'exécution</th><th>Statut délai</th></tr></thead>
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
                    <thead class="sticky top-0 z-10 bg-white">
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
    </div>
</section>
