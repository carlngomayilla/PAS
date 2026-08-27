<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\Dashboard\DashboardFilterContext;
use App\Services\ExerciceContext;
use App\Services\PtaSuiviService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DashboardFilterContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_organization_user_can_select_an_active_direction_and_its_service(): void
    {
        [$direction, $service] = $this->organization('DIR-A', 'Direction A');
        $inactiveDirection = Direction::query()->create([
            'code' => 'DIR-OFF',
            'libelle' => 'Direction inactive',
            'actif' => false,
        ]);
        $user = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $context = $this->context([
            'exercice' => '2026',
            'direction_id' => (string) $direction->id,
            'service_id' => (string) $service->id,
        ]);

        $this->assertSame($direction->id, $context->directionId($user));
        $this->assertSame($service->id, $context->serviceId($user));
        $this->assertSame([
            'annee' => 2026,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ], $context->actionRouteFilters($user));

        $selector = $context->directionContext($user);
        $this->assertTrue($selector['enabled']);
        $this->assertSame($direction->id, $selector['selected_id']);
        $this->assertSame($service->id, $selector['service_selected_id']);
        $this->assertSame(['DIR-A - Direction A'], array_column($selector['options'], 'label'));
        $this->assertSame(['DIR-A-SERVICE - Service Direction A'], array_column($selector['service_options'], 'label'));
        $this->assertNotContains($inactiveDirection->id, array_column($selector['options'], 'id'));
    }

    public function test_invalid_inactive_and_mismatched_values_are_ignored_without_warnings(): void
    {
        [$direction] = $this->organization('DIR-B', 'Direction B');
        [, $foreignService] = $this->organization('DIR-C', 'Direction C');
        $user = User::factory()->create(['role' => User::ROLE_SCIQ]);

        $arrayContext = $this->context([
            'direction_id' => ['unexpected'],
            'service_id' => ['unexpected'],
            'periode' => ['q1'],
            'responsable_id' => ['1'],
            'statut_suivi' => ['en_cours'],
        ]);

        $this->assertNull($arrayContext->directionId($user));
        $this->assertNull($arrayContext->serviceId($user));
        $this->assertSame('all', $arrayContext->period());
        $this->assertNull($arrayContext->synthesisFilters()['responsable_id']);
        $this->assertNull($arrayContext->synthesisFilters()['statut_suivi']);

        $mismatchedContext = $this->context([
            'direction_id' => (string) $direction->id,
            'service_id' => (string) $foreignService->id,
        ]);

        $this->assertSame($direction->id, $mismatchedContext->directionId($user));
        $this->assertNull($mismatchedContext->serviceId($user));
    }

    public function test_local_profile_cannot_forge_cross_organization_filters(): void
    {
        [$localDirection, $localService] = $this->organization('LOCAL', 'Direction locale');
        [$externalDirection, $externalService] = $this->organization('EXTERNAL', 'Direction externe');
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $localDirection->id,
            'service_id' => $localService->id,
        ]);
        $context = $this->context([
            'direction_id' => (string) $externalDirection->id,
            'service_id' => (string) $externalService->id,
        ]);

        $this->assertNull($context->directionId($user));
        $this->assertNull($context->serviceId($user));
        $this->assertFalse($context->directionContext($user)['enabled']);
        $this->assertSame([], $context->directionContext($user)['options']);
        $this->assertSame([], $context->directionContext($user)['service_options']);
    }

    public function test_local_director_filters_resolve_inside_the_own_scope_without_exposing_the_web_selector(): void
    {
        [$direction] = $this->organization('OWN-DIRECTION', 'Direction propre');
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'OWN-DIRECTION-SECOND',
            'libelle' => 'Second service',
            'actif' => true,
        ]);
        $user = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'service_id' => null,
        ]);
        $context = $this->context([
            'direction_id' => (string) $direction->id,
            'service_id' => (string) $service->id,
        ]);

        $this->assertSame($direction->id, $context->directionId($user));
        $this->assertSame($service->id, $context->serviceId($user));
        $this->assertFalse($context->directionContext($user)['enabled']);
        $this->assertSame([], $context->directionContext($user)['options']);
        $this->assertSame([], $context->directionContext($user)['service_options']);
    }

    public function test_service_delegation_exposes_only_own_and_exact_delegated_active_scope(): void
    {
        [$ownDirection, $ownService] = $this->organization('OWN', 'Direction propre');
        [$delegatedDirection, $delegatedService] = $this->organization('DELEGATED', 'Direction déléguée');
        $siblingService = Service::query()->create([
            'direction_id' => $delegatedDirection->id,
            'code' => 'DELEGATED-SIBLING',
            'libelle' => 'Service non délégué',
            'actif' => true,
        ]);
        [$foreignDirection] = $this->organization('FOREIGN', 'Direction étrangère');
        $user = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]);
        $delegant = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $delegatedDirection->id,
        ]);
        Delegation::query()->create([
            'delegant_id' => $delegant->id,
            'delegue_id' => $user->id,
            'role_scope' => Delegation::SCOPE_SERVICE,
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
            'permissions' => ['planning_read'],
            'motif' => 'Continuité de service',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addWeek(),
            'statut' => 'active',
            'cree_par' => $delegant->id,
        ]);
        $context = $this->context([
            'direction_id' => (string) $delegatedDirection->id,
            'service_id' => (string) $delegatedService->id,
        ]);

        $this->assertSame($delegatedDirection->id, $context->directionId($user));
        $this->assertSame($delegatedService->id, $context->serviceId($user));
        $selector = $context->directionContext($user);
        $this->assertTrue($selector['enabled']);
        $this->assertSame(
            collect([$ownDirection->id, $delegatedDirection->id])->sort()->values()->all(),
            collect($selector['options'])->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame([$delegatedService->id], array_column($selector['service_options'], 'id'));
        $this->assertNotContains($foreignDirection->id, array_column($selector['options'], 'id'));

        $forgedSibling = $this->context([
            'direction_id' => (string) $delegatedDirection->id,
            'service_id' => (string) $siblingService->id,
        ]);
        $this->assertSame($delegatedDirection->id, $forgedSibling->directionId($user));
        $this->assertNull($forgedSibling->serviceId($user));
    }

    public function test_synthesis_values_are_normalized_and_memoization_is_reset_for_a_new_request(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CHEF_PLANIFICATION]);
        $firstRequest = Request::create('/dashboard', 'GET', [
            'periode' => 'T2',
            'responsable_id' => '42',
            'statut_action' => 'a_corriger',
            'statut_suivi' => 'en_cours',
            'statut_delai' => 'hors_delai',
            'alerte_echeance' => 'critique',
        ]);
        $this->app->instance('request', $firstRequest);
        $context = new DashboardFilterContext(
            $firstRequest,
            app(ExerciceContext::class),
            app(PtaSuiviService::class),
        );

        $this->assertSame([
            'periode' => 'q2',
            'periode_label' => 'T2',
            'responsable_id' => 42,
            'statut_action' => 'a_corriger',
            'statut_suivi' => 'en_cours',
            'statut_delai' => 'hors_delai',
            'alerte_echeance' => 'critique',
        ], $context->synthesisFilters());

        $secondRequest = Request::create('/dashboard', 'GET', [
            'periode' => 'm12',
            'responsable_id' => '-1',
            'statut_suivi' => 'inconnu',
        ]);
        $this->app->instance('request', $secondRequest);
        $context->useRequest($secondRequest);

        $this->assertSame('m12', $context->period());
        $this->assertSame('Decembre', $context->synthesisFilters()['periode_label']);
        $this->assertNull($context->synthesisFilters()['responsable_id']);
        $this->assertNull($context->synthesisFilters()['statut_suivi']);
        $this->assertSame([
            'periode' => 'm12',
            'direction_filter' => null,
            'service_filter' => null,
            'responsable_filter' => null,
            'statut_action' => null,
            'statut_suivi' => null,
            'statut_delai' => null,
            'alerte_echeance' => null,
        ], $context->cacheDimensions($user));
    }

    public function test_controller_delegates_filter_state_and_query_reads_to_the_context(): void
    {
        $source = file_get_contents((new \ReflectionClass(DashboardController::class))->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('dashboardDirectionResolved', $source);
        $this->assertStringNotContainsString('$dashboardDirectionId', $source);
        $this->assertStringNotContainsString('dashboardServiceResolved', $source);
        $this->assertStringNotContainsString('$dashboardServiceId', $source);
        $this->assertStringNotContainsString("request()->query('direction_id'", $source);
        $this->assertStringNotContainsString("request()->query('service_id'", $source);
        $this->assertStringNotContainsString("request()->query('responsable_id'", $source);
        $this->assertStringNotContainsString('array_merge(request()->query()', $source);
        $this->assertStringContainsString(
            '$this->dashboardFilterContext->cacheDimensions($user)',
            $source
        );
    }

    public function test_dashboard_route_filters_only_expose_normalized_scalar_values(): void
    {
        [$direction, $service] = $this->organization('DIR-ROUTE', 'Direction route');
        $user = User::factory()->create(['role' => User::ROLE_PLANIFICATION]);
        $context = $this->context([
            'exercice' => '2026',
            'periode' => 'T2',
            'direction_id' => (string) $direction->id,
            'service_id' => (string) $service->id,
            'responsable_id' => '42',
            'statut_action' => 'acheve',
            'statut_suivi' => 'en_cours',
            'statut_delai' => 'hors_delai',
            'alerte_echeance' => 'critique',
            'dashboardTab' => ['advanced'],
            'unexpected' => ['value'],
        ], $user);

        $this->assertSame([
            'exercice' => 2026,
            'periode' => 'q2',
            'responsable_id' => 42,
            'statut_action' => 'acheve',
            'statut_suivi' => 'en_cours',
            'statut_delai' => 'hors_delai',
            'alerte_echeance' => 'critique',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ], $context->dashboardRouteFilters());
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function context(array $query, ?User $user = null): DashboardFilterContext
    {
        $request = Request::create('/dashboard', 'GET', $query);
        $this->app->instance('request', $request);

        if ($user instanceof User) {
            $request->setUserResolver(static fn (): User => $user);
        }

        return new DashboardFilterContext(
            $request,
            app(ExerciceContext::class),
            app(PtaSuiviService::class),
        );
    }

    /**
     * @return array{Direction, Service}
     */
    private function organization(string $code, string $label): array
    {
        $direction = Direction::query()->create([
            'code' => $code,
            'libelle' => $label,
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => $code.'-SERVICE',
            'libelle' => 'Service '.$label,
            'actif' => true,
        ]);

        return [$direction, $service];
    }
}
