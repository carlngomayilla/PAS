<?php

namespace App\Services;

use App\Models\Action;
use App\Models\ExportTemplate;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use Illuminate\Support\Collection;

class PlatformSimulationService
{
    public function __construct(
        private readonly ActionCalculationSettings $actionCalculationSettings,
        private readonly ActionManagementSettings $actionManagementSettings,
        private readonly WorkflowSettings $workflowSettings,
        private readonly DashboardProfileSettings $dashboardProfileSettings,
        private readonly ManagedKpiSettings $managedKpiSettings
    ) {
    }

    /**
     * @param  array<string, string|int|bool|null>  $payload
     * @return array<string, mixed>
     */
    public function simulate(array $payload): array
    {
        $actions = Action::query()
            ->with('actionKpi:id,action_id,kpi_global')
            ->get();

        $currentThreshold = $this->actionCalculationSettings->statisticalScope();
        $simulatedThreshold = $currentThreshold;

        $currentStatistical = $this->filterByThreshold($actions, $currentThreshold);
        $simulatedStatistical = $currentStatistical;
        $currentSummary = $this->metricSummary($currentStatistical);
        $simulatedSummary = $this->metricSummary($simulatedStatistical);

        $currentClosureProgress = $this->actionManagementSettings->minProgressForClosure();
        $simulatedClosureProgress = max(0, min(100, (int) ($payload['actions_min_progress_for_closure'] ?? $currentClosureProgress)));
        $currentAutoComplete = $this->actionManagementSettings->autoCompleteWhenTargetReached();
        $simulatedAutoComplete = (string) ($payload['actions_auto_complete_when_target_reached'] ?? ($currentAutoComplete ? '1' : '0')) === '1';

        $currentServiceEnabled = $this->workflowSettings->serviceValidationEnabled();
        $currentDirectionEnabled = $this->workflowSettings->directionValidationEnabled();
        $simulatedServiceEnabled = (string) ($payload['actions_service_validation_enabled'] ?? ($currentServiceEnabled ? '1' : '0')) === '1';
        $simulatedDirectionEnabled = (string) ($payload['actions_direction_validation_enabled'] ?? ($currentDirectionEnabled ? '1' : '0')) === '1';

        $autoCompleteCandidates = $actions
            ->filter(function (Action $action): bool {
                return (float) ($action->progression_reelle ?? 0) >= 100
                    && $action->date_fin_reelle === null
                    && ! in_array((string) ($action->statut_dynamique ?? ''), [
                        ActionTrackingService::STATUS_SUSPENDU,
                        ActionTrackingService::STATUS_ANNULE,
                    ], true);
            })
            ->count();

        $openActions = $actions->filter(function (Action $action): bool {
            return ! in_array((string) ($action->statut_validation ?? ''), [
                ActionTrackingService::VALIDATION_VALIDEE_DIRECTION,
            ], true);
        })->values();

        return [
            'current' => [
                'statistical_basis_label' => $this->labelForThreshold($currentThreshold),
                'official_basis_label' => $this->labelForThreshold($currentThreshold),
                'workflow_chain_label' => $this->chainLabel($currentServiceEnabled, $currentDirectionEnabled),
                'statistical_actions_total' => $currentStatistical->count(),
                'official_actions_total' => $currentStatistical->count(),
                'statistical_completion_rate' => $this->completionRate($currentStatistical),
                'official_completion_rate' => $this->completionRate($currentStatistical),
                'statistical_average_score' => $this->averageScore($currentStatistical),
                'official_average_score' => $this->averageScore($currentStatistical),
                'min_progress_for_closure' => $currentClosureProgress,
                'auto_complete_when_target_reached' => $currentAutoComplete,
                'closure_eligible_actions' => $this->countClosureEligible($openActions, $currentClosureProgress),
            ],
            'simulated' => [
                'statistical_basis_label' => $this->labelForThreshold($simulatedThreshold),
                'official_basis_label' => $this->labelForThreshold($simulatedThreshold),
                'workflow_chain_label' => $this->chainLabel($simulatedServiceEnabled, $simulatedDirectionEnabled),
                'statistical_actions_total' => $simulatedStatistical->count(),
                'official_actions_total' => $simulatedStatistical->count(),
                'statistical_completion_rate' => $this->completionRate($simulatedStatistical),
                'official_completion_rate' => $this->completionRate($simulatedStatistical),
                'statistical_average_score' => $this->averageScore($simulatedStatistical),
                'official_average_score' => $this->averageScore($simulatedStatistical),
                'min_progress_for_closure' => $simulatedClosureProgress,
                'auto_complete_when_target_reached' => $simulatedAutoComplete,
                'closure_eligible_actions' => $this->countClosureEligible($openActions, $simulatedClosureProgress),
            ],
            'impact' => [
                'statistical_actions_delta' => $simulatedStatistical->count() - $currentStatistical->count(),
                'official_actions_delta' => $simulatedStatistical->count() - $currentStatistical->count(),
                'statistical_completion_rate_delta' => round($this->completionRate($simulatedStatistical) - $this->completionRate($currentStatistical), 2),
                'official_completion_rate_delta' => round($this->completionRate($simulatedStatistical) - $this->completionRate($currentStatistical), 2),
                'statistical_average_score_delta' => round($this->averageScore($simulatedStatistical) - $this->averageScore($currentStatistical), 2),
                'official_average_score_delta' => round($this->averageScore($simulatedStatistical) - $this->averageScore($currentStatistical), 2),
                'closure_eligible_actions_delta' => $this->countClosureEligible($openActions, $simulatedClosureProgress) - $this->countClosureEligible($openActions, $currentClosureProgress),
                'auto_complete_candidates' => $autoCompleteCandidates,
            ],
            'dashboard_preview' => [
                'dg' => [
                    'cards' => $this->dashboardPreview('dg'),
                    'kpis' => $this->managedKpiSettings->buildRuntimeMetrics($simulatedSummary, ['role' => User::ROLE_DG]),
                ],
                'service' => [
                    'cards' => $this->dashboardPreview('service'),
                    'kpis' => $this->managedKpiSettings->buildRuntimeMetrics($simulatedSummary, ['role' => User::ROLE_SERVICE]),
                ],
            ],
            'export_preview' => $this->exportPreview(),
            'warnings' => $this->warnings(
                $currentServiceEnabled,
                $simulatedServiceEnabled,
                $currentDirectionEnabled,
                $simulatedDirectionEnabled
            ),
            'payload' => [
                'actions_service_validation_enabled' => $simulatedServiceEnabled ? '1' : '0',
                'actions_direction_validation_enabled' => $simulatedDirectionEnabled ? '1' : '0',
                'actions_min_progress_for_closure' => (string) $simulatedClosureProgress,
                'actions_auto_complete_when_target_reached' => $simulatedAutoComplete ? '1' : '0',
            ],
        ];
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return array<string, float>
     */
    private function metricSummary(Collection $actions): array
    {
        $average = fn (string $key): float => round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->{$key} ?? 0)), 2);

        return [
            'delai' => $average('kpi_delai'),
            'performance' => $average('kpi_performance'),
            'conformite' => $average('kpi_conformite'),
            'qualite' => $average('kpi_qualite'),
            'risque' => $average('kpi_risque'),
            'global' => $average('kpi_global'),
            'progression' => round((float) $actions->avg(fn (Action $action): float => (float) ($action->progression_reelle ?? 0)), 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dashboardPreview(string $role): array
    {
        $profile = $this->dashboardProfileSettings->all()[$role] ?? [];

        return collect($profile['cards'] ?? [])
            ->filter(fn (array $card): bool => (bool) ($card['enabled'] ?? true))
            ->sortBy('order')
            ->map(fn (array $card): array => [
                'label' => (string) ($card['label'] ?? $card['code'] ?? 'Carte'),
                'size' => (string) ($card['size'] ?? 'md'),
                'tone' => (string) ($card['tone'] ?? 'auto'),
                'target_route' => (string) ($card['target_route'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exportPreview(): array
    {
        return ExportTemplate::query()
            ->where('module', 'reporting')
            ->where('status', ExportTemplate::STATUS_PUBLISHED)
            ->orderBy('format')
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('format')
            ->map(function (Collection $templates, string $format): array {
                /** @var ExportTemplate|null $template */
                $template = $templates->first();

                return [
                    'format' => strtoupper($format),
                    'name' => (string) ($template?->name ?? 'Template non publie'),
                    'reading_level' => (string) ($template?->reading_level ?? 'consolide'),
                    'meta' => [
                        'graphs' => (bool) data_get($template?->meta_config, 'show_graphs', true),
                        'watermark' => (bool) data_get($template?->meta_config, 'show_watermark', false),
                        'signatures' => (bool) data_get($template?->meta_config, 'show_signatures', false),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function warnings(
        bool $currentServiceEnabled,
        bool $simulatedServiceEnabled,
        bool $currentDirectionEnabled,
        bool $simulatedDirectionEnabled
    ): array {
        $warnings = [];

        if ($currentDirectionEnabled && ! $simulatedDirectionEnabled) {
            $warnings[] = 'La validation direction est retiree du circuit simule.';
        }

        if ($currentServiceEnabled !== $simulatedServiceEnabled) {
            $warnings[] = $simulatedServiceEnabled
                ? 'La validation chef redevient une etape obligatoire.'
                : 'La validation chef est retiree du circuit simule.';
        }

        if (! $simulatedServiceEnabled && ! $simulatedDirectionEnabled) {
            $warnings[] = 'Le circuit simule cloture les actions sans validation complementaire.';
        }

        return $warnings;
    }

    /**
     * @param  Collection<int, Action>  $actions
     * @return Collection<int, Action>
     */
    private function filterByThreshold(Collection $actions, string $threshold): Collection
    {
        $allowed = $this->actionCalculationSettings->validationStatusesFrom($threshold);

        return $actions
            ->filter(fn (Action $action): bool => in_array((string) ($action->statut_validation ?? ''), $allowed, true))
            ->values();
    }

    /**
     * @param  Collection<int, Action>  $actions
     */
    private function completionRate(Collection $actions): float
    {
        $total = $actions->count();
        if ($total === 0) {
            return 0.0;
        }

        $completed = $actions->filter(fn (Action $action): bool => in_array((string) ($action->statut_dynamique ?? ''), [
            ActionTrackingService::STATUS_ACHEVE_DANS_DELAI,
            ActionTrackingService::STATUS_ACHEVE_HORS_DELAI,
        ], true))->count();

        return round(($completed / $total) * 100, 2);
    }

    /**
     * @param  Collection<int, Action>  $actions
     */
    private function averageScore(Collection $actions): float
    {
        if ($actions->isEmpty()) {
            return 0.0;
        }

        return round((float) $actions->avg(fn (Action $action): float => (float) ($action->actionKpi?->kpi_global ?? 0)), 2);
    }

    /**
     * @param  Collection<int, Action>  $actions
     */
    private function countClosureEligible(Collection $actions, int $minimumProgress): int
    {
        return $actions
            ->filter(fn (Action $action): bool => (float) ($action->progression_reelle ?? 0) >= $minimumProgress)
            ->count();
    }

    private function labelForThreshold(string $threshold): string
    {
        return $this->actionCalculationSettings->statisticalScopeOptions()[$threshold]
            ?? 'Toutes les actions visibles';
    }

    private function chainLabel(bool $serviceEnabled, bool $directionEnabled): string
    {
        if ($serviceEnabled && $directionEnabled) {
            return 'Agent -> Chef de service -> Direction';
        }

        if ($serviceEnabled) {
            return 'Agent -> Chef de service';
        }

        if ($directionEnabled) {
            return 'Agent -> Direction';
        }

        return 'Agent -> cloture directe';
    }
}
