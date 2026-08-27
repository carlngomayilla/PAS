<?php

namespace Tests\Unit;

use App\Services\Dashboard\DashboardPythonChartService;
use App\Services\Dashboard\DashboardTabPayloadService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class DashboardTabPayloadServiceTest extends TestCase
{
    public function test_it_normalizes_aliases_and_memoizes_the_current_tab(): void
    {
        $aliases = [
            'overview' => ['overview', 'pilotage', '', 'unknown'],
            'advanced' => ['actions', 'tables', 'advanced', 'analyse'],
            'charts' => ['charts', 'graphes', 'kpi', 'gantt', 'analytics'],
        ];

        foreach ($aliases as $expected => $requestedTabs) {
            foreach ($requestedTabs as $requestedTab) {
                $request = Request::create('/dashboard', 'GET', ['dashboardTab' => $requestedTab]);
                $service = new DashboardTabPayloadService($request, $this->chartService());

                $this->assertSame($expected, $service->current());
                $this->assertTrue($service->is($expected));

                $request->query->set('dashboardTab', $expected === 'charts' ? 'overview' : 'charts');
                $this->assertSame($expected, $service->current());
            }
        }
    }

    public function test_it_defaults_array_query_values_to_overview_without_a_warning(): void
    {
        $request = Request::create('/dashboard', 'GET', ['dashboardTab' => ['charts']]);
        $service = new DashboardTabPayloadService($request, $this->chartService());

        $this->assertSame('overview', $service->current());
        $this->assertTrue($service->is('overview'));
    }

    public function test_it_resets_the_memoized_tab_when_a_new_request_is_used(): void
    {
        $service = $this->serviceFor('overview');
        $this->assertSame('overview', $service->current());

        $service->useRequest(Request::create('/dashboard', 'GET', ['dashboardTab' => 'charts']));

        $this->assertSame('charts', $service->current());
    }

    public function test_it_projects_only_data_needed_by_each_tab(): void
    {
        $dashboardData = [
            'dashboard_role' => 'suivi_evaluation',
            'global_scores' => ['global' => 75],
            'unit_rows' => [['label' => 'Direction']],
            'action_rows' => [['label' => 'Action']],
            'monthly' => [['label' => 'Janvier']],
            'plotly_figures' => ['agent_scores' => []],
            'decision_charts' => ['status' => []],
            'private_server_detail' => ['must_not' => 'reach JSON'],
        ];

        $overview = $this->serviceFor('overview')->dashboardClientData($dashboardData);
        $advanced = $this->serviceFor('advanced')->dashboardClientData($dashboardData);
        $charts = $this->serviceFor('charts')->dashboardClientData($dashboardData);

        $this->assertSame(['dashboard_role', 'global_scores'], array_keys($overview));
        $this->assertArrayHasKey('unit_rows', $advanced);
        $this->assertArrayHasKey('action_rows', $advanced);
        $this->assertArrayNotHasKey('monthly', $advanced);
        $this->assertArrayNotHasKey('plotly_figures', $advanced);
        $this->assertArrayHasKey('monthly', $charts);
        $this->assertArrayHasKey('plotly_figures', $charts);
        $this->assertArrayHasKey('decision_charts', $charts);
        $this->assertArrayNotHasKey('private_server_detail', $charts);

        $reporting = ['charts' => ['funnel' => []], 'details' => ['sensitive' => true]];
        $this->assertSame([], $this->serviceFor('overview')->reportingClientData($reporting));
        $this->assertSame([], $this->serviceFor('advanced')->reportingClientData($reporting));
        $this->assertSame(['charts' => ['funnel' => []]], $this->serviceFor('charts')->reportingClientData($reporting));
    }

    public function test_finalize_calls_python_only_for_charts_and_refreshes_client_projections(): void
    {
        $payload = [
            'dashboardData' => [
                'dashboard_role' => 'suivi_evaluation',
                'agent_performance' => ['rows' => [['label' => 'Agent A']]],
                'plotly_figures' => ['stale' => true],
            ],
            'dashboardClientData' => ['stale' => true],
            'reportingAnalytics' => ['charts' => ['funnel' => ['values' => [1, 2, 3]]]],
            'reportingClientAnalytics' => ['stale' => true],
        ];

        foreach (['overview', 'advanced'] as $tab) {
            $chartService = $this->chartService(['agent_scores' => ['data' => []]]);
            $result = $this->serviceFor($tab, $chartService)->finalize($payload);

            $this->assertSame(0, $chartService->calls);
            $this->assertSame([], $result['dashboardData']['plotly_figures']);
            $this->assertSame([], $result['reportingClientAnalytics']);
            $this->assertArrayNotHasKey('stale', $result['dashboardClientData']);
        }

        $figures = ['agent_scores' => ['data' => [['type' => 'bar']]]];
        $chartService = $this->chartService($figures);
        $result = $this->serviceFor('charts', $chartService)->finalize($payload);

        $this->assertSame(1, $chartService->calls);
        $this->assertSame($payload['dashboardData']['agent_performance'], $chartService->lastPayload);
        $this->assertSame($figures, $result['dashboardData']['plotly_figures']);
        $this->assertSame($figures, $result['dashboardClientData']['plotly_figures']);
        $this->assertSame(
            ['charts' => $payload['reportingAnalytics']['charts']],
            $result['reportingClientAnalytics']
        );
    }

    private function serviceFor(
        string $tab,
        ?DashboardPythonChartService $chartService = null,
    ): DashboardTabPayloadService {
        return new DashboardTabPayloadService(
            Request::create('/dashboard', 'GET', ['dashboardTab' => $tab]),
            $chartService ?? $this->chartService(),
        );
    }

    private function chartService(array $figures = []): DashboardPythonChartService
    {
        return new class($figures) extends DashboardPythonChartService
        {
            public int $calls = 0;

            /**
             * @var array<string, mixed>
             */
            public array $lastPayload = [];

            /**
             * @param  array<string, mixed>  $figures
             */
            public function __construct(private readonly array $figures) {}

            /**
             * @param  array<string, mixed>  $payload
             * @return array<string, mixed>
             */
            public function generate(array $payload): array
            {
                $this->calls++;
                $this->lastPayload = $payload;

                return $this->figures;
            }
        };
    }
}
