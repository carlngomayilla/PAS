<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Pao;
use App\Models\Pas;
use App\Models\Pta;
use App\Models\Service;
use App\Models\SousAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_overview_requires_authentication(): void
    {
        $this->getJson(route('v1.dashboard.overview'))
            ->assertUnauthorized();
    }

    public function test_dashboard_overview_denies_a_profile_without_dashboard_permission(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_INVITE_LECTURE]);
        Sanctum::actingAs($viewer);

        $this->getJson(route('v1.dashboard.overview'))
            ->assertForbidden();
    }

    public function test_cross_organization_profile_receives_a_private_whitelisted_payload_and_stable_etag(): void
    {
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $viewer = User::factory()->create(['role' => User::ROLE_DG]);
        Sanctum::actingAs($viewer);

        $url = route('v1.dashboard.overview', [
            'exercice' => 'all',
            'periode' => 'q2',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $response = $this->getJson($url);

        $response->assertOk()
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'scope' => [
                    'mode',
                    'user_role',
                    'effective_role',
                    'cross_organization_filters',
                    'organization_filters_enabled',
                    'read_only',
                    'direction_id',
                    'service_id',
                    'selected_direction_id',
                    'selected_service_id',
                ],
                'direction_selector' => ['enabled', 'selected_id', 'selected_label', 'options', 'service_options'],
                'filters' => ['periode', 'periode_label', 'statut_action', 'responsable_id'],
                'filter_options' => [
                    'years',
                    'quarters',
                    'periods',
                    'action_statuses',
                    'tracking_statuses',
                    'delay_statuses',
                    'deadline_alerts',
                    'responsibles',
                ],
                'exercise' => ['year', 'quarter'],
                'metrics' => ['totals', 'alerts', 'status_breakdown', 'action_scope'],
                'synthesis_decision_summary',
                'financial_summary',
                'links' => [
                    'blade_pilotage',
                    'tables',
                    'charts',
                    'actions',
                    'pas',
                    'paos',
                    'ptas',
                    'late_actions',
                    'kpi_below_threshold',
                    'reporting',
                    'alerts',
                    'pta_tracking',
                    'breakdowns' => [
                        'actions',
                        'workflow',
                        'alerts',
                    ],
                ],
            ])
            ->assertJsonPath('schema_version', '1.0')
            ->assertJsonPath('scope.direction_id', (int) $direction->id)
            ->assertJsonPath('scope.service_id', (int) $service->id)
            ->assertJsonPath('scope.selected_direction_id', (int) $direction->id)
            ->assertJsonPath('scope.selected_service_id', (int) $service->id)
            ->assertJsonPath('filters.periode', 'q2')
            ->assertJsonPath('exercise.quarter', 'q2')
            ->assertJsonPath('exercise.year', null)
            ->assertJsonPath('metrics.status_breakdown.actions.a_corriger', 0);

        $this->assertLessThan(120 * 1024, mb_strlen($response->getContent(), '8bit'));
        $this->assertStringNotContainsString($viewer->email, $response->getContent());
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=30', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('Accept, Authorization, Cookie', $response->headers->get('Vary'));
        $links = $response->json('links');
        $this->assertIsArray($links);
        $this->assertSafeRelativeLinks($links);
        $this->assertSame(
            'acheve',
            $this->queryParameters(data_get($links, 'breakdowns.actions.acheve'))['statut_action'] ?? null,
        );
        $this->assertSame(
            (string) $direction->id,
            $this->queryParameters(data_get($links, 'breakdowns.actions.acheve'))['direction_id'] ?? null,
        );
        $this->assertArrayHasKey('a_corriger', data_get($links, 'breakdowns.actions'));
        foreach (['actions', 'pas', 'paos', 'ptas', 'kpi_below_threshold', 'reporting', 'alerts', 'pta_tracking'] as $linkKey) {
            $parameters = $this->queryParameters(data_get($links, $linkKey));
            $this->assertSame('advanced', $parameters['dashboardTab'] ?? null);
            $this->assertSame('q2', $parameters['periode'] ?? null);
            $this->assertSame((string) $direction->id, $parameters['direction_id'] ?? null);
            $this->assertSame((string) $service->id, $parameters['service_id'] ?? null);
        }
        $lateActionParameters = $this->queryParameters(data_get($links, 'late_actions'));
        $this->assertSame('advanced', $lateActionParameters['dashboardTab'] ?? null);
        $this->assertSame('q2', $lateActionParameters['periode'] ?? null);
        $this->assertSame('en_retard', $lateActionParameters['statut_action'] ?? null);
        $this->assertSame(
            'validation_controleur',
            $this->queryParameters(data_get($links, 'breakdowns.workflow.validation_controleur'))['statut_suivi'] ?? null,
        );
        $this->assertSame(
            'advanced',
            $this->queryParameters(data_get($links, 'breakdowns.workflow.validation_controleur'))['dashboardTab'] ?? null,
        );
        $this->assertSame(
            'en_retard',
            $this->queryParameters(data_get($links, 'breakdowns.alerts.en_retard'))['alerte_echeance'] ?? null,
        );

        $etag = (string) $response->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->withHeader('If-None-Match', $etag)
            ->getJson($url)
            ->assertNotModified();
    }

    public function test_invalid_inactive_mismatched_and_array_filters_return_validation_errors(): void
    {
        $direction = Direction::factory()->create();
        $otherDirection = Direction::factory()->create();
        $inactiveDirection = Direction::factory()->create(['actif' => false]);
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $suspendedResponsable = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'is_active' => true,
            'suspended_until' => now()->addDay(),
        ]);
        $viewer = User::factory()->create(['role' => User::ROLE_DG]);
        Sanctum::actingAs($viewer);

        $this->getJson(route('v1.dashboard.overview', ['direction_id' => $inactiveDirection->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');

        $this->getJson(route('v1.dashboard.overview', [
            'direction_id' => $direction->id,
            'service_id' => $otherService->id,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->getJson(route('v1.dashboard.overview', ['responsable_id' => $suspendedResponsable->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('responsable_id');

        $this->getJson(route('v1.dashboard.overview', [
            'exercice' => ['2026'],
            'periode' => ['q1'],
            'direction_id' => [$direction->id],
            'service_id' => [$otherService->id],
            'responsable_id' => [$suspendedResponsable->id],
            'statut_action' => ['acheve'],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors([
                'exercice',
                'periode',
                'direction_id',
                'service_id',
                'responsable_id',
                'statut_action',
            ]);

        $this->getJson(route('v1.dashboard.overview', [
            'exercice' => 1999,
            'periode' => 'q5',
            'statut_action' => 'inconnu',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exercice', 'periode', 'statut_action']);

        $this->getJson(route('v1.dashboard.overview', ['unsupported_filter' => 'value']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unsupported_filter');
    }

    public function test_local_profile_cannot_forge_an_organization_filter(): void
    {
        $ownDirection = Direction::factory()->create();
        $ownService = Service::factory()->create(['direction_id' => $ownDirection->id]);
        $foreignDirection = Direction::factory()->create();
        $foreignService = Service::factory()->create(['direction_id' => $foreignDirection->id]);
        $viewer = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson(route('v1.dashboard.overview'))
            ->assertOk()
            ->assertJsonPath('scope.direction_id', (int) $ownDirection->id)
            ->assertJsonPath('scope.service_id', (int) $ownService->id)
            ->assertJsonPath('scope.selected_direction_id', null)
            ->assertJsonPath('scope.selected_service_id', null);

        $response = $this->getJson(route('v1.dashboard.overview', [
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
        ]));

        $response->assertForbidden();
        $this->assertStringNotContainsString($foreignDirection->libelle, $response->getContent());
        $this->assertStringNotContainsString($foreignService->libelle, $response->getContent());
    }

    public function test_active_delegation_allows_its_scope_but_not_another_scope(): void
    {
        $ownDirection = Direction::factory()->create();
        $ownService = Service::factory()->create(['direction_id' => $ownDirection->id]);
        $delegatedDirection = Direction::factory()->create();
        $delegatedService = Service::factory()->create(['direction_id' => $delegatedDirection->id]);
        $forbiddenDirection = Direction::factory()->create();
        $forbiddenService = Service::factory()->create(['direction_id' => $forbiddenDirection->id]);
        $delegant = User::factory()->create(['role' => User::ROLE_DIRECTION, 'direction_id' => $delegatedDirection->id]);
        $viewer = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]);
        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $viewer->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
            'permissions' => ['planning_read'],
            'motif' => 'Continuité de service API',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDay(),
            'statut' => 'active',
            'cree_par' => $delegant->id,
        ]);
        Sanctum::actingAs($viewer);

        $this->getJson(route('v1.dashboard.overview', [
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
        ]))->assertOk()
            ->assertJsonPath('scope.direction_id', (int) $delegatedDirection->id)
            ->assertJsonPath('scope.service_id', (int) $delegatedService->id);

        $this->getJson(route('v1.dashboard.overview', [
            'direction_id' => $forbiddenDirection->id,
            'service_id' => $forbiddenService->id,
        ]))->assertForbidden();
    }

    public function test_responsable_filter_accepts_primary_co_rmo_and_sub_action_assignments_only(): void
    {
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $primary = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $direction->id, 'service_id' => $service->id]);
        $coRmo = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $otherDirection->id, 'service_id' => $otherService->id]);
        $subActionAgent = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $otherDirection->id, 'service_id' => $otherService->id]);
        $unassigned = User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $direction->id, 'service_id' => $service->id]);
        $action = $this->createAction($direction, $service, $primary, 2026);
        $action->responsables()->syncWithoutDetaching([$coRmo->id => ['is_primary' => false]]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $subActionAgent->id,
            'libelle' => 'Sous-action affectée',
            'date_debut' => '2026-02-01',
            'date_fin' => '2026-03-31',
        ]);
        $viewer = User::factory()->create(['role' => User::ROLE_DG]);
        Sanctum::actingAs($viewer);

        $optionsResponse = $this->getJson(route('v1.dashboard.overview', [
            'exercice' => 2026,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]))->assertOk();
        $responsibleOptions = collect($optionsResponse->json('filter_options.responsibles'));
        $this->assertSame(
            collect([$primary->id, $coRmo->id, $subActionAgent->id])->sort()->values()->all(),
            $responsibleOptions->pluck('id')->sort()->values()->all(),
        );
        foreach ($responsibleOptions as $option) {
            $this->assertSame(['id', 'label'], array_keys($option));
        }
        foreach ([$primary, $coRmo, $subActionAgent] as $responsable) {
            $this->assertStringNotContainsString($responsable->email, $optionsResponse->getContent());
        }

        foreach ([$primary, $coRmo, $subActionAgent] as $responsable) {
            $response = $this->getJson(route('v1.dashboard.overview', [
                'exercice' => 2026,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'responsable_id' => $responsable->id,
            ]));

            $response->assertOk()
                ->assertJsonPath('filters.responsable_id', (int) $responsable->id);
            $this->assertStringNotContainsString($responsable->email, $response->getContent());
        }

        $response = $this->getJson(route('v1.dashboard.overview', [
            'exercice' => 2026,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'responsable_id' => $unassigned->id,
        ]));

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('responsable_id');
        $this->assertStringNotContainsString($unassigned->name, $response->getContent());
        $this->assertStringNotContainsString($unassigned->email, $response->getContent());
    }

    public function test_cached_overview_is_invalidated_after_a_visible_action_mutation(): void
    {
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $responsable = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $viewer = User::factory()->create(['role' => User::ROLE_DG]);
        Sanctum::actingAs($viewer);
        $url = route('v1.dashboard.overview', [
            'exercice' => 2026,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);

        $firstResponse = $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('metrics.totals.actions_total', 0);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('generated_at', $firstResponse->json('generated_at'));

        $this->createAction($direction, $service, $responsable, 2026);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('metrics.totals.actions_total', 1);
    }

    private function createAction(Direction $direction, Service $service, User $responsable, int $year): Action
    {
        $pas = Pas::query()->create([
            'titre' => 'PAS contrat API dashboard',
            'periode_debut' => $year,
            'periode_fin' => $year,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => $year,
            'titre' => 'PAO contrat API dashboard',
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA contrat API dashboard',
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'libelle' => 'Action contrat API dashboard',
            'date_debut' => $year.'-01-01',
            'date_fin' => $year.'-12-31',
            'date_echeance' => $year.'-12-31',
            'responsable_id' => $responsable->id,
            'financement_requis' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $links
     */
    private function assertSafeRelativeLinks(array $links): void
    {
        foreach ($links as $link) {
            if (is_array($link)) {
                $this->assertSafeRelativeLinks($link);

                continue;
            }

            $this->assertIsString($link);
            $this->assertStringStartsWith('/', $link);
            $this->assertFalse(str_starts_with($link, '//'));
            $this->assertStringNotContainsString(chr(92), $link);
        }
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(mixed $link): array
    {
        $this->assertIsString($link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $parameters);

        return collect($parameters)
            ->filter(static fn (mixed $value): bool => is_scalar($value))
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }
}
