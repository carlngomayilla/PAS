@if (($directionSynthesisTables ?? []) !== [])
    <section class="mb-3">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="showcase-panel-title">Tableaux de pilotage</h2>
            <span class="showcase-chip">Tableaux</span>
        </div>
        <div class="space-y-2" data-progressive-accordion-group>
            @foreach ($directionSynthesisTables as $synthesisTable)
                @php
                    $synthesisTableId = 'dashboard-synthesis-table-'.$loop->index;
                    $synthesisExportName = \Illuminate\Support\Str::slug((string) ($synthesisTable['title'] ?? 'tableau')).'-'.now()->format('Ymd-His');
                    $synthesisRowCount = is_array($synthesisTable['rows'] ?? null) ? count($synthesisTable['rows']) : 0;
                @endphp
                <details class="showcase-panel dashboard-synthesis-card w-full overflow-hidden p-0" data-progressive-accordion-item {{ $loop->first ? 'open' : '' }}>
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 px-3 py-2 list-none">
                        <h3 class="text-sm font-black text-[#17324a]">
                            <span class="inline-block w-3 text-[#3996d3]">&gt;</span>
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
                    <div class="app-table-wrapper overflow-x-auto">
                        <table id="{{ $synthesisTableId }}" class="app-table data-table dashboard-synthesis-table">
                            <thead>
                                <tr>
                                    @foreach ($synthesisTable['headers'] as $header)
                                        <th>{{ $header }}</th>
                                    @endforeach
                                    <th class="dashboard-no-export">Détail</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (($synthesisTable['rows'] ?? []) as $row)
                                    @php
                                        $cellLevels = (array) ($row['cell_levels'] ?? $synthesisTable['cell_levels'] ?? []);
                                        $rowUrl = (string) ($row['url'] ?? '');
                                        $detailPayload = base64_encode(json_encode([
                                            'title' => (string) ($synthesisTable['title'] ?? 'Tableau'),
                                            'headers' => array_values((array) ($synthesisTable['headers'] ?? [])),
                                            'cells' => array_values((array) ($row['cells'] ?? [])),
                                            'url' => $rowUrl,
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                    @endphp
                                    <tr>
                                        @foreach (($row['cells'] ?? []) as $cellIndex => $cell)
                                            @php
                                                $cellLevel = (string) ($cellLevels[$cellIndex] ?? '');
                                                $cellClass = $cellLevel !== '' ? 'dashboard-synthesis-hierarchy-cell dashboard-synthesis-level-'.$cellLevel : '';
                                            @endphp
                                            <td @if ($cellClass !== '') class="{{ $cellClass }}" @endif>{{ $cell }}</td>
                                        @endforeach
                                        <td class="dashboard-no-export">
                                            @if ($rowUrl !== '')
                                                <a href="{{ $rowUrl }}" class="btn btn-primary btn-sm rounded-xl"
                                                    data-dashboard-row-detail="{{ $detailPayload }}">
                                                    Voir
                                                </a>
                                            @else
                                                <button type="button" class="btn btn-primary btn-sm rounded-xl"
                                                    data-dashboard-row-detail="{{ $detailPayload }}">
                                                    Voir
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($synthesisTable['headers']) + 1 }}">
                                            <x-ui.empty-state title="Aucune donnee" :message="$synthesisTable['empty'] ?? 'Aucune donnee disponible.'" icon="file" />
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

    <div id="dashboard-row-detail-modal" class="fixed inset-0 z-[1000] hidden items-stretch justify-end bg-slate-950/55" aria-hidden="true">
        <aside class="h-full w-full max-w-[42rem] overflow-hidden bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="dashboard-row-detail-title">
            <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-[#3996d3]">Détail de ligne</p>
                    <h3 id="dashboard-row-detail-title" class="mt-1 text-lg font-black text-[#17324a]">Détail</h3>
                </div>
                <button type="button" class="btn btn-primary btn-sm rounded-xl" data-dashboard-row-detail-close>Fermer</button>
            </div>
            <div class="h-[calc(100vh-5rem)] overflow-y-auto p-5">
                <dl id="dashboard-row-detail-body" class="grid gap-3 md:grid-cols-2"></dl>
                <a id="dashboard-row-detail-link" href="#" class="btn btn-primary mt-5 hidden rounded-xl">Ouvrir la page</a>
            </div>
        </aside>
    </div>
@endif
