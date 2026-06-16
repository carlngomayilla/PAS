<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use App\Services\DeletionRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningControlChiefUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_control_chief_can_manage_direction_service_users_only(): void
    {
        [$direction, $service] = $this->createOperationalScope();
        $dgDirection = Direction::query()->create([
            'code' => 'DG',
            'libelle' => 'Direction generale',
            'actif' => true,
        ]);
        $actor = User::factory()->create([
            'role' => User::ROLE_CHEF_PLANIFICATION,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $agent = User::factory()->create([
            'name' => 'Agent Visible',
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'email' => 'agent-visible@example.test',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'agent_matricule' => 'AG-777',
            'agent_fonction' => 'Responsable suivi',
            'is_active' => true,
            'password_changed_at' => now(),
        ]);
        $dgUser = User::factory()->create([
            'role' => User::ROLE_DG,
            'email' => 'dg-hidden@example.test',
            'direction_id' => $dgDirection->id,
            'is_active' => true,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($actor)
            ->get(route('workspace.referentiel.utilisateurs.index'))
            ->assertOk()
            ->assertSee('Nom complet')
            ->assertSee('Adresse email')
            ->assertSee('Fonction')
            ->assertSee('Matricule')
            ->assertSee('Portée')
            ->assertSee('Agent Visible')
            ->assertSee($agent->email)
            ->assertSee('Responsable suivi')
            ->assertSee('AG-777')
            ->assertSee('Portée direction et service (agent)')
            ->assertDontSee($dgUser->email);

        $this->actingAs($actor)
            ->get(route('workspace.referentiel.utilisateurs.create'))
            ->assertOk()
            ->assertSee('Service')
            ->assertDontSee('Directeur General');

        $this->actingAs($actor)
            ->get(route('workspace.referentiel.utilisateurs.edit', $agent))
            ->assertOk();

        $this->actingAs($actor)
            ->get(route('workspace.referentiel.utilisateurs.edit', $dgUser))
            ->assertForbidden();

        $deletionRequests = app(DeletionRequestService::class);
        $this->assertTrue($deletionRequests->canRequestUserDeletion($actor, $agent));
        $this->assertFalse($deletionRequests->canRequestUserDeletion($actor, $dgUser));

        $this->actingAs($actor)
            ->post(route('workspace.referentiel.utilisateurs.store'), [
                'name' => 'Responsable Service',
                'email' => 'responsable-service@example.test',
                'role' => User::ROLE_SERVICE,
                'is_active' => '1',
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'password' => 'ValidPass123!',
                'password_confirmation' => 'ValidPass123!',
            ])
            ->assertRedirect(route('workspace.referentiel.utilisateurs.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'responsable-service@example.test',
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
        ]);

        $this->actingAs($actor)
            ->post(route('workspace.referentiel.utilisateurs.store'), [
                'name' => 'Profil DG Refuse',
                'email' => 'profil-dg-refuse@example.test',
                'role' => User::ROLE_DG,
                'is_active' => '1',
                'direction_id' => $dgDirection->id,
                'password' => 'ValidPass123!',
                'password_confirmation' => 'ValidPass123!',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($actor)
            ->getJson('/api/v1/referentiel/utilisateurs?per_page=50')
            ->assertOk()
            ->assertJsonFragment(['email' => $agent->email])
            ->assertJsonMissing(['email' => $dgUser->email]);
    }

    public function test_both_planning_control_chief_roles_open_user_creation(): void
    {
        foreach ([User::ROLE_CHEF_PLANIFICATION, User::ROLE_CHEF_UNITE_SCIQ] as $role) {
            $actor = User::factory()->create([
                'role' => $role,
                'is_active' => true,
                'password_changed_at' => now(),
            ]);

            $this->actingAs($actor)
                ->get(route('workspace.referentiel.utilisateurs.create'))
                ->assertOk();
        }
    }

    /**
     * @return array{0: Direction, 1: Service}
     */
    private function createOperationalScope(): array
    {
        $direction = Direction::query()->create([
            'code' => 'DAF',
            'libelle' => 'Direction administrative et financiere',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SVC',
            'libelle' => 'Service operationnel',
            'actif' => true,
        ]);

        return [$direction, $service];
    }
}
