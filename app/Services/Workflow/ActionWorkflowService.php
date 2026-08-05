<?php

namespace App\Services\Workflow;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\SousAction;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrateur du workflow de suivi V2 (cf. docs/WORKFLOW-SUIVI-V2.md).
 *
 * Cycle : non_demarre → en_cours → chef → controleur → valide / correction.
 *
 *   - record*Progress() : enregistrement brouillon (Save). Recalcule la
 *     performance PROVISOIRE. Aucune contrainte.
 *   - submit*()         : soumission au chef (Submit). Vérifie la conformité.
 *   - reviewAction()    : visa du chef et ajustement motive eventuel.
 *   - reviewActionByController() : décision finale et performance officielle.
 *
 * Délègue tout le calcul à ActionPerformanceCalculator (service pur).
 */
class ActionWorkflowService
{
    public function __construct(
        private readonly ActionPerformanceCalculator $calculator
    ) {}

    // ── ACTION SIMPLE (quantitative / non quantitative) ──────────────────────

    /**
     * Enregistrement brouillon d'une action simple (Save).
     *
     * @param  array{quantite_realisee?:mixed,commentaire?:?string,difficulte?:?string}  $data
     */
    public function recordActionProgress(Action $action, array $data, ?User $actor = null): Action
    {
        $this->assertActionExecutionEditable($action);

        if ($action->isQuantitative() && array_key_exists('quantite_realisee', $data)) {
            $action->quantite_realisee = max(0.0, (float) ($data['quantite_realisee'] ?? 0));
        }

        $provisional = $this->calculator->provisionalPerformance($action);

        // Date d'atteinte du seuil de completude : enregistree UNE SEULE FOIS,
        // au premier franchissement. Elle sert au calcul du statut delai : sans
        // elle, une action atteignant son seuil apres l'echeance apparaissait
        // « dans les delais ».
        $completionThreshold = max(0.0, min(100.0, (float) ($action->seuil_minimum ?? 100.0)));
        $thresholdReachedAt = $action->seuil_atteint_le;
        if ($thresholdReachedAt === null && $provisional >= $completionThreshold) {
            $thresholdReachedAt = now();
        }

        $action->forceFill([
            'progression_reelle' => $provisional,
            'statut_performance' => $this->calculator->performanceStatus($provisional),
            'seuil_atteint_le' => $thresholdReachedAt,
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            // Tant que non soumise, on reste en non_soumise / correction.
            'statut_validation' => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_CORRECTION_CONTROLE,
            ], true) ? $action->statut_validation : ActionTrackingService::VALIDATION_NON_SOUMISE,
        ])->save();

        $this->log($action, 'suivi_enregistre', 'Avancement enregistré (brouillon).', $actor, [
            'progression_provisoire' => $provisional,
        ]);

        return $action->refresh();
    }

    /**
     * Soumission d'une action simple au chef (Submit).
     *
     * @param  array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool}  $data
     *
     * @throws \InvalidArgumentException si la conformité n'est pas remplie.
     */
    public function submitAction(Action $action, array $data, ?User $actor = null): Action
    {
        return DB::transaction(function () use ($action, $data, $actor): Action {
            // Verrou : deux soumissions simultanees ne doivent pas se chevaucher.
            $action = Action::query()
                ->whereKey($action->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->applySubmission($action, $data, $actor);
        });
    }

    /**
     * Soumission de l'action, une fois le verrou pose.
     *
     * @param  array<string, mixed>  $data
     */
    private function applySubmission(Action $action, array $data, ?User $actor): Action
    {
        $this->assertActionExecutionEditable($action);

        $conformity = $this->calculator->actionConformity(
            $action,
            $data['commentaire'] ?? null,
            $data['difficulte'] ?? null,
            (bool) ($data['has_new_proof'] ?? false)
        );

        if (! $conformity['can_submit']) {
            throw new \InvalidArgumentException(
                'Conditions de soumission non remplies : '.implode(', ', $conformity['missing']).'.'
            );
        }

        $provisional = $this->calculator->provisionalPerformance($action);

        $action->forceFill([
            'progression_reelle' => $provisional,
            'statut_performance' => $this->calculator->performanceStatus($provisional),
            'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CHEF,
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            'soumise_le' => now(),
            'soumise_par' => $actor?->id ?? $action->soumise_par,
            'chef_progress_percent' => null,
            'chef_adjustment_reason' => null,
            'controle_decision' => null,
            'controle_comment' => null,
            'controle_reviewed_by' => null,
            'controle_reviewed_at' => null,
        ])->save();

        $this->log($action, 'action_soumise_validation', 'Action soumise au chef de service.', $actor, [
            'progression_provisoire' => $provisional,
        ]);

        return $action->refresh();
    }

    /**
     * Décision du chef sur une action simple.
     */
    public function reviewAction(
        Action $action,
        bool $approve,
        ?string $motif,
        ?User $actor = null,
        ?float $progressPercent = null
    ): Action {
        return DB::transaction(function () use ($action, $approve, $motif, $actor, $progressPercent): Action {
            // Verrou + relecture : sans cela deux visas simultanes passaient tous
            // les deux le controle de statut et faisaient sauter une etape.
            $action = Action::query()
                ->whereKey($action->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $action->statut_validation !== ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
                throw new \InvalidArgumentException('Cette action n est pas en attente de validation du chef.');
            }

            // Separation des roles : le visa du chef ne peut pas etre pose par la
            // personne qui a soumis l'action, ni par son responsable.
            if ($actor instanceof User
                && ($action->isResponsible($actor) || (int) ($action->soumise_par ?? 0) === (int) $actor->id)
            ) {
                throw new \InvalidArgumentException('Le visa du chef doit etre pose par un autre intervenant que le responsable de l action.');
            }

            return $this->applyChefDecision($action, $approve, $motif, $actor, $progressPercent);
        });
    }

    /**
     * Decision du chef, une fois les verrous et les gardes passes.
     */
    private function applyChefDecision(
        Action $action,
        bool $approve,
        ?string $motif,
        ?User $actor,
        ?float $progressPercent
    ): Action {
        if ($approve) {
            $provisional = $this->calculator->provisionalPerformance($action);
            $proposed = $progressPercent ?? $provisional;
            if ($proposed < 0.0 || $proposed > 100.0) {
                throw new \InvalidArgumentException('Le taux propose par le chef doit etre compris entre 0 et 100.');
            }

            $wasAdjusted = abs($proposed - $provisional) >= 0.01;
            if ($wasAdjusted && trim((string) $motif) === '') {
                throw new \InvalidArgumentException('Une justification est obligatoire lorsque le chef ajuste le taux calcule.');
            }

            $action->forceFill([
                'chef_progress_percent' => $proposed,
                'chef_adjustment_reason' => $wasAdjusted ? trim((string) $motif) : null,
                'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_CONTROLE,
                'statut' => ActionTrackingService::STATUS_EN_COURS,
                'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                'evalue_le' => now(),
                'evalue_par' => $actor?->id,
                'motif_validation_chef' => $motif,
            ])->save();

            $this->log($action, 'action_transmise_controle', 'Action visee par le chef et transmise au controleur.', $actor, [
                'progression_calculee' => $provisional,
                'progression_proposee' => $proposed,
                'ajustement' => $wasAdjusted,
            ], 'controleur');

            return $action->refresh();
        }

        $action->forceFill([
            'statut_validation' => ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
            'statut' => ActionTrackingService::STATUS_A_CORRIGER,
            'statut_dynamique' => ActionTrackingService::STATUS_A_CORRIGER,
            'evalue_le' => now(),
            'evalue_par' => $actor?->id,
            'motif_validation_chef' => $motif,
        ])->save();

        $this->log($action, 'action_rejetee_chef', 'Action renvoyée par le chef pour correction.', $actor, [
            'motif' => $motif,
        ]);

        return $action->refresh();
    }

    public function reviewActionByController(
        Action $action,
        bool $approve,
        ?string $comment,
        User $actor
    ): Action {
        if (! $approve && trim((string) $comment) === '') {
            throw new \InvalidArgumentException('Le motif est obligatoire pour demander une correction.');
        }

        return DB::transaction(function () use ($action, $approve, $comment, $actor): Action {
            $lockedAction = Action::query()
                ->whereKey($action->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedAction->statut_validation !== ActionTrackingService::VALIDATION_SOUMISE_CONTROLE) {
                throw new \InvalidArgumentException('Cette action n est pas en attente de controle.');
            }

            if ($lockedAction->isResponsible($actor)
                || (int) ($lockedAction->soumise_par ?? 0) === (int) $actor->id
                || (int) ($lockedAction->evalue_par ?? 0) === (int) $actor->id
            ) {
                throw new \InvalidArgumentException('Le controle final doit etre realise par un autre intervenant.');
            }

            if ($approve) {
                // Circuit a 3 visas : le controleur (SCIQ) ne cloture plus l'action,
                // il la transmet a la planification qui realise la validation finale.
                $provisional = (float) ($lockedAction->chef_progress_percent
                    ?? $this->calculator->provisionalPerformance($lockedAction));

                $lockedAction->forceFill([
                    'statut_validation' => ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION,
                    'statut' => ActionTrackingService::STATUS_EN_COURS,
                    'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                    'controle_decision' => 'valider',
                    'controle_comment' => $comment,
                    'controle_reviewed_by' => $actor->id,
                    'controle_reviewed_at' => now(),
                ])->save();

                $this->log(
                    $lockedAction,
                    'action_transmise_planification',
                    'Action visee par le controle et transmise a la planification.',
                    $actor,
                    ['performance_provisoire' => $provisional],
                    'planification'
                );

                return $lockedAction->refresh();
            }

            $lockedAction->forceFill([
                'statut_validation' => ActionTrackingService::VALIDATION_CORRECTION_CONTROLE,
                'statut' => ActionTrackingService::STATUS_A_CORRIGER,
                'statut_dynamique' => ActionTrackingService::STATUS_A_CORRIGER,
                'controle_decision' => 'rejeter',
                'controle_comment' => trim((string) $comment),
                'controle_reviewed_by' => $actor->id,
                'controle_reviewed_at' => now(),
            ])->save();

            if ($lockedAction->isComposee()) {
                $lockedAction->sousActions()
                    ->where('validation_status', SousAction::VALIDATION_VALIDEE)
                    ->update([
                        'validation_status' => SousAction::VALIDATION_REJETEE,
                        'statut' => 'rejetee_a_corriger',
                        'est_effectuee' => false,
                        'completed_at' => null,
                    ]);
            }

            $this->log($lockedAction, 'action_rejetee_controle', 'Action renvoyee par le controleur pour correction.', $actor, [
                'motif' => $comment,
            ], 'responsable');

            return $lockedAction->refresh();
        });
    }

    /**
     * Validation finale par la planification : troisieme et dernier visa du
     * circuit (chef de service -> controle SCIQ -> planification). C'est ce visa
     * qui cloture officiellement l'action et fige sa performance officielle.
     */
    public function reviewActionByPlanification(
        Action $action,
        bool $approve,
        ?string $comment,
        User $actor
    ): Action {
        if (! $approve && trim((string) $comment) === '') {
            throw new \InvalidArgumentException('Le motif est obligatoire pour renvoyer une action en correction.');
        }

        return DB::transaction(function () use ($approve, $action, $comment, $actor): Action {
            $lockedAction = Action::query()
                ->whereKey($action->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $lockedAction->statut_validation !== ActionTrackingService::VALIDATION_SOUMISE_PLANIFICATION) {
                throw new \InvalidArgumentException('Cette action n est pas en attente de validation planification.');
            }

            if ($lockedAction->isResponsible($actor)
                || (int) ($lockedAction->soumise_par ?? 0) === (int) $actor->id
                || (int) ($lockedAction->evalue_par ?? 0) === (int) $actor->id
                || (int) ($lockedAction->controle_reviewed_by ?? 0) === (int) $actor->id
            ) {
                throw new \InvalidArgumentException('La validation finale doit etre realisee par un autre intervenant.');
            }

            if ($approve) {
                $official = (float) ($lockedAction->chef_progress_percent
                    ?? $this->calculator->provisionalPerformance($lockedAction));

                $lockedAction->forceFill([
                    'official_progress_percent' => $official,
                    'progression_reelle' => $official,
                    'statut_performance' => $this->calculator->performanceStatus($official),
                    'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_PLANIFICATION,
                    'statut' => ActionTrackingService::STATUS_CLOTUREE,
                    'statut_dynamique' => ActionTrackingService::STATUS_CLOTUREE,
                    'date_fin_reelle' => $lockedAction->date_fin_reelle ?: now()->toDateString(),
                    'cloture_le' => now(),
                    'cloture_par' => $actor->id,
                ])->save();

                $this->log(
                    $lockedAction,
                    'action_validee_planification',
                    'Action validee par la planification : cloture officielle.',
                    $actor,
                    ['performance_officielle' => $official],
                    'responsable'
                );

                return $lockedAction->refresh();
            }

            $lockedAction->forceFill([
                'statut_validation' => ActionTrackingService::VALIDATION_CORRECTION_PLANIFICATION,
                'statut' => ActionTrackingService::STATUS_A_CORRIGER,
                'statut_dynamique' => ActionTrackingService::STATUS_A_CORRIGER,
            ])->save();

            if ($lockedAction->isComposee()) {
                $lockedAction->sousActions()
                    ->where('validation_status', SousAction::VALIDATION_VALIDEE)
                    ->update([
                        'validation_status' => SousAction::VALIDATION_REJETEE,
                        'statut' => 'rejetee_a_corriger',
                        'est_effectuee' => false,
                        'completed_at' => null,
                    ]);
            }

            $this->log(
                $lockedAction,
                'action_rejetee_planification',
                'Action renvoyee par la planification pour correction.',
                $actor,
                ['motif' => $comment],
                'responsable'
            );

            return $lockedAction->refresh();
        });
    }

    // ── SOUS-ACTION (action composée) ────────────────────────────────────────

    /**
     * Enregistrement brouillon d'une sous-action (Save).
     *
     * @param  array{quantite_realisee?:mixed,resultat_obtenu?:?string,commentaire?:?string}  $data
     */
    public function recordSubActionProgress(SousAction $sousAction, array $data, ?User $actor = null): SousAction
    {
        $this->assertSubActionExecutionEditable($sousAction);

        if ($sousAction->isQuantitative() && array_key_exists('quantite_realisee', $data)) {
            $sousAction->quantite_realisee = max(0.0, (float) ($data['quantite_realisee'] ?? 0));
        }

        $provisional = $this->calculator->subActionPerformance($sousAction);

        $sousAction->forceFill([
            'taux_realisation' => $provisional,
            'taux_execution' => $provisional,
            'resultat_obtenu' => $data['resultat_obtenu'] ?? $sousAction->resultat_obtenu,
            'commentaire' => $data['commentaire'] ?? $sousAction->commentaire,
            'statut' => 'en_cours',
            'validation_status' => in_array((string) $sousAction->validation_status, [
                SousAction::VALIDATION_VALIDEE,
            ], true) ? $sousAction->validation_status : SousAction::VALIDATION_NON_SOUMISE,
        ])->save();

        return $sousAction->refresh();
    }

    /**
     * Soumission d'une sous-action au chef.
     *
     * @param  array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool}  $data
     *
     * @throws \InvalidArgumentException si la conformité n'est pas remplie.
     */
    public function submitSubAction(SousAction $sousAction, array $data, ?User $actor = null): SousAction
    {
        $this->assertSubActionExecutionEditable($sousAction);
        $this->assertSubActionConformity($sousAction, $data);

        $provisional = $this->calculator->subActionPerformance($sousAction);

        $sousAction->forceFill([
            'taux_realisation' => $provisional,
            'taux_execution' => $provisional,
            'validation_status' => SousAction::VALIDATION_SOUMISE,
            'statut' => 'en_attente_validation_chef',
            'est_effectuee' => true,
            'completed_at' => $sousAction->completed_at ?: now(),
        ])->save();

        return $sousAction->refresh();
    }

    /**
     * Decision du chef sur une sous-action. Si toutes les sous-actions du parent
     * sont validees, l'action composee est soumise a la validation finale.
     */
    public function reviewSubAction(SousAction $sousAction, bool $approve, ?string $motif, ?User $actor = null): SousAction
    {
        if ((string) $sousAction->validation_status !== SousAction::VALIDATION_SOUMISE) {
            throw new \InvalidArgumentException('Cette sous-action n est pas en attente de validation du chef.');
        }

        if ($approve) {
            $official = $this->calculator->subActionPerformance($sousAction);
            $sousAction->forceFill([
                'official_progress_percent' => $official,
                'taux_realisation' => $official,
                'validation_status' => SousAction::VALIDATION_VALIDEE,
                'statut' => 'validee_chef',
                'date_realisation' => $sousAction->date_realisation ?: now(),
            ])->save();
        } else {
            $sousAction->forceFill([
                'validation_status' => SousAction::VALIDATION_REJETEE,
                'statut' => 'rejetee_a_corriger',
                'est_effectuee' => false,
                'commentaire' => $motif ? trim((string) $sousAction->commentaire."\nMotif chef : ".$motif) : $sousAction->commentaire,
            ])->save();
        }

        $sousAction->refresh();
        $action = $sousAction->action;
        if ($action instanceof Action) {
            $this->refreshCompositeParent($action, $actor);
        }

        return $sousAction;
    }

    private function assertActionExecutionEditable(Action $action): void
    {
        if ((string) ($action->statut_parametrage ?? '') === 'a_parametrer') {
            throw new \InvalidArgumentException('Cette action doit etre parametree avant le suivi.');
        }

        // Une action renvoyee en correction — par le chef, le controle OU la
        // planification — doit redevenir modifiable par son responsable.
        $editableStatuses = array_merge(
            [ActionTrackingService::VALIDATION_NON_SOUMISE],
            ActionTrackingService::CORRECTION_VALIDATION_STATUSES
        );

        if (! in_array((string) ($action->statut_validation ?? ActionTrackingService::VALIDATION_NON_SOUMISE), $editableStatuses, true)) {
            throw new \InvalidArgumentException('Cette action est gelee pendant la validation.');
        }

        $lifecycleStatus = (string) ($action->statut_dynamique ?: $action->statut ?: '');
        if (in_array($lifecycleStatus, [
            ActionTrackingService::STATUS_SUSPENDU,
            ActionTrackingService::STATUS_ANNULE,
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_CLOTUREE,
            'cloture',
            'archive',
        ], true)) {
            throw new \InvalidArgumentException('Cette action est suspendue, terminee ou cloturee.');
        }
    }

    private function assertSubActionExecutionEditable(SousAction $sousAction): void
    {
        if (! in_array((string) ($sousAction->validation_status ?? SousAction::VALIDATION_NON_SOUMISE), [
            SousAction::VALIDATION_NON_SOUMISE,
            SousAction::VALIDATION_REJETEE,
        ], true)) {
            throw new \InvalidArgumentException('Cette sous-action est gelee pendant ou apres sa validation.');
        }

        $action = $sousAction->action;
        if ($action instanceof Action) {
            $this->assertActionExecutionEditable($action);
        }
    }

    /**
     * Recalcule la performance d'une action composee depuis ses sous-actions.
     * La validation des sous-actions declenche la validation finale du parent.
     */
    public function refreshCompositeParent(Action $action, ?User $actor = null): Action
    {
        $action->loadMissing('sousActions');
        $subActions = $action->sousActions;

        $provisional = $this->calculator->compositePerformance($action);
        $allValidated = $subActions->isNotEmpty()
            && $subActions->every(fn (SousAction $sa): bool => (string) $sa->validation_status === SousAction::VALIDATION_VALIDEE);
        $validationStatus = (string) $action->statut_validation;
        $alreadySubmitted = $validationStatus === ActionTrackingService::VALIDATION_SOUMISE_CHEF;
        $alreadyInControl = $validationStatus === ActionTrackingService::VALIDATION_SOUMISE_CONTROLE;
        $alreadyValidated = $validationStatus === ActionTrackingService::VALIDATION_VALIDEE_CONTROLE;

        $payload = [
            'progression_reelle' => $provisional,
            'statut_performance' => $this->calculator->performanceStatus($provisional),
        ];

        if ($allValidated) {
            if ($alreadyValidated) {
                $payload['statut'] = ActionTrackingService::STATUS_CLOTUREE;
                $payload['statut_dynamique'] = ActionTrackingService::STATUS_CLOTUREE;
            } elseif ($alreadyInControl) {
                $payload['statut'] = ActionTrackingService::STATUS_EN_COURS;
                $payload['statut_dynamique'] = ActionTrackingService::STATUS_EN_COURS;
            } else {
                $payload['statut_validation'] = ActionTrackingService::VALIDATION_SOUMISE_CHEF;
                $payload['statut'] = ActionTrackingService::STATUS_EN_COURS;
                $payload['statut_dynamique'] = ActionTrackingService::STATUS_EN_COURS;
                $payload['soumise_le'] = $alreadySubmitted && $action->soumise_le
                    ? $action->soumise_le
                    : now();
                $payload['soumise_par'] = $alreadySubmitted && $action->soumise_par
                    ? $action->soumise_par
                    : ($actor?->id ?? $action->soumise_par);
            }
        } else {
            $payload['statut'] = ActionTrackingService::STATUS_EN_COURS;
            $payload['statut_dynamique'] = ActionTrackingService::STATUS_EN_COURS;
        }

        $action->forceFill($payload)->save();

        if ($allValidated && ! $alreadySubmitted && ! $alreadyInControl && ! $alreadyValidated) {
            $this->log($action, 'action_soumise_validation', 'Action composee soumise au chef de service (toutes les sous-actions sont validees).', $actor, [
                'progression_provisoire' => $provisional,
            ]);
        }

        return $action->refresh();
    }

    /**
     * @param  array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool}  $data
     */
    private function assertSubActionConformity(SousAction $sousAction, array $data): void
    {
        $missing = [];

        if ($sousAction->isQuantitative() && (float) ($sousAction->quantite_realisee ?? 0) <= 0) {
            $missing[] = 'quantite';
        }

        $hasProof = (bool) ($data['has_new_proof'] ?? false)
            || $sousAction->justificatifs()->exists();
        if ((bool) $sousAction->requires_proof && ! $hasProof) {
            $missing[] = 'justificatif';
        }

        if ((bool) $sousAction->requires_comment && trim((string) ($data['commentaire'] ?? $sousAction->commentaire)) === '') {
            $missing[] = 'commentaire';
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Conditions de soumission de la sous-action non remplies : '.implode(', ', $missing).'.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(
        Action $action,
        string $event,
        string $message,
        ?User $actor,
        array $details = [],
        string $targetRole = 'chef_service'
    ): void {
        DB::afterCommit(function () use ($action, $event, $message, $actor, $details, $targetRole): void {
            ActionLog::query()->create([
                'action_id' => (int) $action->id,
                'niveau' => 'info',
                'type_evenement' => $event,
                'message' => $message,
                'details' => $details,
                'cible_role' => $targetRole,
                'utilisateur_id' => $actor?->id,
            ]);
        });
    }
}
