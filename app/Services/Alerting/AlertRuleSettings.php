<?php

namespace App\Services\Alerting;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\SchemaIntrospectionCache;

class AlertRuleSettings
{
    /**
     * @var array<string, int|float>|null
     */
    private ?array $resolved = null;

    private ?bool $tableAvailable = null;

    /**
     * @return array{overdue_critical_days:int,pending_setup_warning_days:int,kpi_critical_ratio:float,delegation_warning_days:int}
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            /** @var array{overdue_critical_days:int,pending_setup_warning_days:int,kpi_critical_ratio:float,delegation_warning_days:int} $resolved */
            $resolved = $this->resolved;

            return $resolved;
        }

        $settings = $this->defaults();

        if ($this->hasSettingsTable()) {
            $stored = PlatformSetting::query()
                ->where('group', 'alert_rules')
                ->pluck('value', 'key')
                ->all();

            foreach (array_keys($settings) as $key) {
                if (! array_key_exists($key, $stored)) {
                    continue;
                }

                $decoded = json_decode((string) $stored[$key], true);
                $settings[$key] = is_numeric($decoded) ? $decoded : $stored[$key];
            }
        }

        return $this->resolved = $this->sanitize($settings);
    }

    /**
     * @return array{overdue_critical_days:int,pending_setup_warning_days:int,kpi_critical_ratio:float,delegation_warning_days:int}
     */
    public function defaults(): array
    {
        return [
            'overdue_critical_days' => 7,
            'pending_setup_warning_days' => 7,
            'kpi_critical_ratio' => 0.8,
            'delegation_warning_days' => 1,
        ];
    }

    public function overdueCriticalDays(): int
    {
        return (int) $this->all()['overdue_critical_days'];
    }

    public function pendingSetupWarningDays(): int
    {
        return (int) $this->all()['pending_setup_warning_days'];
    }

    public function kpiCriticalRatio(): float
    {
        return (float) $this->all()['kpi_critical_ratio'];
    }

    public function delegationWarningDays(): int
    {
        return (int) $this->all()['delegation_warning_days'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{overdue_critical_days:int,pending_setup_warning_days:int,kpi_critical_ratio:float,delegation_warning_days:int}
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $settings = $this->sanitize($payload);

        foreach ($settings as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => 'alert_rules', 'key' => $key],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'updated_by' => $actor?->id,
                ]
            );
        }

        $this->flush();

        return $this->all();
    }

    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{overdue_critical_days:int,pending_setup_warning_days:int,kpi_critical_ratio:float,delegation_warning_days:int}
     */
    private function sanitize(array $settings): array
    {
        $defaults = $this->defaults();

        return [
            'overdue_critical_days' => max(1, min(365, (int) ($settings['overdue_critical_days'] ?? $defaults['overdue_critical_days']))),
            'pending_setup_warning_days' => max(0, min(365, (int) ($settings['pending_setup_warning_days'] ?? $defaults['pending_setup_warning_days']))),
            'kpi_critical_ratio' => max(0.01, min(1.0, (float) ($settings['kpi_critical_ratio'] ?? $defaults['kpi_critical_ratio']))),
            'delegation_warning_days' => max(0, min(365, (int) ($settings['delegation_warning_days'] ?? $defaults['delegation_warning_days']))),
        ];
    }

    private function hasSettingsTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            return $this->tableAvailable = SchemaIntrospectionCache::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}
