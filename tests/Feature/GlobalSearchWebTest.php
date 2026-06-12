<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminUser;
use Tests\TestCase;

class GlobalSearchWebTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAdminUser;

    public function test_global_search_returns_detailed_user_profiles_by_name(): void
    {
        $this->seed();

        $admin = $this->createSuperAdminUser();
        $direction = Direction::query()->create([
            'code' => 'DGLOB',
            'libelle' => 'Direction globale recherche',
            'actif' => true,
        ]);
        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SGLOB',
            'libelle' => 'Service recherche globale',
            'actif' => true,
        ]);

        User::factory()->create([
            'name' => 'Marie Claire Nguema',
            'email' => 'marie.nguema@anbg.test',
            'role' => User::ROLE_AGENT,
            'is_agent' => true,
            'agent_matricule' => 'AG-902',
            'agent_fonction' => 'Chargee de suivi',
            'agent_telephone' => '077000000',
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('workspace.search', ['q' => 'Marie Claire', 'format' => 'json']));

        $response->assertOk()
            ->assertJsonPath('groups.0.title', 'Profils utilisateurs')
            ->assertJsonPath('groups.0.items.0.title', 'Marie Claire Nguema')
            ->assertJsonPath('groups.0.items.0.badge', 'Actif')
            ->assertJsonFragment(['label' => 'Matricule', 'value' => 'AG-902'])
            ->assertJsonFragment(['label' => 'Direction', 'value' => 'DGLOB - Direction globale recherche'])
            ->assertJsonFragment(['label' => 'Service', 'value' => 'SGLOB - Service recherche globale']);
    }
}
