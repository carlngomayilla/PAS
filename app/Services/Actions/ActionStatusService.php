<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\SousAction;
use App\Services\ActionCalculationSettings;
use Illuminate\Support\Collection;

class ActionStatusService
{
    /**
     * @var list<string>
     */
    // Valeurs en dur (string) plutôt que via les constantes
    // ActionTrackingService::VALIDATION_* pour éviter toute dépendance de
    // chargement entre classes (la définition de const arrays est évaluée à
    // la première utilisation de la classe). Les valeurs `*_direction` sont
    // conservees pour reconnaitre les actions historiques avant la migration
    // de purge.
    private const STARTED_VALIDATION_STATUSES = [
        'soumise_chef',
        'correction_demandee',
        'rejetee_chef',
        'validee_chef',
        'rejetee_direction',
        'validee_direction',
        'en_validation_chef',
        'soumise_direction',
        'en_validation_direction',
    ];

    /**
     * @var list<string>
     */
    private const STARTED_DYNAMIC_STATUSES = [
        ActionTrackingService::STATUS_EN_COURS,
        ActionTrackingService::STATUS_A_RISQUE,
        ActionTrackingService::STATUS_EN_AVANCE,
        ActionTrackingService::STATUS_EN_RETARD,
        ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
        ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
        ActionTrackingService::STATUS_A_CORRIGER,
        ActionTrackingService::STATUS_CLOTUREE,
        ActionTrackingService::STATUS_SUSPENDU,
        ActionTrackingService::STATUS_ANNULE,
        'achevee',
        'acheve',
    ];

    /**
     * @var list<string>
     */
    private const EXECUTION_LOG_TYPES = [
        'semaine_renseignee',
        'sous_action_mise_a_jour',
        'sous_action_effectuee',
        'execution_quantitative',
        'action_soumise_validation',
        'action_validee_chef',
        'action_rejetee_chef',
        'action_correction_demandee',
        'action_validee_direction',
        'action_rejetee_direction',
    ];

    /**
     * @var list<string>
     */
    private const EXECUTION_JUSTIFICATIF_CATEGORIES = [
        'hebdomadaire',
        'final',
        'execution_quantitative',
        'execution_non_quantitative',
        'execution_mixte',
        'sous_action',
    ];

    /**
     * @var list<string>
     */
    private const NON_FINAL_VALIDATION_STATUSES = [
        ActionTrackingService::VALIDATION_SOUMISE_CHEF,
        ActionTrackingService::VALIDATION_REJETEE_CHEF,
        ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
        ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
        'en_validation_chef',
        'soumise_direction',
        'en_validation_direction',
    ];

