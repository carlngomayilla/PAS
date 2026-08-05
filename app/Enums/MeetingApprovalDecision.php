<?php

namespace App\Enums;

enum MeetingApprovalDecision: string
{
    case Validated = 'VALIDATED';
    case CorrectionRequested = 'CORRECTION_REQUESTED';

    public function label(): string
    {
        return match ($this) {
            self::Validated => 'Validé',
            self::CorrectionRequested => 'Correction demandée',
        };
    }
}
