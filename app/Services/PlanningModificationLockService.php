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

        // RÈGLE V2 (2026-05-31) : une ACTION verrouillée n'est PLUS modifiable
        // directement par la direction / le chef de service. Sa modification
        // exige le circuit chef → directeur → planification → DG. Le bypass
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

        // Circuit V2 : la demande part d'abord vers le DIRECTEUR de la direction.
        $this->notifyDirecteur($unlockRequest, $requester);

        return $unlockRequest;
    }

    /**
     * Étape directeur : transfère la demande à la Planification (avis) + DG (décision).
     */
    public function transferByDirecteur(PlanningUnlockRequest $unlockRequest, User $directeur, ?string $comment = null): void
    {
        if (! $this->canTransfer($directeur, $unlockRequest)) {
            abort(403, 'Seul le directeur de la direction concernée peut transférer cette demande.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_SOUMISE) {
            abort(409, 'Cette demande n\'est plus en attente de transfert.');
        }

        $unlockRequest->forceFill([
            'status' => PlanningUnlockRequest::STATUS_TRANSMISE,
            'transferred_by' => (int) $directeur->id,
            'transferred_at' => now(),
            'transfer_comment' => ($value = trim((string) $comment)) !== '' ? $value : null,
        ])->save();

        $this->notifyPlanifAndDg($unlockRequest, $directeur);
    }

    /**
     * Étape planification : avis CONSULTATIF (ne décide pas). Notifie le DG.
     */
    public function recordPlanifAvis(PlanningUnlockRequest $unlockRequest, User $planif, string $avis, ?string $comment = null): void
    {
        if (! $this->canGivePlanifAvis($planif)) {
            abort(403, 'Seule la Planification peut émettre un avis.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_TRANSMISE) {
            abort(409, 'Cette demande n\'attend pas d\'avis de la Planification.');
        }

        $unlockRequest->forceFill([
            'planif_avis' => $avis === PlanningUnlockRequest::AVIS_FAVORABLE
                ? PlanningUnlockRequest::AVIS_FAVORABLE
                : PlanningUnlockRequest::AVIS_DEFAVORABLE,
            'planif_avis_by' => (int) $planif->id,
            'planif_avis_at' => now(),
            'planif_comment' => ($value = trim((string) $comment)) !== '' ? $value : null,
        ])->save();

        $this->notifyDgAfterAvis($unlockRequest, $planif);
    }

    public function approve(PlanningUnlockRequest $unlockRequest, User $reviewer, ?string $comment = null, ?Carbon $unlockedUntil = null): void
    {
        if (! $this->isUnlockReviewer($reviewer)) {
            abort(403, 'Seul le DG peut approuver un deverrouillage.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_TRANSMISE) {
            abort(409, 'La demande doit être transférée par le directeur avant décision du DG.');
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

        $this->notifyRequester($unlockRequest, true, $reviewer);
    }

    public function reject(PlanningUnlockRequest $unlockRequest, User $reviewer, string $comment): void
    {
        if (! $this->isUnlockReviewer($reviewer)) {
            abort(403, 'Seul le DG peut rejeter un deverrouillage.');
        }

        if ((string) $unlockRequest->status !== PlanningUnlockRequest::STATUS_TRANSMISE) {
            abort(409, 'La demande doit être transférée par le directeur avant décision du DG.');
        }

        $unlockRequest->forceFill([
            'status' => PlanningUnlockRequest::STATUS_REJETEE,
            'decision' => PlanningUnlockRequest::DECISION_REJETER,
            'review_comment' => trim($comment),
            'reviewed_by' => (int) $reviewer->id,
            'reviewed_at' => now(),
        ])->save();

        $this->notifyRequester($unlockRequest, false, $reviewer);
    }

    /**
     * Le directeur de la direction de la cible peut transférer (ou SA/admin).
     */
    public function canTransfer(User $user, PlanningUnlockRequest $unlockRequest): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole(User::ROLE_ADMIN, User::ROLE_ADMIN_FONCTIONNEL)) {
            return true;
        }

        return $user->hasRole(User::ROLE_DIRECTION)
            && $user->direction_id !== null
            && (int) $user->direction_id === (int) $unlockRequest->direction_id;
    }

    public function canGivePlanifAvis(User $user): bool
    {
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
     * Circuit V2 — étape 1 : notifier le directeur de la direction concernée.
     */
    private function notifyDirecteur(PlanningUnlockRequest $unlockRequest, User $requester): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_DIRECTION)
            ->where('is_active', true)
            ->when($unlockRequest->direction_id !== null, fn ($q) => $q->where('direction_id', (int) $unlockRequest->direction_id))
            ->get();

        $this->notifyUsers(
            $recipients,
            [
                'title' => 'Demande de modification à transférer',
                'message' => sprintf('%s demande la modification de %s. À transférer à la Planification et à la DG.', $requester->name, (string) $unlockRequest->target_label),
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
     * Circuit V2 — étape 2 : le directeur a transféré → Planification (avis) + DG (décision).
     */
    private function notifyPlanifAndDg(PlanningUnlockRequest $unlockRequest, User $directeur): void
    {
        $recipients = User::query()
            ->whereIn('role', [User::ROLE_DG, User::ROLE_PLANIFICATION, User::ROLE_SCIQ])
            ->where('is_active', true)
            ->get();

        $this->notifyUsers(
            $recipients,
            [
                'title' => 'Demande de modification transmise',
                'message' => sprintf('%s (direction) a transmis une demande de modification de %s. Avis Planification puis décision DG attendus.', $directeur->name, (string) $unlockRequest->target_label),
                'module' => 'gouvernance',
                'entity_type' => 'planning_unlock_request',
                'entity_id' => $unlockRequest->id,
                'url' => route('workspace.planning-unlocks.index'),
                'icon' => 'send',
                'status' => 'info',
                'priority' => 'high',
            ]
        );
    }

    /**
     * Circuit V2 — étape 3 : avis Planification rendu → notifier le DG pour décision.
     */
    private function notifyDgAfterAvis(PlanningUnlockRequest $unlockRequest, User $planif): void
    {
        $recipients = User::query()
            ->where('role', User::ROLE_DG)
            ->where('is_active', true)
            ->get();

        $this->notifyUsers(
            $recipients,
            [
                'title' => 'Avis Planification rendu — décision DG attendue',
                'message' => sprintf('Avis « %s » de la Planification sur la modification de %s. Votre décision est attendue.', (string) $unlockRequest->planif_avis, (string) $unlockRequest->target_label),
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
}
