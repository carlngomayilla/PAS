<?php

namespace App\Enums;

enum StatutRetard: string
{
    case DansLesDelais = 'dans_les_delais';
    case EnRetard = 'en_retard';

    public function label(): string
    {
        return match ($this) {
            self::DansLesDelais => 'Dans les delais',
            self::EnRetard => 'Hors délai / En retard',
        };
    }
}
