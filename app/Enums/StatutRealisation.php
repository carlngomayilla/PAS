<?php

namespace App\Enums;

enum StatutRealisation: string
{
    case AParametrer = 'a_parametrer';
    case NonDemarree = 'non_demarree';
    case EnCours = 'en_cours';
    case PartiellementRealisee = 'partiellement_realisee';
    case Realisee = 'realisee';
    case Cloturee = 'cloturee';

    public static function fromRate(?float $rate, bool $closed = false, float $completionThreshold = 100.0): self
    {
        if ($closed) {
            return self::Cloturee;
        }

        if ($rate === null) {
            return self::AParametrer;
        }

        $completionThreshold = min(100.0, max(0.0, $completionThreshold));

        if ($rate >= $completionThreshold) {
            return self::Realisee;
        }

        if ($rate <= 0.0) {
            return self::NonDemarree;
        }

        return self::PartiellementRealisee;
    }

    public function label(): string
    {
        return match ($this) {
            self::AParametrer => 'A parametrer',
            self::NonDemarree => 'Non demarree',
            self::EnCours => 'En cours',
            self::PartiellementRealisee => 'Partiellement realisee',
            self::Realisee => 'Realisee',
            self::Cloturee => 'Cloturee',
        };
    }

    public function legacyStatus(): string
    {
        return match ($this) {
            self::AParametrer => 'a_parametrer',
            self::NonDemarree => 'en_attente',
            self::EnCours, self::PartiellementRealisee => 'en_cours',
            self::Realisee, self::Cloturee => 'realise',
        };
    }
}
