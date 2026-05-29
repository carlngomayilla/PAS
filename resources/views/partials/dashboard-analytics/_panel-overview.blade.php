<section class="dashboard-tab-panel active" data-dashboard-panel="overview">
    {{-- ── KPI STAT CARDS ──────────────────────────────────────────────── --}}
    @php
        $kpiStatCards = [
            [
                'label'   => 'Actions totales',
                'value'   => $metrics['totals']['actions_total'] ?? 0,
                'accent'  => '#1c203d',
                'icon'    => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                'trend'   => null,
                'href'    => route('workspace.actions.index'),
            ],
            [
                'label'   => 'Indicateur global',
                'value'   => number_format((float) ($globalScores['global'] ?? 0), 1, ',', ' ') . '%',
                'accent'  => '#178f5f',
                'icon'    => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
                'trend'   => (float) ($globalScores['global'] ?? 0) >= 80 ? 'up' : ((float) ($globalScores['global'] ?? 0) >= 60 ? 'neutral' : 'down'),
                'trendLabel' => (float) ($globalScores['global'] ?? 0) >= 80 ? 'Bon' : ((float) ($globalScores['global'] ?? 0) >= 60 ? 'À surveiller' : 'Critique'),
                'href'    => route('workspace.actions.index', ['sort' => 'kpi_global_desc']),
            ],
            [
                'label'   => 'En retard',
                'value'   => $metrics['alerts']['actions_en_retard'] ?? 0,
                'accent'  => '#b42318',
                'icon'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
                'trend'   => ((int)($metrics['alerts']['actions_en_retard'] ?? 0)) > 0 ? 'down' : 'up',
                'trendLabel' => ((int)($metrics['alerts']['actions_en_retard'] ?? 0)) > 0 ? 'Alerte' : 'OK',
                'href'    => route('workspace.actions.index', ['statut' => 'en_retard']),
            ],
            [
                'label'   => 'Non démarrées',
                'value'   => collect($statusCards)->firstWhere('key', 'non_demarre')['count'] ?? (collect($statusCards)->firstWhere('label', 'Non demarre')['count'] ?? 0),
                'accent'  => '#64748b',
                'icon'    => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                'trend'   => 'neutral',
                'trendLabel' => 'À lancer',
                'href'    => route('workspace.actions.index', ['statut' => 'non_demarre']),
            ],
            [
                'label'   => 'Achevées',
                'value'   => $statusCount('acheve'),
                'accent'  => '#3996d3',
                'icon'    => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
                'trend'   => 'up',
                'trendLabel' => 'Terminées',
                'href'    => route('workspace.actions.index', ['statut' => 'achevees']),
            ],
        ];
        if ($dashboardRole === 'agent' && (int) ($personalActionsSummary['total'] ?? 0) > 0) {
            array_unshift($kpiStatCards, [
                'label'  => 'Mes actions',
                'value'  => (int) $personalActionsSummary['total'],
                'accent' => '#1c203d',
                'icon'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                'trend'  => null,
                'href'   => (string) ($personalActionsSummary['url'] ?? route('workspace.actions.index', ['vue' => 'mes_actions'])),
            ]);
        }
    @endphp
    {{-- Cartes statistiques : memes dimensions et structure que <x-ui.stat-card>
         utilisee dans les autres pages (min-w-[150px] max-w-[220px], px-4 py-3,
         label en haut, valeur en bas, badge tendance optionnel). --}}
    <div class="mx-auto mb-3 flex w-full max-w-5xl flex-wrap justify-center gap-2">
        @foreach ($kpiStatCards as $ksc)
            @php
                $trendUp   = ($ksc['trend'] ?? null) === 'up';
                $trendDown = ($ksc['trend'] ?? null) === 'down';
            @endphp
            <a href="{{ $ksc['href'] }}" class="no-kpi-band stat-card app-card min-w-[150px] max-w-[220px] flex-1 rounded-xl border border-[#3996d3]/30 bg-white px-4 py-3 text-center shadow-sm transition" style="--kpi-accent:{{ $ksc['accent'] }};">
                <div class="flex min-h-[4.5rem] flex-col items-center justify-center gap-2">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg" style="color:{{ $ksc['accent'] }}; background:color-mix(in srgb, {{ $ksc['accent'] }} 12%, transparent);">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true">{!! $ksc['icon'] !!}</svg>
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-center text-[11px] font-bold uppercase tracking-wide text-[#667085]">{{ $ksc['label'] }}</p>
                        <p class="mt-1 text-center text-xl font-extrabold leading-none" style="color:{{ $ksc['accent'] }};">{{ $ksc['value'] }}</p>
                    </div>
                </div>
                @if (!is_null($ksc['trend'] ?? null))
                    <div class="mt-2 flex justify-center">
                        <span class="app-badge app-badge-{{ $trendUp ? 'success' : ($trendDown ? 'danger' : 'neutral') }}">
                            @if ($trendUp)
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="18 15 12 9 6 15"/></svg>
                            @elseif ($trendDown)
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                            @else
                                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            @endif
                            <span class="ml-1">{{ $ksc['trendLabel'] ?? '' }}</span>
                        </span>
                    </div>
                @endif
            </a>
        @endforeach
    </div>

    @if (!in_array($dashboardRole, ['agent'], true))
    <div class="mb-4 w-full space-y-3">
        @php
            $planningHierarchyRows = [
                [
                    'group' => 'PAS',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['pas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pas.index', ['statut' => 'actif'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['pas_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                    ],
                ],
                [
                    'group' => 'PAO',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['paos_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pao.index', ['statut' => 'en_cours'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['paos_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pao.index')],
                    ],
                ],
                [
                    'group' => 'PTA',
                    'cards' => [
                        ['label' => 'Actifs', 'value' => $metrics['totals']['ptas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pta.index', ['statut' => 'en_cours'])],
                        ['label' => 'Total', 'value' => $metrics['totals']['ptas_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pta.index')],
                    ],
                ],
                [
                    'group' => 'ACTION',
                    'cards' => [
                        ['label' => 'Total', 'value' => $metrics['totals']['actions_total'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.actions.index')],
                        ['label' => 'Finies', 'value' => $statusCount('acheve'), 'accent' => '#178f5f', 'href' => route('workspace.actions.index', ['statut' => 'achevees'])],
                        ['label' => 'Cours', 'value' => $statusCount('en_cours'), 'accent' => '#3996D3', 'href' => route('workspace.actions.index', ['statut' => 'en_cours'])],
                        ['label' => 'Retard', 'value' => $metrics['alerts']['actions_en_retard'] ?? $statusCount('en_retard'), 'accent' => '#B42318', 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
                    ],
                ],
            ];
            $planningHierarchyRows = [
                [
                    'group' => 'PAS',
                    'cards' => [
                        ['label' => 'PAS actif', 'value' => $metrics['totals']['pas_actifs'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pas.index', ['statut' => 'actif'])],
                        ['label' => 'Axes concernés', 'value' => $decisionCounts['axes_concernes'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                        ['label' => 'Objectifs stratégiques concernés', 'value' => $decisionCounts['objectifs_strategiques_concernes'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pas.index')],
                        ['label' => 'Taux d\'alignement stratégique', 'value' => $fmtPct($decisionCounts['taux_alignement'] ?? 0), 'accent' => '#178f5f', 'href' => route('workspace.actions.index')],
                    ],
                ],
                [
                    'group' => 'PAO',
                    'cards' => [
                        ['label' => 'PAO de la direction', 'value' => $metrics['totals']['paos_total'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs opérationnels', 'value' => $decisionCounts['objectifs_operationnels'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs transmis aux services', 'value' => $decisionCounts['objectifs_transmis_services'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.pao.index')],
                        ['label' => 'Objectifs non repris dans les PTA', 'value' => max(0, (int) ($decisionCounts['objectifs_operationnels'] ?? 0) - (int) ($decisionCounts['ptas_lies'] ?? 0)), 'accent' => '#B42318', 'href' => route('workspace.pao.index')],
                    ],
                ],
                [
                    'group' => 'PTA',
                    'cards' => [
                        ['label' => 'PTA des services', 'value' => $metrics['totals']['ptas_total'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.pta.index')],
                        ['label' => 'PTA en cours', 'value' => $metrics['totals']['ptas_actifs'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.pta.index', ['statut' => 'en_cours'])],
                        ['label' => 'PTA sans actions', 'value' => max(0, (int) ($metrics['totals']['ptas_total'] ?? 0) - (int) ($decisionCounts['ptas_avec_actions'] ?? 0)), 'accent' => '#B42318', 'href' => route('workspace.pta.index')],
                        ['label' => 'Services couverts', 'value' => $decisionCounts['services_couverts'] ?? 0, 'accent' => '#17324a', 'href' => route('workspace.pta.index')],
                    ],
                ],
                [
                    'group' => 'ACTIONS',
                    'cards' => [
                        ['label' => 'Actions totales', 'value' => $decisionCounts['actions_total'] ?? ($metrics['totals']['actions_total'] ?? 0), 'accent' => '#17324a', 'href' => route('workspace.actions.index')],
                        ['label' => 'Actions terminées', 'value' => $decisionCounts['actions_terminees'] ?? 0, 'accent' => '#178f5f', 'href' => route('workspace.actions.index', ['statut' => 'achevees'])],
                        ['label' => 'Actions en cours', 'value' => $decisionCounts['actions_en_cours'] ?? 0, 'accent' => '#3996D3', 'href' => route('workspace.actions.index', ['statut' => 'en_cours'])],
                        ['label' => 'Actions en retard', 'value' => $decisionCounts['actions_en_retard'] ?? 0, 'accent' => '#B42318', 'href' => route('workspace.actions.index', ['statut' => 'en_retard'])],
                        ['label' => 'Taux d\'exécution', 'value' => $fmtPct($decisionCounts['taux_execution'] ?? 0), 'accent' => '#178f5f', 'href' => route('workspace.actions.index')],
                        ['label' => 'Taux validation', 'value' => $fmtPct($decisionCounts['taux_validation'] ?? 0), 'accent' => '#3996D3', 'href' => route('workspace.actions.index')],
                    ],
                ],
            ];
            $moduleProgressCards = [
                [
                    'group' => 'PAS',
                    'label' => 'Statut global',
                    'progress' => ($metrics['totals']['pas_total'] ?? 0) > 0 ? (($metrics['totals']['pas_actifs'] ?? 0) / max(1, (int) ($metrics['totals']['pas_total'] ?? 0))) * 100 : 0,
                    'status' => ($metrics['totals']['pas_total'] ?? 0) > 0 ? 'En evolution' : 'A initialiser',
                    'href' => route('workspace.pas.index'),
                    'accent' => '#3996D3',
                ],
                [
                    'group' => 'PAO',
                    'label' => 'Déclinaison opérationnelle',
                    'progress' => ($metrics['totals']['paos_total'] ?? 0) > 0 ? (($metrics['totals']['paos_actifs'] ?? 0) / max(1, (int) ($metrics['totals']['paos_total'] ?? 0))) * 100 : 0,
                    'status' => ($metrics['totals']['paos_total'] ?? 0) > 0 ? 'En cours' : 'A initialiser',
                    'href' => route('workspace.pao.index'),
                    'accent' => '#8FC043',
                ],
                [
                    'group' => 'PTA',
                    'label' => 'Execution annuelle',
                    'progress' => (float) collect($synthesisPtaRows)->avg(fn (array $row): float => (float) ($row['progression'] ?? 0)),
                    'status' => ($metrics['totals']['ptas_total'] ?? 0) > 0 ? 'Suivi actif' : 'A initialiser',
                    'href' => route('workspace.pta.index'),
                    'accent' => '#F9B13C',
                ],
                [
                    'group' => 'ACTION',
                    'label' => 'Avancement reel',
                    'progress' => (float) ($globalScores['progression'] ?? 0),
                    'status' => $statusCount('non_demarre') > 0 ? $fmtCount($statusCount('non_demarre')).' non démarrée(s)' : 'Suivi opérationnel',
                    'href' => route('workspace.actions.index'),
                    'accent' => '#1C203D',
                ],
            ];
            $planningHierarchyRows = collect($moduleProgressCards)->map(function (array $module) use ($fmtPct): array {
                $progress = max(0, min(100, (float) ($module['progress'] ?? 0)));

                return [
                    'group' => $module['group'],
                    'cards' => [[
                        'label' => $module['label'],
                        'value' => $fmtPct($progress),
                        'meta' => $module['status'],
                        'progress' => $progress,
                        'accent' => $module['accent'],
                        'href' => $module['href'],
                    ]],
                ];
            })->all();
        @endphp
        {{-- 4 cartes PAS / PAO / PTA / ACTION : memes dimensions que les cartes
             statistiques du haut et que <x-ui.stat-card> dans les autres pages. --}}
        <div class="mx-auto flex w-full max-w-5xl flex-wrap justify-center gap-2">
            @foreach ($planningHierarchyRows as $row)
                @php $card = $row['cards'][0] ?? null; @endphp
                @if ($card)
                    @php $progress = max(0, min(100, (float) ($card['progress'] ?? 0))); @endphp
                    <a href="{{ $card['href'] }}" class="no-kpi-band stat-card app-card min-w-[150px] max-w-[220px] flex-1 rounded-xl border bg-white px-4 py-3 text-center shadow-sm transition hover:shadow-md" style="border-color: {{ $card['accent'] }}33;">
                        <div class="flex min-h-[4.5rem] flex-col items-center justify-center gap-1">
                            <p class="truncate text-center text-[11px] font-bold uppercase tracking-wide text-[#667085]">{{ $row['group'] }}</p>
                            <p class="text-xl font-extrabold leading-none" style="color: {{ $card['accent'] }};">{{ $card['value'] }}</p>
                            <p class="truncate text-center text-[10px] font-semibold text-[#94A3B8]">{{ $card['label'] }}</p>
                        </div>
                        <div class="mt-2">
                            <span class="block h-1.5 overflow-hidden rounded-full bg-slate-200/80">
                                <span class="block h-full rounded-full" style="width: {{ $progress }}%; background: {{ $card['accent'] }};"></span>
                            </span>
                            <p class="mt-1 truncate text-center text-[10px] font-semibold text-[#667085]">{{ $card['meta'] ?? '' }}</p>
                        </div>
                    </a>
                @endif
            @endforeach
        </div>
    </div>
    @endif

    @if ($directionSynthesisTables !== [])
        @if (false)
        <section class="mb-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Graphiques de décision</h2>
                <span class="showcase-chip">Services et agents</span>
            </div>
            <div class="grid gap-3 xl:grid-cols-3">
                @foreach ($decisionCharts as $chart)
                    <article class="showcase-panel dashboard-synthesis-card">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <h3 class="text-sm font-black text-[#17324a]">{{ $chart['title'] }}</h3>
                        </div>
                        <div class="space-y-3">
                            @forelse (($chart['rows'] ?? []) as $row)
                                @php
                                    $barValue = min(100, max(0, (float) ($row['value'] ?? 0)));
                                    $barColor = (string) ($row['color'] ?? '#3996D3');
                                @endphp
                                <div>
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs font-semibold text-[#17324a]">
                                        <span class="truncate">{{ $row['label'] }}</span>
                                        <span class="whitespace-nowrap">{{ number_format($barValue, 1, ',', ' ') }}%</span>
                                    </div>
                                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-200/80">
                                        <div class="h-full rounded-full" style="width: {{ $barValue }}%; background: {{ $barColor }};"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] font-medium text-[#667085]">{{ $row['meta'] ?? '' }}</p>
                                </div>
                            @empty
                                <x-ui.empty-state
                                    title="Aucune donnée"
                                    message="Aucune donnée disponible."
                                    icon="chart"
                                    tone="info"
                                />
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        @endif

        <section class="mb-3">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h2 class="showcase-panel-title">Tableaux de décision</h2>
                <span class="showcase-chip">Performance et alertes</span>
            </div>
            <div class="space-y-2">
                @foreach ($directionSynthesisTables as $synthesisTable)
                    @php
                        $synthesisTableId = 'dashboard-synthesis-table-'.$loop->index;
                        $synthesisExportName = \Illuminate\Support\Str::slug((string) ($synthesisTable['title'] ?? 'tableau')).'-'.now()->format('Ymd-His');
                        $synthesisRowCount = is_array($synthesisTable['rows'] ?? null) ? count($synthesisTable['rows']) : 0;
                    @endphp
                    <details class="showcase-panel dashboard-synthesis-card w-full overflow-hidden p-0" {{ $loop->first ? 'open' : '' }}>
                        <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 px-3 py-2 list-none">
                            <h3 class="text-sm font-black text-[#17324a]">
                                <span class="inline-block w-3 text-[#3996d3]">▸</span>
                                {{ $synthesisTable['title'] }}
                            </h3>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="showcase-chip">{{ $synthesisTable['chip'] }}</span>
                                <span class="text-[11px] font-semibold text-[#667085]">{{ $synthesisRowCount }} ligne(s)</span>
                                <button type="button" class="btn btn-primary btn-sm rounded-xl"
                                    data-dashboard-export-table="{{ $synthesisTableId }}"
                                    data-dashboard-export-name="{{ $synthesisExportName }}"
                                    onclick="event.stopPropagation();">
                                    Export Excel
                                </button>
                            </div>
                        </summary>
                        <div class="app-table-wrapper max-h-[60vh] overflow-auto">
                            <table id="{{ $synthesisTableId }}" class="app-table data-table dashboard-synthesis-table">
                                <thead class="sticky top-0 z-10 bg-white">
                                    <tr>
                                        @foreach ($synthesisTable['headers'] as $header)
                                            <th>{{ $header }}</th>
                                        @endforeach
                                        <th class="dashboard-no-export">D&eacute;tail</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse (($synthesisTable['rows'] ?? []) as $row)
                                        @php
                                            $detailPayload = base64_encode(json_encode([
                                                'title' => (string) ($synthesisTable['title'] ?? 'Tableau'),
                                                'headers' => array_values((array) ($synthesisTable['headers'] ?? [])),
                                                'cells' => array_values((array) ($row['cells'] ?? [])),
                                                'url' => (string) ($row['url'] ?? ''),
                                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                        @endphp
                                        <tr>
                                            @foreach (($row['cells'] ?? []) as $cell)
                                                <td>{{ $cell }}</td>
                                            @endforeach
                                            <td class="dashboard-no-export">
                                                <button type="button" class="btn btn-primary btn-sm rounded-xl"
                                                    data-dashboard-row-detail="{{ $detailPayload }}">
                                                    Voir
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ count($synthesisTable['headers']) + 1 }}">
                                                <x-ui.empty-state title="Aucune donnée" :message="$synthesisTable['empty'] ?? 'Aucune donnée disponible.'" icon="file" />
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endforeach
            </div>
        </section>

        <div id="dashboard-row-detail-modal" class="fixed inset-0 z-[1000] hidden items-center justify-center bg-slate-950/55 p-4" aria-hidden="true">
            <div class="max-h-[88vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#3996d3]">Détail de ligne</p>
                        <h3 id="dashboard-row-detail-title" class="mt-1 text-lg font-black text-[#17324a]">Détail</h3>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm rounded-xl" data-dashboard-row-detail-close>Fermer</button>
                </div>
                <div class="max-h-[62vh] overflow-y-auto p-5">
                    <dl id="dashboard-row-detail-body" class="grid gap-3 md:grid-cols-2"></dl>
                    <a id="dashboard-row-detail-link" href="#" class="btn btn-primary mt-5 hidden rounded-xl">Ouvrir la page</a>
                </div>
            </div>
        </div>
    @endif

    @if ($showRoleOverview)
        @include('partials.dashboard-role-overview', [
            'roleDashboard' => $roleDashboard,
            'dashboardRole' => $dashboardRole,
            'statisticalPolicy' => $statisticalPolicy,
            'officialPolicy' => $officialPolicy,
            'displayMode' => 'overview',
        ])
    @endif

</section>
