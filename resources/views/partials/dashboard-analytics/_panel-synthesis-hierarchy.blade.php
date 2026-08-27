@php
    $synthesisHierarchy = is_array($analytics['synthesis_hierarchy'] ?? null) ? $analytics['synthesis_hierarchy'] : [];
    $axisNodes = collect($synthesisHierarchy['axes'] ?? [])->values();
    $synthesisDetailUrl = route('synthese.index', array_merge($baseSynthesisQuery, ['dashboardTab' => 'advanced']));
    $synthesisTone = static function (float $value): string {
        if ($value >= 100) {
            return '#0f7a3a';
        }

        if ($value >= 75) {
            return '#20c76b';
        }

        if ($value >= 50) {
            return '#3996d3';
        }

        if ($value >= 25) {
            return '#f9b13c';
        }

        return '#b42318';
    };
    $synthesisProgressStyle = static function (float $value) use ($synthesisTone): string {
        $clamped = max(0, min(100, $value));

        return '--progress: '.$clamped.'%; --progress-tone: '.$synthesisTone($clamped).';';
    };
@endphp

<section class="dashboard-synthesis-hierarchy-card" data-dashboard-synthesis-hierarchy aria-labelledby="dashboard-hierarchy-title">
    <header class="dashboard-hierarchy-header">
        <div>
            <span class="dashboard-hierarchy-kicker">Vue synthétique des axes</span>
            <h2 id="dashboard-hierarchy-title">Du PAS aux actions opérationnelles</h2>
            <p>Dépliez uniquement le niveau à analyser pour conserver une lecture claire du plan.</p>
        </div>
        <div class="dashboard-hierarchy-header-actions">
            <span class="dashboard-hierarchy-count">{{ $axisNodes->count() }} axe(s)</span>
            <a href="{{ $synthesisDetailUrl }}" class="app-btn app-btn-secondary">Vue tableau</a>
        </div>
    </header>

    <div class="dashboard-hierarchy-tree" data-progressive-accordion-group>
        @forelse ($axisNodes as $axis)
            @php
                $axisProgress = max(0, min(100, (float) ($axis['progress'] ?? 0)));
                $objectiveNodes = collect($axis['objectives'] ?? [])->values();
                $lateActions = (int) ($axis['late_actions_total'] ?? 0);
            @endphp
            <details class="dashboard-synthesis-node dashboard-synthesis-node-axis" data-progressive-accordion-item {{ $loop->first ? 'open' : '' }}>
                <summary class="dashboard-synthesis-node-summary">
                    <span class="dashboard-hierarchy-chevron" aria-hidden="true"></span>
                    <span class="dashboard-axis-identity">
                        <span class="dashboard-hierarchy-code">{{ $axis['code'] ?? 'AXE' }}</span>
                        <span class="dashboard-axis-title">{{ $axis['label'] ?? 'Axe non renseigné' }}</span>
                    </span>
                    <span class="dashboard-axis-metrics">
                        <span>
                            <small>Actions réalisées</small>
                            <strong>{{ $fmtCount($axis['completed_actions_total'] ?? 0) }}/{{ $fmtCount($axis['actions_total'] ?? 0) }}</strong>
                        </span>
                        <span class="{{ $lateActions > 0 ? 'is-critical' : '' }}">
                            <small>Retards</small>
                            <strong>{{ $fmtCount($lateActions) }}</strong>
                        </span>
                    </span>
                    <span class="dashboard-progress-cluster" style="{{ $synthesisProgressStyle($axisProgress) }}">
                        <span class="dashboard-progress-copy">
                            <small>{{ $axis['status'] ?? 'Avancement' }}</small>
                            <strong>{{ $fmtPct($axisProgress) }}</strong>
                        </span>
                        <span class="dashboard-progress-track"><span></span></span>
                    </span>
                </summary>

                <div class="dashboard-axis-body">
                    <div class="dashboard-axis-context">
                        <p><strong>Niveau attendu :</strong> {{ $axis['target'] ?? '100%' }}</p>
                        <p><strong>Réalisé :</strong> {{ $axis['realized'] ?? $fmtPct($axisProgress) }}</p>
                        <a href="{{ $axis['detail_url'] ?? $synthesisDetailUrl }}">Analyser cet axe <span aria-hidden="true">→</span></a>
                    </div>

                    <div class="dashboard-objective-list" data-progressive-accordion-group>
                        @forelse ($objectiveNodes as $objective)
                            @php
                                $objectiveProgress = max(0, min(100, (float) ($objective['progress'] ?? 0)));
                                $operationals = collect($objective['operational_objectives'] ?? [])->values();
                            @endphp
                            <details class="dashboard-synthesis-node dashboard-synthesis-node-strategic-objective" data-progressive-accordion-item>
                                <summary class="dashboard-synthesis-node-summary">
                                    <span class="dashboard-hierarchy-chevron" aria-hidden="true"></span>
                                    <span class="dashboard-node-copy">
                                        <span class="dashboard-synthesis-level-label">Objectif stratégique {{ $objective['code'] ?? 'OS' }}</span>
                                        <strong>{{ $objective['label'] ?? '-' }}</strong>
                                    </span>
                                    <span class="dashboard-progress-cluster is-compact" style="{{ $synthesisProgressStyle($objectiveProgress) }}">
                                        <strong>{{ $fmtPct($objectiveProgress) }}</strong>
                                        <span class="dashboard-progress-track"><span></span></span>
                                    </span>
                                </summary>

                                <div class="dashboard-operational-list" data-progressive-accordion-group>
                                    @forelse ($operationals as $operational)
                                        @php
                                            $operationalProgress = max(0, min(100, (float) ($operational['progress'] ?? 0)));
                                            $ptas = collect($operational['ptas'] ?? [])->values();
                                        @endphp
                                        <details class="dashboard-synthesis-node dashboard-synthesis-node-operational-objective" data-progressive-accordion-item>
                                            <summary class="dashboard-synthesis-node-summary">
                                                <span class="dashboard-hierarchy-chevron" aria-hidden="true"></span>
                                                <span class="dashboard-node-copy">
                                                    <span class="dashboard-synthesis-level-label">Objectif opérationnel / PAO</span>
                                                    <strong>{{ $operational['label'] ?? '-' }}</strong>
                                                    <small>{{ $operational['direction'] ?? '-' }} · {{ $operational['service'] ?? '-' }}</small>
                                                </span>
                                                <span class="dashboard-progress-cluster is-compact" style="{{ $synthesisProgressStyle($operationalProgress) }}">
                                                    <strong>{{ $fmtPct($operationalProgress) }}</strong>
                                                    <span class="dashboard-progress-track"><span></span></span>
                                                </span>
                                            </summary>

                                            <div class="dashboard-pta-list">
                                                @forelse ($ptas as $pta)
                                                    @php
                                                        $ptaProgress = max(0, min(100, (float) ($pta['progress'] ?? 0)));
                                                        $actions = collect($pta['actions'] ?? [])->values();
                                                    @endphp
                                                    <section class="dashboard-synthesis-node dashboard-synthesis-node-pta">
                                                        <header class="dashboard-pta-header">
                                                            <span class="dashboard-node-copy">
                                                                <span class="dashboard-synthesis-level-label">{{ $pta['code'] ?? 'PTA' }} · {{ $pta['service'] ?? '-' }}</span>
                                                                <strong>{{ $pta['label'] ?? '-' }}</strong>
                                                            </span>
                                                            <span class="dashboard-progress-cluster is-compact" style="{{ $synthesisProgressStyle($ptaProgress) }}">
                                                                <strong>{{ $fmtPct($ptaProgress) }}</strong>
                                                                <span class="dashboard-progress-track"><span></span></span>
                                                            </span>
                                                        </header>

                                                        <div class="dashboard-action-list" data-progressive-accordion-group>
                                                            @forelse ($actions as $action)
                                                                @php
                                                                    $actionProgress = max(0, min(100, (float) ($action['progress'] ?? 0)));
                                                                    $actionIsLate = str_contains(mb_strtolower((string) ($action['delay_status'] ?? '')), 'retard');
                                                                @endphp
                                                                <details class="dashboard-synthesis-node dashboard-synthesis-node-action {{ $actionIsLate ? 'is-late' : '' }}" data-progressive-accordion-item>
                                                                    <summary class="dashboard-synthesis-node-summary">
                                                                        <span class="dashboard-hierarchy-chevron" aria-hidden="true"></span>
                                                                        <span class="dashboard-node-copy">
                                                                            <span class="dashboard-synthesis-level-label">{{ $action['code'] ?? 'ACT' }} · {{ $action['responsible'] ?? '-' }}</span>
                                                                            <strong>{{ $action['label'] ?? '-' }}</strong>
                                                                            <small>Niveau attendu {{ $action['target'] ?? '-' }} · Réalisé {{ $action['realized'] ?? '-' }}</small>
                                                                        </span>
                                                                        <span class="dashboard-progress-cluster is-compact" style="{{ $synthesisProgressStyle($actionProgress) }}">
                                                                            <strong>{{ $fmtPct($actionProgress) }}</strong>
                                                                            <span class="dashboard-progress-track"><span></span></span>
                                                                        </span>
                                                                    </summary>

                                                                    <div class="dashboard-action-detail">
                                                                        <dl class="dashboard-action-facts">
                                                                            <div><dt>Statut</dt><dd>{{ $action['status'] ?? '-' }}</dd></div>
                                                                            <div><dt>Délai</dt><dd>{{ $action['delay_status'] ?? '-' }}</dd></div>
                                                                            <div><dt>Cause</dt><dd>{{ $action['blockage_reason'] ?? '-' }}</dd></div>
                                                                            <div><dt>Preuves</dt><dd>{{ $fmtCount($action['proofs_total'] ?? 0) }}</dd></div>
                                                                        </dl>
                                                                        <div class="dashboard-action-footer">
                                                                            <span>{{ $fmtCount($action['sub_actions_done'] ?? 0) }}/{{ $fmtCount($action['sub_actions_total'] ?? 0) }} sous-action(s) effectuée(s)</span>
                                                                            <a href="{{ $action['detail_url'] ?? '#' }}" class="app-btn app-btn-primary">Ouvrir l’action</a>
                                                                        </div>

                                                                        @if (!empty($action['sub_actions']))
                                                                            <div class="dashboard-sub-action-grid">
                                                                                @foreach ($action['sub_actions'] as $subAction)
                                                                                    @php $subProgress = max(0, min(100, (float) ($subAction['progress'] ?? 0))); @endphp
                                                                                    <article class="dashboard-synthesis-node dashboard-synthesis-node-sub-action">
                                                                                        <span class="dashboard-node-copy">
                                                                                            <span class="dashboard-synthesis-level-label">{{ $subAction['code'] ?? 'SA' }}</span>
                                                                                            <strong>{{ $subAction['label'] ?? '-' }}</strong>
                                                                                            <small>{{ $subAction['responsible'] ?? '-' }} · {{ $subAction['deadline'] ?? '-' }}</small>
                                                                                        </span>
                                                                                        <span class="dashboard-progress-cluster is-compact" style="{{ $synthesisProgressStyle($subProgress) }}">
                                                                                            <strong>{{ $fmtPct($subProgress) }}</strong>
                                                                                            <span class="dashboard-progress-track"><span></span></span>
                                                                                        </span>
                                                                                    </article>
                                                                                @endforeach
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </details>
                                                            @empty
                                                                <x-ui.empty-state title="Aucune action" message="Aucune action PTA rattachée." icon="file" />
                                                            @endforelse
                                                        </div>
                                                    </section>
                                                @empty
                                                    <x-ui.empty-state title="Aucun PTA" message="Aucun PTA rattaché à cet objectif opérationnel." icon="file" />
                                                @endforelse
                                            </div>
                                        </details>
                                    @empty
                                        <x-ui.empty-state title="Aucun objectif opérationnel" message="Aucun objectif opérationnel rattaché." icon="file" />
                                    @endforelse
                                </div>
                            </details>
                        @empty
                            <x-ui.empty-state title="Aucun objectif" message="Aucun objectif stratégique rattaché à cet axe." icon="file" />
                        @endforelse
                    </div>
                </div>
            </details>
        @empty
            <x-ui.empty-state title="Aucune synthèse PAS" message="Aucune action ne permet encore de construire l’arborescence PAS." icon="chart" tone="info" />
        @endforelse
    </div>
</section>
