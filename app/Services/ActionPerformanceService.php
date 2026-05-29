<?php

namespace App\Services;

use App\Models\Action;
use App\Models\SousAction;
use App\Services\Actions\ActionBusinessRules;
use App\Services\Actions\ActionStatusService;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Carbon;

class ActionPerformanceService
{
    public function __construct(
        private readonly ActionStatusService $actionStatusService,
        private readonly ActionBusinessRules $businessRules
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function officialWeights(): array
    {
        return [
            'performance' => 70,
            'delay' => 30,
        ];
    }

    public function calculateRealProgress(Action $action): float
    {
        $target = max(0.0, (float) ($action->quantite_cible ?? 0));

        if ($this->businessRules->isActionQuantifiable($action) && $target > 0.0) {
            return $this->boundRate(($this->realizedQuantity($action) / $target) * 100);
        }

        $totalSubActions = $this->subActions($action)->count();

        if ($totalSubActions > 0) {
            $subActions = $this->subActions($action);
            $subActionTarget = $subActions
                ->filter(fn (SousAction $subAction): bool => $this->businessRules->isSubActionQuantifiable($subAction))
                ->sum(fn (SousAction $subAction): float => max(0.0, (float) ($subAction->cible_prevue ?? 0)));

            $hasQuantifiedSubActions = $subActionTarget > 0.0;
            $hasNonQuantifiedSubActions = $subActions->contains(
                fn (SousAction $subAction): bool => ! $this->businessRules->isSubActionQuantifiable($subAction)
            );

            if ($hasQuantifiedSubActions && ! $hasNonQuantifiedSubActions) {
                $declaredQuantity = $subActions
                    ->sum(fn (SousAction $subAction): float => max(0.0, (float) ($subAction->quantite_realisee ?? 0)));

                return $this->boundRate(($declaredQuantity / $subActionTarget) * 100);
            }

            $weight = 100.0 / max(1, $totalSubActions);
            $progress = $subActions->sum(function (SousAction $subAction) use ($weight): float {
                return ($this->businessRules->declaredSubActionProgress($subAction) / 100) * $weight;
            });

            return $this->boundRate((float) $progress);
        }

        if ($this->isDeclaredNonQuantifiableActionCompleted($action)) {
            return 100.0;
        }

        return 0.0;
    }

    public function calculateDeclaredProgress(Action $action): float
    {
        $target = max(0.0, (float) ($action->quantite_cible ?? 0));

        if ($this->businessRules->isActionQuantifiable($action) && $target > 0.0) {
            return $this->boundRate(($this->realizedQuantity($action) / $target) * 100);
        }

        $subActions = $this->subActions($action);
        $totalSubActions = $subActions->count();

        if ($totalSubActions > 0) {
            $subActionTarget = $subActions
                ->filter(fn (SousAction $subAction): bool => $this->businessRules->isSubActionQuantifiable($subAction))
                ->sum(fn (SousAction $subAction): float => max(0.0, (float) ($subAction->cible_prevue ?? 0)));

            $hasQuantifiedSubActions = $subActionTarget > 0.0;
            $hasNonQuantifiedSubActions = $subActions->contains(
                fn (SousAction $subAction): bool => ! $this->businessRules->isSubActionQuantifiable($subAction)
            );

            if ($hasQuantifiedSubActions && ! $hasNonQuantifiedSubActions) {
                $declaredQuantity = $subActions
                    ->sum(fn (SousAction $subAction): float => max(0.0, (float) ($subAction->quantite_realisee ?? 0)));

                return $this->boundRate(($declaredQuantity / $subActionTarget) * 100);
            }

            $weight = 100.0 / max(1, $totalSubActions);
            $progress = $subActions->sum(function (SousAction $subAction) use ($weight): float {
                return ($this->businessRules->declaredSubActionProgress($subAction) / 100) * $weight;
            });

            return $this->boundRate((float) $progress);
        }

        if ($this->isDeclaredNonQuantifiableActionCompleted($action)) {
            return 100.0;
        }

        return 0.0;
    }

    /**
     * KPI Delai (spec v2 PAS ANBG, 28/05/2026).
     *
     * Mode 'graduated' (par defaut, config kpis.delay.mode) :
     *     KPI_delai = max(0, 100 - (retard_jours / duree_prevue * 100))
     * Soumis a temps      -> 100.
     * Soumis en retard    -> dégradation progressive proportionnelle a la duree prevue.
     * Jamais soumis et echue -> 0.
     * Pas encore echue    -> 100 (non penalise).
     *
     * Mode 'binary' (rollback) : ancien comportement 100 ou 0 selon respect de l'echeance.
     */
    public function calculateDelayScore(Action $action, ?Carbon $referenceDate = null): float
    {
        $referenceDate = $referenceDate?->copy() ?? Carbon::today();
        $deadline = $action->date_echeance ?? $action->date_fin;

        if ($deadline === null) {
            return 100.0;
        }

        $deadline = Carbon::parse($deadline)->endOfDay();
        $mode = (string) config('kpis.delay.mode', 'graduated');

        if ($action->soumise_le !== null) {
            $submittedAt = Carbon::parse($action->soumise_le)->endOfDay();

            if ($submittedAt->lte($deadline)) {
                return 100.0;
            }

            if ($mode === 'binary') {
                return 0.0;
            }

            return $this->graduatedDelayScore($action, $deadline, $submittedAt);
        }

        if ($referenceDate->copy()->endOfDay()->gt($deadline)) {
            return 0.0;
        }

        return 100.0;
    }

    /**
     * Formule graduee : penalite proportionnelle a la duree prevue de l'action.
     * Fallback : penalite forfaitaire par jour quand date_debut manque.
     */
    private function graduatedDelayScore(Action $action, Carbon $deadline, Carbon $submittedAt): float
    {
        $retardJours = (float) $deadline->diffInDays($submittedAt, false);
        if ($retardJours <= 0.0) {
            return 100.0;
        }

        $start = $action->date_debut;
        if ($start !== null) {
            $start = Carbon::parse($start)->startOfDay();
            $dureePrevueJours = (float) $start->diffInDays($deadline->copy()->startOfDay(), false);

            if ($dureePrevueJours > 0.0) {
                $ratio = ($retardJours / $dureePrevueJours) * 100.0;

                return round($this->boundRate(100.0 - $ratio), 2);
            }
        }

        // Pas de date_debut OU duree calculee <= 0 : fallback forfaitaire.
        $dailyPenalty = (float) config('kpis.delay.fallback_daily_penalty', 5.0);
        $score = 100.0 - ($retardJours * $dailyPenalty);

        return round($this->boundRate($score), 2);
    }

    public function calculateQualityScore(Action $action): float
    {
        return $this->calculateConformityScore($action);
    }

    public function calculateValidationScore(Action $action): float
    {
        return 0.0;
    }

    public function calculateTargetScore(Action $action): float
    {
        return $this->calculateRealProgress($action) >= 100.0 ? 100.0 : 0.0;
    }

    public function calculateGlobalKpi(Action $action, ?Carbon $referenceDate = null): float
    {
        $weights = $this->officialWeights();
        $progress = $this->calculateRealProgress($action);
        $delay = $this->calculateDelayScore($action, $referenceDate);

        if ($progress <= 0.0 && $action->soumise_le === null) {
            return 0.0;
        }

        $score = ($progress * ($weights['performance'] / 100))
            + ($delay * ($weights['delay'] / 100));

        return round($this->boundRate($score), 2);
    }

    /**
     * KPI conformité supprimé de la règle métier active.
     * La méthode reste neutre pour compatibilité avec les colonnes historiques.
     */
    public function calculateConformityScore(Action $action): float
    {
        return 0.0;
    }

    public function calculateExecutionPerformance(Action $action, ?Carbon $referenceDate = null): float
    {
        $progress = $this->calculateRealProgress($action);

        if ($progress <= 0.0 || $this->normalizeStatus($action) === 'non_demarre') {
            return 0.0;
        }

        $delayScore = $this->calculateDelayScore($action, $referenceDate);

        $performance = ($progress * 0.70) + ($delayScore * 0.30);

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
            || in_array($status, ['effectuee', 'terminee', 'termine', 'realisee', 'realise', 'en_attente_validation_chef', 'validee', 'validee_chef', 'cloturee'], true);
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

        if (in_array($status, ['validee', 'validee_chef', 'cloturee'], true)) {
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

    private function isDeclaredNonQuantifiableActionCompleted(Action $action): bool
    {
        if ($this->businessRules->isActionQuantifiable($action)) {
            return false;
        }

        $validationStatus = (string) ($action->statut_validation ?? '');
        if (in_array($validationStatus, [
            ActionTrackingService::VALIDATION_REJETEE_CHEF,
            ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
        ], true)) {
            return false;
        }

        return $this->hasActionExecutionProof($action)
            || $action->date_fin_reelle !== null
            || $action->cloture_le !== null
            || in_array($validationStatus, [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true)
            || in_array(strtolower(trim((string) ($action->statut ?? ''))), ['termine', 'terminee', 'acheve', 'achevee'], true);
    }

    private function hasActionExecutionProof(Action $action): bool
    {
        $proofCategories = [
            'execution_quantitative',
            'execution_non_quantitative',
            'execution_mixte',
            'final',
        ];

        if ($action->relationLoaded('justificatifs')) {
            return $action->justificatifs
                ->contains(fn ($justificatif): bool => in_array((string) ($justificatif->categorie ?? ''), $proofCategories, true));
        }

        return $action->exists
            && $action->justificatifs()
                ->whereIn('categorie', $proofCategories)
                ->exists();
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
