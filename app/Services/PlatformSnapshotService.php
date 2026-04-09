<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\PlatformSettingSnapshot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PlatformSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function compareSnapshots(PlatformSettingSnapshot $left, PlatformSettingSnapshot $right): array
    {
        $leftMap = collect($left->payload['settings'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['key']))
            ->mapWithKeys(fn (array $row): array => [
                (string) $row['key'] => [
                    'group' => (string) ($row['group'] ?? 'general'),
                    'value' => isset($row['value']) ? (string) $row['value'] : null,
                ],
            ]);

        $rightMap = collect($right->payload['settings'] ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['key']))
            ->mapWithKeys(fn (array $row): array => [
                (string) $row['key'] => [
                    'group' => (string) ($row['group'] ?? 'general'),
                    'value' => isset($row['value']) ? (string) $row['value'] : null,
                ],
            ]);

        $allKeys = $leftMap->keys()->merge($rightMap->keys())->unique()->sort()->values();

        $changes = $allKeys->map(function (string $key) use ($leftMap, $rightMap): array {
            $leftRow = $leftMap->get($key);
            $rightRow = $rightMap->get($key);
            $leftValue = $leftRow['value'] ?? null;
            $rightValue = $rightRow['value'] ?? null;

            return [
                'key' => $key,
                'group' => (string) ($leftRow['group'] ?? $rightRow['group'] ?? 'general'),
                'left_value' => $leftValue,
                'right_value' => $rightValue,
                'status' => match (true) {
                    $leftRow === null => 'added',
                    $rightRow === null => 'removed',
                    $leftValue !== $rightValue => 'changed',
                    default => 'same',
                },
            ];
        });

        return [
            'left' => $left,
            'right' => $right,
            'summary' => [
                'same' => $changes->where('status', 'same')->count(),
                'changed' => $changes->where('status', 'changed')->count(),
                'added' => $changes->where('status', 'added')->count(),
                'removed' => $changes->where('status', 'removed')->count(),
            ],
            'changes' => $changes->where('status', '!=', 'same')->values()->all(),
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function currentPayload(): array
    {
        return [
            'settings' => PlatformSetting::query()
                ->orderBy('group')
                ->orderBy('key')
                ->get(['group', 'key', 'value'])
                ->map(fn (PlatformSetting $setting): array => [
                    'group' => (string) $setting->group,
                    'key' => (string) $setting->key,
                    'value' => $setting->value,
                ])
                ->all(),
        ];
    }

    public function createSnapshot(string $label, ?string $description = null, ?User $actor = null): PlatformSettingSnapshot
    {
        return PlatformSettingSnapshot::query()->create([
            'label' => $label,
            'description' => $description,
            'payload' => $this->currentPayload(),
            'created_by' => $actor?->id,
        ]);
    }

    public function restoreSnapshot(PlatformSettingSnapshot $snapshot, ?User $actor = null): PlatformSettingSnapshot
    {
        return $this->restoreSnapshotGroups($snapshot, $this->groupsForSnapshot($snapshot), $actor);
    }

    /**
     * @param  array<int, string>  $groups
     */
    public function restoreSnapshotGroups(PlatformSettingSnapshot $snapshot, array $groups, ?User $actor = null): PlatformSettingSnapshot
    {
        $selectedGroups = collect($groups)
            ->map(fn ($group): string => trim((string) $group))
            ->filter()
            ->unique()
            ->values();

        $rows = collect(is_array($snapshot->payload['settings'] ?? null) ? $snapshot->payload['settings'] : [])
            ->filter(function ($row) use ($selectedGroups): bool {
                if (! is_array($row)) {
                    return false;
                }

                return $selectedGroups->contains((string) ($row['group'] ?? 'general'));
            })
            ->values();

        DB::transaction(function () use ($rows, $selectedGroups, $actor): void {
            foreach ($selectedGroups as $group) {
                $groupRows = $rows
                    ->filter(fn ($row): bool => (string) ($row['group'] ?? 'general') === $group)
                    ->values();

                $keys = $groupRows
                    ->pluck('key')
                    ->map(fn ($key): string => (string) $key)
                    ->filter()
                    ->values()
                    ->all();

                $deleteQuery = PlatformSetting::query()->where('group', $group);
                if ($keys !== []) {
                    $deleteQuery->whereNotIn('key', $keys);
                }
                $deleteQuery->delete();

                foreach ($groupRows as $row) {
                    PlatformSetting::query()->updateOrCreate(
                        [
                            'group' => (string) ($row['group'] ?? 'general'),
                            'key' => (string) ($row['key'] ?? ''),
                        ],
                        [
                            'value' => isset($row['value']) ? (string) $row['value'] : null,
                            'updated_by' => $actor?->id,
                        ]
                    );
                }
            }
        });

        $snapshot->forceFill([
            'restored_by' => $actor?->id,
            'last_restored_at' => now(),
        ])->save();

        $this->flushSettings();

        return $snapshot->fresh() ?? $snapshot;
    }

    /**
     * @return array<int, string>
     */
    public function groupsForSnapshot(PlatformSettingSnapshot $snapshot): array
    {
        return collect(is_array($snapshot->payload['settings'] ?? null) ? $snapshot->payload['settings'] : [])
            ->filter(fn ($row): bool => is_array($row))
            ->map(fn (array $row): string => (string) ($row['group'] ?? 'general'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function flushSettings(): void
    {
        foreach ([
            PlatformSettings::class,
            AppearanceSettings::class,
            WorkspaceModuleSettings::class,
            WorkflowSettings::class,
            ActionCalculationSettings::class,
            ActionManagementSettings::class,
            RolePermissionSettings::class,
            DynamicReferentialSettings::class,
            ManagedKpiSettings::class,
            NotificationPolicySettings::class,
            DashboardProfileSettings::class,
            RoleRegistryService::class,
        ] as $serviceClass) {
            if (! app()->bound($serviceClass)) {
                continue;
            }

            $service = app($serviceClass);
            if (method_exists($service, 'flush')) {
                $service->flush();
            }
        }
    }
}
