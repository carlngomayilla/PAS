@php
    $synthesisHierarchy = is_array($analytics['synthesis_hierarchy'] ?? null) ? $analytics['synthesis_hierarchy'] : [];
    $axisNodes = collect($synthesisHierarchy['axes'] ?? [])->values();
    $synthesisDetailUrl = route('synthese.index', array_merge($baseSynthesisQuery, ['dashboardTab' => 'advanced']));
    $synthesisTone = static function (float $value): string {
        if ($value >= 100) {
            return '#0f7a3a';
        }

        if ($value >= 75) {
            return '#20C76B';
        }

        if ($value >= 50) {
            return '#3996D3';
        }

        if ($value >= 25) {
            return '#F9B13C';
        }

        return '#B42318';
    };
    $synthesisProgressStyle = static function (float $value) use ($synthesisTone): string {
        $clamped = max(0, min(100, $value));

        return 'width: '.$clamped.'%; background: '.$synthesisTone($clamped).';';
    };
@endphp

<section class="dashboard-synthesis-hierarchy-card mb-4 space-y-3" data-dashboard-synthesis-hierarchy>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <p class="text-[11px] font-black uppercase tracking-wide text-[#3996d3]">Vue synthétique des axes</p>
            <h2 class="showcase-panel-title mt-1">Axes → Objectifs → PAO/PTA → Actions</h2>
        </div>
        <div class="flex items-center gap-2">
            <span class="showcase-chip">{{ $axisNodes->count() }} axe(s)</span>
            <a href="{{ $synthesisDetailUrl }}" class="btn btn-secondary btn-sm rounded-lg px-3 py-1.5 text-xs">
                Vue détaillée
            </a>
        </div>
    </div>

    <div class="grid gap-3">
        @forelse ($axisNodes as $axis)
            @php
                $axisProgress = max(0, min(100, (float) ($axis['progress'] ?? 0)));
                $axisTone = $synthesisTone($axisProgress);
                $objectiveNodes = collect($axis['objectives'] ?? [])->values();
            @endphp
            <details class="showcase-panel dashboard-synthesis-node dashboard-synthesis-node-axis overflow-hidden rounded-lg p-0" {{ $loop->first ? 'open' : '' }}>
                <summary class="dashboard-synthesis-node-summary flex cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 list-none">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="dashboard-pill dashboard-synthesis-level-pill">{{ $axis['code'] ?? 'AXE' }}</span>
                            <strong class="text-[#17324a]">{{ $axis['label'] ?? 'Axe non renseigné' }}</strong>
                        </div>
                        <div class="mt-2 grid gap-2 text-xs font-semibold text-[#667085] md:grid-cols-5">
                            <span>Niveau attendu {{ $axis['target'] ?? '100%' }}</span>
                            <span>Réalisé {{ $axis['realized'] ?? $fmtPct($axisProgress) }}</span>
                            <span>Évolution : {{ $axis['status'] ?? '-' }}</span>
                            <span>{{ $fmtCount($axis['completed_actions_total'] ?? 0) }}/{{ $fmtCount($axis['actions_total'] ?? 0) }} action(s) terminée(s)</span>
                            <span>{{ $fmtCount($axis['late_actions_total'] ?? 0) }} retard(s)</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200">
                            <div class="h-full rounded-full" style="{{ $synthesisProgressStyle($axisProgress) }}"></div>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <span class="text-xl font-black" style="color: {{ $axisTone }};">{{ $fmtPct($axisProgress) }}</span>
                        <a href="{{ $axis['detail_url'] ?? $synthesisDetailUrl }}" class="btn btn-secondary btn-sm rounded-lg px-3 py-1.5 text-xs" onclick="event.stopPropagation();">
                            Voir pourquoi
                        </a>
                    </div>
                </summary>

                <div class="border-t border-slate-200/80 px-4 py-3">
                    @forelse ($objectiveNodes as $objective)
                        @php
                            $objectiveProgress = max(0, min(100, (float) ($objective['progress'] ?? 0)));
                            $operationals = collect($objective['operational_objectives'] ?? [])->values();
                        @endphp
                        <details class="dashboard-synthesis-node dashboard-synthesis-node-strategic-objective border-l-2 border-[#3996d3]/30 pl-3" {{ $loop->first ? 'open' : '' }}>
                            <summary class="dashboard-synthesis-node-summary cursor-pointer list-none py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="dashboard-synthesis-level-label text-[11px] font-black uppercase text-[#3996d3]">Objectif stratégique {{ $objective['code'] ?? 'OS' }}</p>
                                        <h3 class="text-sm font-black text-[#17324a]">{{ $objective['label'] ?? '-' }}</h3>
                                    </div>
                                    <span class="dashboard-pill dashboard-synthesis-progress-pill" style="--pill-fg:{{ $synthesisTone($objectiveProgress) }};">{{ $fmtPct($objectiveProgress) }}</span>
                                </div>
                            </summary>

                            <div class="space-y-2 pb-2">
                                @forelse ($operationals as $operational)
                                    @php
                                        $operationalProgress = max(0, min(100, (float) ($operational['progress'] ?? 0)));
                                        $ptas = collect($operational['ptas'] ?? [])->values();
                                    @endphp
                                    <details class="dashboard-synthesis-node dashboard-synthesis-node-operational-objective rounded-lg border border-slate-200 bg-slate-50/80" {{ $loop->first ? 'open' : '' }}>
                                        <summary class="dashboard-synthesis-node-summary cursor-pointer list-none px-3 py-2">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="dashboard-synthesis-level-label text-[11px] font-black uppercase text-[#667085]">Objectif opérationnel / PAO</p>
                                                    <h4 class="text-sm font-black text-[#17324a]">{{ $operational['label'] ?? '-' }}</h4>
                                                    <p class="mt-1 text-xs font-semibold text-[#667085]">{{ $operational['direction'] ?? '-' }} | {{ $operational['service'] ?? '-' }}</p>
                                                </div>
                                                <div class="min-w-[150px]">
                                                    <div class="mb-1 text-right text-xs font-black text-[#17324a]">{{ $fmtPct($operationalProgress) }}</div>
                                                    <div class="h-2 overflow-hidden rounded-full bg-slate-200">
                                                        <div class="h-full rounded-full" style="{{ $synthesisProgressStyle($operationalProgress) }}"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </summary>

                                        <div class="space-y-2 border-t border-slate-200 px-3 py-2">
                                            @foreach ($ptas as $pta)
                                                @php
                                                    $ptaProgress = max(0, min(100, (float) ($pta['progress'] ?? 0)));
                                                    $actions = collect($pta['actions'] ?? [])->values();
                                                @endphp
                                                <div class="dashboard-synthesis-node dashboard-synthesis-node-pta rounded-lg bg-white p-3">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <div class="min-w-0">
                                                            <p class="dashboard-synthesis-level-label text-[11px] font-black uppercase text-[#667085]">{{ $pta['code'] ?? 'PTA' }} | {{ $pta['service'] ?? '-' }}</p>
                                                            <strong class="text-sm text-[#17324a]">{{ $pta['label'] ?? '-' }}</strong>
                                                        </div>
                                                        <span class="dashboard-pill dashboard-synthesis-progress-pill" style="--pill-fg:{{ $synthesisTone($ptaProgress) }};">{{ $fmtPct($ptaProgress) }}</span>
                                                    </div>

                                                    <div class="mt-3 space-y-2">
                                                        @forelse ($actions as $action)
                                                            @php $actionProgress = max(0, min(100, (float) ($action['progress'] ?? 0))); @endphp
                                                            <details class="dashboard-synthesis-node dashboard-synthesis-node-action rounded-lg border border-slate-200">
                                                                <summary class="dashboard-synthesis-node-summary cursor-pointer list-none px-3 py-2">
                                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                                        <div class="min-w-0 flex-1">
                                                                            <p class="dashboard-synthesis-level-label text-[11px] font-black uppercase text-[#667085]">{{ $action['code'] ?? 'ACT' }} | {{ $action['responsible'] ?? '-' }}</p>
                                                                            <h5 class="text-sm font-black text-[#17324a]">{{ $action['label'] ?? '-' }}</h5>
                                                                            <p class="mt-1 text-xs font-semibold text-[#667085]">Niveau attendu {{ $action['target'] ?? '-' }} | Réalisé {{ $action['realized'] ?? '-' }} | {{ $action['alert'] ?? '-' }}</p>
                                                                        </div>
                                                                        <div class="flex min-w-[170px] items-center gap-2">
                                                                            <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-200">
                                                                                <div class="h-full rounded-full" style="{{ $synthesisProgressStyle($actionProgress) }}"></div>
                                                                            </div>
                                                                            <span class="text-xs font-black text-[#17324a]">{{ $fmtPct($actionProgress) }}</span>
                                                                        </div>
                                                                    </div>
                                                                </summary>
                                                                <div class="border-t border-slate-200 px-3 py-2">
                                                                    <div class="grid gap-2 text-xs font-semibold text-[#667085] md:grid-cols-4">
                                                                        <span>Statut : {{ $action['status'] ?? '-' }}</span>
                                                                        <span>Délai : {{ $action['delay_status'] ?? '-' }}</span>
                                                                        <span>Cause : {{ $action['blockage_reason'] ?? '-' }}</span>
                                                                        <span>Preuves : {{ $fmtCount($action['proofs_total'] ?? 0) }}</span>
                                                                    </div>
                                                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                                        <span class="text-xs font-semibold text-[#667085]">{{ $fmtCount($action['sub_actions_done'] ?? 0) }}/{{ $fmtCount($action['sub_actions_total'] ?? 0) }} sous-action(s) effectuée(s)</span>
                                                                        <a href="{{ $action['detail_url'] ?? '#' }}" class="btn btn-primary btn-sm rounded-lg px-3 py-1.5 text-xs">Voir action</a>
                                                                    </div>

                                                                    @if (!empty($action['sub_actions']))
                                                                        <div class="mt-2 grid gap-2 md:grid-cols-2">
                                                                            @foreach ($action['sub_actions'] as $subAction)
                                                                                @php $subProgress = max(0, min(100, (float) ($subAction['progress'] ?? 0))); @endphp
                                                                                <div class="dashboard-synthesis-node dashboard-synthesis-node-sub-action rounded-lg border border-slate-200 bg-slate-50 p-2">
                                                                                    <div class="flex items-start justify-between gap-2">
                                                                                        <div class="min-w-0">
                                                                                            <p class="dashboard-synthesis-level-label text-[11px] font-black uppercase text-[#667085]">{{ $subAction['code'] ?? 'SA' }}</p>
                                                                                            <p class="text-xs font-bold text-[#17324a]">{{ $subAction['label'] ?? '-' }}</p>
                                                                                            <p class="mt-1 text-[11px] font-semibold text-[#667085]">{{ $subAction['responsible'] ?? '-' }} | {{ $subAction['deadline'] ?? '-' }}</p>
                                                                                        </div>
                                                                                        <span class="text-xs font-black" style="color: {{ $synthesisTone($subProgress) }};">{{ $fmtPct($subProgress) }}</span>
                                                                                    </div>
                                                                                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                                                                        <div class="h-full rounded-full" style="{{ $synthesisProgressStyle($subProgress) }}"></div>
                                                                                    </div>
                                                                                </div>
                                                                            @endforeach
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                            </details>
                                                        @empty
                                                            <p class="text-sm font-semibold text-[#667085]">Aucune action PTA rattachée.</p>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @empty
                                    <p class="text-sm font-semibold text-[#667085]">Aucun objectif opérationnel rattaché.</p>
                                @endforelse
                            </div>
                        </details>
                    @empty
                        <x-ui.empty-state title="Aucun objectif" message="Aucun objectif stratégique rattaché à cet axe." icon="file" />
                    @endforelse
                </div>
            </details>
        @empty
            <x-ui.empty-state title="Aucune synthèse PAS" message="Aucune action ne permet encore de construire l'arborescence PAS." icon="chart" tone="info" />
        @endforelse
    </div>
</section>
