<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Direction;
use App\Models\ObjectifOperationnel;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\PasAxe;
use App\Models\PasObjectif;
use App\Models\Pta;
use App\Models\Service;
use App\Models\User;
use App\Services\Actions\ActionTrackingService;
use App\Services\Dashboard\DashboardPythonChartService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('performance')]
class DashboardPerformanceBaselineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<float>
     */
    private array $queryTimes = [];

    /**
     * @var array<string, int>
     */
    private array $querySignatures = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::listen(function (QueryExecuted $query): void {
            $this->queryTimes[] = (float) $query->time;
            $signature = preg_replace('/\s+/', ' ', trim($query->sql)) ?: trim($query->sql);
            $this->querySignatures[$signature] = ($this->querySignatures[$signature] ?? 0) + 1;
        });
    }

    public function test_dashboard_cold_and_warm_reference_is_reproducible(): void
    {
        $this->seed();

        $user = User::query()
            ->where('email', 'r.dogui.anbg@gmail.com')
            ->firstOrFail();
        $this->createDashboardDataset(100);

        Cache::flush();
        $cold = $this->measureDashboardRequest($user);
        $warm = $this->measureDashboardRequest($user);

        $this->assertGreaterThan(0, $cold['query_count']);
        $this->assertGreaterThan(0, $cold['response_bytes']);
        $this->assertLessThan(
            $cold['query_count'],
            $warm['query_count'],
            'Une requête chaude doit exécuter moins de requêtes SQL que le calcul initial.'
        );
        $this->assertLessThanOrEqual(110, $cold['query_count']);
        $this->assertLessThanOrEqual(10, $warm['query_count']);
        $this->assertLessThan(200_000, $cold['response_bytes']);
        $this->assertLessThanOrEqual(1, $cold['active_exercise_query_count']);
        $this->assertLessThanOrEqual(1, $warm['active_exercise_query_count']);

        fwrite(STDOUT, PHP_EOL.'DASHBOARD_BASELINE '.json_encode([
            'dataset' => [
                'actions' => DB::table('actions')->count(),
                'users' => DB::table('users')->count(),
            ],
            'cold' => $cold,
            'warm' => $warm,
        ], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @return array{duration_ms: float, query_count: int, query_ms: float, peak_memory_bytes: int, response_bytes: int, active_exercise_query_count: int, repeated_queries: list<array{count: int, sql: string}>}
     */
    private function measureDashboardRequest(User $user): array
    {
        $this->queryTimes = [];
        $this->querySignatures = [];
        gc_collect_cycles();

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $memoryBefore = memory_get_usage(true);
        $startedAt = hrtime(true);
        $response = $this->actingAs($user)->get(route('dashboard'));
        $durationMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $response->assertOk();

        arsort($this->querySignatures);
        $repeatedQueries = collect($this->querySignatures)
            ->take(8)
            ->map(fn (int $count, string $sql): array => [
                'count' => $count,
                'sql' => mb_strimwidth($sql, 0, 180, '...'),
            ])
            ->values()
            ->all();

        return [
            'duration_ms' => round($durationMilliseconds, 2),
            'query_count' => count($this->queryTimes),
            'query_ms' => round(array_sum($this->queryTimes), 2),
            'peak_memory_bytes' => max(0, memory_get_peak_usage(true) - $memoryBefore),
            'response_bytes' => strlen($response->getContent()),
            'active_exercise_query_count' => collect($this->querySignatures)
                ->filter(fn (int $count, string $sql): bool => str_contains($sql, 'from exercices')
                    && str_contains($sql, 'is_active'))
                ->sum(),
            'repeated_queries' => $repeatedQueries,
        ];
    }

    public function test_python_charts_are_generated_only_for_the_graphics_tab(): void
    {
        $this->seed();

        $user = User::query()
            ->where('email', 'r.dogui.anbg@gmail.com')
            ->firstOrFail();
        $figures = ['agent_scores' => ['data' => [['type' => 'bar']]]];

        $this->mock(DashboardPythonChartService::class, function (MockInterface $mock) use ($figures): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn($figures);
        });

        Cache::flush();

        $overview = $this->actingAs($user)->get(route('dashboard'));
        $overview->assertOk();
        $this->assertSame([], $overview->viewData('dashboardData')['plotly_figures'] ?? null);

        $tables = $this->actingAs($user)->get(route('dashboard', ['dashboardTab' => 'advanced']));
        $tables->assertOk();
        $this->assertSame([], $tables->viewData('dashboardData')['plotly_figures'] ?? null);

        $charts = $this->actingAs($user)->get(route('dashboard', ['dashboardTab' => 'charts']));
        $charts->assertOk();
        $this->assertSame($figures, $charts->viewData('dashboardData')['plotly_figures'] ?? null);
    }

    public function test_dashboard_payload_is_partitioned_by_selected_tab(): void
    {
        $this->seed();

        $user = User::query()
            ->where('email', 'r.dogui.anbg@gmail.com')
            ->firstOrFail();
        $this->createDashboardDataset(5);

        $this->mock(DashboardPythonChartService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn([]);
        });

        Cache::flush();

        $overview = $this->actingAs($user)->get(route('dashboard'));
        $overview->assertOk();
        $this->assertSame([], $overview->viewData('dashboardData')['action_rows'] ?? null);
        $this->assertSame([], $overview->viewData('dashboardData')['decision_charts'] ?? null);

        $tables = $this->actingAs($user)->get(route('dashboard', ['dashboardTab' => 'advanced']));
        $tables->assertOk();
        $this->assertNotEmpty($tables->viewData('dashboardData')['action_rows'] ?? []);
        $this->assertSame([], $tables->viewData('dashboardData')['decision_charts'] ?? null);
        $this->assertNotEmpty($tables->viewData('reportingAnalytics')['pasConsolidation'] ?? []);
        $this->assertNotEmpty($tables->viewData('reportingAnalytics')['interannualComparison'] ?? []);

        $charts = $this->actingAs($user)->get(route('dashboard', ['dashboardTab' => 'charts']));
        $charts->assertOk();
        $this->assertSame([], $charts->viewData('dashboardData')['action_rows'] ?? null);
        $this->assertNotEmpty($charts->viewData('dashboardData')['decision_charts'] ?? []);
        $this->assertSame([], $charts->viewData('reportingAnalytics')['pasConsolidation'] ?? null);

        $ptaQuarterlyAnalysis = $charts->viewData('reportingAnalytics')['ptaQuarterlyAnalysis'] ?? null;
        $this->assertIsArray($ptaQuarterlyAnalysis);
        $this->assertArrayHasKey('summary', $ptaQuarterlyAnalysis);
        $this->assertArrayHasKey('axis_progression', $ptaQuarterlyAnalysis['charts'] ?? []);
        $this->assertSame(
            $ptaQuarterlyAnalysis['charts'],
            $charts->viewData('reportingAnalytics')['charts']['pta_quarterly'] ?? null,
        );
    }

    public function test_dashboard_payload_round_trips_through_hardened_database_cache(): void
    {
        $this->seed();
        $user = User::query()
            ->where('email', 'r.dogui.anbg@gmail.com')
            ->firstOrFail();

        config()->set('cache.default', 'database');
        config()->set('cache.serializable_classes', false);
        app('cache')->forgetDriver('database');
        Cache::flush();

        $first = $this->actingAs($user)->get(route('dashboard'));
        $second = $this->actingAs($user)->get(route('dashboard'));

        $first->assertOk();
        $second->assertOk();
        $this->assertIsString($first->viewData('reportingAnalytics')['generatedAt'] ?? null);
        $this->assertSame(
            $first->viewData('reportingAnalytics')['generatedAt'] ?? null,
            $second->viewData('reportingAnalytics')['generatedAt'] ?? null,
        );
    }

    private function createDashboardDataset(int $actionCount): void
    {
        $direction = Direction::query()->create([
            'code' => 'PERF-DIR',
            'libelle' => 'Direction performance',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'PERF-SRV',
            'libelle' => 'Service performance',
            'actif' => true,
        ]);
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);
        $pas = Pas::query()->create([
            'titre' => 'PAS performance',
            'periode_debut' => now()->year,
            'periode_fin' => now()->year + 2,
            'statut' => 'actif',
        ]);
        $axe = PasAxe::query()->create([
            'pas_id' => $pas->id,
            'code' => 'PERF-AXE',
            'libelle' => 'Axe performance',
            'ordre' => 1,
        ]);
        $objectif = PasObjectif::query()->create([
            'pas_axe_id' => $axe->id,
            'code' => 'PERF-OS',
            'libelle' => 'Objectif performance',
            'date_echeance' => now()->addYears(2)->toDateString(),
            'ordre' => 1,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => null,
            'annee' => now()->year,
            'titre' => 'PAO performance',
            'objectif_operationnel' => 'Objectif opérationnel performance',
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $objectifOperationnel = ObjectifOperationnel::query()->create([
            'pao_id' => $pao->id,
            'pas_id' => $pas->id,
            'pas_axe_id' => $axe->id,
            'pas_objectif_id' => $objectif->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'libelle' => 'Objectif opérationnel performance',
            'echeance' => now()->addYear()->toDateString(),
            'statut' => Pao::STATUS_VALIDE,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'objectif_operationnel_id' => $objectifOperationnel->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA performance',
            'statut' => Pta::STATUS_EN_COURS,
        ]);

        Action::withoutEvents(function () use ($actionCount, $agent, $objectifOperationnel, $pao, $pta): void {
            for ($index = 1; $index <= $actionCount; $index++) {
                Action::query()->create([
                    'pta_id' => $pta->id,
                    'pao_id' => $pao->id,
                    'objectif_operationnel_id' => $objectifOperationnel->id,
                    'code' => sprintf('PERF-ACT-%04d', $index),
                    'libelle' => sprintf('Action performance %04d', $index),
                    'description' => 'Action synthétique réservée à la mesure PHPUnit.',
                    'type_cible' => 'quantitative',
                    'type_indicateur' => 'quantitatif',
                    'unite_cible' => 'dossiers',
                    'quantite_cible' => 100,
                    'quantite_a_realiser' => 100,
                    'date_debut' => now()->startOfYear()->toDateString(),
                    'date_fin' => now()->endOfYear()->toDateString(),
                    'date_echeance' => now()->endOfYear()->toDateString(),
                    'echeance_cible' => now()->endOfYear()->toDateString(),
                    'responsable_id' => $agent->id,
                    'statut' => 'en_cours',
                    'statut_dynamique' => ActionTrackingService::STATUS_EN_COURS,
                    'statut_parametrage' => 'parametre',
                    'progression_reelle' => $index % 101,
                    'progression_theorique' => 50,
                    'seuil_alerte_progression' => 10,
                    'financement_requis' => false,
                ]);
            }
        });
    }
}
