<?php

namespace Tests\Feature;

use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre A13 : `/workspace/ajax/users` ne doit JAMAIS lister l ensemble des
 * utilisateurs si l appelant n a pas de portee globale ET n a pas de
 * rattachement direction (perimetre indetermine).
 */
class DependentSelectUsersScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_global_and_without_direction_is_forbidden(): void
    {
        $orphan = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'direction_id' => null,
            'service_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($orphan)
            ->getJson(route('workspace.ajax.users'))
            ->assertForbidden();
    }

    public function test_user_with_direction_only_sees_his_direction_users(): void
    {
        $dirA = Direction::query()->create(['code' => 'DA', 'libelle' => 'Direction A', 'actif' => true]);
        $dirB = Direction::query()->create(['code' => 'DB', 'libelle' => 'Direction B', 'actif' => true]);

        $director = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $dirA->id,
            'is_active' => true,
        ]);

        User::factory()->create([
            'name' => 'Agent A',
            'role' => User::ROLE_AGENT,
            'direction_id' => $dirA->id,
            'is_active' => true,
        ]);
        User::factory()->create([
            'name' => 'Agent B',
            'role' => User::ROLE_AGENT,
            'direction_id' => $dirB->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($director)
            ->getJson(route('workspace.ajax.users'))
            ->assertOk();

        $directionIds = collect($response->json())->pluck('direction_id')->unique()->values();
        $this->assertCount(1, $directionIds);
        $this->assertSame($dirA->id, $directionIds->first());
    }

    public function test_admin_with_global_read_sees_users_in_all_directions(): void
    {
        $dirA = Direction::query()->create(['code' => 'DGA', 'libelle' => 'A', 'actif' => true]);
        $dirB = Direction::query()->create(['code' => 'DGB', 'libelle' => 'B', 'actif' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'direction_id' => null,
            'is_active' => true,
        ]);

        User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $dirA->id, 'is_active' => true]);
        User::factory()->create(['role' => User::ROLE_AGENT, 'direction_id' => $dirB->id, 'is_active' => true]);

        $response = $this->actingAs($admin)
            ->getJson(route('workspace.ajax.users'))
            ->assertOk();

        $directionIds = collect($response->json())->pluck('direction_id')->filter()->unique()->values();
        $this->assertGreaterThanOrEqual(2, $directionIds->count());
    }
}
