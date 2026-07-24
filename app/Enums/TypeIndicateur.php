<?php

namespace App\Enums;

enum TypeIndicateur: string
{
    case Quantitatif = 'quantitatif';
    case NonQuantitatif = 'non_quantitatif';
    case Mixte = 'mixte';

    public static function fromLegacy(?string $value): self
    {
        return match (self::normalize($value)) {
            'q', 'quantitatif', 'quantitative', 'quantite', 'cible_quantitative' => self::Quantitatif,
            'm', 'mixte', 'mixed', 'composee', 'compose', 'sous_actions' => self::Mixte,
            default => self::NonQuantitatif,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $typeIndicateur): string => $typeIndicateur->value,
            self::cases()
        );
    }

    public function label(): string
    {
        return match ($this) {
            self::Quantitatif => 'Quantitatif',
            self::NonQuantitatif => 'Non quantitatif',
            self::Mixte => 'Mixte',
        };
    }

    public function tracksQuantity(): bool
    {
        return in_array($this, [self::Quantitatif, self::Mixte], true);
    }

    public function tracksDeliverable(): bool
    {
        return in_array($this, [self::NonQuantitatif, self::Mixte], true);
    }

    private static function normalize(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return str_replace([' ', '-'], '_', $value);
    }
}
