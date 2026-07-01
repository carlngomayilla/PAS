@php
    $synthesisHierarchy = is_array($analytics['synthesis_hierarchy'] ?? null) ? $analytics['synthesis_hierarchy'] : [];
    $pasNode = is_array($synthesisHierarchy['pas'] ?? null) ? $synthesisHierarchy['pas'] : [];
    $axisNodes = collect($synthesisHierarchy['axes'] ?? [])->values();
    $pasProgress = max(0, min(100, (float) ($pasNode['progress'] ?? 0)));
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

<section class="mb-4 space-y-3" data-dashboard-synthesis-hierarchy>
    <article class="showcase-panel rounded-lg p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[11px] font-black uppercase tracking-wide text-[#3996d3]">Vue synthetique d'avancement PAS</p>
                <h2 class="mt-1 text-xl font-black text-[#17324a]">{{ $pasNode['label'] ?? 'Plan d\'Acceleration Strategique' }}</h2>
                <p class="mt-1 text-sm font-semibold text-[#667085]">{{ $pasNode['period'] ?? ($exerciseFilter['label'] ?? 'Periode courante') }}</p>
            </div>
            <a href="{{ $synthesisDetailUrl }}" class="btn btn-primary btn-sm rounded-lg px-3 py-2 text-xs">
                Vue detaillee
            </a>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-4">
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                <p class="text-[11px] font-black uppercase text-[#667085]">Avancement global</p>
                <p class="mt-2 text-2xl font-black text-[#17324a]">{{ $fmtPct($pasProgress) }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                <p class="text-[11px] font-black uppercase text-[#667085]">Axes suivis</p>
                <p class="mt-2 text-2xl font-black text-[#17324a]">{{ $fmtCount($pasNode['axes_total'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                <p class="text-[11px] font-black uppercase text-[#667085]">Actions hors delai</p>
                <p class="mt-2 text-2xl font-black text-[#B42318]">{{ $fmtCount($pasNode['late_actions_total'] ?? 0) }}</p>
            </div>
            <div class="rounded-lg border border-[#d8ecf8] bg-white p-3">
                <p class="text-[11px] font-black uppercase text-[#667085]">Sous-actions</p>
                <p class="mt-2 text-2xl font-black text-[#17324a]">{{ $fmtCount($pasNode['sub_actions_total'] ?? 0) }}</p>
            </div>
        </div>

        <div class="mt-4">
            <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-sm font-bold text-[#17324a]">
                <span>Cible globale : {{ $pasNode['target'] ?? '100%' }}</span>
                <span>Realise : {{ $pasNode['realized'] ?? $fmtPct($pasProgress) }} | Ecart restant : {{ $pasNode['remaining'] ?? $fmtPct(max(0, 100 - $pasProgress)) }}</span>
            </div>
            <div class="h-4 overflow-hidden rounded-full bg-slate-200">
                <div class="h-full rounded-full" style="{{ $synthesisProgressStyle($pasProgress) }}"></div>
            </div>
        </div>
    </article>

    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="showcase-panel-title">PAS -> Axes -> Objectifs -> PAO/PTA -> Actions</h2>
            <p class="mt-1 text-sm font-semibold text-[#667085]">Ouvrez un axe pour comprendre rapidement ce qui avance, ce qui bloque et ou aller en detail.</p>
        </div>
        <span class="showcase-chip">{{ $axisNodes->count() }} axe(s)</span>
    </div>

    <div class="grid gap-3">
        @forelse ($axisNodes as $axis)
            @php
                $axisProgress = max(0, min(100, (float) ($axis['progress'] ?? 0)));
                $axisTone = $synthesisTone($axisProgress);
                $objectiveNodes = collect($axis['objectives'] ?? [])->values();
            @endphp
            <details class="showcase-panel overflow-hidden rounded-lg p-0" {{ $loop->first ? 'open' : '' }}>
                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 list-none">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="dashboard-pill" style="--pill-bg:#E8F3FB;--pill-fg:#3996D3;">{{ $axis['code'] ?? 'AXE' }}</span>
                            <strong class="text-[#17324a]">{{ $axis['label'] ?? 'Axe non renseigne' }}</strong>
                        </div>
                        <div class="mt-2 grid gap-2 text-xs font-semibold text-[#667085] md:grid-cols-4">
                            <span>Cible {{ $axis['target'] ?? '100%' }}</span>
                            <span>Realise {{ $axis['realized'] ?? $fmtPct($axisProgress) }}</span>
                            <span>{{ $fmtCount($axis['actions_total'] ?? 0) }} action(s)</span>
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
                        <details class="border-l-2 border-[#3996d3]/30 pl-3" {{ $loop->first ? 'open' : '' }}>
                            <summary class="cursor-pointer list-none py-2">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-[11px] font-black uppercase text-[#3996d3]">Objectif strategique {{ $objective['code'] ?? 'OS' }}</p>
                                        <h3 class="text-sm font-black text-[#17324a]">{{ $objective['label'] ?? '-' }}</h3>
                                    </div>
                                    <span class="dashboard-pill" style="--pill-bg:#F2F8E8;--pill-fg:{{ $synthesisTone($objectiveProgress) }};">{{ $fmtPct($objectiveProgress) }}</span>
                                </div>
                            </summary>

                            <div class="space-y-2 pb-2">
                                @forelse ($operationals as $operational)
                                    @php
                                        $operationalProgress = max(0, min(100, (float) ($operational['progress'] ?? 0)));
                                        $ptas = collect($operational['ptas'] ?? [])->values();
                                    @endphp
                                    <details class="rounded-lg border border-slate-200 bg-slate-50/80" {{ $loop->first ? 'open' : '' }}>
                                        <summary class="cursor-pointer list-none px-3 py-2">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div class="min-w-0">
                                                    <p class="text-[11px] font-black uppercase text-[#667085]">Objectif operationnel / PAO</p>
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
                                                <div class="rounded-lg bg-white p-3">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <div class="min-w-0">
                                                            <p class="text-[11px] font-black uppercase text-[#667085]">{{ $pta['code'] ?? 'PTA' }} | {{ $pta['service'] ?? '-' }}</p>
                                                            <strong class="text-sm text-[#17324a]">{{ $pta['label'] ?? '-' }}</strong>
                                                        </div>
                                                        <span class="dashboard-pill" style="--pill-bg:#E8F3FB;--pill-fg:{{ $synthesisTone($ptaProgress) }};">{{ $fmtPct($ptaProgress) }}</span>
                                                    </div>

                                                    <div class="mt-3 space-y-2">
                                                        @forelse ($actions as $action)
                                                            @php $actionProgress = max(0, min(100, (float) ($action['progress'] ?? 0))); @endphp
                                                            <details class="rounded-lg border border-slate-200">
                                                                <summary class="cursor-pointer list-none px-3 py-2">
                                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                                        <div class="min-w-0 flex-1">
                                                                            <p class="text-[11px] font-black uppercase text-[#667085]">{{ $action['code'] ?? 'ACT' }} | {{ $action['responsible'] ?? '-' }}</p>
                                                                            <h5 class="text-sm font-black text-[#17324a]">{{ $action['label'] ?? '-' }}</h5>
                                                                            <p class="mt-1 text-xs font-semibold text-[#667085]">Cible {{ $action['target'] ?? '-' }} | Realise {{ $action['realized'] ?? '-' }} | {{ $action['alert'] ?? '-' }}</p>
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
                                                                        <span>Delai : {{ $action['delay_status'] ?? '-' }}</span>
                                                                        <span>Cause : {{ $action['blockage_reason'] ?? '-' }}</span>
                                                                        <span>Preuves : {{ $fmtCount($action['proofs_total'] ?? 0) }}</span>
                                                                    </div>
                                                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                                                        <span class="text-xs font-semibold text-[#667085]">{{ $fmtCount($action['sub_actions_done'] ?? 0) }}/{{ $fmtCount($action['sub_actions_total'] ?? 0) }} sous-action(s) effectuee(s)</span>
                                                                        <a href="{{ $action['detail_url'] ?? '#' }}" class="btn btn-primary btn-sm rounded-lg px-3 py-1.5 text-xs">Voir action</a>
                                                                    </div>

                                                                    @if (!empty($action['sub_actions']))
                                                                        <div class="mt-2 grid gap-2 md:grid-cols-2">
                                                                            @foreach ($action['sub_actions'] as $subAction)
                                                                                @php $subProgress = max(0, min(100, (float) ($subAction['progress'] ?? 0))); @endphp
                                                                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                                                                                    <div class="flex items-start justify-between gap-2">
                                                                                        <div class="min-w-0">
                                                                                            <p class="text-[11px] font-black uppercase text-[#667085]">{{ $subAction['code'] ?? 'SA' }}</p>
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
                                                            <p class="text-sm font-semibold text-[#667085]">Aucune action PTA rattachee.</p>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @empty
                                    <p class="text-sm font-semibold text-[#667085]">Aucun objectif operationnel rattache.</p>
                                @endforelse
                            </div>
                        </details>
                    @empty
                        <x-ui.empty-state title="Aucun objectif" message="Aucun objectif strategique rattache a cet axe." icon="file" />
                    @endforelse
                </div>
            </details>
        @empty
            <x-ui.empty-state title="Aucune synthese PAS" message="Aucune action ne permet encore de construire l'arborescence PAS." icon="chart" tone="info" />
        @endforelse
    </div>
</section>
