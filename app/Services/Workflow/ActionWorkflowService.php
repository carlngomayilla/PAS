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
 * Cycle : non_demarre → en_cours → soumis → validé ✓ / rejeté.
 *
 *   - record*Progress() : enregistrement brouillon (Save). Recalcule la
 *     performance PROVISOIRE. Aucune contrainte.
 *   - submit*()         : soumission au chef (Submit). Vérifie la conformité.
 *   - review*()         : décision du chef. Valider fige la performance
 *     OFFICIELLE ; rejeter renvoie à l'agent avec motif.
 *
 * Délègue tout le calcul à ActionPerformanceCalculator (service pur).
 */
class ActionWorkflowService
{
    public function __construct(
        private readonly ActionPerformanceCalculator $calculator
    ) {
    }

    // ── ACTION SIMPLE (quantitative / non quantitative) ──────────────────────

    /**
     * Enregistrement brouillon d'une action simple (Save).
     *
     * @param array{quantite_realisee?:mixed,commentaire?:?string,difficulte?:?string} $data
     */
    public function recordActionProgress(Action $action, array $data, ?User $actor = null): Action
    {
        if ($action->isQuantitative() && array_key_exists('quantite_realisee', $data)) {
            $action->quantite_realisee = max(0.0, (float) ($data['quantite_realisee'] ?? 0));
        }

        $provisional = $this->calculator->provisionalPerformance($action);

        $action->forceFill([
            'progression_reelle' => $provisional,
            'statut_performance' => $this->calculator->performanceStatus($provisional),
            'statut' => ActionTrackingService::STATUS_EN_COURS,
            'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
            // Tant que non soumise, on reste en non_soumise / correction.
            'statut_validation' => in_array((string) $action->statut_validation, [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
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
     * @param array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool} $data
     *
     * @throws \InvalidArgumentException si la conformité n'est pas remplie.
     */
    public function submitAction(Action $action, array $data, ?User $actor = null): Action
    {
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
        ])->save();

        $this->log($action, 'action_soumise_validation', 'Action soumise au chef de service.', $actor, [
            'progression_provisoire' => $provisional,
        ]);

        return $action->refresh();
    }

    /**
     * Décision du chef sur une action simple.
     */
    public function reviewAction(Action $action, bool $approve, ?string $motif, ?User $actor = null): Action
    {
        if ($approve) {
            $official = $this->calculator->provisionalPerformance($action);

            $action->forceFill([
                'official_progress_percent' => $official,
                'progression_reelle' => $official,
                'statut_performance' => $this->calculator->performanceStatus($official),
                'statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                'statut' => ActionTrackingService::STATUS_CLOTUREE,
                'statut_dynamique' => ActionTrackingService::STATUS_CLOTUREE,
                'date_fin_reelle' => $action->date_fin_reelle ?: now()->toDateString(),
                'cloture_le' => $action->cloture_le ?: now(),
                'cloture_par' => $actor?->id ?? $action->cloture_par,
                'evalue_le' => now(),
                'evalue_par' => $actor?->id,
                'motif_validation_chef' => $motif,
            ])->save();

            $this->log($action, 'action_validee_chef', 'Action validée par le chef de service.', $actor, [
                'performance_officielle' => $official,
            ]);

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

    // ── SOUS-ACTION (action composée) ────────────────────────────────────────

    /**
     * Enregistrement brouillon d'une sous-action (Save).
     *
     * @param array{quantite_realisee?:mixed,resultat_obtenu?:?string,commentaire?:?string} $data
     */
    public function recordSubActionProgress(SousAction $sousAction, array $data, ?User $actor = null): SousAction
    {
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
     * @param array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool} $data
     *
     * @throws \InvalidArgumentException si la conformité n'est pas remplie.
     */
    public function submitSubAction(SousAction $sousAction, array $data, ?User $actor = null): SousAction
    {
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
        $alreadyValidated = $validationStatus === ActionTrackingService::VALIDATION_VALIDEE_CHEF;

        $payload = [
            'progression_reelle' => $provisional,
            'statut_performance' => $this->calculator->performanceStatus($provisional),
        ];

        if ($allValidated) {
            if ($alreadyValidated) {
                $payload['statut'] = ActionTrackingService::STATUS_CLOTUREE;
                $payload['statut_dynamique'] = ActionTrackingService::STATUS_CLOTUREE;
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

        if ($allValidated && ! $alreadySubmitted && ! $alreadyValidated) {
            $this->log($action, 'action_soumise_validation', 'Action composee soumise au chef de service (toutes les sous-actions sont validees).', $actor, [
                'progression_provisoire' => $provisional,
            ]);
        }

        return $action->refresh();
    }

    /**
     * @param array{commentaire?:?string,difficulte?:?string,has_new_proof?:bool} $data
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
     * @param array<string, mixed> $details
     */
    private function log(Action $action, string $event, string $message, ?User $actor, array $details = []): void
    {
        DB::afterCommit(function () use ($action, $event, $message, $actor, $details): void {
            ActionLog::query()->create([
                'action_id' => (int) $action->id,
                'niveau' => 'info',
                'type_evenement' => $event,
                'message' => $message,
                'details' => $details,
                'cible_role' => 'chef_service',
                'utilisateur_id' => $actor?->id,
            ]);
        });
    }
}
