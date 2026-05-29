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
            'actions_direction_validation_enabled' => '0',
            'actions_rejection_comment_required' => '1',
            'pas_workflow_mode' => 'canonical',
            'pao_workflow_mode' => 'canonical',
            'pta_workflow_mode' => 'canonical',
        ];
    }

    public function serviceValidationEnabled(): bool
    {
        return true;
    }

    public function directionValidationEnabled(): bool
    {
        return false;
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
            'canonical' => 'Cycle metier canonique PAS ANBG',
        ];
    }

    public function planningWorkflowMode(string $module): string
    {
        return 'canonical';
    }

    /**
     * @return array<string, mixed>
     */
    public function planningWorkflowSummary(string $module): array
    {
        $statusOptions = match ($module) {
            'pas' => ['actif', 'cloture', 'archive'],
            'pao' => ['en_cours', 'valide', 'cloture', 'archive'],
            'pta' => ['en_cours', 'cloture', 'archive'],
            default => [],
        };

        return [
            'module' => $module,
            'mode' => 'canonical',
            'mode_label' => $this->planningWorkflowModes()['canonical'],
            'submit_enabled' => false,
            'approve_enabled' => false,
            'lock_enabled' => false,
            'submit_target_status' => null,
            'reopen_allowed_statuses' => [],
            'status_options_global' => $statusOptions,
            'status_options_writer' => $statusOptions,
            'chain_label' => match ($module) {
                'pas' => 'Actif -> Cloture -> Archive',
                'pao' => 'En cours -> Valide automatiquement -> Cloture -> Archive',
                'pta' => 'En cours -> Cloture -> Archive',
                default => 'Cycle canonique',
            },
            'submit_button_label' => 'Ancien circuit supprime',
            'submit_success_text' => 'Ancien circuit supprime.',
            'approve_success_text' => 'Ancienne validation supprimee.',
            'lock_success_text' => 'Ancien verrouillage supprime.',
            'final_statistics_hint' => match ($module) {
                'pao' => 'Le PAO est valide automatiquement quand ses champs obligatoires sont complets.',
                'pta' => 'Le PTA ne possede pas de statut valide.',
                default => 'Le PAS est deja valide officiellement avant saisie.',
            },
        ];
    }

    public function actionSubmissionTarget(): string
    {
        return 'service';
    }

    public function actionFinalStage(): string
    {
        return 'service';
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
            'direction_enabled' => false,
            'rejection_comment_required' => $this->rejectionCommentRequired(),
            'submission_target' => $submissionTarget,
            'final_stage' => $finalStage,
            'chain_label' => 'Agent -> Chef de service',
            'submission_help_text' => 'L action est envoyee au chef de service pour validation finale.',
            'submission_button_label' => 'Soumettre',
            'service_review_button_label' => 'Valider la cloture',
            'service_review_success_text' => 'Action validee par le chef de service. Le directeur et l agent sont notifies.',
            'final_statistics_hint' => 'Oui apres validation finale du chef de service.',
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
            if ($key === 'actions_direction_validation_enabled') {
                $value = '0';
            } elseif ($key === 'actions_service_validation_enabled') {
                $value = '1';
            }

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
        foreach (['pas', 'pao', 'pta'] as $module) {
            $key = $module.'_workflow_mode';

            PlatformSetting::query()->updateOrCreate(
                ['group' => 'workflow', 'key' => $key],
                ['value' => 'canonical', 'updated_by' => $actor?->id]
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
