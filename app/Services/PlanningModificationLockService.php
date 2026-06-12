<?php

namespace App\Services;

use App\Models\Action;
use App\Models\Pas;
use App\Models\PlanningUnlockRequest;
use App\Models\Pta;
use App\Models\User;
use App\Notifications\WorkspaceModuleNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class PlanningModificationLockService
{
    /**
     * @var array<class-string<Model>, string>
     */
    private const MODULES = [
        Pas::class => 'pas',
        Pta::class => 'pta',
        Action::class => 'action',
    ];

    public function isLocked(Model $target): bool
    {
        if (! $this->supports($target)) {
            return false;
        }

        if ($target->getAttribute('modification_locked_at') === null) {
            return false;
        }

        $unlockedAt = $target->getAttribute('modification_unlocked_at');
        if ($unlockedAt === null) {
            return true;
        }

        $expiresAt = $target->getAttribute('modification_unlock_expires_at');

        return $expiresAt !== null && Carbon::parse($expiresAt)->isPast();
    }

    public function isUnlockedForModification(Model $target): bool
    {
        return ! $this->isLocked($target);
    }

    public function lockAfterSave(Model $target, ?User $actor = null): void
    {
        if (! $this->supports($target)) {
            return;
        }

        $target->forceFill([
            'modification_locked_at' => now(),
            'modification_locked_by' => $actor?->id,
            'modification_unlocked_at' => null,
            'modification_unlocked_by' => null,
            'modification_unlock_expires_at' => null,
            'modification_unlock_reason' => null,
        ])->save();
    }

    public function lockedMessage(Model $target): string
    {
        return sprintf(
            '%s verrouille apres enregistrement. Toute modification exige une demande de deverrouillage approuvee par le DG.',
            $this->targetHumanName($target)
        );
    }

    /**
     * Verifie qu'un element n'est pas verrouille. Retourne null si OK, ou un
     * message d'erreur a propager a l'utilisateur si verrouille.
     *
     * Regle metier ANBG (2026-05-29) — Bypass du verrou pour les operateurs habilites :
     *   1. SA et DG : pilotage complet, bypassent partout (cf. 2026-05-28).
     *   2. Planification / SCIQ / SCIQ suivi global / Admin
     *      fonctionnel : operateurs globaux, peuvent ecrire sans demande de
     *      deverrouillage pendant la phase d'edition.
     *   3. Direction : peut ecrire dans sa direction (PAO, PTAs et actions de sa
     *      direction) sans demande.
     *   4. Chef de service / chef d'unite UCAS-DGA-Cabinet / Service / UCAS : peuvent
     *      ecrire dans LEUR service (PTA et actions de leur service) sans demande.
     *
     * Pour les autres roles (agent etc.), le verrou s'applique et exige une demande
     * de deverrouillage approuvee. Ce changement reflete la realite operationnelle :
     * ce sont les chefs de service / direction / planification qui parametrent et
     * mettent a jour les actions au quotidien — c'est leur metier.
     */
    public function ensureUnlocked(Model $target, ?User $actor = null): ?string
    {
        if ($actor !== null && $this->canBypassLock($actor, $target)) {
            return null;
        }

        return $this->isLocked($target) ? $this->lockedMessage($target) : null;
    }

    /**
     * Determine si l'utilisateur peut ecrire sur l'element verrouille sans passer
     * par le workflow de demande de deverrouillage.
     */
    private function canBypassLock(User $actor, Model $target): bool
    {
        // 1. Pilotage complet (cf. fix 2026-05-28).
        if ($actor->isSuperAdmin() || $actor->hasRole(User::ROLE_DG)) {
            return true;
        }

        // 2. Operateurs globaux : ecriture globale autorisee pendant l'edition.
        if ($actor->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL,
            User::ROLE_ADMIN_FONCTIONNEL,
        )) {
            return true;
        }

        // REGLE V3 : une ACTION verrouillee n'est PLUS modifiable directement par
        // la direction / le chef de service. Sa modification exige le circuit
        // demandeur -> controleur SCIQ/Planification -> DG. Le bypass
        // direction/chef ci-dessous ne s'applique donc qu'aux PAS / PTA.
        if ($target instanceof Action) {
            return false;
        }

        [$directionId, $serviceId] = $this->targetScope($target);

        // 3. Direction : bypass uniquement dans SA direction.
        if ($actor->hasRole(User::ROLE_DIRECTION) && $actor->direction_id !== null) {
            return $directionId === null || (int) $actor->direction_id === (int) $directionId;
        }

        // 4. Chef de service / chef d'unite oprationnel : bypass dans SON service.
        if ($actor->hasRole(
            User::ROLE_SERVICE,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE_UCAS,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_UCAS,
        ) && $actor->service_id !== null) {
            return $serviceId !== null
                && (int) $actor->service_id === (int) $serviceId
                && (int) $actor->direction_id === (int) $directionId;
        }

        return false;
    }

    public function canRequestUnlock(User $user, Model $target): bool
    {
        if (! $this->supports($target) || $user->isAgent() || $this->isUnlockReviewer($user)) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN, User::ROLE_ADMIN_FONCTIONNEL)) {
            return true;
        }

        if ($user->isPlanningControlChief()) {
            return true;
        }

        if ($user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL
        )) {
            return true;
        }

        [$directionId, $serviceId] = $this->targetScope($target);

        if ($user->hasRole(User::ROLE_DIRECTION)) {
            return $directionId === null || (int) $user->direction_id === (int) $directionId;
        }

        if ($user->hasRole(
            User::ROLE_SERVICE,
            User::ROLE_CHEF_UNITE,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
            User::ROLE_CHEF_UNITE_DGA,
            User::ROLE_CHEF_UNITE_CABINET,
            User::ROLE_CHEF_UNITE_UCAS,
            User::ROLE_UCAS
        )) {
            return $serviceId !== null
                && (int) $user->direction_id === (int) $directionId
                && (int) $user->service_id === (int) $serviceId;
        }

        return $user->hasDelegatedPermission('planning_write');
    }

    public function isUnlockReviewer(User $user): bool
    {
        return $user->hasRole(User::ROLE_DG);
    }

    public function requestUnlock(Model $target, User $requester, string $reason, ?string $justificatifPath = null): PlanningUnlockRequest
    {
        if (! $this->supports($target)) {
            throw new InvalidArgumentException('Type de cible non pris en charge.');
        }

        $pending = PlanningUnlockRequest::query()
            ->where('target_type', $target::class)
            ->where('target_id', (int) $target->getKey())
            ->whereIn('status', [PlanningUnlockRequest::STATUS_SOUMISE, PlanningUnlockRequest::STATUS_TRANSMISE])
            ->first();

        if ($pending instanceof PlanningUnlockRequest) {
            return $pending;
        }

        [$directionId, $serviceId] = $this->targetScope($target);

        $unlockRequest = PlanningUnlockRequest::query()->create([
            'module' => $this->moduleFor($target),
            'target_type' => $target::class,
            'target_id' => (int) $target->getKey(),
            'target_label' => $this->targetLabel($target),
            'direction_id' => $directionId,
            'service_id' => $serviceId,
            'requested_by' => (int) $requester->id,
            'reason' => trim($reason),
            'justificatif_path' => $justificatifPath,
            'status' => PlanningUnlockRequest::STATUS_SOUMISE,
            'metadata' => [
                'locked_at' => optional($target->getAttribute('modification_locked_at'))->toDateTimeString(),
                'requester_role' => (string) $requester->role,
            ],
        ]);

        $this->bumpPersonalTaskCache();

        // Circuit V3 : la demande part d'abord vers les controleurs
        // SCIQ/Planification, qui la transmettent ensuite a la DG.
        $this->notifyControllers($unlockRequest, $requester);

        return $unlockRequest;
    }

    /**
     * Etape controleur : avis SCIQ/Planification puis transmission a la DG.
     */
    public function transmitByController(PlanningUnlockRequest $unlockRequest, User $controller, string $avis, ?string $comment = null): void
    {
        if (! $this->canTransfer($controller, $unlockRequest)) {
            abort(403, 'Seul un controleur SCIQ/Planification peut transmettre cette demande.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_SOUMISE) {
            abort(409, 'Cette demande n\'est plus en attente de controle.');
        }

        $unlockRequest->forceFill([
            'status' => PlanningUnlockRequest::STATUS_TRANSMISE,
            'transferred_by' => (int) $controller->id,
            'transferred_at' => now(),
            'transfer_comment' => ($value = trim((string) $comment)) !== '' ? $value : null,
            'planif_avis' => $avis === PlanningUnlockRequest::AVIS_FAVORABLE
                ? PlanningUnlockRequest::AVIS_FAVORABLE
                : PlanningUnlockRequest::AVIS_DEFAVORABLE,
            'planif_avis_by' => (int) $controller->id,
            'planif_avis_at' => now(),
            'planif_comment' => ($value = trim((string) $comment)) !== '' ? $value : null,
        ])->save();

        $this->bumpPersonalTaskCache();

        $this->notifyDgAfterControllerTransmission($unlockRequest, $controller);
    }

    /**
     * Ancien nom conserve pour compatibilite interne eventuelle.
     */
    public function transferByDirecteur(PlanningUnlockRequest $unlockRequest, User $controller, ?string $comment = null): void
    {
        $this->transmitByController(
            $unlockRequest,
            $controller,
            PlanningUnlockRequest::AVIS_FAVORABLE,
            $comment
        );
    }

    /**
     * Etape controleur : avis SCIQ/Planification puis transmission a la DG.
     */
    public function recordPlanifAvis(PlanningUnlockRequest $unlockRequest, User $planif, string $avis, ?string $comment = null): void
    {
        $this->transmitByController($unlockRequest, $planif, $avis, $comment);
    }

    public function approve(PlanningUnlockRequest $unlockRequest, User $reviewer, ?string $comment = null, ?Carbon $unlockedUntil = null): void
    {
        if (! $this->isUnlockReviewer($reviewer)) {
            abort(403, 'Seul le DG peut approuver un deverrouillage.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_TRANSMISE) {
            abort(409, 'La demande doit etre transmise par un controleur avant decision du DG.');
        }

        $target = $unlockRequest->target;
        if (! $target instanceof Model || ! $this->supports($target)) {
            abort(404);
        }

        $unlockRequest->forceFill([
            'status' => PlanningUnlockRequest::STATUS_APPROUVEE,
            'decision' => PlanningUnlockRequest::DECISION_APPROUVER,
            'review_comment' => $comment,
            'reviewed_by' => (int) $reviewer->id,
            'reviewed_at' => now(),
            'unlocked_until' => $unlockedUntil,
        ])->save();

        $target->forceFill([
            'modification_unlocked_at' => now(),
            'modification_unlocked_by' => (int) $reviewer->id,
            'modification_unlock_expires_at' => $unlockedUntil,
            'modification_unlock_reason' => (string) $unlockRequest->reason,
        ])->save();

        $this->bumpPersonalTaskCache();

        $this->notifyRequester($unlockRequest, true, $reviewer);
    }

    public function reject(PlanningUnlockRequest $unlockRequest, User $reviewer, string $comment): void
    {
        if (! $this->isUnlockReviewer($reviewer)) {
            abort(403, 'Seul le DG peut rejeter un deverrouillage.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_TRANSMISE) {
            abort(409, 'La demande doit etre transmise par un controleur avant decision du DG.');
        }

        $unlockRequest->forceFill([
            'status' => PlanningUnlockRequest::STATUS_REJETEE,
            'decision' => PlanningUnlockRequest::DECISION_REJETER,
            'review_comment' => trim($comment),
            'reviewed_by' => (int) $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $this->bumpPersonalTaskCache();

        $this->notifyRequester($unlockRequest, false, $reviewer);
    }

    /**
     * Les controleurs SCIQ/Planification peuvent transmettre la demande a la DG.
     */
    public function canTransfer(User $user, PlanningUnlockRequest $unlockRequest): bool
    {
        return $this->canGivePlanifAvis($user);
    }

    public function canGivePlanifAvis(User $user): bool
    {
        if ($user->isPlanningControlChief()) {
            return true;
        }

        return $user->hasRole(
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_SCIQ_SUIVI_GLOBAL
        );
    }

    public function targetHumanName(Model $target): string
    {
        return match ($target::class) {
            Pas::class => 'PAS',
            Pta::class => 'PTA',
            Action::class => 'Action',
            default => 'Enregistrement',
        };
    }

    public function targetLabel(Model $target): string
    {
        return match ($target::class) {
            Pas::class => (string) ($target->titre ?? 'PAS #'.$target->getKey()),
            Pta::class => (string) ($target->titre ?? 'PTA #'.$target->getKey()),
            Action::class => (string) ($target->libelle ?? 'Action #'.$target->getKey()),
            default => 'Enregistrement #'.$target->getKey(),
        };
    }

    private function supports(Model $target): bool
    {
        return array_key_exists($target::class, self::MODULES)
            && Schema::hasColumn($target->getTable(), 'modification_locked_at')
            && Schema::hasColumn($target->getTable(), 'modification_unlocked_at');
    }

    private function moduleFor(Model $target): string
    {
        return self::MODULES[$target::class] ?? 'planning';
    }

    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function targetScope(Model $target): array
    {
        if ($target instanceof Action) {
            $target->loadMissing('pta:id,direction_id,service_id');

            return [
                $target->pta?->direction_id !== null ? (int) $target->pta->direction_id : null,
                $target->pta?->service_id !== null ? (int) $target->pta->service_id : null,
            ];
        }

        if ($target instanceof Pta) {
            return [
                $target->direction_id !== null ? (int) $target->direction_id : null,
                $target->service_id !== null ? (int) $target->service_id : null,
            ];
        }

        return [null, null];
    }

    /**
     * Circuit V3 - etape 1 : notifier les controleurs SCIQ/Planification.
     */
    private function notifyControllers(PlanningUnlockRequest $unlockRequest, User $requester): void
    {
        $recipients = User::query()
            ->whereIn('role', [
                User::ROLE_PLANIFICATION,
                User::ROLE_SCIQ,
                User::ROLE_SCIQ_SUIVI_GLOBAL,
                User::ROLE_CHEF_PLANIFICATION,
                User::ROLE_CHEF_UNITE_SCIQ,
            ])
            ->where('is_active', true)
            ->get();

        $this->notifyUsers(
            $recipients,
            [
                'title' => 'Demande de modification a controler',
                'message' => sprintf('%s demande la modification de %s. Controle SCIQ/Planification puis transmission DG attendus.', $requester->name, (string) $unlockRequest->target_label),
                'module' => 'gouvernance',
                'entity_type' => 'planning_unlock_request',
                'entity_id' => $unlockRequest->id,
                'url' => route('workspace.planning-unlocks.index'),
                'icon' => 'lock-keyhole',
                'status' => 'warning',
                'priority' => 'high',
            ]
        );
    }

    /**
     * Circuit V3 - etape 2 : le controleur a transmis -> decision DG.
     */
    private function notifyDgAfterControllerTransmission(PlanningUnlockRequest $unlockRequest, User $controller): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_DG)
            ->where('is_active', true)
            ->get();

        $this->notifyUsers(
            $recipients,
            [
                'title' => 'Demande de modification transmise a la DG',
                'message' => sprintf('%s a transmis la demande de modification de %s avec avis "%s". Votre decision est attendue.', $controller->name, (string) $unlockRequest->target_label, (string) $unlockRequest->planif_avis),
                'module' => 'gouvernance',
                'entity_type' => 'planning_unlock_request',
                'entity_id' => $unlockRequest->id,
                'url' => route('workspace.planning-unlocks.index'),
                'icon' => 'gavel',
                'status' => 'warning',
                'priority' => 'high',
            ]
        );
    }

    private function notifyRequester(PlanningUnlockRequest $unlockRequest, bool $approved, User $reviewer): void
    {
        $requester = $unlockRequest->requester;
        if (! $requester instanceof User) {
            return;
        }

        $this->notifyUsers(
            new EloquentCollection([$requester]),
            [
                'title' => $approved ? 'Deverrouillage approuve' : 'Deverrouillage rejete',
                'message' => sprintf(
                    '%s a %s la demande pour %s.',
                    $reviewer->name,
                    $approved ? 'approuve' : 'rejete',
                    (string) $unlockRequest->target_label
                ),
                'module' => $unlockRequest->module,
                'entity_type' => 'planning_unlock_request',
                'entity_id' => $unlockRequest->id,
                'url' => $this->targetUrl($unlockRequest),
                'icon' => $approved ? 'unlock' : 'lock',
                'status' => $approved ? 'success' : 'warning',
                'priority' => 'high',
            ]
        );
    }

    /**
     * @param EloquentCollection<int, User> $users
     * @param array<string, mixed> $payload
     */
    private function notifyUsers(EloquentCollection $users, array $payload): void
    {
        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, new WorkspaceModuleNotification($payload));
        } catch (Throwable) {
            // Les notifications de gouvernance sont non bloquantes.
        }
    }

    private function targetUrl(PlanningUnlockRequest $unlockRequest): string
    {
        return match ((string) $unlockRequest->module) {
            'pas' => route('workspace.pas.edit', $unlockRequest->target_id),
            'pta' => route('workspace.pta.edit', $unlockRequest->target_id),
            'action' => route('workspace.actions.edit', $unlockRequest->target_id),
            default => route('workspace.planning-unlocks.index'),
        };
    }

    private function bumpPersonalTaskCache(): void
    {
        app(\App\Services\Analytics\AnalyticsCacheVersionService::class)->bumpAlerts();
    }
}
