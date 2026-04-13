<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagedKpiSettings
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'managed_kpis')
                ->pluck('value', 'key')
                ->all();

            if (array_key_exists('definitions', $stored)) {
                $decoded = json_decode((string) $stored['definitions'], true);
                if (is_array($decoded)) {
                    $settings = $this->sanitizeDefinitions($decoded);
                }
            }
        }

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function defaults(): array
    {
        return $this->sanitizeDefinitions([
            [
                'code' => 'delai',
                'label' => 'Delai',
                'description' => 'Respect moyen des echeances sur les actions visibles.',
                'weight' => 15,
                'green_threshold' => 85,
                'orange_threshold' => 65,
                'visible' => true,
                'source_metric' => 'delai',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'performance',
                'label' => 'Performance',
                'description' => 'Performance moyenne des actions visibles.',
                'weight' => 20,
                'green_threshold' => 85,
                'orange_threshold' => 65,
                'visible' => true,
                'source_metric' => 'performance',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'conformite',
                'label' => 'Conformite',
                'description' => 'Conformite documentaire et procedurale.',
                'weight' => 15,
                'green_threshold' => 80,
                'orange_threshold' => 60,
                'visible' => true,
                'source_metric' => 'conformite',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'qualite',
                'label' => 'Qualite',
                'description' => 'Qualite moyenne des livrables et de l execution.',
                'weight' => 15,
                'green_threshold' => 85,
                'orange_threshold' => 65,
                'visible' => true,
                'source_metric' => 'qualite',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'risque',
                'label' => 'Risque',
                'description' => 'Niveau de maitrise des risques sur les actions visibles.',
                'weight' => 10,
                'green_threshold' => 75,
                'orange_threshold' => 55,
                'visible' => true,
                'source_metric' => 'risque',
                'formula_mode' => 'inverse',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'global',
                'label' => 'Score global',
                'description' => 'Synthese composite globale du portefeuille statistique.',
                'weight' => 20,
                'green_threshold' => 85,
                'orange_threshold' => 65,
                'visible' => true,
                'source_metric' => 'global',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
            [
                'code' => 'progression',
                'label' => 'Progression',
                'description' => 'Progression reelle moyenne des actions suivies.',
                'weight' => 5,
                'green_threshold' => 80,
                'orange_threshold' => 60,
                'visible' => true,
                'source_metric' => 'progression',
                'formula_mode' => 'direct',
                'secondary_metric' => null,
                'tertiary_metric' => null,
                'secondary_weight' => 0,
                'tertiary_weight' => 0,
                'target_value' => null,
                'adjustment' => 0,
                'target_profiles' => [],
                'target_direction_ids' => [],
                'target_service_ids' => [],
            ],
        ]);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return collect($this->all())
            ->mapWithKeys(fn (array $definition, string $code): array => [$code => (string) $definition['label']])
            ->all();
    }

    /**
     * @return array{visible:int,total:int,profiles:int}
     */
    public function summary(): array
    {
        $all = collect($this->all());

        return [
            'visible' => $all->where('visible', true)->count(),
            'total' => $all->count(),
            'profiles' => $all->flatMap(fn (array $definition): array => $definition['target_profiles'] ?? [])->unique()->count(),
        ];
    }

    /**
     * @param  array<string, float|int>  $summary
     * @param  array{role?:string|null,direction_id?:int|null,service_id?:int|null}|null  $context
     * @return list<array<string, mixed>>
     */
    public function buildRuntimeMetrics(array $summary, ?array $context = null): array
    {
        $role = trim((string) ($context['role'] ?? '')) ?: null;
        $directionId = isset($context['direction_id']) ? (int) $context['direction_id'] : null;
        $serviceId = isset($context['service_id']) ? (int) $context['service_id'] : null;

        return collect($this->all())
            ->filter(function (array $definition) use ($role, $directionId, $serviceId): bool {
                if (! (bool) ($definition['visible'] ?? true)) {
                    return false;
                }

                $targets = is_array($definition['target_profiles'] ?? null) ? $definition['target_profiles'] : [];
                if ($targets === [] || $role === null || trim($role) === '') {
                    // no-op
                } elseif (! in_array($role, $targets, true)) {
                    return false;
                }

                $targetDirections = collect($definition['target_direction_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values();
                if ($targetDirections->isNotEmpty() && ($directionId === null || ! $targetDirections->contains($directionId))) {
                    return false;
                }

                $targetServices = collect($definition['target_service_ids'] ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->values();
                if ($targetServices->isNotEmpty() && ($serviceId === null || ! $targetServices->contains($serviceId))) {
                    return false;
                }

                return true;
            })
            ->map(function (array $definition, string $code) use ($summary): array {
                $sourceMetric = (string) ($definition['source_metric'] ?? $code);
                $rawValue = round((float) ($summary[$sourceMetric] ?? $summary[$code] ?? 0), 2);
                $formulaMode = (string) ($definition['formula_mode'] ?? 'direct');
                $value = $this->computeMetricValue($definition, $summary, $code);
                $green = (float) ($definition['green_threshold'] ?? 80);
                $orange = (float) ($definition['orange_threshold'] ?? 60);

                return [
                    'code' => $code,
                    'label' => (string) ($definition['label'] ?? Str::headline($code)),
                    'description' => (string) ($definition['description'] ?? ''),
                    'value' => $value,
                    'raw_value' => $rawValue,
                    'source_metric' => $sourceMetric,
                    'formula_mode' => $formulaMode,
                    'formula_label' => $this->formulaModeOptions()[$formulaMode] ?? Str::headline(str_replace('_', ' ', $formulaMode)),
                    'formula_summary' => $this->formulaSummary($definition),
                    'secondary_metric' => $definition['secondary_metric'] ?? null,
                    'tertiary_metric' => $definition['tertiary_metric'] ?? null,
                    'secondary_weight' => (int) ($definition['secondary_weight'] ?? 0),
                    'tertiary_weight' => (int) ($definition['tertiary_weight'] ?? 0),
                    'target_value' => $definition['target_value'] ?? null,
                    'adjustment' => (float) ($definition['adjustment'] ?? 0),
                    'weight' => (int) ($definition['weight'] ?? 0),
                    'green_threshold' => $green,
                    'orange_threshold' => $orange,
                    'tone' => $value >= $green ? 'success' : ($value >= $orange ? 'warning' : 'danger'),
                    'target_profiles' => $definition['target_profiles'] ?? [],
                    'target_direction_ids' => $definition['target_direction_ids'] ?? [],
                    'target_service_ids' => $definition['target_service_ids'] ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function sourceMetricOptions(): array
    {
        return [
            'delai' => 'Delai',
            'performance' => 'Performance',
            'conformite' => 'Conformite',
            'qualite' => 'Qualite',
            'risque' => 'Risque',
            'global' => 'Score global',
            'progression' => 'Progression',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function formulaModeOptions(): array
    {
        return [
            'direct' => 'Lecture directe',
            'inverse' => 'Lecture inverse (100 - valeur)',
            'weighted_average' => 'Moyenne ponderee multi-sources',
            'gap_to_target' => 'Ecart a la cible',
            'minimum' => 'Minimum des sources',
            'maximum' => 'Maximum des sources',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $definitions = $this->normalizePayload($payload);

        PlatformSetting::query()->updateOrCreate(
            ['group' => 'managed_kpis', 'key' => 'definitions'],
            [
                'value' => json_encode(array_values($definitions), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_by' => $actor?->id,
            ]
        );

        $this->flush();

        return $this->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    public function normalizePayload(array $payload): array
    {
        $rows = $payload['definitions'] ?? [];
        if (! is_array($rows)) {
            throw ValidationException::withMessages([
                'definitions' => 'Definitions KPI invalides.',
            ]);
        }

        $definitions = $this->sanitizeDefinitions($rows);
        if ($definitions === []) {
            throw ValidationException::withMessages([
                'definitions' => 'Renseignez au moins un KPI pilote.',
            ]);
        }

        return $definitions;
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
    }

    /**
     * @param  iterable<int, mixed>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function sanitizeDefinitions(iterable $rows): array
    {
        $allowedCodes = ['delai', 'performance', 'conformite', 'qualite', 'risque', 'global', 'progression'];
        $allowedProfiles = array_values(array_filter([
            User::ROLE_AGENT,
            User::ROLE_SERVICE,
            User::ROLE_DIRECTION,
            User::ROLE_PLANIFICATION,
            User::ROLE_DG,
            User::ROLE_ADMIN,
            User::ROLE_SUPER_ADMIN,
            User::ROLE_CABINET,
        ]));
        $allowedSourceMetrics = array_keys($this->sourceMetricOptions());
        $allowedFormulaModes = array_keys($this->formulaModeOptions());

        $definitions = collect($rows)
            ->map(function ($row) use ($allowedCodes, $allowedProfiles, $allowedSourceMetrics, $allowedFormulaModes): ?array {
                if (! is_array($row)) {
                    return null;
                }

                $code = strtolower(trim((string) ($row['code'] ?? '')));
                if (! in_array($code, $allowedCodes, true)) {
                    return null;
                }

                $label = trim((string) ($row['label'] ?? ''));
                $description = trim((string) ($row['description'] ?? ''));
                $weight = max(0, min(100, (int) ($row['weight'] ?? 0)));
                $green = max(0.0, min(100.0, (float) ($row['green_threshold'] ?? 80)));
                $orange = max(0.0, min(100.0, (float) ($row['orange_threshold'] ?? 60)));
                if ($orange > $green) {
                    [$orange, $green] = [$green, $orange];
                }

                $profiles = collect(is_array($row['target_profiles'] ?? null) ? $row['target_profiles'] : [])
                    ->map(fn ($profile): string => trim((string) $profile))
                    ->filter(fn (string $profile): bool => in_array($profile, $allowedProfiles, true))
                    ->unique()
                    ->values()
                    ->all();
                $sourceMetric = trim((string) ($row['source_metric'] ?? $code));
                if (! in_array($sourceMetric, $allowedSourceMetrics, true)) {
                    $sourceMetric = $code;
                }
                $formulaMode = trim((string) ($row['formula_mode'] ?? 'direct'));
                if (! in_array($formulaMode, $allowedFormulaModes, true)) {
                    $formulaMode = 'direct';
                }
                $secondaryMetric = trim((string) ($row['secondary_metric'] ?? ''));
                if (! in_array($secondaryMetric, $allowedSourceMetrics, true)) {
                    $secondaryMetric = null;
                }
                $tertiaryMetric = trim((string) ($row['tertiary_metric'] ?? ''));
                if (! in_array($tertiaryMetric, $allowedSourceMetrics, true)) {
                    $tertiaryMetric = null;
                }
                $secondaryWeight = max(0, min(100, (int) ($row['secondary_weight'] ?? 0)));
                $tertiaryWeight = max(0, min(100, (int) ($row['tertiary_weight'] ?? 0)));
                $targetValue = $row['target_value'] ?? null;
                $targetValue = $targetValue === '' || $targetValue === null
                    ? null
                    : max(0.0, min(100.0, (float) $targetValue));
                $adjustment = max(-100.0, min(100.0, (float) ($row['adjustment'] ?? 0)));
                $targetDirectionIds = collect(is_array($row['target_direction_ids'] ?? null) ? $row['target_direction_ids'] : [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();
                $targetServiceIds = collect(is_array($row['target_service_ids'] ?? null) ? $row['target_service_ids'] : [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'code' => $code,
                    'label' => Str::limit($label !== '' ? $label : Str::headline($code), 60, ''),
                    'description' => Str::limit($description, 180, ''),
                    'weight' => $weight,
                    'green_threshold' => $green,
                    'orange_threshold' => $orange,
                    'visible' => filter_var($row['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'source_metric' => $sourceMetric,
                    'formula_mode' => $formulaMode,
                    'secondary_metric' => $secondaryMetric,
                    'tertiary_metric' => $tertiaryMetric,
                    'secondary_weight' => $secondaryWeight,
                    'tertiary_weight' => $tertiaryWeight,
                    'target_value' => $targetValue,
                    'adjustment' => $adjustment,
                    'target_profiles' => $profiles,
                    'target_direction_ids' => $targetDirectionIds,
                    'target_service_ids' => $targetServiceIds,
                ];
            })
            ->filter()
            ->keyBy('code');

        foreach ($allowedCodes as $code) {
            if (! $definitions->has($code)) {
                $default = $this->defaults()[$code] ?? null;
                if (is_array($default)) {
                    $definitions->put($code, $default);
                }
            }
        }

        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = $definitions
            ->sortBy(fn (array $definition): int => array_search($definition['code'], $allowedCodes, true))
            ->all();

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, float|int>  $summary
     */
    private function computeMetricValue(array $definition, array $summary, string $fallbackCode): float
    {
        $sourceMetric = (string) ($definition['source_metric'] ?? $fallbackCode);
        $primary = $this->metricValue($summary, $sourceMetric, $fallbackCode);
        $secondaryMetric = $definition['secondary_metric'] ?? null;
        $tertiaryMetric = $definition['tertiary_metric'] ?? null;
        $secondary = $secondaryMetric !== null ? $this->metricValue($summary, (string) $secondaryMetric, $fallbackCode) : null;
        $tertiary = $tertiaryMetric !== null ? $this->metricValue($summary, (string) $tertiaryMetric, $fallbackCode) : null;
        $formulaMode = (string) ($definition['formula_mode'] ?? 'direct');

        $value = match ($formulaMode) {
            'inverse' => 100 - $primary,
            'weighted_average' => $this->weightedAverageValue($primary, $secondary, $tertiary, $definition),
            'gap_to_target' => $this->gapToTargetValue($primary, $secondary, $definition),
            'minimum' => min($this->participatingValues($primary, $secondary, $tertiary)),
            'maximum' => max($this->participatingValues($primary, $secondary, $tertiary)),
            default => $primary,
        };

        $value += (float) ($definition['adjustment'] ?? 0);

        return round(max(0.0, min(100.0, $value)), 2);
    }

    /**
     * @param  array<string, float|int>  $summary
     */
    private function metricValue(array $summary, string $metric, string $fallbackCode): float
    {
        return (float) ($summary[$metric] ?? $summary[$fallbackCode] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function weightedAverageValue(float $primary, ?float $secondary, ?float $tertiary, array $definition): float
    {
        $secondaryWeight = max(0, min(100, (int) ($definition['secondary_weight'] ?? 0)));
        $tertiaryWeight = max(0, min(100, (int) ($definition['tertiary_weight'] ?? 0)));
        $primaryWeight = max(0, 100 - $secondaryWeight - $tertiaryWeight);

        $total = $primary * $primaryWeight;
        $weightTotal = $primaryWeight;

        if ($secondary !== null && $secondaryWeight > 0) {
            $total += $secondary * $secondaryWeight;
            $weightTotal += $secondaryWeight;
        }

        if ($tertiary !== null && $tertiaryWeight > 0) {
            $total += $tertiary * $tertiaryWeight;
            $weightTotal += $tertiaryWeight;
        }

        if ($weightTotal <= 0) {
            return $primary;
        }

        return $total / $weightTotal;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function gapToTargetValue(float $primary, ?float $secondary, array $definition): float
    {
        $target = $definition['target_value'] ?? null;
        $target = $target !== null
            ? (float) $target
            : ($secondary ?? 100.0);

        return 100 - abs($primary - $target);
    }

    /**
     * @return list<float>
     */
    private function participatingValues(float $primary, ?float $secondary, ?float $tertiary): array
    {
        return array_values(array_filter([$primary, $secondary, $tertiary], static fn ($value): bool => $value !== null));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function formulaSummary(array $definition): string
    {
        $sourceLabel = $this->sourceMetricOptions()[(string) ($definition['source_metric'] ?? '')] ?? 'Source';
        $secondaryMetric = $definition['secondary_metric'] ?? null;
        $tertiaryMetric = $definition['tertiary_metric'] ?? null;
        $secondaryLabel = $secondaryMetric !== null
            ? ($this->sourceMetricOptions()[(string) $secondaryMetric] ?? (string) $secondaryMetric)
            : null;
        $tertiaryLabel = $tertiaryMetric !== null
            ? ($this->sourceMetricOptions()[(string) $tertiaryMetric] ?? (string) $tertiaryMetric)
            : null;

        return match ((string) ($definition['formula_mode'] ?? 'direct')) {
            'inverse' => 'Score inverse sur '.$sourceLabel,
            'weighted_average' => collect([
                $sourceLabel.' '.max(0, 100 - (int) ($definition['secondary_weight'] ?? 0) - (int) ($definition['tertiary_weight'] ?? 0)).'%',
                $secondaryLabel !== null && (int) ($definition['secondary_weight'] ?? 0) > 0 ? $secondaryLabel.' '.(int) ($definition['secondary_weight'] ?? 0).'%' : null,
                $tertiaryLabel !== null && (int) ($definition['tertiary_weight'] ?? 0) > 0 ? $tertiaryLabel.' '.(int) ($definition['tertiary_weight'] ?? 0).'%' : null,
            ])->filter()->implode(' | '),
            'gap_to_target' => 'Cible '.($definition['target_value'] !== null
                ? number_format((float) $definition['target_value'], 0)
                : ($secondaryLabel ?? '100')),
            'minimum' => collect([$sourceLabel, $secondaryLabel, $tertiaryLabel])->filter()->implode(' / '),
            'maximum' => collect([$sourceLabel, $secondaryLabel, $tertiaryLabel])->filter()->implode(' / '),
            default => 'Lecture directe sur '.$sourceLabel,
        };
    }

    private function hasSettingsTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}



