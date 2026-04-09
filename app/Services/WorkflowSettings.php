<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class WorkflowSettings
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
                ->where('group', 'workflow')
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
            'actions_service_validation_enabled' => '1',
            'actions_direction_validation_enabled' => '1',
            'actions_rejection_comment_required' => '1',
            'pas_workflow_mode' => 'full',
            'pao_workflow_mode' => 'full',
            'pta_workflow_mode' => 'full',
        ];
    }

    public function serviceValidationEnabled(): bool
    {
        return $this->get('actions_service_validation_enabled', '1') === '1';
    }

    public function directionValidationEnabled(): bool
    {
        return $this->get('actions_direction_validation_enabled', '1') === '1';
    }

    public function rejectionCommentRequired(): bool
    {
        return $this->get('actions_rejection_comment_required', '1') === '1';
    }

    /**
     * @return array<string, string>
     */
    public function planningWorkflowModes(): array
    {
        return [
            'full' => 'Soumission -> Validation -> Verrouillage',
            'approval_only' => 'Soumission -> Validation',
            'direct_approval' => 'Validation directe',
        ];
    }

    public function planningWorkflowMode(string $module): string
    {
        $mode = (string) $this->get($module.'_workflow_mode', 'full');

        return array_key_exists($mode, $this->planningWorkflowModes()) ? $mode : 'full';
    }

    /**
     * @return array<string, mixed>
     */
    public function planningWorkflowSummary(string $module): array
    {
        $mode = $this->planningWorkflowMode($module);

        return [
            'module' => $module,
            'mode' => $mode,
            'mode_label' => $this->planningWorkflowModes()[$mode],
            'submit_enabled' => true,
            'approve_enabled' => in_array($mode, ['full', 'approval_only'], true),
            'lock_enabled' => $mode === 'full',
            'submit_target_status' => $mode === 'direct_approval' ? 'valide' : 'soumis',
            'reopen_allowed_statuses' => $mode === 'direct_approval'
                ? ['valide']
                : ['soumis', 'valide'],
            'status_options_global' => match ($mode) {
                'approval_only' => ['brouillon', 'soumis', 'valide'],
                'direct_approval' => ['brouillon', 'valide'],
                default => ['brouillon', 'soumis', 'valide', 'verrouille'],
            },
            'status_options_writer' => match ($mode) {
                'direct_approval' => ['brouillon', 'valide'],
                default => ['brouillon', 'soumis'],
            },
            'chain_label' => match ($mode) {
                'approval_only' => 'Brouillon -> Soumis -> Valide',
                'direct_approval' => 'Brouillon -> Valide',
                default => 'Brouillon -> Soumis -> Valide -> Verrouille',
            },
            'submit_button_label' => match ($mode) {
                'direct_approval' => 'Valider directement',
                default => 'Soumettre pour validation',
            },
            'submit_success_text' => match ($mode) {
                'direct_approval' => 'Transition appliquee directement au statut valide.',
                default => 'Element soumis pour validation.',
            },
            'approve_success_text' => 'Transition appliquee au statut valide.',
            'lock_success_text' => 'Transition appliquee au statut verrouille.',
            'final_statistics_hint' => match ($mode) {
                'direct_approval', 'approval_only' => 'Le statut valide est la derniere etape du circuit.',
                default => 'Le statut verrouille fige definitivement le plan valide.',
            },
        ];
    }

    public function actionSubmissionTarget(): string
    {
        if ($this->serviceValidationEnabled()) {
            return 'service';
        }

        if ($this->directionValidationEnabled()) {
            return 'direction';
        }

        return 'final';
    }

    public function actionFinalStage(): string
    {
        if ($this->directionValidationEnabled()) {
            return 'direction';
        }

        if ($this->serviceValidationEnabled()) {
            return 'service';
        }

        return 'direct';
    }

    /**
     * @return array<string, mixed>
     */
    public function actionValidationSummary(): array
    {
        $submissionTarget = $this->actionSubmissionTarget();
        $finalStage = $this->actionFinalStage();

        return [
            'service_enabled' => $this->serviceValidationEnabled(),
            'direction_enabled' => $this->directionValidationEnabled(),
            'rejection_comment_required' => $this->rejectionCommentRequired(),
            'submission_target' => $submissionTarget,
            'final_stage' => $finalStage,
            'chain_label' => match ($submissionTarget) {
                'service' => $this->directionValidationEnabled()
                    ? 'Agent -> Chef de service -> Direction'
                    : 'Agent -> Chef de service',
                'direction' => 'Agent -> Direction',
                default => 'Agent -> cloture directe',
            },
            'submission_help_text' => match ($submissionTarget) {
                'service' => $this->directionValidationEnabled()
                    ? 'L action est d abord revue par le chef de service, puis par la direction.'
                    : 'L action est revue uniquement par le chef de service, qui cloture le circuit.',
                'direction' => 'Le niveau service est desactive. La soumission est adressee directement a la direction.',
                default => 'Le circuit hierarchique est desactive. La cloture rend l action finale immediatement.',
            },
            'submission_button_label' => match ($submissionTarget) {
                'service' => 'Soumettre au chef de service',
                'direction' => 'Soumettre a la direction',
                default => 'Cloturer sans validation',
            },
            'service_review_button_label' => $this->directionValidationEnabled()
                ? 'Valider la revue chef'
                : 'Valider la cloture',
            'service_review_success_text' => $this->directionValidationEnabled()
                ? 'Action validee par le chef de service et transmise a la direction.'
                : 'Action validee par le chef de service. Elle est maintenant prise en compte dans les statistiques.',
            'final_statistics_hint' => match ($finalStage) {
                'direction' => 'Oui apres validation direction.',
                'service' => 'Oui apres validation finale du chef de service.',
                default => 'Oui des la cloture de l action.',
            },
        ];
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updateActionWorkflow(array $payload, ?User $actor = null): array
    {
        foreach ([
            'actions_service_validation_enabled',
            'actions_direction_validation_enabled',
            'actions_rejection_comment_required',
        ] as $key) {
            $defaultValue = $this->defaults()[$key];
            $value = ($payload[$key] ?? $defaultValue) === '1' ? '1' : '0';

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'workflow', 'key' => $key],
                ['value' => $value, 'updated_by' => $actor?->id]
            );
        }

        $this->flush();

        return $this->all();
    }

    /**
     * @param  array<string, string|null>  $payload
     * @return array<string, string>
     */
    public function updatePlanningWorkflow(array $payload, ?User $actor = null): array
    {
        $allowedModes = array_keys($this->planningWorkflowModes());

        foreach (['pas', 'pao', 'pta'] as $module) {
            $key = $module.'_workflow_mode';
            $value = (string) ($payload[$key] ?? $this->defaults()[$key]);
            if (! in_array($value, $allowedModes, true)) {
                $value = $this->defaults()[$key];
            }

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'workflow', 'key' => $key],
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
            return $this->tableAvailable = Schema::hasTable('platform_settings');
        } catch (\Throwable) {
            return $this->tableAvailable = false;
        }
    }
}



