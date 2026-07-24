<section class="dashboard-tab-panel active" data-dashboard-panel="charts">
    @php
        $decisionChartPayload = is_array($analytics['decision_charts'] ?? null) ? $analytics['decision_charts'] : [];
        $decisionMeta = is_array($decisionChartPayload['meta'] ?? null) ? $decisionChartPayload['meta'] : [];
        $decisionPasEvolution = is_array($decisionChartPayload['pas_evolution'] ?? null) ? $decisionChartPayload['pas_evolution'] : [];
        $decisionAxisProgress = is_array($decisionChartPayload['axis_progress'] ?? null) ? $decisionChartPayload['axis_progress'] : [];
        $decisionStrategicObjectives = is_array($decisionChartPayload['strategic_objectives'] ?? null) ? $decisionChartPayload['strategic_objectives'] : [];
        $decisionOperationalObjectives = is_array($decisionChartPayload['operational_objectives'] ?? null) ? $decisionChartPayload['operational_objectives'] : [];
        $decisionPtaEvolution = is_array($decisionChartPayload['pta_evolution'] ?? null) ? $decisionChartPayload['pta_evolution'] : [];
        $decisionActionStatus = is_array($decisionChartPayload['action_status'] ?? null) ? $decisionChartPayload['action_status'] : [];

        $statusTotal = collect($decisionActionStatus['values'] ?? [])->sum();
        $axisTotal = count((array) ($decisionAxisProgress['labels'] ?? []));
        $strategicTotal = count((array) ($decisionStrategicObjectives['labels'] ?? []));
        $operationalTotal = count((array) ($decisionOperationalObjectives['labels'] ?? []));
        $ptaPoints = count((array) ($decisionPtaEvolution['labels'] ?? []));
    @endphp

    <section class="charts-requested-section">
        <article class="showcase-panel charts-requested-card charts-requested-card-wide">
            <header class="charts-requested-header">
                <div>
                    <h2 class="charts-requested-title">Évolution de l'avancement du PAS</h2>
                    <p class="charts-requested-description">{{ $decisionMeta['period'] ?? $exerciseFilter['label'] ?? 'Période courante' }}</p>
                    <dl class="charts-requested-meta">
                        <div><dt>Variable</dt><dd>Avancement</dd></div>
                        <div><dt>Unité</dt><dd>%</dd></div>
                        <div><dt>Fréquence</dt><dd>Mensuelle</dd></div>
                    </dl>
                </div>
                <label class="charts-requested-select-wrap">
                    <span class="sr-only">Periode du graphique</span>
                    <select class="charts-requested-select" data-requested-area-range>
                        <option value="3">3 derniers mois</option>
                        <option value="6">6 derniers mois</option>
                        <option value="12" selected>12 derniers mois</option>
                        <option value="0">Toutes les périodes</option>
                    </select>
                </label>
            </header>
            <div class="dashboard-canvas dashboard-canvas-requested dashboard-canvas-requested-area">
                <div id="dashboard-requested-area-interactive-chart" class="dashboard-chart-host">
                    <div class="dashboard-chart-fallback" aria-hidden="true">
                        <x-ui.empty-state title="Aucune evolution PAS" message="Les donnees apparaitront apres consolidation." icon="chart" tone="info" />
                    </div>
                </div>
            </div>
        </article>

        <div class="charts-requested-grid">
            <article class="showcase-panel charts-requested-card">
                <header class="charts-requested-header">
                    <div>
                        <h2 class="charts-requested-title">Avancement des axes stratégiques</h2>
                        <p class="charts-requested-description">{{ $axisTotal }} axe(s) dans le périmètre</p>
                        <dl class="charts-requested-meta">
                            <div><dt>Variable</dt><dd>Avancement consolidé</dd></div>
                            <div><dt>Unité</dt><dd>%</dd></div>
                        </dl>
                    </div>
                </header>
                <div class="dashboard-canvas dashboard-canvas-requested">
                    <div id="dashboard-requested-bar-label-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Aucun axe" message="Les axes apparaitront apres consolidation." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <footer class="charts-requested-footer">Référence : 100 % correspond à l'objectif atteint.</footer>
            </article>

            <article class="showcase-panel charts-requested-card">
                <header class="charts-requested-header">
                    <div>
                        <h2 class="charts-requested-title">Avancement des objectifs opérationnels</h2>
                        <p class="charts-requested-description">{{ $operationalTotal }} objectif(s) dans le périmètre</p>
                        <dl class="charts-requested-meta">
                            <div><dt>Variable</dt><dd>Avancement consolidé</dd></div>
                            <div><dt>Unité</dt><dd>%</dd></div>
                        </dl>
                    </div>
                </header>
                <div class="dashboard-canvas dashboard-canvas-requested">
                    <div id="dashboard-requested-bar-custom-label-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Aucun objectif" message="Les objectifs apparaitront apres consolidation." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <footer class="charts-requested-footer">Les objectifs sont classés par niveau d'avancement.</footer>
            </article>

            <article class="showcase-panel charts-requested-card">
                <header class="charts-requested-header">
                    <div>
                        <h2 class="charts-requested-title">Exécution trimestrielle des PTA</h2>
                        <p class="charts-requested-description">{{ $ptaPoints }} période(s) de suivi</p>
                        <dl class="charts-requested-meta">
                            <div><dt>Variables</dt><dd>Prévues, terminées, en retard</dd></div>
                            <div><dt>Unité</dt><dd>Actions</dd></div>
                        </dl>
                    </div>
                </header>
                <div class="dashboard-canvas dashboard-canvas-requested">
                    <div id="dashboard-requested-bar-multiple-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Aucune evolution PTA" message="Les donnees PTA apparaitront apres consolidation." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <footer class="charts-requested-footer">Volumes des actions au regard du calendrier PTA.</footer>
            </article>

            <article class="showcase-panel charts-requested-card">
                <header class="charts-requested-header">
                    <div>
                        <h2 class="charts-requested-title">Répartition des actions par statut</h2>
                        <p class="charts-requested-description">{{ $fmtCount($statusTotal) }} action(s) dans le périmètre</p>
                        <dl class="charts-requested-meta">
                            <div><dt>Variable</dt><dd>Statut de suivi</dd></div>
                            <div><dt>Unité</dt><dd>Actions et %</dd></div>
                        </dl>
                    </div>
                </header>
                <div class="dashboard-canvas dashboard-canvas-requested dashboard-canvas-requested-square">
                    <div id="dashboard-requested-pie-legend-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Aucun statut" message="Les statuts apparaitront apres consolidation." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <footer class="charts-requested-footer">La légende précise le volume et la part de chaque statut.</footer>
            </article>

            <article class="showcase-panel charts-requested-card">
                <header class="charts-requested-header">
                    <div>
                        <h2 class="charts-requested-title">Lecture des niveaux de pilotage</h2>
                        <p class="charts-requested-description">Axes, objectifs stratégiques et opérationnels</p>
                        <dl class="charts-requested-meta">
                            <div><dt>Variable</dt><dd>Avancement consolidé</dd></div>
                            <div><dt>Unité</dt><dd>%</dd></div>
                        </dl>
                    </div>
                </header>
                <div class="dashboard-canvas dashboard-canvas-requested dashboard-canvas-requested-square">
                    <div id="dashboard-requested-radial-label-chart" class="dashboard-chart-host">
                        <div class="dashboard-chart-fallback" aria-hidden="true">
                            <x-ui.empty-state title="Aucun radial" message="Les donnees apparaitront apres consolidation." icon="chart" tone="info" />
                        </div>
                    </div>
                </div>
                <footer class="charts-requested-footer">{{ $axisTotal + $strategicTotal + $operationalTotal }} élément(s) analysé(s).</footer>
            </article>
        </div>
    </section>

    @if ($showRoleOverview)
        @include('partials.dashboard-role-overview', [
            'roleDashboard' => $roleDashboard,
            'dashboardRole' => $dashboardRole,
            'statisticalPolicy' => $statisticalPolicy,
            'officialPolicy' => $officialPolicy,
            'displayMode' => 'charts',
        ])
    @endif
</section>
