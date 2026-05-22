<?php

namespace App\Services;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Carbon;

class ActionPerformanceService
{
    public function __construct(
        private readonly ActionStatusService $actionStatusService
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function officialWeights(): array
    {
        return [
            'real_progress' => 80,
            'delay' => 20,
        ];
    }

    public function calculateRealProgress(Action $action): float
    {
        $target = max(0.0, (float) ($action->quantite_cible ?? 0));

        if ($target > 0.0) {
            return $this->boundRate(($this->realizedQuantity($action) / $target) * 100);
        }

        $totalSubActions = $this->subActions($action)->count();

        if ($totalSubActions > 0) {
            $completedSubActions = $this->subActions($action)
                ->filter(fn (SousAction $subAction): bool => $this->isCompletedSubAction($subAction))
                ->count();

            return $this->boundRate(($completedSubActions / $totalSubActions) * 100);
        }

        return 0.0;
    }

    public function calculateDelayScore(Action $action, ?Carbon $referenceDate = null): float
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $progress = $this->calculateRealProgress($action);
        $status = $this->normalizeStatus($action);
        $deadline = $action->date_echeance ?? $action->date_fin;

        if ($status === 'non_demarre') {
            return 0.0;
        }

        if ($deadline === null) {
            return $progress > 0.0 ? 100.0 : 0.0;
        }

        $deadline = Carbon::parse($deadline)->endOfDay();
        $completedAt = $action->date_fin_reelle ?? $action->cloture_le;

        if ($completedAt !== null || $progress >= 100.0) {
            $realEnd = $completedAt !== null
                ? Carbon::parse($completedAt)->endOfDay()
                : $referenceDate->copy()->endOfDay();

            return $realEnd->lte($deadline) ? 100.0 : 40.0;
        }

        if ($referenceDate->copy()->endOfDay()->gt($deadline)) {
            return $progress <= 0.0 ? 0.0 : 40.0;
        }

        $daysLeft = $referenceDate->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay(), false);

