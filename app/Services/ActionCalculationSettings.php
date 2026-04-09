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
            'actions_official_validation_status' => self::OFFICIAL_SCOPE_ALL_VISIBLE,
        ];
    }

    public function officialValidationStatus(): string
    {
        return self::OFFICIAL_SCOPE_ALL_VISIBLE;
    }

    /**
     * @return array<string, string>
     */
    public function officialValidationStatusOptions(): array
    {
        return [
            self::OFFICIAL_SCOPE_ALL_VISIBLE => 'Toutes les actions visibles',
        ];
    }

    /**
     * @return list<string>
     */
    public function officialValidationStatuses(): array
    {
        return $this->validationStatusesFrom($this->officialValidationStatus());
    }

    /**
     * @return list<string>
     */
    public function validationStatusesFrom(string $status): array
    {
        return match ($status) {
            self::OFFICIAL_SCOPE_ALL_VISIBLE => [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            ActionTrackingService::VALIDATION_SOUMISE_CHEF => [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            ActionTrackingService::VALIDATION_VALIDEE_CHEF => [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            default => [
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
        };
    }

    public function officialThresholdLabel(): string
    {
        return $this->officialValidationStatusOptions()[$this->officialValidationStatus()]
            ?? 'Toutes les actions visibles';
    }

    public function officialScopeSummary(): string
    {
        return 'Les statistiques et les KPI sont calcules sur toutes les actions visibles. Les validations chef et direction restent purement workflow.';
    }

    public function officialAverageSummary(): string
    {
        return 'Moyenne calculee sur toutes les actions visibles.';
    }

    /**
     * @return array<string, string>
     */
    public function officialRouteFilters(): array
    {
        return [];
    }

    public function applyOfficialScope(Builder $query, string $column = 'statut_validation'): void
    {
        unset($query, $column);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function filterOfficial(Collection $items, string $field = 'statut_validation'): Collection
    {
        unset($field);

        return $items->values();
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateOfficialPolicy(array $payload, ?User $actor = null): array
    {
        $status = (string) ($payload['actions_official_validation_status'] ?? self::OFFICIAL_SCOPE_ALL_VISIBLE);
        if (! array_key_exists($status, $this->officialValidationStatusOptions())) {
            $status = self::OFFICIAL_SCOPE_ALL_VISIBLE;
        }

        PlatformSetting::query()->updateOrCreate(
            ['group' => 'action_calculation', 'key' => 'actions_official_validation_status'],
            ['value' => $status, 'updated_by' => $actor?->id]
        );

        $this->flush();

        return $this->all();
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
}



