<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\ActionLog;
use App\Models\ActionWeek;
use App\Models\Justificatif;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\ActionPerformanceService;
use App\Services\NotificationPolicySettings;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\WorkflowSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Service central du suivi des actions.
 *
 * Ce service est le cœur de l'application. Il gère :
 * - Les statuts dynamiques des actions (en cours, en retard, achevé...)
 * - Le workflow de validation (soumission, approbation chef/direction)
 * - Le calcul et la mise à jour des métriques (KPI, progression)
 * - La gestion des semaines d'exécution et des justificatifs
 * - Le circuit de financement (DAF, DG)
 * - Les commentaires et discussions sur les actions
 */
class ActionTrackingService
{
    public function __construct(
        private readonly WorkflowSettings $workflowSettings,
        private readonly ActionManagementSettings $actionManagementSettings,
        private readonly NotificationPolicySettings $notificationPolicySettings,
        private readonly ActionProgressService $actionProgressService,
        private readonly ActionPerformanceService $actionPerformanceService
    ) {
    }

    // ── CONSTANTES DE FRÉQUENCE D'EXÉCUTION ───────────────────────────────────
    // Fréquence à laquelle l'agent soumet ses rapports de progression.
    public const FREQUENCE_INSTANTANEE = 'instantanee';
    public const FREQUENCE_JOURNALIERE = 'journaliere';
    public const FREQUENCE_HEBDOMADAIRE = 'hebdomadaire';
    public const FREQUENCE_MENSUELLE = 'mensuelle';
    public const FREQUENCE_ANNUELLE = 'annuelle';

    // ── CONSTANTES DE STATUT DYNAMIQUE ────────────────────────────────────────
    // Statut calculé automatiquement par le système selon la progression et les délais.
    public const STATUS_NON_DEMARRE = 'non_demarre';
    public const STATUS_EN_COURS = 'en_cours';
    public const STATUS_A_RISQUE = 'a_risque';        // À surveiller (proche de l'échéance)
    public const STATUS_EN_AVANCE = 'en_avance';
    public const STATUS_EN_RETARD = 'en_retard';
    public const STATUS_SUSPENDU = 'suspendu';
    public const STATUS_ANNULE = 'annule';
    public const STATUS_ACHEVE_DANS_DELAI = 'acheve_dans_delai';
    public const STATUS_ACHEVE_HORS_DELAI = 'acheve_hors_delai';
    public const STATUS_A_CORRIGER = 'a_corriger';
    public const STATUS_CLOTUREE = 'cloturee';

    // Nombre de jours avant l'échéance à partir duquel une action passe en "à surveiller".
    public const RISK_ALERT_THRESHOLD_DAYS = 3;

    // ── CONSTANTES DE VALIDATION ──────────────────────────────────────────────
    // Circuit de validation : agent → chef de service → direction.
    public const VALIDATION_NON_SOUMISE = 'non_soumise';
    public const VALIDATION_SOUMISE_CHEF = 'soumise_chef';
    public const VALIDATION_REJETEE_CHEF = 'rejetee_chef';
    public const VALIDATION_VALIDEE_CHEF = 'validee_chef';
    public const VALIDATION_REJETEE_DIRECTION = 'rejetee_direction';
    public const VALIDATION_VALIDEE_DIRECTION = 'validee_direction';

    // ── CONSTANTES DE FINANCEMENT ─────────────────────────────────────────────
    // Décisions possibles sur une demande de financement d'action.
    public const FINANCEMENT_DECISION_VALIDER = 'valider';   // DAF : dossier complet, transmis au DG
    public const FINANCEMENT_DECISION_REJETER = 'rejeter';   // DAF : dossier incomplet, retour agent
    public const FINANCEMENT_DECISION_ACCORDER = 'accorder'; // DG  : financement accordé
    public const FINANCEMENT_DECISION_REFUSER = 'refuser';   // DG  : financement refusé

    /**
     * @return array<int, string>
     */
    /**
     * Liste centralisée des statuts considérés comme "action terminée".
     *
     * Utilisée à la fois par le dashboard, le reporting (Excel/CSV/PDF) et le
     * monitoring pour garantir que toutes les surfaces comptent les mêmes
     * actions comme terminées. Inclut volontairement les actions clôturées.
     *
     * @return list<string>
     */
    public static function completedActionStatuses(): array
    {
        return [
            self::STATUS_ACHEVE_DANS_DELAI,
            self::STATUS_ACHEVE_HORS_DELAI,
            self::STATUS_SUSPENDU,
            self::STATUS_ANNULE,
            self::STATUS_CLOTUREE,
        ];
    }

    public static function dynamicStatusOptions(): array
    {
        return [
            self::STATUS_NON_DEMARRE,
            self::STATUS_EN_COURS,
            self::STATUS_A_RISQUE,
            self::STATUS_EN_AVANCE,
            self::STATUS_EN_RETARD,
            self::STATUS_SUSPENDU,
            self::STATUS_ANNULE,
            self::STATUS_ACHEVE_DANS_DELAI,
            self::STATUS_ACHEVE_HORS_DELAI,
            self::STATUS_A_CORRIGER,
            self::STATUS_CLOTUREE,
        ];
    }

    // ── PRIORISATION ──────────────────────────────────────────────────────────

    /**
     * Calcule un score de priorité pour trier les actions en retard.
     * Plus le score est élevé, plus l'action est urgente à traiter.
     */
    public function computeActionDelayPriorityScore(Action $action, Carbon $today): float
    {
        $lateDays = 0;
        if (
            $action->date_echeance instanceof Carbon
            && ! in_array((string) $action->statut_dynamique, [
                self::STATUS_ACHEVE_DANS_DELAI,
                self::STATUS_ACHEVE_HORS_DELAI,
                self::STATUS_CLOTUREE,
                self::STATUS_ANNULE,
            ], true)
            && $action->date_echeance->lt($today)
        ) {
            $lateDays = $action->date_echeance->diffInDays($today);
        }

        $gap = max(0.0, (float) ($action->progression_theorique ?? 0) - (float) ($action->progression_reelle ?? 0));
        $score = ($lateDays * 2.0) + $gap;

        if ((string) ($action->statut_dynamique ?? '') === self::STATUS_EN_RETARD) {
            $score += 25.0;
        }
        if ((bool) $action->financement_requis) {
            $score += 4.0;
        }

        return max(0.0, $score);
    }

