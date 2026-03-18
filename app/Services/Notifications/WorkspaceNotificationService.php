<?php

namespace App\Services\Notifications;

use App\Models\Action;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\User;
use App\Services\Governance\DelegationService;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class WorkspaceNotificationService
{
    public function __construct(
        private readonly DelegationService $delegationService
    ) {
    }

    public function notifyActionAssigned(Action $action, ?User $actor = null): void
    {
        if ($action->responsable_id === null) {
            return;
        }

        /** @var EloquentCollection<int, User> $users */
        $users = User::query()
            ->whereKey((int) $action->responsable_id)
            ->get();

        $this->dispatch(
            $users,
            [
                'title' => 'Nouvelle action attribuee',
                'message' => sprintf('L action "%s" vous a ete attribuee.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'bolt',
                'status' => 'info',
                'priority' => 'normal',
            ],
            null
        );
    }

    public function notifyActionSubmittedToChef(Action $action, ?User $actor = null): void
    {
        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $users = $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]);
        $users = $this->mergeRecipients(
            $users,
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );

        $this->dispatch(
            $users,
            [
                'title' => 'Action soumise pour validation',
                'message' => sprintf('L action "%s" attend votre evaluation.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'check-circle',
                'status' => 'info',
                'priority' => 'high',
            ],
            $actor?->id
        );
    }

    public function notifyActionReviewedByChef(Action $action, bool $approved, ?User $actor = null): void
    {
        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $agentRecipients = $this->agentRecipient($action);

        if ($approved) {
            $directionRecipients = $this->mergeRecipients(
                $this->directionUsers($directionId, [User::ROLE_DIRECTION]),
                $this->delegationService->delegatedDirectionReviewers($directionId)
            );
            $directionRecipients = $this->mergeRecipients(
                $directionRecipients,
                $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION])
            );

            $this->dispatch(
                $directionRecipients,
                [
                    'title' => 'Action validee par le chef',
                    'message' => sprintf('L action "%s" est prete pour validation direction.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'arrow-up-right',
                    'status' => 'info',
                    'priority' => 'high',
                ],
                $actor?->id
            );

            $this->dispatch(
                $agentRecipients,
                [
                    'title' => 'Action transmise a la direction',
                    'message' => sprintf('Votre action "%s" a ete validee par le chef de service.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'check-circle',
                    'status' => 'success',
                    'priority' => 'normal',
                ],
                $actor?->id
            );

            return;
        }

        $this->dispatch(
            $agentRecipients,
            [
                'title' => 'Action rejetee par le chef',
                'message' => sprintf('Votre action "%s" a ete rejetee. Consultez le motif.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'x-circle',
                'status' => 'warning',
                'priority' => 'high',
            ],
            $actor?->id
        );
    }

    public function notifyActionReviewedByDirection(Action $action, bool $approved, ?User $actor = null): void
    {
        $action->loadMissing('pta:id,direction_id,service_id');

        $directionId = (int) ($action->pta?->direction_id ?? 0);
        $serviceId = (int) ($action->pta?->service_id ?? 0);

        $serviceRecipients = $this->mergeRecipients(
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE]),
            $this->delegationService->delegatedServiceReviewers($directionId, $serviceId)
        );
        $agentRecipients = $this->agentRecipient($action);

        if ($approved) {
            $targets = $this->mergeRecipients($serviceRecipients, $agentRecipients);
            $targets = $this->mergeRecipients(
                $targets,
                $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET])
            );

            $this->dispatch(
                $targets,
                [
                    'title' => 'Action validee par la direction',
                    'message' => sprintf('L action "%s" est officiellement comptabilisee.', (string) $action->libelle),
                    'module' => 'actions',
                    'entity_type' => 'action',
                    'entity_id' => $action->id,
                    'url' => route('workspace.actions.suivi', $action),
                    'icon' => 'badge-check',
                    'status' => 'success',
                    'priority' => 'normal',
                ],
                $actor?->id
            );

            return;
        }

        $targets = $this->mergeRecipients($serviceRecipients, $agentRecipients);
        $this->dispatch(
            $targets,
            [
                'title' => 'Action rejetee par la direction',
                'message' => sprintf('L action "%s" doit etre corrigee puis resoumise.', (string) $action->libelle),
                'module' => 'actions',
                'entity_type' => 'action',
                'entity_id' => $action->id,
                'url' => route('workspace.actions.suivi', $action),
                'icon' => 'x-circle',
                'status' => 'warning',
                'priority' => 'high',
            ],
            $actor?->id
        );
    }

    public function notifyPasStatus(Pas $pas, string $event, ?User $actor = null): void
    {
        $pas->loadMissing('directions:id');
        $directionIds = $pas->directions
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionIds, [User::ROLE_DIRECTION, User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PAS', (string) $pas->titre, $event);

        $this->dispatch(
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pas',
                'entity_type' => 'pas',
                'entity_id' => $pas->id,
                'url' => route('workspace.pas.index'),
                'icon' => 'target',
                'status' => $status,
                'priority' => 'normal',
            ],
            $actor?->id
        );
    }

    public function notifyPaoStatus(Pao $pao, string $event, ?User $actor = null): void
    {
        $directionId = (int) $pao->direction_id;
        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionId, [User::ROLE_DIRECTION, User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PAO', (string) $pao->titre, $event);

        $this->dispatch(
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pao',
                'entity_type' => 'pao',
                'entity_id' => $pao->id,
                'url' => route('workspace.pao.index'),
                'icon' => 'folder',
                'status' => $status,
                'priority' => 'normal',
            ],
            $actor?->id
        );
    }

    public function notifyPtaStatus(Pta $pta, string $event, ?User $actor = null): void
    {
        $directionId = (int) $pta->direction_id;
        $serviceId = (int) $pta->service_id;

        $targets = $this->mergeRecipients(
            $this->globalUsers([User::ROLE_ADMIN, User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_CABINET]),
            $this->directionUsers($directionId, [User::ROLE_DIRECTION])
        );
        $targets = $this->mergeRecipients(
            $targets,
            $this->serviceUsers($directionId, $serviceId, [User::ROLE_SERVICE])
        );

        [$title, $message, $status] = $this->resolveStatusPayload('PTA', (string) $pta->titre, $event);

        $this->dispatch(
            $targets,
            [
                'title' => $title,
                'message' => $message,
                'module' => 'pta',
                'entity_type' => 'pta',
                'entity_id' => $pta->id,
                'url' => route('workspace.pta.index'),
                'icon' => 'calendar',
                'status' => $status,
                'priority' => 'normal',
            ],
            $actor?->id
        );
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveStatusPayload(string $moduleLabel, string $titleValue, string $event): array
    {
        return match ($event) {
            'submitted' => [
                sprintf('%s soumis', $moduleLabel),
                sprintf('%s "%s" a ete soumis pour validation.', $moduleLabel, $titleValue),
                'info',
            ],
            'approved' => [
                sprintf('%s valide', $moduleLabel),
                sprintf('%s "%s" a ete valide.', $moduleLabel, $titleValue),
                'success',
            ],
            'locked' => [
                sprintf('%s verrouille', $moduleLabel),
                sprintf('%s "%s" a ete verrouille.', $moduleLabel, $titleValue),
                'info',
            ],
            'reopened' => [
                sprintf('%s remis en brouillon', $moduleLabel),
                sprintf('%s "%s" a ete remis en brouillon.', $moduleLabel, $titleValue),
                'warning',
            ],
            default => [
                sprintf('%s mis a jour', $moduleLabel),
                sprintf('%s "%s" a ete mis a jour.', $moduleLabel, $titleValue),
                'info',
            ],
        };
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

    /**
     * @param Collection<int, User>|EloquentCollection<int, User> $users
     * @param array<string, mixed> $payload
     */
    private function dispatch(Collection|EloquentCollection $users, array $payload, ?int $excludeUserId = null): void
    {
        $targets = $users
            ->filter(static fn ($user): bool => $user instanceof User)
            ->unique('id')
            ->values();

        if ($excludeUserId !== null) {
            $targets = $targets
                ->reject(static fn (User $user): bool => (int) $user->id === (int) $excludeUserId)
                ->values();
        }

        if ($targets->isEmpty()) {
            return;
        }

        Notification::send($targets, new WorkspaceModuleNotification($payload));
    }
}
