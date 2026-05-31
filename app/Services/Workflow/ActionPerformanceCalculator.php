<?php

namespace App\Services\Workflow;

use App\Models\Action;
use App\Models\SousAction;
use Illuminate\Support\Carbon;

/**
 * Calculateur de performance — Workflow de suivi V2.
 *
 * Voir docs/WORKFLOW-SUIVI-V2.md.
 *
 * Ce service est PUR : il ne persiste rien, ne déclenche aucune notification.
 * Il calcule uniquement, à partir des règles définies dans le PTA :
 *   - la performance PROVISOIRE d'une action / sous-action,
 *   - le statut de performance (critique → cible dépassée),
 *   - le statut temporel (dimension transverse échéance),
 *   - l'état de conformité (dimension transverse : justificatif / commentaire / difficulté).
 *
 * Les paliers de performance quantitative sont fixes en v1 (50 / 80 / 100),
 * alignés sur Action::resolveQuantitativeExecutionStatus(). Ils pourront être
 * rendus configurables (seuil_t1..t4) dans une itération ultérieure.
 */
class ActionPerformanceCalculator
{
    // Paliers de performance quantitative (en %).
    public const SEUIL_CRITIQUE = 50.0;
    public const SEUIL_ALERTE = 80.0;
    public const SEUIL_CIBLE = 100.0;

    // Statuts de performance (6 niveaux, alignés sur le doc V2 §6.1).
    public const PERF_NON_DEMARRE = 'non_demarre';
    public const PERF_CRITIQUE = 'critique';       // 1 → 49 %
    public const PERF_ALERTE = 'en_alerte';         // 50 → 79 %
    public const PERF_ACCEPTABLE = 'acceptable';    // 80 → 99 %
    public const PERF_CIBLE_ATTEINTE = 'cible_atteinte';  // 100 %
    public const PERF_CIBLE_DEPASSEE = 'cible_depassee';  // > 100 %

    // Statuts temporels.
    public const TEMPS_DANS_DELAI = 'dans_delai';
    public const TEMPS_BIENTOT_RETARD = 'bientot_retard';
    public const TEMPS_EN_RETARD = 'en_retard';
    public const TEMPS_CRITIQUE = 'critique';
    public const TEMPS_SANS_ECHEANCE = 'sans_echeance';

    // Fenêtre (en jours) avant l'échéance pour signaler "bientôt en retard".
    private const SEUIL_BIENTOT_RETARD_JOURS = 7;

    /**
     * Performance PROVISOIRE d'une action (0-100+), selon son type.
     */
    public function provisionalPerformance(Action $action): float
    {
        return match ($action->resolvedTypeAction()) {
            Action::TYPE_QUANTITATIVE => $this->quantitativePerformance(
                (float) ($action->quantite_realisee ?? 0),
                (float) ($action->quantite_cible ?? 0)
            ),
            Action::TYPE_NON_QUANTITATIVE => $this->binaryPerformance(
                $this->actionHasProof($action)
            ),
            Action::TYPE_COMPOSEE => $this->compositePerformance($action),
            default => 0.0,
        };
    }

    /**
     * Performance PROVISOIRE d'une sous-action (0-100+).
     * v1 simplifiée : réalisé/prévu (quantitative) ou 0/100 (non quantitative).
     */
    public function subActionPerformance(SousAction $sousAction): float
    {
        if ($sousAction->isQuantitative()) {
            return $this->quantitativePerformance(
                (float) ($sousAction->quantite_realisee ?? 0),
                (float) ($sousAction->cible_prevue ?? 0)
            );
        }

        return $this->binaryPerformance($this->subActionHasProof($sousAction));
    }

    /**
     * Performance d'une action COMPOSÉE = Σ(perf_sous_action × poids).
     * Si aucun poids défini → moyenne simple.
     */
    public function compositePerformance(Action $action): float
    {
        $subActions = $action->relationLoaded('sousActions')
            ? $action->sousActions
            : $action->sousActions()->get();

        if ($subActions->isEmpty()) {
            return 0.0;
        }

        $totalWeight = $subActions->sum(static fn (SousAction $sa): float => (float) ($sa->weight ?? 0));

        // Mode pondéré : au moins une sous-action a un poids.
        if ($totalWeight > 0) {
            $weighted = $subActions->sum(function (SousAction $sa): float {
                $weight = (float) ($sa->weight ?? 0);

                return $this->subActionPerformance($sa) * $weight;
            });

            return round($weighted / $totalWeight, 2);
        }

        // Mode moyenne simple (aucun poids défini).
        $average = $subActions->avg(fn (SousAction $sa): float => $this->subActionPerformance($sa));

        return round((float) $average, 2);
    }

