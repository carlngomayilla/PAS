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
            $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
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
        if ($user->hasGlobalReadAccess()) {
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

        $query->where(function (Builder $scopedQuery) use ($directionIds, $serviceScopes, $directionColumn, $serviceColumn, $query): void {
            foreach ($directionIds as $directionId) {
                $scopedQuery->orWhere($directionColumn, (int) $directionId);
            }

            foreach ($serviceScopes as $scope) {
                if ($serviceColumn !== null) {
                    $scopedQuery->orWhere(function (Builder $subQuery) use ($directionColumn, $serviceColumn, $scope): void {
                        $subQuery
                            ->where($directionColumn, (int) $scope['direction_id'])
                            ->where($serviceColumn, (int) $scope['service_id']);
                    });

                    continue;
                }

                if (method_exists($query->getModel(), 'ptas')) {
                    $scopedQuery->orWhereHas('ptas', function (Builder $ptaQuery) use ($scope): void {
                        $ptaQuery
                            ->where('direction_id', (int) $scope['direction_id'])
                            ->where('service_id', (int) $scope['service_id']);
                    });
                }
            }
        });
    }

    protected function canReadDirection(User $user, ?int $directionId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_read') || $user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        return false;
    }

    protected function canReadService(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
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
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasDelegatedDirectionScope($directionId, 'planning_write')) {
            return true;
        }

        return false;
    }

    protected function canWriteStrategicPlanning(User $user): bool
    {
        return $user->hasGlobalWriteAccess() || $user->hasRole(User::ROLE_CABINET);
    }

    protected function canManagePao(User $user, ?int $directionId): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        if ($directionId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        return $user->hasDelegatedDirectionScope($directionId, 'planning_write');
    }

    protected function canManagePta(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
            return (int) $user->direction_id === $directionId
                && (int) $user->service_id === $serviceId;
        }

        return $user->hasDelegatedServiceScope($directionId, $serviceId, 'planning_write');
    }

    protected function canWriteService(User $user, ?int $directionId, ?int $serviceId): bool
    {
        if ($user->hasGlobalWriteAccess()) {
            return true;
        }

        if ($directionId === null || $serviceId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return (int) $user->direction_id === $directionId;
        }

        if ($user->hasRole(User::ROLE_SERVICE)) {
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

    protected function scopePasByUser(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            $query->whereHas('directions', fn (Builder $q) => $q->whereKey((int) $user->direction_id));
            return;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $serviceId = (int) $user->service_id;
            $query->where(function (Builder $scopedQuery) use ($serviceId): void {
                $scopedQuery
                    ->whereHas('paos', fn (Builder $q) => $q->where('service_id', $serviceId))
                    ->orWhereHas('paos.ptas', fn (Builder $q) => $q->where('service_id', $serviceId));
            });
            return;
        }

        $delegatedDirectionIds = array_merge(
            $user->delegatedDirectionIds('planning_read'),
            $user->delegatedDirectionIds('planning_write')
        );
        $delegatedServiceScopes = array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write')
        );

        if ($delegatedDirectionIds !== [] || $delegatedServiceScopes !== []) {
            $query->where(function (Builder $scopedQuery) use ($delegatedDirectionIds, $delegatedServiceScopes): void {
                foreach ($delegatedDirectionIds as $directionId) {
                    $scopedQuery->orWhereHas('directions', fn (Builder $q) => $q->whereKey((int) $directionId));
                }
                foreach ($delegatedServiceScopes as $scope) {
                    $scopedQuery->orWhere(function (Builder $serviceQuery) use ($scope): void {
                        $serviceQuery
                            ->whereHas('paos', fn (Builder $q) => $q
                                ->where('direction_id', (int) $scope['direction_id'])
                                ->where('service_id', (int) $scope['service_id']))
                            ->orWhereHas('paos.ptas', fn (Builder $q) => $q
                                ->where('direction_id', (int) $scope['direction_id'])
                                ->where('service_id', (int) $scope['service_id']));
                    });
                }
            });

            return;
        }

        $query->whereRaw('1 = 0');
    }

    protected function canReadPas(User $user, ?int $pasId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($pasId === null) {
            return false;
        }

        $query = Pas::query()->whereKey((int) $pasId);

        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return (clone $query)
                ->whereHas('directions', fn (Builder $q) => $q->whereKey((int) $user->direction_id))
                ->exists();
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            $serviceId = (int) $user->service_id;

            return (clone $query)
                ->where(function (Builder $scopedQuery) use ($serviceId): void {
                    $scopedQuery
                        ->whereHas('paos', fn (Builder $q) => $q->where('service_id', $serviceId))
                        ->orWhereHas('paos.ptas', fn (Builder $q) => $q->where('service_id', $serviceId));
                })
                ->exists();
        }

        if ($user->delegatedDirectionIds('planning_read') !== [] || $user->delegatedDirectionIds('planning_write') !== []) {
            $directionIds = array_merge(
                $user->delegatedDirectionIds('planning_read'),
                $user->delegatedDirectionIds('planning_write')
            );

            if ((clone $query)->whereHas('directions', fn (Builder $q) => $q->whereIn('directions.id', $directionIds))->exists()) {
                return true;
            }
        }

        $serviceScopes = array_merge(
            $user->delegatedServiceScopes('planning_read'),
            $user->delegatedServiceScopes('planning_write')
        );
        foreach ($serviceScopes as $scope) {
            if ((clone $query)
                ->where(function (Builder $scopedQuery) use ($scope): void {
                    $scopedQuery
                        ->whereHas('paos', fn (Builder $q) => $q
                            ->where('direction_id', (int) $scope['direction_id'])
                            ->where('service_id', (int) $scope['service_id']))
                        ->orWhereHas('paos.ptas', fn (Builder $q) => $q
                            ->where('direction_id', (int) $scope['direction_id'])
                            ->where('service_id', (int) $scope['service_id']));
                })
                ->exists()
            ) {
                return true;
            }
        }

        return false;
    }

    protected function canReadPao(User $user, ?int $paoId, ?int $directionId): bool
    {
        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        if ($paoId === null) {
            return false;
        }

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return $directionId !== null && (int) $user->direction_id === (int) $directionId;
        }

        if ($user->hasRole(User::ROLE_SERVICE) && $user->service_id !== null) {
            return Pao::query()
                ->whereKey((int) $paoId)
                ->where(function (Builder $query) use ($user): void {
                    $query->where('service_id', (int) $user->service_id)
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
                            ->orWhereHas('ptas', fn (Builder $q) => $q->where('service_id', (int) $scope['service_id']));
                    })
                    ->exists();
            }
        }

        return false;
    }

    protected function scopePlanningActions(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $query->whereHas('pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    protected function scopePlanningKpis(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
            return;
        }

        $query->whereHas('action.pta', function (Builder $ptaQuery) use ($user): void {
            $this->scopeByUserDirection($ptaQuery, $user, 'direction_id', 'service_id');
        });
    }

    protected function scopePlanningKpiMesures(Builder|Relation $query, User $user): void
    {
        if ($user->hasGlobalReadAccess()) {
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
}
