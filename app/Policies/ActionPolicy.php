<?php

namespace App\Policies;

use App\Http\Controllers\Api\Concerns\AuthorizesPlanningScope;
use App\Models\Action;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Governance\DelegationService;
use App\Services\PlanningModificationLockService;

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
            || $user->isServiceOrUnitChief()
            || $user->isAgent()
            || $user->hasDelegatedPermission('action_review')
            || $user->hasDelegatedPermission('planning_write');
    }

    /**
     * Peut voir le détail d'une action.
     */
    public function view(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return true;
        }

        if ($user->isAgent()) {
            return (int) $action->responsable_id === (int) $user->id
                || $action->sousActions()->where('agent_id', $user->id)->exists();
        }

        if ((bool) $action->financement_requis
            && ($this->isDafFinanceReviewer($user) || $user->hasRole(User::ROLE_DG))
        ) {
            return true;
        }

        if ($user->hasGlobalReadAccess()) {
            return true;
        }

        $lockService = app(PlanningModificationLockService::class);
        if ($lockService->isUnlockReviewer($user) || $lockService->canGivePlanifAvis($user)) {
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

        return $this->canReadService(
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
        if (in_array((string) $action->statut_validation, [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
            ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION,
            ActionTrackingService::VALIDATION_VALIDEE_CONTROLE,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            return $user->hasRole(User::ROLE_ADMIN_FONCTIONNEL)
                || $user->hasPermission('planning.write.global');
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

    /** Compatibilité API historique : seul le responsable peut soumettre son exécution. */
    public function submitWeek(User $user, Action $action): bool
    {
        return $action->isResponsible($user);
    }

    /**
     * Peut valider/rejeter en tant que chef de service.
     */
    public function reviewByChef(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if ($user->isServiceOrUnitChief()
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
        if ($action->isResponsible($user)) {
            return false;
        }

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
    public function submitFinancing(User $user, Action $action): bool
    {
        return (bool) $action->financement_requis
            && $action->isResponsible($user);
    }

    public function reviewFinancingByDaf(User $user, Action $action): bool
    {
        return (bool) $action->financement_requis
            && ! $action->isResponsible($user)
            && $this->isDafFinanceReviewer($user);
    }

    public function reviewFinancingByDg(User $user, Action $action): bool
    {
        return (bool) $action->financement_requis
            && ! $action->isResponsible($user)
            && $user->hasRole(User::ROLE_DG);
    }

    public function requestDeadlineExtension(User $user, Action $action): bool
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            return false;
        }

        return $action->isResponsible($user)
            || ($user->isAgent() && $action->sousActions()->where('agent_id', $user->id)->exists());
    }

    public function reviewDeadlineExtensionByChef(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        if ($user->isServiceOrUnitChief()
            && ! $user->isPlanningControlChief()
            && (int) $user->direction_id === (int) $action->pta?->direction_id
            && (int) $user->service_id === (int) $action->pta?->service_id
        ) {
            return true;
        }

        return app(DelegationService::class)->canReviewServiceAction(
            $user,
            (int) $action->pta?->direction_id,
            (int) $action->pta?->service_id
        );
    }

    public function reviewDeadlineExtensionByDirector(User $user, Action $action): bool
    {
        if ($action->isResponsible($user) || ! $user->hasRole(User::ROLE_DIRECTION)) {
            return false;
        }

        return (int) $user->direction_id > 0
            && (int) $user->direction_id === (int) $action->pta?->direction_id;
    }

    public function reviewDeadlineExtensionFinal(User $user, Action $action): bool
    {
        if ($action->isResponsible($user)) {
            return false;
        }

        return $user->hasRole(User::ROLE_DG);
    }

    public function applyDeadlineExtension(User $user, Action $action): bool
    {
        return $this->reviewDeadlineExtensionFinal($user, $action);
    }

    public function reviewDeadlineExtensionByDg(User $user, Action $action): bool
    {
        return $this->reviewDeadlineExtensionFinal($user, $action);
    }

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

    private function isDafFinanceReviewer(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_DIRECTION) || $user->direction_id === null) {
            return false;
        }

        if ($user->relationLoaded('direction')) {
            return (string) ($user->direction?->code ?? '') === 'DAF';
        }

        return $user->direction()->where('code', 'DAF')->exists();
    }
}
