<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Pao;
use App\Models\Pas;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

trait AuthorizesPlanningScope
{
    protected function denyUnlessPlanningReader(User $user): void
    {
        if (
            $user->hasPermission('planning.read')
            || $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write')
        ) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessGlobalWriter(User $user): void
    {
        if ($user->hasGlobalWriteAccess()) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessStrategicWriter(User $user): void
    {
        if ($this->canWriteStrategicPlanning($user)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessWriteDirection(User $user, ?int $directionId): void
    {
        if ($this->canWriteDirection($user, $directionId)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessWriteService(User $user, ?int $directionId, ?int $serviceId): void
    {
        if ($this->canWriteService($user, $directionId, $serviceId)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessManagePao(User $user, ?int $directionId): void
    {
        if ($this->canManagePao($user, $directionId)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function denyUnlessManagePta(User $user, ?int $directionId, ?int $serviceId): void
    {
        if ($this->canManagePta($user, $directionId, $serviceId)) {
            return;
        }

        abort(403, 'Acces non autorise.');
    }

    protected function scopeByUserDirection(
        Builder|Relation $query,
        User $user,
        string $directionColumn,
        ?string $serviceColumn = null
    ): void {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $directionIds = $user->delegatedDirectionIds('planning_read');
        $directionIds = array_merge($directionIds, $user->delegatedDirectionIds('planning_write'));
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $directionIds[] = (int) $user->direction_id;
        }
        $directionIds = array_values(array_unique(array_filter($directionIds, static fn ($id): bool => (int) $id > 0)));

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

        if ($directionIds === [] && $serviceScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $uniqueServiceScopes = collect($serviceScopes)
            ->unique(fn (array $s): string => $s['direction_id'].'-'.$s['service_id'])
            ->values()
            ->all();

        $query->where(function (Builder $scopedQuery) use ($directionIds, $uniqueServiceScopes, $directionColumn, $serviceColumn, $query): void {
            if (! empty($directionIds)) {
                $scopedQuery->orWhereIn($directionColumn, $directionIds);
            }

            if (! empty($uniqueServiceScopes)) {
                if ($serviceColumn !== null) {
                    $scopedQuery->orWhere(function (Builder $inner) use ($uniqueServiceScopes, $directionColumn, $serviceColumn): void {
                        foreach ($uniqueServiceScopes as $scope) {
                            $inner->orWhere(function (Builder $pair) use ($scope, $directionColumn, $serviceColumn): void {
                                $pair->where($directionColumn, (int) $scope['direction_id'])
                                    ->where($serviceColumn, (int) $scope['service_id']);
                            });
                        }
                    });

                    if (method_exists($query->getModel(), 'objectifsOperationnels')) {
                        $scopedQuery->orWhereHas('objectifsOperationnels', function (Builder $objectiveQuery) use ($uniqueServiceScopes): void {
                            $objectiveQuery->where(function (Builder $inner) use ($uniqueServiceScopes): void {
                                foreach ($uniqueServiceScopes as $scope) {
                                    $inner->orWhere(function (Builder $pair) use ($scope): void {
                                        $pair->where('direction_id', (int) $scope['direction_id'])
                                            ->where('service_id', (int) $scope['service_id']);
                                    });
                                }
                            });
                        });
                    }
                } elseif (method_exists($query->getModel(), 'ptas')) {
                    $scopedQuery->orWhereHas('ptas', function (Builder $ptaQuery) use ($uniqueServiceScopes): void {
                        $ptaQuery->where(function (Builder $inner) use ($uniqueServiceScopes): void {
                            foreach ($uniqueServiceScopes as $scope) {
                                $inner->orWhere(function (Builder $pair) use ($scope): void {
                                    $pair->where('direction_id', (int) $scope['direction_id'])
                                        ->where('service_id', (int) $scope['service_id']);
                                });
                            }
                        });
                    });
                }
            }
        });
    }

    protected function canReadDirection(User $user, ?int $directionId): bool
    {
        if (! $this->canReadPlanningScope($user)) {
            return false;
        }

        if ($this->canReadAllPlanning($user)) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) || $this->hasOwnServicePlanningScope($user)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_read') || $user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        return false;
    }

    protected function canReadService(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if (! $this->canReadPlanningScope($user)) {
            return false;
        }

        if ($this->canReadAllPlanning($user)) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            return (int) $user->direction_id === $directionId
                && (int) $user->service_id === $serviceId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_read') || $user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        if ($user->hasDelegatedServiceScope($directionId, $serviceId, 'planning_read') || $user->hasDelegatedServiceScope($directionId, $serviceId, 'planning_write')) {
            return true;
        }

        return false;
    }

    protected function canWriteDirection(User $user, ?int $directionId): bool
    {
        if ($this->canWriteAllPlanning($user)) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->hasPermission('planning.write.direction')) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        return false;
    }

    protected function canWriteStrategicPlanning(User $user): bool
    {
        if ($user->isServiceOrUnitChief()) {
            return false;
        }

        return $user->hasPermission('planning.strategic.manage');
    }

    protected function canManagePao(User $user, ?int $directionId): bool
    {
        if ($this->canWriteAllPlanning($user)) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->hasPermission('planning.write.direction')) {
            return (int) $user->direction_id === $directionId;
        }

        return $user->hasDelegatedDirectionScope($directionId, 'planning_write');
    }

    protected function canManagePta(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($this->canWriteAllPlanning($user) || $this->canWriteStrategicPlanning($user)) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasPermission('planning.write.service')) {
            return (int) $user->direction_id === $directionId
                && (int) $user->service_id === $serviceId;
        }

        return $user->hasDelegatedServiceScope($directionId, $serviceId, 'planning_write');
    }

    protected function canWriteService(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($this->canWriteAllPlanning($user)) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasPermission('planning.write.service')) {
            return (int) $user->direction_id === $directionId
                && (int) $user->service_id === $serviceId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        if ($user->hasDelegatedServiceScope($directionId, $serviceId, 'planning_write')) {
            return true;
        }

        return false;
    }

    /**
     * Restreint la liste des PAS au perimetre de l'utilisateur :
     *   - lecteurs globaux (super admin, DG, planification, SCIQ, cabinet...) :
     *     visibilite totale
     *   - directeurs (ROLE_DIRECTION) : PAS contenant au moins un PAO de leur direction
     *   - chefs de service / agents avec perimetre service : PAS contenant au moins
     *     un PTA ou un objectif operationnel de leur service
     *   - utilisateurs avec delegation : meme logique sur les directions/services delegues
     *   - aucun perimetre = aucun PAS visible
     */
    protected function scopePasByUser(Builder|Relation $query, User $user): void
    {
        if (! $this->canReadPlanningScope($user)) {
            $query->whereRaw('1 = 0');

            return;
        }

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

        if ($directionIds === [] && $serviceScopes === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $uniqueServiceScopes = collect($serviceScopes)
            ->unique(fn (array $s): string => $s['direction_id'].'-'.$s['service_id'])
            ->values()
            ->all();

        $query->where(function (Builder $pasQuery) use ($directionIds, $uniqueServiceScopes): void {
            if (! empty($directionIds)) {
                $pasQuery->orWhereHas('paos', fn (Builder $paoQuery) => $paoQuery->whereIn('direction_id', $directionIds));
            }

            foreach ($uniqueServiceScopes as $scope) {
                $pasQuery->orWhereHas('paos', function (Builder $paoQuery) use ($scope): void {
                    $paoQuery->where('direction_id', (int) $scope['direction_id'])
                        ->where(function (Builder $inner) use ($scope): void {
                            // PAO directement rattache au service de l'utilisateur,
                            // OU contenant un PTA / objectif operationnel du service.
                            $inner->where('service_id', (int) $scope['service_id'])
                                ->orWhereHas('ptas', fn (Builder $ptaQuery) => $ptaQuery->where('service_id', (int) $scope['service_id']))
                                ->orWhereHas('objectifsOperationnels', fn (Builder $ooQuery) => $ooQuery->where('service_id', (int) $scope['service_id']));
                        });
                });
            }
        });
    }

    protected function canReadPas(User $user, ?int $pasId): bool
    {
        if (! $this->canReadPlanningScope($user)) {
            return false;
        }

        if ($pasId === null) {
            return false;
        }

        $query = Pas::query()->whereKey((int) $pasId);
        $this->scopePasByUser($query, $user);

        return $query->exists();
    }

    protected function canReadPao(User $user, ?int $paoId, ?int $directionId): bool
    {
        if (! $this->canReadPlanningScope($user)) {
            return false;
        }

        if ($this->canReadAllPlanning($user)) {
            return true;
        }

        if ($paoId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return $directionId !== null && (int) $user->direction_id === (int) $directionId;
        }

        if ($this->hasOwnServicePlanningScope($user)) {
            return Pao::query()
                ->whereKey((int) $paoId)
                ->where(function (Builder $query) use ($user): void {
                    $query->where('service_id', (int) $user->service_id)
                        ->orWhereHas('objectifsOperationnels', fn (Builder $q) => $q->where('service_id', (int) $user->service_id))
                        ->orWhereHas('ptas', fn (Builder $q) => $q->where('service_id', (int) $user->service_id));
                })
                ->exists();
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_read') || $user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        if ($directionId !== null) {
            foreach (array_merge($user->delegatedServiceScopes('planning_read'), $user->delegatedServiceScopes('planning_write')) as $scope) {
                if ($scope['direction_id'] !== (int) $directionId) {
                    continue;
                }

                return Pao::query()
                    ->whereKey((int) $paoId)
                    ->where(function (Builder $query) use ($scope): void {
                        $query->where('service_id', (int) $scope['service_id'])
                            ->orWhereHas('objectifsOperationnels', fn (Builder $q) => $q->where('service_id', (int) $scope['service_id']))
                            ->orWhereHas('ptas', fn (Builder $q) => $q->where('service_id', (int) $scope['service_id']));
                    })
                    ->exists();
            }
        }

        return false;
    }

    protected function scopePlanningActions(Builder|Relation $query, User $user): void
    {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    protected function scopePlanningKpis(Builder|Relation $query, User $user): void
    {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $query->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    protected function scopePlanningKpiMesures(Builder|Relation $query, User $user): void
    {
        if ($this->canReadAllPlanning($user)) {
            return;
        }

        $query->whereHas('kpi.action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    protected function scopePlanningUsers(Builder|Relation $query, User $user): void
    {
        $this->scopeByUserDirection($query, $user, 'direction_id', 'service_id');
    }

    protected function canReadPlanningScope(User $user): bool
    {
        return $user->hasPermission('planning.read')
            || $user->hasDelegatedPermission('planning_read')
            || $user->hasDelegatedPermission('planning_write');
    }

    protected function canReadAllPlanning(User $user): bool
    {
        if ($user->isServiceOrUnitChief()) {
            return false;
        }

        return $user->hasGlobalReadAccess() && $user->hasPermission('planning.read');
    }

    protected function canWriteAllPlanning(User $user): bool
    {
        if ($user->isServiceOrUnitChief()) {
            return false;
        }

        return $user->hasPermission('planning.write.global');
    }

    protected function hasOwnServicePlanningScope(User $user): bool
    {
        if ($user->direction_id === null || $user->service_id === null) {
            return false;
        }

        if ($user->hasGlobalReadAccess()) {
            return false;
        }

        return $user->hasPermission('planning.write.service')
            || $user->hasRole(
                User::ROLE_SERVICE,
                User::ROLE_CHEF_UNITE,
                User::ROLE_CHEF_PLANIFICATION,
                User::ROLE_CHEF_UNITE_SCIQ,
                User::ROLE_CHEF_UNITE_DGA,
                User::ROLE_CHEF_UNITE_CABINET,
                User::ROLE_CHEF_UNITE_UCAS,
                User::ROLE_UCAS
            );
    }
}
