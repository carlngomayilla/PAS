<?php

namespace App\Services\Actions;

use App\Models\Action;
use App\Models\ActionKpi;
use App\Models\ActionLog;
use App\Models\ActionWeek;
use App\Models\Justificatif;
use App\Models\User;
use App\Services\ActionManagementSettings;
use App\Services\NotificationPolicySettings;
use App\Services\Notifications\WorkspaceNotificationService;
use App\Services\WorkflowSettings;
use Illuminate\Support\Carbon;

class ActionTrackingService
{
    public function __construct(
        private readonly WorkflowSettings $workflowSettings,
        private readonly ActionManagementSettings $actionManagementSettings,
        private readonly NotificationPolicySettings $notificationPolicySettings
    ) {
    }

    public const FREQUENCE_INSTANTANEE = 'instantanee';
    public const FREQUENCE_JOURNALIERE = 'journaliere';
    public const FREQUENCE_HEBDOMADAIRE = 'hebdomadaire';
    public const FREQUENCE_MENSUELLE = 'mensuelle';
    public const FREQUENCE_ANNUELLE = 'annuelle';

    public const STATUS_NON_DEMARRE = 'non_demarre';
    public const STATUS_EN_COURS = 'en_cours';
    public const STATUS_A_RISQUE = 'a_risque';
    public const STATUS_EN_AVANCE = 'en_avance';
    public const STATUS_EN_RETARD = 'en_retard';
    public const STATUS_SUSPENDU = 'suspendu';
    public const STATUS_ANNULE = 'annule';
    public const STATUS_ACHEVE_DANS_DELAI = 'acheve_dans_delai';
    public const STATUS_ACHEVE_HORS_DELAI = 'acheve_hors_delai';

    public const RISK_ALERT_THRESHOLD_DAYS = 3;

    public const VALIDATION_NON_SOUMISE = 'non_soumise';
    public const VALIDATION_SOUMISE_CHEF = 'soumise_chef';
    public const VALIDATION_REJETEE_CHEF = 'rejetee_chef';
    public const VALIDATION_VALIDEE_CHEF = 'validee_chef';
    public const VALIDATION_REJETEE_DIRECTION = 'rejetee_direction';
    public const VALIDATION_VALIDEE_DIRECTION = 'validee_direction';

