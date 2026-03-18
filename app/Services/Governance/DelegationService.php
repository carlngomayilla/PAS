<?php

namespace App\Services\Governance;

use App\Models\Delegation;
use App\Models\User;
use Illuminate\Support\Collection;

class DelegationService
{
    /**
     * @return Collection<int, Delegation>
     */
    public function activeDelegationsFor(User $user, ?string $permission = null): Collection
    {
        return $user->activeDelegations($permission);
    }

    public function canReviewServiceAction(User $user, ?int $directionId, ?int $serviceId): bool
    {
        return $user->hasDelegatedServiceScope($directionId, $serviceId, 'action_review');
    }

    public function canReviewDirectionAction(User $user, ?int $directionId): bool
    {
        return $user->hasDelegatedDirectionScope($directionId, 'action_review');
    }

    /**
     * @return Collection<int, User>
     */
    public function delegatedServiceReviewers(int $directionId, int $serviceId): Collection
    {
        if ($directionId <= 0 || $serviceId <= 0) {
            return collect();
        }

        $delegateIds = Delegation::query()
            ->active()
            ->where('role_scope', Delegation::SCOPE_SERVICE)
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->get()
            ->filter(static fn (Delegation $delegation): bool => $delegation->hasPermission('action_review'))
            ->pluck('delegue_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($delegateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $delegateIds->all())
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    public function delegatedDirectionReviewers(int $directionId): Collection
    {
        if ($directionId <= 0) {
            return collect();
        }

        $delegateIds = Delegation::query()
            ->active()
            ->where('role_scope', Delegation::SCOPE_DIRECTION)
            ->where('direction_id', $directionId)
            ->get()
            ->filter(static fn (Delegation $delegation): bool => $delegation->hasPermission('action_review'))
            ->pluck('delegue_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($delegateIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $delegateIds->all())
            ->get();
    }
}
