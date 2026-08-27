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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DashboardResponsibleOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_responsible_options_require_authentication(): void
    {
        $this->getJson(route('synthese.responsibles'))
            ->assertUnauthorized();
    }

    public function test_responsible_options_deny_a_profile_without_dashboard_access(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_INVITE_LECTURE]);

        $this->actingAs($viewer)
            ->getJson(route('synthese.responsibles'))
            ->assertForbidden();
    }

    public function test_responsible_options_reject_invalid_and_unknown_filters(): void
    {
        $direction = Direction::factory()->create();
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $viewer = User::factory()->create(['role' => User::ROLE_DG]);

        $this->actingAs($viewer)
            ->getJson(route('synthese.responsibles', ['service_id' => $otherService->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('direction_id');

        $this->getJson(route('synthese.responsibles', [
            'direction_id' => $direction->id,
            'service_id' => $otherService->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->getJson(route('synthese.responsibles', ['unsupported_filter' => 'value']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('unsupported_filter');
    }

    public function test_cross_organization_dashboard_profiles_receive_only_assigned_id_label_options(): void
    {
        $direction = Direction::factory()->create();
        $service = Service::factory()->create(['direction_id' => $direction->id]);
        $otherDirection = Direction::factory()->create();
        $otherService = Service::factory()->create(['direction_id' => $otherDirection->id]);
        $primary = User::factory()->create([
            'name' => 'Alpha RMO',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);
        $coResponsible = User::factory()->create([
            'name' => 'Beta RMO',
            'role' => User::ROLE_AGENT,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);
        $subActionAgent = User::factory()->create([
            'name' => 'Gamma RMO',
            'role' => User::ROLE_AGENT,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);
        $suspended = User::factory()->create([
            'name' => 'Responsable suspendu',
            'role' => User::ROLE_AGENT,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'suspended_until' => now()->addDay(),
        ]);
        $foreignResponsible = User::factory()->create([
            'name' => 'Responsable hors périmètre',
            'role' => User::ROLE_AGENT,
            'direction_id' => $otherDirection->id,
            'service_id' => $otherService->id,
        ]);
        $action = $this->createAction($direction, $service, $primary, 2026, 'Action sélectionnée');
        $action->responsables()->syncWithoutDetaching([
            $coResponsible->id => ['is_primary' => false],
            $suspended->id => ['is_primary' => false],
        ]);
        SousAction::query()->create([
            'action_id' => $action->id,
            'agent_id' => $subActionAgent->id,
            'libelle' => 'Sous-action sélectionnée',
            'date_debut' => '2026-02-01',
            'date_fin' => '2026-03-31',
        ]);
        $this->createAction($otherDirection, $otherService, $foreignResponsible, 2026, 'Action étrangère');

        $expected = [
            ['id' => (int) $primary->id, 'label' => 'Alpha RMO'],
            ['id' => (int) $coResponsible->id, 'label' => 'Beta RMO'],
            ['id' => (int) $subActionAgent->id, 'label' => 'Gamma RMO'],
        ];

        foreach ([
            User::ROLE_DG,
            User::ROLE_PLANIFICATION,
            User::ROLE_SCIQ,
            User::ROLE_CHEF_PLANIFICATION,
            User::ROLE_CHEF_UNITE_SCIQ,
        ] as $role) {
            $viewer = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($viewer)->getJson(route('synthese.responsibles', [
                'exercice' => 2026,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
            ]));

            $response->assertOk()->assertExactJson($expected);
            $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertNoSensitiveUserFields($response, [
                $viewer,
                $primary,
                $coResponsible,
                $subActionAgent,
                $suspended,
                $foreignResponsible,
            ]);
        }
    }

    public function test_local_profile_is_limited_to_its_own_scope(): void
    {
        $ownDirection = Direction::factory()->create();
        $ownService = Service::factory()->create(['direction_id' => $ownDirection->id]);
        $foreignDirection = Direction::factory()->create();
        $foreignService = Service::factory()->create(['direction_id' => $foreignDirection->id]);
        $ownResponsible = User::factory()->create([
            'name' => 'RMO du service',
            'role' => User::ROLE_AGENT,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]);
        $foreignResponsible = User::factory()->create([
            'name' => 'RMO étranger',
            'role' => User::ROLE_AGENT,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
        ]);
        $viewer = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]);
        $this->createAction($ownDirection, $ownService, $ownResponsible, 2026, 'Action propre');
        $this->createAction($foreignDirection, $foreignService, $foreignResponsible, 2026, 'Action étrangère');

        $response = $this->actingAs($viewer)->getJson(route('synthese.responsibles', [
            'exercice' => 2026,
            'direction_id' => $ownDirection->id,
            'service_id' => $ownService->id,
        ]));

        $response->assertOk()->assertExactJson([
            ['id' => (int) $ownResponsible->id, 'label' => 'RMO du service'],
        ]);
        $this->assertNoSensitiveUserFields($response, [$viewer, $ownResponsible, $foreignResponsible]);

        $forbiddenResponse = $this->getJson(route('synthese.responsibles', [
            'exercice' => 2026,
            'direction_id' => $foreignDirection->id,
            'service_id' => $foreignService->id,
        ]));
        $forbiddenResponse->assertForbidden();
        $this->assertStringNotContainsString($foreignResponsible->name, $forbiddenResponse->getContent());
        $this->assertStringNotContainsString($foreignResponsible->email, $forbiddenResponse->getContent());
    }

    public function test_active_delegation_exposes_only_its_delegated_scope(): void
    {
        $ownDirection = Direction::factory()->create();
        $ownService = Service::factory()->create(['direction_id' => $ownDirection->id]);
        $delegatedDirection = Direction::factory()->create();
        $delegatedService = Service::factory()->create(['direction_id' => $delegatedDirection->id]);
        $forbiddenDirection = Direction::factory()->create();
        $forbiddenService = Service::factory()->create(['direction_id' => $forbiddenDirection->id]);
        $delegatedResponsible = User::factory()->create([
            'name' => 'RMO délégué',
            'role' => User::ROLE_AGENT,
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
        ]);
        $forbiddenResponsible = User::factory()->create([
            'name' => 'RMO non délégué',
            'role' => User::ROLE_AGENT,
            'direction_id' => $forbiddenDirection->id,
            'service_id' => $forbiddenService->id,
        ]);
        $delegant = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $delegatedDirection->id,
        ]);
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
            'motif' => 'Continuité du pilotage',
            'date_debut' => now()->subDay(),
            'date_fin' => now()->addDay(),
            'statut' => 'active',
            'cree_par' => $delegant->id,
        ]);
        $this->createAction($delegatedDirection, $delegatedService, $delegatedResponsible, 2026, 'Action déléguée');
        $this->createAction($forbiddenDirection, $forbiddenService, $forbiddenResponsible, 2026, 'Action non déléguée');

        $response = $this->actingAs($viewer)->getJson(route('synthese.responsibles', [
            'exercice' => 2026,
            'direction_id' => $delegatedDirection->id,
            'service_id' => $delegatedService->id,
        ]));

        $response->assertOk()->assertExactJson([
            ['id' => (int) $delegatedResponsible->id, 'label' => 'RMO délégué'],
        ]);
        $this->assertNoSensitiveUserFields($response, [
            $viewer,
            $delegatedResponsible,
            $forbiddenResponsible,
        ]);

        $this->getJson(route('synthese.responsibles', [
            'exercice' => 2026,
            'direction_id' => $forbiddenDirection->id,
            'service_id' => $forbiddenService->id,
        ]))->assertForbidden();
    }

    /**
     * @param  list<User>  $users
     */
    private function assertNoSensitiveUserFields(TestResponse $response, array $users): void
    {
        $content = $response->getContent();
        foreach ($users as $user) {
            $this->assertStringNotContainsString((string) $user->email, $content);
        }

        foreach (['email', 'role', 'direction_id', 'service_id'] as $forbiddenKey) {
            $this->assertStringNotContainsString('"'.$forbiddenKey.'"', $content);
        }

        foreach ($response->json() as $option) {
            $this->assertSame(['id', 'label'], array_keys($option));
        }
    }

    private function createAction(
        Direction $direction,
        Service $service,
        User $responsible,
        int $year,
        string $label,
    ): Action {
        $pas = Pas::query()->create([
            'titre' => 'PAS '.$label,
            'periode_debut' => $year,
            'periode_fin' => $year,
        ]);
        $pao = Pao::query()->create([
            'pas_id' => $pas->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'annee' => $year,
            'titre' => 'PAO '.$label,
        ]);
        $pta = Pta::query()->create([
            'pao_id' => $pao->id,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'titre' => 'PTA '.$label,
        ]);

        return Action::query()->create([
            'pta_id' => $pta->id,
            'pao_id' => $pao->id,
            'libelle' => $label,
            'date_debut' => $year.'-01-01',
            'date_fin' => $year.'-12-31',
            'date_echeance' => $year.'-12-31',
            'responsable_id' => $responsible->id,
            'financement_requis' => false,
        ]);
    }
}
