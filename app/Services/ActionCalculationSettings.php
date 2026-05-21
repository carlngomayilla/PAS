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

    // A10 — Politique par defaut : exclut les actions rejetees du calcul des
    // KPI consolides. Les rejetees restent visibles dans le workflow mais ne
    // gonflent plus les statistiques officielles. Le super-admin peut basculer
    // sur STATISTICAL_SCOPE_ALL_VISIBLE pour retrouver l ancien comportement.
    public const SCOPE_EXCLUDE_REJECTED = 'exclude_rejected';

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
            // A10 — Defaut institutionnel : exclure les actions rejetees.
            self::SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS => self::SCOPE_EXCLUDE_REJECTED,
            self::SETTING_ACTIONS_STATISTICAL_SCOPE => self::SCOPE_EXCLUDE_REJECTED,
        ];
    }

    public function statisticalScope(): string
    {
        $value = (string) ($this->get(self::SETTING_ACTIONS_STATISTICAL_SCOPE, self::SCOPE_EXCLUDE_REJECTED) ?? self::SCOPE_EXCLUDE_REJECTED);

        return array_key_exists($value, $this->statisticalScopeOptions())
            ? $value
            : self::SCOPE_EXCLUDE_REJECTED;
    }

    /**
     * @return array<string, string>
     */
    public function statisticalScopeOptions(): array
    {
        return [
            self::SCOPE_EXCLUDE_REJECTED => 'Toutes les actions visibles, sauf les rejetées (recommandé)',
            self::STATISTICAL_SCOPE_ALL_VISIBLE => 'Toutes les actions visibles (y compris rejetées)',
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
        return match ($status) {
            self::OFFICIAL_SCOPE_ALL_VISIBLE => [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            // A10 — Statuts comptes dans les KPI quand on exclut les rejetees.
            self::SCOPE_EXCLUDE_REJECTED => [
                ActionTrackingService::VALIDATION_NON_SOUMISE,
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ],
            ActionTrackingService::VALIDATION_SOUMISE_CHEF => [
                ActionTrackingService::VALIDATION_SOUMISE_CHEF,
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ],
            ActionTrackingService::VALIDATION_VALIDEE_CHEF => [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ],
            default => [
                ActionTrackingService::VALIDATION_VALIDEE_CHEF,
            ],
        };
    }

    /**
     * Statuts de validation que la politique courante REJETTE explicitement
     * des agregats (KPI consolides, reporting officiel).
     *
     * @return list<string>
     */
    public function rejectedValidationStatuses(): array
    {
        return match ($this->statisticalScope()) {
            self::SCOPE_EXCLUDE_REJECTED => [
                ActionTrackingService::VALIDATION_REJETEE_CHEF,
                ActionTrackingService::VALIDATION_REJETEE_DIRECTION,
            ],
            default => [],
        };
    }

    public function statisticalScopeLabel(): string
    {
        return $this->statisticalScopeOptions()[$this->statisticalScope()]
            ?? 'Toutes les actions visibles';
    }

    public function statisticalScopeSummary(): string
    {
        return $this->statisticalScope() === self::SCOPE_EXCLUDE_REJECTED
            ? 'Les statistiques et les KPI excluent les actions rejetees (par le chef ou la direction). Les autres validations restent comptees.'
            : 'Les statistiques et les KPI sont calcules sur toutes les actions visibles. Les validations chef et direction restent purement workflow.';
    }

    public function statisticalAverageSummary(): string
    {
        return $this->statisticalScope() === self::SCOPE_EXCLUDE_REJECTED
            ? 'Moyenne calculee sur toutes les actions visibles, hors actions rejetees.'
            : 'Moyenne calculee sur toutes les actions visibles.';
    }

    /**
     * @return array<string, string>
     */
    public function statisticalRouteFilters(): array
    {
        return [];
    }

    public function applyStatisticalScope(Builder $query, string $column = 'statut_validation'): void
    {
        // A10 — Filtre SQL pour exclure les statuts rejetes des agregats
        // officiels. Si la politique active autorise tous les statuts
        // (OFFICIAL_SCOPE_ALL_VISIBLE), aucun filtre n est applique.
        $rejected = $this->rejectedValidationStatuses();
        if ($rejected === []) {
            return;
        }

        $query->whereNotIn($column, $rejected);
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return Collection<int, mixed>
     */
    public function filterStatistical(Collection $items, string $field = 'statut_validation'): Collection
    {
        // A10 — Pendant in-memory du filtre SQL : exclut les actions rejetees
        // des collections agregees cote PHP. Aucun filtre si la politique active
        // autorise tous les statuts.
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
        $status = (string) ($payload[self::SETTING_ACTIONS_STATISTICAL_SCOPE]
            ?? $payload[self::SETTING_ACTIONS_OFFICIAL_VALIDATION_STATUS]
            ?? self::SCOPE_EXCLUDE_REJECTED);

        if (! array_key_exists($status, $this->statisticalScopeOptions())) {
            $status = self::SCOPE_EXCLUDE_REJECTED;
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
}
