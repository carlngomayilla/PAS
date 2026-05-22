<?php

namespace App\Support;

/**
 * Classement à 3 niveaux pour le KPI Conformité noté par le chef.
 *
 * Bornes (note brute /100) :
 *   - 0   à 49.99  -> Faible  (rouge / danger)
 *   - 50  à 74.99  -> Moyen   (orange / warning)
 *   - 75  à 100    -> Élevé   (vert / success)
 *
 * Un rejet chef ou direction est représenté par une note de 0 et donne le
 * niveau Faible. L'absence de note (action pas encore évaluée) est traitée
 * séparément par les vues (badge neutre « En attente »).
 */
final class KpiLevel
{
    public const CODE_FAIBLE = 'faible';
    public const CODE_MOYEN = 'moyen';
    public const CODE_ELEVE = 'eleve';
    public const CODE_NON_EVALUE = 'non_evalue';

    /**
     * @return array{code: string, label: string, tone: string, score: float}
     */
    public static function conformiteLevel(?float $score, bool $isEvaluated = true): array
    {
        if (! $isEvaluated || $score === null) {
            return [
                'code' => self::CODE_NON_EVALUE,
                'label' => 'En attente',
                'tone' => 'neutral',
                'score' => 0.0,
            ];
        }

        $bounded = max(0.0, min(100.0, $score));

        return match (true) {
            $bounded >= 75.0 => [
                'code' => self::CODE_ELEVE,
                'label' => 'Élevé',
                'tone' => 'success',
                'score' => $bounded,
            ],
            $bounded >= 50.0 => [
                'code' => self::CODE_MOYEN,
                'label' => 'Moyen',
                'tone' => 'warning',
                'score' => $bounded,
            ],
            default => [
                'code' => self::CODE_FAIBLE,
                'label' => 'Faible',
                'tone' => 'danger',
                'score' => $bounded,
            ],
        };
    }
}
