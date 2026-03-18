<?php

namespace App\Services;

use App\Models\Direction;
use App\Models\Pas;
use Illuminate\Support\Str;

class PasStructureService
{
    /**
     * @param array<int, array<string, mixed>> $axes
     */
    public function sync(Pas $pas, array $axes, ?int $createdBy = null): void
    {
        $pas->axes()
            ->with('objectifs')
            ->get()
            ->each(function ($axe): void {
                $axe->objectifs()->delete();
                $axe->delete();
            });

        $usedAxeCodes = [];

        foreach (array_values($axes) as $axeIndex => $axeData) {
            $axeCode = $this->resolveUniqueCode(
                $usedAxeCodes,
                $axeData['code'] ?? null,
                'AXE',
                $axeIndex + 1
            );

            $axe = $pas->axes()->create([
                'direction_id' => null,
                'code' => $axeCode,
                'libelle' => trim((string) ($axeData['libelle'] ?? '')),
                'periode_debut' => $this->resolveAxisStartDate($axeData, (int) $pas->periode_debut),
                'periode_fin' => $this->resolveAxisEndDate($axeData, (int) $pas->periode_fin),
                'description' => $this->nullableString($axeData['description'] ?? null),
                'ordre' => (int) ($axeData['ordre'] ?? ($axeIndex + 1)),
                'created_by' => $createdBy,
            ]);

            $usedObjectifCodes = [];
            $objectifs = is_array($axeData['objectifs'] ?? null) ? $axeData['objectifs'] : [];

            foreach (array_values($objectifs) as $objectifIndex => $objectifData) {
                $objectifCode = $this->resolveUniqueCode(
                    $usedObjectifCodes,
                    $objectifData['code'] ?? null,
                    'OS'.($axeIndex + 1),
                    $objectifIndex + 1
                );

                $targetValues = $this->resolveTargetValues($objectifData);

                $axe->objectifs()->create([
                    'code' => $objectifCode,
                    'libelle' => trim((string) ($objectifData['libelle'] ?? '')),
                    'description' => $this->nullableString($objectifData['description'] ?? null),
                    'ordre' => (int) ($objectifData['ordre'] ?? ($objectifIndex + 1)),
                    'indicateur_global' => $this->nullableString($objectifData['indicateur_global'] ?? null),
                    'valeur_cible' => $this->nullableString($objectifData['valeur_cible'] ?? null),
                    'valeurs_cible' => $targetValues,
                    'created_by' => $createdBy,
                ]);
            }
        }

        $pas->directions()->sync(
            Direction::query()
                ->where('actif', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
        );
    }

    /**
     * @param array<string, bool> $usedCodes
     */
    private function resolveUniqueCode(array &$usedCodes, mixed $rawCode, string $prefix, int $position): string
    {
        $base = $this->normalizeCode($rawCode);
        if ($base === null) {
            $base = strtoupper($prefix).'-'.$position;
        }

        $candidate = $base;
        $suffix = 2;

        while (isset($usedCodes[$candidate])) {
            $candidate = Str::limit($base.'-'.$suffix, 30, '');
            $suffix++;
        }

        $usedCodes[$candidate] = true;

        return $candidate;
    }

    private function normalizeCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $ascii = Str::ascii($trimmed);
        $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '-', $ascii);
        $normalized = trim((string) $normalized, '-_');

        if ($normalized === '') {
            return null;
        }

        return Str::limit(strtoupper($normalized), 30, '');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $axeData
     */
    private function resolveAxisStartDate(array $axeData, int $defaultYear): string
    {
        $raw = $this->nullableString($axeData['periode_debut'] ?? null);

        return $raw ?? sprintf('%04d-01-01', $defaultYear);
    }

    /**
     * @param array<string, mixed> $axeData
     */
    private function resolveAxisEndDate(array $axeData, int $defaultYear): string
    {
        $raw = $this->nullableString($axeData['periode_fin'] ?? null);

        return $raw ?? sprintf('%04d-12-31', $defaultYear);
    }

    /**
     * @param array<string, mixed> $objectifData
     * @return array<string, scalar>|null
     */
    private function resolveTargetValues(array $objectifData): ?array
    {
        $targetValues = [];

        if (is_array($objectifData['valeurs_cible'] ?? null)) {
            foreach ($objectifData['valeurs_cible'] as $key => $value) {
                if (! is_string($key) || $key === '') {
                    continue;
                }

                if (is_scalar($value) && trim((string) $value) !== '') {
                    $targetValues[$key] = $value;
                }
            }
        }

        $indicateurGlobal = $this->nullableString($objectifData['indicateur_global'] ?? null);
        if ($indicateurGlobal !== null) {
            $targetValues['indicateur_global'] = $indicateurGlobal;
        }

        $valeurCible = $this->nullableString($objectifData['valeur_cible'] ?? null);
        if ($valeurCible !== null) {
            $targetValues['valeur_cible'] = $valeurCible;
        }

        return $targetValues === [] ? null : $targetValues;
    }
}
