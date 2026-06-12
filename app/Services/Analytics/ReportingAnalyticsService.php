<?php

namespace App\Services\Analytics;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\PaoObjectifOperationnel;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\ActionCalculationSettings;
use App\Services\Actions\ActionTrackingService;
use App\Services\ExerciceContext;
use App\Services\ManagedKpiSettings;
use App\Support\SafeSql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReportingAnalyticsService
{
    use AuthorizesPlanningScope;

    private const CACHE_TTL_SECONDS = 300;

    // A25 — Bornes explicites sur les listes de details (exports + dashboard).
    // Anti-OOM en RAM cote PHP et anti-explosion de taille XLSX/PDF. Le payload
    // expose `truncation.*.truncated` pour que les vues affichent un bandeau
    // signalant que la liste est tronquee.
    public const DETAIL_LIMIT_LATE_ACTIONS = 200;
    public const DETAIL_LIMIT_KPI_BELOW_THRESHOLD = 200;
    public const DETAIL_LIMIT_STRUCTURE = 300;

    // A33 — Seuil au-dela duquel on emet un warning en logs : les agregats
    // (KPI moyennes, repartition statuts) chargent toutes les actions visibles
    // en RAM pour les averager. Au-dela de ce seuil, prevoir un job batch ou
    // l agregation SQL native plutot que la fetch-everything-then-php.
    public const AGGREGATE_WARN_THRESHOLD = 5000;

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ManagedKpiSettings $managedKpiSettings,
        private readonly AnalyticsCacheVersionService $cacheVersionService,
        private readonly KpiAggregatorService $kpiAggregatorService,
        private readonly ActionSummaryService $actionSummaryService,
        private readonly ExerciceContext $exerciceContext,
        private readonly \App\Services\Actions\ActionTrackingService $actionTrackingService
    ) {
    }

    /**
     * @return array{
     *     generatedAt: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     statisticalPolicy: array{scope_status: string, scope_label: string, scope_summary: string},
     *     officialPolicy: array{threshold_status: string, threshold_label: string, scope_summary: string},
     *     global: array<string, int>,
     *     kpiSummary: array<string, float>,
     *     managedKpis: list<array<string, mixed>>,
     *     statuts: array<string, array<string, int>>,
     *     alertes: array<string, int>,
     *     pasConsolidation: array<int, array<string, mixed>>,
     *     interannualComparison: array<int, array<string, mixed>>,
     *     charts: array<string, mixed>,
     *     details: array{
     *         actions_retard: \Illuminate\Support\Collection<int, \App\Models\Action>,
     *         kpi_sous_seuil: \Illuminate\Support\Collection<int, \App\Models\KpiMesure>,
     *         structure_rapports: \Illuminate\Support\Collection<int, array<string, string>>,
     *         direction_service_report: \Illuminate\Support\Collection<int, array<string, mixed>>
     *     }
     * }
     */
    public function buildPayload(User $user, bool $withDetails = false, bool $withCharts = true): array
    {
        return Cache::remember(
            $this->cacheKey($user, $withDetails, $withCharts),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->buildPayloadFresh($user, $withDetails, $withCharts)
        );
    }

    private function buildPayloadFresh(User $user, bool $withDetails = false, bool $withCharts = true): array
    {
        $today = Carbon::today()->toDateString();
        $pas = $this->buildPasScopedQuery($user);

        $paos = Pao::query();
        $ptas = Pta::query();
        $actions = Action::query();
        $mesures = KpiMesure::query();
        $objectifsOperationnels = PaoObjectifOperationnel::query();

        $this->scopePao($paos, $user);
        $this->scopePta($ptas, $user);
        $this->scopeAction($actions, $user);
        $this->scopeMesure($mesures, $user);
        $this->scopeObjectifOperationnel($objectifsOperationnels, $user);
        $this->applyReportingFilters($paos, $ptas, $actions, $mesures, $objectifsOperationnels, $user);
        $actionsStatistics = (clone $actions);
        $this->scopeActionStatistics($actionsStatistics);

        // A33 — Pre-compte pour eventuellement alerter en logs si le volume
        // d agregats devient excessif (Pas d action sur le metier : on continue,
        // mais on sait qu il faudra refacto en chunk/SQL natif a terme).
        $validatedCount = (clone $actionsStatistics)->count();
        if ($validatedCount > self::AGGREGATE_WARN_THRESHOLD) {
            \Illuminate\Support\Facades\Log::warning('Reporting aggregate volume exceeds safe threshold (A33).', [
                'user_id' => $user->id,
                'validated_actions_count' => $validatedCount,
                'threshold' => self::AGGREGATE_WARN_THRESHOLD,
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 1),
            ]);
        }

        $validatedActions = (clone $actionsStatistics)
            ->with([
                'actionKpi:id,action_id,kpi_delai,kpi_performance,kpi_global',
            ])
            ->get();

        $retardsActions = (clone $actions)
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<', $today)
            ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
            ->count();

        $kpiSousSeuilQuery = KpiMesure::query()
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpis.seuil_alerte')
            ->whereColumn('kpi_mesures.valeur', '<', 'kpis.seuil_alerte');
        $this->scopeJoinedPta($kpiSousSeuilQuery, $user, 'ptas.direction_id', 'ptas.service_id');

        $details = [
            'actions_retard' => collect(),
            'kpi_sous_seuil' => collect(),
            'structure_rapports' => collect(),
            'direction_service_report' => collect(),
            // A25 — Meta-information sur les troncatures appliquees aux details
            // (utilise par les vues / exports pour avertir le lecteur).
            'truncation' => [
                'actions_retard' => ['limit' => 0, 'total' => 0, 'truncated' => false],
                'kpi_sous_seuil' => ['limit' => 0, 'total' => 0, 'truncated' => false],
                'structure_rapports' => ['limit' => 0, 'total' => 0, 'truncated' => false],
            ],
        ];

        if ($withDetails) {
            // A25 — Limites explicites + traces de troncature.
            $lateActionsLimit = self::DETAIL_LIMIT_LATE_ACTIONS;
            $kpiBelowLimit = self::DETAIL_LIMIT_KPI_BELOW_THRESHOLD;
            $structureLimit = self::DETAIL_LIMIT_STRUCTURE;

            $lateActionsQuery = (clone $actions)
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<', $today)
                ->whereNotIn('statut_dynamique', $this->completedActionStatuses());

            $lateActionsTotal = (clone $lateActionsQuery)->count();

            $details['actions_retard'] = $lateActionsQuery
                ->with([
                    'pta:id,titre,direction_id,service_id',
                    'responsable:id,name,email',
                    'actionKpi:id,action_id,kpi_global,kpi_performance',
                ])
                ->orderBy('date_echeance')
                ->limit($lateActionsLimit)
                ->get();

            $details['truncation']['actions_retard'] = [
                'limit' => $lateActionsLimit,
                'total' => $lateActionsTotal,
                'truncated' => $lateActionsTotal > $lateActionsLimit,
            ];

            $kpiBelowTotal = (clone $kpiSousSeuilQuery)->count();

            $kpiIds = (clone $kpiSousSeuilQuery)
                ->select('kpi_mesures.id')
                ->orderByDesc('kpi_mesures.id')
                ->limit($kpiBelowLimit)
                ->pluck('kpi_mesures.id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $details['kpi_sous_seuil'] = KpiMesure::query()
                ->with([
                    'kpi:id,action_id,libelle,seuil_alerte,periodicite',
                    'kpi.action:id,libelle,pta_id',
                    'kpi.action.pta:id,titre,direction_id,service_id',
                ])
                ->whereIn('id', $kpiIds)
                ->orderByDesc('id')
                ->get();

            $details['truncation']['kpi_sous_seuil'] = [
                'limit' => $kpiBelowLimit,
                'total' => $kpiBelowTotal,
                'truncated' => $kpiBelowTotal > $kpiBelowLimit,
            ];

            $structureTotal = (clone $actions)->count();

            $details['structure_rapports'] = (clone $actions)
                ->with([
                    'pta:id,pao_id,titre,direction_id,service_id',
                    'pta.pao:id,titre',
                    'responsable:id,name,email',
                    'kpis:id,action_id,libelle',
                    'actionKpi:id,action_id,kpi_global,kpi_performance',
                ])
                ->orderByDesc('id')
                ->limit($structureLimit)
                ->get()
                ->map(function (Action $action): array {
                    $ressources = [];
                    if ((bool) $action->ressource_main_oeuvre) {
                        $ressources[] = 'Main d oeuvre';
                    }
                    if ((bool) $action->ressource_equipement) {
                        $ressources[] = 'Equipement';
                    }
                    if ((bool) $action->ressource_partenariat) {
                        $ressources[] = 'Partenariat';
                    }
                    if ((bool) $action->ressource_autres) {
                        $details = trim((string) ($action->ressource_autres_details ?? ''));
                        $ressources[] = $details !== '' ? 'Autres: '.$details : 'Autres';
                    }
                    if ((bool) $action->financement_requis) {
                        $source = trim((string) ($action->source_financement ?? ''));
                        $ressources[] = $source !== '' ? 'Financement: '.$source : 'Financement';
                    }

                    $cible = '';
                    if ($action->type_cible === 'quantitative') {
                        $quantite = $action->quantite_cible !== null
                            ? number_format((float) $action->quantite_cible, 0, '.', '')
                            : '';
                        $unite = trim((string) ($action->unite_cible ?? ''));
                        $cible = trim($quantite.' '.$unite);
                    } else {
                        $cible = trim((string) ($action->resultat_attendu ?: $action->livrable_attendu ?: ''));
                    }

                    $indicateurs = $action->kpis
                        ->pluck('libelle')
                        ->filter(fn ($label): bool => trim((string) $label) !== '')
                        ->values()
                        ->implode(' | ');

                    return [
                        // Cf. reportActionRow() : axe stratégique = pasAxe->libelle,
                        // objectif stratégique = pasObjectif->libelle, objectif
                        // opérationnel = ObjectifOperationnel->libelle. Les anciens
                        // fallbacks vers les titres de PAO/PTA causaient l'affichage
                        // de "PTA - SCIQ" en lieu et place du libellé d'OO.
                        'axe_strategique' => (string) ($action->pta?->pao?->pasObjectif?->pasAxe?->libelle ?? '-'),
                        'objectif_strategique' => (string) ($action->pta?->pao?->pasObjectif?->libelle ?? '-'),
                        'objectif_operationnel' => (string) (
                            $action->objectifOperationnel?->libelle
                            ?? $action->pta?->objectifOperationnel?->libelle
                            ?? '-'
                        ),
                        'description_actions_detaillees' => (string) ($action->description ?? $action->libelle ?? ''),
                        'rmo' => (string) ($action->responsable?->name ?? ''),
                        'cible' => (string) $cible,
                        'debut' => optional($action->date_debut)->format('Y-m-d') ?? '',
                        'fin' => optional($action->date_fin)->format('Y-m-d') ?? '',
                        'etat_realisation' => (string) $action->statut_dynamique,
                        'progression' => number_format((float) ($action->progression_reelle ?? 0), 0, '.', '').'%',
                        'kpi_global' => round((float) ($action->actionKpi?->kpi_global ?? 0), 2),
                        'ressources_requises' => implode(' | ', $ressources),
                        'indicateurs_performance' => (string) $indicateurs,
                    ];
                });

            $details['truncation']['structure_rapports'] = [
                'limit' => $structureLimit,
                'total' => $structureTotal,
                'truncated' => $structureTotal > $structureLimit,
            ];

            $details['direction_service_report'] = $this->buildDirectionServiceReport($user);
        }

        $kpiSummary = $this->buildKpiSummary($validatedActions);

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
            'reportFilters' => $this->currentReportingFilters(),
            'statisticalPolicy' => [
                'scope_status' => $this->actionCalculationSettings->statisticalScope(),
                'scope_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
                'route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
            ],
            'officialPolicy' => [
                'threshold_status' => $this->actionCalculationSettings->statisticalScope(),
                'threshold_label' => $this->actionCalculationSettings->statisticalScopeLabel(),
                'scope_summary' => $this->actionCalculationSettings->statisticalScopeSummary(),
                'route_filters' => $this->actionCalculationSettings->statisticalRouteFilters(),
            ],
            'global' => [
                'pas_total' => (clone $pas)->count(),
                'paos_total' => (clone $paos)->count(),
                'ptas_total' => (clone $ptas)->count(),
                'actions_total' => (clone $actions)->count(),
                'actions_validees' => (clone $actionsStatistics)->count(),
                'kpi_mesures_total' => (clone $mesures)->count(),
                'objectifs_operationnels_total' => (clone $objectifsOperationnels)->count(),
            ],
            'kpiSummary' => $kpiSummary,
            'managedKpis' => $this->managedKpiSettings->buildRuntimeMetrics($kpiSummary, [
                'role' => $user->effectiveRoleCode(),
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
            ]),
            'statuts' => [
                'pas' => $this->countByStatus($pas, 'statut'),
                'paos' => $this->countByStatus($paos, 'statut'),
                'ptas' => $this->countByStatus($ptas, 'statut'),
                'actions' => $this->countByStatus($actions, 'statut_dynamique'),
                'actions_validation' => $this->countByStatus($actions, 'statut_validation'),
                'objectifs_operationnels' => $this->countByStatus($objectifsOperationnels, 'statut_realisation'),
            ],
            'alertes' => [
                'actions_en_retard' => $retardsActions,
                'mesures_kpi_sous_seuil' => $kpiSousSeuilQuery->count(),
                'alertes_action_actives' => $this->activeActionAlertLogsCount($user),
            ],
            'pasConsolidation' => $this->buildPasConsolidation($user),
            'interannualComparison' => $this->buildInterannualComparison($user),
            'charts' => $withCharts ? $this->buildChartsPayload($user) : [],
            'details' => $details,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function buildDirectionServiceReport(User $user): Collection
    {
        $servicesQuery = Service::query()
            ->where('actif', true)
            ->with([
                'users' => function ($query): void {
                    $query
                        ->select(['id', 'name', 'role', 'service_id'])
                        ->where('role', User::ROLE_SERVICE)
                        ->where('name', 'not like', '%compléter%')
                        ->where('name', 'not like', '%completer%')
                        ->orderBy('name');
                },
            ]);

        $this->scopeReportingServices($servicesQuery, $user);
        $this->applyServiceReportFilters($servicesQuery, $user);

        $services = $servicesQuery
            ->orderBy('direction_id')
            ->orderBy('code')
            ->get(['id', 'direction_id', 'code', 'libelle', 'actif']);

        if ($services->isEmpty()) {
            return collect();
        }

        $directionIds = $services
            ->pluck('direction_id')
            ->filter()
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $directions = Direction::query()
            ->with([
                'users' => function ($query): void {
                    $query
                        ->select(['id', 'name', 'role', 'direction_id'])
                        ->where('role', User::ROLE_DIRECTION)
                        ->where('name', 'not like', '%compléter%')
                        ->where('name', 'not like', '%completer%')
                        ->orderBy('name');
                },
            ])
            ->whereIn('id', $directionIds)
            ->orderBy('code')
            ->get(['id', 'code', 'libelle', 'actif'])
            ->keyBy('id');

        $serviceIds = $services
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $actionsQuery = Action::query()
            ->with([
                'pta:id,pao_id,objectif_operationnel_id,titre,direction_id,service_id',
                // Charger l'objectif operationnel reel (via le PTA ET via l'action
                // directement) pour que reportActionRow() puisse afficher le bon
                // libelle d'OO et eviter le fallback sur le titre du PTA.
                'pta.objectifOperationnel:id,libelle',
                'objectifOperationnel:id,libelle',
                'pta.pao:id,pas_objectif_id,titre,annee,direction_id,service_id,objectif_operationnel,echeance',
                'pta.pao.pasObjectif:id,pas_axe_id,code,libelle',
                'pta.pao.pasObjectif.pasAxe:id,code,libelle,periode_fin',
                'responsable:id,name,email',
                'responsables:id,name',
                'kpis:id,action_id,libelle,unite,cible,seuil_alerte,periodicite,est_a_renseigner',
                'kpis.mesures:id,kpi_id,periode,valeur,commentaire',
                'actionKpi:id,action_id,kpi_global,kpi_delai,kpi_performance',
                'justificatifs:id,justifiable_type,justifiable_id,categorie,nom_original,description,created_at,ajoute_par',
                'justificatifs.ajoutePar:id,name',
                'actionLogs:id,action_id,niveau,type_evenement,message,details,cible_role,created_at,utilisateur_id',
                'actionLogs.utilisateur:id,name',
            ])
            ->whereHas('pta', function (Builder $ptaQuery) use ($serviceIds): void {
                $ptaQuery->whereIn('service_id', $serviceIds);
            });

        $this->scopeAction($actionsQuery, $user);
        $this->applyActionReportingFilters($actionsQuery, $user);

        $actionsByService = $actionsQuery
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Action $action): int => (int) ($action->pta?->service_id ?? 0));

        return $services
            ->groupBy('direction_id')
            ->map(function (Collection $directionServices, $directionId) use ($directions, $actionsByService): array {
                $direction = $directions->get((int) $directionId);
                $serviceRows = $directionServices
                    ->values()
                    ->map(function (Service $service) use ($actionsByService): array {
                        $serviceActions = $actionsByService
                            ->get((int) $service->id, collect())
                            ->values();

                        return [
                            'id' => (int) $service->id,
                            'code' => (string) ($service->code ?? ''),
                            'libelle' => (string) ($service->libelle ?? ''),
                            'responsable' => (string) ($service->users->first()?->name ?? '-'),
                            'summary' => $this->reportSummary($serviceActions),
                            'actions' => $serviceActions
                                ->map(fn (Action $action): array => $this->reportActionRow($action))
                                ->values()
                                ->all(),
                        ];
                    });

                $directionActions = $serviceRows
                    ->flatMap(fn (array $service): array => (array) ($service['actions'] ?? []))
                    ->values();

                return [
                    'id' => (int) ($direction?->id ?? $directionId),
                    'code' => (string) ($direction?->code ?? ''),
                    'libelle' => (string) ($direction?->libelle ?? 'Direction non renseignee'),
                    'responsable' => (string) ($direction?->users->first()?->name ?? '-'),
                    'summary' => $this->reportSummaryFromRows($directionActions),
                    'services' => $serviceRows->all(),
                ];
            })
            ->values();
    }

    private function scopeReportingServices(Builder $query, User $user): void
    {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $directionIds = array_merge(
            $user->delegatedDirectionIds('planning_read'),
            $user->delegatedDirectionIds('planning_write')
        );
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $directionIds[] = (int) $user->direction_id;
        }
        $directionIds = array_values(array_unique(array_filter($directionIds, static fn ($id): bool => (int) $id > 0)));

        $serviceScopes = array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write')
        );
        if ($user->hasRole(User::ROLE_SERVICE) && $user->direction_id !== null && $user->service_id !== null) {
            $serviceScopes[] = [
                'direction_id' => (int) $user->direction_id,
                'service_id' => (int) $user->service_id,
            ];
        }

        if ($directionIds === [] && $serviceScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scopedQuery) use ($directionIds, $serviceScopes): void {
            foreach ($directionIds as $directionId) {
                $scopedQuery->orWhere('direction_id', (int) $directionId);
            }

            foreach ($serviceScopes as $scope) {
                $scopedQuery->orWhere(function (Builder $serviceQuery) use ($scope): void {
                    $serviceQuery
                        ->where('direction_id', (int) $scope['direction_id'])
                        ->where('id', (int) $scope['service_id']);
                });
            }
        });
    }

    private function applyReportingFilters(
        Builder $paos,
        Builder $ptas,
        Builder $actions,
        Builder $mesures,
        Builder $objectifsOperationnels,
        User $user
    ): void {
        $filters = $this->currentReportingFilters();
        $directionId = (int) ($filters['direction_id'] ?? 0);
        $serviceId = (int) ($filters['service_id'] ?? 0);

        if ($directionId > 0 && $this->canReadDirection($user, $directionId)) {
            $paos->where('direction_id', $directionId);
            $ptas->where('direction_id', $directionId);
            $actions->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
            $mesures->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('direction_id', $directionId));
            $objectifsOperationnels->whereHas('objectifStrategique.paoAxe.pao', fn (Builder $paoQuery) => $paoQuery->where('direction_id', $directionId));
        }

        if ($serviceId > 0) {
            $service = Service::query()->find($serviceId);
            if ($service instanceof Service && $this->canReadService($user, (int) $service->direction_id, (int) $service->id)) {
                $ptas->where('service_id', $serviceId);
                $paos->where(function (Builder $paoQuery) use ($serviceId): void {
                    $paoQuery
                        ->where('service_id', $serviceId)
                        ->orWhereHas('ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId))
                        ->orWhereHas('objectifsOperationnels', fn (Builder $objectiveQuery) => $objectiveQuery->where('service_id', $serviceId));
                });
                $actions->whereHas('pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
                $mesures->whereHas('kpi.action.pta', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', $serviceId));
                $objectifsOperationnels->where('service_id', $serviceId);
            }
        }

        $this->applyActionReportingFilters($actions, $user, $filters);
        $this->applyMesureReportingFilters($mesures, $user, $filters);
    }

    private function applyServiceReportFilters(Builder $servicesQuery, User $user): void
    {
        $filters = $this->currentReportingFilters();
        $directionId = (int) ($filters['direction_id'] ?? 0);
        $serviceId = (int) ($filters['service_id'] ?? 0);

        if ($directionId > 0 && $this->canReadDirection($user, $directionId)) {
            $servicesQuery->where('direction_id', $directionId);
        }

        if ($serviceId > 0) {
            $service = Service::query()->find($serviceId);
            if ($service instanceof Service && $this->canReadService($user, (int) $service->direction_id, (int) $service->id)) {
                $servicesQuery->whereKey($serviceId);
            }
        }
    }

    /**
     * @param array<string, mixed>|null $filters
     */
    private function applyActionReportingFilters(Builder $query, User $user, ?array $filters = null): void
    {
        $filters ??= $this->currentReportingFilters();

        $status = (string) ($filters['statut'] ?? '');
        if ($status !== '') {
            $query->where('statut_dynamique', $status);
        }

        $mode = (string) ($filters['type_action'] ?? '');
        if ($mode !== '') {
            $query->where(function (Builder $modeQuery) use ($mode): void {
                $modeQuery
                    ->where('mode_evaluation', $mode)
                    ->orWhere('type_cible', $mode);
            });
        }

        $responsableId = (int) ($filters['responsable_id'] ?? 0);
        if ($responsableId > 0) {
            $query->where(function (Builder $responsableQuery) use ($responsableId): void {
                $responsableQuery
                    ->where('responsable_id', $responsableId)
                    ->orWhereHas('responsables', fn (Builder $responsables) => $responsables->whereKey($responsableId))
                    ->orWhereHas('sousActions', fn (Builder $sousActions) => $sousActions->where('agent_id', $responsableId));
            });
        }

        $criticality = (string) ($filters['criticite'] ?? '');
        if ($criticality !== '') {
            $this->applyCriticalityFilter($query, $criticality);
        }

        $start = (string) ($filters['periode_debut'] ?? '');
        $end = (string) ($filters['periode_fin'] ?? '');
        if ($start !== '' || $end !== '') {
            $query->where(function (Builder $dateQuery) use ($start, $end): void {
                foreach (['date_debut', 'date_fin', 'date_echeance', 'created_at'] as $column) {
                    $dateQuery->orWhere(function (Builder $columnQuery) use ($column, $start, $end): void {
                        if ($start !== '') {
                            $columnQuery->whereDate($column, '>=', $start);
                        }
                        if ($end !== '') {
                            $columnQuery->whereDate($column, '<=', $end);
                        }
                    });
                }
            });
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyMesureReportingFilters(Builder $query, User $user, array $filters): void
    {
        $actionFilters = array_intersect_key($filters, array_flip([
            'statut',
            'type_action',
            'responsable_id',
            'criticite',
            'periode_debut',
            'periode_fin',
        ]));

        if ($actionFilters === []) {
            return;
        }

        $query->whereHas('kpi.action', function (Builder $actionQuery) use ($user, $actionFilters): void {
            $this->scopeAction($actionQuery, $user);
            $this->applyActionReportingFilters($actionQuery, $user, $actionFilters);
        });
    }

    private function applyCriticalityFilter(Builder $query, string $criticality): void
    {
        $values = match ($criticality) {
            'critique' => ['critique', 'critical', 'haute', 'elevee', 'élevée'],
            'importante' => ['importante', 'important', 'moyenne', 'medium'],
            'normale' => ['normale', 'normal', 'basse', 'faible'],
            default => [$criticality],
        };

        $query->where(function (Builder $criticalityQuery) use ($values): void {
            if (\App\Support\SchemaIntrospectionCache::hasColumn('actions', 'priorite')) {
                $criticalityQuery->orWhereIn('priorite', $values);
            }
            if (\App\Support\SchemaIntrospectionCache::hasColumn('actions', 'niveau_risque')) {
                $criticalityQuery->orWhereIn('niveau_risque', $values);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function currentReportingFilters(): array
    {
        $request = request();
        $filters = [];

        foreach (['direction_id', 'service_id', 'responsable_id'] as $key) {
            $value = (int) $request->integer($key);
            if ($value > 0) {
                $filters[$key] = $value;
            }
        }

        foreach (['statut', 'type_action', 'criticite'] as $key) {
            $value = trim((string) $request->query($key, ''));
            if ($value !== '' && $value !== 'all') {
                $filters[$key] = $value;
            }
        }

        foreach (['periode_debut', 'periode_fin'] as $key) {
            $value = trim((string) $request->query($key, ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, int|float>
     */
    private function reportSummary(Collection $actions): array
    {
        return $this->actionSummaryService->summarize($actions);
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<string, int|float>
     */
    private function reportSummaryFromRows(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [
                'actions_total' => 0,
                'actions_validees' => 0,
                'actions_terminees' => 0,
                'actions_en_cours' => 0,
                'actions_retard' => 0,
                'progression_moyenne' => 0.0,
                'taux_realisation' => 0.0,
                'taux_retard' => 0.0,
                'kpi_global' => 0.0,
                'kpi_conformite' => 0.0,
            ];
        }

        $total = $rows->count();
        $completed = $rows->filter(fn (array $row): bool => (bool) ($row['est_terminee'] ?? false))->count();
        $delayed = $rows->filter(fn (array $row): bool => (bool) ($row['est_en_retard'] ?? false))->count();

        return [
            'actions_total' => $total,
            'actions_validees' => $rows->filter(fn (array $row): bool => (bool) ($row['est_validee'] ?? false))->count(),
            'actions_terminees' => $completed,
            'actions_en_cours' => max(0, $total - $completed - $delayed),
            'actions_retard' => $delayed,
            'progression_moyenne' => round((float) $rows->avg(fn (array $row): float => (float) ($row['progression_value'] ?? 0)), 2),
            'taux_realisation' => $this->completionRate($completed, $total),
            'taux_retard' => $this->completionRate($delayed, $total),
            'kpi_global' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_global_value'] ?? 0)), 2),
            'kpi_conformite' => round((float) $rows->avg(fn (array $row): float => (float) ($row['kpi_conformite_value'] ?? 0)), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportActionRow(Action $action): array
    {
        $progression = (float) ($action->progression_reelle ?? 0);
        $kpiGlobal = (float) ($action->actionKpi?->kpi_global ?? 0);
        $kpiConformite = 0.0;
        $statut = $this->reportActionStatusLabel($action);
        $validationStatus = (string) ($action->statut_validation ?? '');
        $isCompleted = in_array((string) $action->statut_dynamique, $this->completedActionStatuses(), true)
            || $progression >= 100.0;
        $isDelayed = $this->isReportActionDelayed($action);
        $strategicObjective = (string) (
            $action->pta?->pao?->pasObjectif?->libelle
            ?? $action->pta?->pao?->titre
            ?? '-'
        );
        // Le vrai objectif operationnel vient du modele ObjectifOperationnel
        // (rattache a l'action via objectif_operationnel_id, ou au PTA via
        // pta.objectif_operationnel_id). On evite a tout prix de retomber sur
        // $pta->titre qui contient "PTA - <service>" (l'utilisateur le voyait
        // affiche en lieu et place du libelle d'objectif operationnel).
        $operationalObjective = (string) (
            $action->objectifOperationnel?->libelle
            ?? $action->pta?->objectifOperationnel?->libelle
            ?? $action->pta?->pao?->objectif_operationnel
            ?? '-'
        );
        $pasAxe = $action->pta?->pao?->pasObjectif?->pasAxe;
        $pasObjectif = $action->pta?->pao?->pasObjectif;
        $pao = $action->pta?->pao;
        $justificatifs = $this->reportActionJustificatifs($action);
        $financementStatus = $action->financementStatus();
        $responsables = $this->reportActionResponsibleLabel($action);
        $financingLabel = (string) ($action->financement_status_label ?? Action::financingStatusOptions()[$financementStatus] ?? $financementStatus);

        return [
            'axe_id' => (int) ($pasAxe?->id ?? 0),
            'axe_numero' => (string) ($pasAxe?->code ?? ''),
            'axe' => (string) ($pasAxe?->libelle ?? '-'),
            'axe_strategique' => (string) ($pasAxe?->libelle ?? '-'),
            'objectif_strategique_id' => (int) ($pasObjectif?->id ?? 0),
            'objectif_strategique_numero' => (string) ($pasObjectif?->code ?? ''),
            'objectif' => $strategicObjective,
            'objectif_strategique' => $strategicObjective,
            'objectif_operationnel' => $operationalObjective,
            'objectif_operationnel_id' => (int) ($pao?->id ?? 0),
            'echeance_strategique' => $pao?->echeance?->format('Y-m-d') ?? $pasAxe?->periode_fin?->format('Y-m-d') ?? '',
            'echeance' => $action->date_echeance?->format('Y-m-d') ?? $action->date_fin?->format('Y-m-d') ?? '',
            'action' => (string) ($action->libelle ?? '-'),
            'description_action' => trim((string) ($action->description ?? '')) ?: (string) ($action->libelle ?? '-'),
            'responsable' => $responsables,
            'rmo' => $responsables,
            'mode_execution' => (string) $action->mode_evaluation_label,
            'cible' => $this->reportActionTargetLabel($action) ?: '-',
            'kpi' => $this->reportActionKpiLabel($action),
            'prevu' => $this->reportActionPlannedLabel($action),
            'realise' => $this->reportActionActualLabel($action),
            'debut' => $action->date_debut?->format('Y-m-d') ?? '',
            'fin' => $action->date_fin?->format('Y-m-d') ?? '',
            'taux' => number_format($progression, 0, '.', '').'%',
            'statut' => $statut,
            'statut_validation' => (string) ($action->validation_status_label ?? $validationStatus ?: '-'),
            'kpi_global' => number_format($kpiGlobal, 0, '.', ''),
            'kpi_delai' => number_format((float) ($action->actionKpi?->kpi_delai ?? 0), 0, '.', ''),
            'kpi_performance' => number_format((float) ($action->actionKpi?->kpi_performance ?? 0), 0, '.', ''),
            'kpi_conformite' => number_format($kpiConformite, 0, '.', ''),
            'progression_value' => $progression,
            'kpi_global_value' => $kpiGlobal,
            'kpi_delai_value' => (float) ($action->actionKpi?->kpi_delai ?? 0),
            'kpi_performance_value' => (float) ($action->actionKpi?->kpi_performance ?? 0),
            'kpi_conformite_value' => $kpiConformite,
            'ressources_requises' => $this->reportActionResourcesLabel($action),
            'justificatif' => $this->reportActionJustificatifLabel($justificatifs),
            'justificatifs' => $justificatifs,
            'financement_requis' => (bool) $action->financement_requis,
            'financement_nature' => (string) ($action->nature_financement ?: $action->description_financement ?: '-'),
            'financement_montant' => (float) ($action->montant_estime ?? 0),
            'financement_source' => (string) ($action->source_financement ?: '-'),
            'financement_statut' => $financementStatus,
            'financement_statut_label' => $financingLabel,
            'financement_resume' => $this->reportActionFinancingSummary($action, $financingLabel),
            'financement_observation' => (string) ($action->observation_financement ?: $action->commentaire_financement ?: ''),
            'risque_resume' => $this->reportActionRiskSummary($action),
            'anomalies' => $this->reportActionAnomalies($action),
            'kpi_rows' => $this->reportActionKpiRows($action),
            'est_validee' => in_array($validationStatus, [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true),
            'est_terminee' => $isCompleted,
            'est_en_retard' => $isDelayed,
        ];
    }

    private function reportActionResponsibleLabel(Action $action): string
    {
        $names = collect();

        if ($action->relationLoaded('responsables')) {
            $names = $names->merge(
                $action->responsables
                    ->pluck('name')
                    ->filter(fn ($name): bool => trim((string) $name) !== '')
            );
        }

        if ($action->responsable?->name !== null) {
            $names->push((string) $action->responsable->name);
        }

        $label = $names
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->values()
            ->implode(' | ');

        return $label !== '' ? $label : '-';
    }

    private function reportActionFinancingSummary(Action $action, string $statusLabel): string
    {
        if (! (bool) $action->financement_requis) {
            return 'Non requis';
        }

        $parts = [$statusLabel];
        $nature = trim((string) ($action->nature_financement ?: $action->description_financement ?: ''));
        $source = trim((string) ($action->source_financement ?? ''));
        $amount = (float) ($action->montant_estime ?? 0);

        if ($nature !== '') {
            $parts[] = 'Nature: '.$nature;
        }
        if ($amount > 0) {
            $parts[] = 'Montant: '.number_format($amount, 0, '.', ' ');
        }
        if ($source !== '') {
            $parts[] = 'Source: '.$source;
        }

        return implode(' | ', $parts);
    }

    private function reportActionRiskSummary(Action $action): string
    {
        $parts = [];

        foreach ([
            'niveau_risque' => 'Niveau',
            'risque_lie' => 'Risque',
            'risques' => 'Risques',
            'risque_potentiel' => 'Potentiel',
            'mesures_preventives' => 'Mesures preventives',
            'mesures_correctives' => 'Mesures correctives',
        ] as $field => $label) {
            $value = trim((string) ($action->{$field} ?? ''));
            if ($value !== '') {
                $parts[] = $label.': '.$value;
            }
        }

        return $parts !== [] ? implode(' | ', $parts) : 'Non signale';
    }

    private function reportActionKpiLabel(Action $action): string
    {
        $labels = $action->kpis
            ->pluck('libelle')
            ->filter(fn ($label): bool => trim((string) $label) !== '')
            ->map(fn ($label): string => $this->indicatorLabel((string) $label))
            ->values()
            ->implode(' | ');

        if ($labels !== '') {
            return $labels;
        }

        return 'Indicateur global: '.number_format((float) ($action->actionKpi?->kpi_global ?? 0), 0, '.', '');
    }

    private function reportActionPlannedLabel(Action $action): string
    {
        $parts = [];
        $target = $this->reportActionTargetLabel($action);
        if ($target !== '') {
            $parts[] = 'Cible: '.$target;
        }

        $period = trim(($action->date_debut?->format('Y-m-d') ?? '').' - '.($action->date_fin?->format('Y-m-d') ?? ''), ' -');
        if ($period !== '') {
            $parts[] = 'Periode: '.$period;
        }

        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    private function reportActionActualLabel(Action $action): string
    {
        $parts = ['Progression: '.number_format((float) ($action->progression_reelle ?? 0), 0, '.', '').'%'];

        if ($action->date_fin_reelle !== null) {
            $parts[] = 'Fin reelle: '.$action->date_fin_reelle->format('Y-m-d');
        }

        $rapportFinal = trim((string) ($action->rapport_final ?? ''));
        if ($rapportFinal !== '') {
            $parts[] = 'Rapport: '.Str::limit($rapportFinal, 80, '');
        }

        return implode(' | ', $parts);
    }

    private function reportActionTargetLabel(Action $action): string
    {
        if ($action->type_cible === 'quantitative') {
            $quantite = $action->quantite_cible !== null
                ? number_format((float) $action->quantite_cible, 0, '.', '')
                : '';
            $unite = trim((string) ($action->unite_cible ?? ''));

            return trim($quantite.' '.$unite);
        }

        return trim((string) ($action->resultat_attendu ?: $action->livrable_attendu ?: ''));
    }

    private function reportActionStatusLabel(Action $action): string
    {
        $progression = (float) ($action->progression_reelle ?? 0);
        $status = (string) ($action->statut_dynamique ?: $action->statut ?: '');

        if (in_array($status, [
            ActionTrackingService::STATUS_SUSPENDU,
            ActionTrackingService::STATUS_ANNULE,
        ], true)) {
            return (string) ($action->status_label ?: $status);
        }

        if ($progression >= 100.0) {
            return 'Terminee';
        }

        if ($this->isReportActionDelayed($action)) {
            return 'En retard';
        }

        if ($progression <= 0.0) {
            return 'Non demarree';
        }

        return 'En cours';
    }

    private function isReportActionDelayed(Action $action): bool
    {
        if ((string) $action->statut_dynamique === ActionTrackingService::STATUS_EN_RETARD) {
            return true;
        }

        if ($action->date_fin === null || (float) ($action->progression_reelle ?? 0) >= 100.0) {
            return false;
        }

        return $action->date_fin->lt(Carbon::today());
    }

    private function reportActionResourcesLabel(Action $action): string
    {
        $resources = [];
        if ((bool) $action->ressource_main_oeuvre) {
            $resources[] = 'Main d oeuvre';
        }
        if ((bool) $action->ressource_equipement) {
            $resources[] = 'Equipement';
        }
        if ((bool) $action->ressource_partenariat) {
            $resources[] = 'Partenariat';
        }
        if ((bool) $action->ressource_autres) {
            $details = trim((string) ($action->ressource_autres_details ?? ''));
            $resources[] = $details !== '' ? 'Autres: '.$details : 'Autres';
        }
        if ((bool) $action->financement_requis) {
            $source = trim((string) ($action->source_financement ?? ''));
            $resources[] = $source !== '' ? 'Financement: '.$source : 'Financement';
        }

        return $resources !== [] ? implode(' | ', $resources) : '-';
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function reportActionJustificatifs(Action $action): array
    {
        return $action->justificatifs
            ->sortByDesc('created_at')
            ->map(fn ($justificatif): array => [
                'nom' => (string) ($justificatif->nom_original ?? '-'),
                'categorie' => (string) ($justificatif->categorie ?? 'justificatif'),
                'description' => (string) ($justificatif->description ?? ''),
                'ajoute_par' => (string) ($justificatif->ajoutePar?->name ?? '-'),
                'date' => $justificatif->created_at?->format('Y-m-d') ?? '',
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, string>> $justificatifs
     */
    private function reportActionJustificatifLabel(array $justificatifs): string
    {
        if ($justificatifs === []) {
            return '-';
        }

        return collect($justificatifs)
            ->take(3)
            ->map(fn (array $justificatif): string => trim(($justificatif['nom'] ?? '-').' '.(($justificatif['date'] ?? '') !== '' ? '('.$justificatif['date'].')' : '')))
            ->implode(' | ');
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function reportActionAnomalies(Action $action): array
    {
        return $action->actionLogs
            ->filter(function (ActionLog $log): bool {
                $details = is_array($log->details) ? $log->details : [];
                $level = (string) $log->niveau;
                $isVisibleAlert = in_array($level, ['warning', 'critical', 'urgence'], true)
                    || ($level === 'info' && ($details['manual'] ?? false) === true);

                return $isVisibleAlert
                    && (($details['resolved'] ?? false) !== true)
                    && (str_starts_with((string) $log->type_evenement, 'anomalie_')
                        || in_array((string) $log->type_evenement, ['progression_sous_seuil', 'kpi_global_sous_seuil'], true));
            })
            ->sortByDesc('created_at')
            ->map(function (ActionLog $log): array {
                $details = is_array($log->details) ? $log->details : [];

                return [
                    'type' => (string) $log->type_evenement,
                    'niveau' => (string) $log->niveau,
                    'message' => (string) $log->message,
                    'responsable' => (string) ($log->cible_role ?: '-'),
                    'correction_attendue' => (string) ($details['correction_attendue'] ?? ''),
                    'blocage' => (string) ($details['blocked_scope'] ?? ''),
                    'signale_par' => (string) ($log->utilisateur?->name ?? '-'),
                    'date' => $log->created_at?->format('Y-m-d H:i') ?? '',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reportActionKpiRows(Action $action): array
    {
        return $action->kpis
            ->map(function ($kpi) use ($action): array {
                $measure = $kpi->mesures
                    ->sortByDesc('id')
                    ->first();
                $value = $measure?->valeur !== null ? (float) $measure->valeur : null;
                $threshold = $kpi->seuil_alerte !== null ? (float) $kpi->seuil_alerte : null;

                return [
                    'action' => (string) ($action->libelle ?? '-'),
                    'indicateur' => $this->indicatorLabel((string) ($kpi->libelle ?? '-')),
                    'type' => (string) ($kpi->periodicite ?? ($kpi->est_a_renseigner ? 'A renseigner' : 'Suivi')),
                    'periode' => (string) ($measure?->periode ?? '-'),
                    'valeur' => $value,
                    'seuil' => $threshold,
                    'statut' => $this->reportKpiStatus($value, $threshold),
                ];
            })
            ->values()
            ->all();
    }

    private function reportKpiStatus(?float $value, ?float $threshold): string
    {
        if ($value === null || $threshold === null) {
            return 'Non renseigne';
        }

        return $value < $threshold ? 'Alerte' : 'OK';
    }

    private function indicatorLabel(string $label): string
    {
        $label = trim(str_ireplace('KPI', 'Indicateur', $label));

        return $label !== '' ? $label : '-';
    }

    private function cacheKey(User $user, bool $withDetails, bool $withCharts): string
    {
        return 'reporting-analytics:'.sha1(json_encode([
            'user_id' => (int) $user->id,
            'role' => (string) $user->role,
            'direction_id' => $user->direction_id !== null ? (int) $user->direction_id : null,
            'service_id' => $user->service_id !== null ? (int) $user->service_id : null,
            'with_details' => $withDetails,
            'with_charts' => $withCharts,
            'version' => $this->cacheVersionService->reportingVersion(),
            'alert_version' => (int) Cache::get('alert-center:version', 1),
            'statistical_scope' => $this->actionCalculationSettings->statisticalScope(),
            'exercice' => $this->exerciceContext->selectedYear(),
            'trimestre' => $this->exerciceContext->selectedQuarter(),
            'filters' => $this->currentReportingFilters(),
        ], JSON_THROW_ON_ERROR));
    }

    private function activeActionAlertLogsCount(User $user): int
    {
        return ActionLog::query()
            ->activeAlert()
            ->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
            })
            ->count();
    }

    private function scopePao(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id');
        $this->exerciceContext->applyToPao($query);
    }

    private function scopePta(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
        $this->exerciceContext->applyToPta($query);
    }

    private function scopeAction(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToAction($query);

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

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeActionStatistics(Builder $query, string $column = 'statut_validation'): void
    {
        $this->actionCalculationSettings->applyOfficialScope($query, $column);
    }

    private function scopeMesure(Builder|Relation $query, User $user): void
    {
        $this->exerciceContext->applyToMesure($query);

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

        $query->whereHas('kpi.action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeObjectifOperationnel(Builder $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('objectifStrategique.paoAxe.pao', function (Builder $subQuery) use ($user): void {
                $subQuery->where('direction_id', (int) $user->direction_id);
            });

            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $query->whereHas('objectifStrategique.paoAxe.pao.ptas', function (Builder $subQuery) use ($user): void {
                $subQuery->where('service_id', (int) $user->service_id);
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function scopeJoinedPta(Builder $query, User $user, string $directionColumn, string $serviceColumn): void
    {
        $this->exerciceContext->applyToJoinedPta($query);
        $this->scopeByUserDirection($query, $user, $directionColumn, $serviceColumn);
    }

    private function buildPasScopedQuery(User $user): Builder
    {
        $query = Pas::query();
        $this->scopePasByUser($query, $user);
        $this->exerciceContext->applyToPas($query);

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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
                $validatedActions = $this->validatedActions($actions);
                $actionsTotal = $actions->count();
                $actionsValidees = $validatedActions->count();
                $actionsRetard = $actions
                    ->where('statut_dynamique', ActionTrackingService::STATUS_EN_RETARD)
                    ->count();

                return [
                    'annee' => (int) $annee,
                    'url' => route('workspace.pao.index', ['annee' => (int) $annee]),
                    'paos_total' => $rows->count(),
                    'ptas_total' => $ptas->count(),
                    'actions_total' => $actionsTotal,
                    'actions_validees' => $actionsValidees,
                    'actions_retard' => $actionsRetard,
                    'progression_moyenne' => $actionsValidees > 0
                        ? round((float) $validatedActions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2)
                        : 0.0,
                    'taux_validation' => $this->completionRate($actionsValidees, $actionsTotal),
                ];
            })
            ->sortBy('annee')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPasConsolidation(User $user): array
    {
        $pasRows = $this->buildPasScopedQuery($user)
            ->with(['axes.objectifs'])
            ->orderByDesc('periode_fin')
            ->get();

        if ($pasRows->isEmpty()) {
            return [];
        }

        $paoQuery = Pao::query()
            ->whereIn('pas_id', $pasRows->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->scopePao($paoQuery, $user);

        $directionScopeIds = $this->scopedDirectionIds($user);

        $paos = $paoQuery
            ->with([
                'direction:id,code,libelle',
                'pasObjectif:id,pas_axe_id,code,libelle,ordre',
                'ptas' => function ($query) use ($user): void {
                    $this->scopePta($query, $user);
                    $query->with([
                        'service:id,code,libelle',
                        'actions' => function ($actionQuery) use ($user): void {
                            $this->scopeAction($actionQuery, $user);
                        },
                    ]);
                },
            ])
            ->get();

        $paosByPas = $paos->groupBy('pas_id');
        $paosByObjectif = $paos->groupBy('pas_objectif_id');

        return $pasRows->map(function (Pas $pas) use ($paosByObjectif, $paosByPas, $directionScopeIds): array {
            $paos = $paosByPas->get((int) $pas->id, collect());
            $ptas = $paos->flatMap->ptas;
            $actions = $ptas->flatMap->actions;
            $validatedActions = $this->validatedActions($actions);
            $actionsTotal = $actions->count();
            $actionsValidees = $validatedActions->count();

            $axes = $pas->axes->map(function ($axe) use ($directionScopeIds, $paosByObjectif): array {
                $objectifs = $axe->objectifs->map(function ($objectif) use ($directionScopeIds, $paosByObjectif): array {
                    $objectifPaos = $paosByObjectif->get((int) $objectif->id, collect());
                    $objectifActions = $objectifPaos->flatMap->ptas->flatMap->actions;
                    $validatedObjectifActions = $this->validatedActions($objectifActions);
                    $objectifActionsTotal = $objectifActions->count();
                    $objectifActionsValidees = $validatedObjectifActions->count();
                    $coveredDirectionIds = $objectifPaos
                        ->pluck('direction_id')
                        ->filter()
                        ->map(static fn ($value): int => (int) $value)
                        ->unique()
                        ->values()
                        ->all();
                    $missingDirectionIds = array_values(array_diff($directionScopeIds, $coveredDirectionIds));
                    $missingDirections = Direction::query()
                        ->whereIn('id', $missingDirectionIds)
                        ->orderBy('code')
                        ->get(['id', 'code', 'libelle'])
                        ->map(fn (Direction $direction): string => (string) ($direction->code.' - '.$direction->libelle))
                        ->all();

                    return [
                        'code' => (string) ($objectif->code ?: ''),
                        'libelle' => (string) ($objectif->libelle ?: ''),
                        'paos_total' => $objectifPaos->count(),
                        'directions_couvertes' => count($coveredDirectionIds),
                        'directions_attendues' => count($directionScopeIds),
                        'directions_manquantes' => $missingDirections,
                        'actions_total' => $objectifActionsTotal,
                        'actions_validees' => $objectifActionsValidees,
                        'taux_realisation' => $this->completionRate($objectifActionsValidees, $objectifActionsTotal),
                    ];
                })->values()->all();

                $axePaos = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['paos_total']);
                $axeActionsTotal = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['actions_total']);
                $axeActionsValidees = collect($objectifs)->sum(fn (array $objectif): int => (int) $objectif['actions_validees']);

                return [
                    'code' => (string) ($axe->code ?: ''),
                    'libelle' => (string) ($axe->libelle ?: ''),
                    'objectifs_total' => $axe->objectifs->count(),
                    'paos_total' => $axePaos,
                    'actions_total' => $axeActionsTotal,
                    'actions_validees' => $axeActionsValidees,
                    'taux_realisation' => $this->completionRate($axeActionsValidees, $axeActionsTotal),
                    'objectifs' => $objectifs,
                ];
            })->values()->all();

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
                'taux_realisation' => $this->completionRate($actionsValidees, $actionsTotal),
                'axes' => $axes,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChartsPayload(User $user): array
    {
        $today = Carbon::today();
        $todayDate = $today->toDateString();
        $completedStatuses = $this->completedActionStatuses();

        $pasCount = $this->buildPasScopedQuery($user)->count();

        $paoQuery = Pao::query();
        $ptaQuery = Pta::query();
        $actionQuery = Action::query();
        $this->scopePao($paoQuery, $user);
        $this->scopePta($ptaQuery, $user);
        $this->scopeAction($actionQuery, $user);

        $funnel = [
            'labels' => ['PAS', 'PAO', 'PTA', 'Actions'],
            'values' => [
                (int) $pasCount,
                (int) (clone $paoQuery)->count(),
                (int) (clone $ptaQuery)->count(),
                (int) (clone $actionQuery)->count(),
            ],
            'urls' => [
                route('workspace.pas.index'),
                route('workspace.pao.index'),
                route('workspace.pta.index'),
                route('workspace.actions.index'),
            ],
        ];

        $unitLabel = 'Direction';
        $unitColumn = 'ptas.direction_id';
        $unitNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();

        if (! $user->hasGlobalReadAccess() && $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            $unitLabel = 'Service';
            $unitColumn = 'ptas.service_id';
            $serviceQuery = Service::query();
            if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
                $serviceQuery->where('direction_id', (int) $user->direction_id);
            }
            if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
                $serviceQuery->where('id', (int) $user->service_id);
            }
            $unitNames = $serviceQuery
                ->pluck('libelle', 'id')
                ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
                ->toArray();
        }
        $unitFilterKey = $unitColumn === 'ptas.service_id' ? 'service_id' : 'direction_id';

        $unitColumn = SafeSql::identifier($unitColumn, [
            'ptas.direction_id',
            'ptas.service_id',
        ]);

        // A41 — Aligne le graphe reporting sur les 9 buckets dashboardStatus()
        // (cf. ActionStatusService) : inclut 'a_parametrer' (via statut_parametrage,
        // prioritaire) et fond les 11 valeurs de statut_dynamique dans le meme
        // vocabulaire que le dashboard. Corrige les statuts manquants/illisibles
        // signales sur les graphiques (notamment l'absence de "A parametrer").
        $bucketExpr = "CASE "
            ."WHEN actions.statut_parametrage = 'a_parametrer' THEN 'a_parametrer' "
            ."WHEN actions.statut_dynamique IN ('acheve_dans_delai','acheve_hors_delai','cloturee') THEN 'acheve' "
            ."WHEN actions.statut_dynamique = 'en_retard' THEN 'en_retard' "
            ."WHEN actions.statut_dynamique = 'a_risque' THEN 'a_risque' "
            ."WHEN actions.statut_dynamique = 'en_avance' THEN 'en_avance' "
            ."WHEN actions.statut_dynamique = 'suspendu' THEN 'suspendu' "
            ."WHEN actions.statut_dynamique = 'annule' THEN 'annule' "
            ."WHEN actions.statut_dynamique = 'non_demarre' THEN 'non_demarre' "
            ."ELSE 'en_cours' END";

        // NB: l'alias est `status_bucket` (et non `status_label`) car le modele
        // Action expose un accessor `status_label` (cf. $appends) qui ecraserait
        // la valeur SQL brute lors de l'hydratation Eloquent -> buckets vides.
        $statusRows = Action::query()
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->selectRaw("{$unitColumn} as unit_id, {$bucketExpr} as status_bucket, COUNT(*) as total")
            ->groupByRaw("{$unitColumn}, {$bucketExpr}");
        $this->scopeJoinedPta($statusRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $statusMatrix = [];
        $statusTotals = [];
        $unitTotals = [];
        foreach ($statusRows->get() as $row) {
            $unitId = (int) ($row->unit_id ?? 0);
            if ($unitId <= 0) {
                continue;
            }
            $status = trim((string) ($row->status_bucket ?? 'inconnu'));
            if ($status === '') {
                $status = 'inconnu';
            }
            $total = (int) ($row->total ?? 0);
            if ($total <= 0) {
                continue;
            }
            $statusMatrix[$status][$unitId] = $total;
            $statusTotals[$status] = ($statusTotals[$status] ?? 0) + $total;
            $unitTotals[$unitId] = ($unitTotals[$unitId] ?? 0) + $total;
        }
        arsort($unitTotals);
        arsort($statusTotals);

        $unitIds = array_keys($unitTotals);
        if ($unitIds === [] && $user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $unitIds = [(int) $user->service_id];
        }
        if ($unitIds === [] && $user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null && $unitColumn === 'ptas.direction_id') {
            $unitIds = [(int) $user->direction_id];
        }

        $statusLabels = array_map(fn (int $id): string => $unitNames[$id] ?? ('#'.$id), $unitIds);

        // A41 — Ordre, libelles et filtres alignes sur les buckets dashboardStatus().
        // Plus de plafond a 6 : on affiche tous les buckets presents (max 9), dans
        // l'ordre canonique du dashboard, avec des libelles lisibles.
        $statusOrder = ['a_parametrer', 'en_avance', 'en_cours', 'a_risque', 'en_retard', 'suspendu', 'annule', 'non_demarre', 'acheve'];
        $statusLabelMap = [
            'a_parametrer' => 'À paramétrer',
            'en_avance' => 'En avance',
            'en_cours' => 'En cours',
            'a_risque' => 'À surveiller',
            'en_retard' => 'En retard',
            'suspendu' => 'Suspendu',
            'annule' => 'Annulé',
            'non_demarre' => 'Non démarré',
            'acheve' => 'Achevé',
        ];
        // Le bucket 'acheve' se filtre via 'achevees' cote index actions (cf.
        // ActionWebController::applyStatusFilter) ; les autres correspondent
        // directement a la valeur de statut_dynamique / statut_parametrage.
        $statusFilterMap = ['acheve' => 'achevees'];

        $statusNames = array_values(array_filter(
            $statusOrder,
            fn (string $status): bool => array_key_exists($status, $statusTotals)
        ));
        $statusDatasets = [];
        $statusUrls = [];
        foreach ($statusNames as $statusName) {
            $statusDatasets[] = [
                'label' => $statusLabelMap[$statusName] ?? $statusName,
                'data' => array_map(fn (int $unitId): int => (int) ($statusMatrix[$statusName][$unitId] ?? 0), $unitIds),
            ];
            $statusUrls[] = array_map(
                fn (int $unitId): string => $this->actionIndexRoute([
                    $unitFilterKey => $unitId,
                    'statut' => $statusFilterMap[$statusName] ?? $statusName,
                ]),
                $unitIds
            );
        }

        $progressRows = ActionWeek::query()
            ->select(['action_weeks.date_debut', 'action_weeks.progression_reelle', 'action_weeks.progression_theorique'])
            ->join('actions', 'actions.id', '=', 'action_weeks.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('action_weeks.date_debut')
            ->orderBy('action_weeks.date_debut');
        if ($progressRows instanceof Builder) {
            $this->scopeActionStatistics($progressRows, 'actions.statut_validation');
            $this->scopeJoinedPta($progressRows, $user, 'ptas.direction_id', 'ptas.service_id');
        }

        $progressBuckets = [];
        foreach ($progressRows->get() as $row) {
            $weekStart = Carbon::parse((string) $row->date_debut)->startOfWeek(Carbon::MONDAY)->toDateString();
            if (! isset($progressBuckets[$weekStart])) {
                $progressBuckets[$weekStart] = ['sum_reel' => 0.0, 'sum_theorique' => 0.0, 'count' => 0];
            }
            $progressBuckets[$weekStart]['sum_reel'] += (float) ($row->progression_reelle ?? 0);
            $progressBuckets[$weekStart]['sum_theorique'] += (float) ($row->progression_theorique ?? 0);
            $progressBuckets[$weekStart]['count']++;
        }
        ksort($progressBuckets);
        $progressBuckets = array_slice($progressBuckets, -12, 12, true);
        $progressLabels = [];
        $progressReel = [];
        $progressTheorique = [];
        $progressUrls = [];
        foreach ($progressBuckets as $weekStart => $bucket) {
            $date = Carbon::parse($weekStart);
            $count = max(1, (int) $bucket['count']);
            $progressLabels[] = 'S'.$date->isoWeek.' '.$date->year;
            $progressReel[] = round((float) $bucket['sum_reel'] / $count, 2);
            $progressTheorique[] = round((float) $bucket['sum_theorique'] / $count, 2);
            $progressUrls[] = $this->officialActionIndexRoute([
                'week_start' => $weekStart,
            ]);
        }

        $trendRows = KpiMesure::query()
            ->select(['kpi_mesures.periode', 'kpi_mesures.valeur', 'kpis.cible', 'kpis.seuil_alerte'])
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('kpi_mesures.periode')
            ->orderBy('kpi_mesures.id');
        $this->scopeActionStatistics($trendRows, 'actions.statut_validation');
        $this->scopeJoinedPta($trendRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $periodBuckets = [];
        foreach ($trendRows->get() as $row) {
            $period = trim((string) ($row->periode ?? ''));
            if ($period === '') {
                continue;
            }
            if (! isset($periodBuckets[$period])) {
                $periodBuckets[$period] = [
                    'sum_valeur' => 0.0,
                    'count_valeur' => 0,
                    'sum_cible' => 0.0,
                    'count_cible' => 0,
                    'sum_seuil' => 0.0,
                    'count_seuil' => 0,
                ];
            }
            $periodBuckets[$period]['sum_valeur'] += (float) ($row->valeur ?? 0);
            $periodBuckets[$period]['count_valeur']++;
            if ($row->cible !== null) {
                $periodBuckets[$period]['sum_cible'] += (float) $row->cible;
                $periodBuckets[$period]['count_cible']++;
            }
            if ($row->seuil_alerte !== null) {
                $periodBuckets[$period]['sum_seuil'] += (float) $row->seuil_alerte;
                $periodBuckets[$period]['count_seuil']++;
            }
        }

        $periodSortValue = static function (string $period): int {
            if (preg_match('/^(\\d{4})-(\\d{2})$/', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + (int) $matches[2];
            }
            if (preg_match('/^(\\d{4})-T([1-4])$/i', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + ((int) $matches[2]) * 3;
            }
            if (preg_match('/^(\\d{4})-S([1-2])$/i', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100 + ((int) $matches[2]) * 6;
            }
            if (preg_match('/^(\\d{4})$/', $period, $matches) === 1) {
                return ((int) $matches[1]) * 100;
            }

            return 0;
        };

        uksort($periodBuckets, function (string $left, string $right) use ($periodSortValue): int {
            $leftSort = $periodSortValue($left);
            $rightSort = $periodSortValue($right);
            if ($leftSort === $rightSort) {
                return strcmp($left, $right);
            }

            return $leftSort <=> $rightSort;
        });

        $periodBuckets = array_slice($periodBuckets, -12, 12, true);
        $trendLabels = [];
        $trendValues = [];
        $trendTargets = [];
        $trendThresholds = [];
        $trendUrls = [];
        foreach ($periodBuckets as $period => $bucket) {
            $trendLabels[] = $period;
            $trendValues[] = round($bucket['count_valeur'] > 0 ? ((float) $bucket['sum_valeur'] / (int) $bucket['count_valeur']) : 0, 2);
            $trendTargets[] = round($bucket['count_cible'] > 0 ? ((float) $bucket['sum_cible'] / (int) $bucket['count_cible']) : 0, 2);
            $trendThresholds[] = round($bucket['count_seuil'] > 0 ? ((float) $bucket['sum_seuil'] / (int) $bucket['count_seuil']) : 0, 2);
            $trendUrls[] = preg_match('/^(\d{4})/', $period, $matches) === 1
                ? $this->officialActionIndexRoute([
                    'annee' => (int) $matches[1],
                ])
                : $this->officialActionIndexRoute();
        }

        $weekStarts = [];
        for ($i = 7; $i >= 0; $i--) {
            $weekStarts[] = (clone $today)->startOfWeek(Carbon::MONDAY)->subWeeks($i);
        }
        $weekKeys = array_map(fn (Carbon $date): string => $date->toDateString(), $weekStarts);
        $weekLabels = array_map(fn (Carbon $date): string => 'S'.$date->isoWeek, $weekStarts);

        $heatRows = Action::query()
            ->select(['actions.date_echeance', 'ptas.direction_id'])
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->whereNotNull('actions.date_echeance')
            ->whereDate('actions.date_echeance', '<', $todayDate)
            ->whereDate('actions.date_echeance', '>=', $weekStarts[0]->toDateString())
            ->whereNotIn('actions.statut_dynamique', $completedStatuses);
        $this->scopeJoinedPta($heatRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $heatMatrixByDirection = [];
        $heatDirectionTotals = [];
        foreach ($heatRows->get() as $row) {
            $directionId = (int) ($row->direction_id ?? 0);
            if ($directionId <= 0 || $row->date_echeance === null) {
                continue;
            }
            $key = Carbon::parse((string) $row->date_echeance)->startOfWeek(Carbon::MONDAY)->toDateString();
            if (! in_array($key, $weekKeys, true)) {
                continue;
            }
            $heatMatrixByDirection[$directionId][$key] = (int) (($heatMatrixByDirection[$directionId][$key] ?? 0) + 1);
            $heatDirectionTotals[$directionId] = (int) (($heatDirectionTotals[$directionId] ?? 0) + 1);
        }
        arsort($heatDirectionTotals);
        $heatDirectionIds = array_slice(array_keys($heatDirectionTotals), 0, 8);
        if ($heatDirectionIds === [] && $user->direction_id !== null) {
            $heatDirectionIds = [(int) $user->direction_id];
        }
        $heatDirectionNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();
        $heatUnits = array_map(fn (int $directionId): string => $heatDirectionNames[$directionId] ?? ('#'.$directionId), $heatDirectionIds);
        $heatMatrix = [];
        $heatUrls = [];
        $heatMax = 0;
        foreach ($heatDirectionIds as $directionId) {
            $rowValues = [];
            $rowUrls = [];
            foreach ($weekKeys as $weekKey) {
                $value = (int) ($heatMatrixByDirection[$directionId][$weekKey] ?? 0);
                $rowValues[] = $value;
                $rowUrls[] = $value > 0
                    ? $this->actionIndexRoute([
                        'direction_id' => $directionId,
                        'statut' => ActionTrackingService::STATUS_EN_RETARD,
                        'week_start' => $weekKey,
                    ])
                    : '';
                $heatMax = max($heatMax, $value);
            }
            $heatMatrix[] = $rowValues;
            $heatUrls[] = $rowUrls;
        }

        $actionCandidates = (clone $actionQuery)
            ->with([
                'pta:id,pao_id,titre,direction_id,service_id',
                'pta.pao:id,titre',
                'pta.direction:id,code,libelle',
                'pta.service:id,code,libelle',
                'responsable:id,name,email',
                'actionKpi:id,action_id,kpi_global',
            ])
            ->orderByDesc('id')
            ->limit(350)
            ->get();

        $scoredActions = $actionCandidates
            ->map(function (Action $action) use ($today): array {
                $start = $action->date_debut instanceof Carbon
                    ? $action->date_debut->copy()
                    : ($action->created_at instanceof Carbon ? $action->created_at->copy() : $today->copy()->subWeek());
                $end = $action->date_fin instanceof Carbon
                    ? $action->date_fin->copy()
                    : ($action->date_echeance instanceof Carbon ? $action->date_echeance->copy() : $start->copy()->addWeeks(2));
                if ($end->lt($start)) {
                    $end = $start->copy()->addDay();
                }

                return [
                    'action' => $action,
                    'label' => (string) $action->libelle,
                    'start' => $start,
                    'end' => $end,
                    'progress' => round(max(0, min(100, (float) ($action->progression_reelle ?? 0))), 2),
                    'status' => (string) ($action->statut_dynamique ?? 'non_demarre'),
                    'score' => round($this->actionTrackingService->computeActionDelayPriorityScore($action, $today), 2),
                ];
            })
            ->sortByDesc('score')
            ->values();

        $criticalItems = $scoredActions->take(10)->values();
        $ganttMin = $criticalItems->isNotEmpty() ? $criticalItems->min(fn (array $item): int => $item['start']->getTimestamp()) : $today->copy()->subDays(14)->getTimestamp();
        $ganttMax = $criticalItems->isNotEmpty() ? $criticalItems->max(fn (array $item): int => $item['end']->getTimestamp()) : $today->copy()->addDays(14)->getTimestamp();
        if ($ganttMin === $ganttMax) {
            $ganttMax += 86400;
        }

        $criticalGantt = [
            'min' => Carbon::createFromTimestamp($ganttMin)->toDateString(),
            'max' => Carbon::createFromTimestamp($ganttMax)->toDateString(),
            'items' => $criticalItems
                ->map(fn (array $item): array => [
                    'label' => Str::limit($item['label'], 58),
                    'start' => $item['start']->toDateString(),
                    'end' => $item['end']->toDateString(),
                    'progress' => (float) $item['progress'],
                    'status' => (string) $item['status'],
                    'score' => (float) $item['score'],
                    'url' => route('workspace.actions.suivi', $item['action']),
                ])
                ->all(),
        ];

        $resourceGroups = [];
        foreach ($actionCandidates as $action) {
            $groupLabel = trim((string) ($action->pta?->pao?->titre ?? $action->pta?->titre ?? 'Sans axe'));
            if ($groupLabel === '') {
                $groupLabel = 'Sans axe';
            }
            $resourceGroupKey = $action->pta?->pao?->id !== null
                ? 'pao:'.(int) $action->pta->pao->id
                : 'pta:'.(int) $action->pta_id;
            $weight = 0.0;
            $weight += (bool) $action->ressource_main_oeuvre ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_equipement ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_partenariat ? 1.0 : 0.0;
            $weight += (bool) $action->ressource_autres ? 1.0 : 0.0;
            $weight += (bool) $action->financement_requis ? 2.0 : 0.0;
            $montant = (float) ($action->montant_estime ?? 0);
            if ($montant > 0) {
                $weight += min(10.0, $montant / 1000000);
            }
            if ($weight <= 0) {
                $weight = 0.5;
            }
            if (! isset($resourceGroups[$resourceGroupKey])) {
                $resourceGroups[$resourceGroupKey] = [
                    'label' => $groupLabel,
                    'weight' => 0.0,
                    'url' => $action->pta?->pao?->id !== null
                        ? route('workspace.pta.index', ['pao_id' => (int) $action->pta->pao->id])
                        : $this->actionIndexRoute(['pta_id' => (int) $action->pta_id]),
                ];
            }
            $resourceGroups[$resourceGroupKey]['weight'] += $weight;
        }
        uasort($resourceGroups, static fn (array $left, array $right): int => $right['weight'] <=> $left['weight']);
        $resourceGroups = array_slice($resourceGroups, 0, 12, true);
        $resourceLabels = array_map(fn (array $row): string => (string) $row['label'], array_values($resourceGroups));
        $resourceValues = array_map(fn (array $row): float => round((float) $row['weight'], 2), array_values($resourceGroups));
        $resourceUrls = array_map(fn (array $row): string => (string) $row['url'], array_values($resourceGroups));

        $performanceGaugeMeta = $this->resolvePerformanceGaugeMeta($user);
        $performanceRows = $this->buildPerformanceGaugeRows($user, $actionCandidates);
        $performanceLabels = array_map(
            static fn (array $row): string => (string) ($row['label'] ?? '-'),
            $performanceRows
        );
        $performanceValues = array_map(
            static fn (array $row): float => (float) ($row['kpi_global'] ?? 0),
            $performanceRows
        );
        $performanceUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $performanceRows
        );

        $interannualRows = $this->buildInterannualComparison($user);

        return [
            'funnel' => $funnel,
            'status_by_unit' => [
                'unit_label' => $unitLabel,
                'labels' => $statusLabels,
                'datasets' => $statusDatasets,
                'urls' => $statusUrls,
            ],
            'progress_weekly' => [
                'labels' => $progressLabels,
                'reel' => $progressReel,
                'theorique' => $progressTheorique,
                'urls' => $progressUrls,
            ],
            'kpi_trend' => [
                'labels' => $trendLabels,
                'valeurs' => $trendValues,
                'cibles' => $trendTargets,
                'seuils' => $trendThresholds,
                'urls' => $trendUrls,
            ],
            'retard_heatmap' => [
                'weeks' => $weekLabels,
                'units' => $heatUnits,
                'matrix' => $heatMatrix,
                'urls' => $heatUrls,
                'max' => $heatMax,
            ],
            'critical_gantt' => $criticalGantt,
            'resource_treemap' => [
                'labels' => array_map(fn ($label): string => Str::limit((string) $label, 44), $resourceLabels),
                'values' => $resourceValues,
                'urls' => $resourceUrls,
                'total' => round((float) array_sum($resourceValues), 2),
            ],
            'performance_gauge' => [
                'scope_label' => (string) ($performanceGaugeMeta['label'] ?? 'Directions'),
                'empty_label' => (string) ($performanceGaugeMeta['empty_label'] ?? 'Aucune donnee disponible pour les jauges.'),
                'labels' => $performanceLabels,
                'values' => $performanceValues,
                'urls' => $performanceUrls,
            ],
            'interannual_overview' => [
                'labels' => array_map(fn (array $row): string => (string) $row['annee'], $interannualRows),
                'actions_total' => array_map(fn (array $row): int => (int) $row['actions_total'], $interannualRows),
                'actions_validees' => array_map(fn (array $row): int => (int) $row['actions_validees'], $interannualRows),
                'progression_moyenne' => array_map(fn (array $row): float => (float) $row['progression_moyenne'], $interannualRows),
                'urls' => array_map(fn (array $row): string => (string) ($row['url'] ?? route('workspace.pao.index', ['annee' => (int) $row['annee']])), $interannualRows),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function completedActionStatuses(): array
    {
        return ActionTrackingService::completedActionStatuses();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function validatedActions(Collection $actions): Collection
    {
        return $this->actionCalculationSettings->filterOfficial($actions, 'statut_validation');
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float>
     */
    private function buildKpiSummary(Collection $actions): array
    {
        return $this->kpiAggregatorService->summarizeActions($actions);

    }

    /**
     * @return array<int, int>
     */
    private function scopedDirectionIds(User $user): array
    {
        if ($user->hasGlobalReadAccess()) {
            return Direction::query()
                ->where('actif', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        if ($user->direction_id !== null) {
            return [(int) $user->direction_id];
        }

        return [];
    }

    private function completionRate(int $done, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($done / $total) * 100, 2);
    }

    /**
     * @return array{label: string, empty_label: string, mode: string}
     */
    private function resolvePerformanceGaugeMeta(User $user): array
    {
        return match (true) {
            $user->hasGlobalReadAccess() => [
                'label' => 'Directions',
                'empty_label' => 'Aucune direction disponible pour les jauges.',
                'mode' => 'direction',
            ],
            $user->hasRole(User::ROLE_DIRECTION) => [
                'label' => 'Services',
                'empty_label' => 'Aucun service disponible pour les jauges.',
                'mode' => 'service',
            ],
            default => [
                'label' => 'Actions',
                'empty_label' => 'Aucune action disponible pour les jauges.',
                'mode' => 'action',
            ],
        };
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildPerformanceGaugeRows(User $user, Collection $actions): array
    {
        $meta = $this->resolvePerformanceGaugeMeta($user);
        $mode = (string) ($meta['mode'] ?? 'direction');
        $groups = [];

        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                continue;
            }

            if ($mode === 'direction') {
                $groupId = (int) ($action->pta?->direction?->id ?? 0);
                if ($groupId <= 0) {
                    continue;
                }

                $groupKey = 'direction:'.$groupId;
                $label = (string) ($action->pta?->direction?->code ?? $action->pta?->direction?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute(['direction_id' => $groupId]);
            } elseif ($mode === 'service') {
                $groupId = (int) ($action->pta?->service?->id ?? 0);
                if ($groupId <= 0) {
                    continue;
                }

                $groupKey = 'service:'.$groupId;
                $label = (string) ($action->pta?->service?->code ?? $action->pta?->service?->libelle ?? 'Non renseigne');
                $url = $this->officialActionIndexRoute(['service_id' => $groupId]);
            } else {
                $groupKey = 'action:'.(int) $action->id;
                $label = (string) ($action->libelle ?? 'Action');
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

                return [
                    'label' => (string) ($group['label'] ?? 'Non renseigne'),
                    'url' => (string) ($group['url'] ?? $this->officialActionIndexRoute()),
                    'actions_total' => $items->count(),
                    'kpi_global' => round((float) $items->avg(
                        fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)
                    ), 2),
                ];
            })
            ->filter(fn (array $row): bool => (int) ($row['actions_total'] ?? 0) > 0)
            ->sortByDesc(
                fn (array $row): float => ((float) ($row['kpi_global'] ?? 0) * 1000) + (float) ($row['actions_total'] ?? 0)
            )
            ->take(6)
            ->values()
            ->all();
    }

    private function actionIndexRoute(array $filters = []): string
    {
        return route('workspace.actions.index', array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
    }

    private function officialActionIndexRoute(array $filters = []): string
    {
        return $this->actionIndexRoute(array_merge(
            $this->actionCalculationSettings->officialRouteFilters(),
            $filters
        ));
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
}