    /**
     * @var list<string>
     */
    private const FINAL_VALIDATION_STATUSES = [
        ActionTrackingService::VALIDATION_VALIDEE_CHEF,
        ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
    ];

    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings
    ) {
    }

    public function isStarted(Action $action): bool
    {
        return $this->hasProgress($action)
            || $this->hasStartedWorkflow($action)
            || $this->hasRealStartDate($action)
            || $this->hasExecutionComment($action)
            || $this->hasProof($action)
            || $this->hasExecutionHistory($action);
    }

    public function isNotStarted(Action $action): bool
    {
        return ! $this->isStarted($action);
    }

    /**
     * Regle metier ANBG : une action importee sans `type_action` reste "A
     * parametrer". Une ligne importee avec `type_action` valide (Q, NQ ou M)
     * est deja officielle et passe `statut_parametrage = parametre`.
     * Tant qu'elle est 'a_parametrer', l'action n'est ni "non demarree" ni
     * "en cours" : elle n'existe pas encore officiellement dans le PTA.
     */
    public function isPendingSetup(Action $action): bool
    {
        return strtolower(trim((string) ($action->statut_parametrage ?? ''))) === 'a_parametrer';
    }

    public function isCompleted(Action $action): bool
    {
        $dynamicStatus = strtolower(trim((string) ($action->statut_dynamique ?? $action->statut ?? '')));
        $validationStatus = strtolower(trim((string) ($action->statut_validation ?? '')));

        if (in_array($validationStatus, self::NON_FINAL_VALIDATION_STATUSES, true)) {
            return false;
        }

        return in_array($dynamicStatus, [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
            ActionTrackingService::STATUS_CLOTUREE,
            'acheve',
            'achevee',
            'termine',
            'terminee',
        ], true)
            // Validation finale chef de service (etape terminale du workflow ANBG)
            // ou validation direction historique : l'action est consideree achevee.
            || in_array($validationStatus, self::FINAL_VALIDATION_STATUSES, true)
            || $action->cloture_le !== null;
    }

    public function isOfficiallyValidated(Action $action, ?string $level = null): bool
    {
        $allowedStatuses = $this->actionCalculationSettings->validationStatusesFrom(
            $level ?? $this->actionCalculationSettings->officialValidationStatus()
        );

        return in_array((string) ($action->statut_validation ?? ''), $allowedStatuses, true);
    }

    public function dashboardStatus(Action $action): string
    {
        // 1) A parametrer : action importee pas encore enregistree officiellement
        //    dans le PTA. Prioritaire sur tout autre etat — elle ne compte ni
        //    comme "non demarree" ni comme "en cours".
        if ($this->isPendingSetup($action)) {
            return 'a_parametrer';
        }

        // 2) Achevee / validee : statut termine, cloturee, date de fin reelle,
        //    100% atteint, ou validation chef/direction.
        if ($this->isCompleted($action)) {
            return 'acheve';
        }

        // 3) Non demarree : action enregistree dans le PTA mais aucun suivi
        //    d'execution n'a encore commence.
        if ($this->isNotStarted($action)) {
            return 'non_demarre';
        }

        // 4) Suivi engage : on derive l'etat fin a partir du statut dynamique.
        $dynamicStatus = strtolower(trim((string) ($action->statut_dynamique ?? $action->statut ?? '')));

        return match ($dynamicStatus) {
            ActionTrackingService::STATUS_A_RISQUE => 'a_risque',
            ActionTrackingService::STATUS_EN_AVANCE => 'en_avance',
            ActionTrackingService::STATUS_EN_RETARD => 'en_retard',
            ActionTrackingService::STATUS_A_CORRIGER => 'a_corriger',
            ActionTrackingService::STATUS_SUSPENDU => 'suspendu',
            ActionTrackingService::STATUS_ANNULE => 'annule',
            default => 'en_cours',
        };
    }

    private function hasProgress(Action $action): bool
    {
        foreach ([
            'progression_reelle',
            'taux_global',
            'taux_realisation_global',
            'avancement_operationnel',
            'taux_atteinte_cible',
            'quantite_realisee',
        ] as $field) {
            if ((float) ($action->{$field} ?? 0) > 0.0) {
                return true;
            }
        }

        return $this->subActions($action)
            ->contains(fn (SousAction $subAction): bool => $this->isStartedSubAction($subAction));
    }

    private function hasStartedWorkflow(Action $action): bool
    {
        $validationStatus = strtolower(trim((string) ($action->statut_validation ?? '')));
        $dynamicStatus = strtolower(trim((string) ($action->statut_dynamique ?? $action->statut ?? '')));

        return in_array($validationStatus, self::STARTED_VALIDATION_STATUSES, true)
            || in_array($dynamicStatus, self::STARTED_DYNAMIC_STATUSES, true)
            || $action->soumise_le !== null
            || $action->soumise_par !== null
            || $action->evalue_le !== null
            || $action->cloture_le !== null
            || $action->date_fin_reelle !== null;
    }

    private function hasRealStartDate(Action $action): bool
    {
        foreach (['date_debut_reelle', 'started_at', 'demarre_le'] as $field) {
            if ($action->getAttribute($field) !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasExecutionComment(Action $action): bool
    {
        foreach ([
            'rapport_final',
            'resultat_cloture',
            'difficultes_rencontrees',
            'mesures_correctives',
            'justification_cloture',
            // evaluation_commentaire remplace par motif_validation_chef (spec v2).
            'motif_validation_chef',
        ] as $field) {
            if (trim((string) ($action->{$field} ?? '')) !== '') {
                return true;
            }
        }

        return $this->hasExecutionDiscussion($action);
    }

    private function hasProof(Action $action): bool
    {
        if ($action->relationLoaded('justificatifs')) {
            return $action->justificatifs->contains(
                fn ($justificatif): bool => $this->isExecutionJustificatif($justificatif)
            );
        }

        if ($action->exists && $action->justificatifs()
            ->where(function ($query): void {
                $query
                    ->whereIn('categorie', self::EXECUTION_JUSTIFICATIF_CATEGORIES)
                    ->orWhereNotNull('sous_action_id');
            })
            ->exists()
        ) {
            return true;
        }

        return $this->subActions($action)
            ->contains(function (SousAction $subAction): bool {
                if ($subAction->relationLoaded('justificatifs')) {
                    return $subAction->justificatifs->isNotEmpty();
                }

                return $subAction->exists && $subAction->justificatifs()->exists();
            });
    }

    private function hasExecutionHistory(Action $action): bool
    {
        if ($action->relationLoaded('actionLogs')) {
            return $action->actionLogs
                ->contains(fn (ActionLog $log): bool => in_array((string) $log->type_evenement, self::EXECUTION_LOG_TYPES, true));
        }

        return $action->exists
            && $action->actionLogs()
                ->whereIn('type_evenement', self::EXECUTION_LOG_TYPES)
                ->exists();
    }

    private function hasExecutionDiscussion(Action $action): bool
    {
        if ($action->relationLoaded('discussionEntries')) {
            return $action->discussionEntries
                ->contains(fn (ActionLog $log): bool => in_array((string) $log->type_evenement, self::EXECUTION_LOG_TYPES, true));
        }

        return false;
    }

    private function isExecutionJustificatif(mixed $justificatif): bool
    {
        $category = strtolower(trim((string) ($justificatif->categorie ?? '')));

        return in_array($category, self::EXECUTION_JUSTIFICATIF_CATEGORIES, true)
            || $justificatif->sous_action_id !== null;
    }

    private function isStartedSubAction(SousAction $subAction): bool
    {
        $status = strtolower(trim((string) ($subAction->statut ?? '')));

        return (bool) ($subAction->est_effectuee ?? false)
            || $subAction->completed_at !== null
            || $subAction->date_realisation !== null
            || (float) ($subAction->taux_execution ?? $subAction->progression_reelle ?? $subAction->quantite_realisee ?? 0) > 0.0
            || in_array($status, ['en_cours', 'effectuee', 'terminee', 'termine', 'realisee', 'realise', 'en_attente_validation_chef', 'validee', 'validee_chef', 'rejetee_a_corriger', 'cloturee'], true);
    }

    /**
     * @return Collection<int, SousAction>
     */
    private function subActions(Action $action): Collection
    {
        return $action->relationLoaded('sousActions')
            ? $action->sousActions
            : ($action->exists ? $action->sousActions()->get() : collect());
    }
}
