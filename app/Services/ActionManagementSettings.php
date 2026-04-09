<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class ActionManagementSettings
{
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
                ->where('group', 'action_management')
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
            'actions_risk_plan_required' => '0',
            'actions_manual_suspend_enabled' => '1',
            'actions_auto_complete_when_target_reached' => '0',
            'actions_min_progress_for_closure' => '0',
            'actions_final_justificatif_required' => '0',
        ];
    }

    public function riskPlanRequired(): bool
    {
        return $this->get('actions_risk_plan_required', '0') === '1';
    }

    public function manualSuspendEnabled(): bool
    {
        return $this->get('actions_manual_suspend_enabled', '1') === '1';
    }

    public function autoCompleteWhenTargetReached(): bool
    {
        return $this->get('actions_auto_complete_when_target_reached', '0') === '1';
    }

    public function minProgressForClosure(): int
    {
        return max(0, min(100, (int) $this->get('actions_min_progress_for_closure', '0')));
    }

    public function finalJustificatifRequired(): bool
    {
        return $this->get('actions_final_justificatif_required', '0') === '1';
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'risk_plan_required' => $this->riskPlanRequired(),
            'manual_suspend_enabled' => $this->manualSuspendEnabled(),
            'auto_complete_when_target_reached' => $this->autoCompleteWhenTargetReached(),
            'min_progress_for_closure' => $this->minProgressForClosure(),
            'final_justificatif_required' => $this->finalJustificatifRequired(),
        ];
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function update(array $payload, ?User $actor = null): array
    {
        foreach ([
            'actions_risk_plan_required',
            'actions_manual_suspend_enabled',
            'actions_auto_complete_when_target_reached',
            'actions_final_justificatif_required',
        ] as $key) {
            $defaultValue = $this->defaults()[$key];
            $value = ($payload[$key] ?? $defaultValue) === '1' ? '1' : '0';

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'action_management', 'key' => $key],
                ['value' => $value, 'updated_by' => $actor?->id]
            );
        }

        $minProgress = max(0, min(100, (int) ($payload['actions_min_progress_for_closure'] ?? 0)));
        PlatformSetting::query()->updateOrCreate(
            ['group' => 'action_management', 'key' => 'actions_min_progress_for_closure'],
            ['value' => (string) $minProgress, 'updated_by' => $actor?->id]
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



