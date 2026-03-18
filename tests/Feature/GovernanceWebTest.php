<?php

namespace Tests\Feature;

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
}