        return $daysLeft >= 0 && $daysLeft <= ActionTrackingService::RISK_ALERT_THRESHOLD_DAYS
            ? 70.0
            : 100.0;
    }

    public function calculateQualityScore(Action $action): float
    {
        return $this->calculateConformityScore($action);
    }

    /**
     * KPI Conformité = note brute /100 attribuée par le chef de service
     * (ou par la direction lors de la validation finale).
     *
     * - Rejet chef ou direction => 0 (l'action n'est pas conforme).
     * - Validation chef/direction => note brute du validateur (0-100).
     * - Soumise au chef, en attente d'évaluation => 0 (pas encore noté).
     * - Sinon => 0.
     *
     * L'UI applique un badge 3 niveaux (Faible / Moyen / Élevé) via
     * {@see \App\Support\KpiLevel}.
     */
    public function calculateConformityScore(Action $action): float
    {
        $validationStatus = (string) ($action->statut_validation ?? '');

        if (in_array($validationStatus, [
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
        ], true)) {
            return 0.0;
        }

        if (in_array($validationStatus, [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            $note = $action->direction_evaluation_note ?? $action->evaluation_note;

            return $note !== null ? $this->boundRate((float) $note) : 0.0;
        }

        return 0.0;
    }

    public function calculateExecutionPerformance(Action $action, ?Carbon $referenceDate = null): float
    {
        $progress = $this->calculateRealProgress($action);

        if ($progress <= 0.0 || $this->normalizeStatus($action) === 'non_demarre') {
            return 0.0;
        }

        $delayScore = $this->calculateDelayScore($action, $referenceDate);

        $performance = ($progress * 0.80) + ($delayScore * 0.20);

        return round($this->boundRate($performance), 2);
    }

    public function normalizeStatus(Action $action): string
    {
        if (! $this->hasRealExecution($action)) {
            return 'non_demarre';
        }

        $validationStatus = (string) ($action->statut_validation ?? '');
        if (in_array($validationStatus, [
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
        ], true)) {
            return 'rejetee';
        }

        if ($validationStatus === ActionTrackingService::VALIDATION_SOUMISE_CHEF) {
            return 'en_attente_validation';
        }

        if (in_array($validationStatus, [
            ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            return 'validee';
        }

        $status = strtolower(trim((string) ($action->statut_dynamique ?: $action->statut)));

        return match ($status) {
            'non_demarre', 'non demarre', 'non_demarree', 'not_started' => 'en_cours',
            'en_attente_justificatif', 'justificatif_attendu' => 'en_attente_justificatif',
            'en_attente_validation', 'soumise_chef' => 'en_attente_validation',
            'realisee', 'realise', 'termine', 'acheve', 'acheve_dans_delai', 'acheve_hors_delai' => 'realisee',
            'validee', 'validee_direction' => 'validee',
            'rejetee', 'rejetee_chef', 'rejetee_direction', 'a_corriger' => 'rejetee',
            'cloturee', 'cloture' => 'cloturee',
            'en_retard' => 'en_retard',
            default => 'en_cours',
        };
    }

    public function realizedQuantity(Action $action): float
    {
        $storedRealizedQuantity = max(0.0, (float) ($action->quantite_realisee ?? 0));
        $weeklyRealizedQuantity = $action->relationLoaded('weeks')
            ? $action->weeks
                ->filter(fn ($week): bool => (bool) ($week->est_renseignee ?? false))
                ->sum(fn ($week): float => max(0.0, (float) ($week->quantite_realisee ?? 0)))
            : 0.0;
        $subActionRealizedQuantity = $this->subActions($action)
            ->sum(fn (SousAction $subAction): float => max(0.0, (float) ($subAction->quantite_realisee ?? 0)));

        return max($storedRealizedQuantity, (float) $weeklyRealizedQuantity, (float) $subActionRealizedQuantity);
    }

    /**
     * "Realisee" du point de vue de l agent (action consideree comme faite).
     * Utilisee dans le calcul de la progression brute. Volontairement
     * permissive (accepte est_effectuee=true OU statut='effectuee'). Pour
     * un usage CONFORMITE / KPI institutionnel strict, utiliser plutot
     * isValidatedSubAction() (cf. A18).
     */
    public function isCompletedSubAction(SousAction $subAction): bool
    {
        $status = strtolower(trim((string) ($subAction->statut ?? '')));

        return (bool) $subAction->est_effectuee
            || $subAction->completed_at !== null
            || $subAction->date_realisation !== null
            || in_array($status, ['effectuee', 'terminee', 'termine', 'realisee', 'realise', 'validee', 'cloturee'], true);
    }

    /**
     * A18 — "Validee" du point de vue institutionnel : pour qu une sous-action
     * pese dans un KPI consolide / un rapport officiel, on exige :
     *   - soit le statut explicite "validee" / "cloturee" (validation hierarchique
     *     passe pour la sous-action),
     *   - soit que l action parente ait elle-meme un statut_validation valide
     *     (validee_chef ou validee_direction), ce qui implique le bouclage
     *     workflow.
     *
     * Cela evite qu une simple coche "est_effectuee" suffise a poser un 100 %
     * institutionnel : la preuve est requise.
     */
    public function isValidatedSubAction(SousAction $subAction): bool
    {
        $status = strtolower(trim((string) ($subAction->statut ?? '')));

        if (in_array($status, ['validee', 'cloturee'], true)) {
            return true;
        }

        $parent = $subAction->action ?? null;
        if ($parent !== null && in_array((string) $parent->statut_validation, [
            \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            \App\Services\Actions\ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
        ], true)) {
            return $this->isCompletedSubAction($subAction);
        }

        return false;
    }

    private function hasRealExecution(Action $action): bool
    {
        return $this->actionStatusService->isStarted($action);
    }

    public function boundRate(float $value): float
    {
        return round(min(100.0, max(0.0, $value)), 2);
    }

    /**
     * @return \Illuminate\Support\Collection<int, SousAction>
     */
    private function subActions(Action $action): \Illuminate\Support\Collection
    {
        return $action->relationLoaded('sousActions')
            ? $action->sousActions
            : $action->sousActions()->get();
    }
}
