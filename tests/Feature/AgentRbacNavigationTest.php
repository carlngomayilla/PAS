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

        // Fusion modules (2026-05-28) : 'mes_actions' devient 'execution' (label "Action")
        // pour les agents. L'URL reste pointee sur ?vue=mes_actions cote sidebar.
        $this->assertEqualsCanonicalizing([
            'pilotage',
            'mes_taches',
            'execution',
            'corrections',
            'notifications',
        ], $codes);

        $this->assertNotContains('messagerie', $codes);
        $this->assertNotContains('pas', $codes);
        $this->assertNotContains('pao', $codes);
        $this->assertNotContains('pta', $codes);
        $this->assertNotContains('mes_actions', $codes, 'Le code mes_actions a ete fusionne avec execution.');
        $this->assertNotContains('reporting', $codes);
        $this->assertNotContains('alertes', $codes);
        $this->assertNotContains('referentiel', $codes);
        $this->assertNotContains('audit', $codes);
        $this->assertNotContains('api_docs', $codes);
        $this->assertNotContains('retention', $codes);
        $this->assertNotContains('delegations', $codes);
        $this->assertNotContains('super_admin', $codes);
    }

    public function test_agent_cannot_open_planning_modules_by_direct_url(): void
    {
        $agent = User::factory()->create([
            'role' => User::ROLE_AGENT,
        ]);

        $this->actingAs($agent)->get('/workspace/pas')->assertForbidden();
        $this->actingAs($agent)->get('/workspace/pao')->assertForbidden();
        $this->actingAs($agent)->get('/workspace/pta')->assertForbidden();
    }
}
