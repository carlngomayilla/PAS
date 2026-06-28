<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAiPtaFixtures;
use Tests\TestCase;

class AiPermissionsTest extends TestCase
{
    use CreatesAiPtaFixtures;
    use RefreshDatabase;

    public function test_ai_import_routes_are_protected_by_authentication_and_permissions(): void
    {
        $this->get(route('workspace.ai-imports.pta.index'))->assertRedirect(route('login.form'));

        $agent = $this->createAiUser(User::ROLE_AGENT);
        $this->actingAs($agent)
            ->get(route('workspace.ai-imports.pta.index'))
            ->assertForbidden();

        $controller = $this->createAiUser(User::ROLE_PLANIFICATION);
        $this->actingAs($controller)
            ->get(route('workspace.ai-imports.pta.index'))
            ->assertOk();
    }
}
