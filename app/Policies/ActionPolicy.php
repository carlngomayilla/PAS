<?php

namespace App\Policies;

use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Governance\DelegationService;
use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;

class ActionPolicy
{
    use AuthorizesPlanningScope;

    /**
     * Peut voir la liste des actions (index).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasGlobalReadAccess()
            || $user->hasRole(User::ROLE_DIRECTION, User::ROLE_SERVICE)
            || $user->isAgent()
            || $user->hasDelegatedPermission('action_review')
            || $user->hasDelegatedPermission('planning_write');
    }

    /**
     * Peut voir le détail d'une action.
     */
    public function view(User $user, Action $action): bool
    {
        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id;
        }

        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        $delegationService = app(DelegationService::class);

        if ($delegationService->canReviewServiceAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        )) {
            return true;
        }

        if ($delegationService->canReviewDirectionAction($user, (int) $action->pta?->direction_id)) {
            return true;
        }

        if ($user->hasDelegatedDirectionScope((int) $action->pta?->direction_id, 'planning_write')) {
            return true;
        }

        return $this->canWriteService(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    /**
     * Peut créer une action (chef de service ou direction sur ce service).
     */
    public function create(User $user, Action $action): bool
    {
        return $this->canManageAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    /**
     * Peut modifier une action.
     */
    public function update(User $user, Action $action): bool
    {
        // Sécurité supplémentaire : On ne modifie pas une action déjà validée par la direction
        if ($action->statut_validation === ActionTrackingService::VALIDATION_VALIDEE_DIRECTION) {
            return $user->hasRole(User::ROLE_ADMIN) || $user->hasRole(User::ROLE_PLANIFICATION);
        }

        return $this->canManageAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    /**
     * Peut supprimer une action.
     */
    public function delete(User $user, Action $action): bool
    {
        return $this->canManageAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    /**
     * Peut saisir le suivi hebdomadaire (l'agent responsable uniquement).
     */
    public function submitWeek(User $user, Action $action): bool
    {
        return $user->isAgent()
            && (int) $action->responsable_id === (int) $user->id;
    }

    /**
     * Peut soumettre la clôture (l'agent responsable).
     */
    public function submitClosure(User $user, Action $action): bool
    {
        return $user->hasRole(User::ROLE_AGENT)
            && (int) $action->responsable_id === (int) $user->id;
    }

    /**
     * Peut valider/rejeter en tant que chef de service.
     */
    public function reviewByChef(User $user, Action $action): bool
    {
        if ($user->hasRole(User::ROLE_SERVICE)
            && $this->canManageAction(
                $user,
                (int) $action->pta?->direction_id,
                (int) $action->pta?->service_id
            )
        ) {
            return true;
        }

        return app(DelegationService::class)->canReviewServiceAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    /**
     * Peut valider/rejeter en tant que direction.
     */
    public function reviewByDirection(User $user, Action $action): bool
    {
        if ($user->hasRole(User::ROLE_DIRECTION) && $user->direction_id !== null) {
            return (int) $user->direction_id === (int) $action->pta?->direction_id;
        }

        return app(DelegationService::class)->canReviewDirectionAction(
            $user,
            (int) $action->pta?->direction_id
        );
    }

    /**
     * Peut laisser un commentaire (tout utilisateur ayant accès en lecture).
     */
    public function comment(User $user, Action $action): bool
    {
        return $this->view($user, $action);
    }

    // -------------------------------------------------------------------------
    // Méthodes internes partagées
    // -------------------------------------------------------------------------

    private function canManageAction(User $user, ?int $directionId, ?int $serviceId): bool
    {
        return ! $user->isAgent() && $this->canWriteService($user, $directionId, $serviceId);
    }
}
