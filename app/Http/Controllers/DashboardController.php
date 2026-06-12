<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\ActionLog;
use App\Models\Direction;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use App\Services\Analytics\ReportingAnalyticsService;
use App\Services\Analytics\AnalyticsCacheVersionService;
use App\Services\DashboardProfileSettings;
use App\Services\ExerciceContext;
use App\Services\PersonalTaskService;
use App\Services\WorkflowSettings;
use App\Support\SafeSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DashboardController extends Controller
{
    use AuthorizesPlanningScope;

    private bool $dashboardDirectionResolved = false;
    private ?int $dashboardDirectionId = null;
    private bool $dashboardServiceResolved = false;
    private ?int $dashboardServiceId = null;

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ReportingAnalyticsService $reportingAnalyticsService,
        private readonly DashboardProfileSettings $dashboardProfileSettings,
        private readonly WorkflowSettings $workflowSettings,
        private readonly AnalyticsCacheVersionService $cacheVersionService,
        private readonly ExerciceContext $exerciceContext,
        private readonly ActionStatusService $actionStatusService,
        private readonly PersonalTaskService $personalTaskService
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $this->canReadDashboard($user)) {
            abort(403, 'Accès non autorisé.');
        }
        $user->loadMissing([
            'direction:id,libelle',
            'service:id,libelle',
        ]);

        $view = $request->routeIs('admin.*') ? 'admin.dashboard' : 'dashboard';
        $payload = $this->dashboardPagePayload($user);

        return view($view, [
            'user' => $user,
            'profil' => $user->profileInteractions(),
            'modules' => $user->workspaceModules(),
            'accessScope' => $user->accessScope(),
            'metrics' => $payload['metrics'],
            'dashboardData' => $payload['dashboardData'],
            'dashboardClientData' => $payload['dashboardClientData'],
            'reportingAnalytics' => $payload['reportingAnalytics'],
            'reportingClientAnalytics' => $payload['reportingClientAnalytics'],
            'dgPayload' => $payload['dgPayload'],
            'chartPayload' => $payload['chartPayload'],
            'personalTasks' => $this->personalTaskService->forUser($user, 5),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardPagePayload(User $user): array
    {
        $key = null;

        try {
            $key = $this->dashboardCacheKey($user, 'page');
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Fresh dashboard data is safer than failing when the cache store is unavailable.
        }

        $payload = $this->buildDashboardPagePayload($user);

        if ($key !== null) {
            try {
                Cache::put($key, $payload, now()->addMinutes(5));
            } catch (Throwable) {
                // This cache only speeds up the dashboard; rendering can continue without it.
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDashboardPagePayload(User $user): array
    {
        $today = Carbon::today()->toDateString();

        $pas = $this->buildPasScopedQuery($user);
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $mesures = KpiMesure::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($mesures, $user);
        $scopedActions = (clone $actions)
            ->with([
                'pta:id,pao_id,objectif_operationnel_id,titre,direction_id,service_id',
                'pta.pao:id,pas_id,pas_objectif_id,direction_id,annee,titre,statut',
                'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
                'pta.pao.pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'pta.pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
                'objectifOperationnel:id,pao_id,pas_objectif_id,service_id,libelle,echeance,statut',
                'pta.objectifOperationnel:id,pao_id,pas_objectif_id,service_id,libelle,echeance,statut',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'responsable:id,name',
                'responsables:id,name,service_id',
                // directionValidePar supprime avec la migration de purge direction.
                'justificatifs:id,justifiable_type,justifiable_id,sous_action_id,nom_original,description,ajoute_par,created_at',
                'justificatifs.ajoutePar:id,name',
                'sousActions:id,action_id,agent_id,libelle,statut,est_effectuee,taux_execution,date_fin',
                'sousActions.agent:id,name,service_id',
                'sousActions.justificatifs:id,sous_action_id,nom_original,description,ajoute_par,created_at',
                'sousActions.justificatifs.ajoutePar:id,name',
                'actionKpi:id,action_id,kpi_delai,kpi_performance,kpi_global,progression_reelle,progression_theorique',
            ])
            ->orderByDesc('date_echeance')
            ->get();

        $actionSets = $this->splitDashboardActionCollections($user, $scopedActions);
        $dashboardActions = $actionSets['dashboard'];
        $dashboardValidatedActions = $this->validatedActions($dashboardActions);
        $actionStatusBreakdown = $this->statusCounts($dashboardActions);
        $actionValidationBreakdown = $this->countActionsByAttribute($dashboardActions, 'statut_validation');

        // Perf : chaque paire total/actifs est calculee en UNE requete via
        // SUM(CASE...) au lieu de deux count() separes (3 round-trips economises,
        // gain reseau sur DB distante). Resultat arithmetiquement identique a
        // count(*) + count(*) where statut=... — compatible pgsql et sqlite.
        $pasAgg = (clone $pas)
            ->selectRaw("count(*) as total, sum(case when statut = 'actif' then 1 else 0 end) as actifs")
            ->first();
        $paoAgg = (clone $paos)
            ->selectRaw("count(*) as total, sum(case when statut in ('en_cours', 'valide') then 1 else 0 end) as actifs")
            ->first();
        $ptaAgg = (clone $ptas)
            ->selectRaw("count(*) as total, sum(case when statut = 'en_cours' then 1 else 0 end) as actifs")
            ->first();

        $totals = [
            'pas_total'          => (int) ($pasAgg->total ?? 0),
            'pas_actifs'         => (int) ($pasAgg->actifs ?? 0),
            'paos_total'         => (int) ($paoAgg->total ?? 0),
            'paos_actifs'        => (int) ($paoAgg->actifs ?? 0),
            'ptas_total'         => (int) ($ptaAgg->total ?? 0),
            'ptas_actifs'        => (int) ($ptaAgg->actifs ?? 0),
            'actions_total'      => $dashboardActions->count(),
            'actions_validees'   => $dashboardValidatedActions->count(),
            'kpis_total'         => (clone $kpis)->count(),
            'kpi_mesures_total'  => (clone $mesures)->count(),
        ];

        $statusBreakdown = [
            'paos' => $this->countByStatus($paos, 'statut'),
            'ptas' => $this->countByStatus($ptas, 'statut'),
            'actions' => $actionStatusBreakdown,
            'actions_validation' => $actionValidationBreakdown,
        ];

        $actionsRetard = $dashboardActions
            ->filter(fn (Action $action): bool => $this->isLateForDashboard($action, $today))
            ->count();

        $kpiSousSeuilQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiSousSeuilQuery, $user, 'ptas.direction_id', 'ptas.service_id');
        $this->applyDashboardActionContextFilter($kpiSousSeuilQuery, $user, 'actions.contexte_action');

        $alerts = [
            'actions_en_retard' => $actionsRetard,
            'mesures_kpi_sous_seuil' => $kpiSousSeuilQuery->count(),
            'alertes_action_actives' => $this->activeActionAlertLogsCount($user),
        ];

        $dashboardData = $this->buildDashboardData($user, $scopedActions);
        $reportingAnalytics = $this->buildDashboardReportingPayload($user, $totals, $alerts, $statusBreakdown, $dashboardData);
        $dashboardClientData = $this->buildDashboardClientPayload($dashboardData);
        $reportingClientAnalytics = $this->buildReportingClientPayload($reportingAnalytics);
        $dgPayload = [
            'global_scores' => $dashboardData['global_scores'] ?? [],
            'alert_rows' => $dashboardData['alert_rows'] ?? [],
            'kpi_summary' => $reportingAnalytics['kpiSummary'] ?? [],
        ];

        return [
            'metrics' => [
                'totals' => $totals,
                'alerts' => $alerts,
                'status_breakdown' => $statusBreakdown,
                'action_scope' => [
                    'mode' => $user->isAgent() ? 'personnel' : 'pilotage',
                    'visible_actions_total' => $actionSets['visible']->count(),
                    'personal_actions_total' => $actionSets['personal']->count(),
                    'dashboard_actions_total' => $dashboardActions->count(),
                ],
            ],
            'dashboardData' => $dashboardData,
            'dashboardClientData' => $dashboardClientData,
            'reportingAnalytics' => $reportingAnalytics,
            'reportingClientAnalytics' => $reportingClientAnalytics,
            'dgPayload' => $dgPayload,
            'chartPayload' => $this->buildChartPayload($totals, $alerts, $statusBreakdown),
        ];
    }

    /**
     * Keep the dashboard fast after login by deriving the embedded reporting
     * panel from data already calculated for the dashboard. Full report details
     * remain available from the reporting/export screens.
     *
     * A35 — DIVERGENCE METIER : ce payload reconstruit localement les totaux
     * `pas_total / paos_total / ptas_total / actions_total / actions_validees /
     * kpi_mesures_total` (cf. ligne 'global' plus bas) tandis que
     * `ReportingAnalyticsService::buildPayload(... )['global']` les recalcule
     * de son cote. Les deux DOIVENT toujours retourner les memes valeurs pour
     * un meme user + meme exercice. Toute divergence visible dans le test
     * `tests/Feature/Phase3CDashboardReportingAlignmentTest.php` doit etre
     * traitee comme un bug et corrigee a la racine (pas via cache busting).
     *
     * @param  array<string, int>  $totals
     * @param  array<string, int>  $alerts
     * @param  array<string, array<string, int>>  $statusBreakdown
     * @param  array<string, mixed>  $dashboardData
     * @return array<string, mixed>
     */
    private function buildDashboardReportingPayload(
        User $user,
        array $totals,
        array $alerts,
        array $statusBreakdown,
        array $dashboardData
    ): array {
        $monthly = collect($dashboardData['monthly'] ?? []);
        $unitRows = collect($dashboardData['unit_rows'] ?? []);
        $performanceGaugeMeta = is_array($dashboardData['performance_gauge_meta'] ?? null)
            ? $dashboardData['performance_gauge_meta']
            : ['label' => 'Directions', 'empty_label' => 'Aucune direction disponible pour les jauges.'];
        $performanceGaugeRows = collect($dashboardData['performance_gauge_rows'] ?? []);
        $interannual = collect($dashboardData['interannual'] ?? []);
        $alertRows = collect($dashboardData['alert_rows'] ?? []);
        $actionRows = collect($dashboardData['action_rows'] ?? []);
        $globalScores = is_array($dashboardData['global_scores'] ?? null) ? $dashboardData['global_scores'] : [];
        $policy = [
            'scope_status' => $this->actionCalculationSettings->statisticalScope(),
            'scope_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
            'scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
            'route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
        ];

        return [
            'generatedAt' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ],
            'exercise' => [
                'year' => $this->exerciceContext->selectedYear(),
                'label' => $this->exerciceContext->activeLabel(),
                'quarter' => $this->exerciceContext->selectedQuarter(),
                'quarter_label' => $this->exerciceContext->activeQuarterLabel(),
            ],
            'statisticalPolicy' => $policy,
            'officialPolicy' => [
                'threshold_status' => $policy['scope_status'],
                'threshold_label' => $policy['scope_label'],
                'scope_summary' => $policy['scope_summary'],
                'route_filters' => $policy['route_filters'],
            ],
            'global' => [
                'pas_total' => (int) ($totals['pas_total'] ?? 0),
                'paos_total' => (int) ($totals['paos_total'] ?? 0),
                'ptas_total' => (int) ($totals['ptas_total'] ?? 0),
                'actions_total' => (int) ($totals['actions_total'] ?? 0),
                'actions_validees' => (int) ($totals['actions_validees'] ?? 0),
                'kpi_mesures_total' => (int) ($totals['kpi_mesures_total'] ?? 0),
                'objectifs_operationnels_total' => 0,
            ],
            'kpiSummary' => [
                'delai' => (float) ($globalScores['delai'] ?? 0),
                'performance' => (float) ($globalScores['performance'] ?? 0),
                'conformite' => (float) ($globalScores['conformite'] ?? 0),
                'global' => (float) ($globalScores['global'] ?? 0),
            ],
            'managedKpis' => [],
            'statuts' => $statusBreakdown,
            'alertes' => $alerts,
            'pasConsolidation' => $this->buildDashboardPasConsolidation($user),
            'interannualComparison' => $interannual->values()->all(),
            'charts' => [
                'funnel' => [
                    'labels' => ['PAS', 'PAO', 'PTA', 'Actions'],
                    'values' => [
                        (int) ($totals['pas_total'] ?? 0),
                        (int) ($totals['paos_total'] ?? 0),
                        (int) ($totals['ptas_total'] ?? 0),
                        (int) ($totals['actions_total'] ?? 0),
                    ],
                    'urls' => [
                        route('workspace.pas.index'),
                        route('workspace.pao.index'),
                        route('workspace.pta.index'),
                        route('workspace.actions.index'),
                    ],
                ],
                'status_by_unit' => [
                    'unit_label' => (string) ($dashboardData['unit_mode_label'] ?? 'unite'),
                    'labels' => $unitRows->pluck('label')->take(8)->values()->all(),
                    'datasets' => [[
                        'label' => 'Actions',
                        'data' => $unitRows->pluck('actions_total')->take(8)->map(fn ($value): int => (int) $value)->values()->all(),
                    ]],
                    'urls' => [$unitRows->pluck('url')->take(8)->values()->all()],
                ],
                'progress_weekly' => [
                    'labels' => $monthly->pluck('label')->values()->all(),
                    'reel' => $monthly->pluck('global')->map(fn ($value): float => (float) $value)->values()->all(),
                    'theorique' => $monthly->map(fn (): int => 80)->values()->all(),
                    'urls' => $monthly->pluck('url')->values()->all(),
                ],
                'kpi_trend' => [
                    'labels' => $monthly->pluck('label')->values()->all(),
                    'valeurs' => $monthly->pluck('global')->map(fn ($value): float => (float) $value)->values()->all(),
                    'cibles' => $monthly->map(fn (): int => 80)->values()->all(),
                    'seuils' => $monthly->map(fn (): int => 60)->values()->all(),
                    'urls' => $monthly->pluck('url')->values()->all(),
                ],
                'interannual_overview' => [
                    'labels' => $interannual->pluck('annee')->map(fn ($value): string => (string) $value)->values()->all(),
                    'actions_total' => $interannual->pluck('actions_total')->map(fn ($value): int => (int) $value)->values()->all(),
                    'actions_validees' => $interannual->pluck('actions_validees')->map(fn ($value): int => (int) $value)->values()->all(),
                    'progression_moyenne' => $interannual->pluck('progression_moyenne')->map(fn ($value): float => (float) $value)->values()->all(),
                    'urls' => $interannual->pluck('url')->values()->all(),
                ],
                'retard_heatmap' => ['weeks' => [], 'units' => [], 'matrix' => [], 'max' => 0],
                'critical_gantt' => [
                    'min' => now()->subDays(14)->toDateString(),
                    'max' => now()->addDays(14)->toDateString(),
                    'items' => [],
                ],
                'resource_treemap' => ['labels' => [], 'values' => [], 'total' => 0],
                'performance_gauge' => [
                    'scope_label' => (string) ($performanceGaugeMeta['label'] ?? 'Directions'),
                    'empty_label' => (string) ($performanceGaugeMeta['empty_label'] ?? 'Aucune donnée disponible pour les jauges.'),
                    'labels' => $performanceGaugeRows->pluck('label')->take(6)->values()->all(),
                    'values' => $performanceGaugeRows->pluck('kpi_global')->take(6)->map(fn ($value): float => (float) $value)->values()->all(),
                    'urls' => $performanceGaugeRows->pluck('url')->take(6)->values()->all(),
                ],
            ],
            'details' => [
                'actions_retard' => collect(),
                'kpi_sous_seuil' => collect(),
                'structure_rapports' => collect(),
                'direction_service_report' => collect(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDashboardPasConsolidation(User $user): array
    {
        $pasRows = $this->buildPasScopedQuery($user)
            ->with(['axes.objectifs'])
            ->orderByDesc('periode_fin')
            ->get();

        if ($pasRows->isEmpty()) {
            return [];
        }

        return $pasRows
            ->map(function (Pas $pas) use ($user): array {
                $paoQuery = Pao::query()->where('pas_id', (int) $pas->id);
                $this->scopePao($paoQuery, $user);

                $paos = $paoQuery
                    ->with([
                        'ptas' => function ($query) use ($user): void {
                            $this->scopePta($query, $user);
                            $query->with([
                                'actions' => function ($actionQuery) use ($user): void {
                                    $this->scopeAction($actionQuery, $user);
                                },
                            ]);
                        },
                    ])
                    ->get();

                $ptas = $paos->flatMap->ptas;
                $actions = $ptas->flatMap->actions;
                $validatedActions = $this->validatedActions($actions);
                $actionsTotal = $actions->count();
                $actionsValidees = $validatedActions->count();

                return [
                    'id' => (int) $pas->id,
                    'titre' => (string) $pas->titre,
                    'url' => route('workspace.pao.index', ['pas_id' => (int) $pas->id]),
                    'periode' => (string) $pas->periode_debut.'-'.$pas->periode_fin,
                    'statut' => (string) $pas->statut,
                    'axes_total' => $pas->axes->count(),
                    'objectifs_total' => $pas->axes->sum(fn ($axe): int => $axe->objectifs->count()),
                    'paos_total' => $paos->count(),
                    'ptas_total' => $ptas->count(),
                    'actions_total' => $actionsTotal,
                    'actions_validees' => $actionsValidees,
                    'progression_moyenne' => $actionsValidees > 0
                        ? round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                        : 0.0,
                    'taux_realisation' => $actionsTotal > 0 ? round(($actionsValidees / $actionsTotal) * 100, 2) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    private function dashboardCacheKey(User $user, string $segment): string
    {
        return 'dashboard:'.$this->cacheVersionService->dashboardVersion().':'.$segment.':'.sha1(json_encode([
            'user_id' => (int) $user->id,
            'role' => (string) $user->role,
            'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
            'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
            'statistical_scope' => $this->actionCalculationSettings->statisticalScope(),
            'alert_version' => (int) Cache::get('alert-center:version', 1),
            'exercice' => $this->exerciceContext->selectedYear(),
            'trimestre' => $this->exerciceContext->selectedQuarter(),
            'direction_filter' => $this->selectedDashboardDirectionId($user),
            'service_filter' => $this->selectedDashboardServiceId($user),
        ], JSON_THROW_ON_ERROR));
    }
    /**
     * Keep the embedded dashboard JSON small. The Blade view still receives the
     * full server payload for tables, but the browser only needs chart-ready rows.
     *
     * @return array<string, mixed>
     */
    private function buildDashboardClientPayload(array $dashboardData): array
    {
        $keys = [
            'dashboard_role',
            'direction_selector',
            'exercise',
            'actions_index_url',
            'personal_actions_summary',
            'official_action_filters',
            'unit_mode_label',
            'global_scores',
            'status_cards',
            'monthly',
            'unit_rows',
            'synthesis_service_rows',
            'synthesis_agent_rows',
            'decision_counts',
            'decision_service_rows',
            'decision_agent_rows',
            'decision_quarter_rows',
            'interannual',
            'action_rows',
            'gantt_rows',
            'bullet_rows',
            'scatter_points',
            'radar_datasets',
            'top_action_bars',
            'role_dashboard',
        ];

        return array_intersect_key($dashboardData, array_flip($keys));
    }

    /**
     * Reporting details may contain Eloquent collections used by the Blade tables.
     * They must not be mirrored into the JSON block, otherwise /dashboard can spend
     * seconds serializing data that the JavaScript never reads.
     *
     * @return array<string, mixed>
     */
    private function buildReportingClientPayload(array $reportingAnalytics): array
    {
        return [
            'charts' => is_array($reportingAnalytics['charts'] ?? null)
                ? $reportingAnalytics['charts']
                : [],
        ];
    }

    private function scopePao(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id');
        $this->exerciceContext->applyToPao($query);
        $this->applySelectedDashboardDirectionToDirectColumn($query, $user, 'direction_id');
        if (($serviceId = $this->selectedDashboardServiceId($user)) !== null) {
            $query->where(function (Builder $paoQuery) use ($serviceId): void {
                $paoQuery->where('service_id', $serviceId)
                    ->orWhereHas('objectifsOperationnels', fn (Builder $objectifQuery) => $objectifQuery->where('service_id', $serviceId))
                    ->orWhereHas('ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
            });
        }
    }

    private function scopePta(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        $this->exerciceContext->applyToPta($query);
        $this->applySelectedDashboardDirectionToDirectColumn($query, $user, 'direction_id');
        $this->applySelectedDashboardServiceToDirectColumn($query, $user, 'service_id');
    }

    private function scopeAction(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToAction($query);
        $this->applySelectedDashboardDirectionToPtaRelation($query, $user);
        $this->applySelectedDashboardServiceToPtaRelation($query, $user);

        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(function (Builder $agentQuery) use ($user): void {
                $agentQuery->where('responsable_id', (int) $user->id)
                    ->orWhereHas('responsables', fn (Builder $q) => $q->whereKey((int) $user->id))
                    ->orWhereHas('sousActions', fn (Builder $q) => $q->where('agent_id', (int) $user->id));
            });
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeKpi(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToKpi($query);
        $this->applySelectedDashboardDirectionToActionRelation($query, $user);
        $this->applySelectedDashboardServiceToActionRelation($query, $user);

        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $actionQuery->where('responsable_id', (int) $user->id)
                    ->orWhereHas('responsables', fn (Builder $q) => $q->whereKey((int) $user->id))
                    ->orWhereHas('sousActions', fn (Builder $q) => $q->where('agent_id', (int) $user->id));
            });
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeMesure(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToMesure($query);
        $this->applySelectedDashboardDirectionToMeasureRelation($query, $user);
        $this->applySelectedDashboardServiceToMeasureRelation($query, $user);

        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->whereHas('kpi.action', function (Builder $actionQuery) use ($user): void {
                $actionQuery->where('responsable_id', (int) $user->id)
                    ->orWhereHas('responsables', fn (Builder $q) => $q->whereKey((int) $user->id))
                    ->orWhereHas('sousActions', fn (Builder $q) => $q->where('agent_id', (int) $user->id));
            });
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('direction_id', (int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeJoinedPta(
        Builder $query,
        User $user,
        string $directionColumn,
        string $serviceColumn
    ): void {
        $this->exerciceContext->applyToJoinedPta($query);
        if (($directionId = $this->selectedDashboardDirectionId($user)) !== null) {
            $query->where($directionColumn, $directionId);
        }
        if (($serviceId = $this->selectedDashboardServiceId($user)) !== null) {
            $query->where($serviceColumn, $serviceId);
        }

        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where('actions.responsable_id', (int) $user->id);
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->where($directionColumn, (int) $user->direction_id);
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->where($serviceColumn, (int) $user->service_id);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function buildPasScopedQuery(User $user): Builder
    {
        $query = Pas::query();

        if ($user->isAgent()) {
            $query->whereHas('paos.ptas.actions', fn (Builder $q) => $q->where('responsable_id', (int) $user->id));
            $this->exerciceContext->applyToPas($query);
            return $query;
        }

        $this->scopePasByUser($query, $user);
        $this->exerciceContext->applyToPas($query);
        if (($directionId = $this->selectedDashboardDirectionId($user)) !== null) {
            $query->where(function (Builder $pasQuery) use ($directionId): void {
                $pasQuery->whereHas('paos', fn (Builder $paoQuery) => $paoQuery->where('direction_id', $directionId))
                    ->orWhereHas('directions', fn (Builder $directionQuery) => $directionQuery->whereKey($directionId));
            });
        }
        if (($serviceId = $this->selectedDashboardServiceId($user)) !== null) {
            $query->where(function (Builder $pasQuery) use ($serviceId): void {
                $pasQuery->whereHas('paos.objectifsOperationnels', fn (Builder $objectifQuery) => $objectifQuery->where('service_id', $serviceId))
                    ->orWhereHas('paos.ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
            });
        }

        return $query;
    }

    private function selectedDashboardDirectionId(User $user): ?int
    {
        if ($this->dashboardDirectionResolved) {
            return $this->dashboardDirectionId;
        }

        $this->dashboardDirectionResolved = true;
        $this->dashboardDirectionId = null;

        if (! $user->hasGlobalReadAccess()) {
            return null;
        }

        $rawValue = trim((string) request()->query('direction_id', ''));
        if ($rawValue === '' || $rawValue === 'all' || ! is_numeric($rawValue)) {
            return null;
        }

        $directionId = (int) $rawValue;
        if ($directionId <= 0) {
            return null;
        }

        $this->dashboardDirectionId = Direction::query()
            ->whereKey($directionId)
            ->where('actif', true)
            ->exists()
                ? $directionId
                : null;

        return $this->dashboardDirectionId;
    }

    private function selectedDashboardServiceId(User $user): ?int
    {
        if ($this->dashboardServiceResolved) {
            return $this->dashboardServiceId;
        }

        $this->dashboardServiceResolved = true;
        $this->dashboardServiceId = null;

        if (! $user->hasGlobalReadAccess()) {
            return null;
        }

        $directionId = $this->selectedDashboardDirectionId($user);
        if ($directionId === null) {
            return null;
        }

        $rawValue = trim((string) request()->query('service_id', ''));
        if ($rawValue === '' || $rawValue === 'all' || ! is_numeric($rawValue)) {
            return null;
        }

        $serviceId = (int) $rawValue;
        if ($serviceId <= 0) {
            return null;
        }

        $this->dashboardServiceId = Service::query()
            ->whereKey($serviceId)
            ->where('direction_id', $directionId)
            ->where('actif', true)
            ->exists()
                ? $serviceId
                : null;

        return $this->dashboardServiceId;
    }

    private function dashboardDirectionContext(User $user): array
    {
        $enabled = $user->hasGlobalReadAccess()
            && ! $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE, User::ROLE_AGENT);

        $selectedId = $this->selectedDashboardDirectionId($user);
        $selectedServiceId = $this->selectedDashboardServiceId($user);
        $directions = $enabled
            ? Direction::query()
                ->where('actif', true)
                ->orderBy('code')
                ->orderBy('libelle')
                ->get(['id', 'code', 'libelle'])
            : collect();

        $selected = $selectedId !== null
            ? $directions->firstWhere('id', $selectedId)
            : null;
        $services = ($enabled && $selectedId !== null)
            ? Service::query()
                ->where('direction_id', $selectedId)
                ->where('actif', true)
                ->orderBy('code')
                ->orderBy('libelle')
                ->get(['id', 'code', 'libelle'])
            : collect();
        $selectedService = $selectedServiceId !== null
            ? $services->firstWhere('id', $selectedServiceId)
            : null;

        return [
            'enabled' => $enabled,
            'selected_id' => $selectedId,
            'selected_label' => $selected instanceof Direction
                ? trim((string) ($selected->code ?: '').' - '.(string) $selected->libelle, ' -')
                : 'Synthèse globale',
            'service_selected_id' => $selectedServiceId,
            'service_selected_label' => $selectedService instanceof Service
                ? trim((string) ($selectedService->code ?: '').' - '.(string) $selectedService->libelle, ' -')
                : 'Tous les services',
            'options' => $directions
                ->map(fn (Direction $direction): array => [
                    'id' => (int) $direction->id,
                    'label' => trim((string) ($direction->code ?: '').' - '.(string) $direction->libelle, ' -'),
                ])
                ->values()
                ->all(),
            'service_options' => $services
                ->map(fn (Service $service): array => [
                    'id' => (int) $service->id,
                    'label' => trim((string) ($service->code ?: '').' - '.(string) $service->libelle, ' -'),
                ])
                ->values()
                ->all(),
        ];
    }

    private function applySelectedDashboardDirectionToDirectColumn(Builder|Relation $query, User $user, string $column): void
    {
        if (($directionId = $this->selectedDashboardDirectionId($user)) === null) {
            return;
        }

        $query->where($column, $directionId);
    }

    private function applySelectedDashboardDirectionToPtaRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDashboardDirectionId($user)) === null) {
            return;
        }

        $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
    }

    private function applySelectedDashboardDirectionToActionRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDashboardDirectionId($user)) === null) {
            return;
        }

        $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
    }

    private function applySelectedDashboardDirectionToMeasureRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDashboardDirectionId($user)) === null) {
            return;
        }

        $query->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
    }

    private function applySelectedDashboardServiceToDirectColumn(Builder|Relation $query, User $user, string $column): void
    {
        if (($serviceId = $this->selectedDashboardServiceId($user)) === null) {
            return;
        }

        $query->where($column, $serviceId);
    }

    private function applySelectedDashboardServiceToPtaRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedDashboardServiceId($user)) === null) {
            return;
        }

        $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
    }

    private function applySelectedDashboardServiceToActionRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedDashboardServiceId($user)) === null) {
            return;
        }

        $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
    }

    private function applySelectedDashboardServiceToMeasureRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedDashboardServiceId($user)) === null) {
            return;
        }

        $query->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(Builder $query, string $statusColumn): array
    {
        $statusColumn = SafeSql::identifier($statusColumn, [
            'statut',
            'statut_dynamique',
            'statut_validation',
            'statut_realisation',
        ]);

        /** @var array<string, int> $result */
        $result = (clone $query)
            ->selectRaw("{$statusColumn} as status_label, COUNT(*) as total")
            ->groupBy($statusColumn)
            ->pluck('total', 'status_label')
            ->map(fn ($value): int => (int) $value)
            ->toArray();

        return $result;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array{visible:Collection<int, Action>, dashboard:Collection<int, Action>, personal:Collection<int, Action>}
     */
    private function splitDashboardActionCollections(User $user, Collection $actions): array
    {
        $visibleActions = $actions->values();
        $personalActions = $visibleActions
            ->filter(fn (Action $action): bool => (int) ($action->responsable_id ?? 0) === (int) $user->id)
            ->values();

        $dashboardActions = $user->isAgent()
            ? $visibleActions
            : $visibleActions
                ->filter(fn (Action $action): bool => $this->isPilotageDashboardAction($action, $user))
                ->values();

        return [
            'visible' => $visibleActions,
            'dashboard' => $dashboardActions,
            'personal' => $personalActions,
        ];
    }

    private function isPilotageDashboardAction(Action $action, User $user): bool
    {
        $context = (string) ($action->contexte_action ?: Action::CONTEXT_PILOTAGE);
        return $context === Action::CONTEXT_PILOTAGE;
    }

    private function applyDashboardActionContextFilter(
        Builder $query,
        User $user,
        string $contextColumn
    ): void {
        if ($user->isAgent()) {
            return;
        }

        $query->where(function (Builder $contextQuery) use ($contextColumn): void {
            $contextQuery->whereNull($contextColumn)
                ->orWhere($contextColumn, Action::CONTEXT_PILOTAGE);
        });

    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, int>
     */
    private function countActionsByAttribute(Collection $actions, string $attribute): array
    {
        /** @var array<string, int> $counts */
        $counts = $actions
            ->groupBy(fn (Action $action): string => (string) ($action->{$attribute} ?? 'non_renseigne'))
            ->map(fn (Collection $rows): int => $rows->count())
            ->toArray();

        return $counts;
    }

    private function isLateForDashboard(Action $action, string $today): bool
    {
        if (! $action->date_echeance instanceof Carbon) {
            return false;
        }

        if ($action->date_echeance->toDateString() >= $today) {
            return false;
        }

        return ! in_array((string) ($action->statut_dynamique ?? ''), [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_SUSPENDU,
            ActionTrackingService::STATUS_ANNULE,
        ], true);
    }

    /**
     * @param array<string, int> $totals
     * @param array<string, int> $alerts
     * @param array<string, array<string, int>> $statusBreakdown
     * @return array<string, mixed>
     */
    private function buildChartPayload(array $totals, array $alerts, array $statusBreakdown): array
    {
        $statusModules = [
            'PAO' => $statusBreakdown['paos'] ?? [],
            'PTA' => $statusBreakdown['ptas'] ?? [],
            'Actions' => $statusBreakdown['actions'] ?? [],
        ];

        $statusLabels = [];
        foreach ($statusModules as $moduleRows) {
            foreach (array_keys($moduleRows) as $statusKey) {
                if (! in_array($statusKey, $statusLabels, true)) {
                    $statusLabels[] = $statusKey;
                }
            }
        }

        $statusSeries = [];
        foreach ($statusLabels as $statusLabel) {
            $values = [];
            foreach ($statusModules as $moduleRows) {
                $values[] = (int) ($moduleRows[$statusLabel] ?? 0);
            }

            $statusSeries[] = [
                'label' => $statusLabel,
                'values' => $values,
            ];
        }

        return [
            'volumes' => [
                'labels' => ['PAS', 'PAO', 'PTA', 'Actions', 'Indicateurs', 'Mesures d indicateur'],
                'values' => [
                    (int) ($totals['pas_total'] ?? 0),
                    (int) ($totals['paos_total'] ?? 0),
                    (int) ($totals['ptas_total'] ?? 0),
                    (int) ($totals['actions_total'] ?? 0),
                    (int) ($totals['kpis_total'] ?? 0),
                    (int) ($totals['kpi_mesures_total'] ?? 0),
                ],
            ],
            'alerts' => [
                'labels' => ['Actions en retard', 'Indicateurs sous seuil', 'Alertes workflow actives'],
                'values' => [
                    (int) ($alerts['actions_en_retard'] ?? 0),
                    (int) ($alerts['mesures_kpi_sous_seuil'] ?? 0),
                    (int) ($alerts['alertes_action_actives'] ?? 0),
                ],
            ],
            'status' => [
                'module_labels' => array_keys($statusModules),
                'series' => $statusSeries,
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildDashboardData(User $user, Collection $actions): array
    {
        $actionSets = $this->splitDashboardActionCollections($user, $actions);
        $personalActions = $actionSets['personal'];
        $actions = $actionSets['dashboard'];
        $validatedActions = $this->validatedActions($actions);
        $officialActions = $validatedActions;
        $dashboardRole = $this->resolveDashboardRole($user);
        $currentYear = (int) ($this->exerciceContext->selectedYear() ?? now()->year);
        $unitMeta = $this->resolveUnitMeta($user);
        $unitRows = $this->buildUnitRows($actions, (string) $unitMeta['mode']);
        $synthesisObjectiveRows = $this->buildSynthesisObjectiveRows($actions);
        $synthesisPaoRows = $this->buildSynthesisPaoRows($actions);
        $synthesisPtaRows = $this->buildSynthesisPtaRows($actions);
        $synthesisServiceRows = $this->buildUnitRows($actions, 'service');
        $synthesisAgentRows = $this->buildServiceAgentPerformanceRows($actions, 12);
        $synthesisLateRows = $this->buildLateActionRows($actions, 12);
        $decisionCounts = $this->buildDecisionCounts($actions);
        $decisionChainRows = $this->buildDecisionChainRows($actions);
        $decisionServiceRows = $this->buildDecisionServiceRows($actions);
        $decisionAgentRows = $this->buildDecisionAgentRows($actions);
        $decisionPriorityRows = $this->buildDecisionPriorityRows($actions);
        $decisionLateRows = $this->buildDecisionLateRows($actions);
        $decisionPendingValidationRows = $this->buildDecisionPendingValidationRows($actions);
        $decisionProofRows = $this->buildDecisionProofRows($actions);
        $decisionAnomalyRows = $this->buildDecisionAnomalyRows($actions);
        $decisionQuarterRows = $this->buildDecisionQuarterRows($actions, $currentYear);
        $directionPerformanceRows = $this->buildDirectionPerformanceRows($actions);
        $pasDirectionRows = $this->buildPasDirectionRows($actions);
        $paoDirectionRows = $this->buildPaoDirectionRows($actions);
        $ptaServiceActionRows = $this->buildPtaServiceActionRows($actions);
        $agentActionRows = $this->buildAgentActionRows($actions);
        $subActionRows = $this->buildSubActionRows($actions);
        $performanceGaugeMeta = $this->resolvePerformanceGaugeMeta($user);
        $performanceGaugeRows = $this->buildPerformanceGaugeRows($user, $actions);
        $interannual = $this->buildInterannualComparison($user);
        $statusCards = $this->buildStatusCards($actions);
        $officialStatusCards = $this->buildStatusCards($officialActions);
        $alerts = $this->buildDashboardAlertRows($actions);
        $roleDashboard = $this->buildRoleDashboard($user, $actions, $validatedActions);

        $avg = static function (Collection $items, callable $callback): float {
            if ($items->isEmpty()) {
                return 0.0;
            }

            return round((float) $items->avg($callback), 2);
        };

        $operationalGlobalScores = $this->buildGlobalScoreSummary($actions, $avg);
        $globalScores = $this->buildGlobalScoreSummary($officialActions, $avg);
        $operationalMonthly = $this->buildMonthlyScoreRows($actions, $currentYear, $avg, false);
        $monthly = $this->buildMonthlyScoreRows($officialActions, $currentYear, $avg, true);

        $actionRows = $actions
            ->sortByDesc(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0))
            ->take(12)
            ->map(function (Action $action): array {
                $statusKey = $this->dashboardStatus($action);
                $target = max(0.0, (float) ($action->quantite_cible ?? 0));
                $realized = max(0.0, (float) ($action->quantite_realisee ?? 0));
                $targetRate = $target > 0.0
                    ? min(100.0, ($realized / $target) * 100)
                    : (float) ($action->taux_atteinte_cible ?? 0);
                $overrunRate = $target > 0.0 && $realized > $target
                    ? (($realized - $target) / $target) * 100
                    : 0.0;

                return [
                    'id' => (int) $action->id,
                    'libelle' => (string) $action->libelle,
                    'direction' => (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'statut' => $statusKey,
                    'statut_label' => $this->statusLabel($statusKey),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'kpi_global' => round((float) ($action->actionKpi?->kpi_global ?? 0), 2),
                    'kpi_delai' => round((float) ($action->actionKpi?->kpi_delai ?? 0), 2),
                    'kpi_performance' => round((float) ($action->actionKpi?->kpi_performance ?? 0), 2),
                    'kpi_conformite' => round(0.0, 2),
                    'date_debut' => $action->date_debut instanceof Carbon ? $action->date_debut->format('d/m/Y') : '-',
                    'date_fin' => $action->date_fin instanceof Carbon ? $action->date_fin->format('d/m/Y') : '-',
                    'mode_evaluation' => $action->resolvedEvaluationMode(),
                    'unite_cible' => (string) ($action->unite_cible ?? ''),
                    'quantite_cible' => round($target, 4),
                    'quantite_realisee' => round($realized, 4),
                    'reste_a_realiser' => round(max(0.0, $target - $realized), 4),
                    'taux_atteinte_cible' => round($targetRate, 2),
                    'taux_depassement' => round($overrunRate, 2),
                    'has_quantitative_target' => $target > 0.0,
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();

        $ganttRows = $actions
            ->filter(fn (Action $action): bool => $action->date_debut instanceof Carbon && $action->date_fin instanceof Carbon)
            ->sortBy(fn (Action $action): string => $action->date_debut instanceof Carbon ? $action->date_debut->toDateString() : '')
            ->take(10)
            ->map(function (Action $action): array {
                $statusKey = $this->dashboardStatus($action);

                return [
                    'id' => (int) $action->id,
                    'libelle' => (string) $action->libelle,
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'statut' => $statusKey,
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'date_debut' => $action->date_debut instanceof Carbon ? $action->date_debut->toDateString() : null,
                    'date_fin' => $action->date_fin instanceof Carbon ? $action->date_fin->toDateString() : null,
                    'date_debut_label' => $action->date_debut instanceof Carbon ? $action->date_debut->format('d/m') : '-',
                    'date_fin_label' => $action->date_fin instanceof Carbon ? $action->date_fin->format('d/m') : '-',
                    'color' => $this->statusColor($statusKey),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();

        $bulletRows = collect($actionRows)
            ->take(8)
            ->map(fn (array $row): array => [
                'label' => $row['libelle'],
                'real' => (float) $row['kpi_global'],
                'target' => 80.0,
                'url' => (string) $row['url'],
            ])
            ->all();

        $scatterPoints = $actions
            ->filter(fn (Action $action): bool => $action->actionKpi instanceof ActionKpi)
            ->sortByDesc(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0))
            ->take(18)
            ->map(function (Action $action): array {
                $global = (float) ($action->actionKpi?->kpi_global ?? 0);

                return [
                    'label' => (string) $action->id,
                    'x' => round((float) ($action->actionKpi?->kpi_performance ?? 0), 2),
                    'y' => round(0.0, 2),
                    'r' => max(5, min(12, (int) round($global / 10))),
                    'color' => $global >= 80 ? '#8FC043' : ($global >= 60 ? '#3996D3' : '#F9B13C'),
                    'title' => (string) $action->libelle,
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();

        $radarDatasets = collect($unitRows)
            ->take(3)
            ->values()
            ->map(function (array $row, int $index): array {
                $palette = ['#3996D3', '#8FC043', '#F0E509'];

                return [
                    'label' => (string) $row['label'],
                    'borderColor' => $palette[$index % count($palette)],
                    'backgroundColor' => $palette[$index % count($palette)].'26',
                    'url' => (string) ($row['url'] ?? ''),
                    'data' => [
                        round((float) ($row['kpi_delai'] ?? 0), 2),
                        round((float) ($row['kpi_performance'] ?? 0), 2),
                        round((float) ($row['kpi_conformite'] ?? 0), 2),
                        round((float) ($row['progression_moyenne'] ?? 0), 2),
                    ],
                ];
            })
            ->all();

        $topActionBars = collect($actionRows)
            ->take(6)
            ->map(fn (array $row): array => [
                'label' => (string) $row['libelle'],
                'value' => (float) $row['kpi_global'],
                'color' => $this->kpiColor((float) $row['kpi_global']),
                'url' => (string) $row['url'],
            ])
            ->all();

        return [
            'dashboard_role' => $dashboardRole,
            'direction_selector' => $this->dashboardDirectionContext($user),
            'exercise' => [
                'year' => $this->exerciceContext->selectedYear(),
                'label' => $this->exerciceContext->activeLabel(),
                'quarter' => $this->exerciceContext->selectedQuarter(),
                'quarter_label' => $this->exerciceContext->activeQuarterLabel(),
            ],
            'actions_index_url' => $this->actionIndexRoute(),
            'personal_actions_summary' => [
                'total' => $personalActions->count(),
                'late' => $personalActions
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
                    ->count(),
                'url' => $this->actionIndexRoute(['vue' => 'mes_actions']),
            ],
            'official_action_filters' => $this->actionCalculationSettings->officialRouteFilters(),
            'unit_mode_label' => (string) $unitMeta['label'],
            'operational_global_scores' => $operationalGlobalScores,
            'global_scores' => $globalScores,
            'operational_status_cards' => $statusCards,
            'official_status_cards' => $officialStatusCards,
            'operational_monthly' => $operationalMonthly,
            'status_cards' => $statusCards,
            'monthly' => $monthly,
            'unit_rows' => $unitRows,
            'synthesis_objective_rows' => $synthesisObjectiveRows,
            'synthesis_pao_rows' => $synthesisPaoRows,
            'synthesis_pta_rows' => $synthesisPtaRows,
            'synthesis_service_rows' => $synthesisServiceRows,
            'synthesis_agent_rows' => $synthesisAgentRows,
            'synthesis_late_rows' => $synthesisLateRows,
            'decision_counts' => $decisionCounts,
            'decision_chain_rows' => $decisionChainRows,
            'decision_service_rows' => $decisionServiceRows,
            'decision_agent_rows' => $decisionAgentRows,
            'decision_priority_rows' => $decisionPriorityRows,
            'decision_late_rows' => $decisionLateRows,
            'decision_pending_validation_rows' => $decisionPendingValidationRows,
            'decision_proof_rows' => $decisionProofRows,
            'decision_anomaly_rows' => $decisionAnomalyRows,
            'decision_quarter_rows' => $decisionQuarterRows,
            'direction_performance_rows' => $directionPerformanceRows,
            'pas_direction_rows' => $pasDirectionRows,
            'pao_direction_rows' => $paoDirectionRows,
            'pta_service_action_rows' => $ptaServiceActionRows,
            'agent_action_rows' => $agentActionRows,
            'sub_action_rows' => $subActionRows,
            'performance_gauge_meta' => $performanceGaugeMeta,
            'performance_gauge_rows' => $performanceGaugeRows,
            'interannual' => $interannual,
            'action_rows' => $actionRows,
            'gantt_rows' => $ganttRows,
            'bullet_rows' => $bulletRows,
            'scatter_points' => $scatterPoints,
            'radar_datasets' => $radarDatasets,
            'top_action_bars' => $topActionBars,
            'alert_rows' => $alerts,
            'role_dashboard' => $roleDashboard,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $role = $this->resolveDashboardRole($user);

        $dashboard = match ($role) {
            'agent' => $this->buildAgentRoleDashboard($actions),
            'service' => $this->buildServiceRoleDashboard($actions),
            'direction' => $this->buildDirectionRoleDashboard($user, $actions, $validatedActions),
            'planification' => $this->buildPlanificationRoleDashboard($user, $actions, $validatedActions),
            'dg' => $this->buildDgRoleDashboard($user, $actions, $validatedActions),
            'cabinet' => $this->buildCabinetRoleDashboard($user, $actions, $validatedActions),
            default => [
                'enabled' => false,
                'role' => $role,
            ],
        };

        if (($dashboard['enabled'] ?? false) !== true) {
            return $dashboard;
        }

        return $this->dashboardProfileSettings->applyToDashboard($role, $dashboard);
    }

    private function resolveDashboardRole(User $user): string
    {
        // DG — pilotage stratégique
        if ($user->hasRole(User::ROLE_DG)) {
            return 'dg';
        }

        if ($user->hasRole(User::ROLE_ADMIN_FONCTIONNEL, User::ROLE_PLANIFICATION)) {
            return 'planification';
        }

        if ($user->isPlanningControlChief()) {
            return 'planification';
        }

        if ($user->hasRole(User::ROLE_AUDITEUR)) {
            return 'global';
        }

        if ($user->isAgent()) {
            return 'agent';
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
            return 'service';
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        // Cabinet, supervision Cabinet, supervision DGA — vue d'ensemble lecture
        if ($user->hasRole(
            User::ROLE_CABINET,
            User::ROLE_COLLABORATEUR,
            User::ROLE_CABINET_SUPERVISION,
            User::ROLE_DGA_SUPERVISION,
        )) {
            return 'cabinet';
        }

        // Planification, SCIQ suivi global, Admin fonctionnel, Chefs d'unité
        // (SCIQ/DGA/Cabinet) — vue globale avec capacité de pilotage
        if ($user->hasRole(
            User::ROLE_ADMIN,
            User::ROLE_ADMIN_FONCTIONNEL,
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
        )) {
            return 'planification';
        }

        // Auditeur / invité — vue lecture seule globale
        if ($user->hasRole(User::ROLE_AUDITEUR, User::ROLE_INVITE_LECTURE)) {
            return 'global';
        }

        if ($user->isAgent() || $user->hasRole(User::ROLE_UCAS)) {
            return 'agent';
        }

        // Chef d'unité UCAS — opère comme un chef de service sur son unité
        if ($user->hasRole(User::ROLE_SERVICE, User::ROLE_CHEF_UNITE_UCAS)) {
            return 'service';
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return 'direction';
        }

        return 'global';
    }

    private function makeRoleCard(
        string $label,
        string|int $value,
        string $meta,
        string $href,
        string $accent,
        string $bg,
        ?string $badge = null,
        string $badgeTone = 'neutral'
    ): array {
        return [
            'code' => str($label)->slug('_')->toString(),
            'label' => $label,
            'value' => $value,
            'meta' => $meta,
            'href' => $href,
            'accent' => $accent,
            'bg' => $bg,
            'badge' => $badge,
            'badge_tone' => $badgeTone,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, int>
     */
    private function statusCounts(Collection $actions): array
    {
        $counts = [
            'a_parametrer' => 0,
            'non_demarre' => 0,
            'en_cours' => 0,
            'a_risque' => 0,
            'en_retard' => 0,
            'acheve' => 0,
            'suspendu' => 0,
            'annule' => 0,
            'en_avance' => 0,
        ];

        foreach ($actions as $action) {
            $status = $this->dashboardStatus($action);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildRoleTrendChart(Collection $actions): array
    {
        $currentYear = (int) now()->year;
        $rows = collect(range(1, 12))->map(function (int $month) use ($actions, $currentYear): array {
            $monthActions = $actions->filter(function (Action $action) use ($currentYear, $month): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && (int) $date->year === $currentYear
                    && (int) $date->month === $month;
            })->values();

            $monthKey = sprintf('%04d-%02d', $currentYear, $month);

            return [
                'label' => ucfirst(Carbon::create($currentYear, $month, 1)->locale('fr')->translatedFormat('M')),
                'url' => $this->actionIndexRoute(['mois_demarrage' => $monthKey]),
                'total' => $monthActions->count(),
                'achevees' => $monthActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count(),
                'retard' => $monthActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count(),
            ];
        })->all();

        return [
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#3996D3', 'data' => array_column($rows, 'total')],
                ['label' => 'Achevées', 'color' => '#8FC043', 'data' => array_column($rows, 'achevees')],
                ['label' => 'En retard', 'color' => '#B42318', 'data' => array_column($rows, 'retard')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildWeeklyLoadChart(Collection $actions): array
    {
        $start = now()->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $rows = collect(range(0, 7))->map(function (int $offset) use ($actions, $start): array {
            $weekStart = $start->copy()->addWeeks($offset)->startOfDay();
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $weekRows = $actions->filter(function (Action $action) use ($weekStart, $weekEnd): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && $date->betweenIncluded($weekStart, $weekEnd);
            })->values();

            return [
                'label' => 'S'.str_pad((string) $weekStart->isoWeek(), 2, '0', STR_PAD_LEFT),
                'url' => $this->actionIndexRoute(['week_start' => $weekStart->toDateString()]),
                'total' => $weekRows->count(),
            ];
        })->all();

        return [
            'type' => 'bar',
            'index_axis' => 'x',
            'stacked' => false,
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#1C203D', 'data' => array_column($rows, 'total')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildValidationPipelineChart(Collection $actions): array
    {
        $rows = [
            [
                'label' => 'Brouillon',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_NON_SOUMISE)->count(),
                'url' => $this->actionIndexRoute(),
            ],
            [
                'label' => 'Soumises service',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)->count(),
                'url' => $this->actionIndexRoute(),
            ],
            [
                'label' => 'Validees chef',
                'value' => $actions->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_CHEF)->count(),
                'url' => $this->actionIndexRoute(),
            ],
        ];

        return [
            'type' => 'bar',
            'index_axis' => 'x',
            'stacked' => false,
            'labels' => array_column($rows, 'label'),
            'urls' => array_column($rows, 'url'),
            'datasets' => [
                ['label' => 'Actions', 'color' => '#1C203D', 'data' => array_column($rows, 'value')],
            ],
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildDirectionServiceChart(User $user, Collection $actions): array
    {
        $rows = collect($this->buildDirectionServiceRows($user, $actions));

        return [
            'type' => 'bar',
            'index_axis' => 'y',
            'stacked' => false,
            'labels' => $rows->pluck('service')->all(),
            'urls' => $rows->pluck('url')->all(),
            'datasets' => [
                ['label' => 'Exécution', 'color' => '#8FC043', 'data' => $rows->pluck('taux_execution')->all()],
                ['label' => 'Validation', 'color' => '#3996D3', 'data' => $rows->pluck('taux_validation')->all()],
                ['label' => 'Retards', 'color' => '#B42318', 'data' => $rows->pluck('retards')->all()],
            ],
        ];
    }

    private function progressionGap(Action $action): float
    {
        return max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));
    }

    private function requiresActionUpdate(Action $action): bool
    {
        if (in_array((string) ($action->statut_dynamique ?? ''), [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_ANNULE,
        ], true)) {
            return false;
        }

        return $this->progressionGap($action) >= 15
            || $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'non_demarre';
    }

    private function delayDays(Action $action): int
    {
        $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;

        if (! $deadline instanceof Carbon || $deadline->gte(Carbon::today())) {
            return 0;
        }

        return $deadline->diffInDays(Carbon::today());
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildScopePortfolioMetrics(User $user, Collection $actions, Collection $validatedActions): array
    {
        $statusCounts = $this->statusCounts($actions);
        $pasQuery = $this->buildPasScopedQuery($user);
        $paoQuery = Pao::query();
        $ptaQuery = Pta::query();
        $this->scopePao($paoQuery, $user);
        $this->scopePta($ptaQuery, $user);

        $directionRows = $this->buildGlobalDirectionRows($actions, 100);

        return [
            'pas_total' => (clone $pasQuery)->count(),
            'paos_total' => (clone $paoQuery)->count(),
            'ptas_total' => (clone $ptaQuery)->count(),
            'actions_total' => $actions->count(),
            'actions_valides_service' => $actions
                ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                    ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                    ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                ], true))
                ->count(),
            'actions_valides_direction' => $validatedActions->count(),
            'actions_en_retard' => $statusCounts['en_retard'],
            'alerts' => $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count(),
            'completion_rate' => $this->completionRate($statusCounts['acheve'], $actions->count()),
            'delay_rate' => $this->completionRate(max(0, $actions->count() - $statusCounts['en_retard']), $actions->count()),
            'global_score' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
            'directions_total' => collect($directionRows)->count(),
            'services_total' => $actions->pluck('pta.service_id')->filter()->unique()->count(),
            'directions_difficulte' => collect($directionRows)->filter(function (array $row): bool {
                return (float) ($row['score'] ?? 0) < 60 || (int) ($row['retards'] ?? 0) > 0;
            })->count(),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float|int>
     */
    private function buildDashboardSnapshot(Collection $actions): array
    {
        $statusCounts = $this->statusCounts($actions);
        $total = $actions->count();
        $completed = $statusCounts['acheve'];
        $late = $statusCounts['en_retard'];

        return [
            'actions_total' => $total,
            'achevees' => $completed,
            'retards' => $late,
            'completion_rate' => $this->completionRate($completed, $total),
            'delay_rate' => $this->completionRate(max(0, $total - $late), $total),
            'score' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float>
     */
    private function buildGlobalScoreSummary(Collection $actions, callable $avg): array
    {
        return [
            'delai' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
            'performance' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
            'conformite' => $avg($actions, fn (Action $action): float => 0.0),
            'global' => $avg($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            'progression' => $avg($actions, fn (Action $action): float => (float) ($action->progression_reelle ?? 0)),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlyScoreRows(Collection $actions, int $currentYear, callable $avg, bool $official): array
    {
        return collect(range(1, 12))->map(function (int $month) use ($actions, $currentYear, $avg, $official): array {
            $monthActions = $actions->filter(function (Action $action) use ($currentYear, $month): bool {
                $date = $action->date_debut;

                return $date instanceof Carbon
                    && (int) $date->year === $currentYear
                    && (int) $date->month === $month;
            })->values();

            $label = Carbon::create($currentYear, $month, 1)->locale('fr')->translatedFormat('M');
            $monthKey = sprintf('%04d-%02d', $currentYear, $month);
            $url = [
                'mois_demarrage' => $monthKey,
            ];

            if ($official) {
                $url = array_merge($url, $this->actionCalculationSettings->officialRouteFilters());
            }

            return [
                'label' => ucfirst($label),
                'month_key' => $monthKey,
                'url' => $this->actionIndexRoute($url),
                'delai' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
                'performance' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
                'conformite' => $avg($monthActions, fn (Action $action): float => 0.0),
                'global' => $avg($monthActions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            ];
        })->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalDirectionRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                $validatedDirection = $this->validatedActions($rows)->count();
                $score = round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);
                $criticalService = $rows
                    ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->libelle ?? 'Non renseigne'))
                    ->map(fn (Collection $serviceRows): int => $serviceRows->filter(fn (Action $action): bool => $this->isAlertAction($action))->count())
                    ->sortDesc()
                    ->keys()
                    ->first();

                return [
                    'direction' => (string) ($first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'validees_direction' => $validatedDirection,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'taux_validation' => $this->completionRate($validatedDirection, $total),
                    'score' => $score,
                    'service_critique' => (string) ($criticalService ?? '-'),
                    'url' => $directionId > 0
                        ? $this->actionIndexRoute(['direction_id' => $directionId])
                        : route('workspace.actions.index'),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalDirectionComparisonRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);
                $officialRows = $this->officialActions($rows);
                $snapshot = $this->buildDashboardSnapshot($rows);
                $officialSnapshot = $this->buildDashboardSnapshot($officialRows);
                $criticalService = $rows
                    ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->libelle ?? 'Non renseigne'))
                    ->map(fn (Collection $serviceRows): int => $serviceRows->filter(fn (Action $action): bool => $this->isAlertAction($action))->count())
                    ->sortDesc()
                    ->keys()
                    ->first();

                return [
                    'direction' => (string) ($first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'actions_total' => (int) $snapshot['actions_total'],
                    'actions_officielles' => (int) $officialSnapshot['actions_total'],
                    'achevees' => (int) $snapshot['achevees'],
                    'retards' => (int) $snapshot['retards'],
                    'validees_direction' => (int) $officialSnapshot['actions_total'],
                    'taux_execution_operationnel' => (float) $snapshot['completion_rate'],
                    'taux_execution_officiel' => (float) $officialSnapshot['completion_rate'],
                    'taux_validation' => $this->completionRate((int) $officialSnapshot['actions_total'], (int) $snapshot['actions_total']),
                    'score_operationnel' => (float) $snapshot['score'],
                    'score_officiel' => (float) $officialSnapshot['score'],
                    'service_critique' => (string) ($criticalService ?? '-'),
                    'url' => $directionId > 0
                        ? $this->actionIndexRoute(['direction_id' => $directionId])
                        : route('workspace.actions.index'),
                ];
            })
            ->sortByDesc(function (array $row): float {
                return ((float) ($row['score_officiel'] ?? 0) * 1000) + (float) ($row['score_operationnel'] ?? 0);
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildGlobalPendingValidationRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->filter(fn (Action $action): bool => in_array((string) ($action->statut_validation ?? ''), [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ], true))
            ->sortBy(fn (Action $action): string => $action->soumise_le instanceof Carbon ? $action->soumise_le->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'direction' => (string) ($action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'libelle' => (string) $action->libelle,
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'soumise_le' => $action->soumise_le instanceof Carbon ? $action->soumise_le->format('d/m/Y H:i') : '-',
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildPlanificationRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $directionRows = $this->buildGlobalDirectionRows($actions, 8);

        return [
            'enabled' => true,
            'role' => 'planification',
            'hero' => [
                'eyebrow' => 'Vue planification',
                'title' => 'Consolidation transverse du pilotage',
                'subtitle' => 'Lecture globale des plans, des actions validées et des directions en difficulté pour superviser le dispositif.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('PAS actifs', $portfolio['pas_total'], 'Périmètre stratégique', route('workspace.pas.index'), '#1C203D', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('PAO actifs', $portfolio['paos_total'], 'Déclinaison opérationnelle', route('workspace.pao.index'), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('PTA actifs', $portfolio['ptas_total'], 'Exécution en cours', route('workspace.pta.index'), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Actions validées', $portfolio['actions_valides_direction'], 'Clôturées dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Actions en retard', $portfolio['actions_en_retard'], 'Retards prioritaires', $this->actionIndexRoute(['statut' => 'en_retard']), '#B42318', '#FFF1EF', null, 'warning'),
                $this->makeRoleCard('Indicateur global', number_format((float) $portfolio['global_score'], 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1C203D', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Points de vigilance', route('workspace.alertes', ['niveau' => 'critical']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Directions en difficulté', $portfolio['directions_difficulte'], 'Score faible ou retards', route('workspace.reporting'), '#F9B13C', '#FFF8D6', null, 'warning'),
            ],
            // Graphique « Repartition des statuts » retire pour tous les roles
            // (demande metier 2026-06-10).
            'trend_chart' => [
                'title' => 'Évolution globale du dispositif',
                'subtitle' => 'Actions, achèvements et retards sur l\'année en cours.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par direction',
                'subtitle' => 'Comparaison des directions sur exécution, validation et retards.',
                ...[
                    'type' => 'bar',
                    'index_axis' => 'y',
                    'stacked' => false,
                    'labels' => array_column($directionRows, 'direction'),
                    'urls' => array_column($directionRows, 'url'),
                    'datasets' => [
                        ['label' => 'Exécution', 'color' => '#8FC043', 'data' => array_column($directionRows, 'taux_execution')],
                        ['label' => 'Validation', 'color' => '#3996D3', 'data' => array_column($directionRows, 'taux_validation')],
                        ['label' => 'Retards', 'color' => '#B42318', 'data' => array_column($directionRows, 'retards')],
                    ],
                ],
            ],
            'primary_rows' => $directionRows,
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildDgRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $snapshot = $this->buildDashboardSnapshot($actions);
        $directionRows = $this->buildGlobalDirectionRows($actions, 8);
        $difficultyRows = collect($directionRows)
            ->filter(function (array $row): bool {
                return (float) ($row['score'] ?? 0) < 60
                    || (int) ($row['retards'] ?? 0) > 0
                    || (float) ($row['taux_validation'] ?? 0) < 50;
            })
            ->sortByDesc('retards')
            ->values()
            ->all();
        $summaryCards = [
            $this->makeRoleCard('Directions actives', $portfolio['directions_total'], 'Directions visibles', route('workspace.reporting'), '#1C203D', '#E8F3FB', null, 'info'),
            $this->makeRoleCard('Services actifs', $portfolio['services_total'], 'Services engages', route('workspace.reporting'), '#3996D3', '#E8F3FB', null, 'info'),
            $this->makeRoleCard('Actions totales', $portfolio['actions_total'], 'Portefeuille institutionnel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
            $this->makeRoleCard('Actions validées', $portfolio['actions_valides_direction'], 'Clôturées dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#8FC043', '#F2F8E8', null, 'success'),
            $this->makeRoleCard('Taux validation', number_format($this->completionRate($portfolio['actions_valides_direction'], $portfolio['actions_total']), 0).'%', 'Part des actions finalement validées', $this->validatedActionIndexRoute(), '#8FC043', '#F2F8E8', null, 'success'),
            $this->makeRoleCard('Exécution globale', number_format((float) $snapshot['completion_rate'], 0).'%', 'Achevées / portefeuille total', $this->actionIndexRoute(['statut' => 'achevees']), '#3996D3', '#E8F3FB', null, 'info'),
            $this->makeRoleCard('Score global', number_format((float) $snapshot['score'], 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1C203D', '#E8F3FB', null, 'info'),
            $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Points de decision', route('workspace.alertes', ['niveau' => 'critical']), '#B42318', '#FFF1EF', null, 'danger'),
            $this->makeRoleCard('Directions en difficulté', count($difficultyRows), 'Retards, faible score ou faible taux de validation', route('workspace.reporting'), '#F9B13C', '#FFF8D6', null, 'warning'),
        ];

        return [
            'enabled' => true,
            'role' => 'dg',
            'hero' => [
                'eyebrow' => 'Vue DG',
                'title' => 'Lecture stratégique institutionnelle',
                'subtitle' => 'Lecture stratégique unifiée du portefeuille visible, avec suivi explicite des actions validées dans le circuit hiérarchique.',
            ],
            'summary_cards' => $summaryCards,
            'overview_enabled' => true,
            'comparison_chart_enabled' => true,
            // Graphique « Repartition institutionnelle des statuts » retire (demande
            // metier 2026-06-10). La repartition reste visible via les autres
            // surfaces (status distribution global, reporting).
            'trend_chart' => [
                'title' => 'Evolution institutionnelle',
                'subtitle' => 'Lecture temporelle opérationnelle du portefeuille DG.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par direction',
                'subtitle' => 'Comparer par direction le volume des actions, le taux d\'exécution, le taux de validation et le score global.',
                ...[
                    'type' => 'bar',
                    'index_axis' => 'y',
                    'stacked' => false,
                    'labels' => array_column($directionRows, 'direction'),
                    'urls' => array_column($directionRows, 'url'),
                    'datasets' => [
                        ['label' => 'Exécution', 'color' => '#3996D3', 'data' => array_column($directionRows, 'taux_execution')],
                        ['label' => 'Validation', 'color' => '#8FC043', 'data' => array_column($directionRows, 'taux_validation')],
                        ['label' => 'Retards', 'color' => '#B42318', 'data' => array_column($directionRows, 'retards')],
                        ['label' => 'Score', 'color' => '#1C203D', 'data' => array_column($directionRows, 'score')],
                    ],
                ],
            ],
            'primary_rows' => $directionRows,
            'secondary_rows' => $difficultyRows,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildCabinetRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $portfolio = $this->buildScopePortfolioMetrics($user, $actions, $validatedActions);
        $pendingRows = $this->buildGlobalPendingValidationRows($actions, 8);

        return [
            'enabled' => true,
            'role' => 'cabinet',
            'hero' => [
                'eyebrow' => 'Vue cabinet',
                'title' => 'Suivi transverse et appui décisionnel',
                'subtitle' => 'Lecture rapprochee des points bloquants, des validations en attente et des alertes critiques.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions sensibles', $portfolio['alerts'], 'Actions a forte vigilance', route('workspace.alertes', ['niveau' => 'critical']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Alertes critiques', $portfolio['alerts'], 'Niveau d alerte courant', route('workspace.alertes', ['niveau' => 'critical']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Actions en retard', $portfolio['actions_en_retard'], 'Retards institutionnels', $this->actionIndexRoute(['statut' => 'en_retard']), '#F9B13C', '#FFF8D6', null, 'warning'),
                $this->makeRoleCard('Actions validées', $portfolio['actions_valides_direction'], 'Clôturées dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Validations en attente', count($pendingRows), 'Actions a arbitrer', route('workspace.actions.index', ['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF]), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Directions en difficulté', $portfolio['directions_difficulte'], 'Suivi prioritaire', route('workspace.reporting'), '#F9B13C', '#FFF8D6', null, 'warning'),
            ],
            // Graphique « Repartition des statuts » retire pour tous les roles
            // (demande metier 2026-06-10).
            'trend_chart' => [
                'title' => 'Évolution des alertes et retards',
                'subtitle' => 'Lecture temporelle des tensions du portefeuille.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Pipeline de validation transverse',
                'subtitle' => 'Repartition des actions entre soumission et validation finale chef.',
                ...$this->buildValidationPipelineChart($actions),
            ],
            'primary_rows' => $pendingRows,
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildAgentRoleDashboard(Collection $actions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $updateCount = $actions->filter(fn (Action $action): bool => $this->requiresActionUpdate($action))->count();

        return [
            'enabled' => true,
            'role' => 'agent',
            'hero' => [
                'eyebrow' => 'Vue agent',
                'title' => 'Suivi personnel de l\'exécution',
                'subtitle' => 'Pilotage de mes actions, de mes retards et de mes alertes sans quitter mon périmètre individuel.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Mes actions', $total, 'Portefeuille individuel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Mes actions en cours', $statusCounts['en_cours'], 'Exécution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Mes actions achevées', $statusCounts['acheve'], 'Actions terminées', $this->actionIndexRoute(['statut' => 'achevees']), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Mes actions en retard', $statusCounts['en_retard'], 'Retards à traiter', $this->actionIndexRoute(['statut' => 'en_retard']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Mes alertes actives', $alertCount, 'Actions a surveiller', route('workspace.alertes', ['niveau' => 'warning']), '#F9B13C', '#FFF8D6', null, 'warning'),
                $this->makeRoleCard('Actions a mettre a jour', $updateCount, 'Ecarts de progression', route('workspace.actions.index', ['sort' => 'progression_desc']), '#1C203D', '#E8F3FB', null, 'info'),
            ],
            // Graphique « Repartition des statuts » retire pour tous les roles
            // (demande metier 2026-06-10).
            'trend_chart' => [
                'title' => 'Evolution mensuelle de mes actions',
                'subtitle' => 'Volume, achèvement et retards par mois de démarrage.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Charge personnelle par semaine',
                'subtitle' => 'Actions planifiées par semaine de démarrage.',
                ...$this->buildWeeklyLoadChart($actions),
            ],
            'primary_rows' => $this->buildPriorityActionRows($actions, 8),
            'secondary_rows' => $this->buildLateActionRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildServiceRoleDashboard(Collection $actions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $pendingServiceValidation = $actions->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)->count();
        $validatedService = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true))
            ->count();
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $completionRate = $this->completionRate($statusCounts['acheve'], $total);

        return [
            'enabled' => true,
            'role' => 'service',
            'hero' => [
                'eyebrow' => 'Vue service',
                'title' => 'Pilotage du service',
                'subtitle' => 'Suivi des validations, des retards et de la charge du service avec une lecture directement exploitable.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions du service', $total, 'Portefeuille du service', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Actions en cours', $statusCounts['en_cours'], 'Exécution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Actions achevées', $statusCounts['acheve'], 'Actions terminées', $this->actionIndexRoute(['statut' => 'achevees']), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Actions en retard', $statusCounts['en_retard'], 'Retards du service', $this->actionIndexRoute(['statut' => 'en_retard']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Actions à valider', $pendingServiceValidation, 'Soumissions en attente', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF]), '#F9B13C', '#FFF8D6', null, 'warning'),
                $this->makeRoleCard('Actions validées service', $validatedService, 'Validation chef effectuée', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF]), '#1C203D', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Alertes actives', $alertCount, 'Actions critiques', route('workspace.alertes', ['niveau' => 'warning']), '#F9B13C', '#FFF8D6', null, 'warning'),
                $this->makeRoleCard('Taux exécution service', number_format($completionRate, 0).'%', 'Actions achevées / total', route('workspace.actions.index', ['statut' => 'achevees']), '#8FC043', '#F2F8E8', null, 'success'),
            ],
            // Graphique « Repartition des statuts » retire pour tous les roles
            // (demande metier 2026-06-10).
            'trend_chart' => [
                'title' => 'Evolution mensuelle du service',
                'subtitle' => 'Volume, achèvement et retards sur les actions du service.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Pipeline de validation du service',
                'subtitle' => 'Ou se situent les actions entre soumission et validation finale chef.',
                ...$this->buildValidationPipelineChart($actions),
            ],
            'primary_rows' => $this->buildServiceValidationRows($actions, 8),
            'secondary_rows' => $this->buildServiceAgentPerformanceRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @param Collection<int, Action> $validatedActions
     * @return array<string, mixed>
     */
    private function buildDirectionRoleDashboard(User $user, Collection $actions, Collection $validatedActions): array
    {
        $total = $actions->count();
        $statusCounts = $this->statusCounts($actions);
        $validatedDirection = $this->officialActions($actions)->count();
        $validatedService = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true))
            ->count();
        $pendingValidation = $actions
            ->filter(fn (Action $action): bool => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            ], true))
            ->count();
        $alertCount = $actions->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();
        $completionRate = $this->completionRate($statusCounts['acheve'], $total);
        $delayRate = $this->completionRate(max(0, $total - $statusCounts['en_retard']), $total);
        $globalScore = round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);

        return [
            'enabled' => true,
            'role' => 'direction',
            'hero' => [
                'eyebrow' => 'Vue direction',
                'title' => 'Pilotage directionnel et comparaison des services',
                'subtitle' => 'Lecture globale de la direction avec detail par service, validations en attente et actions critiques.',
            ],
            'summary_cards' => [
                $this->makeRoleCard('Actions direction', $total, 'Portefeuille directionnel', route('workspace.actions.index'), '#1F2937', '#F8FBFF', null, 'neutral'),
                $this->makeRoleCard('Actions en cours', $statusCounts['en_cours'], 'Exécution active', $this->actionIndexRoute(['statut' => 'en_cours']), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Actions achevées', $statusCounts['acheve'], 'Actions terminées', $this->actionIndexRoute(['statut' => 'achevees']), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Actions en retard', $statusCounts['en_retard'], 'Retards directionnels', $this->actionIndexRoute(['statut' => 'en_retard']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Actions validées service', $validatedService, 'Niveau chef atteint', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF]), '#3996D3', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Actions validées', $validatedDirection, 'Clôturées dans le circuit de validation actif', $this->validatedActionIndexRoute(), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('En attente validation', $pendingValidation, 'Soumises au chef', $this->actionIndexRoute(['statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF]), '#F9B13C', '#FFF8D6', null, 'warning'),
                $this->makeRoleCard('Alertes critiques', $alertCount, 'Actions à traiter', route('workspace.alertes', ['niveau' => 'critical']), '#B42318', '#FFF1EF', null, 'danger'),
                $this->makeRoleCard('Taux exécution direction', number_format($completionRate, 0).'%', 'Actions achevées / total', route('workspace.actions.index', ['statut' => 'achevees']), '#8FC043', '#F2F8E8', null, 'success'),
                $this->makeRoleCard('Respect des delais', number_format($delayRate, 0).'%', 'Actions hors retard', route('workspace.actions.index', ['statut' => 'en_retard']), '#1C203D', '#E8F3FB', null, 'info'),
                $this->makeRoleCard('Score global direction', number_format($globalScore, 0), 'Moyenne sur toutes les actions visibles', route('workspace.reporting'), '#1C203D', '#E8F3FB', null, 'info'),
            ],
            // Graphique « Repartition des statuts » retire pour tous les roles
            // (demande metier 2026-06-10).
            'trend_chart' => [
                'title' => 'Evolution mensuelle de la direction',
                'subtitle' => 'Volume, achèvement et retards par mois de démarrage.',
                ...$this->buildRoleTrendChart($actions),
            ],
            'support_chart' => [
                'title' => 'Performance par service',
                'subtitle' => 'Comparaison des services sur exécution, validation et retards.',
                ...$this->buildDirectionServiceChart($user, $actions),
            ],
            'primary_rows' => $this->buildDirectionServiceRows($user, $actions),
            'secondary_rows' => $this->buildDirectionCriticalRows($actions, 8),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPriorityActionRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->sortBy([
                fn (Action $action): int => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard' ? 0 : 1,
                fn (Action $action): int => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'a_risque' ? 0 : 1,
                fn (Action $action): float => -1 * $this->progressionGap($action),
                fn (Action $action): string => $action->date_echeance instanceof Carbon ? $action->date_echeance->toDateString() : '9999-12-31',
            ])
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'pta' => (string) ($action->pta?->titre ?? '-'),
                    'echeance' => $action->date_echeance instanceof Carbon ? $action->date_echeance->format('d/m/Y') : '-',
                    'statut' => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildLateActionRows(Collection $actions, int $limit = 8): array
    {
        $today = Carbon::today();

        return $actions
            ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
            ->sortByDesc(function (Action $action) use ($today): int {
                $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;

                return $deadline instanceof Carbon ? $deadline->diffInDays($today) : 0;
            })
            ->take($limit)
            ->map(function (Action $action) use ($today): array {
                $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
                $daysLate = $deadline instanceof Carbon ? $deadline->diffInDays($today) : 0;

                return [
                    'libelle' => (string) $action->libelle,
                    'echeance' => $deadline instanceof Carbon ? $deadline->format('d/m/Y') : '-',
                    'retard_jours' => $daysLate,
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceValidationRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->where('statut_validation', ActionTrackingService::VALIDATION_SOUMISE_CHEF)
            ->sortBy(fn (Action $action): string => $action->soumise_le instanceof Carbon ? $action->soumise_le->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'agent' => (string) ($action->responsable?->name ?? '-'),
                    'soumise_le' => $action->soumise_le instanceof Carbon ? $action->soumise_le->format('d/m/Y H:i') : '-',
                    'statut' => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')),
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'retard_jours' => $this->delayDays($action),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildServiceAgentPerformanceRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->responsable?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();

                return [
                    'agent' => (string) ($first?->responsable?->name ?? 'Non assigne'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'url' => route('workspace.actions.index'),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectionServiceRows(User $user, Collection $actions, ?int $limit = null): array
    {
        $services = $user->direction?->services()
            ->orderBy('libelle')
            ->get()
            ?? collect();

        if ($services->isEmpty()) {
            $rows = $actions
                ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->id ?? 0))
                ->map(function (Collection $rows): array {
                    $first = $rows->first();
                    $total = $rows->count();
                    $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                    $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                    $validatedDirection = $this->officialActions($rows)->count();
                    $score = round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
                    $serviceId = (int) ($first?->pta?->service?->id ?? 0);

                    return [
                        'service' => (string) ($first?->pta?->service?->code ?? $first?->pta?->service?->libelle ?? 'Non renseigne'),
                        'actions_total' => $total,
                        'achevees' => $completed,
                        'retards' => $late,
                        'validees_direction' => $validatedDirection,
                        'taux_execution' => $this->completionRate($completed, $total),
                        'taux_validation' => $this->completionRate($validatedDirection, $total),
                        'score' => $score,
                        'url' => $serviceId > 0
                            ? $this->actionIndexRoute(['service_id' => $serviceId])
                            : route('workspace.actions.index'),
                    ];
                });

            return ($limit === null ? $rows : $rows->take($limit))
                ->values()
                ->all();
        }

        $actionGroups = $actions->groupBy(fn (Action $action): int => (int) ($action->pta?->service?->id ?? 0));

        $rows = $services->map(function (\App\Models\Service $service) use ($actionGroups): array {
            /** @var Collection<int, Action> $serviceActions */
            $serviceActions = $actionGroups->get((int) $service->id, collect());
            $total = $serviceActions->count();
            $completed = $serviceActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
            $late = $serviceActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
            $validatedDirection = $this->officialActions($serviceActions)->count();
            $score = $total > 0
                ? round((float) $serviceActions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2)
                : 0.0;

            return [
                'service' => (string) ($service->code ?: $service->libelle ?: 'Service'),
                'actions_total' => $total,
                'achevees' => $completed,
                'retards' => $late,
                'validees_direction' => $validatedDirection,
                'taux_execution' => $this->completionRate($completed, $total),
                'taux_validation' => $this->completionRate($validatedDirection, $total),
                'score' => $score,
                'url' => $this->actionIndexRoute(['service_id' => (int) $service->id]),
            ];
        });

        $sortedRows = $rows
            ->sortBy([
                fn (array $row): int => $row['actions_total'] > 0 ? 0 : 1,
                fn (array $row): float => -1 * (float) ($row['score'] ?? 0),
                fn (array $row): string => (string) ($row['service'] ?? ''),
            ])
            ->values();

        return ($limit === null ? $sortedRows : $sortedRows->take($limit))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectionCriticalRows(Collection $actions, int $limit = 8): array
    {
        return $actions
            ->filter(fn (Action $action): bool => $this->isAlertAction($action))
            ->sortByDesc(function (Action $action): float {
                return ($this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard' ? 100 : 0)
                    + $this->delayDays($action)
                    + max(0.0, 60 - (float) ($action->actionKpi?->kpi_global ?? 0));
            })
            ->take($limit)
            ->map(function (Action $action): array {
                return [
                    'libelle' => (string) $action->libelle,
                    'direction' => (string) ($action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? '-'),
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'retard_jours' => $this->delayDays($action),
                    'validation_status' => (string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE),
                    'performance_execution' => (float) ($action->actionKpi?->kpi_performance ?? 0),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildStatusCards(Collection $actions): array
    {
        $rows = [
            'a_parametrer' => ['label' => 'À paramétrer', 'color' => '#A855F7', 'bg' => '#F5EBFF', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'a_parametrer'])],
            'en_avance' => ['label' => 'En avance', 'color' => '#8FC043', 'bg' => '#EEF6E1', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_avance'])],
            'en_cours' => ['label' => 'En cours', 'color' => '#3996D3', 'bg' => '#E8F3FB', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_cours'])],
            'a_risque' => ['label' => 'A surveiller', 'color' => '#F0E509', 'bg' => '#FFF8D6', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'a_risque'])],
            'en_retard' => ['label' => 'En retard', 'color' => '#F9B13C', 'bg' => '#FFF0DF', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'en_retard'])],
            'suspendu' => ['label' => 'Suspendu', 'color' => '#7C3AED', 'bg' => '#F3E8FF', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'suspendu'])],
            'annule' => ['label' => 'Annule', 'color' => '#475569', 'bg' => '#E2E8F0', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'annule'])],
            'non_demarre' => ['label' => 'Non demarre', 'color' => '#64748B', 'bg' => '#F1F5F9', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'non_demarre'])],
            'acheve' => ['label' => 'Achevé', 'color' => '#1C203D', 'bg' => '#EEF1F8', 'count' => 0, 'href' => $this->actionIndexRoute(['statut' => 'achevees'])],
        ];

        foreach ($actions as $action) {
            $status = $this->dashboardStatus($action);
            if (! array_key_exists($status, $rows)) {
                continue;
            }
            $rows[$status]['count']++;
        }

        foreach ($rows as $key => &$row) {
            $row['key'] = $key;
        }
        unset($row);

        return array_values($rows);
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildUnitRows(Collection $actions, string $mode): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $groupKey = 'none';
            $label = 'Non renseigne';

            if ($mode === 'objectif_strategique') {
                $groupKey = (string) ($action->pta?->pao?->pasObjectif?->id ?? 0);
                $axeCode = (string) ($action->pta?->pao?->pasObjectif?->pasAxe?->code ?? '');
                $objectifCode = (string) ($action->pta?->pao?->pasObjectif?->code ?? '');
                $objectifLibelle = (string) ($action->pta?->pao?->pasObjectif?->libelle ?? 'Non renseigne');
                $label = trim($axeCode.' / '.$objectifCode.' - '.$objectifLibelle, ' /-') ?: $objectifLibelle;
                $url = $this->officialActionIndexRoute([
                    'pas_objectif_id' => (int) ($action->pta?->pao?->pasObjectif?->id ?? 0),
                ]);
            } elseif ($mode === 'direction') {
                $groupKey = (string) ($action->pta?->direction?->id ?? 0);
                $label = (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute([
                    'direction_id' => (int) ($action->pta?->direction?->id ?? 0),
                ]);
            } elseif ($mode === 'service') {
                $groupKey = (string) ($action->pta?->service?->id ?? 0);
                $label = (string) ($action->pta?->service?->code ?? $action->pta?->service?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute([
                    'service_id' => (int) ($action->pta?->service?->id ?? 0),
                ]);
            } else {
                $groupKey = (string) $action->id;
                $label = (string) $action->libelle;
                $url = route('workspace.actions.suivi', $action);
            }

            $groups[$groupKey]['label'] = $label;
            $groups[$groupKey]['url'] = $url;
            $groups[$groupKey]['items'][] = $action;
        }

        return collect($groups)
            ->map(function (array $group): array {
                /** @var Collection<int, Action> $items */
                $items = collect($group['items'] ?? []);
                $total = max(1, $items->count());
                $validated = $this->officialActions($items)->count();
                $alerts = $items->filter(fn (Action $action): bool => $this->isAlertAction($action))->count();

                return [
                    'label' => (string) ($group['label'] ?? 'Non renseigne'),
                    'url' => (string) ($group['url'] ?? $this->officialActionIndexRoute()),
                    'actions_total' => $items->count(),
                    'progression_moyenne' => round((float) $items->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
                    'kpi_global' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                    'kpi_delai' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)), 2),
                    'kpi_performance' => round((float) $items->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)), 2),
                    'kpi_conformite' => round((float) $items->avg(fn (Action $action): float => 0.0), 2),
                    'alertes' => $alerts,
                    'validation_pct' => round(($validated / $total) * 100, 2),
                ];
            })
            ->sortByDesc('actions_total')
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, mixed>
     */
    private function buildDecisionCounts(Collection $actions): array
    {
        $total = $actions->count();
        $completed = $actions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
        $inProgress = $actions->filter(fn (Action $action): bool => in_array($this->normalizeStatus((string) ($action->statut_dynamique ?? '')), ['en_cours', 'a_risque', 'en_avance'], true))->count();
        $late = $actions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
        $validated = $this->officialActions($actions)->count();
        $aligned = $actions->filter(function (Action $action): bool {
            return $action->pta !== null
                && $action->pta?->pao !== null
                && $action->pta?->pao?->pasObjectif !== null
                && $action->pta?->pao?->pas !== null;
        })->count();

        $subActions = $actions->flatMap(fn (Action $action): Collection => $action->relationLoaded('sousActions') ? $action->sousActions : collect());
        $proofs = $actions->flatMap(function (Action $action): Collection {
            $actionProofs = $action->relationLoaded('justificatifs') ? $action->justificatifs : collect();
            $subActionProofs = $action->relationLoaded('sousActions')
                ? $action->sousActions->flatMap(fn ($subAction): Collection => $subAction->relationLoaded('justificatifs') ? $subAction->justificatifs : collect())
                : collect();

            return $actionProofs->concat($subActionProofs);
        });

        return [
            'axes_concernes' => $actions->pluck('pta.pao.pasObjectif.pasAxe.id')->filter()->unique()->count(),
            'objectifs_strategiques_concernes' => $actions->pluck('pta.pao.pasObjectif.id')->filter()->unique()->count(),
            'pas_lies' => $actions->pluck('pta.pao.pas.id')->filter()->unique()->count(),
            'paos_lies' => $actions->pluck('pta.pao.id')->filter()->unique()->count(),
            'ptas_lies' => $actions->pluck('pta.id')->filter()->unique()->count(),
            'ptas_avec_actions' => $actions->pluck('pta.id')->filter()->unique()->count(),
            'services_couverts' => $actions->pluck('pta.service.id')->filter()->unique()->count(),
            'objectifs_operationnels' => $actions
                ->map(fn (Action $action): ?int => $action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id)
                ->filter()
                ->unique()
                ->count(),
            'objectifs_transmis_services' => $actions
                ->map(fn (Action $action): ?int => ($action->objectifOperationnel?->service_id ?? $action->pta?->service_id) !== null
                    ? ($action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id)
                    : null)
                ->filter()
                ->unique()
                ->count(),
            'actions_total' => $total,
            'actions_terminees' => $completed,
            'actions_en_cours' => $inProgress,
            'actions_en_retard' => $late,
            'taux_execution' => $this->completionRate($completed, $total),
            'taux_validation' => $this->completionRate($validated, $total),
            'taux_alignement' => $this->completionRate($aligned, $total),
            'sous_actions_total' => $subActions->count(),
            'sous_actions_terminees' => $subActions->filter(fn ($subAction): bool => (bool) ($subAction->est_effectuee ?? false))->count(),
            'justificatifs_total' => $proofs->count(),
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionChainRows(Collection $actions, int $limit = 10): array
    {
        return $actions
            ->groupBy(function (Action $action): string {
                return implode(':', [
                    (int) ($action->pta?->pao?->pas?->id ?? 0),
                    (int) ($action->pta?->pao?->pasObjectif?->id ?? 0),
                    (int) ($action->pta?->pao?->id ?? 0),
                    (int) ($action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id ?? 0),
                    (int) ($action->pta?->id ?? 0),
                ]);
            })
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                $objective = $first?->pta?->pao?->pasObjectif;
                $operationalObjective = $first?->objectifOperationnel ?? $first?->pta?->objectifOperationnel;
                $pas = $first?->pta?->pao?->pas;
                $pao = $first?->pta?->pao;
                $pta = $first?->pta;

                $state = 'En cours';
                if ($late > 0) {
                    $state = 'Retard';
                } elseif ($total > 0 && $completed === $total) {
                    $state = 'Termine';
                } elseif ($pas === null || $objective === null || $pao === null || $pta === null) {
                    $state = 'Chaine incomplete';
                }

                return [
                    'pas' => (string) ($pas?->titre ?: 'PAS'),
                    'objectif_strategique' => $this->strategicObjectiveLabel($objective),
                    'pao' => (string) ($pao?->titre ?: ('PAO '.($pao?->annee ?? ''))),
                    'objectif_operationnel' => (string) ($operationalObjective?->libelle ?: 'Non renseigne'),
                    'pta' => (string) ($pta?->titre ?: 'PTA'),
                    'actions' => $total,
                    'etat' => $state,
                ];
            })
            ->sortByDesc('actions')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionServiceRows(Collection $actions, int $limit = 10): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->service?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();
                $inProgress = $rows->filter(fn (Action $action): bool => in_array($this->normalizeStatus((string) ($action->statut_dynamique ?? '')), ['en_cours', 'a_risque', 'en_avance'], true))->count();

                return [
                    'service' => (string) ($first?->pta?->service?->libelle ?? $first?->pta?->service?->code ?? 'Non renseigne'),
                    'pta' => $rows->pluck('pta.id')->filter()->unique()->count(),
                    'actions' => $total,
                    'terminees' => $completed,
                    'en_cours' => $inProgress,
                    'retard' => $late,
                    'taux' => $this->completionRate($completed, $total),
                    'score' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                ];
            })
            ->sortByDesc('actions')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionAgentRows(Collection $actions, int $limit = 10): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $agents = collect();
            if ($action->relationLoaded('responsables') && $action->responsables->isNotEmpty()) {
                $agents = $agents->concat($action->responsables);
            } elseif ($action->responsable !== null) {
                $agents = $agents->push($action->responsable);
            }

            if ($action->relationLoaded('sousActions')) {
                $agents = $agents->concat($action->sousActions->pluck('agent')->filter());
            }

            if ($agents->isEmpty()) {
                $agents = collect([(object) ['id' => 0, 'name' => 'Non assigne']]);
            }

            foreach ($agents->unique('id') as $agent) {
                $agentId = (int) ($agent->id ?? 0);
                $key = (string) $agentId;
                $subActionCount = $action->relationLoaded('sousActions')
                    ? $action->sousActions
                        ->filter(fn ($subAction): bool => (int) ($subAction->agent_id ?? 0) === $agentId)
                        ->count()
                    : 0;

                $groups[$key]['agent'] = (string) ($agent->name ?? 'Non assigne');
                $groups[$key]['service'] = (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? 'Non renseigne');
                $groups[$key]['actions'][(int) $action->id] = $action;
                $groups[$key]['sous_actions'] = (int) ($groups[$key]['sous_actions'] ?? 0) + $subActionCount;
            }
        }

        return collect($groups)
            ->map(function (array $group): array {
                /** @var Collection<int, Action> $rows */
                $rows = collect($group['actions'] ?? []);
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();

                return [
                    'agent' => (string) ($group['agent'] ?? 'Non assigne'),
                    'service' => (string) ($group['service'] ?? 'Non renseigne'),
                    'actions_affectees' => $total,
                    'terminees' => $completed,
                    'en_retard' => $late,
                    'sous_actions' => (int) ($group['sous_actions'] ?? 0),
                    'score' => $this->completionRate($completed, $total),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionPriorityRows(Collection $actions, int $limit = 10): array
    {
        return $actions
            ->sortByDesc(function (Action $action): float {
                $status = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));
                $priority = match ((string) ($action->priorite ?? '')) {
                    'critique' => 40,
                    'elevee', 'haute' => 30,
                    'moyenne' => 20,
                    default => 10,
                };

                return $priority
                    + ($status === 'en_retard' ? 100 : 0)
                    + ($status === 'a_risque' ? 60 : 0)
                    + max(0, 100 - (float) ($action->progression_reelle ?? 0)) / 10;
            })
            ->take($limit)
            ->map(fn (Action $action): array => [
                'action' => (string) $action->libelle,
                'service' => (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-'),
                'responsable' => $this->actionResponsibleLabel($action),
                'date_fin' => $this->actionDeadline($action)?->format('d/m/Y') ?? '-',
                'statut' => $this->statusLabel($this->normalizeStatus((string) ($action->statut_dynamique ?? ''))),
                'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                'validation' => (string) ($action->validation_status_label ?? $action->statut_validation ?? '-'),
                'url' => route('workspace.actions.suivi', $action),
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionLateRows(Collection $actions, int $limit = 10): array
    {
        $today = Carbon::today();

        return $actions
            ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
            ->sortByDesc(fn (Action $action): int => $this->actionDeadline($action)?->diffInDays($today) ?? 0)
            ->take($limit)
            ->map(function (Action $action) use ($today): array {
                $deadline = $this->actionDeadline($action);

                return [
                    'action' => (string) $action->libelle,
                    'responsable' => $this->actionResponsibleLabel($action),
                    'service' => (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-'),
                    'date_fin' => $deadline?->format('d/m/Y') ?? '-',
                    'jours_retard' => $deadline instanceof Carbon ? $deadline->diffInDays($today) : 0,
                    'progression' => round((float) ($action->progression_reelle ?? 0), 2),
                    'motif' => trim((string) ($action->difficultes_rencontrees ?: $action->mesures_correctives ?: 'Non renseigne')),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionPendingValidationRows(Collection $actions, int $limit = 10): array
    {
        $pendingStatuses = [
            ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ];

        return $actions
            ->filter(fn (Action $action): bool => in_array((string) ($action->statut_validation ?? ''), $pendingStatuses, true))
            ->sortBy(fn (Action $action): string => $action->soumise_le instanceof Carbon ? $action->soumise_le->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                $validationStatus = (string) ($action->statut_validation ?? '');
                $level = 'Chef de service';

                return [
                    'element' => (string) $action->libelle,
                    'service' => (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-'),
                    'responsable' => $this->actionResponsibleLabel($action),
                    'niveau' => $level,
                    'statut' => (string) ($action->validation_status_label ?? $validationStatus),
                    'depuis' => $action->soumise_le instanceof Carbon ? $action->soumise_le->diffForHumans(null, true) : '-',
                    'action' => 'Verifier',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionProofRows(Collection $actions, int $limit = 10): array
    {
        return $actions
            ->sortByDesc(function (Action $action): int {
                return $this->actionProofs($action)->count();
            })
            ->take($limit)
            ->map(function (Action $action): array {
                $proofs = $this->actionProofs($action);
                $firstProof = $proofs->first();

                return [
                    'action' => (string) $action->libelle,
                    'agent' => $this->actionResponsibleLabel($action),
                    'justificatif' => $proofs->isNotEmpty()
                        ? ($proofs->count().' preuve(s)'.($firstProof?->nom_original ? ' - '.$firstProof->nom_original : ''))
                        : 'Aucune preuve',
                    'statut_preuve' => $proofs->isNotEmpty() ? 'Deposee' : 'Manquante',
                    // L'etape de validation direction a ete supprimee : on
                    // utilise desormais le chef de service comme validateur.
                    'validateur' => (string) ($action->evaluePar?->name ?? '-'),
                    'observation' => (string) ($action->motif_validation_chef ?: '-'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionAnomalyRows(Collection $actions, int $limit = 10): array
    {
        $rows = [];

        foreach ($actions as $action) {
            $status = $this->normalizeStatus((string) ($action->statut_dynamique ?? ''));
            $service = (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-');
            $label = (string) $action->libelle;

            if ($status === 'en_retard') {
                $rows[] = [
                    'type' => 'Retard',
                    'element' => $label,
                    'service' => $service,
                    'gravite' => 'Critique',
                    'detail' => 'Date de fin depassee',
                    'action_corrective' => 'Relancer le responsable',
                ];
            }

            if ($this->actionResponsibleLabel($action) === 'Non assigne') {
                $rows[] = [
                    'type' => 'Responsable',
                    'element' => $label,
                    'service' => $service,
                    'gravite' => 'Elevee',
                    'detail' => 'Action sans responsable',
                    'action_corrective' => 'Affecter un agent',
                ];
            }

            if ($status === 'acheve' && $this->actionProofs($action)->isEmpty()) {
                $rows[] = [
                    'type' => 'Preuve',
                    'element' => $label,
                    'service' => $service,
                    'gravite' => 'Elevee',
                    'detail' => 'Action terminee sans justificatif',
                    'action_corrective' => 'Demander la preuve',
                ];
            }

            if ((float) ($action->actionKpi?->kpi_global ?? 0) > 0 && (float) ($action->actionKpi?->kpi_global ?? 0) < 60) {
                $rows[] = [
                    'type' => 'Performance',
                    'element' => $label,
                    'service' => $service,
                    'gravite' => 'Moyenne',
                    'detail' => 'Score inferieur a 60',
                    'action_corrective' => 'Analyser le blocage',
                ];
            }
        }

        return collect($rows)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDecisionQuarterRows(Collection $actions, int $year): array
    {
        return collect(range(1, 4))
            ->map(function (int $quarter) use ($actions, $year): array {
                $startMonth = (($quarter - 1) * 3) + 1;
                $start = Carbon::create($year, $startMonth, 1)->startOfDay();
                $end = $start->copy()->addMonths(2)->endOfMonth()->endOfDay();
                $quarterActions = $actions->filter(function (Action $action) use ($start, $end): bool {
                    $date = $this->actionReferenceDate($action);

                    return $date instanceof Carbon && $date->betweenIncluded($start, $end);
                });
                $total = $quarterActions->count();
                $completed = $quarterActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')->count();
                $late = $quarterActions->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')->count();

                return [
                    'trimestre' => 'T'.$quarter,
                    'actions_prevues' => $total,
                    'terminees' => $completed,
                    'retard' => $late,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'score' => round((float) $quarterActions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDirectionPerformanceRows(Collection $actions, int $limit = 12): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->isCompletedAction($action))->count();
                $inProgress = $rows->filter(fn (Action $action): bool => $this->isInProgressAction($action))->count();
                $notStarted = $rows->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'non_demarre')->count();
                $late = $rows->filter(fn (Action $action): bool => $this->isLateAction($action))->count();
                $completedLate = $rows->filter(fn (Action $action): bool => $this->isCompletedLateAction($action))->count();
                $validated = $this->officialActions($rows)->count();
                $services = $rows->pluck('pta.service.id')->filter()->unique()->count();
                $ptaCreated = $rows->pluck('pta.id')->filter()->unique()->count();
                $ptaExpected = max($services, $ptaCreated);
                $executionRate = $this->completionRate($completed, $total);
                $quantitativeRate = $this->averageQuantitativeRate($rows);
                $score = $this->dashboardScore($rows, $executionRate);
                $directionId = (int) ($first?->pta?->direction?->id ?? 0);

                return [
                    'direction' => (string) ($first?->pta?->direction?->code ?? $first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'pao_cree' => $rows->pluck('pta.pao.id')->filter()->unique()->isNotEmpty() ? 'Oui' : 'Non',
                    'objectifs_operationnels' => $rows->map(fn (Action $action): ?int => $action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id)->filter()->unique()->count(),
                    'services' => $services,
                    'pta_ratio' => $ptaCreated.'/'.$ptaExpected,
                    'actions_total' => $total,
                    'non_demarre' => $notStarted,
                    'en_cours' => $inProgress,
                    'realisees' => $completed,
                    'validees' => $validated,
                    'retards' => $late,
                    'hors_delai' => $completedLate,
                    'taux_execution' => $executionRate,
                    'taux_realisation' => $quantitativeRate,
                    'performance' => $this->performanceLabel($score),
                    'statut' => $this->groupEvolutionLabel($rows),
                    'derniere_activite' => $this->lastActivityLabel($rows),
                    'url' => $directionId > 0
                        ? route('dashboard', ['direction_id' => $directionId])
                        : route('dashboard'),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPasDirectionRows(Collection $actions, int $limit = 12): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->direction?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $strategicObjectives = $rows->pluck('pta.pao.pasObjectif.id')->filter()->unique();
                $declinedStrategicObjectives = $rows
                    ->filter(fn (Action $action): bool => ($action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id) !== null)
                    ->pluck('pta.pao.pasObjectif.id')
                    ->filter()
                    ->unique();
                $declinationRate = $this->completionRate($declinedStrategicObjectives->count(), max(1, $strategicObjectives->count()));
                $paoCount = $rows->pluck('pta.pao.id')->filter()->unique()->count();

                return [
                    'direction' => (string) ($first?->pta?->direction?->code ?? $first?->pta?->direction?->libelle ?? 'Non renseignee'),
                    'axes' => $rows->pluck('pta.pao.pasObjectif.pasAxe.id')->filter()->unique()->count(),
                    'objectifs_strategiques' => $strategicObjectives->count(),
                    'pao_cree' => $paoCount > 0 ? 'Oui' : 'Non',
                    'objectifs_operationnels' => $rows->map(fn (Action $action): ?int => $action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id)->filter()->unique()->count(),
                    'taux_declinaison' => $declinationRate,
                    'statut' => $this->pasDeclinationLabel($paoCount, $declinationRate, $rows->count()),
                    'derniere_maj' => $this->lastActivityLabel($rows),
                ];
            })
            ->sortByDesc('taux_declinaison')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPaoDirectionRows(Collection $actions, int $limit = 15): array
    {
        return $actions
            ->groupBy(function (Action $action): string {
                return implode(':', [
                    (int) ($action->pta?->direction?->id ?? 0),
                    (int) ($action->pta?->pao?->pasObjectif?->id ?? 0),
                    (int) ($action->objectifOperationnel?->id ?? $action->pta?->objectifOperationnel?->id ?? 0),
                    (int) ($action->pta?->service?->id ?? 0),
                ]);
            })
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows->filter(fn (Action $action): bool => $this->isCompletedAction($action))->count();
                $inProgress = $rows->filter(fn (Action $action): bool => $this->isInProgressAction($action))->count();
                $late = $rows->filter(fn (Action $action): bool => $this->isLateAction($action))->count();
                $operationalObjective = $first?->objectifOperationnel ?? $first?->pta?->objectifOperationnel;

                return [
                    'direction' => (string) ($first?->pta?->direction?->code ?? $first?->pta?->direction?->libelle ?? '-'),
                    'objectif_strategique' => $this->strategicObjectiveLabel($first?->pta?->pao?->pasObjectif),
                    'objectif_operationnel' => (string) ($operationalObjective?->libelle ?: 'Non renseigne'),
                    'service' => (string) ($first?->pta?->service?->libelle ?? $first?->pta?->service?->code ?? '-'),
                    'echeance' => $operationalObjective?->echeance instanceof Carbon ? $operationalObjective->echeance->format('d/m/Y') : '-',
                    'pta_cree' => $first?->pta !== null ? 'Oui' : 'Non',
                    'actions_creees' => $total,
                    'actions_affectees' => $rows->filter(fn (Action $action): bool => $this->actionResponsibleLabel($action) !== 'Non assigne')->count(),
                    'en_cours' => $inProgress,
                    'realisees' => $completed,
                    'retards' => $late,
                    'taux_execution' => $this->completionRate($completed, $total),
                    'statut' => $this->groupEvolutionLabel($rows),
                ];
            })
            ->sortByDesc('actions_creees')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPtaServiceActionRows(Collection $actions, int $limit = 15): array
    {
        return $actions
            ->sortByDesc(fn (Action $action): string => $action->updated_at instanceof Carbon ? $action->updated_at->toIso8601String() : '')
            ->take($limit)
            ->map(function (Action $action): array {
                $deadline = $this->actionDeadline($action);

                return [
                    'service' => (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-'),
                    'objectif_operationnel' => (string) (($action->objectifOperationnel ?? $action->pta?->objectifOperationnel)?->libelle ?: 'Non renseigne'),
                    'action' => (string) $action->libelle,
                    'responsable' => $this->actionResponsibleLabel($action),
                    'debut' => $action->date_debut instanceof Carbon ? $action->date_debut->format('d/m/Y') : '-',
                    'echeance' => $deadline instanceof Carbon ? $deadline->format('d/m/Y') : '-',
                    'cible' => $this->actionTargetLabel($action),
                    'realise' => $this->actionRealizedLabel($action),
                    'reste' => $this->actionRemainingLabel($action),
                    'taux_realisation' => $this->actionQuantitativeRate($action),
                    'progression' => round((float) ($action->avancement_operationnel ?? $action->progression_reelle ?? 0), 2),
                    'statut' => $this->statusLabel($this->normalizeStatus((string) ($action->statut_dynamique ?? ''))),
                    'statut_delai' => $this->delayStatusLabel($action),
                    'justificatifs' => $this->actionProofs($action)->count(),
                    'performance' => $this->actionPerformanceLabel($action),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildAgentActionRows(Collection $actions, int $limit = 18): array
    {
        $rows = [];

        foreach ($actions as $action) {
            $agents = $this->actionAgents($action);
            foreach ($agents as $agent) {
                $agentId = (int) ($agent->id ?? 0);
                $subActions = $this->actionSubActions($action);
                $agentSubActions = $agentId > 0
                    ? $subActions->filter(fn ($subAction): bool => (int) ($subAction->agent_id ?? 0) === $agentId)
                    : $subActions;
                $subTotal = $agentSubActions->count();
                $subDone = $agentSubActions->filter(fn ($subAction): bool => (bool) ($subAction->est_effectuee ?? false))->count();

                $rows[] = [
                    'agent' => (string) ($agent->name ?? 'Non assigne'),
                    'action' => (string) $action->libelle,
                    'objectif_operationnel' => (string) (($action->objectifOperationnel ?? $action->pta?->objectifOperationnel)?->libelle ?: 'Non renseigne'),
                    'pta' => (string) ($action->pta?->titre ?: 'PTA'),
                    'direction' => (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? '-'),
                    'service' => (string) ($action->pta?->service?->libelle ?? $action->pta?->service?->code ?? '-'),
                    'echeance' => $this->actionDeadline($action)?->format('d/m/Y') ?? '-',
                    'cible' => $this->actionTargetLabel($action),
                    'realise' => $this->actionRealizedLabel($action),
                    'reste' => $this->actionRemainingLabel($action),
                    'sous_actions' => $subDone.'/'.$subTotal,
                    'progression' => round((float) ($action->avancement_operationnel ?? $action->progression_reelle ?? 0), 2),
                    'taux_realisation' => $this->actionQuantitativeRate($action),
                    'statut' => $this->statusLabel($this->normalizeStatus((string) ($action->statut_dynamique ?? ''))),
                    'statut_delai' => $this->delayStatusLabel($action),
                    'performance' => $this->actionPerformanceLabel($action),
                    'justificatifs' => $this->actionProofs($action)->count(),
                    'commentaires' => $subActions->filter(fn ($subAction): bool => trim((string) ($subAction->commentaire ?? '')) !== '')->count(),
                    'derniere_activite' => $this->lastActivityLabel(collect([$action])),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            }
        }

        return collect($rows)
            ->sortByDesc('derniere_activite')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildSubActionRows(Collection $actions, int $limit = 18): array
    {
        $rows = [];

        foreach ($actions as $action) {
            foreach ($this->actionSubActions($action) as $subAction) {
                $planned = (float) ($subAction->cible_prevue ?? 0);
                $realized = (float) ($subAction->quantite_realisee ?? 0);
                $rate = $planned > 0 ? min(100.0, ($realized / $planned) * 100) : (float) ($subAction->taux_realisation ?? $subAction->taux_execution ?? 0);
                $date = $subAction->completed_at ?? $subAction->date_realisation ?? $subAction->date_fin ?? null;

                $rows[] = [
                    'action' => (string) $action->libelle,
                    'sous_action' => (string) ($subAction->libelle ?? 'Sous-action'),
                    'description' => (string) ($subAction->description ?? '-'),
                    'cible' => $planned > 0 ? number_format($planned, 0, ',', ' ') : '-',
                    'realise' => number_format($realized, 0, ',', ' '),
                    'unite' => (string) ($subAction->unite ?? $action->unite_cible ?? '-'),
                    'taux' => round($rate, 2),
                    'resultat' => (string) ($subAction->resultat_obtenu ?? '-'),
                    'effectuee' => (bool) ($subAction->est_effectuee ?? false) ? 'Oui' : 'Non',
                    'date_realisation' => $date instanceof Carbon ? $date->format('d/m/Y') : '-',
                    'justificatif' => $subAction->relationLoaded('justificatifs') && $subAction->justificatifs->isNotEmpty() ? 'Oui' : 'Non',
                    'commentaire' => (string) ($subAction->commentaire ?? '-'),
                    'controle' => (string) ($action->motif_validation_chef ?: '-'),
                    'statut' => (string) ($subAction->statut ?? ((bool) ($subAction->est_effectuee ?? false) ? 'Effectuee' : 'En cours')),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            }
        }

        return collect($rows)
            ->take($limit)
            ->values()
            ->all();
    }

    private function actionDeadline(Action $action): ?Carbon
    {
        if ($action->date_fin instanceof Carbon) {
            return $action->date_fin;
        }

        if ($action->date_echeance instanceof Carbon) {
            return $action->date_echeance;
        }

        return $action->objectifOperationnel?->echeance instanceof Carbon
            ? $action->objectifOperationnel->echeance
            : null;
    }

    private function actionReferenceDate(Action $action): ?Carbon
    {
        return $this->actionDeadline($action)
            ?? ($action->date_debut instanceof Carbon ? $action->date_debut : null)
            ?? ($action->created_at instanceof Carbon ? $action->created_at : null);
    }

    private function actionResponsibleLabel(Action $action): string
    {
        if ($action->relationLoaded('responsables') && $action->responsables->isNotEmpty()) {
            return $action->responsables
                ->pluck('name')
                ->filter()
                ->take(2)
                ->implode(', ');
        }

        return (string) ($action->responsable?->name ?? 'Non assigne');
    }

    private function strategicObjectiveLabel($objective): string
    {
        if ($objective === null) {
            return 'Non renseigne';
        }

        $axisCode = (string) ($objective->pasAxe?->code ?? '');
        $objectiveCode = (string) ($objective->code ?? '');
        $objectiveLabel = (string) ($objective->libelle ?? 'Non renseigne');

        return trim($axisCode.' / '.$objectiveCode.' - '.$objectiveLabel, ' /-') ?: $objectiveLabel;
    }

    private function actionProofs(Action $action): Collection
    {
        $actionProofs = $action->relationLoaded('justificatifs') ? $action->justificatifs : collect();
        $subActionProofs = $action->relationLoaded('sousActions')
            ? $action->sousActions->flatMap(fn ($subAction): Collection => $subAction->relationLoaded('justificatifs') ? $subAction->justificatifs : collect())
            : collect();

        return $actionProofs->concat($subActionProofs)->values();
    }

    private function isCompletedAction(Action $action): bool
    {
        $rawStatus = (string) ($action->statut_dynamique ?? '');

        return $this->normalizeStatus($rawStatus) === 'acheve'
            || $rawStatus === ActionTrackingService::STATUS_CLOTUREE;
    }

    private function isInProgressAction(Action $action): bool
    {
        return in_array($this->normalizeStatus((string) ($action->statut_dynamique ?? '')), ['en_cours', 'a_risque', 'en_avance'], true);
    }

    private function isLateAction(Action $action): bool
    {
        return $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard';
    }

    private function isCompletedLateAction(Action $action): bool
    {
        return (string) ($action->statut_dynamique ?? '') === ActionTrackingService::STATUS_ACHEVE_HORS_DELAI;
    }

    /**
     * @param Collection<int, Action> $actions
     */
    private function averageQuantitativeRate(Collection $actions): float
    {
        $targetActions = $actions->filter(function (Action $action): bool {
            return (float) ($action->quantite_cible ?? 0) > 0
                || (float) ($action->taux_atteinte_cible ?? 0) > 0;
        });

        if ($targetActions->isEmpty()) {
            return 0.0;
        }

        return round((float) $targetActions->avg(fn (Action $action): float => $this->actionQuantitativeRate($action)), 2);
    }

    /**
     * @param Collection<int, Action> $actions
     */
    private function dashboardScore(Collection $actions, float $fallbackRate): float
    {
        $kpiAverage = round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
        if ($kpiAverage > 0) {
            return $kpiAverage;
        }

        $quantitativeRate = $this->averageQuantitativeRate($actions);
        if ($quantitativeRate > 0) {
            return $quantitativeRate;
        }

        return $fallbackRate;
    }

    private function performanceLabel(float $score): string
    {
        if ($score >= 90) {
            return 'Excellente';
        }

        if ($score >= 80) {
            return 'Satisfaisante';
        }

        if ($score >= 60) {
            return 'Acceptable';
        }

        if ($score > 0) {
            return 'Sous-seuil';
        }

        return 'Non evaluee';
    }

    /**
     * @param Collection<int, Action> $actions
     */
    private function groupEvolutionLabel(Collection $actions): string
    {
        $total = $actions->count();
        if ($total <= 0) {
            return 'Non decline';
        }

        $late = $actions->filter(fn (Action $action): bool => $this->isLateAction($action))->count();
        if ($late > 0) {
            return 'En retard';
        }

        $completed = $actions->filter(fn (Action $action): bool => $this->isCompletedAction($action))->count();
        if ($completed === $total) {
            return 'Execute';
        }

        $inProgress = $actions->filter(fn (Action $action): bool => $this->isInProgressAction($action))->count();
        if ($inProgress > 0) {
            return 'En execution';
        }

        return 'En declinaison';
    }

    /**
     * @param Collection<int, Action> $actions
     */
    private function lastActivityLabel(Collection $actions): string
    {
        $latest = null;

        foreach ($actions as $action) {
            foreach ([$action->updated_at, $action->created_at, $action->soumise_le, $action->evalue_le] as $date) {
                if ($date instanceof Carbon && ($latest === null || $date->gt($latest))) {
                    $latest = $date;
                }
            }

            foreach ($this->actionSubActions($action) as $subAction) {
                foreach ([$subAction->updated_at ?? null, $subAction->created_at ?? null, $subAction->completed_at ?? null, $subAction->date_realisation ?? null] as $date) {
                    if ($date instanceof Carbon && ($latest === null || $date->gt($latest))) {
                        $latest = $date;
                    }
                }
            }
        }

        return $latest instanceof Carbon ? $latest->format('d/m/Y') : '-';
    }

    private function pasDeclinationLabel(int $paoCount, float $declinationRate, int $actionCount): string
    {
        if ($paoCount <= 0) {
            return 'Non decline';
        }

        if ($actionCount > 0 && $declinationRate >= 100) {
            return 'En execution';
        }

        if ($declinationRate >= 100) {
            return 'Totalement decline';
        }

        if ($declinationRate > 0) {
            return 'Partiellement decline';
        }

        return 'En declinaison';
    }

    private function actionTargetLabel(Action $action): string
    {
        $target = (float) ($action->quantite_cible ?? 0);
        if ($target <= 0) {
            return $this->actionSubActions($action)->isNotEmpty() ? 'Par sous-action' : '-';
        }

        return trim($this->formatQuantity($target).' '.(string) ($action->unite_cible ?? ''));
    }

    private function actionRealizedLabel(Action $action): string
    {
        $realized = (float) ($action->quantite_realisee ?? 0);
        if ((float) ($action->quantite_cible ?? 0) <= 0) {
            $subActions = $this->actionSubActions($action);
            if ($subActions->isEmpty()) {
                return '-';
            }

            $done = $subActions->filter(fn ($subAction): bool => (bool) ($subAction->est_effectuee ?? false))->count();

            return $done.'/'.$subActions->count().' sous-actions';
        }

        return trim($this->formatQuantity($realized).' '.(string) ($action->unite_cible ?? ''));
    }

    private function actionRemainingLabel(Action $action): string
    {
        $target = (float) ($action->quantite_cible ?? 0);
        if ($target <= 0) {
            return '-';
        }

        $remaining = (float) ($action->reste_a_realiser ?? max(0.0, $target - (float) ($action->quantite_realisee ?? 0)));

        return trim($this->formatQuantity($remaining).' '.(string) ($action->unite_cible ?? ''));
    }

    private function actionQuantitativeRate(Action $action): float
    {
        $target = (float) ($action->quantite_cible ?? 0);
        $realized = (float) ($action->quantite_realisee ?? 0);

        if ($target > 0) {
            return round(min(100.0, ($realized / $target) * 100), 2);
        }

        return round((float) ($action->taux_atteinte_cible ?? 0), 2);
    }

    private function delayStatusLabel(Action $action): string
    {
        $rawStatus = (string) ($action->statut_dynamique ?? '');
        if ($rawStatus === ActionTrackingService::STATUS_ACHEVE_HORS_DELAI) {
            return 'Achevee hors delai';
        }

        if ($this->isLateAction($action)) {
            return 'En retard';
        }

        if ($this->isCompletedAction($action)) {
            return 'Dans les delais';
        }

        $deadline = $this->actionDeadline($action);
        if (! $deadline instanceof Carbon) {
            return '-';
        }

        if ($deadline->betweenIncluded(Carbon::today(), Carbon::today()->addDays(7))) {
            return 'Proche echeance';
        }

        return 'Dans les delais';
    }

    private function actionPerformanceLabel(Action $action): string
    {
        $status = (string) ($action->statut_performance ?? '');
        if ($status !== '') {
            return match ($status) {
                'cible_depassee' => 'Cible depassee',
                'critique' => 'Critique',
                'sous_seuil' => 'Sous-seuil',
                'acceptable' => 'Acceptable',
                'satisfaisante' => 'Satisfaisante',
                'excellente' => 'Excellente',
                default => 'Non evaluee',
            };
        }

        $score = (float) ($action->actionKpi?->kpi_global ?? 0);
        if ($score <= 0) {
            $score = $this->actionQuantitativeRate($action);
        }

        return $this->performanceLabel($score);
    }

    private function formatQuantity(float $value): string
    {
        $precision = abs($value - round($value)) < 0.005 ? 0 : 2;

        return number_format($value, $precision, ',', ' ');
    }

    /**
     * @return Collection<int, mixed>
     */
    private function actionAgents(Action $action): Collection
    {
        $agents = collect();
        if ($action->relationLoaded('responsables') && $action->responsables->isNotEmpty()) {
            $agents = $agents->concat($action->responsables);
        }

        if ($action->responsable !== null) {
            $agents = $agents->push($action->responsable);
        }

        if ($action->relationLoaded('sousActions')) {
            $agents = $agents->concat(
                $action->sousActions
                    ->filter(fn ($subAction): bool => (int) ($subAction->agent_id ?? 0) > 0)
                    ->map(fn ($subAction) => $subAction->agent ?? (object) [
                        'id' => (int) $subAction->agent_id,
                        'name' => 'Agent #'.(int) $subAction->agent_id,
                    ])
            );
        }

        if ($agents->isEmpty()) {
            return collect([(object) ['id' => 0, 'name' => 'Non assigne']]);
        }

        return $agents
            ->filter()
            ->unique(fn ($agent): string => (string) ($agent->id ?? spl_object_id($agent)))
            ->values();
    }

    /**
     * @return Collection<int, mixed>
     */
    private function actionSubActions(Action $action): Collection
    {
        return $action->relationLoaded('sousActions') ? $action->sousActions : collect();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildSynthesisObjectiveRows(Collection $actions, int $limit = 12): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->pao?->pasObjectif?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')
                    ->count();
                $late = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
                    ->count();
                $objective = $first?->pta?->pao?->pasObjectif;
                $axisCode = (string) ($objective?->pasAxe?->code ?? '');
                $objectiveCode = (string) ($objective?->code ?? '');
                $objectiveLabel = (string) ($objective?->libelle ?? 'Non renseigne');

                return [
                    'objectif' => trim($axisCode.' '.$objectiveCode) !== ''
                        ? trim($axisCode.' / '.$objectiveCode.' - '.$objectiveLabel, ' /-')
                        : $objectiveLabel,
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'progression' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
                    'score' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildSynthesisPaoRows(Collection $actions, int $limit = 12): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->pao?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')
                    ->count();
                $late = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
                    ->count();
                $pao = $first?->pta?->pao;

                return [
                    'pao' => (string) ($pao?->titre ?: ('PAO '.($pao?->annee ?? ''))),
                    'annee' => (string) ($pao?->annee ?? '-'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'progression' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
                    'score' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildSynthesisPtaRows(Collection $actions, int $limit = 12): array
    {
        return $actions
            ->groupBy(fn (Action $action): string => (string) ($action->pta?->id ?? 0))
            ->map(function (Collection $rows): array {
                $first = $rows->first();
                $total = $rows->count();
                $completed = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'acheve')
                    ->count();
                $late = $rows
                    ->filter(fn (Action $action): bool => $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard')
                    ->count();
                $pta = $first?->pta;

                return [
                    'pta' => (string) ($pta?->titre ?: 'PTA'),
                    'service' => (string) ($pta?->service?->code ?? $pta?->service?->libelle ?? '-'),
                    'actions_total' => $total,
                    'achevees' => $completed,
                    'retards' => $late,
                    'progression' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
                    'score' => round((float) $rows->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2),
                ];
            })
            ->sortByDesc('actions_total')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildDashboardAlertRows(Collection $actions): array
    {
        $today = Carbon::today();
        $rows = [];

        foreach ($actions as $action) {
            $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
            $status = (string) ($action->statut_dynamique ?? '');
            $globalKpi = (float) ($action->actionKpi?->kpi_global ?? 0);
            $conformiteKpi = 0.0;
            $gap = max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));

            if ($deadline instanceof Carbon && $deadline->lt($today) && ! in_array($status, [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true)) {
                if (in_array($status, [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)) {
                    continue;
                }
                $daysLate = $deadline->diffInDays($today);
                $rows[] = [
                    'titre' => (float) ($action->progression_reelle ?? 0) <= 0 ? 'Action non demarree' : 'Action en retard',
                    'niveau' => $daysLate >= 7 || (float) ($action->progression_reelle ?? 0) <= 0 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => $daysLate.'j',
                    'kpi' => round($globalKpi, 2),
                    'kpi_conformite' => round($conformiteKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if ($globalKpi > 0 && $globalKpi < 60) {
                $rows[] = [
                    'titre' => 'Indicateur global critique',
                    'niveau' => $globalKpi < 40 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => number_format($globalKpi, 0).' / 100',
                    'kpi' => round($globalKpi, 2),
                    'kpi_conformite' => round($conformiteKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if (
                $deadline instanceof Carbon
                && $deadline->lt($today)
                && ! in_array($status, [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true)
                && ! in_array($status, [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)
                && $globalKpi > 0
                && $globalKpi < 40
            ) {
                $rows[] = [
                    'titre' => 'Retard + indicateur critique',
                    'niveau' => 'Urgence',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => 'Escalade DG',
                    'kpi' => round($globalKpi, 2),
                    'kpi_conformite' => round($conformiteKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if ($gap >= 15) {
                $rows[] = [
                    'titre' => 'Ecart de progression',
                    'niveau' => $gap >= 30 ? 'Critique' : 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => number_format($gap, 0).' pts',
                    'kpi' => round($globalKpi, 2),
                    'kpi_conformite' => round($conformiteKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-status',
                ];
            }

            if (
                $action->soumise_le instanceof Carbon
                && (string) ($action->statut_validation ?? '') === ActionTrackingService::VALIDATION_SOUMISE_CHEF
                && $action->soumise_le->diffInDays($today) >= 7
            ) {
                $rows[] = [
                    'titre' => 'Validation chef bloquee',
                    'niveau' => 'Attention',
                    'direction' => (string) ($action->pta?->direction?->code ?? '-'),
                    'action' => (string) $action->libelle,
                    'details' => $action->soumise_le->diffInDays($today).'j',
                    'kpi' => round($globalKpi, 2),
                    'kpi_conformite' => round($conformiteKpi, 2),
                    'url' => route('workspace.actions.suivi', $action).'#action-validation',
                ];
            }
        }

        return collect($rows)
            ->sortByDesc(function (array $row): int {
                return $row['niveau'] === 'Critique' ? 2 : 1;
            })
            ->take(8)
            ->values()
            ->all();
    }

    private function buildInterannualComparison(User $user): array
    {
        $paoQuery = Pao::query();
        $this->scopePao($paoQuery, $user);

        return $paoQuery
            ->with([
                'ptas' => function ($query) use ($user): void {
                    $this->scopePta($query, $user);
                    $query->with([
                        'actions' => function ($actionQuery) use ($user): void {
                            $this->scopeAction($actionQuery, $user);
                        },
                    ]);
                },
            ])
            ->orderBy('annee')
            ->get()
            ->groupBy('annee')
            ->map(function (Collection $rows, $annee): array {
                $ptas = $rows->flatMap->ptas;
                $actions = $ptas->flatMap->actions;
                $actionsTotal = $actions->count();
                $validatedActions = $this->officialActions($actions);
                $actionsValidees = $validatedActions->count();
                $actionsRetard = $actions->filter(function (Action $action): bool {
                    return $this->normalizeStatus((string) ($action->statut_dynamique ?? '')) === 'en_retard';
                })->count();

                return [
                    'annee' => (int) $annee,
                    'url' => route('workspace.pao.index', ['annee' => (int) $annee]),
                    'paos_total' => $rows->count(),
                    'ptas_total' => $ptas->count(),
                    'actions_total' => $actionsTotal,
                    'actions_validees' => $actionsValidees,
                    'actions_retard' => $actionsRetard,
                    'progression_moyenne' => $validatedActions->isNotEmpty()
                        ? round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                        : 0.0,
                    'taux_validation' => $this->completionRate($actionsValidees, $actionsTotal),
                ];
            })
            ->sortBy('annee')
            ->values()
            ->all();
    }

    private function resolveUnitMeta(User $user): array
    {
        if ($user->hasGlobalReadAccess()) {
            return ['mode' => 'direction', 'label' => 'Directions'];
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return ['mode' => 'service', 'label' => 'Services'];
        }

        return ['mode' => 'action', 'label' => 'Actions'];
    }

    /**
     * @return array{label: string, empty_label: string}
     */
    private function resolvePerformanceGaugeMeta(User $user): array
    {
        return match (true) {
            $user->hasGlobalReadAccess() => [
                'label' => 'Directions',
                'empty_label' => 'Aucune direction disponible pour les jauges.',
            ],
            $user->hasRole(User::ROLE_DIRECTION) => [
                'label' => 'Services',
                'empty_label' => 'Aucun service disponible pour les jauges.',
            ],
            default => [
                'label' => 'Actions',
                'empty_label' => 'Aucune action disponible pour les jauges.',
            ],
        };
    }

    /**
     * Les jauges de performance doivent lire l\'exécution organisationnelle,
     * pas les axes/objectifs stratégiques du PAS.
     *
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPerformanceGaugeRows(User $user, Collection $actions): array
    {
        $mode = match (true) {
            $user->hasGlobalReadAccess() => 'direction',
            $user->hasRole(User::ROLE_DIRECTION) => 'service',
            default => 'action',
        };

        return collect($this->buildUnitRows($actions, $mode))
            ->filter(fn (array $row): bool => (int) ($row['actions_total'] ?? 0) > 0)
            ->sortByDesc(fn (array $row): float => (float) ($row['kpi_global'] ?? 0))
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function officialActions(Collection $actions): Collection
    {
        /** @var Collection<int, Action> $official */
        $official = $this->actionCalculationSettings->filterOfficial($actions, 'statut_validation');

        return $official;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function validatedActions(Collection $actions): Collection
    {
        return $this->officialActions($actions);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedActionFilters(array $filters = []): array
    {
        return array_merge($this->actionCalculationSettings->officialRouteFilters(), $filters);
    }

    private function validatedActionIndexRoute(array $filters = []): string
    {
        return $this->actionIndexRoute($this->validatedActionFilters($filters));
    }

    private function officialActionIndexRoute(array $filters = []): string
    {
        return $this->actionIndexRoute(array_merge(
            $this->actionCalculationSettings->officialRouteFilters(),
            $filters
        ));
    }

    private function actionIndexRoute(array $filters = []): string
    {
        return route('workspace.actions.index', array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI => 'acheve',
            ActionTrackingService::STATUS_A_RISQUE => 'a_risque',
            ActionTrackingService::STATUS_EN_AVANCE => 'en_avance',
            ActionTrackingService::STATUS_EN_RETARD => 'en_retard',
            ActionTrackingService::STATUS_SUSPENDU => 'suspendu',
            ActionTrackingService::STATUS_ANNULE => 'annule',
            ActionTrackingService::STATUS_NON_DEMARRE => 'non_demarre',
            default => 'en_cours',
        };
    }

    private function dashboardStatus(Action $action): string
    {
        return $this->actionStatusService->dashboardStatus($action);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'a_parametrer' => 'À paramétrer',
            'acheve' => 'Achevé',
            'a_risque' => 'A surveiller',
            'en_avance' => 'En avance',
            'en_retard' => 'En retard',
            'suspendu' => 'Suspendu',
            'annule' => 'Annule',
            'non_demarre' => 'Non demarre',
            default => 'En cours',
        };
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'a_parametrer' => '#A855F7',
            'acheve' => '#1C203D',
            'a_risque' => '#F0E509',
            'en_avance' => '#8FC043',
            'en_retard' => '#F9B13C',
            'suspendu' => '#7C3AED',
            'annule' => '#475569',
            'non_demarre' => '#64748B',
            default => '#3996D3',
        };
    }

    private function kpiColor(float $value): string
    {
        if ($value >= 80) {
            return '#8FC043';
        }

        if ($value >= 60) {
            return '#F0E509';
        }

        if ($value > 0) {
            return '#F9B13C';
        }

        return '#94A3B8';
    }

    private function isAlertAction(Action $action): bool
    {
        $deadline = $action->date_echeance instanceof Carbon ? $action->date_echeance : $action->date_fin;
        if (in_array((string) ($action->statut_dynamique ?? ''), [ActionTrackingService::STATUS_SUSPENDU, ActionTrackingService::STATUS_ANNULE], true)) {
            return false;
        }
        $isOverdue = $deadline instanceof Carbon
            && $deadline->lt(Carbon::today())
            && ! in_array((string) ($action->statut_dynamique ?? ''), [ActionTrackingService::STATUS_ACHEVE_DANS_DELAI, ActionTrackingService::STATUS_ACHEVE_HORS_DELAI], true);
        $isLowKpi = (float) ($action->actionKpi?->kpi_global ?? 0) > 0 && (float) ($action->actionKpi?->kpi_global ?? 0) < 60;

        return $isOverdue
            || $isLowKpi
            || max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0)) >= 15;
    }

    private function activeActionAlertLogsCount(User $user): int
    {
        $query = ActionLog::query()
            ->activeAlert()
            ->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            });

        if (($directionId = $this->selectedDashboardDirectionId($user)) !== null) {
            $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }

        if (($serviceId = $this->selectedDashboardServiceId($user)) !== null) {
            $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }

        if (! $user->isAgent()) {
            $query->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $actionQuery
                    ->where(function (Builder $contextQuery): void {
                        $contextQuery
                            ->whereNull('contexte_action')
                            ->orWhere('contexte_action', Action::CONTEXT_PILOTAGE);
                    })
                    ->where(function (Builder $responsableQuery) use ($user): void {
                        $responsableQuery
                            ->whereNull('responsable_id')
                            ->orWhere('responsable_id', '!=', (int) $user->id);
                    });
            });
        }

        return $query->count();
    }

    private function completionRate(int $completed, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($completed / $total) * 100, 2);
    }

    private function canReadDashboard(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent();
    }
}
