<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\Direction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernanceWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_api_docs_and_retention_pages(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('workspace.api-docs.index'))
            ->assertOk()
            ->assertSee('Documentation API');

        $this->actingAs($admin)
            ->get(route('workspace.retention.index'))
            ->assertOk()
            ->assertSee('Retention et archivage');
    }

    public function test_agent_cannot_access_governance_pages(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('workspace.api-docs.index'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('workspace.retention.index'))
            ->assertForbidden();
    }

    public function test_creating_delegation_notifies_the_delegate(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'password_changed_at' => now(),
        ]);

        $direction = Direction::query()->create([
            'code' => 'DIR-GOV',
            'libelle' => 'Direction Gouvernance',
            'actif' => true,
        ]);

        $service = Service::query()->create([
            'direction_id' => $direction->id,
            'code' => 'SER-GOV',
            'libelle' => 'Service Gouvernance',
            'actif' => true,
        ]);

        $delegant = User::factory()->create([
            'role' => User::ROLE_SERVICE,
            'direction_id' => $direction->id,
            'service_id' => $service->id,
            'password_changed_at' => now(),
        ]);

        $delegue = User::factory()->create([
            'role' => User::ROLE_DIRECTION,
            'direction_id' => $direction->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('workspace.delegations.store'), [
                'delegant_id' => $delegant->id,
                'delegue_id' => $delegue->id,
                'role_scope' => Delegation::SCOPE_SERVICE,
                'direction_id' => $direction->id,
                'service_id' => $service->id,
                'permissions' => ['planning_read', 'action_review'],
                'date_debut' => now()->format('Y-m-d H:i:s'),
                'date_fin' => now()->addDays(7)->format('Y-m-d H:i:s'),
                'motif' => 'Continuite de service',
            ])
            ->assertRedirect(route('workspace.delegations.index'))
            ->assertSessionHas('success');

        $notification = $delegue->fresh()->notifications()->latest()->first();

        $this->assertNotNull($notification);
        $this->assertSame('delegations', $notification->data['module'] ?? null);
        $this->assertSame('Nouvelle delegation recue', $notification->data['title'] ?? null);
    }
}
