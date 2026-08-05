<?php

namespace App\Enums;

/**
 * Niveaux du circuit de validation d'un PV. L'ordre est sequentiel :
 * la Planification n'intervient qu'apres le SCIQ.
 */
enum MeetingApprovalLevel: string
{
    case Sciq = 'SCIQ';
    case Planification = 'PLANIFICATION';

    public function label(): string
    {
        return match ($this) {
            self::Sciq => 'Contrôle SCIQ',
            self::Planification => 'Validation Planification',
        };
    }

    /** Rang dans le circuit : 1 = premier visa. */
    public function rank(): int
    {
        return match ($this) {
            self::Sciq => 1,
            self::Planification => 2,
        };
    }

    /** Statut de la reunion pendant que ce niveau instruit le PV. */
    public function pendingStatus(): MeetingStatus
    {
        return match ($this) {
            self::Sciq => MeetingStatus::EnValidationSciq,
            self::Planification => MeetingStatus::EnValidationPlanification,
        };
    }

    /** Statut atteint quand ce niveau valide. */
    public function validatedStatus(): MeetingStatus
    {
        return match ($this) {
            self::Sciq => MeetingStatus::EnValidationPlanification,
            self::Planification => MeetingStatus::ValideeDefinitivement,
        };
    }
}
