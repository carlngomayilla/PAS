<?php

namespace App\Services\Dashboard;

final readonly class DashboardFilterData
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            exercice: self::nullableInteger($validated['exercice'] ?? null),
            periode: self::nullableString($validated['periode'] ?? null),
            directionId: self::nullableInteger($validated['direction_id'] ?? null),
            serviceId: self::nullableInteger($validated['service_id'] ?? null),
            responsableId: self::nullableInteger($validated['responsable_id'] ?? null),
            statutAction: self::nullableString($validated['statut_action'] ?? null),
            statutSuivi: self::nullableString($validated['statut_suivi'] ?? null),
            statutDelai: self::nullableString($validated['statut_delai'] ?? null),
            alerteEcheance: self::nullableString($validated['alerte_echeance'] ?? null),
        );
    }

    public function __construct(
        public ?int $exercice,
        public ?string $periode,
        public ?int $directionId,
        public ?int $serviceId,
        public ?int $responsableId,
        public ?string $statutAction,
        public ?string $statutSuivi,
        public ?string $statutDelai,
        public ?string $alerteEcheance,
    ) {}

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'exercice' => $this->exercice,
            'periode' => $this->periode,
            'direction_id' => $this->directionId,
            'service_id' => $this->serviceId,
            'responsable_id' => $this->responsableId,
            'statut_action' => $this->statutAction,
            'statut_suivi' => $this->statutSuivi,
            'statut_delai' => $this->statutDelai,
            'alerte_echeance' => $this->alerteEcheance,
        ];
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
