<?php

namespace App\Services\Dashboard;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class DashboardAccessService
{
    use AuthorizesPlanningScope;

    public function canReadDashboard(User $user): bool
    {
        return $user->hasPermission('planning.read')
            || $user->hasCrossOrganizationDashboardAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent();
    }

    /**
     * @throws AuthorizationException
     */
    public function authorizeFilterScope(User $user, DashboardFilterData $filters): void
    {
        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if (! $this->canUseOrganizationFilters($user, $filters)
            || ($user->isAgent()
                && $filters->responsableId !== null
                && (int) $user->id !== $filters->responsableId)) {
            throw new AuthorizationException('Le filtre sélectionné est hors de votre périmètre.');
        }
    }

    public function responsableIsRelevant(User $user, DashboardFilterData $filters): bool
    {
        if ($filters->responsableId === null) {
            return true;
        }

        $query = Action::query()
            ->where(function (Builder $responsabilityQuery) use ($filters): void {
                $responsabilityQuery
                    ->where('responsable_id', $filters->responsableId)
                    ->orWhereHas('responsables', fn (Builder $query): Builder => $query->whereKey($filters->responsableId))
                    ->orWhereHas('sousActions', fn (Builder $query): Builder => $query->where('agent_id', $filters->responsableId));
            });

        if ($filters->exercice !== null) {
            $year = $filters->exercice;
            $query->where(function (Builder $yearQuery) use ($year): void {
                $yearQuery
                    ->whereHas('pta.pao', fn (Builder $paoQuery): Builder => $paoQuery->where('annee', $year))
                    ->orWhere(function (Builder $dateQuery) use ($year): void {
                        $dateQuery->whereDoesntHave('pta.pao')
                            ->whereBetween('date_debut', [$year.'-01-01', $year.'-12-31']);
                    });
            });
        }

        if ($filters->directionId !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery->where('direction_id', $filters->directionId));
        }

        if ($filters->serviceId !== null) {
            $query->whereHas('pta', fn (Builder $ptaQuery): Builder => $ptaQuery->where('service_id', $filters->serviceId));
        }

        $this->scopeActionsToUser($query, $user);

        return $query->exists();
    }

    private function canUseOrganizationFilters(User $user, DashboardFilterData $filters): bool
    {
        if ($filters->directionId !== null
            && $filters->serviceId === null
            && ! $this->canReadDirection($user, $filters->directionId)
            && ! $this->hasDelegatedDirection($user, $filters->directionId)
            && ! $this->hasDelegatedServiceInDirection($user, $filters->directionId)
            && ! $this->isAgentOwnDirection($user, $filters->directionId)) {
            return false;
        }

        if ($filters->serviceId === null) {
            return true;
        }

        return $this->canReadService($user, $filters->directionId, $filters->serviceId)
            || $this->hasDelegatedDirection($user, (int) $filters->directionId)
            || $user->hasDelegatedServiceScope($filters->directionId, $filters->serviceId, 'planning_read')
            || $user->hasDelegatedServiceScope($filters->directionId, $filters->serviceId, 'planning_write')
            || $this->isAgentOwnService($user, $filters->directionId, $filters->serviceId);
    }

    /**
     * @param  Builder<Action>  $query
     */
    private function scopeActionsToUser(Builder $query, User $user): void
    {
        if ($user->hasCrossOrganizationDashboardAccess()) {
            return;
        }

        if ($user->isAgent()) {
            $query->where(function (Builder $responsabilityQuery) use ($user): void {
                $responsabilityQuery
                    ->where('responsable_id', (int) $user->id)
                    ->orWhereHas('responsables', fn (Builder $builder): Builder => $builder->whereKey((int) $user->id))
                    ->orWhereHas('sousActions', fn (Builder $builder): Builder => $builder->where('agent_id', (int) $user->id));

                if ($this->hasDelegatedPlanningScope($user)) {
                    $responsabilityQuery->orWhereHas('pta', function (Builder $ptaQuery) use ($user): void {
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

    private function isAgentOwnDirection(User $user, int $directionId): bool
    {
        return $user->isAgent()
            && $user->direction_id !== null
            && (int) $user->direction_id === $directionId;
    }

    private function hasDelegatedServiceInDirection(User $user, int $directionId): bool
    {
        return collect([
            ...$user->delegatedServiceScopes('planning_read'),
            ...$user->delegatedServiceScopes('planning_write'),
        ])->contains(fn (array $scope): bool => $scope['direction_id'] === $directionId);
    }

    private function hasDelegatedDirection(User $user, int $directionId): bool
    {
        return $user->hasDelegatedDirectionScope($directionId, 'planning_read')
            || $user->hasDelegatedDirectionScope($directionId, 'planning_write');
    }

    private function isAgentOwnService(User $user, ?int $directionId, int $serviceId): bool
    {
        return $this->isAgentOwnDirection($user, (int) $directionId)
            && $user->service_id !== null
            && (int) $user->service_id === $serviceId;
    }

    private function hasDelegatedPlanningScope(User $user): bool
    {
        return $user->delegatedDirectionIds('planning_read') !== []
            || $user->delegatedDirectionIds('planning_write') !== []
            || $user->delegatedServiceScopes('planning_read') !== []
            || $user->delegatedServiceScopes('planning_write') !== [];
    }
}
