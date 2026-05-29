<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ActionCalculationSettings
{
    public const OFFICIAL_SCOPE_ALL_VISIBLE = 'all_visible';
    public const STATISTICAL_SCOPE_ALL_VISIBLE = self::OFFICIAL_SCOPE_ALL_VISIBLE;
    public const SCOPE_EXCLUDE_REJECTED = 'exclude_rejected';

    public const LEVEL_VALIDATION_AGENT = 'validation_agent';
    public const LEVEL_VALIDATION_CHEF = 'validation_chef';
    public const LEVEL_VALIDATION_DIRECTION = 'validation_direction';
    public const LEVEL_VALIDATION_SCIQ = 'validation_sciq';
    public const LEVEL_VALIDATION_DG = 'validation_dg';

    public const SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS = 'actions_official_validation_status';
    public const SETTING_ACTIONS_STATISTICAL_SCOPE = 'actions_statistical_scope';

    /**
     * @var array<string, string>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'action_calculation')
                ->pluck('value', 'key')
                ->map(fn ($value): string => (string) $value)
                ->all();

            $settings = array_merge($settings, $stored);
        }

        return $this->resolved = $settings;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            self::SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS => self::LEVEL_VALIDATION_CHEF,
            self::SETTING_ACTIONS_STATISTICAL_SCOPE => self::LEVEL_VALIDATION_CHEF,
        ];
    }

    public function statisticalScope(): string
    {
        $value = $this->normalizeScope((string) ($this->get(
            self::SETTING_ACTIONS_STATISTICAL_SCOPE,
            self::LEVEL_VALIDATION_CHEF
        ) ?? self::LEVEL_VALIDATION_CHEF));

        return array_key_exists($value, $this->statisticalScopeOptions())
            ? $value
            : self::LEVEL_VALIDATION_CHEF;
    }

    /**
     * @return array<string, string>
     */
    public function statisticalScopeOptions(): array
    {
        return [
            self::LEVEL_VALIDATION_CHEF => 'Validation chef de service',
            self::LEVEL_VALIDATION_DIRECTION => 'Ancienne validation direction',
            self::LEVEL_VALIDATION_AGENT => 'Soumission agent ou validation chef',
            self::LEVEL_VALIDATION_SCIQ => 'Validation SCIQ ancienne',
            self::LEVEL_VALIDATION_DG => 'Validation DG ancienne',
            self::SCOPE_EXCLUDE_REJECTED => 'Ancienne règle : visibles hors rejetées',
            self::STATISTICAL_SCOPE_ALL_VISIBLE => 'Toutes les actions visibles',
        ];
    }

    /**
     * @return list<string>
     */
    public function statisticalValidationStatuses(): array
    {
        return $this->validationStatusesFrom($this->statisticalScope());
    }

    /**
     * @return list<string>
     */
    public function validationStatusesFrom(string $status): array
    {
        return match ($this->normalizeScope($status)) {
            self::OFFICIAL_SCOPE_ALL_VISIBLE => [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            self::SCOPE_EXCLUDE_REJECTED => [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            self::LEVEL_VALIDATION_AGENT => [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            self::LEVEL_VALIDATION_CHEF => [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            self::LEVEL_VALIDATION_DIRECTION,
            self::LEVEL_VALIDATION_SCIQ,
            self::LEVEL_VALIDATION_DG => [
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            default => [
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function rejectedValidationStatuses(): array
    {
        return match ($this->statisticalScope()) {
            self::SCOPE_EXCLUDE_REJECTED => [
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_CORRECTION_DEMANDEE,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ],
            default => [],
        };
    }

    public function statisticalScopeLabel(): string
    {
        return $this->statisticalScopeOptions()[$this->statisticalScope()]
            ?? 'Validation chef de service';
    }

    public function statisticalScopeSummary(): string
    {
        return match ($this->statisticalScope()) {
            self::LEVEL_VALIDATION_DIRECTION,
            self::LEVEL_VALIDATION_SCIQ,
            self::LEVEL_VALIDATION_DG => 'Ancienne règle : seules les actions validées par la direction sont comptées.',
            self::LEVEL_VALIDATION_CHEF => 'Les statistiques officielles comptent les actions validées par le chef de service.',
            self::LEVEL_VALIDATION_AGENT => 'Les statistiques officielles comptent les actions soumises par les agents et les actions déjà validées.',
            self::SCOPE_EXCLUDE_REJECTED => 'Ancienne règle : les statistiques excluent les actions rejetées ou en correction.',
            default => 'Les statistiques et les indicateurs sont calculés sur toutes les actions visibles.',
        };
    }

    public function statisticalAverageSummary(): string
    {
        return match ($this->statisticalScope()) {
            self::LEVEL_VALIDATION_DIRECTION,
            self::LEVEL_VALIDATION_SCIQ,
            self::LEVEL_VALIDATION_DG => 'Moyenne calculée sur les anciennes validations direction.',
            self::LEVEL_VALIDATION_CHEF => 'Moyenne calculée sur les actions validées par le chef de service.',
            self::LEVEL_VALIDATION_AGENT => 'Moyenne calculée sur les actions soumises ou déjà validées.',
            self::SCOPE_EXCLUDE_REJECTED => 'Moyenne calculée sur toutes les actions visibles, hors actions rejetées ou en correction.',
            default => 'Moyenne calculée sur toutes les actions visibles.',
        };
    }

    /**
     * @return array<string, string>
     */
    public function statisticalRouteFilters(): array
    {
        return match ($this->statisticalScope()) {
            self::LEVEL_VALIDATION_DIRECTION,
            self::LEVEL_VALIDATION_SCIQ,
            self::LEVEL_VALIDATION_DG => ['statut_validation' => ActionTrackingService::VALIDATION_VALIDEE_DIRECTION],
            self::LEVEL_VALIDATION_CHEF => ['statut_validation_min' => ActionTrackingService::VALIDATION_VALIDEE_CHEF],
            self::LEVEL_VALIDATION_AGENT => ['statut_validation_min' => ActionTrackingService::VALIDATION_SOUMISE_CHEF],
            default => [],
        };
    }

    public function applyStatisticalScope(Builder $query, string $column = 'statut_validation'): void
    {
        $allowed = $this->statisticalValidationStatuses();
        if ($allowed !== []) {
            $query->whereIn($column, $allowed);
            return;
        }

        $rejected = $this->rejectedValidationStatuses();
        if ($rejected !== []) {
            $query->whereNotIn($column, $rejected);
        }
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function filterStatistical(Collection $items, string $field = 'statut_validation'): Collection
    {
        $allowed = $this->statisticalValidationStatuses();
        if ($allowed !== []) {
            return $items
                ->filter(fn ($item): bool => in_array((string) data_get($item, $field, ''), $allowed, true))
                ->values();
        }

        $rejected = $this->rejectedValidationStatuses();
        if ($rejected === []) {
            return $items->values();
        }

        return $items
            ->reject(fn ($item): bool => in_array((string) data_get($item, $field, ''), $rejected, true))
            ->values();
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateStatisticalPolicy(array $payload, ?User $actor = null): array
    {
        $status = $this->normalizeScope((string) ($payload[self::SETTING_ACTIONS_STATISTICAL_SCOPE]
            ?? $payload[self::SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS]
            ?? self::LEVEL_VALIDATION_CHEF));

        if (! array_key_exists($status, $this->statisticalScopeOptions())) {
            $status = self::LEVEL_VALIDATION_CHEF;
        }

        foreach ([self::SETTING_ACTIONS_STATISTICAL_SCOPE, self::SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS] as $key) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'action_calculation', 'key' => $key],
                ['value' => $status, 'updated_by' => $actor?->id]
            );
        }

        $this->flush();

        return $this->all();
    }

    public function officialValidationStatus(): string
    {
        return $this->statisticalScope();
    }

    /**
     * @return array<string, string>
     */
    public function officialValidationStatusOptions(): array
    {
        return $this->statisticalScopeOptions();
    }

    /**
     * @return list<string>
     */
    public function officialValidationStatuses(): array
    {
        return $this->statisticalValidationStatuses();
    }

    public function officialThresholdLabel(): string
    {
        return $this->statisticalScopeLabel();
    }

    public function officialScopeSummary(): string
    {
        return $this->statisticalScopeSummary();
    }

    public function officialAverageSummary(): string
    {
        return $this->statisticalAverageSummary();
    }

    /**
     * @return array<string, string>
     */
    public function officialRouteFilters(): array
    {
        return $this->statisticalRouteFilters();
    }

    public function applyOfficialScope(Builder $query, string $column = 'statut_validation'): void
    {
        $this->applyStatisticalScope($query, $column);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function filterOfficial(Collection $items, string $field = 'statut_validation'): Collection
    {
        return $this->filterStatistical($items, $field);
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateOfficialPolicy(array $payload, ?User $actor = null): array
    {
        return $this->updateStatisticalPolicy($payload, $actor);
    }

    public function flush(): void
    {
        $this->resolved = null;
        $this->tableAvailable = null;
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

    private function normalizeScope(string $scope): string
    {
        return match ($scope) {
            ActionTrackingService::VALIDATION_SOUMISE_CHEF => self::LEVEL_VALIDATION_AGENT,
            ActionTrackingService::VALIDATION_VALIDEE_CHEF => self::LEVEL_VALIDATION_CHEF,
            ActionTrackingService::VALIDATION_VALIDEE_DIRECTION => self::LEVEL_VALIDATION_DIRECTION,
            default => $scope,
        };
    }
}
