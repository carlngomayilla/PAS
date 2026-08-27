<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Delegation;
use App\Models\Kpi;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use App\Services\ExerciceContext;
use App\Services\FinancialMonitoringService;
use App\Services\PtaSuiviService;
use App\Support\SafeSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DashboardOverviewReadModel
{
    use AuthorizesPlanningScope;

    /**
     * Relations shared by the web dashboard and the versioned API projection.
     *
     * @var list<string>
     */
    public const ACTION_RELATIONS = [
        'pta:id,pao_id,objectif_operationnel_id,titre,direction_id,service_id',
        'pta.pao:id,pas_id,pas_objectif_id,direction_id,annee,titre,objectif_operationnel,statut',
        'pta.pao.pas:id,titre,periode_debut,periode_fin,statut',
        'pta.pao.pasObjectif:id,pas_axe_id,code,libelle,ordre',
        'pta.pao.pasObjectif.pasAxe:id,pas_id,code,libelle,ordre',
        'objectifOperationnel:id,pao_id,pas_axe_id,pas_objectif_id,service_id,libelle,echeance,statut',
        'objectifOperationnel.pasAxe:id,code,libelle',
        'objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle',
        'pta.objectifOperationnel:id,pao_id,pas_axe_id,pas_objectif_id,service_id,libelle,echeance,statut',
        'pta.objectifOperationnel.pasAxe:id,code,libelle',
        'pta.objectifOperationnel.pasObjectif:id,pas_axe_id,code,libelle',
        'pta.direction:id,code,libelle',
        'pta.service:id,code,libelle',
        'responsable:id,name,email,is_active,suspended_until',
        'responsables:id,name,service_id,is_active,suspended_until',
        'justificatifs:id,justifiable_type,justifiable_id,sous_action_id,categorie,nom_original,description,ajoute_par,created_at',
        'justificatifs.ajoutePar:id,name',
        'sousActions:id,action_id,agent_id,libelle,statut,est_effectuee,taux_execution,quantite_realisee,date_fin,date_realisation,completed_at',
        'sousActions.agent:id,name,service_id,is_active,suspended_until',
        'sousActions.justificatifs:id,sous_action_id,nom_original,description,ajoute_par,created_at',
        'sousActions.justificatifs.ajoutePar:id,name',
        'actionKpi:id,action_id,kpi_delai,kpi_performance,kpi_global,progression_reelle,progression_theorique',
        'actionLogs:id,action_id,type_evenement',
    ];

    /** @var list<string> */
    private const RESPONSIBLE_OPTION_RELATIONS = [
        'responsable:id,name,is_active,suspended_until',
        'responsables:id,name,is_active,suspended_until',
        'sousActions:id,action_id,agent_id',
        'sousActions.agent:id,name,is_active,suspended_until',
    ];

    /**
     * @var array<int, string>
     */
    private array $dashboardStatusCache = [];

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ActionStatusService $actionStatusService,
        private readonly DashboardFilterContext $dashboardFilterContext,
        private readonly ExerciceContext $exerciceContext,
        private readonly FinancialMonitoringService $financialMonitoringService,
        private readonly PtaSuiviService $ptaSuiviService,
    ) {}

    public function useRequest(Request $request): self
    {
        $this->dashboardFilterContext->useRequest($request);

        return $this;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function responsibleOptions(User $user): array
    {
        $actions = Action::query();
        $this->scopeAction($actions, $user);

        /** @var Collection<int, Action> $scopedActions */
        $scopedActions = $actions
            ->select([
                'actions.id',
                'actions.pta_id',
                'actions.responsable_id',
                'actions.contexte_action',
            ])
            ->with(self::RESPONSIBLE_OPTION_RELATIONS)
            ->orderByDesc('id')
            ->get();
        $dashboardActions = $this->splitActionCollections($user, $scopedActions)['dashboard'];

        return $this->dashboardFilterContext->responsibleOptions($dashboardActions);
    }

    /**
     * @return array{effective_role: string, custom_role_code: string|null, resolved_permissions: string, active_delegations: string}
     */
    public function cacheDimensions(User $user): array
    {
        $delegations = $user->activeDelegations()
            ->sortBy(fn (Delegation $delegation): int => (int) $delegation->getKey())
            ->map(function (Delegation $delegation): array {
                $permissions = collect($delegation->permissions ?? [])
                    ->filter(static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
                    ->map(static fn (string $permission): string => trim($permission))
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $delegation->getKey(),
                    'delegant_id' => (int) $delegation->delegant_id,
                    'role_scope' => (string) $delegation->role_scope,
                    'direction_id' => $delegation->direction_id !== null ? (int) $delegation->direction_id : null,
                    'service_id' => $delegation->service_id !== null ? (int) $delegation->service_id : null,
                    'permissions' => $permissions,
                    'date_debut' => $delegation->date_debut?->toISOString(),
                    'date_fin' => $delegation->date_fin?->toISOString(),
                ];
            })
            ->values()
            ->all();
        $customRoleCode = trim((string) ($user->custom_role_code ?? ''));
        $resolvedPermissions = collect($user->grantedPermissions())
            ->filter(static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '')
            ->map(static fn (string $permission): string => trim($permission))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'effective_role' => $user->effectiveRoleCode(),
            'custom_role_code' => $customRoleCode !== '' ? $customRoleCode : null,
            'resolved_permissions' => sha1(json_encode($resolvedPermissions, JSON_THROW_ON_ERROR)),
            'active_delegations' => sha1(json_encode($delegations, JSON_THROW_ON_ERROR)),
        ];
    }

    public function read(User $user, bool $includeFinancialSummary = true): DashboardOverviewSnapshot
    {
        $this->dashboardStatusCache = [];
        $today = Carbon::today()->toDateString();
        $pas = $this->buildPasScopedQuery($user);
        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $kpis = Kpi::query();
        $measures = KpiMesure::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeKpi($kpis, $user);
        $this->scopeMesure($measures, $user);

        /** @var Collection<int, Action> $allScopedActions */
        $allScopedActions = (clone $actions)
            ->with(self::ACTION_RELATIONS)
            ->orderByDesc('date_echeance')
            ->get();
        $allActionSets = $this->splitActionCollections($user, $allScopedActions);
        $allDashboardActions = $allActionSets['dashboard'];
        $scopedActions = $this->applySynthesisActionFilters($allScopedActions);
        $actionSets = $this->splitActionCollections($user, $scopedActions);
        $dashboardActions = $actionSets['dashboard'];
        $validatedActions = $this->officialActions($dashboardActions);
        $dashboardActionIds = $this->actionIds($dashboardActions);

        $this->scopeHierarchyQueriesToFilteredActions($pas, $paos, $ptas, $dashboardActions);
        $this->whereIntegerIds($kpis, 'action_id', $dashboardActionIds);
        $measures->whereHas('kpi', function (Builder $kpiQuery) use ($dashboardActionIds): void {
            $this->whereIntegerIds($kpiQuery, 'action_id', $dashboardActionIds);
        });

        $pasAggregate = (clone $pas)
            ->selectRaw("count(*) as total, sum(case when statut = 'actif' then 1 else 0 end) as actifs")
            ->first();
        $paoAggregate = (clone $paos)
            ->selectRaw("count(*) as total, sum(case when statut in ('en_cours', 'valide') then 1 else 0 end) as actifs")
            ->first();
        $ptaAggregate = (clone $ptas)
            ->selectRaw("count(*) as total, sum(case when statut = 'en_cours' then 1 else 0 end) as actifs")
            ->first();

        $totals = [
            'pas_total' => (int) ($pasAggregate->total ?? 0),
            'pas_actifs' => (int) ($pasAggregate->actifs ?? 0),
            'paos_total' => (int) ($paoAggregate->total ?? 0),
            'paos_actifs' => (int) ($paoAggregate->actifs ?? 0),
            'ptas_total' => (int) ($ptaAggregate->total ?? 0),
            'ptas_actifs' => (int) ($ptaAggregate->actifs ?? 0),
            'actions_total' => $dashboardActions->count(),
            'actions_validees' => $validatedActions->count(),
            'kpis_total' => (clone $kpis)->count(),
            'kpi_mesures_total' => (clone $measures)->count(),
        ];
        $statusBreakdown = [
            'paos' => $this->countByStatus($paos, 'statut'),
            'ptas' => $this->countByStatus($ptas, 'statut'),
            'actions' => $this->statusCounts($dashboardActions),
            'actions_validation' => $this->countActionsByAttribute($dashboardActions, 'statut_validation'),
        ];

        $kpiUnderThreshold = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiUnderThreshold, $user, 'ptas.direction_id', 'ptas.service_id');
        $this->applyActionContextFilter($kpiUnderThreshold, $user, 'actions.contexte_action');
        $this->whereIntegerIds($kpiUnderThreshold, 'actions.id', $dashboardActionIds);

        $alerts = [
            'actions_en_retard' => $dashboardActions
                ->filter(fn (Action $action): bool => $this->isLate($action, $today))
                ->count(),
            'mesures_kpi_sous_seuil' => $kpiUnderThreshold->count(),
            'alertes_action_actives' => $this->activeActionAlertLogsCount($user, $dashboardActionIds),
        ];
        $directionSelector = $this->dashboardFilterContext->directionContext($user);
        $selectedDirectionId = $this->selectedDirectionId($user);
        $selectedServiceId = $this->selectedServiceId($user);
        $effectiveOrganizationScope = $this->effectiveOrganizationScope(
            $user,
            $selectedDirectionId,
            $selectedServiceId,
        );
        $scope = [
            'mode' => $this->usesPersonalDashboardMode($user) ? 'personnel' : 'pilotage',
            'user_role' => $user->baseRoleCode(),
            'effective_role' => $user->effectiveRoleCode(),
            'cross_organization_filters' => $user->hasCrossOrganizationDashboardAccess(),
            'organization_filters_enabled' => (bool) $directionSelector['enabled'],
            'read_only' => ! $this->canWriteDashboard($user),
            'direction_id' => $effectiveOrganizationScope['direction_id'],
            'service_id' => $effectiveOrganizationScope['service_id'],
            'selected_direction_id' => $selectedDirectionId,
            'selected_service_id' => $selectedServiceId,
        ];

        return new DashboardOverviewSnapshot(
            allScopedActions: $allScopedActions,
            allDashboardActions: $allDashboardActions,
            scopedActions: $scopedActions,
            visibleActions: $actionSets['visible'],
            dashboardActions: $dashboardActions,
            personalActions: $actionSets['personal'],
            validatedActions: $validatedActions,
            totals: $totals,
            statusBreakdown: $statusBreakdown,
            alerts: $alerts,
            scope: $scope,
            filters: $this->dashboardFilterContext->synthesisFilters(),
            filterOptions: $this->dashboardFilterContext->filterOptions($allDashboardActions),
            exercise: [
                'year' => $this->exerciceContext->selectedYear(),
                'quarter' => $this->exerciceContext->selectedQuarter(),
            ],
            directionSelector: $directionSelector,
            links: $this->dashboardLinks($user),
            synthesisDecisionSummary: $this->buildSynthesisDecisionSummary($dashboardActions),
            financialSummary: $includeFinancialSummary
                ? $this->financialMonitoringService->dashboardSummaryForActions($user, $dashboardActions)
                : null,
            generatedAt: now()->toISOString(),
        );
    }

    /**
     * @return array{direction_id: int|null, service_id: int|null}
     */
    private function effectiveOrganizationScope(
        User $user,
        ?int $selectedDirectionId,
        ?int $selectedServiceId
    ): array {
        if ($selectedDirectionId !== null) {
            return [
                'direction_id' => $selectedDirectionId,
                'service_id' => $selectedServiceId,
            ];
        }

        if ($user->hasCrossOrganizationDashboardAccess()
            || $this->hasDelegatedPlanningScope($user)
            || $this->usesPersonalDashboardMode($user)) {
            return [
                'direction_id' => null,
                'service_id' => null,
            ];
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return [
                'direction_id' => (int) $user->direction_id,
                'service_id' => null,
            ];
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            return [
                'direction_id' => (int) $user->direction_id,
                'service_id' => (int) $user->service_id,
            ];
        }

        return [
            'direction_id' => null,
            'service_id' => null,
        ];
    }

    /**
     * @return array{
     *     blade_pilotage: string,
     *     tables: string,
     *     charts: string,
     *     actions: string,
     *     pas: string,
     *     paos: string,
     *     ptas: string,
     *     late_actions: string,
     *     kpi_below_threshold: string,
     *     reporting: string,
     *     alerts: string,
     *     pta_tracking: string,
     *     breakdowns: array{
     *         actions: array<string, string>,
     *         workflow: array<string, string>,
     *         alerts: array<string, string>
     *     }
     * }
     */
    private function dashboardLinks(User $user): array
    {
        $dashboardFilters = $this->dashboardFilterContext->dashboardRouteFilters($user);
        $tableFilters = [
            ...$dashboardFilters,
            'dashboardTab' => 'advanced',
        ];

        return [
            'blade_pilotage' => route('dashboard', [
                ...$dashboardFilters,
                'dashboardTab' => 'overview',
            ], false),
            'tables' => route('dashboard', [
                ...$dashboardFilters,
                'dashboardTab' => 'advanced',
            ], false),
            'charts' => route('dashboard', [
                ...$dashboardFilters,
                'dashboardTab' => 'charts',
            ], false),
            'actions' => route('dashboard', $tableFilters, false),
            'pas' => route('dashboard', $tableFilters, false),
            'paos' => route('dashboard', $tableFilters, false),
            'ptas' => route('dashboard', $tableFilters, false),
            'late_actions' => route('dashboard', [
                ...$tableFilters,
                'statut_action' => 'en_retard',
            ], false),
            'kpi_below_threshold' => route('dashboard', $tableFilters, false),
            'reporting' => route('dashboard', $tableFilters, false),
            'alerts' => route('dashboard', $tableFilters, false),
            'pta_tracking' => route('dashboard', $tableFilters, false),
            'breakdowns' => [
                'actions' => $this->actionBreakdownLinks($dashboardFilters),
                'workflow' => $this->dashboardTableBreakdownLinks(
                    $dashboardFilters,
                    'statut_suivi',
                    array_keys(DashboardFilterContext::TRACKING_STATUS_OPTIONS),
                ),
                'alerts' => $this->dashboardTableBreakdownLinks(
                    $dashboardFilters,
                    'alerte_echeance',
                    array_keys(DashboardFilterContext::DEADLINE_ALERT_OPTIONS),
                ),
            ],
        ];
    }

    /**
     * @param  array<string, int|string>  $filters
     * @return array<string, string>
     */
    private function actionBreakdownLinks(array $filters): array
    {
        $links = [];

        foreach (array_keys(DashboardFilterContext::ACTION_STATUS_OPTIONS) as $status) {
            $links[$status] = route('dashboard', [
                ...$filters,
                'dashboardTab' => 'advanced',
                'statut_action' => $status,
            ], false);
        }

        return $links;
    }

    /**
     * @param  array<string, int|string>  $filters
     * @param  list<string>  $statuses
     * @return array<string, string>
     */
    private function dashboardTableBreakdownLinks(array $filters, string $filterName, array $statuses): array
    {
        $links = [];

        foreach ($statuses as $status) {
            $links[$status] = route('dashboard', [
                ...$filters,
                'dashboardTab' => 'advanced',
                $filterName => $status,
            ], false);
        }

        return $links;
    }

    public function scopePao(Builder|Relation $query, User $user): void
    {
        if (! $user->hasCrossOrganizationDashboardAccess()) {
            $this->scopeByUserDirection($query, $user, 'direction_id');
        }
        $this->exerciceContext->applyToPao($query);
        $this->applySelectedDirectionToDirectColumn($query, $user, 'direction_id');
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->where(function (Builder $paoQuery) use ($serviceId): void {
                $paoQuery->where('service_id', $serviceId)
                    ->orWhereHas('objectifsOperationnels', fn (Builder $objectifQuery) => $objectifQuery->where('service_id', $serviceId))
                    ->orWhereHas('ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
            });
        }
    }

    public function scopePta(Builder|Relation $query, User $user): void
    {
        if (! $user->hasCrossOrganizationDashboardAccess()) {
            $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        }
        $this->exerciceContext->applyToPta($query);
        $this->applySelectedDirectionToDirectColumn($query, $user, 'direction_id');
        $this->applySelectedServiceToDirectColumn($query, $user, 'service_id');
    }

    public function scopeAction(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToAction($query);
        $this->applySelectedDirectionToPtaRelation($query, $user);
        $this->applySelectedServiceToPtaRelation($query, $user);

        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if ($this->usesPersonalDashboardMode($user)) {
            $query->where(function (Builder $visibilityQuery) use ($user): void {
                $this->applyAgentAssignmentScope($visibilityQuery, $user);

                if ($this->hasDelegatedPlanningScope($user)) {
                    $visibilityQuery->orWhereHas('pta', function (Builder $ptaQuery) use ($user): void {
                        $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                    });
                }
            });

            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    public function scopeKpi(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToKpi($query);
        $this->applySelectedDirectionToActionRelation($query, $user);
        $this->applySelectedServiceToActionRelation($query, $user);

        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if ($this->usesPersonalDashboardMode($user)) {
            $query->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $actionQuery->where(function (Builder $visibilityQuery) use ($user): void {
                    $this->applyAgentAssignmentScope($visibilityQuery, $user);

                    if ($this->hasDelegatedPlanningScope($user)) {
                        $visibilityQuery->orWhereHas('pta', function (Builder $ptaQuery) use ($user): void {
                            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                        });
                    }
                });
            });

            return;
        }

        $query->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    public function scopeMesure(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToMesure($query);
        $this->applySelectedDirectionToMeasureRelation($query, $user);
        $this->applySelectedServiceToMeasureRelation($query, $user);

        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if ($this->usesPersonalDashboardMode($user)) {
            $query->whereHas('kpi.action', function (Builder $actionQuery) use ($user): void {
                $actionQuery->where(function (Builder $visibilityQuery) use ($user): void {
                    $this->applyAgentAssignmentScope($visibilityQuery, $user);

                    if ($this->hasDelegatedPlanningScope($user)) {
                        $visibilityQuery->orWhereHas('pta', function (Builder $ptaQuery) use ($user): void {
                            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                        });
                    }
                });
            });

            return;
        }

        $query->whereHas('kpi.action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    public function scopeJoinedPta(
        Builder $query,
        User $user,
        string $directionColumn,
        string $serviceColumn
    ): void {
        $this->exerciceContext->applyToJoinedPta($query);
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->where($directionColumn, $directionId);
        }
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->where($serviceColumn, $serviceId);
        }

        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if ($this->usesPersonalDashboardMode($user)) {
            $query->where(function (Builder $visibilityQuery) use ($user, $directionColumn, $serviceColumn): void {
                $visibilityQuery
                    ->where('actions.responsable_id', (int) $user->id)
                    ->orWhereExists(function ($responsableQuery) use ($user): void {
                        $responsableQuery
                            ->selectRaw('1')
                            ->from('action_responsables')
                            ->whereColumn('action_responsables.action_id', 'actions.id')
                            ->where('action_responsables.user_id', (int) $user->id);
                    })
                    ->orWhereExists(function ($subActionQuery) use ($user): void {
                        $subActionQuery
                            ->selectRaw('1')
                            ->from('sous_actions')
                            ->whereColumn('sous_actions.action_id', 'actions.id')
                            ->where('sous_actions.agent_id', (int) $user->id);
                    });

                if ($this->hasDelegatedPlanningScope($user)) {
                    $visibilityQuery->orWhere(function (Builder $delegatedQuery) use ($user, $directionColumn, $serviceColumn): void {
                        $this->scopeByUserDirection($delegatedQuery, $user, $directionColumn, $serviceColumn);
                    });
                }
            });

            return;
        }

        $this->scopeByUserDirection($query, $user, $directionColumn, $serviceColumn);
    }

    public function buildPasScopedQuery(User $user): Builder
    {
        $query = Pas::query();

        if ($this->usesPersonalDashboardMode($user)) {
            $query->where(function (Builder $visibilityQuery) use ($user): void {
                $visibilityQuery->whereHas('paos.ptas.actions', function (Builder $actionQuery) use ($user): void {
                    $this->applyAgentAssignmentScope($actionQuery, $user);
                });

                if ($this->hasDelegatedPlanningScope($user)) {
                    $visibilityQuery->orWhereHas('paos.ptas', function (Builder $ptaQuery) use ($user): void {
                        $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                    });
                }
            });
        } elseif (! $user->hasCrossOrganizationDashboardAccess()) {
            $this->scopePasByUser($query, $user);
        }

        $this->exerciceContext->applyToPas($query);
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->where(function (Builder $pasQuery) use ($directionId): void {
                $pasQuery->whereHas('paos', fn (Builder $paoQuery) => $paoQuery->where('direction_id', $directionId))
                    ->orWhereHas('directions', fn (Builder $directionQuery) => $directionQuery->whereKey($directionId));
            });
        }
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->where(function (Builder $pasQuery) use ($serviceId): void {
                $pasQuery->whereHas('paos.objectifsOperationnels', fn (Builder $objectifQuery) => $objectifQuery->where('service_id', $serviceId))
                    ->orWhereHas('paos.ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
            });
        }

        return $query;
    }

    private function selectedDirectionId(User $user): ?int
    {
        return $this->dashboardFilterContext->directionId($user);
    }

    private function selectedServiceId(User $user): ?int
    {
        return $this->dashboardFilterContext->serviceId($user);
    }

    private function applySelectedDirectionToDirectColumn(Builder|Relation $query, User $user, string $column): void
    {
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->where($column, $directionId);
        }
    }

    private function applySelectedDirectionToPtaRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }
    }

    private function applySelectedDirectionToActionRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }
    }

    private function applySelectedDirectionToMeasureRelation(Builder|Relation $query, User $user): void
    {
        if (($directionId = $this->selectedDirectionId($user)) !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
        }
    }

    private function applySelectedServiceToDirectColumn(Builder|Relation $query, User $user, string $column): void
    {
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->where($column, $serviceId);
        }
    }

    private function applySelectedServiceToPtaRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }
    }

    private function applySelectedServiceToActionRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->whereHas('action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }
    }

    private function applySelectedServiceToMeasureRelation(Builder|Relation $query, User $user): void
    {
        if (($serviceId = $this->selectedServiceId($user)) !== null) {
            $query->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
        }
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array{visible: Collection<int, Action>, dashboard: Collection<int, Action>, personal: Collection<int, Action>}
     */
    public function splitActionCollections(User $user, Collection $actions): array
    {
        $visibleActions = $actions->values();
        $personalActions = $visibleActions
            ->filter(fn (Action $action): bool => $this->isAssignedToUser($action, $user))
            ->values();
        $dashboardActions = $this->usesPersonalDashboardMode($user)
            ? $visibleActions
            : $visibleActions
                ->filter(fn (Action $action): bool => $this->isPilotageAction($action))
                ->values();

        return [
            'visible' => $visibleActions,
            'dashboard' => $dashboardActions,
            'personal' => $personalActions,
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return Collection<int, Action>
     */
    public function applySynthesisActionFilters(Collection $actions): Collection
    {
        $filters = $this->dashboardFilterContext->synthesisFilters();
        $periodRange = $this->ptaSuiviService->periodRange(
            $this->exerciceContext->selectedYear(),
            (string) ($filters['periode'] ?? 'all')
        );

        if ($periodRange === null && ! $filters['statut_action'] && ! $filters['statut_suivi'] && ! $filters['statut_delai'] && ! $filters['alerte_echeance'] && ! $filters['responsable_id']) {
            return $actions->values();
        }

        return $actions
            ->filter(function (Action $action) use ($filters, $periodRange): bool {
                if ($periodRange !== null) {
                    $date = $this->actionReferenceDate($action);
                    if (! $date instanceof Carbon || ! $date->betweenIncluded($periodRange[0], $periodRange[1])) {
                        return false;
                    }
                }

                if ($filters['responsable_id'] !== null
                    && ! $this->isAssignedToUserId($action, (int) $filters['responsable_id'])) {
                    return false;
                }

                if ($filters['statut_action'] !== null
                    && $this->dashboardStatus($action) !== $filters['statut_action']) {
                    return false;
                }

                if ($filters['statut_suivi'] !== null && $this->synthesisWorkflowStatus($action) !== $filters['statut_suivi']) {
                    return false;
                }

                if ($filters['statut_delai'] !== null && $this->synthesisDelayStatus($action) !== $filters['statut_delai']) {
                    return false;
                }

                if ($filters['alerte_echeance'] !== null && $this->synthesisAlertStatus($action) !== $filters['alerte_echeance']) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return Collection<int, Action>
     */
    public function officialActions(Collection $actions): Collection
    {
        /** @var Collection<int, Action> $officialActions */
        $officialActions = $this->actionCalculationSettings->filterOfficial($actions, 'statut_validation');

        return $officialActions;
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array<string, int>
     */
    public function statusCounts(Collection $actions): array
    {
        $counts = [
            'a_parametrer' => 0,
            'non_demarre' => 0,
            'en_cours' => 0,
            'a_risque' => 0,
            'en_avance' => 0,
            'en_retard' => 0,
            'a_corriger' => 0,
            'acheve' => 0,
            'suspendu' => 0,
            'annule' => 0,
        ];

        foreach ($actions as $action) {
            $status = $this->dashboardStatus($action);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array<string, mixed>
     */
    public function buildSynthesisDecisionSummary(Collection $actions): array
    {
        $workflowCounts = [
            'a_parametrer' => 0,
            'non_demarre' => 0,
            'en_cours' => 0,
            'validation_chef' => 0,
            'validation_controleur' => 0,
            'validation_planification' => 0,
            'cloture' => 0,
        ];
        $delayCounts = [
            'dans_les_delais' => 0,
            'hors_delai' => 0,
        ];
        $alertCounts = [
            'aucune_alerte' => 0,
            'echeance_proche' => 0,
            'critique' => 0,
            'en_retard' => 0,
            'cloturee' => 0,
            'a_parametrer' => 0,
        ];

        foreach ($actions as $action) {
            $workflow = $this->synthesisWorkflowStatus($action);
            $delay = $this->synthesisDelayStatus($action);
            $alert = $this->synthesisAlertStatus($action);

            $workflowCounts[$workflow] = ($workflowCounts[$workflow] ?? 0) + 1;
            $delayCounts[$delay] = ($delayCounts[$delay] ?? 0) + 1;
            $alertCounts[$alert] = ($alertCounts[$alert] ?? 0) + 1;
        }

        $total = $actions->count();
        $progress = $total > 0
            ? round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
            : 0.0;
        $performance = $total > 0
            ? round((float) $actions->avg(function (Action $action): float {
                $score = (float) ($action->actionKpi?->kpi_global ?? 0);

                return $score > 0 ? $score : $this->actionQuantitativeRate($action);
            }), 2)
            : 0.0;

        return [
            'total' => $total,
            'taux_execution' => $progress,
            'performance_pta' => $performance,
            'workflow' => $workflowCounts,
            'delay' => $delayCounts,
            'alerts' => $alertCounts,
        ];
    }

    public function synthesisWorkflowStatus(Action $action): string
    {
        if ($this->actionStatusService->isPendingSetup($action)) {
            return 'a_parametrer';
        }

        $dynamic = strtolower(trim((string) ($action->statut_dynamique ?? $action->statut ?? '')));
        $validation = strtolower(trim((string) ($action->statut_validation ?? '')));

        if ($dynamic === ActionTrackingService::STATUS_CLOTUREE || $action->cloture_le !== null) {
            return 'cloture';
        }

        if (in_array($validation, [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
        ], true)) {
            return 'validation_controleur';
        }

        if (in_array($validation, [
            ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION,
            ActionTrackingService::VALIDATION_CORRECTION_PLANIFICATION,
        ], true)) {
            return 'validation_planification';
        }

        if (in_array($validation, [
            ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION,
            ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            return 'cloture';
        }

        if ($validation === ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return 'validation_chef';
        }

        if ($this->actionStatusService->isNotStarted($action)) {
            return 'non_demarre';
        }

        return 'en_cours';
    }

    public function synthesisDelayStatus(Action $action): string
    {
        $deadline = $this->actionDeadline($action);
        if (! $deadline instanceof Carbon) {
            return 'dans_les_delais';
        }

        $deadlineDay = $deadline->copy()->endOfDay();
        $completedAt = $this->synthesisCompletedAt($action);
        if ($completedAt instanceof Carbon) {
            return $completedAt->copy()->startOfDay()->gt($deadlineDay) ? 'hors_delai' : 'dans_les_delais';
        }

        return Carbon::today()->gt($deadlineDay) ? 'hors_delai' : 'dans_les_delais';
    }

    public function synthesisAlertStatus(Action $action): string
    {
        if ($this->synthesisWorkflowStatus($action) === 'cloture' || $this->synthesisCompletedAt($action) instanceof Carbon) {
            return 'cloturee';
        }

        $deadline = $this->actionDeadline($action);
        if (! $deadline instanceof Carbon) {
            return 'a_parametrer';
        }

        $today = Carbon::today();
        $deadlineDay = $deadline->copy()->startOfDay();
        if ($today->gt($deadlineDay)) {
            return 'en_retard';
        }

        $days = $today->diffInDays($deadlineDay, false);
        if ($days <= 3) {
            return 'critique';
        }

        if ($days <= 7) {
            return 'echeance_proche';
        }

        return 'aucune_alerte';
    }

    public function synthesisCompletedAt(Action $action): ?Carbon
    {
        foreach (['date_fin_reelle', 'cloture_le', 'evalue_le'] as $field) {
            $date = $action->{$field} ?? null;
            if ($date instanceof Carbon) {
                return $date;
            }
        }

        return null;
    }

    private function applyAgentAssignmentScope(Builder $query, User $user): void
    {
        $query->where(function (Builder $assignmentQuery) use ($user): void {
            $assignmentQuery
                ->where('responsable_id', (int) $user->id)
                ->orWhereHas('responsables', fn (Builder $responsableQuery) => $responsableQuery->whereKey((int) $user->id))
                ->orWhereHas('sousActions', fn (Builder $subActionQuery) => $subActionQuery->where('agent_id', (int) $user->id));
        });
    }

    private function hasDelegatedPlanningScope(User $user): bool
    {
        return $user->delegatedDirectionIds('planning_read') !== []
            || $user->delegatedDirectionIds('planning_write') !== []
            || $user->delegatedServiceScopes('planning_read') !== []
            || $user->delegatedServiceScopes('planning_write') !== [];
    }

    private function isAssignedToUser(Action $action, User $user): bool
    {
        return $this->isAssignedToUserId($action, (int) $user->id);
    }

    private function isAssignedToUserId(Action $action, int $userId): bool
    {
        if ((int) ($action->responsable_id ?? 0) === $userId) {
            return true;
        }

        if ($action->relationLoaded('responsables')
            && $action->responsables->contains(fn ($responsable): bool => (int) $responsable->getKey() === $userId)) {
            return true;
        }

        return $action->relationLoaded('sousActions')
            && $action->sousActions->contains(fn ($subAction): bool => (int) ($subAction->agent_id ?? 0) === $userId);
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
            ->map(fn (mixed $value): int => (int) $value)
            ->toArray();

        return $result;
    }

    /**
     * @param  Collection<int, Action>  $actions
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

    private function isPilotageAction(Action $action): bool
    {
        return (string) ($action->contexte_action ?: Action::CONTEXT_PILOTAGE) === Action::CONTEXT_PILOTAGE;
    }

    private function usesPersonalDashboardMode(User $user): bool
    {
        return $user->isAgent() && ! $user->hasCrossOrganizationDashboardAccess();
    }

    private function applyActionContextFilter(Builder $query, User $user, string $contextColumn): void
    {
        if ($this->usesPersonalDashboardMode($user)) {
            return;
        }

        $query->where(function (Builder $contextQuery) use ($contextColumn): void {
            $contextQuery
                ->whereNull($contextColumn)
                ->orWhere($contextColumn, Action::CONTEXT_PILOTAGE);
        });
    }

    private function isLate(Action $action, string $today): bool
    {
        if (! $action->date_echeance instanceof Carbon || $action->date_echeance->toDateString() >= $today) {
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
     * @param  list<int>  $actionIds
     */
    private function activeActionAlertLogsCount(User $user, array $actionIds): int
    {
        $query = ActionLog::query()
            ->activeAlert()
            ->whereHas('action', function (Builder $actionQuery) use ($user): void {
                $this->scopeAction($actionQuery, $user);
            });
        $this->whereIntegerIds($query, 'action_id', $actionIds);

        if (! $this->usesPersonalDashboardMode($user)) {
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

    /**
     * @param  Collection<int, Action>  $actions
     * @return list<int>
     */
    private function actionIds(Collection $actions): array
    {
        return $actions
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Action>  $actions
     */
    private function scopeHierarchyQueriesToFilteredActions(
        Builder $pas,
        Builder $paos,
        Builder $ptas,
        Collection $actions
    ): void {
        if (! $this->hasSynthesisActionFilters()) {
            return;
        }

        $ptaIds = $actions
            ->pluck('pta_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $paoIds = $actions
            ->map(static fn (Action $action): mixed => $action->pao_id ?? $action->pta?->pao_id)
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $pasIds = $actions
            ->map(static fn (Action $action): mixed => $action->pta?->pao?->pas_id)
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->whereIntegerIds($pas, 'id', $pasIds);
        $this->whereIntegerIds($paos, 'id', $paoIds);
        $this->whereIntegerIds($ptas, 'id', $ptaIds);
    }

    private function hasSynthesisActionFilters(): bool
    {
        $filters = $this->dashboardFilterContext->synthesisFilters();

        return ($filters['periode'] ?? 'all') !== 'all'
            || collect([
                $filters['responsable_id'] ?? null,
                $filters['statut_action'] ?? null,
                $filters['statut_suivi'] ?? null,
                $filters['statut_delai'] ?? null,
                $filters['alerte_echeance'] ?? null,
            ])->contains(static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<int>  $ids
     */
    private function whereIntegerIds(Builder|Relation $query, string $column, array $ids): void
    {
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIntegerInRaw($column, $ids);
    }

    private function actionDeadline(Action $action): ?Carbon
    {
        if ($action->date_echeance instanceof Carbon) {
            return $action->date_echeance;
        }

        if ($action->date_fin instanceof Carbon) {
            return $action->date_fin;
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

    private function actionQuantitativeRate(Action $action): float
    {
        $target = (float) ($action->quantite_cible ?? 0);
        $realized = (float) ($action->quantite_realisee ?? 0);

        if ($target > 0) {
            return round(min(100.0, ($realized / $target) * 100), 2);
        }

        return round((float) ($action->taux_atteinte_cible ?? 0), 2);
    }

    private function dashboardStatus(Action $action): string
    {
        $objectId = spl_object_id($action);

        return $this->dashboardStatusCache[$objectId]
            ??= $this->actionStatusService->dashboardStatus($action);
    }

    private function canWriteDashboard(User $user): bool
    {
        return $user->hasAnyPermission(
            'planning.write.global',
            'planning.write.direction',
            'planning.write.service',
            'planning.strategic.manage',
        ) || $user->hasDelegatedPermission('planning_write');
    }
}
