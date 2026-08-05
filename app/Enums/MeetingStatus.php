<?php

namespace App\Enums;

/**
 * Statuts du module de gestion des reunions.
 *
 * Circuit normal :
 *   AProgrammer -> Programmee -> PvAttendu -> EnValidationSciq
 *                -> EnValidationPlanification -> VallideeDefinitivement
 *
 * Circuit avec correction :
 *   EnValidationSciq | EnValidationPlanification -> ACorriger
 *                -> (nouveau PV) -> EnValidationSciq
 */
enum MeetingStatus: string
{
    case AProgrammer = 'a_programmer';
    case Programmee = 'programmee';
    case Reportee = 'reportee';
    case Annulee = 'annulee';
    case PvAttendu = 'pv_attendu';
    case EnValidationSciq = 'en_validation_sciq';
    case ACorriger = 'a_corriger';
    case EnValidationPlanification = 'en_validation_planification';
    case ValideeDefinitivement = 'validee_definitivement';

    public function label(): string
    {
        return match ($this) {
            self::AProgrammer => 'À programmer',
            self::Programmee => 'Programmée',
            self::Reportee => 'Reportée',
            self::Annulee => 'Annulée',
            self::PvAttendu => 'PV attendu',
            self::EnValidationSciq => 'En validation SCIQ',
            self::ACorriger => 'À corriger',
            self::EnValidationPlanification => 'En validation Planification',
            self::ValideeDefinitivement => 'Validée définitivement',
        };
    }

    /** Ton d'affichage du badge. */
    public function tone(): string
    {
        return match ($this) {
            self::AProgrammer, self::Reportee => 'neutral',
            self::Programmee => 'info',
            self::Annulee => 'danger',
            self::PvAttendu, self::ACorriger => 'warning',
            self::EnValidationSciq, self::EnValidationPlanification => 'info',
            self::ValideeDefinitivement => 'success',
        };
    }

    /**
     * Transitions autorisees. Toute transition absente de cette table est
     * refusee : le circuit ne peut pas etre court-circuite.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AProgrammer => [self::Programmee, self::Annulee],
            self::Programmee => [self::Reportee, self::Annulee, self::PvAttendu, self::EnValidationSciq],
            self::Reportee => [self::Programmee, self::Reportee, self::Annulee, self::PvAttendu, self::EnValidationSciq],
            self::PvAttendu => [self::EnValidationSciq, self::Reportee, self::Annulee],
            self::EnValidationSciq => [self::EnValidationPlanification, self::ACorriger],
            self::ACorriger => [self::EnValidationSciq],
            self::EnValidationPlanification => [self::ValideeDefinitivement, self::ACorriger],
            // Etats terminaux.
            self::ValideeDefinitivement, self::Annulee => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** La reunion est-elle officiellement tenue ? */
    public function isOfficiallyHeld(): bool
    {
        return $this === self::ValideeDefinitivement;
    }

    /** Un PV est-il en cours de circuit ? */
    public function isUnderReview(): bool
    {
        return in_array($this, [self::EnValidationSciq, self::EnValidationPlanification], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::ValideeDefinitivement, self::Annulee], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
