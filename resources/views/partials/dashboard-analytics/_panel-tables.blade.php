<section class="dashboard-tab-panel active" data-dashboard-panel="overview-tables">
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
                    <h2 class="showcase-panel-title">Tableau de synthèse par {{ strtolower($unitModeLabel) }}</h2>
                </div>
                <span class="showcase-chip">{{ count($unitRows) }} lignes</span>
            </summary>
            <div class="app-table-wrapper max-h-[60vh] overflow-auto">
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

        <details class="showcase-panel overflow-hidden p-0">
            <summary class="flex cursor-pointer items-center justify-between gap-3 border-b border-slate-200/80 px-3 py-2 list-none">
                <div class="flex items-center gap-2">
                    <span class="inline-block w-3 text-[#3996d3]">▸</span>
                    <h2 class="showcase-panel-title">Actions prioritaires</h2>
                </div>
                <span class="showcase-chip">{{ count($priorityActionRows) }} lignes</span>
            </summary>
            <div class="app-table-wrapper max-h-[60vh] overflow-auto">
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
                    <h2 class="showcase-panel-title">Alertes actives</h2>
                </div>
                <span class="showcase-chip">{{ count($alertRows) }} alerte(s)</span>
            </summary>
            <div class="app-table-wrapper max-h-[60vh] overflow-auto">
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
                    <div><h2 class="showcase-panel-title">Tables analytiques</h2></div>
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
