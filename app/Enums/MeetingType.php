<?php

namespace App\Enums;

enum MeetingType: string
{
    case Service = 'service';
    case Direction = 'direction';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Réunion de service',
            self::Direction => 'Réunion de direction',
        };
    }

    /** Une reunion de service est rattachee a un service, pas une reunion de direction. */
    public function requiresService(): bool
    {
        return $this === self::Service;
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
