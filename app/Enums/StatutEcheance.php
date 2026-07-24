<?php

namespace App\Enums;

enum StatutEcheance: string
{
    case NonEchue = 'non_echue';
    case Echue = 'echue';

    public function label(): string
    {
        return match ($this) {
            self::NonEchue => 'Non echue',
            self::Echue => 'Echue',
        };
    }
}
