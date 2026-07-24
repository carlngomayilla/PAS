<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActionFinancingWorkflowService
{
    /** @var list<string> */
    private const SUBMISSION_STATUSES = [
        Action::FINANCEMENT_PRE_SIGNALE_DAF,
        Action::FINANCEMENT_COMPLEMENT_DEMANDE,
        Action::FINANCEMENT_REJETE_DAF,
    ];

    /** @var list<string> */
    private const DAF_REVIEW_STATUSES = [
        Action::FINANCEMENT_SOUMIS_DAF,
    ];

    /** @var list<string> */
    private const DG_REVIEW_STATUSES = [
        Action::FINANCEMENT_TRANSMIS_DG,
        Action::FINANCEMENT_VALIDE_DAF,
    ];

    public function canSubmitStatus(Action $action): bool
    {
        return in_array($action->financementStatus(), self::SUBMISSION_STATUSES, true);
    }

    public function canReviewByDafStatus(Action $action): bool
    {
        return in_array($action->financementStatus(), self::DAF_REVIEW_STATUSES, true);
    }

    public function canReviewByDgStatus(Action $action): bool
    {
        return in_array($action->financementStatus(), self::DG_REVIEW_STATUSES, true);
    }

    /**
     * @param  array{source_financement:string,commentaire_financement:string}  $payload
     */
    public function submitToDaf(Action $action, array $payload, User $actor): Action
    {
        return DB::transaction(function () use ($action, $payload, $actor): Action {
            $lockedAction = $this->lockAction($action);
            $this->assertOpenFinancing($lockedAction);

            if (! $lockedAction->isResponsible($actor)) {
                throw new InvalidArgumentException('Seul un RMO de l action peut soumettre le dossier de financement.');
            }

            if (! $this->canSubmitStatus($lockedAction)) {
                throw new InvalidArgumentException('Ce dossier de financement ne peut pas etre soumis dans son etat actuel.');
            }

            $previousStatus = $lockedAction->financementStatus();
            $lockedAction->forceFill([
                'source_financement' => trim($payload['source_financement']),
                'commentaire_financement' => trim($payload['commentaire_financement']),
                'financement_statut' => Action::FINANCEMENT_SOUMIS_DAF,
                'financement_soumis_le' => now(),
                'financement_notifie_le' => null,
                'financement_dg_par' => null,
                'financement_dg_le' => null,
                'financement_dg_decision' => null,
                'financement_dg_commentaire' => null,
            ])->save();

            $isResubmission = $previousStatus !== Action::FINANCEMENT_PRE_SIGNALE_DAF;
            $this->log(
                $lockedAction,
                $isResubmission ? 'financement_resoumis_daf' : 'financement_soumis_daf',
                $isResubmission
                    ? 'Dossier de financement corrige et resoumis a la DAF.'
                    : 'Dossier de financement soumis a la DAF.',
                $actor,
                [
                    'statut_precedent' => $previousStatus,
                    'montant_estime' => $lockedAction->montant_estime,
                    'source_financement' => $lockedAction->source_financement,
                ],
                'daf'
            );

            return $lockedAction->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewByDaf(Action $action, array $payload, User $actor): Action
    {
        return DB::transaction(function () use ($action, $payload, $actor): Action {
            $lockedAction = $this->lockAction($action);
            $this->assertOpenFinancing($lockedAction);

            if (! $this->isDafReviewer($actor) || $lockedAction->isResponsible($actor)) {
                throw new InvalidArgumentException('Seule la direction DAF habilitee peut instruire ce dossier.');
            }

            if (! $this->canReviewByDafStatus($lockedAction)) {
                throw new InvalidArgumentException('Ce dossier n est pas en attente de traitement DAF.');
            }

            $decision = (string) $payload['decision_financement'];
            $approved = $decision === ActionTrackingService::FINANCEMENT_DECISION_VALIDER;
            $requiresComplement = $decision === ActionTrackingService::FINANCEMENT_DECISION_COMPLEMENT;

            $lockedAction->forceFill([
                'financement_statut' => match (true) {
                    $approved => Action::FINANCEMENT_TRANSMIS_DG,
                    $requiresComplement => Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                    default => Action::FINANCEMENT_REJETE_DAF,
                },
                'financement_daf_par' => $actor->id,
                'financement_daf_le' => now(),
                'financement_daf_decision' => $decision,
                'financement_daf_commentaire' => trim((string) $payload['commentaire_financement']),
                'financement_montant_valide' => $approved ? (float) $payload['montant_valide'] : null,
                'financement_reference' => $approved ? trim((string) $payload['reference_financement']) : null,
                'financement_dg_par' => null,
                'financement_dg_le' => null,
                'financement_dg_decision' => null,
                'financement_dg_commentaire' => null,
            ])->save();

            $this->log(
                $lockedAction,
                match (true) {
                    $approved => 'financement_valide_daf',
                    $requiresComplement => 'financement_complement_demande',
                    default => 'financement_rejete_daf',
                },
                match (true) {
                    $approved => 'Avis favorable DAF. Dossier transmis a la DG.',
                    $requiresComplement => 'Complement demande par la DAF au RMO.',
                    default => 'Dossier de financement rejete par la DAF.',
                },
                $actor,
                [
                    'decision' => $decision,
                    'montant_valide' => $lockedAction->financement_montant_valide,
                    'reference' => $lockedAction->financement_reference,
                    'commentaire' => $lockedAction->financement_daf_commentaire,
                ],
                $approved ? 'dg' : 'responsable'
            );

            return $lockedAction->refresh();
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reviewByDg(Action $action, array $payload, User $actor): Action
    {
        return DB::transaction(function () use ($action, $payload, $actor): Action {
            $lockedAction = $this->lockAction($action);
            $this->assertOpenFinancing($lockedAction);

            if (! $actor->hasRole(User::ROLE_DG) || $lockedAction->isResponsible($actor)) {
                throw new InvalidArgumentException('Seule la Direction Generale peut rendre la decision finale.');
            }

            if (! $this->canReviewByDgStatus($lockedAction)) {
                throw new InvalidArgumentException('Ce dossier n est pas en attente de decision DG.');
            }

            $decision = (string) $payload['decision_financement'];
            $approved = $decision === ActionTrackingService::FINANCEMENT_DECISION_ACCORDER;

            $lockedAction->forceFill([
                'financement_statut' => $approved
                    ? Action::FINANCEMENT_ACCORDE_DG
                    : Action::FINANCEMENT_REFUSE_DG,
                'financement_dg_par' => $actor->id,
                'financement_dg_le' => now(),
                'financement_dg_decision' => $decision,
                'financement_dg_commentaire' => trim((string) $payload['commentaire_financement']),
            ])->save();

            $this->log(
                $lockedAction,
                $approved ? 'financement_accord_dg' : 'financement_refus_dg',
                $approved
                    ? 'Financement accorde par la Direction Generale.'
                    : 'Financement refuse par la Direction Generale.',
                $actor,
                [
                    'decision' => $decision,
                    'montant_valide' => $lockedAction->financement_montant_valide,
                    'reference' => $lockedAction->financement_reference,
                    'commentaire' => $lockedAction->financement_dg_commentaire,
                ],
                'responsable'
            );

            return $lockedAction->refresh();
        }, attempts: 3);
    }

    private function lockAction(Action $action): Action
    {
        return Action::query()
            ->with('pta:id,statut')
            ->whereKey($action->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertOpenFinancing(Action $action): void
    {
        if (! (bool) $action->financement_requis) {
            throw new InvalidArgumentException('Cette action ne requiert pas de financement.');
        }

        if (in_array((string) $action->pta?->statut, ['cloture', 'archive'], true)) {
            throw new InvalidArgumentException('Le PTA parent est cloture ou archive.');
        }

        if (in_array((string) $action->statut, ['suspendu', 'annule', 'annulee', 'archive'], true)) {
            throw new InvalidArgumentException('Le financement ne peut pas evoluer pour une action suspendue, annulee ou archivee.');
        }
    }

    private function isDafReviewer(User $user): bool
    {
        if (! $user->hasRole(User::ROLE_DIRECTION) || $user->direction_id === null) {
            return false;
        }

        if ($user->relationLoaded('direction')) {
            return (string) ($user->direction?->code ?? '') === 'DAF';
        }

        return $user->direction()->where('code', 'DAF')->exists();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(
        Action $action,
        string $type,
        string $message,
        User $actor,
        array $details,
        string $targetRole
    ): void {
        ActionLog::query()->create([
            'action_id' => $action->id,
            'niveau' => str_contains($type, 'rejete') || str_contains($type, 'refus') ? 'warning' : 'info',
            'type_evenement' => $type,
            'message' => $message,
            'details' => $details,
            'cible_role' => $targetRole,
            'utilisateur_id' => $actor->id,
            'lu' => false,
        ]);
    }
}