    /**
     * Mappe un taux de réalisation (%) sur un statut de performance.
     */
    public function performanceStatus(float $percent): string
    {
        if ($percent <= 0.0) {
            return self::PERF_NON_DEMARRE;
        }
        if ($percent > self::SEUIL_CIBLE) {
            return self::PERF_CIBLE_DEPASSEE;
        }
        if ($percent >= self::SEUIL_CIBLE) {
            return self::PERF_CIBLE_ATTEINTE;
        }
        if ($percent >= self::SEUIL_ALERTE) {
            return self::PERF_ACCEPTABLE;   // 80 → 99 %
        }
        if ($percent >= self::SEUIL_CRITIQUE) {
            return self::PERF_ALERTE;        // 50 → 79 %
        }

        return self::PERF_CRITIQUE;          // 1 → 49 %
    }

    /**
     * Statut temporel (dimension transverse échéance).
     */
    public function temporalStatus(Action $action, ?Carbon $reference = null): string
    {
        $deadline = $action->date_echeance ?? $action->echeance_cible ?? null;
        if ($deadline === null) {
            return self::TEMPS_SANS_ECHEANCE;
        }

        $today = ($reference ?? Carbon::now())->startOfDay();
        $deadlineDay = Carbon::parse($deadline)->startOfDay();

        if ($today->lte($deadlineDay)) {
            $daysLeft = $today->diffInDays($deadlineDay, false);

            return $daysLeft <= self::SEUIL_BIENTOT_RETARD_JOURS
                ? self::TEMPS_BIENTOT_RETARD
                : self::TEMPS_DANS_DELAI;
        }

        // Échéance dépassée : critique si action jamais démarrée, sinon en retard.
        $started = (float) ($action->quantite_realisee ?? 0) > 0
            || (float) ($action->progression_reelle ?? 0) > 0
            || $this->actionHasProof($action);

        return $started ? self::TEMPS_EN_RETARD : self::TEMPS_CRITIQUE;
    }

    /**
     * État de conformité d'une action pour la SOUMISSION.
     *
     * @return array{proof_ok:bool,comment_ok:bool,difficulty_ok:bool,can_submit:bool,missing:list<string>}
     */
    public function actionConformity(Action $action, ?string $comment = null, ?string $difficulty = null, bool $hasNewProof = false): array
    {
        $proofOk = ! (bool) $action->justificatif_obligatoire
            || $hasNewProof
            || $this->actionHasProof($action);

        $commentOk = ! (bool) $action->requires_comment
            || trim((string) $comment) !== '';

        // La difficulté n'est exigée que si le champ est activé ET qu'une difficulté
        // est effectivement signalée (texte non vide attendu dans ce cas).
        $difficultyOk = ! (bool) $action->allows_difficulty
            || $difficulty === null
            || trim((string) $difficulty) !== '';

        // Quantité requise à la soumission pour une action quantitative.
        $quantityOk = ! $action->isQuantitative()
            || (float) ($action->quantite_realisee ?? 0) > 0;

        $missing = [];
        if (! $proofOk) {
            $missing[] = 'justificatif';
        }
        if (! $commentOk) {
            $missing[] = 'commentaire';
        }
        if (! $quantityOk) {
            $missing[] = 'quantite';
        }

        return [
            'proof_ok' => $proofOk,
            'comment_ok' => $commentOk,
            'difficulty_ok' => $difficultyOk,
            'quantity_ok' => $quantityOk,
            'can_submit' => $proofOk && $commentOk && $difficultyOk && $quantityOk,
            'missing' => $missing,
        ];
    }

    // ── HELPERS PRIVÉS ───────────────────────────────────────────────────────

    private function quantitativePerformance(float $realized, float $target): float
    {
        if ($target <= 0.0) {
            // Pas de cible chiffrée → binaire sur la quantité réalisée.
            return $realized > 0 ? 100.0 : 0.0;
        }

        return round(($realized / $target) * 100, 2);
    }

    private function binaryPerformance(bool $hasProof): float
    {
        return $hasProof ? 100.0 : 0.0;
    }

    private function actionHasProof(Action $action): bool
    {
        if ($action->relationLoaded('justificatifs')) {
            return $action->justificatifs
                ->whereIn('categorie', ['execution_quantitative', 'execution_non_quantitative', 'execution_mixte', 'final'])
                ->isNotEmpty();
        }

        return $action->justificatifs()
            ->whereIn('categorie', ['execution_quantitative', 'execution_non_quantitative', 'execution_mixte', 'final'])
            ->exists();
    }

    private function subActionHasProof(SousAction $sousAction): bool
    {
        if ($sousAction->relationLoaded('justificatifs')) {
            return $sousAction->justificatifs->isNotEmpty();
        }

        return $sousAction->justificatifs()->exists();
    }
}
