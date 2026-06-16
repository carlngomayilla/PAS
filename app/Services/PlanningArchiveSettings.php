<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class PlanningArchiveSettings
{
    private const GROUP = 'planning_archive';

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
                ->where('group', self::GROUP)
                ->pluck('value', 'key')
                ->map(fn ($value): string => (string) $value)
                ->all();

            $settings = array_merge($settings, $stored);
        }

        return $this->resolved = $settings;
    }

    /**
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            'planning_auto_archive_enabled' => '1',
            'planning_pao_archive_after_days' => '30',
            'planning_pta_archive_after_days' => '30',
        ];
    }

    public function enabled(): bool
    {
        return $this->all()['planning_auto_archive_enabled'] === '1';
    }

    public function paoArchiveAfterDays(): int
    {
        return max(1, min(3650, (int) $this->all()['planning_pao_archive_after_days']));
    }

    public function ptaArchiveAfterDays(): int
    {
        return max(1, min(3650, (int) $this->all()['planning_pta_archive_after_days']));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled(),
            'pao_archive_after_days' => $this->paoArchiveAfterDays(),
            'pta_archive_after_days' => $this->ptaArchiveAfterDays(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        $values = [
            'planning_auto_archive_enabled' => ($payload['planning_auto_archive_enabled'] ?? '0') === '1' ? '1' : '0',
            'planning_pao_archive_after_days' => (string) max(1, min(3650, (int) ($payload['planning_pao_archive_after_days'] ?? 30))),
            'planning_pta_archive_after_days' => (string) max(1, min(3650, (int) ($payload['planning_pta_archive_after_days'] ?? 30))),
        ];

        foreach ($values as $key => $value) {
            PlatformSetting::query()->updateOrCreate(
                ['group' => self::GROUP, 'key' => $key],
                ['value' => $value, 'updated_by' => $actor?->id]
            );
        }

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
            return $this->tableAvailable = \App\Support\SchemaIntrospectionCache::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}
