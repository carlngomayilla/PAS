<?php

namespace App\Services\Alerting;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Governance\DelegationService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class AlertRoutingService
{
    public function __construct(
        private readonly DelegationService $delegationService
    ) {
    }

    /**
     * @return Collection<int, User>
     */
    public function recipientsForActionLog(ActionLog $log): Collection
    {
        $log->loadMissing('action:id,pta_id,responsable_id', 'action.pta:id,direction_id,service_id');

        $action = $log->action;
        if (! $action instanceof Action) {
            return collect();
        }

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);
        $targetRole = strtolower(trim((string) ($log->cible_role ?? '')));
        $serviceRecipients = $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]),
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );
        $directionRecipients = $this->mergeRecipients(
            $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
            $this->delegationService->delegatedDirectionReviewers($directionId)
        );
        $planificationRecipients = $this->globalUsers([User::ROLE_ADMIN, User::ROLE_PLANIFICATION]);
        $dgRecipients = $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG]);

        if (in_array($targetRole, ['responsable', 'agent'], true)) {
            return $this->mergeRecipients(
                $this->agentRecipient($action),
                $this->globalUsers([User::ROLE_ADMIN])
            );
        }

        if (in_array($targetRole, ['chef_service', 'service'], true)) {
            return $this->mergeRecipients(
                $serviceRecipients,
                $this->globalUsers([User::ROLE_ADMIN])
            );
        }

        $chainRecipients = $this->mergeRecipients($serviceRecipients, $directionRecipients);
        $chainRecipients = $this->mergeRecipients($chainRecipients, $planificationRecipients);

        if ($targetRole === 'direction') {
            return $chainRecipients;
        }

        if (in_array($targetRole, ['planification', 'dg'], true)) {
            return $this->mergeRecipients($chainRecipients, $dgRecipients);
        }

        return $this->mergeRecipients($chainRecipients, $dgRecipients);
    }

    public function userCanSeeActionLog(User $user, ActionLog $log): bool
    {
        if ($user->hasRole(User::ROLE_ADMIN)) {
            return true;
        }

        return $this->recipientsForActionLog($log)
            ->contains(fn (User $recipient): bool => (int) $recipient->id === (int) $user->id);
    }

    /**
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function globalUsers(array $roles): EloquentCollection
    {
        return User::query()
            ->whereIn('role', $roles)
            ->get();
    }

    /**
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function serviceUsers(int $directionId, int $serviceId, array $roles): EloquentCollection
    {
        if ($directionId <= 0 || $serviceId <= 0) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereIn('role', $roles)
            ->where('direction_id', $directionId)
            ->where('service_id', $serviceId)
            ->get();
    }

    /**
     * @param int|array<int, int> $directionIds
     * @param array<int, string> $roles
     * @return EloquentCollection<int, User>
     */
    private function directionUsers(int|array $directionIds, array $roles): EloquentCollection
    {
        $ids = is_array($directionIds) ? $directionIds : [$directionIds];
        $ids = array_values(array_filter($ids, static fn (int $value): bool => $value > 0));

        if ($ids === []) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereIn('role', $roles)
            ->whereIn('direction_id', $ids)
            ->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    private function agentRecipient(Action $action): EloquentCollection
    {
        if ($action->responsable_id === null) {
            return new EloquentCollection();
        }

        return User::query()
            ->whereKey((int) $action->responsable_id)
            ->get();
    }

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $first
     * @param Collection<int, User>|EloquentCollection<int, User> $second
     * @return Collection<int, User>
     */
    private function mergeRecipients(Collection|EloquentCollection $first, Collection|EloquentCollection $second): Collection
    {
        return $first
            ->concat($second)
            ->filter(static fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();
    }
}
