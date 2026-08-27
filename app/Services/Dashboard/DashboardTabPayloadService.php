<?php

namespace App\Services\Dashboard;

use Illuminate\Http\Request;

class DashboardTabPayloadService
{
    private ?string $currentTab = null;

    public function __construct(
        private Request $request,
        private readonly DashboardPythonChartService $dashboardPythonChartService,
    ) {}

    public function useRequest(Request $request): void
    {
        if ($this->request === $request) {
            return;
        }

        $this->request = $request;
        $this->currentTab = null;
    }

    public function current(): string
    {
        if ($this->currentTab !== null) {
            return $this->currentTab;
        }

        $requested = $this->request->query('dashboardTab', 'overview');
        if (! is_string($requested) && ! is_numeric($requested)) {
            $requested = 'overview';
        }

        $requested = trim((string) $requested);

        return $this->currentTab = match ($requested) {
            'charts', 'graphes', 'kpi', 'gantt', 'analytics' => 'charts',
            'actions', 'tables', 'advanced', 'analyse' => 'advanced',
            default => 'overview',
        };
    }

    public function is(string $tab): bool
    {
        return $this->current() === $tab;
    }

    /**
     * Keep expensive Python rendering outside the shared page payload. The
     * chart service owns its own contextual cache, while Pilotage and Tableaux
     * never need to start a Python process.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function finalize(array $payload): array
    {
        $dashboardData = is_array($payload['dashboardData'] ?? null)
            ? $payload['dashboardData']
            : [];
        $figures = [];

        if ($this->is('charts')) {
            $agentPerformance = $dashboardData['agent_performance'] ?? [];
            $figures = $this->dashboardPythonChartService->generate(
                is_array($agentPerformance) ? $agentPerformance : []
            );
        }

        $dashboardData['plotly_figures'] = $figures;
        $payload['dashboardData'] = $dashboardData;
        $payload['dashboardClientData'] = $this->dashboardClientData($dashboardData);
        $payload['reportingClientAnalytics'] = $this->reportingClientData(
            is_array($payload['reportingAnalytics'] ?? null) ? $payload['reportingAnalytics'] : []
        );

        return $payload;
    }

    /**
     * Keep the embedded dashboard JSON small. The Blade view still receives the
     * full server payload for tables, but the browser only needs chart-ready rows.
     *
     * @param  array<string, mixed>  $dashboardData
     * @return array<string, mixed>
     */
    public function dashboardClientData(array $dashboardData): array
    {
        $keys = [
            'dashboard_role',
            'direction_selector',
            'exercise',
            'actions_index_url',
            'personal_actions_summary',
            'official_action_filters',
            'unit_mode_label',
            'global_scores',
            'quality_threshold',
            'status_cards',
            'role_dashboard',
            'synthesis_filters',
            'synthesis_decision_summary',
        ];

        if ($this->is('advanced')) {
            $keys = [
                ...$keys,
                'unit_rows',
                'synthesis_service_rows',
                'synthesis_agent_rows',
                'direction_performance_rows',
                'decision_counts',
                'decision_service_rows',
                'decision_agent_rows',
                'decision_quarter_rows',
                'action_rows',
            ];
        }

        if ($this->is('charts')) {
            $keys = [
                ...$keys,
                'monthly',
                'unit_rows',
                'agent_performance',
                'plotly_figures',
                'direction_performance_rows',
                'decision_counts',
                'decision_charts',
                'decision_service_rows',
                'decision_agent_rows',
                'decision_quarter_rows',
                'interannual',
                'action_rows',
                'gantt_rows',
                'bullet_rows',
                'scatter_points',
                'radar_datasets',
                'top_action_bars',
            ];
        }

        return array_intersect_key($dashboardData, array_flip($keys));
    }

    /**
     * Reporting details may contain Eloquent collections used by the Blade tables.
     * They must not be mirrored into the JSON block, otherwise /dashboard can spend
     * seconds serializing data that the JavaScript never reads.
     *
     * @param  array<string, mixed>  $reportingAnalytics
     * @return array<string, mixed>
     */
    public function reportingClientData(array $reportingAnalytics): array
    {
        if (! $this->is('charts')) {
            return [];
        }

        return [
            'charts' => is_array($reportingAnalytics['charts'] ?? null)
                ? $reportingAnalytics['charts']
                : [],
        ];
    }
}
