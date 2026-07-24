<?php

namespace App\Services;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class PasHierarchyService
{
    use AuthorizesPlanningScope;

    /**
     * @return array{
     *     axes:list<array<string, mixed>>,
     *     summary:array<string, int|float>,
     *     anomalies:list<array{label:string,count:int}>
     * }
     */
    public function build(Pas $pas, User $user): array
    {
        $pas->load([
            'axes' => fn (Builder|Relation $query) => $query
                ->with(['objectifs'])
                ->orderBy('ordre')
                ->orderBy('id'),
        ]);

        $paos = $this->scopedPaos($pas, $user);
        $objectiveIds = $pas->axes
            ->flatMap(fn ($axis) => $axis->objectifs->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->all();

        $paoNodesByObjective = $this->paoNodesByObjective($paos, $objectiveIds);
        $axes = $pas->axes
            ->map(function ($axis) use ($paoNodesByObjective): array {
                $objectives = $axis->objectifs
                    ->map(function ($objective) use ($paoNodesByObjective): array {
                        $paoNodes = collect($paoNodesByObjective[(int) $objective->id] ?? [])->values();
                        $operationalObjectives = $paoNodes->flatMap(
                            fn (array $paoNode): array => $paoNode['operational_objectives']
                        );
                        $ptas = $operationalObjectives->flatMap(
                            fn (array $operationalObjective): array => $operationalObjective['ptas']
                        );

                        return [
                            'id' => (int) $objective->id,
                            'code' => (string) $objective->code,
                            'label' => (string) $objective->libelle,
                            'deadline' => $objective->date_echeance?->format('d/m/Y'),
                            'indicator' => $objective->indicateur_global,
                            'target' => $objective->valeur_cible,
                            'paos' => $paoNodes->all(),
                            'paos_count' => $paoNodes->pluck('id')->unique()->count(),
                            'operational_objectives_count' => $operationalObjectives->count(),
                            'ptas_count' => $ptas->count(),
                            'actions_count' => $ptas->sum('actions_count'),
                            'is_declined' => $operationalObjectives->isNotEmpty(),
                        ];
                    })
                    ->values();

                return [
                    'id' => (int) $axis->id,
                    'code' => (string) $axis->code,
                    'label' => (string) $axis->libelle,
                    'period' => $this->periodLabel($axis->periode_debut, $axis->periode_fin),
                    'objectives' => $objectives->all(),
                    'objectives_count' => $objectives->count(),
                    'operational_objectives_count' => $objectives->sum('operational_objectives_count'),
                    'ptas_count' => $objectives->sum('ptas_count'),
                    'actions_count' => $objectives->sum('actions_count'),
                ];
            })
            ->values();

        $operationalObjectives = $paos->flatMap->objectifsOperationnels;
        $ptas = $operationalObjectives->flatMap->ptas;
        $strategicObjectives = $axes->flatMap(
            fn (array $axis): array => $axis['objectives']
        );
        $strategicObjectivesWithoutOperational = $strategicObjectives
            ->where('is_declined', false)
            ->count();
        $operationalObjectivesWithoutPta = $operationalObjectives
            ->filter(fn (ObjectifOperationnel $objective): bool => $objective->ptas->isEmpty())
            ->count();
        $ptasWithoutAction = $ptas
            ->filter(fn (Pta $pta): bool => (int) $pta->actions_count === 0)
            ->count();
        $strategicObjectivesTotal = $strategicObjectives->count();
        $orphanOperationalObjectives = $operationalObjectives
            ->reject(fn (ObjectifOperationnel $objective): bool => in_array((int) $objective->pas_objectif_id, $objectiveIds, true))
            ->count();

        return [
            'axes' => $axes->all(),
            'summary' => [
                'axes' => $axes->count(),
                'strategic_objectives' => $strategicObjectivesTotal,
                'paos' => $paos->count(),
                'operational_objectives' => $operationalObjectives->count(),
                'ptas' => $ptas->count(),
                'actions' => $ptas->sum('actions_count'),
                'strategic_objectives_without_operational' => $strategicObjectivesWithoutOperational,
                'operational_objectives_without_pta' => $operationalObjectivesWithoutPta,
                'ptas_without_action' => $ptasWithoutAction,
                'strategic_coverage' => $strategicObjectivesTotal === 0
                    ? 0
                    : round((($strategicObjectivesTotal - $strategicObjectivesWithoutOperational) / $strategicObjectivesTotal) * 100),
            ],
            'anomalies' => collect([
                ['label' => 'Objectifs strategiques non declines', 'count' => $strategicObjectivesWithoutOperational],
                ['label' => 'Objectifs operationnels sans PTA', 'count' => $operationalObjectivesWithoutPta],
                ['label' => 'PTA sans action', 'count' => $ptasWithoutAction],
                ['label' => 'Rattachements strategiques incoherents', 'count' => $orphanOperationalObjectives],
            ])->filter(fn (array $anomaly): bool => $anomaly['count'] > 0)->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Pao>
     */
    private function scopedPaos(Pas $pas, User $user): Collection
    {
        $query = Pao::query()
            ->where('pas_id', (int) $pas->id)
            ->with([
                'direction:id,code,libelle',
                'objectifsOperationnels' => function (Builder|Relation $objectiveQuery) use ($user): void {
                    $this->scopeByUserDirection($objectiveQuery, $user, 'direction_id', 'service_id');
                    $objectiveQuery
                        ->with([
                            'service:id,direction_id,code,libelle',
                            'ptas' => function (Builder|Relation $ptaQuery) use ($user): void {
                                $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
                                $ptaQuery
                                    ->with('service:id,direction_id,code,libelle')
                                    ->withCount('actions')
                                    ->orderBy('id');
                            },
                        ])
                        ->orderBy('import_ordre')
                        ->orderBy('id');
                },
            ])
            ->orderByDesc('annee')
            ->orderBy('direction_id')
            ->orderBy('id');

        $this->scopePaosForHierarchy($query, $user);

        return $query->get();
    }

    private function scopePaosForHierarchy(Builder $query, User $user): void
    {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $directionIds = array_values(array_unique(array_filter(array_merge(
            $user->delegatedDirectionIds('planning_read'),
            $user->delegatedDirectionIds('planning_write'),
            $user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null
                ? [(int) $user->direction_id]
                : []
        ), static fn ($id): bool => (int) $id > 0)));

        $serviceScopes = array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write')
        );
        if ($this->hasOwnServicePlanningScope($user)) {
            $serviceScopes[] = [
                'direction_id' => (int) $user->direction_id,
                'service_id' => (int) $user->service_id,
            ];
        }
        $serviceScopes = collect($serviceScopes)
            ->unique(fn (array $scope): string => $scope['direction_id'].'-'.$scope['service_id'])
            ->values()
            ->all();

        if ($directionIds === [] && $serviceScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $scopeQuery) use ($directionIds, $serviceScopes): void {
            if ($directionIds !== []) {
                $scopeQuery->orWhereIn('direction_id', $directionIds);
            }

            foreach ($serviceScopes as $scope) {
                $scopeQuery->orWhere(function (Builder $serviceQuery) use ($scope): void {
                    $serviceQuery
                        ->where('direction_id', (int) $scope['direction_id'])
                        ->where(function (Builder $relationQuery) use ($scope): void {
                            $relationQuery
                                ->where('service_id', (int) $scope['service_id'])
                                ->orWhereHas('objectifsOperationnels', fn (Builder $objectiveQuery) => $objectiveQuery->where('service_id', (int) $scope['service_id']))
                                ->orWhereHas('ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', (int) $scope['service_id']));
                        });
                });
            }
        });
    }

