<?php

namespace App\Services\Planning;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PlanningWorkflowRulesService
{
    /**
     * @var array<string, int>
     */
    public const GLOBAL_WEIGHTS = [
        'performance' => 40,
        'delay' => 20,
        'quality' => 30,
        'risk' => 10,
    ];

    /**
     * @var list<string>
     */
    public const ACTION_STATUSES = [
        'brouillon',
        'en_attente_validation',
        'validee',
        'en_cours',
        'realisee_dans_delais',
        'realisee_hors_delai',
        'en_retard',
        'bloquee',
        'a_corriger',
        'rejetee',
        'cloturee',
    ];

    /**
     * @var list<string>
     */
    public const REQUIRED_ACTION_FIELDS = [
        'responsable_id',
        'quantite_cible',
        'date_debut',
        'date_fin',
        'resultat_attendu',
        'objectif_operationnel_id',
        'service_id',
        'direction_id',
    ];

    public function deadlineIsWithinParent(mixed $childDeadline, mixed $parentDeadline): bool
    {
        if ($childDeadline === null || $childDeadline === '' || $parentDeadline === null || $parentDeadline === '') {
            return true;
        }

        return $this->toDate($childDeadline)?->lte($this->toDate($parentDeadline)) ?? true;
    }

    /**
     * @param iterable<int, mixed> $steps
     */
    public function performanceFromSteps(iterable $steps): float
    {
        $total = 0;
        $completed = 0;

        foreach ($steps as $step) {
            $total++;

            if ($this->isCompletedStepWithProof($step)) {
                $completed++;
            }
        }

        if ($total === 0) {
            return 0.0;
        }

        return round(($completed / $total) * 100, 2);
    }

    public function delayScore(mixed $plannedEnd, mixed $actualEnd = null, bool $completed = false): float
    {
        $planned = $this->toDate($plannedEnd);
        if (! $planned instanceof CarbonInterface) {
            return 0.0;
        }

        if ($completed) {
            $actual = $this->toDate($actualEnd) ?? Carbon::now();

            return $actual->lte($planned) ? 100.0 : 60.0;
        }

        return Carbon::now()->gt($planned) ? 0.0 : 70.0;
    }

    public function qualityScore(float|int|null $serviceNote, float|int|null $directionNote): ?float
    {
        if ($serviceNote === null || $directionNote === null) {
            return null;
        }

        return round((((float) $serviceNote) + ((float) $directionNote)) / 2, 2);
    }

    /**
     * @param array<string, mixed> $signals
     * @return array{level:string,color:string,score:float,recommendation:string}
     */
    public function riskProfile(array $signals): array
    {
        $score = 100.0;

        $score -= ((int) ($signals['late_days'] ?? 0)) * 4;
        $score -= ((int) ($signals['blocking_count'] ?? 0)) * 12;
        $score -= ((int) ($signals['missing_proofs'] ?? 0)) * 10;
        $score -= ((int) ($signals['rejections'] ?? 0)) * 12;
        $score -= ((int) ($signals['under_threshold_indicators'] ?? 0)) * 8;

        if ((bool) ($signals['has_no_completed_steps'] ?? false)) {
            $score -= 18;
        }

        $score = max(0.0, min(100.0, $score));

        if ($score < 35) {
            return [
                'level' => 'Critique',
                'color' => 'red',
                'score' => $score,
                'recommendation' => 'Arbitrage prioritaire requis.',
            ];
        }

        if ($score < 60) {
            return [
                'level' => 'Eleve',
                'color' => 'orange',
                'score' => $score,
                'recommendation' => 'Plan correctif a suivre.',
            ];
        }

        if ($score < 80) {
            return [
                'level' => 'Modere',
                'color' => 'yellow',
                'score' => $score,
                'recommendation' => 'Surveillance renforcee.',
            ];
        }

        return [
            'level' => 'Faible',
            'color' => 'green',
            'score' => $score,
            'recommendation' => 'Execution sous controle.',
        ];
    }

    /**
     * @param array<string, int|float> $weights
     */
    public function globalPerformance(
        float|int $performance,
        float|int $delay,
        float|int|null $quality,
        float|int $risk,
        array $weights = self::GLOBAL_WEIGHTS
    ): float {
        $qualityValue = $quality === null ? 0.0 : (float) $quality;
        $totalWeight = max(1, array_sum($weights));

        $score = ((float) $performance * ($weights['performance'] ?? 40))
            + ((float) $delay * ($weights['delay'] ?? 20))
            + ($qualityValue * ($weights['quality'] ?? 30))
            + ((float) $risk * ($weights['risk'] ?? 10));

        return round($score / $totalWeight, 2);
    }

    private function isCompletedStepWithProof(mixed $step): bool
    {
        $status = strtolower((string) data_get($step, 'statut', data_get($step, 'status', '')));
        $completed = in_array($status, ['terminee', 'terminée', 'termine', 'terminé', 'achevee', 'achevé'], true);

        if (! $completed) {
            return false;
        }

        return filled(data_get($step, 'justificatif_id'))
            || filled(data_get($step, 'justificatif'))
            || filled(data_get($step, 'proof_id'))
            || filled(data_get($step, 'preuve'));
    }

    private function toDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
