<?php

namespace App\Services;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\DeadlineExtensionRequest;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PaoHierarchyService
{
    use AuthorizesPlanningScope;

    /**
     * @var array<int, float>
     */
    private array $actionProgressCache = [];

    public function __construct(
        private readonly ActionPerformanceService $performanceService
    ) {}

    /**
     * @return array{
     *     strategic_groups:list<array<string, mixed>>,
     *     summary:array<string, int|float>,
     *     anomalies:list<array{label:string,count:int}>
     * }
     */
    public function build(Pao $pao, User $user): array
    {
        $this->actionProgressCache = [];

        $pao->load([
            'pas:id,titre,periode_debut,periode_fin,statut',
            'direction:id,code,libelle',
            'service:id,direction_id,code,libelle',
            'pasObjectif:id,pas_axe_id,code,libelle,date_echeance,indicateur_global,valeur_cible',
            'pasObjectif.pasAxe:id,pas_id,code,libelle',
            'objectifsOperationnels' => function (Builder|Relation $objectiveQuery) use ($user): void {
                $this->scopeByUserDirection($objectiveQuery, $user, 'direction_id', 'service_id');
                $objectiveQuery
                    ->with([
                        'pasObjectif:id,pas_axe_id,code,libelle,date_echeance,indicateur_global,valeur_cible',
                        'pasObjectif.pasAxe:id,pas_id,code,libelle',
                        'service:id,direction_id,code,libelle',
                        'ptas' => function (Builder|Relation $ptaQuery) use ($user): void {
                            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                            $ptaQuery
                                ->with([
                                    'service:id,direction_id,code,libelle',
                                    'actions' => fn (Builder|Relation $actionQuery) => $actionQuery
                                        ->with([
                                            'responsable:id,name,email',
                                            'sousActions:id,action_id,libelle,statut,est_effectuee,cible_prevue,quantite_realisee,sub_action_type,type_indicateur',
                                            'justificatifs:id,justifiable_type,justifiable_id,categorie',
                                            'deadlineExtensionRequests:id,action_id,status,requested_deadline,approved_deadline,created_at',
                                        ])
                                        ->orderBy('ordre_import')
                                        ->orderBy('id'),
                                ])
                                ->orderBy('id');
                        },
                    ])
                    ->orderBy('import_ordre')
                    ->orderBy('id');
            },
        ]);

        $strategicGroups = $this->strategicGroups($pao);
        $operationalObjectives = $pao->objectifsOperationnels;
        $ptas = $operationalObjectives->flatMap->ptas;
        $actions = $ptas->flatMap->actions;
        $operationalObjectivesWithoutPta = $operationalObjectives
            ->filter(fn (ObjectifOperationnel $objective): bool => $objective->ptas->isEmpty())
            ->count();
        $ptasWithoutAction = $ptas
            ->filter(fn (Pta $pta): bool => $pta->actions->isEmpty())
            ->count();
        $lateActions = $actions
            ->filter(fn (Action $action): bool => $this->isLate($action))
            ->count();
        $activeReports = $actions
            ->filter(fn (Action $action): bool => $this->activeDeadlineRequest($action) instanceof DeadlineExtensionRequest)
            ->count();
        $unconfiguredActions = $actions
            ->where('statut_parametrage', 'a_parametrer')
            ->count();
        $progress = $actions->isEmpty()
            ? 0
            : round($actions->avg(fn (Action $action): float => $this->actionProgress($action)), 1);

        return [
            'strategic_groups' => $strategicGroups,
            'summary' => [
                'strategic_objectives' => collect($strategicGroups)->where('id', '>', 0)->count(),
                'services' => $operationalObjectives->pluck('service_id')->filter()->unique()->count(),
                'operational_objectives' => $operationalObjectives->count(),
                'ptas' => $ptas->count(),
                'actions' => $actions->count(),
                'progress' => $progress,
                'operational_objectives_without_pta' => $operationalObjectivesWithoutPta,
                'ptas_without_action' => $ptasWithoutAction,
                'late_actions' => $lateActions,
                'active_reports' => $activeReports,
                'unconfigured_actions' => $unconfiguredActions,
            ],
            'anomalies' => collect([
                ['label' => 'Objectifs operationnels sans PTA', 'count' => $operationalObjectivesWithoutPta],
                ['label' => 'PTA sans action', 'count' => $ptasWithoutAction],
                ['label' => 'Actions en retard', 'count' => $lateActions],
                ['label' => 'Reports d echeance actifs', 'count' => $activeReports],
                ['label' => 'Actions a parametrer', 'count' => $unconfiguredActions],
                ['label' => 'Rattachements strategiques a corriger', 'count' => collect($strategicGroups)->where('id', 0)->sum('operational_objectives_count')],
            ])->filter(fn (array $anomaly): bool => $anomaly['count'] > 0)->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function strategicGroups(Pao $pao): array
    {
        $groups = $pao->objectifsOperationnels
            ->groupBy(fn (ObjectifOperationnel $objective): int => (int) ($objective->pas_objectif_id ?? 0))
            ->map(function (Collection $objectives, int $strategicObjectiveId): array {
                $strategicObjective = $objectives->first()?->pasObjectif;

                return [
                    'id' => $strategicObjectiveId,
                    'code' => (string) ($strategicObjective?->code ?: 'A CORRIGER'),
                    'label' => (string) ($strategicObjective?->libelle ?: 'Objectifs sans rattachement strategique valide'),
                    'deadline' => $strategicObjective?->date_echeance?->format('d/m/Y'),
                    'indicator' => $strategicObjective?->indicateur_global,
                    'target' => $strategicObjective?->valeur_cible,
                    'axis' => [
                        'code' => (string) ($strategicObjective?->pasAxe?->code ?: '-'),
                        'label' => (string) ($strategicObjective?->pasAxe?->libelle ?: 'Axe non renseigné'),
                    ],
                    'operational_objectives' => $objectives
                        ->map(fn (ObjectifOperationnel $objective): array => $this->operationalObjectiveNode($objective))
                        ->values()
                        ->all(),
                    'operational_objectives_count' => $objectives->count(),
                    'ptas_count' => $objectives->flatMap->ptas->count(),
                    'actions_count' => $objectives->flatMap->ptas->flatMap->actions->count(),
                ];
            })
            ->sortBy(fn (array $group): string => $group['axis']['code'].'-'.$group['code'])
            ->values();

        if ($groups->isEmpty() && $pao->pasObjectif !== null) {
            $groups->push([
                'id' => (int) $pao->pasObjectif->id,
                'code' => (string) $pao->pasObjectif->code,
                'label' => (string) $pao->pasObjectif->libelle,
                'deadline' => $pao->pasObjectif->date_echeance?->format('d/m/Y'),
                'indicator' => $pao->pasObjectif->indicateur_global,
                'target' => $pao->pasObjectif->valeur_cible,
                'axis' => [
                    'code' => (string) ($pao->pasObjectif->pasAxe?->code ?: '-'),
                    'label' => (string) ($pao->pasObjectif->pasAxe?->libelle ?: 'Axe non renseigné'),
                ],
                'operational_objectives' => [],
                'operational_objectives_count' => 0,
                'ptas_count' => 0,
                'actions_count' => 0,
            ]);
        }

        return $groups->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalObjectiveNode(ObjectifOperationnel $objective): array
    {
        $ptas = $objective->ptas
            ->map(fn (Pta $pta): array => $this->ptaNode($pta))
            ->values();

        return [
            'id' => (int) $objective->id,
            'code' => (string) ($objective->code ?: 'OO-'.$objective->id),
            'label' => (string) $objective->libelle,
            'description' => $objective->description,
            'deadline' => $objective->echeance?->format('d/m/Y'),
            'status' => (string) $objective->statut,
            'service' => [
                'id' => (int) ($objective->service_id ?? 0),
                'code' => (string) ($objective->service?->code ?: '-'),
                'label' => (string) ($objective->service?->libelle ?: 'Service non renseigne'),
            ],
            'ptas' => $ptas->all(),
            'ptas_count' => $ptas->count(),
            'actions_count' => $ptas->sum('actions_count'),
            'progress' => $this->averageNodeProgress($ptas),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ptaNode(Pta $pta): array
    {
        $actions = $pta->actions
            ->map(fn (Action $action): array => $this->actionNode($action))
            ->values();

        return [
            'id' => (int) $pta->id,
            'code' => (string) ($pta->code ?: 'PTA-'.$pta->id),
            'label' => (string) $pta->titre,
            'status' => (string) $pta->statut,
            'service' => (string) ($pta->service?->libelle ?: 'Service non renseigne'),
            'actions' => $actions->all(),
            'actions_count' => $actions->count(),
            'progress' => $this->averageNodeProgress($actions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function actionNode(Action $action): array
    {
        $progress = $this->actionProgress($action);
        $activeReport = $this->activeDeadlineRequest($action);

        return [
            'id' => (int) $action->id,
            'code' => (string) ($action->code ?: 'ACT-'.$action->id),
            'label' => (string) $action->libelle,
            'status' => $this->performanceService->normalizeStatus($action),
            'deadline' => $this->actionDeadline($action)?->format('d/m/Y'),
            'progress' => $progress,
            'is_late' => $this->isLate($action),
            'responsible' => (string) ($action->responsable?->name ?: 'Non affecte'),
            'sub_actions_count' => $action->sousActions->count(),
            'report' => $activeReport instanceof DeadlineExtensionRequest
                ? [
                    'id' => (int) $activeReport->id,
                    'status' => (string) $activeReport->status,
                    'requested_deadline' => $activeReport->requested_deadline?->format('d/m/Y'),
                ]
                : null,
        ];
    }

    private function actionProgress(Action $action): float
    {
        return $this->actionProgressCache[(int) $action->id]
            ??= round($this->performanceService->calculateRealProgress($action), 1);
    }

    private function isLate(Action $action): bool
    {
        $deadline = $this->actionDeadline($action);

        return $deadline !== null
            && $deadline->isBefore(Carbon::today())
            && $this->actionProgress($action) < 100;
    }

    private function actionDeadline(Action $action): ?Carbon
    {
        $deadline = $action->echeance_cible ?? $action->date_echeance ?? $action->date_fin;

        return $deadline === null ? null : Carbon::parse($deadline);
    }

    private function activeDeadlineRequest(Action $action): ?DeadlineExtensionRequest
    {
        return $action->deadlineExtensionRequests
            ->first(fn (DeadlineExtensionRequest $request): bool => ! in_array((string) $request->status, [
                DeadlineExtensionRequest::STATUS_REJETEE,
                DeadlineExtensionRequest::STATUS_MISE_A_JOUR_APPLIQUEE,
            ], true));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     */
    private function averageNodeProgress(Collection $nodes): float
    {
        return $nodes->isEmpty() ? 0 : round((float) $nodes->avg('progress'), 1);
    }
}