    /**
     * @param  Collection<int, Pao>  $paos
     * @param  list<int>  $objectiveIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function paoNodesByObjective(Collection $paos, array $objectiveIds): array
    {
        $nodes = [];

        foreach ($paos as $pao) {
            $operationalGroups = $pao->objectifsOperationnels
                ->filter(fn (ObjectifOperationnel $objective): bool => in_array((int) $objective->pas_objectif_id, $objectiveIds, true))
                ->groupBy(fn (ObjectifOperationnel $objective): int => (int) $objective->pas_objectif_id);

            if ($operationalGroups->isEmpty() && in_array((int) $pao->pas_objectif_id, $objectiveIds, true)) {
                $operationalGroups->put((int) $pao->pas_objectif_id, collect());
            }

            foreach ($operationalGroups as $strategicObjectiveId => $operationalObjectives) {
                $nodes[(int) $strategicObjectiveId][] = [
                    'id' => (int) $pao->id,
                    'code' => (string) ($pao->code ?: 'PAO-'.$pao->id),
                    'label' => (string) ($pao->titre ?: 'PAO '.$pao->annee),
                    'year' => (int) $pao->annee,
                    'status' => (string) $pao->statut,
                    'direction' => $pao->direction?->libelle ?? '-',
                    'operational_objectives' => $operationalObjectives
                        ->map(fn (ObjectifOperationnel $objective): array => $this->operationalObjectiveNode($objective))
                        ->values()
                        ->all(),
                ];
            }
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalObjectiveNode(ObjectifOperationnel $objective): array
    {
        return [
            'id' => (int) $objective->id,
            'code' => (string) ($objective->code ?: 'OO-'.$objective->id),
            'label' => (string) $objective->libelle,
            'deadline' => $objective->echeance?->format('d/m/Y'),
            'status' => (string) $objective->statut,
            'service' => $objective->service?->libelle ?? '-',
            'ptas' => $objective->ptas
                ->map(fn (Pta $pta): array => [
                    'id' => (int) $pta->id,
                    'code' => (string) ($pta->code ?: 'PTA-'.$pta->id),
                    'label' => (string) $pta->titre,
                    'status' => (string) $pta->statut,
                    'service' => $pta->service?->libelle ?? '-',
                    'actions_count' => (int) $pta->actions_count,
                ])
                ->values()
                ->all(),
        ];
    }

    private function periodLabel(mixed $start, mixed $end): string
    {
        if ($start === null && $end === null) {
            return '-';
        }

        return ($start?->format('d/m/Y') ?? '-').' - '.($end?->format('d/m/Y') ?? '-');
    }
}