    /**
     * @return array<int, string>
     */
    public static function executionFrequencyOptions(): array
    {
        return [
            self::FREQUENCE_INSTANTANEE,
            self::FREQUENCE_JOURNALIERE,
            self::FREQUENCE_HEBDOMADAIRE,
            self::FREQUENCE_MENSUELLE,
            self::FREQUENCE_ANNUELLE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function validationStatusOptions(): array
    {
        return [
            self::VALIDATION_NON_SOUMISE,
            self::VALIDATION_SOUMISE_CHEF,
            self::VALIDATION_REJETEE_CHEF,
            self::VALIDATION_VALIDEE_CHEF,
            self::VALIDATION_REJETEE_DIRECTION,
            self::VALIDATION_VALIDEE_DIRECTION,
        ];
    }

    // ── INITIALISATION ET SEMAINES D'EXÉCUTION ────────────────────────────────

    /**
     * Initialise le suivi d'une action nouvellement créée.
     * Génère les semaines d'exécution et place l'action en statut "non démarré".
     */
    public function initializeActionTracking(Action $action, ?User $actor = null): void
    {
        if ($action->usesStructuredProgressTracking()) {
            $action->weeks()->delete();
            $this->refreshActionMetrics($action);
        } else {
            $this->regenerateWeeks($action);
            $this->refreshActionMetrics($action);
        }

        $this->createLogIfMissingToday(
            $action,
            'action_initialisee',
            'info',
            $action->usesStructuredProgressTracking() ? 'Action initialisee avec suivi structure.' : 'Action initialisee avec suivi periodique automatique.',
            [
                'type_cible' => $action->type_cible,
                'mode_evaluation' => $action->resolvedEvaluationMode(),
                'date_debut' => optional($action->date_debut)->toDateString(),
                'date_fin' => optional($action->date_fin)->toDateString(),
            ],
            'responsable',
            $actor?->id
        );
    }

    public function canRegenerateWeeks(Action $action): bool
    {
        if ($action->usesStructuredProgressTracking()) {
            return true;
        }

        return ! $action->weeks()
            ->where('est_renseignee', true)
            ->exists();
    }

    public function regenerateWeeks(Action $action): void
    {
        $action->refresh();

        if ($action->usesStructuredProgressTracking()) {
            $action->weeks()->delete();
            return;
        }

        $start = $action->date_debut !== null ? Carbon::parse($action->date_debut)->startOfDay() : null;
        $end = $action->date_fin !== null ? Carbon::parse($action->date_fin)->startOfDay() : null;
        $frequence = (string) ($action->frequence_execution ?? self::FREQUENCE_HEBDOMADAIRE);
        if (! in_array($frequence, self::executionFrequencyOptions(), true)) {
            $frequence = self::FREQUENCE_HEBDOMADAIRE;
        }

        if ($start === null || $end === null || $start->gt($end)) {
            return;
        }

        $action->weeks()->delete();

        $rows = [];
        $periodNumber = 1;
        $currentStart = $start->copy();

        if ($frequence === self::FREQUENCE_INSTANTANEE) {
            $rows[] = [
                'numero_semaine' => 1,
                'date_debut' => $currentStart->toDateString(),
                'date_fin' => $end->copy()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        } else {
            while ($currentStart->lte($end)) {
                $currentEnd = match ($frequence) {
                    self::FREQUENCE_JOURNALIERE => $currentStart->copy(),
                    self::FREQUENCE_MENSUELLE => $currentStart->copy()->endOfMonth(),
                    self::FREQUENCE_ANNUELLE => $currentStart->copy()->endOfYear(),
                    default => $currentStart->copy()->addDays(6),
                };

                if ($currentEnd->gt($end)) {
                    $currentEnd = $end->copy();
                }

                $rows[] = [
                    'numero_semaine' => $periodNumber,
                    'date_debut' => $currentStart->toDateString(),
                    'date_fin' => $currentEnd->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $periodNumber++;
                $currentStart = $currentEnd->copy()->addDay();
            }
        }

        if ($rows !== []) {
            $action->weeks()->createMany($rows);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** Enregistre le rapport de progression d'une semaine d'exécution. */
    public function submitWeek(ActionWeek $week, array $payload, ?User $actor = null): ActionWeek
    {
        $week->loadMissing('action');
        $action = $week->action;

        if (! $action instanceof Action) {
            throw new \RuntimeException('Action introuvable pour la semaine.');
        }

        $updates = [
            'commentaire' => $payload['commentaire'] ?? null,
            'difficultes' => $payload['difficultes'] ?? null,
            'mesures_correctives' => $payload['mesures_correctives'] ?? null,
            'est_renseignee' => true,
            'saisi_le' => now(),
            'saisi_par' => $actor?->id,
        ];

        if ($action->type_cible === 'quantitative') {
            $updates['quantite_realisee'] = $payload['quantite_realisee'] ?? 0;
            $updates['taches_realisees'] = null;
            $updates['avancement_estime'] = null;
        } else {
            $updates['quantite_realisee'] = null;
            $updates['taches_realisees'] = $payload['taches_realisees'] ?? null;
            $updates['avancement_estime'] = $payload['avancement_estime'] ?? 0;
        }

        $week->fill($updates);
        $week->save();

        $this->createLogIfMissingToday(
            $action,
            'semaine_renseignee',
            'info',
            sprintf('Periode %d renseignee.', (int) $week->numero_semaine),
            ['numero_semaine' => (int) $week->numero_semaine],
            'responsable',
            $actor?->id,
            $week
        );

        $this->refreshActionMetrics($action);

        return $week->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    // ── CLÔTURE ET VALIDATION ─────────────────────────────────────────────────

    /**
     * L'agent soumet une demande de clôture de son action.
     * La demande part en validation chez le chef de service.
     */
    public function submitClosureForReview(Action $action, array $payload, ?User $actor = null): Action
    {
        if ($action->statut_dynamique === self::STATUS_NON_DEMARRE) {
            throw new \InvalidArgumentException('Une action non démarrée ne peut pas être soumise à validation.');
        }

        $submissionTarget = $this->workflowSettings->actionSubmissionTarget();
        $status = match ($submissionTarget) {
            'direction' => self::VALIDATION_VALIDEE_CHEF,
            'final' => self::VALIDATION_VALIDEE_DIRECTION,
            default => self::VALIDATION_SOUMISE_CHEF,
        };
        $message = match ($submissionTarget) {
            'direction' => 'Action soumise directement a la direction.',
            'final' => 'Action cloturee sans circuit de validation supplementaire.',
            default => 'Action soumise au chef de service pour evaluation.',
        };

        $closureData = [
            'date_fin_reelle' => $payload['date_fin_reelle'] ?? $action->date_fin_reelle,
            'rapport_final' => $payload['rapport_final'] ?? $action->rapport_final,
            'validation_hierarchique' => $submissionTarget === 'final',
            'validation_sans_correction' => null,
            'statut_validation' => $status,
            'soumise_par' => $actor?->id,
            'soumise_le' => now(),
            'evalue_par' => null,
            'evalue_le' => null,
            'evaluation_note' => null,
            'evaluation_commentaire' => null,
            'direction_valide_par' => null,
            'direction_valide_le' => null,
            'direction_evaluation_note' => null,
            'direction_evaluation_commentaire' => null,
        ];

        foreach (['resultat_cloture', 'difficultes_rencontrees', 'mesures_correctives', 'justification_cloture'] as $field) {
            if (array_key_exists($field, $payload)) {
                $closureData[$field] = $payload[$field];
            }
        }

        if ($submissionTarget === 'final') {
            $closureData['statut_dynamique'] = self::STATUS_CLOTUREE;
            $closureData['cloture_par'] = $actor?->id;
            $closureData['cloture_le'] = now();
        }

        $action->fill($closureData);
        $action->save();

        $this->createLogIfMissingToday(
            $action,
            'action_soumise_validation',
            'info',
            $message,
            [
                'date_fin_reelle' => optional($action->date_fin_reelle)->toDateString(),
                'workflow_target' => $submissionTarget,
            ],
            match ($submissionTarget) {
                'direction' => 'direction',
                'final' => 'responsable',
                default => 'chef_service',
            },
            $actor?->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['rapport_final'] ?? $message),
            'action_soumise_validation',
            'info',
            [
                'date_fin_reelle' => optional($action->date_fin_reelle)->toDateString(),
                'rapport_final' => $payload['rapport_final'] ?? null,
                'workflow_target' => $submissionTarget,
            ],
            $actor
        );

        return $this->refreshActionMetrics($action);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function reviewClosureSubmission(Action $action, array $payload, ?User $actor = null): Action
    {
        return $this->reviewClosureByChef($action, $payload, $actor);
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** Le chef de service approuve ou rejette la demande de clôture de l'agent. */
    public function reviewClosureByChef(Action $action, array $payload, ?User $actor = null): Action
    {
        $decision = (string) ($payload['decision_validation'] ?? 'rejeter');
        $isApproved = $decision === 'valider';
        $directionEnabled = $this->workflowSettings->directionValidationEnabled();

        $action->fill([
            'statut_validation' => $isApproved
                ? ($directionEnabled ? self::VALIDATION_VALIDEE_CHEF : self::VALIDATION_VALIDEE_DIRECTION)
                : self::VALIDATION_REJETEE_CHEF,
            'validation_hierarchique' => $isApproved ? ! $directionEnabled : false,
            'validation_sans_correction' => $isApproved
                ? ($payload['validation_sans_correction'] ?? $action->validation_sans_correction)
                : null,
            'evalue_par' => $actor?->id,
            'evalue_le' => now(),
            'evaluation_note' => $payload['evaluation_note'] ?? null,
            'evaluation_commentaire' => $payload['evaluation_commentaire'] ?? null,
        ]);
        $action->save();

        $this->createLogIfMissingToday(
            $action,
            $isApproved ? 'action_validee_chef' : 'action_rejetee_chef',
            $isApproved ? 'info' : 'warning',
            $isApproved
                ? ($directionEnabled
                    ? 'Action validee par le chef de service.'
                    : 'Action validee par le chef de service. Validation finale du circuit.')
                : 'Action rejetee par le chef de service.',
            [
                'evaluation_note' => $action->evaluation_note,
                'statut_validation' => $action->statut_validation,
                'workflow_final_stage' => $directionEnabled ? 'direction' : 'service',
            ],
            'responsable',
            $actor?->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['evaluation_commentaire'] ?? ($isApproved
                ? ($directionEnabled ? 'Validation chef de service.' : 'Validation finale chef de service.')
                : 'Rejet chef de service.')),
            $isApproved ? 'action_validee_chef' : 'action_rejetee_chef',
            $isApproved ? 'info' : 'warning',
            [
                'evaluation_note' => $action->evaluation_note,
                'statut_validation' => $action->statut_validation,
                'validation_sans_correction' => $action->validation_sans_correction,
                'workflow_final_stage' => $directionEnabled ? 'direction' : 'service',
            ],
            $actor
        );

        return $this->refreshActionMetrics($action);
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** La direction approuve ou rejette la clôture déjà validée par le chef de service. */
    public function reviewClosureByDirection(Action $action, array $payload, ?User $actor = null): Action
    {
        $decision = (string) ($payload['decision_validation'] ?? 'rejeter');
        $isApproved = $decision === 'valider';

        $action->fill([
            'statut_validation' => $isApproved ? self::VALIDATION_VALIDEE_DIRECTION : self::VALIDATION_REJETEE_DIRECTION,
            'validation_hierarchique' => $isApproved,
            'direction_valide_par' => $actor?->id,
            'direction_valide_le' => now(),
            'direction_evaluation_note' => $payload['evaluation_note'] ?? null,
            'direction_evaluation_commentaire' => $payload['evaluation_commentaire'] ?? null,
        ]);
        $action->save();

        $this->createLogIfMissingToday(
            $action,
            $isApproved ? 'action_validee_direction' : 'action_rejetee_direction',
            $isApproved ? 'info' : 'warning',
            $isApproved
                ? 'Action validee par le directeur.'
                : 'Action rejetee par le directeur.',
            [
                'evaluation_note' => $action->direction_evaluation_note,
                'statut_validation' => $action->statut_validation,
            ],
            'responsable',
            $actor?->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['evaluation_commentaire'] ?? ($isApproved
                ? 'Validation direction.'
                : 'Rejet direction.')),
            $isApproved ? 'action_validee_direction' : 'action_rejetee_direction',
            $isApproved ? 'info' : 'warning',
            [
                'evaluation_note' => $action->direction_evaluation_note,
                'statut_validation' => $action->statut_validation,
            ],
            $actor
        );

        return $this->refreshActionMetrics($action);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function closeAction(Action $action, array $payload, ?User $actor = null): Action
    {
        return $this->submitClosureForReview($action, $payload, $actor);
    }

    // ── FINANCEMENT ───────────────────────────────────────────────────────────

    /**
     * Crée ou met à jour la demande de financement d'une action.
     * Déclenche une notification à la DAF si le financement est requis.
     */
    public function syncFinancingRequest(Action $action, ?User $actor = null): Action
    {
        if (! (bool) $action->financement_requis) {
            $action->fill([
                'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
            ]);
            $action->save();

            return $action->fresh();
        }

        $status = $action->financementStatus();
        if (in_array($status, [
            Action::FINANCEMENT_NON_REQUIS,
            Action::FINANCEMENT_REJETE_DAF,
            Action::FINANCEMENT_REFUSE_DG,
        ], true)) {
            $action->financement_statut = Action::FINANCEMENT_A_TRAITER_DAF;
        }

        if ($action->financement_soumis_le === null) {
            $action->financement_soumis_le = now();
        }

        $action->save();

        $this->createLogIfMissingToday(
            $action,
            'financement_demande',
            'info',
            'Besoin de financement transmis au circuit DAF.',
            [
                'montant_estime' => $action->montant_estime,
                'source_financement' => $action->source_financement,
                'statut_financement' => $action->financementStatus(),
            ],
            'daf',
            $actor?->id
        );

        return $action->fresh();
    }

    public function markFinancingNotificationSent(Action $action): Action
    {
        $action->forceFill(['financement_notifie_le' => now()])->save();

        return $action->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** La DAF examine la demande de financement et la valide ou la rejette. */
    public function reviewFinancingByDaf(Action $action, array $payload, User $actor): Action
    {
        $decision = (string) ($payload['decision_financement'] ?? self::FINANCEMENT_DECISION_REJETER);
        $approved = $decision === self::FINANCEMENT_DECISION_VALIDER;

        $action->fill([
            'financement_statut' => $approved ? Action::FINANCEMENT_VALIDE_DAF : Action::FINANCEMENT_REJETE_DAF,
            'financement_daf_par' => $actor->id,
            'financement_daf_le' => now(),
            'financement_daf_decision' => $decision,
            'financement_daf_commentaire' => $payload['commentaire_financement'] ?? null,
            'financement_montant_valide' => $approved
                ? ($payload['montant_valide'] ?? $action->montant_estime)
                : null,
            'financement_reference' => $approved
                ? ($payload['reference_financement'] ?? $action->financement_reference)
                : null,
            'financement_dg_par' => null,
            'financement_dg_le' => null,
            'financement_dg_decision' => null,
            'financement_dg_commentaire' => null,
        ]);
        $action->save();

        $this->createLogIfMissingToday(
            $action,
            $approved ? 'financement_valide_daf' : 'financement_rejete_daf',
            $approved ? 'info' : 'warning',
            $approved
                ? 'Financement valide par la DAF. Accord DG requis.'
                : 'Financement rejete par la DAF.',
            [
                'decision' => $decision,
                'montant_valide' => $action->financement_montant_valide,
                'reference' => $action->financement_reference,
                'commentaire' => $action->financement_daf_commentaire,
            ],
            $approved ? 'dg' : 'direction',
            $actor->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['commentaire_financement'] ?? ($approved
                ? 'Validation DAF du besoin de financement. Accord DG attendu.'
                : 'Rejet DAF du besoin de financement.')),
            $approved ? 'financement_valide_daf' : 'financement_rejete_daf',
            $approved ? 'info' : 'warning',
            [
                'decision' => $decision,
                'montant_valide' => $action->financement_montant_valide,
                'reference' => $action->financement_reference,
            ],
            $actor
        );

        return $action->fresh();
    }

    /**
     * @param array<string, mixed> $payload
     */
    /** Le DG accorde ou refuse définitivement le financement d'une action. */
    public function reviewFinancingByDg(Action $action, array $payload, User $actor): Action
    {
        $decision = (string) ($payload['decision_financement'] ?? self::FINANCEMENT_DECISION_REFUSER);
        $approved = $decision === self::FINANCEMENT_DECISION_ACCORDER;

        $action->fill([
            'financement_statut' => $approved ? Action::FINANCEMENT_ACCORDE_DG : Action::FINANCEMENT_REFUSE_DG,
            'financement_dg_par' => $actor->id,
            'financement_dg_le' => now(),
            'financement_dg_decision' => $decision,
            'financement_dg_commentaire' => $payload['commentaire_financement'] ?? null,
        ]);
        $action->save();

        $this->createLogIfMissingToday(
            $action,
            $approved ? 'financement_accord_dg' : 'financement_refus_dg',
            $approved ? 'info' : 'warning',
            $approved
                ? 'Accord DG donne pour le financement.'
                : 'Accord DG refuse pour le financement.',
            [
                'decision' => $decision,
                'commentaire' => $action->financement_dg_commentaire,
                'montant_valide' => $action->financement_montant_valide,
                'reference' => $action->financement_reference,
            ],
            'daf',
            $actor->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['commentaire_financement'] ?? ($approved
                ? 'Accord DG donne pour le financement.'
                : 'Accord DG refuse pour le financement.')),
            $approved ? 'financement_accord_dg' : 'financement_refus_dg',
            $approved ? 'info' : 'warning',
            [
                'decision' => $decision,
                'montant_valide' => $action->financement_montant_valide,
                'reference' => $action->financement_reference,
            ],
            $actor
        );

        return $action->fresh();
    }
    // ── MÉTRIQUES ET STATUT DYNAMIQUE ─────────────────────────────────────────

    /**
     * Recalcule et sauvegarde tous les indicateurs d'une action.
     * À appeler après chaque mise à jour de progression, de semaine, ou de sous-action.
     * Met à jour : progression réelle/théorique, KPI global, qualité, délai, performance.
     */
    public function refreshActionMetrics(Action $action, ?Carbon $referenceDate = null): Action
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $action->loadMissing('weeks', 'sousActions.justificatifs', 'actionKpi');

        if ($action->usesStructuredProgressTracking()) {
            return $this->refreshStructuredActionMetrics($action, $referenceDate);
        }

        $weeks = $action->weeks()
            ->orderBy('numero_semaine')
            ->get();

        $targetQuantity = (float) ($action->quantite_cible ?? 0);
        $cumulativeQuantity = 0.0;

        foreach ($weeks as $week) {
            if ($action->type_cible === 'quantitative') {
                $weeklyDone = $week->est_renseignee ? max(0.0, (float) ($week->quantite_realisee ?? 0)) : 0.0;
                $cumulativeQuantity += $weeklyDone;
                $weeklyRealProgress = $targetQuantity > 0
                    ? min(100.0, round(($cumulativeQuantity / $targetQuantity) * 100, 2))
                    : 0.0;
            } else {
                $weeklyRealProgress = $week->est_renseignee
                    ? min(100.0, max(0.0, (float) ($week->avancement_estime ?? 0)))
                    : 0.0;
            }

            $weeklyTheoreticalProgress = $this->calculateTheoreticalProgress(
                $action,
                Carbon::parse($week->date_fin)->endOfDay()
            );
            $weeklyGap = round($weeklyRealProgress - $weeklyTheoreticalProgress, 2);

            $week->fill([
                'quantite_cumulee' => round($cumulativeQuantity, 4),
                'progression_reelle' => $weeklyRealProgress,
                'progression_theorique' => $weeklyTheoreticalProgress,
                'ecart_progression' => $weeklyGap,
            ]);
            $week->save();
        }

        $realProgress = $this->actionPerformanceService->calculateRealProgress($action);
        $cumulativeQuantity = $this->actionPerformanceService->realizedQuantity($action);

        if ($this->actionManagementSettings->autoCompleteWhenTargetReached()
            && $action->date_fin_reelle === null
            && $realProgress >= 100.0
            && ! in_array((string) ($action->statut ?? ''), [self::STATUS_SUSPENDU, self::STATUS_ANNULE], true)) {
            $action->date_fin_reelle = $referenceDate->copy()->toDateString();
        }

        $theoreticalProgress = $this->calculateTheoreticalProgress($action, $referenceDate->copy()->endOfDay());
        $status = $this->determineDynamicStatus($action, $realProgress, $theoreticalProgress, $referenceDate);
        $legacyStatus = $this->mapLegacyStatus($status);
        $beforeStatus = (string) $action->statut_dynamique;

        $action->fill([
            'date_echeance' => $action->date_echeance ?? $action->date_fin,
            'progression_reelle' => $realProgress,
            'progression_theorique' => $theoreticalProgress,
            'statut_dynamique' => $status,
            'statut' => $legacyStatus,
        ]);
        $action->save();

        if ($status === self::STATUS_SUSPENDU) {
            $kpis = $this->frozenActionKpis($action)
                ?? $this->calculateActionKpis(
                    $action,
                    $realProgress,
                    $theoreticalProgress,
                    $cumulativeQuantity,
                    $referenceDate
                );
        } elseif ($status === self::STATUS_ANNULE) {
            $kpis = [
                'kpi_delai' => 0.0,
                'kpi_performance' => 0.0,
                'kpi_conformite' => 0.0,
                'kpi_qualite' => 0.0,
                'kpi_global' => 0.0,
            ];
        } else {
            $kpis = $this->calculateActionKpis(
                $action,
                $realProgress,
                $theoreticalProgress,
                $cumulativeQuantity,
                $referenceDate
            );
        }

        ActionKpi::query()->updateOrCreate(
            ['action_id' => $action->id],
            array_merge(
                $kpis,
                [
                    'progression_reelle' => $realProgress,
                    'progression_theorique' => $theoreticalProgress,
                    'statut_calcule' => $status,
                    'derniere_evaluation_at' => now(),
                ]
            )
        );

        $tauxRealisation = round(max(0.0, min(100.0, (float) ($kpis['kpi_performance'] ?? 0))), 2);
        $action->fill([
            'taux_performance' => $kpis['kpi_performance'] ?? null,
            'taux_conformite' => $kpis['kpi_conformite'] ?? null,
            'taux_delai' => $kpis['kpi_delai'] ?? null,
            'taux_realisation_global' => $tauxRealisation,
        ]);
        $action->save();

        if ($beforeStatus !== '' && $beforeStatus !== $status) {
            $this->createLogIfMissingToday(
                $action,
                'changement_statut',
                'info',
                sprintf('Statut dynamique mis a jour: %s -> %s', $beforeStatus, $status),
                ['from' => $beforeStatus, 'to' => $status],
                'chef_service'
            );

            if ($status === self::STATUS_SUSPENDU) {
                $this->createLogIfMissingToday(
                    $action,
                    'action_suspendue',
                    'warning',
                    'Action suspendue. Les indicateurs sont geles jusqu a reactivation.',
                    [
                        'statut_dynamique' => $status,
                        'progression_reelle' => $realProgress,
                    ],
                    'direction'
                );
            }

            if ($status === self::STATUS_ANNULE) {
                $this->createLogIfMissingToday(
                    $action,
                    'action_annulee',
                    'info',
                    'Action annulee. Les alertes automatiques sont desactivees.',
                    [
                        'statut_dynamique' => $status,
                    ],
                    'direction'
                );
            }
        }

        if (! in_array($status, [self::STATUS_SUSPENDU, self::STATUS_ANNULE], true)) {
            $this->generateAutomaticAlerts($action, $weeks, $realProgress, $theoreticalProgress, $kpis, $referenceDate);
        }

        return $action->fresh(['actionKpi', 'weeks', 'sousActions.justificatifs']);
    }

    private function refreshStructuredActionMetrics(Action $action, Carbon $referenceDate): Action
    {
        $metrics = $this->actionProgressService->compute($action, $referenceDate);
        $realProgress = (float) ($metrics['progression_reelle'] ?? 0);
        $theoreticalProgress = (float) ($metrics['progression_theorique'] ?? 0);
        $beforeStatus = (string) $action->statut_dynamique;

        if (
            $this->actionManagementSettings->autoCompleteWhenTargetReached()
            && $action->date_fin_reelle === null
            && $realProgress >= 100.0
            && ! in_array((string) ($action->statut ?? ''), [self::STATUS_SUSPENDU, self::STATUS_ANNULE], true)
        ) {
            $action->date_fin_reelle = $referenceDate->copy()->toDateString();
        }

        $status = $this->determineDynamicStatus($action, $realProgress, $theoreticalProgress, $referenceDate);
        $legacyStatus = $this->mapLegacyStatus($status);

        $updates = [
            'date_echeance' => $action->date_echeance ?? $action->date_fin,
            'quantite_realisee' => (float) ($metrics['quantite_realisee'] ?? $action->quantite_realisee ?? 0),
            'progression_reelle' => $realProgress,
            'progression_theorique' => $theoreticalProgress,
            'avancement_operationnel' => (float) ($metrics['avancement_operationnel'] ?? 0),
            'taux_atteinte_cible' => (float) ($metrics['taux_atteinte_cible'] ?? 0),
            'taux_global' => (float) ($metrics['taux_global'] ?? $realProgress),
            'statut_dynamique' => $status,
            'statut' => $legacyStatus,
        ];

        $optionalMetrics = [
            'reste_a_realiser' => (float) ($metrics['reste_a_realiser'] ?? 0),
            'taux_depassement' => (float) ($metrics['taux_depassement'] ?? 0),
            'statut_performance' => $action->resolvePerformanceStatus(
                (float) ($metrics['taux_atteinte_cible'] ?? 0),
                (float) ($metrics['taux_depassement'] ?? 0)
            ),
            'statut_execution_quantitative' => $action->resolveQuantitativeExecutionStatus(
                (float) ($metrics['cible_mesurable_attendue'] ?? 0) > 0
                    ? (((float) ($metrics['quantite_realisee'] ?? 0) / (float) $metrics['cible_mesurable_attendue']) * 100)
                    : 0.0
            ),
        ];

        foreach ($optionalMetrics as $column => $value) {
            if (Schema::hasColumn($action->getTable(), $column)) {
                $updates[$column] = $value;
            }
        }

        $action->fill($updates);
        $action->save();

        $quantitativeBase = $action->usesQuantitativeProgress()
            ? (float) ($metrics['quantite_realisee'] ?? 0)
            : (float) ($metrics['sous_actions_realisees'] ?? 0);

        $kpis = match ($status) {
            self::STATUS_SUSPENDU => $this->frozenActionKpis($action)
                ?? $this->calculateActionKpis($action, $realProgress, $theoreticalProgress, $quantitativeBase, $referenceDate),
            self::STATUS_ANNULE => [
                'kpi_delai' => 0.0,
                'kpi_performance' => 0.0,
                'kpi_conformite' => 0.0,
                'kpi_qualite' => 0.0,
                'kpi_global' => 0.0,
            ],
            default => $this->calculateActionKpis($action, $realProgress, $theoreticalProgress, $quantitativeBase, $referenceDate),
        };

        ActionKpi::query()->updateOrCreate(
            ['action_id' => $action->id],
            array_merge(
                $kpis,
                [
                    'progression_reelle' => $realProgress,
                    'progression_theorique' => $theoreticalProgress,
                    'statut_calcule' => $status,
                    'derniere_evaluation_at' => now(),
                ]
            )
        );

        $tauxRealisation = round(max(0.0, min(100.0, (float) ($kpis['kpi_performance'] ?? 0))), 2);

        $action->fill([
            'taux_performance' => $kpis['kpi_performance'] ?? null,
            'taux_conformite' => $kpis['kpi_conformite'] ?? null,
            'taux_delai' => $kpis['kpi_delai'] ?? null,
            'taux_realisation_global' => $tauxRealisation,
        ]);
        $action->save();

        if ($beforeStatus !== '' && $beforeStatus !== $status) {
            $this->createLogIfMissingToday(
                $action,
                'changement_statut',
                'info',
                sprintf('Statut dynamique mis a jour: %s -> %s', $beforeStatus, $status),
                ['from' => $beforeStatus, 'to' => $status],
                'chef_service'
            );

            if ($status === self::STATUS_SUSPENDU) {
                $this->createLogIfMissingToday(
                    $action,
                    'action_suspendue',
                    'warning',
                    'Action suspendue. Les indicateurs sont geles jusqu a reactivation.',
                    [
                        'statut_dynamique' => $status,
                        'progression_reelle' => $realProgress,
                    ],
                    'direction'
                );
            }

            if ($status === self::STATUS_ANNULE) {
                $this->createLogIfMissingToday(
                    $action,
                    'action_annulee',
                    'info',
                    'Action annulee. Les alertes automatiques sont desactivees.',
                    [
                        'statut_dynamique' => $status,
                    ],
                    'direction'
                );
            }
        }

        if (! in_array($status, [self::STATUS_SUSPENDU, self::STATUS_ANNULE], true)) {
            $weeks = $action->weeks()
                ->orderBy('numero_semaine')
                ->get();
            $this->generateAutomaticAlerts($action, $weeks, $realProgress, $theoreticalProgress, $kpis, $referenceDate);
        }

        return $action->fresh(['actionKpi', 'sousActions.justificatifs']);
    }
    // ── JUSTIFICATIFS ─────────────────────────────────────────────────────────

    /** Attache un fichier justificatif (PDF, image...) à une action. */
    public function addActionJustificatif(
        Action $action,
        ?ActionWeek $week,
        string $categorie,
        string $path,
        string $originalName,
        ?string $mimeType,
        ?int $size,
        ?string $description,
        ?User $actor = null,
        bool $encrypted = false
    ): Justificatif {
        return Justificatif::query()->create([
            'justifiable_type' => Action::class,
            'justifiable_id' => $action->id,
            'action_week_id' => $week?->id,
            'categorie' => $categorie,
            'nom_original' => $originalName,
            'chemin_stockage' => $path,
            'est_chiffre' => $encrypted,
            'mime_type' => $mimeType,
            'taille_octets' => $size,
            'description' => $description,
            'ajoute_par' => $actor?->id,
        ]);
    }

    private function calculateTheoreticalProgress(Action $action, Carbon $at): float
    {
        if ($action->date_debut === null || $action->date_fin === null) {
            return 0.0;
        }

        $start = Carbon::parse($action->date_debut)->startOfDay();
        $end = Carbon::parse($action->date_fin)->endOfDay();

        if ($at->lt($start)) {
            return 0.0;
        }

        if ($at->gte($end)) {
            return 100.0;
        }

        $totalDuration = max(1, $start->diffInSeconds($end));
        $elapsed = max(0, $start->diffInSeconds($at));

        return round(min(100.0, ($elapsed / $totalDuration) * 100), 2);
    }

    private function determineDynamicStatus(
        Action $action,
        float $realProgress,
        float $theoreticalProgress,
        Carbon $referenceDate
    ): string {
        $manualStatus = strtolower(trim((string) ($action->statut ?? '')));
        if ($manualStatus === self::STATUS_ANNULE) {
            return self::STATUS_ANNULE;
        }

        if ($manualStatus === self::STATUS_SUSPENDU) {
            return self::STATUS_SUSPENDU;
        }

        $startDate = $action->date_debut !== null ? Carbon::parse($action->date_debut)->startOfDay() : null;
        $endDate = $action->date_fin !== null ? Carbon::parse($action->date_fin)->endOfDay() : null;
        $actualEnd = $action->date_fin_reelle !== null ? Carbon::parse($action->date_fin_reelle)->endOfDay() : null;

        if ($actualEnd !== null) {
            $realEnd = $action->date_fin_reelle !== null
                ? Carbon::parse($action->date_fin_reelle)->endOfDay()
                : $referenceDate->copy()->endOfDay();

            if ($endDate !== null && $realEnd->lte($endDate)) {
                return self::STATUS_ACHEVE_DANS_DELAI;
            }

            return self::STATUS_ACHEVE_HORS_DELAI;
        }

        if ($startDate !== null && $referenceDate->lt($startDate)) {
            return self::STATUS_NON_DEMARRE;
        }

        if ($endDate !== null && $referenceDate->gt($endDate)) {
            return self::STATUS_EN_RETARD;
        }

        if ($endDate !== null) {
            $daysLeft = $referenceDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay(), false);
            if ($daysLeft >= 0 && $daysLeft <= self::RISK_ALERT_THRESHOLD_DAYS) {
                return self::STATUS_A_RISQUE;
            }
        }

        return self::STATUS_EN_COURS;
    }

    /**
     * @return array{kpi_delai: float, kpi_performance: float, kpi_conformite: float, kpi_qualite: float, kpi_global: float}|null
     */
    private function frozenActionKpis(Action $action): ?array
    {
        $existingKpi = ActionKpi::query()
            ->where('action_id', $action->id)
            ->first();

        if (! $existingKpi instanceof ActionKpi) {
            return null;
        }

        return [
            'kpi_delai' => (float) $existingKpi->kpi_delai,
            'kpi_performance' => (float) $existingKpi->kpi_performance,
            'kpi_conformite' => (float) $existingKpi->kpi_conformite,
            'kpi_qualite' => (float) $existingKpi->kpi_qualite,
            // Lecture de la valeur réellement stockée (gel des KPI).
            // L'ancien code écrasait kpi_global avec kpi_performance, ce qui faisait
            // perdre la valeur figée pour les actions suspendues.
            'kpi_global' => round(max(0.0, min(100.0, (float) $existingKpi->kpi_global)), 2),
        ];
    }

    /**
     * @return array{kpi_delai: float, kpi_performance: float, kpi_conformite: float, kpi_qualite: float, kpi_global: float}
     */
    private function calculateActionKpis(
        Action $action,
        float $realProgress,
        float $theoreticalProgress,
        float $cumulativeQuantity,
        Carbon $referenceDate
    ): array {
        $realProgress = $this->actionPerformanceService->calculateRealProgress($action);
        $delayKpi = $this->actionPerformanceService->calculateDelayScore($action, $referenceDate);
        $qualityKpi = $this->actionPerformanceService->calculateQualityScore($action);
        $performanceKpi = $this->actionPerformanceService->calculateExecutionPerformance($action, $referenceDate);
        $conformiteKpi = $qualityKpi;
        $globalKpi = $performanceKpi;

        return [
            'kpi_delai' => $delayKpi,
            'kpi_performance' => $performanceKpi,
            'kpi_conformite' => $conformiteKpi,
            'kpi_qualite' => $qualityKpi,
            'kpi_global' => $globalKpi,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, ActionWeek> $weeks
     * @param array{kpi_delai: float, kpi_performance: float, kpi_conformite: float, kpi_qualite: float, kpi_global: float} $kpis
     */
    private function generateAutomaticAlerts(
        Action $action,
        \Illuminate\Support\Collection $weeks,
        float $realProgress,
        float $theoreticalProgress,
        array $kpis,
        Carbon $referenceDate
    ): void {
        $reference = $referenceDate->copy()->endOfDay();

        $overdueWeeks = $weeks->filter(function (ActionWeek $week) use ($reference): bool {
            return ! $week->est_renseignee
                && Carbon::parse($week->date_fin)->endOfDay()->lt($reference);
        })->values();

        foreach ($overdueWeeks as $index => $week) {
            $cible = $this->escalationRoleForMissingWeek($index + 1);
            $this->createLogIfMissingToday(
                $action,
                'semaine_non_renseignee',
                'warning',
                sprintf('Periode %d non renseignee apres echeance.', (int) $week->numero_semaine),
                ['numero_semaine' => (int) $week->numero_semaine],
                $cible,
                null,
                $week
            );
        }

        if ($overdueWeeks->isNotEmpty()) {
            $this->createLogIfMissingToday(
                $action,
                'conformite_incomplete',
                'warning',
                'Conformite insuffisante: des periodes attendues ne sont pas renseignees.',
                [
                    'periodes_manquantes' => $overdueWeeks->count(),
                    'progression_reelle' => $realProgress,
                    'progression_theorique' => $theoreticalProgress,
                ],
                'chef_service'
            );
        }

        $gap = round($theoreticalProgress - $realProgress, 2);
        $gapThreshold = (float) ($action->seuil_alerte_progression ?? 10);
        if ($gap > $gapThreshold && $realProgress < 100) {
            $this->createLogIfMissingToday(
                $action,
                'progression_sous_seuil',
                'warning',
                'Progression reelle en dessous du seuil attendu.',
                [
                    'progression_reelle' => $realProgress,
                    'progression_theorique' => $theoreticalProgress,
                    'ecart' => $gap,
                    'seuil' => $gapThreshold,
                ],
                'direction'
            );
        }

        if ($action->date_fin !== null) {
            $daysLeft = $referenceDate
                ->copy()
                ->startOfDay()
                ->diffInDays(Carbon::parse($action->date_fin)->startOfDay(), false);

            if ($daysLeft >= 0 && $daysLeft <= self::RISK_ALERT_THRESHOLD_DAYS && $action->date_fin_reelle === null) {
                $this->createLogIfMissingToday(
                    $action,
                    'action_a_surveiller',
                    'warning',
                    'Action proche de l echeance et necessitant une vigilance immediate.',
                    [
                        'jours_restants' => $daysLeft,
                        'progression_reelle' => $realProgress,
                        'progression_theorique' => $theoreticalProgress,
                    ],
                    'chef_service'
                );
            }
        }

        $this->generateTimelineAlerts($action, $referenceDate);

        if ($action->date_fin !== null) {
            $daysLeft = $referenceDate
                ->copy()
                ->startOfDay()
                ->diffInDays(Carbon::parse($action->date_fin)->startOfDay(), false);

            if ($daysLeft >= 0 && $daysLeft <= 7 && $realProgress < 90) {
                $this->createLogIfMissingToday(
                    $action,
                    'echeance_proche',
                    'critical',
                    'Date de fin proche sans avancement suffisant.',
                    [
                        'jours_restants' => $daysLeft,
                        'progression_reelle' => $realProgress,
                    ],
                    'chef_service'
                );
            }
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->whereIn('categorie', ['hebdomadaire', 'final', 'execution_quantitative', 'execution_mixte'])
            ->exists();
        $hasSousActionJustificatif = $action->sousActions()->whereHas('justificatifs')->exists();

        if (($overdueWeeks->isNotEmpty() || $action->date_fin_reelle !== null || $action->usesStructuredProgressTracking()) && ! $hasExecutionJustificatif && ! $hasSousActionJustificatif) {
            $this->createLogIfMissingToday(
                $action,
                'justificatif_absent',
                'warning',
                'Aucun justificatif d execution n a ete depose pour l action.',
                [
                    'statut_dynamique' => $action->statut_dynamique,
                    'date_fin_reelle' => optional($action->date_fin_reelle)->toDateString(),
                ],
                'chef_service'
            );
        }

        $globalKpi = (float) ($kpis['kpi_global'] ?? 0);

        if ($globalKpi < 40) {
            $this->createLogIfMissingToday(
                $action,
                'kpi_global_sous_seuil',
                'critical',
                'Indicateur global de l action sous le seuil critique de pilotage.',
                ['kpi_global' => $globalKpi],
                'direction'
            );
        } elseif ($globalKpi < 60) {
            $this->createLogIfMissingToday(
                $action,
                'kpi_global_sous_seuil',
                'warning',
                'Indicateur global de l action sous le seuil de pilotage.',
                ['kpi_global' => $globalKpi],
                'direction'
            );
        }

        if (
            $action->date_fin !== null
            && $action->date_fin_reelle === null
            && $referenceDate->copy()->endOfDay()->gt(Carbon::parse($action->date_fin)->endOfDay())
            && $globalKpi < 40
        ) {
            $this->createLogIfMissingToday(
                $action,
                'alerte_combinee_critique',
                'urgence',
                'Action en retard avec indicateur critique. Urgence et escalade DG requises.',
                [
                    'kpi_global' => $globalKpi,
                    'progression_reelle' => $realProgress,
                    'progression_theorique' => $theoreticalProgress,
                    'statut_dynamique' => $action->statut_dynamique,
                ],
                'dg'
            );
        }
    }

    private function generateTimelineAlerts(Action $action, Carbon $referenceDate): void
    {
        $deadline = $action->date_echeance !== null
            ? Carbon::parse($action->date_echeance)->startOfDay()
            : ($action->date_fin !== null ? Carbon::parse($action->date_fin)->startOfDay() : null);

        if ($deadline === null || $action->date_fin_reelle !== null) {
            return;
        }

        $offsetDays = $deadline->diffInDays($referenceDate->copy()->startOfDay(), false);
        $rules = $this->notificationPolicySettings->matchingTimelineRules($offsetDays);

        foreach ($rules as $rule) {
            $message = trim($this->notificationPolicySettings->renderTimelineRuleMessage($rule, new ActionLog([
                'action_id' => $action->id,
                'niveau' => (string) ($rule['level'] ?? 'warning'),
                'type_evenement' => 'alerte_temporelle',
                'message' => (string) ($rule['message_template'] ?? ''),
                'details' => ['offset_days' => $offsetDays, 'timeline_rule' => $rule['code'] ?? ''],
                'cible_role' => (string) ($rule['target_role'] ?? 'service'),
            ])));

            $this->createLogIfMissingToday(
                $action,
                'alerte_temporelle_'.(string) ($rule['code'] ?? $offsetDays),
                (string) ($rule['level'] ?? 'warning'),
                $message !== '' ? $message : sprintf('Alerte temporelle %s sur l echeance de l action.', $offsetDays >= 0 ? 'J+'.$offsetDays : 'J'.$offsetDays),
                [
                    'offset_days' => $offsetDays,
                    'timeline_rule' => (string) ($rule['code'] ?? ''),
                    'date_echeance' => $deadline->toDateString(),
                ],
                (string) ($rule['target_role'] ?? 'service')
            );
        }
    }

    private function calculateQualityKpi(Action $action, Carbon $referenceDate): float
    {
        $score = 100.0;

        if (trim((string) ($action->description ?? '')) === '') {
            $score -= 15.0;
        }

        if (trim((string) ($action->criteres_validation ?? '')) === '') {
            $score -= 15.0;
        }

        if (trim((string) ($action->livrable_attendu ?? '')) === '') {
            $score -= 10.0;
        }

        $dueWeeks = $action->weeks()
            ->whereDate('date_fin', '<=', $referenceDate->toDateString())
            ->get();

        if ($dueWeeks->isNotEmpty() && $dueWeeks->contains(fn (ActionWeek $week): bool => ! $week->est_renseignee)) {
            $score -= 30.0;
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->whereIn('categorie', ['hebdomadaire', 'final', 'execution_quantitative', 'execution_mixte'])
            ->exists();
        $hasSousActionJustificatif = $action->sousActions()->whereHas('justificatifs')->exists();

        if (! $hasExecutionJustificatif && ! $hasSousActionJustificatif) {
            $score -= 20.0;
        }

        if ($action->date_fin_reelle !== null && trim((string) ($action->rapport_final ?? '')) === '') {
            $score -= 10.0;
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function escalationRoleForMissingWeek(int $overdueCount): string
    {
        return match (true) {
            $overdueCount >= 4 => 'dg',
            $overdueCount === 3 => 'direction',
            $overdueCount === 2 => 'chef_service',
            default => 'responsable',
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createLogIfMissingToday(
        Action $action,
        string $type,
        string $level,
        string $message,
        array $details = [],
        ?string $targetRole = null,
        ?int $userId = null,
        ?ActionWeek $week = null
    ): ?ActionLog {
        $query = ActionLog::query()
            ->where('action_id', $action->id)
            ->where('type_evenement', $type)
            ->whereDate('created_at', today()->toDateString());

        if ($week !== null) {
            $query->where('action_week_id', $week->id);
        } else {
            $query->whereNull('action_week_id');
        }

        if ($query->exists()) {
            return null;
        }

        $log = ActionLog::query()->create([
            'action_id' => $action->id,
            'action_week_id' => $week?->id,
            'niveau' => $level,
            'type_evenement' => $type,
            'message' => $message,
            'details' => $details,
            'cible_role' => $targetRole,
            'utilisateur_id' => $userId,
            'lu' => false,
        ]);

        if (in_array($level, ['warning', 'critical', 'urgence'], true)) {
            app(WorkspaceNotificationService::class)->notifyActionAlertEscalation($log, $userId);
        }

        return $log;
    }

    /**
     * @param array<string, mixed> $details
     */
    // ── COMMENTAIRES ET DISCUSSION ────────────────────────────────────────────

    /** Ajoute un commentaire dans le fil de discussion d'une action. */
    public function addDiscussionEntry(
        Action $action,
        string $message,
        string $type = 'commentaire',
        string $level = 'info',
        array $details = [],
        ?User $actor = null
    ): ActionLog {
        return ActionLog::query()->create([
            'action_id' => $action->id,
            'action_week_id' => null,
            'niveau' => $level,
            'type_evenement' => $type,
            'message' => trim($message) !== '' ? trim($message) : 'Commentaire',
            'details' => $details,
            'cible_role' => null,
            'utilisateur_id' => $actor?->id,
            'lu' => false,
        ]);
    }

    private function mapLegacyStatus(string $dynamicStatus): string
    {
        return match ($dynamicStatus) {
            self::STATUS_NON_DEMARRE => 'non_demarre',
            self::STATUS_SUSPENDU => 'suspendu',
            self::STATUS_ANNULE => 'annule',
            self::STATUS_ACHEVE_DANS_DELAI, self::STATUS_ACHEVE_HORS_DELAI => 'termine',
            self::STATUS_EN_RETARD => 'en_cours',
            default => 'en_cours',
        };
    }
}
