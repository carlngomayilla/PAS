<?php

namespace App\Services\Analytics;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\ActionWeek;
use App\Models\Direction;
use App\Models\KpiMesure;
use App\Models\Pao;
use App\Models\PaoObjectifOperationnel;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ReportingAnalyticsService
{
    use AuthorizesPlanningScope;

    private const CACHE_TTL_SECONDS = 60;

    /**
     * @return array{
     *     generatedAt: \Illuminate\Support\Carbon,
     *     scope: array{role: string, direction_id: int|null, service_id: int|null},
     *     global: array<string, int>,
     *     kpiSummary: array<string, float>,
     *     statuts: array<string, array<string, int>>,
     *     alertes: array<string, int>,
     *     pasConsolidation: array<int, array<string, mixed>>,
     *     interannualComparison: array<int, array<string, mixed>>,
     *     charts: array<string, mixed>,
     *     details: array{
     *         actions_retard: \Illuminate\Support\Collection<int, \App\Models\Action>,
     *         kpi_sous_seuil: \Illuminate\Support\Collection<int, \App\Models\KpiMesure>,
     *         structure_rapports: \Illuminate\Support\Collection<int, array<string, string>>
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
        $actionsStatistics = (clone $actions);
        $this->scopeActionStatistics($actionsStatistics);
        $validatedActions = (clone $actionsStatistics)
            ->with([
                'actionKpi:id,action_id,kpi_delai,kpi_performance,kpi_conformite,kpi_qualite,kpi_risque,kpi_global',
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
        ];

        if ($withDetails) {
            $details['actions_retard'] = (clone $actions)
                ->with([
                    'pta:id,titre,direction_id,service_id',
                    'responsable:id,name,email',
                    'actionKpi:id,action_id,kpi_global,kpi_qualite,kpi_risque',
                ])
                ->whereNotNull('date_echeance')
                ->whereDate('date_echeance', '<', $today)
                ->whereNotIn('statut_dynamique', $this->completedActionStatuses())
                ->orderBy('date_echeance')
                ->limit(200)
                ->get();

            $kpiIds = (clone $kpiSousSeuilQuery)
                ->select('kpi_mesures.id')
                ->orderByDesc('kpi_mesures.id')
                ->limit(200)
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

            $details['structure_rapports'] = (clone $actions)
                ->with([
                    'pta:id,pao_id,titre,direction_id,service_id',
                    'pta.pao:id,titre',
                    'responsable:id,name,email',
                    'kpis:id,action_id,libelle',
                    'actionKpi:id,action_id,kpi_global,kpi_qualite,kpi_risque',
                ])
                ->orderByDesc('id')
                ->limit(300)
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
                            ? number_format((float) $action->quantite_cible, 2, '.', '')
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
                        'axe_strategique' => (string) ($action->pta?->pao?->titre ?? $action->pta?->titre ?? ''),
                        'objectif_strategique' => (string) ($action->pta?->titre ?? ''),
                        'objectif_operationnel' => (string) $action->libelle,
                        'description_actions_detaillees' => (string) ($action->description ?? ''),
                        'rmo' => (string) ($action->responsable?->name ?? ''),
                        'cible' => (string) $cible,
                        'debut' => optional($action->date_debut)->format('Y-m-d') ?? '',
                        'fin' => optional($action->date_fin)->format('Y-m-d') ?? '',
                        'etat_realisation' => (string) $action->statut_dynamique,
                        'progression' => number_format((float) ($action->progression_reelle ?? 0), 2, '.', '').'%',
                        'kpi_global' => round((float) ($action->actionKpi?->kpi_global ?? 0), 2),
                        'kpi_qualite' => round((float) ($action->actionKpi?->kpi_qualite ?? 0), 2),
                        'kpi_risque' => round((float) ($action->actionKpi?->kpi_risque ?? 0), 2),
                        'ressources_requises' => implode(' | ', $ressources),
                        'indicateurs_performance' => (string) $indicateurs,
                        'risques_potentiels' => (string) ($action->risques ?? ''),
                    ];
                });
        }

        return [
            'generatedAt' => now(),
            'scope' => [
                'role' => $user->role,
                'direction_id' => $user->direction_id,
                'service_id' => $user->service_id,
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
            'kpiSummary' => $this->buildKpiSummary($validatedActions),
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
            ],
            'pasConsolidation' => $this->buildPasConsolidation($user),
            'interannualComparison' => $this->buildInterannualComparison($user),
            'charts' => $withCharts ? $this->buildChartsPayload($user) : [],
            'details' => $details,
        ];
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
        ], JSON_THROW_ON_ERROR));
    }

    private function scopePao(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id');
    }

    private function scopePta(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
    }

    private function scopeAction(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    private function scopeActionStatistics(Builder $query, string $column = 'statut_validation'): void
    {
        $query->where($column, ActionTrackingService::VALIDATION_VALIDEE_DIRECTION);
    }

    private function scopeMesure(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
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
        $this->scopeByUserDirection($query, $user, $directionColumn, $serviceColumn);
    }

    private function buildPasScopedQuery(User $user): Builder
    {
        $query = Pas::query();
        $this->scopePasByUser($query, $user);

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

        $statusRows = Action::query()
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->selectRaw("{$unitColumn} as unit_id, actions.statut_dynamique as status_label, COUNT(*) as total")
            ->groupBy($unitColumn, 'actions.statut_dynamique');
        $this->scopeJoinedPta($statusRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $statusMatrix = [];
        $statusTotals = [];
        $unitTotals = [];
        foreach ($statusRows->get() as $row) {
            $unitId = (int) ($row->unit_id ?? 0);
            if ($unitId <= 0) {
                continue;
            }
            $status = trim((string) ($row->status_label ?? 'inconnu'));
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
        $statusNames = array_slice(array_keys($statusTotals), 0, 6);
        $statusDatasets = [];
        $statusUrls = [];
        foreach ($statusNames as $statusName) {
            $statusDatasets[] = [
                'label' => $statusName,
                'data' => array_map(fn (int $unitId): int => (int) ($statusMatrix[$statusName][$unitId] ?? 0), $unitIds),
            ];
            $statusUrls[] = array_map(
                fn (int $unitId): string => $this->actionIndexRoute([
                    $unitFilterKey => $unitId,
                    'statut' => $statusName,
                ]),
                $unitIds
            );
        }

        $progressRows = ActionWeek::query()
            ->select(['action_weeks.date_debut', 'action_weeks.progression_reelle', 'action_weeks.progression_theorique'])
            ->join('actions', 'actions.id', '=', 'action_weeks.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->where('actions.statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->whereNotNull('action_weeks.date_debut')
            ->orderBy('action_weeks.date_debut');
        $this->scopeJoinedPta($progressRows, $user, 'ptas.direction_id', 'ptas.service_id');

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
            $progressUrls[] = $this->actionIndexRoute([
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                'week_start' => $weekStart,
            ]);
        }

        $trendRows = KpiMesure::query()
            ->select(['kpi_mesures.periode', 'kpi_mesures.valeur', 'kpis.cible', 'kpis.seuil_alerte'])
            ->join('kpis', 'kpis.id', '=', 'kpi_mesures.kpi_id')
            ->join('actions', 'actions.id', '=', 'kpis.action_id')
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->where('actions.statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->whereNotNull('kpi_mesures.periode')
            ->orderBy('kpi_mesures.id');
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
                ? $this->actionIndexRoute([
                    'annee' => (int) $matches[1],
                    'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                ])
                : $this->actionIndexRoute([
                    'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
                ]);
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
            ->with(['pta:id,pao_id,titre,direction_id,service_id', 'pta.pao:id,titre', 'responsable:id,name,email'])
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
                    'score' => round($this->computeActionRiskScore($action, $today), 2),
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

        $riskCounts = [];
        foreach ($actionCandidates as $action) {
            $riskText = (string) ($action->risques ?? '');
            if (trim($riskText) === '') {
                continue;
            }
            $parts = preg_split('/[;,|\\/\\r\\n]+/', $riskText) ?: [];
            foreach ($parts as $part) {
                $normalized = $this->normalizeRiskLabel((string) $part);
                if ($normalized === null) {
                    continue;
                }
                $riskCounts[$normalized] = (int) (($riskCounts[$normalized] ?? 0) + 1);
            }
        }
        arsort($riskCounts);
        $riskCounts = array_slice($riskCounts, 0, 8, true);

        $paretoLabels = array_keys($riskCounts);
        $paretoValues = array_values($riskCounts);
        $paretoUrls = array_map(
            fn (string $label): string => $this->actionIndexRoute(['risque_label' => $label]),
            $paretoLabels
        );
        $paretoCumulative = [];
        $runningTotal = 0;
        $allTotal = array_sum($paretoValues);
        foreach ($paretoValues as $value) {
            $runningTotal += (int) $value;
            $paretoCumulative[] = $allTotal > 0 ? round(($runningTotal / $allTotal) * 100, 2) : 0.0;
        }

        $topRiskRows = $scoredActions
            ->take(10)
            ->map(function (array $item): array {
                $action = $item['action'];

                return [
                    'action' => (string) $item['label'],
                    'score' => (float) $item['score'],
                    'statut' => (string) $item['status'],
                    'echeance' => $action->date_echeance instanceof Carbon ? $action->date_echeance->toDateString() : '-',
                    'responsable' => (string) ($action->responsable?->name ?? '-'),
                    'url' => route('workspace.actions.suivi', $action),
                ];
            })
            ->all();

        $topRiskLabels = array_map(fn (array $row): string => Str::limit((string) $row['action'], 34), $topRiskRows);
        $topRiskScores = array_map(fn (array $row): float => (float) $row['score'], $topRiskRows);

        $performanceRows = Action::query()
            ->join('ptas', 'ptas.id', '=', 'actions.pta_id')
            ->where('actions.statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->selectRaw('ptas.direction_id as direction_id, AVG(COALESCE(actions.progression_reelle, 0)) as avg_progression, COUNT(*) as total')
            ->groupBy('ptas.direction_id');
        $this->scopeJoinedPta($performanceRows, $user, 'ptas.direction_id', 'ptas.service_id');

        $performanceDirectionNames = Direction::query()
            ->pluck('libelle', 'id')
            ->mapWithKeys(fn ($label, $id): array => [(int) $id => (string) $label])
            ->toArray();

        $perfRows = $performanceRows->get()->map(function ($row): array {
            return [
                'direction_id' => (int) ($row->direction_id ?? 0),
                'avg' => round(max(0, min(100, (float) ($row->avg_progression ?? 0))), 2),
                'total' => (int) ($row->total ?? 0),
            ];
        })->sortByDesc('total')->values();

        $performanceLabels = [];
        $performanceValues = [];
        $performanceUrls = [];
        foreach ($perfRows->take(6) as $row) {
            $directionId = (int) $row['direction_id'];
            if ($directionId <= 0) {
                continue;
            }
            $performanceLabels[] = $performanceDirectionNames[$directionId] ?? ('#'.$directionId);
            $performanceValues[] = (float) $row['avg'];
            $performanceUrls[] = $this->actionIndexRoute([
                'direction_id' => $directionId,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ]);
        }

        if ($performanceLabels === [] && $user->direction_id !== null) {
            $performanceLabels[] = $performanceDirectionNames[(int) $user->direction_id] ?? ('#'.$user->direction_id);
            $performanceValues[] = 0.0;
            $performanceUrls[] = $this->actionIndexRoute([
                'direction_id' => (int) $user->direction_id,
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ]);
        }

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
            'risk_pareto' => [
                'labels' => array_map(fn ($label): string => Str::limit(Str::title((string) $label), 42), $paretoLabels),
                'counts' => $paretoValues,
                'urls' => $paretoUrls,
                'cumulative_pct' => $paretoCumulative,
            ],
            'top_risks' => [
                'labels' => $topRiskLabels,
                'scores' => $topRiskScores,
                'rows' => $topRiskRows,
            ],
            'performance_gauge' => [
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
        return [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_SUSPENDU,
            ActionTrackingService::STATUS_ANNULE,
        ];
    }

    /**
     * @param Collection<int, Action> $actions
     * @return Collection<int, Action>
     */
    private function validatedActions(Collection $actions): Collection
    {
        return $actions
            ->where('statut_validation', ActionTrackingService::VALIDATION_VALIDEE_DIRECTION)
            ->values();
    }

    /**
     * @param Collection<int, Action> $actions
     * @return array<string, float>
     */
    private function buildKpiSummary(Collection $actions): array
    {
        $average = static function (Collection $items, callable $callback): float {
            if ($items->isEmpty()) {
                return 0.0;
            }

            return round((float) $items->avg($callback), 2);
        };

        return [
            'delai' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_delai ?? 0)),
            'performance' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_performance ?? 0)),
            'conformite' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_conformite ?? 0)),
            'qualite' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_qualite ?? 0)),
            'risque' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_risque ?? 0)),
            'global' => $average($actions, fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)),
            'progression' => $average($actions, fn (Action $action): float => (float) ($action->progression_reelle ?? 0)),
        ];
    }

    private function computeActionRiskScore(Action $action, Carbon $today): float
    {
        $lateDays = 0;
        if ($action->date_echeance instanceof Carbon && ! in_array((string) $action->statut_dynamique, $this->completedActionStatuses(), true) && $action->date_echeance->lt($today)) {
            $lateDays = $action->date_echeance->diffInDays($today);
        }

        $gap = max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));
        $hasRisk = trim((string) ($action->risques ?? '')) !== '';
        $isDelayed = (string) ($action->statut_dynamique ?? '') === ActionTrackingService::STATUS_EN_RETARD;

        $score = ($lateDays * 2.0) + $gap;
        if ($isDelayed) {
            $score += 25.0;
        }
        if ($hasRisk) {
            $score += 10.0;
        }
        if ((bool) $action->financement_requis) {
            $score += 4.0;
        }

        return max(0.0, $score);
    }

    private function normalizeRiskLabel(string $value): ?string
    {
        $normalized = Str::of($value)
            ->lower()
            ->replaceMatches('/\\s+/', ' ')
            ->trim(" \t\n\r\0\x0B-._");

        $label = (string) $normalized;
        if ($label === '' || Str::length($label) < 4) {
            return null;
        }

        $ignored = ['ras', 'aucun', 'aucune', 'neant', 'n/a', 'na', 'none', 'ok'];
        if (in_array($label, $ignored, true)) {
            return null;
        }

        return $label;
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

    private function actionIndexRoute(array $filters = []): string
    {
        return route('workspace.actions.index', array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== ''
        ));
    }

    /**
     * @return array<string, int>
     */
    private function countByStatus(Builder $query, string $statusColumn): array
    {
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