    /**
     * @return array<int, string>
     */
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
        ];
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

    public function initializeActionTracking(Action $action, ?User $actor = null): void
    {
        $this->regenerateWeeks($action);
        $this->refreshActionMetrics($action);

        $this->createLogIfMissingToday(
            $action,
            'action_initialisee',
            'info',
            'Action initialisee avec suivi periodique automatique.',
            [
                'type_cible' => $action->type_cible,
                'date_debut' => optional($action->date_debut)->toDateString(),
                'date_fin' => optional($action->date_fin)->toDateString(),
                'frequence_execution' => (string) ($action->frequence_execution ?? self::FREQUENCE_HEBDOMADAIRE),
            ],
            'responsable',
            $actor?->id
        );
    }

    public function canRegenerateWeeks(Action $action): bool
    {
        return ! $action->weeks()
            ->where('est_renseignee', true)
            ->exists();
    }

    public function regenerateWeeks(Action $action): void
    {
        $action->refresh();
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
    public function submitClosureForReview(Action $action, array $payload, ?User $actor = null): Action
    {
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

        $action->fill([
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
        ]);
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

    public function refreshActionMetrics(Action $action, ?Carbon $referenceDate = null): Action
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $action->loadMissing('weeks', 'actionKpi');

        $weeks = $action->weeks()
            ->orderBy('numero_semaine')
            ->get();

        $targetQuantity = (float) ($action->quantite_cible ?? 0);
        $cumulativeQuantity = 0.0;
        $latestQualitativeProgress = 0.0;

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

                if ($week->est_renseignee) {
                    $latestQualitativeProgress = $weeklyRealProgress;
                }
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

        $realProgress = $action->type_cible === 'quantitative'
            ? ($targetQuantity > 0 ? min(100.0, round(($cumulativeQuantity / $targetQuantity) * 100, 2)) : 0.0)
            : min(100.0, max(0.0, $latestQualitativeProgress));

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

        $existingKpi = $action->actionKpi;
        if ($status === self::STATUS_SUSPENDU) {
            $kpis = $existingKpi instanceof ActionKpi
                ? [
                    'kpi_delai' => (float) $existingKpi->kpi_delai,
                    'kpi_performance' => (float) $existingKpi->kpi_performance,
                    'kpi_conformite' => (float) $existingKpi->kpi_conformite,
                    'kpi_qualite' => (float) $existingKpi->kpi_qualite,
                    'kpi_risque' => (float) $existingKpi->kpi_risque,
                    'kpi_global' => (float) $existingKpi->kpi_global,
                ]
                : $this->calculateActionKpis(
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
                'kpi_risque' => 0.0,
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

        return $action->fresh(['actionKpi', 'weeks']);
    }

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
     * @return array{kpi_delai: float, kpi_performance: float, kpi_conformite: float, kpi_qualite: float, kpi_risque: float, kpi_global: float}
     */
    private function calculateActionKpis(
        Action $action,
        float $realProgress,
        float $theoreticalProgress,
        float $cumulativeQuantity,
        Carbon $referenceDate
    ): array {
        $delayKpi = 0.0;
        $plannedEnd = $action->date_fin !== null ? Carbon::parse($action->date_fin)->endOfDay() : null;
        $plannedStart = $action->date_debut !== null ? Carbon::parse($action->date_debut)->startOfDay() : null;

        if ($realProgress >= 100.0 && $plannedEnd !== null) {
            $actualEnd = $action->date_fin_reelle !== null
                ? Carbon::parse($action->date_fin_reelle)->endOfDay()
                : $referenceDate->copy()->endOfDay();

            if ($actualEnd->lte($plannedEnd)) {
                $delayKpi = 100.0;
            } else {
                $plannedDuration = $plannedStart !== null ? max(1, $plannedStart->diffInDays($plannedEnd) + 1) : 1;
                $actualDuration = $plannedStart !== null ? max(1, $plannedStart->diffInDays($actualEnd) + 1) : 1;
                $delayKpi = round(min(100.0, ($plannedDuration / $actualDuration) * 100), 2);
            }
        } else {
            $delayKpi = round(max(0.0, min(100.0, 100.0 - max(0.0, $theoreticalProgress - $realProgress))), 2);
        }

        if ($action->type_cible === 'quantitative') {
            $target = (float) ($action->quantite_cible ?? 0);
            $performanceKpi = $target > 0
                ? round(min(100.0, max(0.0, ($cumulativeQuantity / $target) * 100)), 2)
                : 0.0;
        } else {
            $performanceKpi = round(min(100.0, max(0.0, $realProgress)), 2);
        }

        $conformiteKpi = $action->direction_evaluation_note !== null
            ? round(min(100.0, max(0.0, (float) $action->direction_evaluation_note)), 2)
            : ($action->evaluation_note !== null
                ? round(min(100.0, max(0.0, (float) $action->evaluation_note)), 2)
                : match ($action->validation_sans_correction) {
                true => 100.0,
                false => 70.0,
                default => 85.0,
            });

        $dueWeeks = $action->weeks()
            ->whereDate('date_fin', '<=', $referenceDate->toDateString())
            ->get();
        $missingDueWeeks = $dueWeeks->filter(fn (ActionWeek $week): bool => ! $week->est_renseignee)->count();
        if ($missingDueWeeks > 0) {
            $conformiteKpi = round(max(0.0, $conformiteKpi - min(45.0, 20.0 + (($missingDueWeeks - 1) * 10.0))), 2);
        }

        $qualityKpi = $this->calculateQualityKpi($action, $referenceDate);
        $riskKpi = $this->calculateRiskKpi($action, $realProgress, $theoreticalProgress, $referenceDate);

        $globalKpi = round(
            (0.25 * $delayKpi)
            + (0.30 * $performanceKpi)
            + (0.15 * $conformiteKpi)
            + (0.15 * $qualityKpi)
            + (0.15 * $riskKpi),
            2
        );

        return [
            'kpi_delai' => $delayKpi,
            'kpi_performance' => $performanceKpi,
            'kpi_conformite' => $conformiteKpi,
            'kpi_qualite' => $qualityKpi,
            'kpi_risque' => $riskKpi,
            'kpi_global' => $globalKpi,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, ActionWeek> $weeks
     * @param array{kpi_delai: float, kpi_performance: float, kpi_conformite: float, kpi_qualite: float, kpi_risque: float, kpi_global: float} $kpis
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
                    'action_a_risque',
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
            ->whereIn('categorie', ['hebdomadaire', 'final'])
            ->exists();

        if (($overdueWeeks->isNotEmpty() || $action->date_fin_reelle !== null) && ! $hasExecutionJustificatif) {
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
            ->whereIn('categorie', ['hebdomadaire', 'final'])
            ->exists();

        if (! $hasExecutionJustificatif) {
            $score -= 20.0;
        }

        if ($action->date_fin_reelle !== null && trim((string) ($action->rapport_final ?? '')) === '') {
            $score -= 10.0;
        }

        return round(max(0.0, min(100.0, $score)), 2);
    }

    private function calculateRiskKpi(
        Action $action,
        float $realProgress,
        float $theoreticalProgress,
        Carbon $referenceDate
    ): float {
        $score = 100.0;
        $gap = max(0.0, $theoreticalProgress - $realProgress);

        if ($gap > 0) {
            $score -= min(30.0, round($gap, 2));
        }

        $deadline = $action->date_fin !== null ? Carbon::parse($action->date_fin)->endOfDay() : null;
        if ($deadline !== null && $action->date_fin_reelle === null) {
            if ($referenceDate->copy()->endOfDay()->gt($deadline)) {
                $daysLate = $deadline->diffInDays($referenceDate->copy()->endOfDay());
                $score -= min(45.0, 20.0 + ($daysLate * 5.0));
            } else {
                $daysLeft = $referenceDate->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);
                if ($daysLeft >= 0 && $daysLeft <= self::RISK_ALERT_THRESHOLD_DAYS) {
                    $score -= 20.0;
                }
            }
        }

        $risks = trim((string) ($action->risques ?? ''));
        $preventiveMeasures = trim((string) ($action->mesures_preventives ?? ''));
        if ($risks !== '' && $preventiveMeasures === '') {
            $score -= 15.0;
        } elseif ($risks !== '') {
            $score -= 5.0;
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
