<?php

namespace App\Services\Dashboard;

use App\Models\Action;
use Illuminate\Support\Collection;

final readonly class DashboardOverviewSnapshot
{
    /**
     * @param  Collection<int, Action>  $allScopedActions
     * @param  Collection<int, Action>  $allDashboardActions
     * @param  Collection<int, Action>  $scopedActions
     * @param  Collection<int, Action>  $visibleActions
     * @param  Collection<int, Action>  $dashboardActions
     * @param  Collection<int, Action>  $personalActions
     * @param  Collection<int, Action>  $validatedActions
     * @param  array<string, int>  $totals
     * @param  array<string, array<string, int>>  $statusBreakdown
     * @param  array<string, int>  $alerts
     * @param  array{mode: string, user_role: string, effective_role: string, cross_organization_filters: bool, organization_filters_enabled: bool, read_only: bool, direction_id: int|null, service_id: int|null, selected_direction_id: int|null, selected_service_id: int|null}  $scope
     * @param  array{periode: string, periode_label: string, statut_action: string|null, statut_suivi: string|null, statut_delai: string|null, alerte_echeance: string|null, responsable_id: int|null}  $filters
     * @param  array{year: int|null, quarter: string|null}  $exercise
     * @param  array{enabled: bool, selected_id: int|null, selected_label: string, service_selected_id: int|null, service_selected_label: string, options: list<array{id: int, label: string}>, service_options: list<array{id: int, label: string}>}  $directionSelector
     * @param  array<string, list<array<string, int|string>>>  $filterOptions
     * @param  array<string, string|array<string, array<string, string>>>  $links
     * @param  array<string, mixed>  $synthesisDecisionSummary
     * @param  array<string, mixed>|null  $financialSummary
     */
    public function __construct(
        public Collection $allScopedActions,
        public Collection $allDashboardActions,
        public Collection $scopedActions,
        public Collection $visibleActions,
        public Collection $dashboardActions,
        public Collection $personalActions,
        public Collection $validatedActions,
        public array $totals,
        public array $statusBreakdown,
        public array $alerts,
        public array $scope,
        public array $filters,
        public array $exercise,
        public array $directionSelector,
        public array $filterOptions,
        public array $links,
        public array $synthesisDecisionSummary,
        public ?array $financialSummary,
        public string $generatedAt,
    ) {}

    /**
     * @return array{totals: array<string, int>, alerts: array<string, int>, status_breakdown: array<string, array<string, int>>, action_scope: array{mode: string, visible_actions_total: int, personal_actions_total: int, dashboard_actions_total: int}}
     */
    public function metrics(): array
    {
        return [
            'totals' => $this->totals,
            'alerts' => $this->alerts,
            'status_breakdown' => $this->statusBreakdown,
            'action_scope' => [
                'mode' => $this->scope['mode'],
                'visible_actions_total' => $this->visibleActions->count(),
                'personal_actions_total' => $this->personalActions->count(),
                'dashboard_actions_total' => $this->dashboardActions->count(),
            ],
        ];
    }

    /**
     * API-safe overview core. Eloquent models and collections intentionally stay
     * outside this boundary and are only consumed by the server-rendered view.
     *
     * @return array{scope: array<string, mixed>, direction_selector: array<string, mixed>, filters: array<string, mixed>, filter_options: array<string, mixed>, exercise: array<string, int|string|null>, metrics: array<string, mixed>, links: array<string, string|array<string, array<string, string>>>, synthesis_decision_summary: array<string, mixed>, financial_summary: array<string, mixed>|null, generated_at: string}
     */
    public function toPayload(): array
    {
        return [
            'scope' => $this->scope,
            'direction_selector' => $this->directionSelector,
            'filters' => $this->filters,
            'filter_options' => $this->filterOptions,
            'exercise' => $this->exercise,
            'metrics' => $this->metrics(),
            'links' => $this->links,
            'synthesis_decision_summary' => $this->synthesisDecisionSummary,
            'financial_summary' => $this->financialSummary,
            'generated_at' => $this->generatedAt,
        ];
    }
}
