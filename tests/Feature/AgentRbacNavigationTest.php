<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentRbacNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_workspace_modules_follow_corrected_visibility_matrix(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
        ]);

        $codes = collect($agent->workspaceModules())->pluck('code')->all();

        $this->assertContains('pilotage', $codes);
        $this->assertContains('messagerie', $codes);
        $this->assertContains('pas', $codes);
        $this->assertContains('pao', $codes);
        $this->assertContains('pta', $codes);
        $this->assertContains('execution', $codes);
        $this->assertContains('reporting', $codes);
        $this->assertContains('alertes', $codes);
        $this->assertNotContains('referentiel', $codes);
        $this->assertNotContains('audit', $codes);
        $this->assertNotContains('api_docs', $codes);
        $this->assertNotContains('retention', $codes);
        $this->assertNotContains('delegations', $codes);
        $this->assertNotContains('super_admin', $codes);
    }

    public function test_agent_can_open_planning_read_routes_by_direct_url(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
        ]);

        $this->actingAs($agent)->get('/workspace/pas')->assertOk();
        $this->actingAs($agent)->get('/workspace/pao')->assertOk();
        $this->actingAs($agent)->get('/workspace/pta')->assertOk();
    }
}
