<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\ActionLog;
use App\Models\Justificatif;
use App\Models\SousAction;
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
        private readonly ActionPerformanceService $actionPerformanceService,
        private readonly ActionStatusService $actionStatusService,
        private readonly WorkspaceNotificationService $notificationService
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
    public const VALIDATION_CORRECTION_DEMANDEE = 'correction_demandee';
    public const VALIDATION_VALIDEE_CHEF = 'validee_chef';
    /**
     * @deprecated L'etape de validation direction a ete supprimee du circuit.
     * Ces constantes sont conservees pour la retro-compatibilite des chemins
     * de lecture (dashboards, policies, KPI) jusqu'a leur purge complete.
     * Les enregistrements historiques sont backfilles vers leurs equivalents
     * chef par la migration de purge.
     */
    public const VALIDATION_REJETEE_DIRECTION = 'rejetee_direction';
    /** @deprecated Voir VALIDATION_REJETEE_DIRECTION. */
    public const VALIDATION_VALIDEE_DIRECTION = 'validee_direction';

    // ── CONSTANTES DE FINANCEMENT ─────────────────────────────────────────────
    // Décisions possibles sur une demande de financement d'action.
    public const FINANCEMENT_DECISION_VALIDER = 'valider';   // DAF : dossier complet, transmis au DG
    public const FINANCEMENT_DECISION_REJETER = 'rejeter';   // DAF : dossier incomplet, retour agent
    public const FINANCEMENT_DECISION_COMPLEMENT = 'demander_complement'; // DAF : dossier a completer
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
            self::VALIDATION_CORRECTION_DEMANDEE,
            self::VALIDATION_VALIDEE_CHEF,
        ];
    }

    // ── INITIALISATION ET SEMAINES D'EXÉCUTION ────────────────────────────────

    /**
     * Initialise le suivi d'une action nouvellement créée.
     * Génère les semaines d'exécution et place l'action en statut "non démarré".
     */
    public function initializeActionTracking(Action $action, ?User $actor = null): void
    {
        // Le suivi hebdomadaire a ete supprime. L'initialisation se limite a
        // recalculer les metriques (statut dynamique, progression).
        $this->refreshActionMetrics($action);

        $this->createLogIfMissingToday(
            $action,
            'action_initialisee',
            'info',
            'Action initialisee. Suivi par sous-actions ou progression quantitative.',
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

    // canRegenerateWeeks / regenerateWeeks : methodes supprimees.
    // Le suivi hebdomadaire n'existe plus dans la spec canonique.
    // Conserve un stub neutre pour les anciens appels eventuels.
    public function canRegenerateWeeks(Action $action): bool
    {
        return false;
    }

    public function regenerateWeeks(Action $action): void
    {
        // no-op : plus de generation de semaines.
    }

    // submitWeek : methode supprimee. Le suivi hebdomadaire n'existe plus.

    /**
     * Bascule automatique vers le chef de service.
     *
     * L'ancien formulaire agent « Soumission de clôture » a été supprimé : la
     * bascule se produit donc dès qu'une des conditions suivantes est remplie,
     * à partir du moment où l'action est démarrée et n'est pas déjà soumise ou
     * validée :
     *
     *   1. Toutes les périodes de suivi sont renseignées ET la progression
     *      réelle atteint 100 % (cas nominal d'une action quantitative menée
     *      au bout). C'est le déclencheur historique.
     *   2. Le statut métier passe à `termine` (l'agent a explicitement marqué
     *      l'action achevée via le bouton « statut rapide »).
     *   3. L'action est suivie en mode sous-actions / mixte et toutes ses
     *      sous-actions sont marquées « effectuée ».
     *
     * Il n'existe plus de soumission manuelle separee : le bouton `Soumettre`
     * de la sous-action declenche ce circuit.
     */
    private function maybeAutoSubmitClosureToChef(?Action $action, ?User $actor): void
    {
        if (! $action instanceof Action) {
            return;
        }

        $current = (string) ($action->statut_validation ?? self::VALIDATION_NON_SOUMISE);
        if (in_array($current, [
            self::VALIDATION_SOUMISE_CHEF,
            self::VALIDATION_VALIDEE_CHEF,
        ], true)) {
            return;
        }

        // L'action doit avoir été demarree. Sans cette garde, soumettre une
        // action `non_demarre` leverait une exception dans
        // submitClosureForReview.
        if (! $this->actionStatusService->isStarted($action)) {
            return;
        }

        // Forcer le chargement des relations dont depend
        // ActionPerformanceService::calculateRealProgress (semaines et
        // sous-actions). Sans cela les sommes hebdomadaires retomberaient a 0
        // et la bascule n'aboutirait jamais.
        $action->loadMissing(['sousActions']);

        if (! $this->shouldAutoSubmitClosure($action)) {
            return;
        }

        $payload = [
            'date_fin_reelle' => optional($action->date_fin_reelle)->toDateString() ?: now()->toDateString(),
            'rapport_final' => (string) ($action->rapport_final ?? 'Soumission automatique : conditions de cloture atteintes.'),
        ];

        $this->submitClosureForReview($action, $payload, $actor);

        $action->refresh()->loadMissing('pta:id,direction_id,service_id');
        $this->notificationService->notifyActionSubmittedToChef($action, $actor);
    }

    /**
     * Point d'entrée public pour redéclencher la bascule automatique vers le
     * chef de service depuis l'extérieur du service (typiquement après une
     * modification de l'action qui peut changer le statut métier à `termine`,
     * ou après synchronisation de sous-actions).
     */
    public function attemptAutoSubmitClosure(Action $action, ?User $actor = null): void
    {
        // La soumission officielle est volontairement explicite: le bouton
        // "Enregistrer" garde le suivi chez l'agent, seul "Soumettre au chef"
        // declenche le circuit de validation.
        return;
    }

    /**
     * Détermine si l'action remplit l'une des conditions de bascule
     * automatique vers le chef de service. Voir
     * {@see self::maybeAutoSubmitClosureToChef()} pour le détail métier.
     *
     * Hypothèse d'appel : `$action` a déjà été vérifiée comme démarrée et ses
     * relations `weeks` et `sousActions` sont chargées.
     */
    private function shouldAutoSubmitClosure(Action $action): bool
    {
        // Cas 1 — statut métier explicitement marqué « terminé » par l'agent.
        if ((string) $action->statut === 'termine') {
            return true;
        }

        // Cas 2 — mode sous-actions / mixte avec toutes les sous-actions
        // marquées effectuées (et au moins une définie).
        if ($action->usesSubTasksProgress()) {
            $sousActions = $action->sousActions;
            if ($sousActions->isNotEmpty()
                && $sousActions->every(fn ($sa) => (bool) $sa->est_effectuee === true)
            ) {
                return true;
            }
        }

        // Cas 3 — progression reelle atteint 100 % (calcul quantitatif ou
        // pourcentage agent). Le suivi hebdomadaire ayant ete supprime, on
        // garde uniquement la verification du taux global.
        if ($this->actionPerformanceService->calculateDeclaredProgress($action) >= 100.0) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    // ── CLÔTURE ET VALIDATION ─────────────────────────────────────────────────


    // ── WORKFLOW DE VALIDATION OPÉRATIONNEL SUPPRIMÉ (2026-05-31) ──────────
    // Les méthodes submitClosureForReview / reviewClosureSubmission /
    // reviewClosureByChef / reviewSubActionByChef et leurs helpers privés
    // ont été retirées pour permettre la refonte from scratch du workflow
    // de suivi opérationnel. La méthode attemptAutoSubmitClosure ci-dessus
    // est conservée comme no-op pour compatibilité ascendante.


    // reviewClosureByDirection : methode supprimee.
    // L'etape de validation direction a ete retiree du circuit metier.
    // Les colonnes direction_* (direction_valide_par/le/note/commentaire)
    // sont conservees pour l'historique mais ne sont plus alimentees.

    // ── FINANCEMENT ───────────────────────────────────────────────────────────

    /**
     * Crée ou met à jour la demande de financement d'une action.
     * Déclenche une notification à la DAF si le financement est requis.
     */
    public function syncFinancingRequest(Action $action, ?User $actor = null): Action
    {
        if (! (bool) $action->financement_requis) {
            $action->forceFill([
                'financement_statut' => Action::FINANCEMENT_NON_REQUIS,
            ])->save();

            return $action->fresh();
        }

        $status = $action->financementStatus();
        if (in_array($status, [
            Action::FINANCEMENT_NON_REQUIS,
            Action::FINANCEMENT_REJETE_DAF,
            Action::FINANCEMENT_REFUSE_DG,
        ], true)) {
            $action->financement_statut = Action::FINANCEMENT_PRE_SIGNALE_DAF;
            $action->financement_soumis_le = null;
        }

        if ($action->financement_notifie_le === null) {
            $action->financement_notifie_le = now();
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
        $requiresComplement = $decision === self::FINANCEMENT_DECISION_COMPLEMENT;

        $action->forceFill([
            'financement_statut' => match (true) {
                $approved => Action::FINANCEMENT_TRANSMIS_DG,
                $requiresComplement => Action::FINANCEMENT_COMPLEMENT_DEMANDE,
                default => Action::FINANCEMENT_REJETE_DAF,
            },
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
            match (true) {
                $approved => 'financement_valide_daf',
                $requiresComplement => 'financement_complement_demande',
                default => 'financement_rejete_daf',
            },
            $approved ? 'info' : 'warning',
            match (true) {
                $approved => 'Financement valide par la DAF. Accord DG requis.',
                $requiresComplement => 'Complement demande par la DAF.',
                default => 'Financement rejete par la DAF.',
            },
            [
                'decision' => $decision,
                'montant_valide' => $action->financement_montant_valide,
                'reference' => $action->financement_reference,
                'commentaire' => $action->financement_daf_commentaire,
            ],
            match (true) {
                $approved => 'dg',
                $requiresComplement => 'responsable',
                default => 'direction',
            },
            $actor->id
        );

        $this->addDiscussionEntry(
            $action,
            (string) ($payload['commentaire_financement'] ?? ($approved
                ? 'Validation DAF du besoin de financement. Accord DG attendu.'
                : ($requiresComplement
                    ? 'Complement demande par la DAF.'
                    : 'Rejet DAF du besoin de financement.'))),
            match (true) {
                $approved => 'financement_valide_daf',
                $requiresComplement => 'financement_complement_demande',
                default => 'financement_rejete_daf',
            },
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

        $action->forceFill([
            'financement_statut' => $approved ? Action::FINANCEMENT_ACCORDE_DG : Action::FINANCEMENT_REFUSE_DG,
            'financement_dg_par' => $actor->id,
            'financement_dg_le' => now(),
            'financement_dg_decision' => $decision,
            'financement_dg_commentaire' => $payload['commentaire_financement'] ?? null,
        ])->save();

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
        // A28 — Wrapper toute la chaine recalcul (refresh + structured) dans
        // une transaction avec lockForUpdate sur l action courante : evite la
        // race condition ou une sous-action sauvegarde pendant le calcul vienne
        // corrompre les KPI consolides (lecture partielle / KPI tronques).
        return \Illuminate\Support\Facades\DB::transaction(function () use ($action, $referenceDate): Action {
            // Lock pessimiste sur la ligne action courante (PG / MySQL). En
            // SQLite, lockForUpdate est silencieusement ignore mais la
            // transaction reste serialisable au niveau base.
            \App\Models\Action::query()
                ->whereKey($action->id)
                ->lockForUpdate()
                ->first();

            return $this->refreshActionMetricsInternal($action, $referenceDate);
        });
    }

    private function refreshActionMetricsInternal(Action $action, ?Carbon $referenceDate): Action
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $action->load('sousActions.justificatifs', 'actionKpi');

        if ($action->usesStructuredProgressTracking()) {
            return $this->refreshStructuredActionMetrics($action, $referenceDate);
        }

        // Suivi hebdomadaire supprime. La progression est calculee directement
        // a partir de quantite_realisee (mode quantitatif) ou de l'avancement
        // declare via les sous-actions et le mode quantitatif global.
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

        $action->forceFill([
            'date_echeance' => $action->date_echeance ?? $action->date_fin,
            'progression_reelle' => $realProgress,
            'progression_theorique' => $theoreticalProgress,
            'statut_dynamique' => $status,
            'statut' => $legacyStatus,
        ])->save();

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
        $action->forceFill([
            'taux_performance' => $kpis['kpi_performance'] ?? null,
            // taux_conformite supprime par la migration spec v2.
            'taux_delai' => $kpis['kpi_delai'] ?? null,
            'taux_realisation_global' => $tauxRealisation,
        ])->save();

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
            $emptyWeeks = collect();
            $this->generateAutomaticAlerts($action, $emptyWeeks, $realProgress, $theoreticalProgress, $kpis, $referenceDate);
        }

        return $action->fresh(['actionKpi', 'sousActions.justificatifs']);
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

        $action->forceFill($updates)->save();

        $quantitativeBase = $action->usesQuantitativeProgress()
            ? (float) ($metrics['quantite_realisee'] ?? 0)
            : (float) ($metrics['sous_actions_realisees'] ?? 0);

        $kpis = match ($status) {
            self::STATUS_SUSPENDU => $this->frozenActionKpis($action)
                ?? $this->calculateActionKpis($action, $realProgress, $theoreticalProgress, $quantitativeBase, $referenceDate),
            self::STATUS_ANNULE => [
                'kpi_delai' => 0.0,
                'kpi_performance' => 0.0,
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

        $action->forceFill([
            'taux_performance' => $kpis['kpi_performance'] ?? null,
            // taux_conformite supprime par la migration spec v2.
            'taux_delai' => $kpis['kpi_delai'] ?? null,
            'taux_realisation_global' => $tauxRealisation,
        ])->save();

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
            $emptyWeeks = collect();
            $this->generateAutomaticAlerts($action, $emptyWeeks, $realProgress, $theoreticalProgress, $kpis, $referenceDate);
        }

        return $action->fresh(['actionKpi', 'sousActions.justificatifs']);
    }
    // ── JUSTIFICATIFS ─────────────────────────────────────────────────────────

    /** Attache un fichier justificatif (PDF, image...) à une action. */
    public function addActionJustificatif(
        Action $action,
        $week, // parametre conserve pour compatibilite, ignore (action_week_id supprime)
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

        if (in_array((string) ($action->statut_validation ?? ''), [
            self::VALIDATION_REJETEE_CHEF,
            self::VALIDATION_CORRECTION_DEMANDEE,
        ], true)) {
            return self::STATUS_A_CORRIGER;
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

        if ($realProgress <= 0.0 && ! $this->actionStatusService->isStarted($action)) {
            return self::STATUS_NON_DEMARRE;
        }

        if ($startDate !== null && $referenceDate->lt($startDate)) {
            return self::STATUS_EN_COURS;
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
     * Spec v2 : KPI conformite supprime. Seuls Performance, Delai et Global subsistent.
     *
     * @return array{kpi_delai: float, kpi_performance: float, kpi_global: float}|null
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
            // Lecture de la valeur réellement stockée (gel des KPI).
            // L'ancien code écrasait kpi_global avec kpi_performance, ce qui faisait
            // perdre la valeur figée pour les actions suspendues.
            'kpi_global' => round(max(0.0, min(100.0, (float) $existingKpi->kpi_global)), 2),
        ];
    }

    /**
     * Spec v2 : KPI conformite supprime. Le KPI global pondere Performance et Delai
     * (voir ActionPerformanceService::calculateGlobalKpi).
     *
     * @return array{kpi_delai: float, kpi_performance: float, kpi_global: float}
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
        $performanceKpi = $realProgress;
        $globalKpi = $this->actionPerformanceService->calculateGlobalKpi($action, $referenceDate);

        return [
            'kpi_delai' => $delayKpi,
            'kpi_performance' => $performanceKpi,
            'kpi_global' => $globalKpi,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection $weeks parametre conserve mais ignore (suivi hebdomadaire supprime)
     * @param array{kpi_delai: float, kpi_performance: float, kpi_global: float} $kpis
     */
    private function generateAutomaticAlerts(
        Action $action,
        \Illuminate\Support\Collection $weeks,
        float $realProgress,
        float $theoreticalProgress,
        array $kpis,
        Carbon $referenceDate
    ): void {
        // Alertes de periodes non renseignees supprimees (plus de semaines).
        // Conserve l'alerte sur l'ecart progression reelle vs theorique.

        $gap = round($theoreticalProgress - $realProgress, 2);
        $gapThreshold = (float) ($action->seuil_alerte_progression ?? 10);
        if ($gap > $gapThreshold && $realProgress < 100) {
            $this->createLogIfMissingToday(
                $action,
                'progression_sous_seuil',
                'warning',
                'La progression réelle est plus basse que la progression attendue.',
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
                    'L\'action arrive bientôt à échéance. Elle doit être suivie de près.',
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
                    'La date de fin est proche et l\'avancement est insuffisant.',
                    [
                        'jours_restants' => $daysLeft,
                        'progression_reelle' => $realProgress,
                    ],
                    'chef_service'
                );
            }
        }

        $hasExecutionJustificatif = $action->justificatifs()
            ->whereIn('categorie', ['hebdomadaire', 'final', 'execution_quantitative', 'execution_non_quantitative', 'execution_mixte'])
            ->exists();
        $hasSousActionJustificatif = $action->sousActions()->whereHas('justificatifs')->exists();

        $hasTrackedExecution = $weeks->isNotEmpty()
            || $realProgress > 0.0
            || $action->date_fin_reelle !== null
            || $this->actionStatusService->isStarted($action);

        if ($hasTrackedExecution && ! $hasExecutionJustificatif && ! $hasSousActionJustificatif) {
            $this->createLogIfMissingToday(
                $action,
                'justificatif_absent',
                'warning',
                'Aucun justificatif d\'exécution n\'a été déposé pour l\'action.',
                [
                    'statut_dynamique' => $action->statut_dynamique,
                    'date_fin_reelle' => optional($action->date_fin_reelle)->toDateString(),
                ],
                'chef_service'
            );
        }

        $globalKpi = (float) ($kpis['kpi_global'] ?? 0);

        $hasKpiAlertBasis = $this->actionStatusService->isStarted($action);

        if ($hasKpiAlertBasis && $globalKpi < 40) {
            $this->createLogIfMissingToday(
                $action,
                'kpi_global_sous_seuil',
                'critical',
                'Le score global de l\'action est sous le seuil critique.',
                ['kpi_global' => $globalKpi],
                'direction'
            );
        } elseif ($hasKpiAlertBasis && $globalKpi < 60) {
            $this->createLogIfMissingToday(
                $action,
                'kpi_global_sous_seuil',
                'warning',
                'Le score global de l\'action est sous le seuil attendu.',
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
                'L\'action est en retard et son score est critique. Une décision rapide est nécessaire.',
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
                $message !== '' ? $message : sprintf('Échéance à vérifier (%s) pour cette action.', $offsetDays >= 0 ? 'J+'.$offsetDays : 'J'.$offsetDays),
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

        // Penalite « semaines non renseignees » supprimee : le suivi
        // hebdomadaire n'existe plus.

        $hasExecutionJustificatif = $action->justificatifs()
            ->whereIn('categorie', ['hebdomadaire', 'final', 'execution_quantitative', 'execution_non_quantitative', 'execution_mixte'])
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
        $week = null // parametre conserve pour compatibilite, ignore
    ): ?ActionLog {
        $query = ActionLog::query()
            ->where('action_id', $action->id)
            ->where('type_evenement', $type)
            ->whereDate('created_at', today()->toDateString());

        if ($query->exists()) {
            return null;
        }

        $log = ActionLog::query()->create([
            'action_id' => $action->id,
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
